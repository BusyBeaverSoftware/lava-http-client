<?php

declare(strict_types=1);

namespace Lava\HttpClient;

use Lava\Core\Config\Config;
use Lava\Core\Problem\InvalidConfig;

/**
 * What the client does between attempts, and how long it waits — one value
 * object, so a request never has to be told twice.
 *
 * Built from `config/http_client.php` by {@see of()}, and constructed directly
 * by anything that wants different numbers for one call — a test that needs a
 * 200ms timeout, or an app that talks to a slow third party. The fields are
 * floats even though the config keys are whole seconds: a timeout you write in
 * a config file is "how many seconds am I willing to wait", and a fractional
 * one is a thing you write in code.
 *
 * **Nothing here is validated in the constructor.** The range checks live in
 * {@see of()}, because that is where the key name and the file are known, and
 * `Fix the value of 'timeout' in config/http_client.php` is the sentence a
 * reader can act on. A `new ClientOptions(timeout: -1)` in app code is the same
 * trust level as any other literal in the same file.
 */
final readonly class ClientOptions
{
    public function __construct(
        /** Total seconds for one attempt, connect time included. */
        public float $timeout = 10.0,
        /** Seconds allowed for the connection alone — a host that accepts TCP and then says nothing. */
        public float $connectTimeout = 5.0,
        /**
         * Extra attempts after the first, for idempotent methods only.
         *
         * Two by default, and the default is safe because the METHOD decides
         * whether it applies — see {@see HttpClient}. A POST is never retried
         * however this is set, so the number cannot turn a create into two.
         */
        public int $retries = 2,
        /** Milliseconds between attempts. Fixed, not exponential: a deterministic delay is a testable one. */
        public int $backoffMs = 100,
        public string $userAgent = 'lavaphp/http-client',
        /**
         * The most a response body may weigh, in bytes.
         *
         * A ceiling exists because the alternative is not a slow request, it is
         * a dead worker: the body is buffered in memory, so an upstream that
         * answers with more than `memory_limit` kills the process with a fatal
         * error — which no problem type can catch and no error page can render.
         * A timeout does not bound this; a slow dribble of bytes exhausts the
         * limit inside any time budget (Lava Notes security review, 2026-09-20).
         *
         * 8 MiB because this client is for APIs, and a JSON payload larger than
         * that is a file transfer wearing an API's clothes — an app that really
         * wants one raises this for that call and knows to watch its memory.
         */
        public int $maxResponseBytes = 8_388_608,
    ) {
    }

    /**
     * Read the pack's config keys, with defaults, failing loudly on anything
     * unusable.
     *
     * The type checks are `Config`'s own (`http_client.retries` set to `'lots'`
     * is `invalid_config` naming the key and the file). The range checks are
     * here, because `-5` is a perfectly good int and `Config::int()` cannot
     * refuse it — and a negative timeout reaches curl, which rejects it on the
     * first request with a message that names neither the key nor the file.
     */
    public static function of(Config $config): self
    {
        $timeout = $config->int('http_client.timeout', 10);
        self::positive($config, 'http_client.timeout', $timeout, 'a positive number of seconds');

        $connectTimeout = $config->int('http_client.connect_timeout', 5);
        self::positive($config, 'http_client.connect_timeout', $connectTimeout, 'a positive number of seconds');

        $retries = $config->int('http_client.retries', 2);
        if ($retries < 0) {
            throw InvalidConfig::outOfRange(
                'http_client.retries',
                $retries,
                'zero or more attempts',
                self::file($config, 'http_client.retries'),
            );
        }

        $backoff = $config->int('http_client.backoff_ms', 100);
        if ($backoff < 0) {
            throw InvalidConfig::outOfRange(
                'http_client.backoff_ms',
                $backoff,
                'zero or more milliseconds',
                self::file($config, 'http_client.backoff_ms'),
            );
        }

        $maxResponseBytes = $config->int('http_client.max_response_bytes', 8_388_608);
        self::positive($config, 'http_client.max_response_bytes', $maxResponseBytes, 'a positive number of bytes');

        return new self(
            timeout: (float) $timeout,
            connectTimeout: (float) $connectTimeout,
            retries: $retries,
            backoffMs: $backoff,
            userAgent: $config->string('http_client.user_agent', 'lavaphp/http-client'),
            maxResponseBytes: $maxResponseBytes,
        );
    }

    private static function positive(Config $config, string $key, int $value, string $expected): void
    {
        if ($value <= 0) {
            throw InvalidConfig::outOfRange($key, $value, $expected, self::file($config, $key));
        }
    }

    /**
     * The file a key came from, for the fix text.
     *
     * From `provenance()`, not a literal: the pack's config file is optional, so
     * a value the app never set has no file at all — and the fallback names the
     * file the app would have to create, which is the honest answer for "this
     * key is out of range but you never wrote it".
     */
    private static function file(Config $config, string $key): string
    {
        return $config->provenance($key) ?? 'config/http_client.php';
    }
}
