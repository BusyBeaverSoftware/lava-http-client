<?php

declare(strict_types=1);

namespace Lava\HttpClient;

use Lava\HttpClient\Problem\BadRequestUrl;
use Lava\HttpClient\Problem\ResponseTooLarge;
use Lava\HttpClient\Problem\TransportFailed;
use Lava\HttpClient\Problem\UnsendableRequest;
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

        // The two things curl cannot express at all, kept ahead of the rules
        // below because they are about the request object rather than about
        // what may be sent — and because everything after this point may
        // assume a URL and a method exist.
        if ($url === '' || $method === '') {
            throw TransportFailed::of(
                $request,
                $url === '' ? 'the request has no URL' : 'the request has no method',
            );
        }

        // `HttpClient` makes these same three checks before it gets here, and
        // they are repeated because this class is public, documented, and
        // reachable on its own: an app that takes `CurlTransport` for one
        // unadorned request would otherwise have no scheme rule at all, and
        // curl speaks thirty-two protocols (Lava Notes security review,
        // 2026-09-20). A guard that only runs on the path most callers take is
        // not a guard.
        $badMethod = Url::whyBadMethod($method);
        if ($badMethod !== null) {
            throw UnsendableRequest::method($request, $method, $badMethod);
        }

        $unusable = Url::whyUnusable($url);
        if ($unusable !== null) {
            throw BadRequestUrl::of($request, $unusable);
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

        // The body is collected here, a chunk at a time, instead of being
        // returned in one piece by `curl_exec()`: that is the only way to stop
        // reading. `CURLOPT_MAXFILESIZE` is not enough on its own, because it
        // believes `Content-Length`, and an upstream that declares nothing —
        // or lies — is exactly the one worth stopping.
        $collected = '';
        $received = 0;
        $limit = $this->options->maxResponseBytes;
        $overflowed = false;
        $write = static function (\CurlHandle $_, string $chunk) use (&$collected, &$received, &$overflowed, $limit): int {
            $received += strlen($chunk);
            if ($received > $limit) {
                // Any number that is not the chunk's length aborts the transfer;
                // -1 is the conventional one, and curl then reports errno 23.
                $overflowed = true;

                return -1;
            }

            $collected .= $chunk;

            return strlen($chunk);
        };

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => $write,
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

        // Belt and braces for the scheme rule above: even if a URL reached curl
        // unchecked, libcurl itself speaks nothing but HTTP here — which also
        // covers the scheme a redirect could name, whether or not following
        // them is ever turned on. libcurl gained the string form in 7.85; the
        // bitmask says the same thing on an older build.
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

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

        $completed = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        // Before the transport check below, because an abandoned transfer looks
        // exactly like a failed one from curl's side — and "the body was too
        // big" is an answer a caller can act on, while curl's own errno 23
        // ("failed writing body") names this pack's own callback and explains
        // nothing.
        if ($overflowed) {
            throw ResponseTooLarge::of($request, $limit, $received);
        }

        // With a write callback in place a completed transfer returns true and
        // the body is in `$collected`; `false` means the transfer failed. Either
        // way a response code of 0 means no complete response arrived —
        // including the truncated-response case, where curl reports errno 18
        // and no body at all.
        if ($completed === false || $status === 0) {
            throw TransportFailed::of(
                $request,
                $error !== '' ? $error : 'curl returned no complete response',
            );
        }

        $response = $this->factory->createResponse($status)
            ->withBody($this->factory->createStream($collected));

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
     * **Except a line break.** A name or value carrying CR, LF or NUL is
     * refused rather than concatenated: libcurl writes these lines into the
     * header block verbatim, so a `\r\n` in a value ends the header and starts
     * whatever follows it — a second header, or a whole second request. The
     * pack's own array API is protected by the PSR-7 implementation, but
     * `sendRequest()` is the PSR-18 boundary and takes any request object,
     * including one whose implementation validates nothing (Lava Notes security
     * review, 2026-09-20).
     *
     * @return list<string>
     *
     * @throws UnsendableRequest when a header would split the request
     */
    private static function headerLines(RequestInterface $request): array
    {
        $lines = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (strcasecmp($name, 'Host') === 0) {
                continue;
            }
            foreach ($values as $value) {
                if (preg_match('/[\r\n\x00]/', $name . $value) === 1) {
                    throw UnsendableRequest::header($request, $name);
                }

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
