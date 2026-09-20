<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Unit;

use Lava\HttpClient\ClientOptions;
use Lava\HttpClient\HttpClient;
use Lava\HttpClient\Problem\BadJsonResponse;
use Lava\HttpClient\Problem\BadRequestUrl;
use Lava\HttpClient\Problem\TransportFailed;
use Lava\HttpClient\Problem\UnencodableJsonBody;
use Lava\HttpClient\Problem\UnexpectedStatus;
use Lava\HttpClient\Tests\Support\FakeTransport;
use Lava\HttpClient\Problem\UnsendableRequest;
use Lava\HttpClient\Tests\Support\OneShotStream;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class HttpClientTest extends TestCase
{
    private const URL = 'https://api.example.com/v1/things';

    /**
     * Backoff is zero everywhere except the one test that measures it: the
     * default 100ms would add a quarter of a second to every retry test for no
     * assertion anyone asked for.
     */
    private function client(FakeTransport $transport, ?ClientOptions $options = null): HttpClient
    {
        return new HttpClient(
            $transport,
            new Psr17Factory(),
            $options ?? new ClientOptions(retries: 2, backoffMs: 0),
        );
    }

    private function request(string $method = 'GET', string $url = self::URL): RequestInterface
    {
        return (new Psr17Factory())->createRequest($method, $url);
    }

    // ── The retry rule ────────────────────────────────────────────────────

    public function testAnIdempotentRequestIsRetriedUntilItSucceeds(): void
    {
        $transport = new FakeTransport();
        $transport->pushNetworkFailure()->pushNetworkFailure()->pushResponse(200, 'fine');

        $response = $this->client($transport)->sendRequest($this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('fine', (string) $response->getBody());
        self::assertSame(3, $transport->attempts(), 'two failures, then the success');
    }

    public function testAPostIsNeverRetried(): void
    {
        // The rule the pack exists for: a POST sent twice is two creates, so
        // no setting of `retries` can make it happen. Three failures are queued
        // and only one may be consumed.
        $transport = new FakeTransport();
        $transport->pushNetworkFailure()->pushNetworkFailure()->pushNetworkFailure();

        try {
            $this->client($transport)->sendRequest($this->request('POST'));
            self::fail('a POST that never got a response should still raise');
        } catch (TransportFailed) {
            self::assertSame(1, $transport->attempts(), 'a non-idempotent method gets exactly one attempt');
        }
    }

    public function testEveryIdempotentMethodIsRetried(): void
    {
        foreach (['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'] as $method) {
            $transport = new FakeTransport();
            $transport->pushNetworkFailure()->pushResponse(200);

            $this->client($transport)->sendRequest($this->request($method));

            self::assertSame(2, $transport->attempts(), "{$method} is idempotent and should be retried");
        }
    }

    public function testANonIdempotentMethodIsNotRetriedEvenInLowerCase(): void
    {
        // PSR-7 does not normalise the method, so the rule has to.
        $transport = new FakeTransport();
        $transport->pushNetworkFailure()->pushNetworkFailure();

        try {
            $this->client($transport)->sendRequest($this->request('patch'));
            self::fail('patch is not idempotent and should not be retried');
        } catch (TransportFailed) {
            self::assertSame(1, $transport->attempts());
        }
    }

    public function testTheRetryCountIsACeiling(): void
    {
        $transport = new FakeTransport();
        $transport->pushNetworkFailure()->pushNetworkFailure()->pushNetworkFailure()->pushResponse(200);

        try {
            $this->client($transport, new ClientOptions(retries: 1, backoffMs: 0))
                ->sendRequest($this->request());
            self::fail('one retry means two attempts, and the third failure should raise');
        } catch (TransportFailed) {
            self::assertSame(2, $transport->attempts());
        }
    }

    public function testZeroRetriesMeansExactlyOneAttempt(): void
    {
        $transport = new FakeTransport();
        $transport->pushNetworkFailure()->pushNetworkFailure();

        try {
            $this->client($transport, new ClientOptions(retries: 0, backoffMs: 0))
                ->sendRequest($this->request());
            self::fail('zero retries should not retry');
        } catch (TransportFailed) {
            self::assertSame(1, $transport->attempts());
        }
    }

    public function testItWaitsTheConfiguredBackoffBetweenAttempts(): void
    {
        // A lower bound, so a loaded machine cannot make this flake: two
        // retries at 40ms each cannot finish sooner than 80ms.
        $transport = new FakeTransport();
        $transport->pushNetworkFailure()->pushNetworkFailure()->pushResponse(200);

        $started = hrtime(true);
        $this->client($transport, new ClientOptions(retries: 2, backoffMs: 40))->sendRequest($this->request());
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        self::assertGreaterThanOrEqual(80.0, $elapsedMs, 'two retries should wait 40ms each');
    }

    public function testThePacksOwnFailureIsRethrownUntouched(): void
    {
        // Re-wrapping would lose the redacted context and the `getRequest()`
        // the PSR-18 interface promises. Same rule lavaphp/db and lavaphp/view
        // follow: a wrapper wraps only what is not already a LavaProblem.
        $transport = new FakeTransport();
        $original = null;
        $transport->repeat(3, static function (RequestInterface $request) use (&$original): never {
            $original ??= TransportFailed::of($request, 'connection refused');
            throw $original;
        });

        try {
            $this->client($transport)->sendRequest($this->request());
            self::fail('the failure should propagate');
        } catch (TransportFailed $caught) {
            self::assertSame($original, $caught, 'the same object, not a copy of it');
        }
    }

    public function testAMalformedRequestIsNotRetried(): void
    {
        // `RequestExceptionInterface` is "this request is broken", which sending
        // it again cannot fix — so it propagates on the first attempt, unlike a
        // network failure.
        $transport = new FakeTransport();
        $transport->push(static function (RequestInterface $request): never {
            throw BadRequestUrl::of($request, 'it is not an absolute URL — it has no scheme or no host');
        });

        try {
            $this->client($transport)->sendRequest($this->request());
            self::fail('a malformed request should propagate');
        } catch (BadRequestUrl) {
            self::assertSame(1, $transport->attempts());
        }
    }

    // ── The PSR-18 boundary ──────────────────────────────────────────────

    public function testANonSuccessfulResponseIsReturnedRatherThanThrown(): void
    {
        // PSR-18 says a response is a result, whatever its status. Only the
        // JSON calls — which promise a decoded body — turn a status into an
        // error.
        $transport = new FakeTransport();
        $transport->pushResponse(500, 'boom');

        $response = $this->client($transport)->sendRequest($this->request());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('boom', (string) $response->getBody());
    }

    public function testARedirectIsReturnedRatherThanFollowed(): void
    {
        $transport = new FakeTransport();
        $transport->pushResponse(302, '');

        self::assertSame(302, $this->client($transport)->sendRequest($this->request())->getStatusCode());
    }

    // ── The URL guard ────────────────────────────────────────────────────

    public function testARelativeUrlIsRefusedBeforeTheTransportSeesIt(): void
    {
        $transport = new FakeTransport();

        try {
            $this->client($transport)->sendRequest($this->request('GET', '/v1/things'));
            self::fail('a relative URL should be refused');
        } catch (BadRequestUrl $problem) {
            self::assertSame('bad_request_url', $problem->code());
            self::assertSame(0, $transport->attempts(), 'the guard runs before the transport, always');
        }
    }

    public function testAFileUrlIsRefusedBeforeTheTransportSeesIt(): void
    {
        // The security rule: this pack's URLs arrive from outside, and curl
        // would happily read a local file.
        $transport = new FakeTransport();

        try {
            $this->client($transport)->sendRequest($this->request('GET', 'file:///etc/passwd'));
            self::fail('a file URL should be refused');
        } catch (BadRequestUrl $problem) {
            self::assertStringContainsString("'file'", $problem->getMessage());
            self::assertSame(0, $transport->attempts());
        }
    }

    public function testTheGuardAppliesToTheJsonCallsToo(): void
    {
        $transport = new FakeTransport();

        $this->expectException(BadRequestUrl::class);
        $this->client($transport)->getJson('api.example.com/things');
    }

    // ── The JSON shortcut ────────────────────────────────────────────────

    public function testGetJsonDecodesTheBody(): void
    {
        $transport = new FakeTransport();
        $transport->pushResponse(200, '{"id":7,"name":"thing"}');

        self::assertSame(
            ['id' => 7, 'name' => 'thing'],
            $this->client($transport)->getJson(self::URL),
        );
    }

    public function testGetJsonSendsNoBody(): void
    {
        $transport = new FakeTransport();
        $transport->pushResponse(200, '{}');

        $this->client($transport)->getJson(self::URL);

        $request = $transport->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('', (string) $request->getBody());
        self::assertFalse($request->hasHeader('Content-Type'));
    }

    public function testGetJsonOnANonTwoHundredRaisesTheStatusProblem(): void
    {
        $transport = new FakeTransport();
        $transport->pushResponse(503, 'upstream is down');

        try {
            $this->client($transport)->getJson(self::URL);
            self::fail('a 503 should be a problem');
        } catch (UnexpectedStatus $problem) {
            self::assertSame('unexpected_status', $problem->code());
            self::assertSame(502, $problem->httpStatus(), 'the upstream failed, so the app answers 502');
            self::assertStringContainsString('503', $problem->getMessage());
            self::assertStringContainsString('Service Unavailable', $problem->getMessage());
            self::assertSame(503, $problem->context['status']);
            // The body travels in `context`, which both renderers print — the
            // message stays one sentence by contract.
            self::assertSame('upstream is down', $problem->context['body']);
            self::assertStringNotContainsString('upstream is down', $problem->getMessage());
            self::assertStringContainsString('not a fault in your request', $problem->fix);
        }
    }

    public function testTheStatusProblemCarriesTheReasonPhraseFromTheWire(): void
    {
        $transport = new FakeTransport();
        $transport->pushResponse(404, 'nope');

        try {
            $this->client($transport)->getJson(self::URL);
            self::fail('a 404 should be a problem');
        } catch (UnexpectedStatus $problem) {
            self::assertStringContainsString('Not Found', $problem->getMessage());
            self::assertStringContainsString('does not exist on that host', $problem->fix);
        }
    }

    public function testTheBodyExcerptIsBoundedAndTheElisionIsVisible(): void
    {
        $transport = new FakeTransport();
        $transport->pushResponse(500, str_repeat('x', 5_000));

        try {
            $this->client($transport)->getJson(self::URL);
            self::fail('a 500 should be a problem');
        } catch (UnexpectedStatus $problem) {
            $excerpt = $problem->context['body'];
            self::assertIsString($excerpt);
            self::assertLessThan(500, strlen($excerpt));
            self::assertStringEndsWith('…', $excerpt, 'the elision is visible, so nobody hunts for the rest');
        }
    }

    public function testGetJsonOnHtmlRaisesTheJsonProblem(): void
    {
        // The most common real cause of this is a URL that hit the wrong thing,
        // so the excerpt is what the message leads with.
        $transport = new FakeTransport();
        $transport->pushResponse(200, "<html><body>Sign in</body></html>\n");

        try {
            $this->client($transport)->getJson(self::URL);
            self::fail('an HTML body should be a problem');
        } catch (BadJsonResponse $problem) {
            self::assertSame('bad_json_response', $problem->code());
            self::assertStringContainsString('not a JSON object', $problem->getMessage());
            self::assertStringContainsString('<html>', (string) $problem->context['body']);
            self::assertStringContainsString('reached the wrong place', $problem->fix);
            self::assertSame(502, $problem->httpStatus());
        }
    }

    public function testGetJsonOnAValidScalarRaisesTheJsonProblem(): void
    {
        // `"ok"` decodes cleanly and is still not indexable, so the problem is
        // the same one with a different reason — and the reason has to say so.
        $transport = new FakeTransport();
        $transport->pushResponse(200, '"just a string"');

        try {
            $this->client($transport)->getJson(self::URL);
            self::fail('a scalar body should be a problem');
        } catch (BadJsonResponse $problem) {
            self::assertStringContainsString('decoded to string', $problem->getMessage());
            self::assertSame('it decoded to string, and these calls return an object or array', $problem->context['json_error']);
        }
    }

    public function testPostJsonSendsJson(): void
    {
        $transport = new FakeTransport();
        $transport->pushResponse(201, '{"id":9}');

        $decoded = $this->client($transport)->postJson(self::URL, ['name' => 'thing', 'n' => 1]);

        self::assertSame(['id' => 9], $decoded);

        $request = $transport->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"name":"thing","n":1}', (string) $request->getBody());
    }

    public function testPostJsonKeepsAContentTypeTheCallerSet(): void
    {
        // An API that wants `application/vnd.api+json` is not a mistake to
        // correct.
        $transport = new FakeTransport();
        $transport->pushResponse(200, '{}');

        $this->client($transport)->postJson(self::URL, ['a' => 1], ['Content-Type' => 'application/vnd.api+json']);

        self::assertSame('application/vnd.api+json', $transport->lastRequest()->getHeaderLine('Content-Type'));
    }

    public function testAnEmptyJsonBodySendsNoBodyAtAll(): void
    {
        $transport = new FakeTransport();
        $transport->pushResponse(200, '{}');

        $this->client($transport)->postJson(self::URL);

        $request = $transport->lastRequest();
        self::assertSame('', (string) $request->getBody());
        self::assertFalse($request->hasHeader('Content-Type'));
    }

    public function testAnUnencodableBodyIsRefusedAndThePayloadIsNeverPrinted(): void
    {
        // The payload is the most credential-bearing value in the pack, so it
        // must not appear in the problem — not in the message, not in the fix,
        // not in the context. A login body is exactly this shape.
        $transport = new FakeTransport();
        $payload = ['password' => "\xB1\x31", 'user' => 'someone@example.com'];

        try {
            $this->client($transport)->postJson(self::URL, $payload);
            self::fail('an unencodable payload should be refused');
        } catch (UnencodableJsonBody $problem) {
            $printed = json_encode($problem->json(), JSON_THROW_ON_ERROR);
            self::assertIsString($printed);
            self::assertStringNotContainsString('someone@example.com', $printed);
            self::assertStringNotContainsString('password', $printed);
            self::assertSame(0, $transport->attempts(), 'nothing is sent if the body cannot be built');
            self::assertStringContainsString('Malformed UTF-8', $problem->getMessage());
            self::assertSame(500, $problem->httpStatus(), 'our own code built an unusable value');
        }
    }

    public function testAHeaderOnAPlainRequestReachesTheTransport(): void
    {
        $transport = new FakeTransport();
        $transport->pushResponse(200, 'ok');

        $this->client($transport)->get(self::URL, ['Authorization' => 'Bearer secret-token']);

        self::assertSame('Bearer secret-token', $transport->lastRequest()->getHeaderLine('Authorization'));
    }

    // ── What a retry may do to the body (security review, 2026-09-20) ─────

    public function testABodyThatCannotBeRewoundIsSentOnceEvenOnAnIdempotentMethod(): void
    {
        // PUT is idempotent, so the METHOD allows a retry — but the body is a
        // one-shot stream, and attempts two and three would send an empty one
        // to an endpoint that would accept it happily. A truncated upload that
        // reports success is worse than a failed one.
        $transport = new FakeTransport();
        $transport->repeat(3, static function (RequestInterface $request): never {
            throw new \Lava\HttpClient\Tests\Support\FakeNetworkFailure($request, 'connection reset');
        });

        $request = $this->request('PUT')->withBody(new OneShotStream('IMPORTANT PAYLOAD'));

        try {
            $this->client($transport)->sendRequest($request);
            self::fail('the transport failed on every attempt');
        } catch (TransportFailed $failed) {
            self::assertStringContainsString('connection reset', $failed->getMessage());
        }

        self::assertSame(1, $transport->attempts(), 'a one-shot body is sent exactly once');
    }

    public function testARewindableBodyIsWholeOnEveryAttempt(): void
    {
        // The other half of the same rule: when the body CAN be rewound, the
        // retry must actually send it — reading it once left the stream at its
        // end, so the second attempt used to send nothing at all.
        $seen = [];
        $transport = new FakeTransport();
        $transport->repeat(2, static function (RequestInterface $request) use (&$seen): never {
            $seen[] = (string) $request->getBody();

            throw new \Lava\HttpClient\Tests\Support\FakeNetworkFailure($request, 'timeout');
        });
        $transport->push(static function (RequestInterface $request) use (&$seen): ResponseInterface {
            $seen[] = (string) $request->getBody();

            return (new Psr17Factory())->createResponse(200);
        });

        $request = $this->request('PUT')->withBody((new Psr17Factory())->createStream('IMPORTANT PAYLOAD'));
        $this->client($transport)->sendRequest($request);

        self::assertSame(
            ['IMPORTANT PAYLOAD', 'IMPORTANT PAYLOAD', 'IMPORTANT PAYLOAD'],
            $seen,
            'every attempt sends the whole body',
        );
    }

    public function testAMethodCarryingALineBreakIsRefusedBeforeTheTransportSeesIt(): void
    {
        // The method reaches the wire verbatim, so `\r\n` in it ends the
        // request line and everything after it becomes a second request.
        $transport = new FakeTransport();

        try {
            $this->client($transport)->request("GET / HTTP/1.1\r\nX-Injected: yes\r\n\r\nGET", self::URL);
            self::fail('a method carrying a line break must be refused');
        } catch (UnsendableRequest $refused) {
            self::assertSame('unsendable_request', $refused->code());
            self::assertStringContainsString('\\x0D\\x0A', $refused->getMessage(), 'the control bytes are shown escaped');
            self::assertStringNotContainsString("\r", $refused->getMessage(), 'and never raw');
        }

        self::assertSame(0, $transport->attempts(), 'nothing was sent');
    }

    public function testPostJsonKeepsAContentTypeTheCallerSetInAnyCase(): void
    {
        // A header name is case-insensitive, so a caller writing
        // `content-type` means the same thing as `Content-Type` — and used to
        // have it silently replaced.
        $transport = new FakeTransport();
        $transport->pushResponse(200, '{}');

        $this->client($transport)->postJson(self::URL, ['a' => 1], ['content-type' => 'application/vnd.api+json']);

        self::assertSame('application/vnd.api+json', $transport->lastRequest()->getHeaderLine('Content-Type'));
    }
}
