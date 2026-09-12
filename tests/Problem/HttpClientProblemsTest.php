<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\HttpClient\Problem\BadJsonResponse;
use Lava\HttpClient\Problem\BadRequestUrl;
use Lava\HttpClient\Problem\TransportFailed;
use Lava\HttpClient\Problem\UnencodableJsonBody;
use Lava\HttpClient\Problem\UnexpectedStatus;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The pack's problem surface, asserted as shapes rather than as prose.
 *
 * The JSON key order is fixed framework-wide, the codes are the registry's
 * contract, and the HTTP status is what a handler's caller sees — three things
 * a consumer can depend on and therefore three things worth failing a build
 * over.
 */
final class HttpClientProblemsTest extends TestCase
{
    private const URL = 'https://api.example.com/v1/things';

    private function request(string $method = 'GET'): RequestInterface
    {
        return (new Psr17Factory())->createRequest($method, self::URL);
    }

    /**
     * Every code the pack raises, once.
     *
     * @return iterable<string, array{string, LavaProblem, int}>
     */
    public static function problems(): iterable
    {
        yield 'transport_failed' => [
            'transport_failed',
            TransportFailed::of(
                (new Psr17Factory())->createRequest('GET', self::URL),
                'Connection refused',
            ),
            502,
        ];

        yield 'bad_request_url' => [
            'bad_request_url',
            BadRequestUrl::of((new Psr17Factory())->createRequest('GET', '/v1/things'), 'no host'),
            500,
        ];

        yield 'unexpected_status' => [
            'unexpected_status',
            UnexpectedStatus::of('GET', self::URL, 500, 'boom', 'Internal Server Error'),
            502,
        ];

        yield 'bad_json_response' => [
            'bad_json_response',
            BadJsonResponse::of('GET', self::URL, 200, '<html>', 'Syntax error'),
            502,
        ];

        yield 'unencodable_json_body' => [
            'unencodable_json_body',
            UnencodableJsonBody::of('POST', self::URL, 'Malformed UTF-8 characters'),
            500,
        ];
    }

    #[DataProvider('problems')]
    public function testEveryProblemHasTheStandardShape(string $code, LavaProblem $problem, int $status): void
    {
        $json = $problem->json();

        self::assertSame(
            ['code', 'problem', 'fix', 'context', 'source', 'severity'],
            array_keys($json),
            'the key order is the framework contract',
        );
        self::assertSame($code, $json['code']);
        self::assertSame($code, $problem->code());
        self::assertSame('fatal', $json['severity']);
        self::assertSame($status, $problem->httpStatus());
        self::assertIsString($json['problem']);
        self::assertNotSame('', $json['problem'], 'a problem always says what failed');
        self::assertIsString($json['fix']);
        self::assertNotSame('', $json['fix'], 'a problem always says what to do');
        self::assertNull($json['source'], 'no problem in this pack points at a user file');
    }

    public function testTheCodesAreDistinct(): void
    {
        $codes = [];
        foreach (self::problems() as [$code, $problem, $_]) {
            $codes[$code] = $problem->code();
        }

        self::assertSame(array_keys($codes), array_values($codes));
    }

    public function testTheTransportFailureKeepsTheRequestForTheInterface(): void
    {
        // NetworkExceptionInterface requires getRequest(), and PSR-18 says it
        // MAY be a different object — but it must be a request.
        $request = $this->request('PUT');
        $problem = TransportFailed::of($request, 'Connection refused');

        self::assertSame($request, $problem->getRequest());
    }

    public function testTheTransportFailureRedactsTheUrlAndTheReason(): void
    {
        // curl echoes the URL back in several of its errors, so the driver's
        // text is not ours to trust — the same rule lavaphp/db applies to a DSN
        // and to PDO's message.
        $request = (new Psr17Factory())->createRequest(
            'GET',
            'https://user:s3cr3t@api.example.com/v1/things?token=abc123',
        );
        $problem = TransportFailed::of($request, 'Could not resolve host: api.example.com?token=abc123');

        $printed = json_encode($problem->json(), JSON_THROW_ON_ERROR);
        self::assertIsString($printed);
        self::assertStringNotContainsString('s3cr3t', $printed);
        self::assertStringNotContainsString('abc123', $printed);
        self::assertStringContainsString('***@', $printed);
    }

    public function testTheTransportFailureNeverPrintsTheRequestHeaders(): void
    {
        // The request carries an Authorization header, and a problem report is
        // a log line and a pasted issue. The request object is kept for
        // getRequest() and printed nowhere.
        $request = $this->request()->withHeader('Authorization', 'Bearer super-secret');
        $problem = TransportFailed::of($request, 'Connection refused');

        $printed = json_encode($problem->json(), JSON_THROW_ON_ERROR);
        self::assertIsString($printed);
        self::assertStringNotContainsString('super-secret', $printed);
        self::assertStringNotContainsString('Authorization', $printed);
    }

    public function testTheUrlProblemAlsoRedacts(): void
    {
        $request = (new Psr17Factory())->createRequest('GET', 'https://api.example.com/x?api_key=k-9');
        $problem = BadRequestUrl::of($request, 'it is not an absolute URL — it has no host');

        self::assertStringNotContainsString('k-9', $problem->getMessage());
        self::assertStringNotContainsString('k-9', json_encode($problem->context, JSON_THROW_ON_ERROR) ?: '');
    }

    public function testTheStatusProblemSaysWhichStatusAndWhatToDoAboutIt(): void
    {
        // The status is known when the message is written, so the fix is
        // specific: a 404 and a 429 are two different mistakes in two different
        // places, and a generic "the request failed" is never useful.
        $notFound = UnexpectedStatus::of('GET', self::URL, 404, '', 'Not Found');
        $rateLimited = UnexpectedStatus::of('GET', self::URL, 429, '', 'Too Many Requests');

        self::assertStringContainsString('does not exist on that host', $notFound->fix);
        self::assertStringContainsString('rate-limiting', $rateLimited->fix);
        self::assertNotSame($notFound->fix, $rateLimited->fix);
    }

    public function testAnUnknownStatusStillGetsAUsableFix(): void
    {
        // A non-standard status from a proxy must not produce an empty fix.
        $problem = UnexpectedStatus::of('GET', self::URL, 599, '', '');

        self::assertNotSame('', $problem->fix);
        self::assertStringContainsString('599', $problem->getMessage());
    }

    public function testTheStatusProblemSurvivesAnEmptyReasonPhrase(): void
    {
        // A PSR-7 implementation may leave the reason phrase blank; the message
        // must not end up with a double space or a dangling comma.
        $problem = UnexpectedStatus::of('GET', self::URL, 500, '', '');

        self::assertSame(
            'GET https://api.example.com/v1/things returned 500, and this call requires a 2xx.',
            $problem->getMessage(),
        );
    }

    public function testTheJsonProblemReportsAnEmptyBodyAsSuch(): void
    {
        // A 204 with a JSON call is a real case, and "the body is empty" is a
        // more useful excerpt than a blank string.
        $problem = BadJsonResponse::of('GET', self::URL, 204, '   ', 'Syntax error');

        self::assertSame('', $problem->context['body']);
        self::assertSame('Syntax error', $problem->context['json_error']);
    }

    public function testTheUnencodableBodyProblemCarriesNoPayload(): void
    {
        // Asserted at the class level as well as through the client: the
        // payload is never a parameter of this factory, so there is no call
        // site that could pass one by accident.
        $problem = UnencodableJsonBody::of('POST', self::URL, 'Malformed UTF-8 characters');
        $signature = new \ReflectionMethod(UnencodableJsonBody::class, 'of');

        self::assertSame(
            ['method', 'url', 'reason'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $signature->getParameters()),
        );
        self::assertSame(['method', 'url', 'reason'], array_keys($problem->context));
    }

    public function testTheJsonCallProblemsAreGatewayErrorsAndTheCodeProblemsAreNot(): void
    {
        // Two different audiences: "the upstream misbehaved" is a 502 the
        // caller can retry, while "our own code built a bad value" is a 500
        // that only a deploy fixes. Collapsing them into one status would make
        // an alerting rule unable to tell them apart.
        self::assertSame(502, TransportFailed::of($this->request(), 'x')->httpStatus());
        self::assertSame(502, UnexpectedStatus::of('GET', self::URL, 404, '', '')->httpStatus());
        self::assertSame(502, BadJsonResponse::of('GET', self::URL, 200, '', 'x')->httpStatus());
        self::assertSame(500, BadRequestUrl::of($this->request(), 'x')->httpStatus());
        self::assertSame(500, UnencodableJsonBody::of('POST', self::URL, 'x')->httpStatus());
    }
}
