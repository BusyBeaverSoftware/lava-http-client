<?php

declare(strict_types=1);

namespace Lava\HttpClient\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\HttpClient\Url;

/**
 * The body you asked this call to send could not be turned into JSON.
 *
 * `json_encode()` fails for real reasons — a string with invalid UTF-8 (a
 * latin-1 column straight out of a database is the classic), a float that is
 * `NAN` or `INF`, a resource, a structure that contains itself. None of them
 * are visible at the call site, and without this problem the raw `JsonException`
 * escapes the pack with a message that names neither the endpoint nor the call.
 *
 * **The payload is deliberately absent from this problem's context.** It is the
 * single most credential-bearing value in the pack — a `postJson()` body is
 * where a login sends a password and a webhook sends its signing secret — and a
 * problem report is a log line, an error page and a `--json` body that gets
 * pasted into an issue. The `json_encode` error text describes the *shape* of
 * the fault ("Malformed UTF-8 characters"), which is the part a reader can act
 * on, so the payload itself is never needed and never printed.
 */
final class UnencodableJsonBody extends LavaProblem
{
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

    public static function of(string $method, string $url, string $reason): self
    {
        $url = Url::redact($url);

        return new self(
            "The body for {$method} {$url} could not be encoded as JSON: {$reason}.",
            'Find the value that will not encode — usually a string that is not UTF-8, or a float that is NAN. '
            . 'Encode each field on its own to find it: json_encode(\'x\', JSON_THROW_ON_ERROR) names the '
            . 'failing value. Convert it at the edge (mb_convert_encoding) rather than at the call.',
            ['method' => $method, 'url' => $url, 'reason' => $reason],
        );
    }

    public function code(): string
    {
        return 'unencodable_json_body';
    }

    /** Our own code built an unusable value: a 500, and the fix is in the app. */
    public function httpStatus(): int
    {
        return 500;
    }
}
