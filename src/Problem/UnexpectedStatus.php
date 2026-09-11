<?php

declare(strict_types=1);

namespace Lava\HttpClient\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\HttpClient\Url;

/**
 * The response arrived, and its status is not one the caller asked to accept.
 *
 * **This is the pack's own opinion, not PSR-18's.** `sendRequest()` returns a
 * 404 response like any other, because that is what PSR-18 promises and what a
 * caller doing its own status handling needs. The judgement "a non-2xx is a
 * failure" belongs to `getJson()` / `postJson()` / `json()`, which promise a
 * decoded body and cannot return one from an error page — so they raise this
 * instead of handing back the HTML of a 500 as if it were data.
 *
 * The body is included, bounded, in `context['body']` — and `context` is a
 * rendered field, not a debug one: both the CLI renderer and the JSON envelope
 * print it. Without it a 500 from a third party is a number with no diagnosis;
 * with it, the service's own error message is right there in the report. It
 * stays out of the message because the message is one sentence by contract, and
 * a body pasted into it would make every other line of a report unreadable. It
 * is the *response* body — the remote's own words about its own failure — which
 * is a different kind of thing from the request, and the request is what is
 * never printed (see {@see TransportFailed}).
 */
final class UnexpectedStatus extends LavaProblem
{
    /** Enough of a body to see the error, not enough to fill a terminal. */
    private const EXCERPT = 400;

    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        string $message,
        string $fix,
        array $context,
    ) {
        parent::__construct($message, $fix, $context);
    }

    /**
     * @param string $reason the response's own reason phrase, from the wire
     */
    public static function of(string $method, string $url, int $status, string $body, string $reason = ''): self
    {
        $url = Url::redact($url);
        $excerpt = self::excerpt($body);
        $phrase = trim($reason) === '' ? '' : ' ' . trim($reason);

        return new self(
            "{$method} {$url} returned {$status}{$phrase}, and this call requires a 2xx.",
            self::fixFor($status),
            [
                'method' => $method,
                'url' => $url,
                'status' => $status,
                'body' => $excerpt,
            ],
        );
    }

    /**
     * The fix, by what the status means.
     *
     * A generic "the request failed" is the one thing that is never useful here:
     * a 404, a 401 and a 503 are three different mistakes in three different
     * places, and the status is already known when the message is written, so
     * there is no reason to make the reader translate it.
     */
    private static function fixFor(int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'The service refused the credentials. Check the token or key '
                . 'this call sends — usually a config value, not the code.',
            $status === 404 => 'The path does not exist on that host. Check it against the service\'s API docs; '
                . 'a base URL that ends in the right host but the wrong path prefix is the usual cause.',
            $status === 405 => 'The host does not allow this HTTP method on that path. Check which verb the '
                . 'endpoint expects — a search that is a GET, a create that is a POST.',
            $status === 429 => 'The service is rate-limiting this caller. Retrying immediately makes it worse: '
                . 'slow the caller down, or ask the service for a higher quota.',
            $status >= 500 => 'The remote service failed. This is not a fault in your request — the response body '
                . 'above is the service explaining itself. Retry later, or check the service\'s status page.',
            default => 'Check the response body above, then the request this call builds: the service rejected it.',
        };
    }

    /** One line, bounded, with the elision visible so nobody hunts for the missing part. */
    private static function excerpt(string $body): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $body));

        return strlen($flat) <= self::EXCERPT ? $flat : substr($flat, 0, self::EXCERPT) . '…';
    }

    public function code(): string
    {
        return 'unexpected_status';
    }

    /** The upstream failed, so the app's response is a gateway error, not a 500 of its own. */
    public function httpStatus(): int
    {
        return 502;
    }
}
