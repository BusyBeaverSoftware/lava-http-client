<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Http;

use Lava\HttpClient\ClientOptions;
use Lava\HttpClient\CurlTransport;
use Lava\HttpClient\Problem\TransportFailed;
use Lava\HttpClient\Problem\BadRequestUrl;
use Lava\HttpClient\Problem\ResponseTooLarge;
use Lava\HttpClient\Problem\UnsendableRequest;
use Lava\HttpClient\Tests\Support\LenientRequest;
use Lava\HttpClient\Tests\Support\LocalServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The sender, against a real socket.
 *
 * Every claim curl makes is checked here rather than reasoned about, because
 * the failure mode of a curl option set wrong is a request that looks fine and
 * behaves differently — a redirect followed when it should not be, a body sent
 * on a GET, a timeout that is not the one configured.
 */
final class CurlTransportTest extends TestCase
{
    private static LocalServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalServer::shared();
    }

    private function transport(?ClientOptions $options = null): CurlTransport
    {
        return new CurlTransport(
            $options ?? new ClientOptions(timeout: 5.0, connectTimeout: 2.0),
            new Psr17Factory(),
        );
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, array $headers = []): RequestInterface
    {
        $request = (new Psr17Factory())->createRequest($method, self::$server->url($path));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function echo(CurlTransport $transport, string $method = 'GET', string $body = ''): array
    {
        $request = $this->request($method, '/echo');
        if ($body !== '') {
            $request = $request->withBody((new Psr17Factory())->createStream($body));
        }

        $decoded = json_decode((string) $transport->sendRequest($request)->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testItSendsARequestAndReadsTheResponse(): void
    {
        $response = $this->transport()->sendRequest($this->request('GET', '/ok'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
        // The PHP dev server appends `;charset=UTF-8`; the pack passes the
        // header through untouched, so the assertion is on the prefix.
        self::assertStringStartsWith('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function testItCollectsTheResponseHeaders(): void
    {
        $response = $this->transport()->sendRequest($this->request('GET', '/json'));

        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertTrue($response->hasHeader('Content-Type'));
    }

    public function testARedirectIsReturnedRatherThanFollowed(): void
    {
        // PSR-18 says a response is a result. Following the redirect here would
        // hide the 302 from the caller and, on a redirect to another host,
        // resend the Authorization header somewhere it was never meant to go.
        $response = $this->transport()->sendRequest($this->request('GET', '/redirect'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/ok', $response->getHeaderLine('Location'));
    }

    public function testANonSuccessfulStatusIsAResponseNotAFailure(): void
    {
        $response = $this->transport()->sendRequest($this->request('GET', '/status/500'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('status 500', (string) $response->getBody());
    }

    public function testItSendsTheHeadersItWasGiven(): void
    {
        // The point of /echo: only the server can say what actually arrived.
        $seen = $this->echo($this->transport());

        self::assertSame('GET', $seen['method']);
        self::assertSame('lavaphp/http-client', $seen['user_agent']);
    }

    public function testItSendsAnAuthorizationHeader(): void
    {
        $transport = $this->transport();
        $request = $this->request('GET', '/echo', ['Authorization' => 'Bearer secret-token']);

        $seen = json_decode((string) $transport->sendRequest($request)->getBody(), true);

        self::assertIsArray($seen);
        self::assertSame('Bearer secret-token', $seen['authorization']);
    }

    public function testTheConfiguredUserAgentIsUsed(): void
    {
        $seen = $this->echo($this->transport(new ClientOptions(userAgent: 'my-app/9.9')));

        self::assertSame('my-app/9.9', $seen['user_agent']);
    }

    public function testItSendsABodyWithItsContentType(): void
    {
        $seen = $this->echo($this->transport(), 'POST', '{"a":1}');

        self::assertSame('POST', $seen['method']);
        self::assertSame('{"a":1}', $seen['body']);
    }

    public function testABodylessRequestSendsNoContentLength(): void
    {
        // Setting POSTFIELDS unconditionally would make curl send
        // `Content-Length: 0` on every GET, and some servers read that as a
        // POST — the kind of bug that only shows up against a real one.
        $seen = $this->echo($this->transport());

        self::assertNull($seen['content_length']);
    }

    public function testATimeoutIsATransportFailureNotAResponse(): void
    {
        // /slow sleeps a second; this client waits 150ms.
        $transport = $this->transport(new ClientOptions(timeout: 0.15, connectTimeout: 0.15));

        try {
            $transport->sendRequest($this->request('GET', '/slow'));
            self::fail('a timeout should be a transport failure');
        } catch (TransportFailed $problem) {
            self::assertSame('transport_failed', $problem->code());
            self::assertStringContainsString('timed out', $problem->getMessage());
            // The request is kept for the PSR-18 interface and printed nowhere.
            self::assertSame('GET', $problem->getRequest()->getMethod());
        }
    }

    public function testAnUnreachableHostIsATransportFailure(): void
    {
        // Port 1 is privileged and nothing is listening on it, so the kernel
        // refuses the connection immediately rather than letting it hang.
        $transport = $this->transport(new ClientOptions(timeout: 2.0, connectTimeout: 2.0));
        $request = (new Psr17Factory())->createRequest('GET', 'http://127.0.0.1:1/x');

        $this->expectException(TransportFailed::class);
        $transport->sendRequest($request);
    }

    public function testATruncatedResponseIsATransportFailure(): void
    {
        // The server promises 100 bytes and sends 5. curl reports errno 18 and
        // returns false — no complete response arrived, so this is the failure
        // the pack retries.
        try {
            $this->transport()->sendRequest($this->request('GET', '/drop?id=' . LocalServer::flakyId()));
            self::fail('a truncated response should be a transport failure');
        } catch (TransportFailed $problem) {
            self::assertSame('transport_failed', $problem->code());
        }
    }

    // ── What the transport refuses on its own (security review, 2026-09-20) ──

    public function testTheTransportRefusesANonHttpSchemeItself(): void
    {
        // HttpClient checks this too, but this class is public and documented
        // as the one an app may take for an unadorned request — and curl here
        // speaks thirty-two protocols, of which `gopher://` writes attacker
        // bytes to an internal TCP port and `file://` reads the disk.
        foreach (['gopher://127.0.0.1:1/_x', 'file:///etc/passwd'] as $url) {
            $request = (new Psr17Factory())->createRequest('GET', $url);

            try {
                $this->transport()->sendRequest($request);
                self::fail("{$url} must be refused by the transport itself");
            } catch (BadRequestUrl $refused) {
                self::assertSame('bad_request_url', $refused->code());
                self::assertStringContainsString('is not http or https', $refused->getMessage());
            }
        }
    }

    public function testAMethodCarryingALineBreakNeverReachesCurl(): void
    {
        $request = new LenientRequest("GET / HTTP/1.1\r\nX-Injected: yes\r\n\r\nGET", self::$server->url('/ok'));

        $this->expectException(UnsendableRequest::class);
        $this->transport()->sendRequest($request);
    }

    public function testAHeaderCarryingALineBreakNeverReachesCurl(): void
    {
        // nyholm refuses this before the pack sees it, which is why the double
        // exists: `sendRequest()` takes any PSR-7 request, including one whose
        // implementation validates nothing.
        $request = (new LenientRequest('GET', self::$server->url('/echo')))
            ->withRawHeader('X-Note', "ok\r\nX-Injected: yes");

        try {
            $this->transport()->sendRequest($request);
            self::fail('a header value carrying CRLF must be refused');
        } catch (UnsendableRequest $refused) {
            self::assertSame('unsendable_request', $refused->code());
            self::assertStringContainsString('X-Note', $refused->getMessage());
            self::assertStringNotContainsString('X-Injected', $refused->getMessage(), 'the value is never printed');
        }
    }

    // ── The response ceiling ────────────────────────────────────────────────

    public function testAResponseOverTheCeilingIsAProblemRatherThanADeadWorker(): void
    {
        $transport = $this->transport(new ClientOptions(timeout: 5.0, connectTimeout: 2.0, maxResponseBytes: 50_000));

        try {
            $transport->sendRequest($this->request('GET', '/big?bytes=400000'));
            self::fail('a response over the ceiling must be refused');
        } catch (ResponseTooLarge $refused) {
            self::assertSame('response_too_large', $refused->code());
            self::assertSame(502, $refused->httpStatus());
            self::assertSame(50_000, $refused->context['limit_bytes']);
            self::assertGreaterThan(50_000, $refused->context['received_bytes']);
        }
    }

    public function testTheCeilingHoldsWhenTheUpstreamDeclaresNoLength(): void
    {
        // The case CURLOPT_MAXFILESIZE cannot see: with no Content-Length curl
        // only knows the size once it has already read it.
        $transport = $this->transport(new ClientOptions(timeout: 5.0, connectTimeout: 2.0, maxResponseBytes: 50_000));

        $this->expectException(ResponseTooLarge::class);
        $transport->sendRequest($this->request('GET', '/big-unknown?bytes=400000'));
    }

    public function testAResponseUpToTheCeilingIsDeliveredWhole(): void
    {
        $transport = $this->transport(new ClientOptions(timeout: 5.0, connectTimeout: 2.0, maxResponseBytes: 1_000));

        $response = $transport->sendRequest($this->request('GET', '/big?bytes=1000'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1_000, strlen((string) $response->getBody()), 'the boundary itself is not over it');
    }
}
