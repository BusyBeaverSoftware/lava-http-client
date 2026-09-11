<?php

declare(strict_types=1);

namespace Lava\HttpClient\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\HttpClient\Url;

/**
 * A 2xx arrived, and its body is not JSON.
 *
 * The most common cause is not a broken API: it is a URL that hit something
 * else. A path with a typo, a base URL missing a prefix, a proxy or a captive
 * portal answering with an HTML page — all of them return 200 and a document
 * full of `<html>`. The excerpt is what tells those apart in one reading, so
 * the first thing the message does is show the beginning of what actually came
 * back.
 *
 * A valid JSON *scalar* counts as this problem too, because these calls return
 * `array<string, mixed>`: `"ok"` and `42` decode cleanly and are still not
 * something a caller can index. The `json_error` field says which of the two
 * happened, so the fix can be honest about it.
 */
final class BadJsonResponse extends LavaProblem
{
    private const EXCERPT = 300;

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
     * @param string $reason what `json_decode` said, or a description of the
     *                       scalar that decoded successfully
     */
    public static function of(string $method, string $url, int $status, string $body, string $reason): self
    {
        $url = Url::redact($url);
        $excerpt = self::excerpt($body);

        return new self(
            "{$method} {$url} returned {$status} but its body is not a JSON object: {$reason}.",
            'Check the path — a 2xx that is not JSON usually means the request reached the wrong place '
            . '(an HTML error page, a redirect target, a proxy). If the endpoint really does return something '
            . 'else, call get() or post() instead and read the body yourself; those do not decode. '
            . 'The beginning of what came back is in the report as \'body\'.',
            ['method' => $method, 'url' => $url, 'status' => $status, 'body' => $excerpt, 'json_error' => $reason],
        );
    }

    private static function excerpt(string $body): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $body));

        return strlen($flat) <= self::EXCERPT ? $flat : substr($flat, 0, self::EXCERPT) . '…';
    }

    public function code(): string
    {
        return 'bad_json_response';
    }

    /** The upstream returned something unusable, so the app's response is a gateway error. */
    public function httpStatus(): int
    {
        return 502;
    }
}
