<?php

declare(strict_types=1);

namespace Lava\HttpClient;

use Lava\Core\Problem\LavaProblem;
use Lava\HttpClient\Problem\BadJsonResponse;
use Lava\HttpClient\Problem\BadRequestUrl;
use Lava\HttpClient\Problem\TransportFailed;
use Lava\HttpClient\Problem\UnencodableJsonBody;
use Lava\HttpClient\Problem\UnexpectedStatus;
use Lava\HttpClient\Problem\UnsendableRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The pack's client: a PSR-18 client with two rules and a JSON shortcut.
 *
 * Two rules, and both exist because the alternative is a failure that is
 * invisible until production:
 *
 * **A method that is not idempotent is never retried.** Retrying is safe
 * because a GET sent twice is a GET sent once, and it is not safe because a
 * POST sent twice is two creates — a duplicate charge, a duplicate email. The
 * pack can see the method, so it can make the distinction itself instead of
 * leaving it to every call site to remember. `$options->retries` is therefore
 * a ceiling, not a promise: it applies to `GET`, `HEAD`, `PUT`, `DELETE`,
 * `OPTIONS` and `TRACE` (the methods RFC 9110 calls idempotent) and to nothing
 * else, whatever it is set to.
 *
 * **The retry boundary is "no response arrived".** `sendRequest()` retries a
 * `NetworkExceptionInterface` — a connection refused, a DNS failure, a timeout
 * — and never a response, whatever its status. A 500 is returned to the caller
 * like any other response, because PSR-18 says a response is a result, and
 * because retrying a 5xx without honouring `Retry-After` and without jitter is
 * how a client turns one slow service into a stampede. `UnexpectedStatus` is
 * where a bad status becomes an error, and it is raised only by the JSON calls,
 * which promise a body and cannot return one from an error page.
 *
 * The rest is the shortcut: `getJson()` / `postJson()` / `json()` fetch, check
 * for a 2xx, decode, and fail with a problem that says which of those three
 * steps went wrong.
 */
final class HttpClient implements ClientInterface
{
    /**
     * The methods RFC 9110 defines as idempotent — sending one twice has the
     * same effect as sending it once, so a second attempt cannot cause harm.
     */
    private const IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'];

    public function __construct(
        private readonly ClientInterface $transport,
        private readonly RequestFactoryInterface&StreamFactoryInterface $factory,
        private readonly ClientOptions $options = new ClientOptions(),
    ) {
    }

    /**
     * One request, with retries when they are safe, and the response whatever
     * its status.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();

        // Before the transport, so the rule holds for any transport — including
        // the one a test injects. See Url::whyUnusable() for why the scheme is
        // checked at all.
        $reason = Url::whyUnusable($url);
        if ($reason !== null) {
            throw BadRequestUrl::of($request, $reason);
        }

        // Same place, same reason: the method reaches the wire verbatim, and a
        // line break in it puts a second request there. See Url::whyBadMethod().
        $method = $request->getMethod();
        $badMethod = Url::whyBadMethod($method);
        if ($badMethod !== null) {
            throw UnsendableRequest::method($request, $method, $badMethod);
        }

        $attempts = $this->attemptsFor($request);
        $failure = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($attempt > 1 && $this->options->backoffMs > 0) {
                usleep($this->options->backoffMs * 1000);
            }

            // A retry re-sends the body, and the body is a stream that the last
            // attempt read to the end — so it is rewound first, or the second
            // attempt would send nothing at all. `attemptsFor()` has already
            // refused to retry a stream that cannot be rewound.
            if ($attempt > 1) {
                $request->getBody()->rewind();
            }

            try {
                return $this->transport->sendRequest($request);
            } catch (NetworkExceptionInterface $failed) {
                $failure = $failed;
            }
        }

        // A transport that already reports in this pack's own vocabulary is
        // rethrown untouched, so its redacted context and its `getRequest()`
        // survive. Only a foreign PSR-18 exception needs wrapping — the same
        // rule lavaphp/db and lavaphp/view follow: a wrapper wraps what is not
        // already a LavaProblem.
        if ($failure instanceof LavaProblem) {
            throw $failure;
        }

        throw TransportFailed::of(
            $request,
            $failure === null ? 'the transport failed without a reason' : $failure->getMessage(),
        );
    }

    /**
     * A plain request, returning the response for you to inspect.
     *
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): ResponseInterface
    {
        $request = $this->factory->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== '') {
            $request = $request->withBody($this->factory->createStream($body));
        }

        return $this->sendRequest($request);
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $url, $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, string $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('POST', $url, $headers, $body);
    }

    /**
     * A request whose response must be JSON, decoded to an array.
     *
     * An empty `$body` sends **no body at all**, not `{}` — so `postJson($url, [])`
     * is a POST with an empty body, and `getJson()` is a GET that sends nothing.
     * One rule for every caller, rather than a special case per verb.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function json(string $method, string $url, array $body = [], array $headers = []): array
    {
        $payload = '';

        if ($body !== []) {
            $payload = self::encode($method, $url, $body);
            // A Content-Type the caller set is kept — an API that wants
            // `application/vnd.api+json` is not a mistake to correct — and the
            // comparison ignores case, because a header name does: `+=` on the
            // array replaced a caller's lowercase `content-type` while honouring
            // `Content-Type`, which is two behaviours for one header.
            if (!self::names($headers, 'Content-Type')) {
                $headers['Content-Type'] = 'application/json';
            }
        }

        $response = $this->request($method, $url, $headers, $payload);
        $status = $response->getStatusCode();
        $text = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            throw UnexpectedStatus::of($method, $url, $status, $text, $response->getReasonPhrase());
        }

        return self::decode($method, $url, $status, $text);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function getJson(string $url, array $headers = []): array
    {
        return $this->json('GET', $url, [], $headers);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $body = [], array $headers = []): array
    {
        return $this->json('POST', $url, $body, $headers);
    }

    /**
     * How many times this request may be sent: once, unless the method is
     * idempotent AND the body can be sent again.
     *
     * The body half is the quieter rule. A `PUT` is idempotent, so the method
     * allows a retry — but if its body is a stream that cannot be rewound (a
     * pipe, a socket, an upload being forwarded), the first attempt consumed
     * it, and every attempt after that would send an EMPTY body to an endpoint
     * that would accept it happily. A truncated upload that reports success is
     * worse than a failed one, so a one-shot body is sent exactly once and the
     * transport's failure is reported as it stands (Lava Notes security review,
     * 2026-09-20).
     */
    private function attemptsFor(RequestInterface $request): int
    {
        if (!in_array(strtoupper($request->getMethod()), self::IDEMPOTENT, true)) {
            return 1;
        }

        $body = $request->getBody();
        if (!$body->isSeekable() && ($body->getSize() === null || $body->getSize() > 0)) {
            return 1;
        }

        return max(1, $this->options->retries + 1);
    }

    /**
     * Whether a header is already in the array, by HTTP's rule rather than
     * PHP's: header names are case-insensitive.
     *
     * @param array<string, string> $headers
     */
    private static function names(array $headers, string $header): bool
    {
        foreach (array_keys($headers) as $name) {
            if (strcasecmp((string) $name, $header) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function encode(string $method, string $url, array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $encoded) {
            // The payload is not passed on: see UnencodableJsonBody.
            throw UnencodableJsonBody::of($method, $url, $encoded->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private static function decode(string $method, string $url, int $status, string $text): array
    {
        try {
            $decoded = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $decoded) {
            throw BadJsonResponse::of($method, $url, $status, $text, $decoded->getMessage());
        }

        if (!is_array($decoded)) {
            // A scalar decodes fine and is still not indexable, so it is the
            // same problem with a different reason — the field is honest about
            // which of the two happened.
            throw BadJsonResponse::of(
                $method,
                $url,
                $status,
                $text,
                'it decoded to ' . get_debug_type($decoded) . ', and these calls return an object or array',
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
