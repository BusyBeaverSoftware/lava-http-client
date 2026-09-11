<?php

declare(strict_types=1);

namespace Lava\HttpClient;

use Lava\HttpClient\Problem\TransportFailed;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The ext-curl sender: one request in, one response out, no opinions.
 *
 * Everything the pack *decides* — whether a URL is sendable, whether a method
 * may be retried, whether a status is acceptable — lives in
 * {@see HttpClient}, which sits in front of this class. That split is what lets
 * an app replace the sender without losing the decisions: hand
 * `HttpClient` any PSR-18 client and the retry rule, the URL guard and the
 * problem types all still apply.
 *
 * The two PSR-17 capabilities are one parameter rather than two because they
 * are one dependency: a single factory object that can build responses and
 * streams. `Nyholm\Psr7\Factory\Psr17Factory` — the implementation core already
 * uses — satisfies both, so the pack never names it here and a test can pass
 * any pair-in-one it likes.
 *
 * **`curl_close()` is not called.** Since PHP 8.0 a curl handle is an object
 * that frees itself when the last reference goes, and the function is a
 * deprecated no-op; calling it would add a deprecation notice to every request
 * for nothing.
 */
final class CurlTransport implements ClientInterface
{
    public function __construct(
        private readonly ClientOptions $options,
        private readonly ResponseFactoryInterface&StreamFactoryInterface $factory,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $method = $request->getMethod();

        // `HttpClient` refuses an unusable URL before it gets here, but this
        // class is public and a caller may use it directly — so it refuses the
        // two things curl cannot express at all rather than handing them over
        // and reporting whatever curl says about them.
        if ($url === '' || $method === '') {
            throw TransportFailed::of(
                $request,
                $url === '' ? 'the request has no URL' : 'the request has no method',
            );
        }

        $handle = curl_init();
        if ($handle === false) {
            throw TransportFailed::of($request, 'curl_init() failed');
        }

        /** @var array<string, list<string>> $headers */
        $headers = [];
        $collect = static function (\CurlHandle $_, string $line) use (&$headers): int {
            $trimmed = trim($line);

            // The blank line that terminates a header block is not a header and
            // must not reset anything: it arrives *after* every real header, so
            // treating it as a reset would discard the whole response's headers
            // one call before the function is last used.
            if ($trimmed === '') {
                return strlen($line);
            }

            // A status line, on the other hand, starts a new block — which
            // happens on a 1xx interim response, surfaced even though redirects
            // are not followed.
            if (str_starts_with($trimmed, 'HTTP/')) {
                $headers = [];

                return strlen($line);
            }

            $colon = strpos($trimmed, ':');
            if ($colon !== false) {
                $headers[substr($trimmed, 0, $colon)][] = trim(substr($trimmed, $colon + 1));
            }

            return strlen($line);
        };

        $body = (string) $request->getBody();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            // A redirect is a response, and PSR-18 says return it. Following it
            // here would hide the 301 from the caller and, on a redirect to
            // another host, silently resend the Authorization header somewhere
            // it was never meant to go.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => self::ms($this->options->timeout),
            CURLOPT_CONNECTTIMEOUT_MS => self::ms($this->options->connectTimeout),
            CURLOPT_HTTPHEADER => self::headerLines($request),
            CURLOPT_HEADERFUNCTION => $collect,
        ];

        // An empty configured agent is left to curl rather than sent as a blank
        // header: `User-Agent: ` is refused outright by some servers, and
        // curl's own default identifies the client better than nothing does.
        if ($this->options->userAgent !== '') {
            $options[CURLOPT_USERAGENT] = $this->options->userAgent;
        }

        // Only when there is one: setting POSTFIELDS on a bodyless GET makes
        // curl send `Content-Length: 0` and some servers treat that as a POST.
        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        // `curl_exec` returns false when the transfer failed and true only in
        // the (unused) output-to-stdout mode, so anything that is not a string
        // means no complete response arrived — including the truncated-response
        // case, where curl reports errno 18 and no body at all.
        if (!is_string($raw) || $status === 0) {
            throw TransportFailed::of(
                $request,
                $error !== '' ? $error : 'curl returned no complete response',
            );
        }

        $response = $this->factory->createResponse($status)
            ->withBody($this->factory->createStream($raw));

        foreach ($headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response;
    }

    /**
     * The request's headers as curl wants them.
     *
     * `Host` is dropped because curl derives it from the URL, and sending a
     * second one alongside its own is a request-smuggling shape that some
     * servers reject outright. Everything else, `Authorization` included, is
     * passed through — the client's job is to send what it was given.
     *
     * @return list<string>
     */
    private static function headerLines(RequestInterface $request): array
    {
        $lines = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (strcasecmp($name, 'Host') === 0) {
                continue;
            }
            foreach ($values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }

        return $lines;
    }

    private static function ms(float $seconds): int
    {
        return max(1, (int) round($seconds * 1000));
    }
}
