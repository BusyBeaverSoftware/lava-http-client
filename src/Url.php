<?php

declare(strict_types=1);

namespace Lava\HttpClient;

/**
 * The questions about a request line that the client, the transport and three
 * problem factories all have to ask, answered once.
 *
 * They live together because they are the same question seen from two sides —
 * "can this be sent, and what may be printed about it" — and because a second
 * copy of either answer would drift from the first. The redaction rule in
 * particular is only worth anything if it cannot be bypassed: every place
 * this pack prints a URL prints it through {@see redact()}.
 */
final class Url
{
    private function __construct()
    {
    }

    /**
     * Why this URL cannot be fetched, or null when it can.
     *
     * Returns the reason rather than a bool because the reason is the problem's
     * message: "it is not an absolute URL" is a fix, and `false` is not.
     *
     * **The scheme check is a security rule, not a formality.** curl will
     * happily fetch `file:///etc/passwd` and `gopher://…`, and this pack's URLs
     * are exactly the kind of value that arrives from outside — a webhook
     * target, a callback, a URL read out of a row. An HTTP client that fetches
     * whatever scheme it is handed is a file-disclosure and SSRF primitive. So
     * the pack accepts `http` and `https` and refuses everything else, with a
     * message that says which scheme it refused.
     */
    public static function whyUnusable(string $url): ?string
    {
        $parts = parse_url($url);

        // The scheme is checked FIRST, and the order is load-bearing:
        // `parse_url('file:///etc/passwd')` has a scheme and no host, so a host
        // check that ran first would report "not an absolute URL" for a URL
        // whose real problem is that it is a file. The security rule has to
        // produce its own message or it is indistinguishable from a typo.
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        if (!is_string($scheme) || $scheme === '') {
            return 'it is not an absolute URL — it has no scheme';
        }

        $scheme = strtolower($scheme);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return "the scheme '{$scheme}' is not http or https, and this client fetches nothing else";
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || $host === '') {
            return 'it is not an absolute URL — it has no host';
        }

        return null;
    }

    /**
     * Why this method cannot be sent, or null when it can.
     *
     * **This is a security rule too.** The method reaches curl as
     * `CURLOPT_CUSTOMREQUEST`, which libcurl writes into the request line
     * verbatim, and neither PSR-7 nor libcurl validates it. A method carrying
     * `\r\n` therefore ends the request line early and the rest of the string
     * becomes *a second request of the attacker's choosing* — path, headers and
     * body included. Any app that forwards an inbound method to an upstream
     * hands that control to whoever made the inbound request, so the check is
     * here rather than at each call site (Lava Notes security review, 2026-09-20).
     *
     * The rule is RFC 9110's `token`: the characters a method is allowed to be
     * made of, which excludes every control character, space and separator.
     */
    public static function whyBadMethod(string $method): ?string
    {
        if ($method === '') {
            return 'it is empty';
        }

        // `\z`, not `$`: PCRE's `$` matches before a final newline, so `"GET\n"`
        // would pass a rule written the obvious way — and a bare LF is exactly
        // the byte that ends a request line early.
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $method) !== 1) {
            return 'it is not a method name — RFC 9110 allows only letters, digits and the characters !#$%&\'*+-.^_`|~';
        }

        return null;
    }

    /**
     * The URL with every credential in it replaced.
     *
     * Two places a URL carries a secret, and both are masked:
     *
     *  - **userinfo** — `https://user:token@host/…`. Basic-auth credentials in
     *    a URL are common in webhook and API configs, and the whole URL is what
     *    a problem report prints.
     *  - **query parameters with secret-shaped names** — `?token=…`,
     *    `?api_key=…`, `?signature=…`. The name list is deliberately generous:
     *    masking a query parameter that turns out to be harmless costs a little
     *    readability in one error message, while failing to mask a real token
     *    costs a leaked credential in a log, a CI transcript, and an issue
     *    someone pasted `--json` output into. The two mistakes are not
     *    symmetric, so the rule leans.
     *
     * **A secret word anywhere in the name counts.** The rule used to anchor on
     * a word boundary, and `_` is a word character, so it matched only names
     * that *began* with one of the words: `client_secret`, `refresh_token`,
     * `private_key` and `aws_secret_access_key` — the names OAuth 2 and the
     * cloud SDKs actually use — went out in clear, beside a masked `token=***`
     * that made the guard look like it had worked (Lava Notes security review,
     * 2026-09-20). The match is now anchored on the parameter's position, `?`
     * or `&`, so a prefix cannot defeat it.
     *
     * The userinfo match runs to the LAST `@` before the path, because a
     * password may contain one: `https://user:p@ssw0rd@host/x` masked to the
     * first `@` left the tail of the password in the string.
     *
     * This is the same rule `lavaphp/db` applies to DSNs, applied to the other
     * kind of string that carries credentials.
     */
    public static function redact(string $url): string
    {
        $url = (string) preg_replace(
            '#^([a-zA-Z][a-zA-Z0-9+.\-]*://)[^/?\#]*@#',
            '$1***@',
            $url,
        );

        return (string) preg_replace_callback(
            '/([?&][^=&\#\s]*(?:token|key|secret|pass(?:wd|word)?|pwd|signature|sig|auth|credential)[^=&\#\s]*)=[^&\#\s]*/i',
            static fn (array $match): string => $match[1] . '=***',
            $url,
        );
    }
}
