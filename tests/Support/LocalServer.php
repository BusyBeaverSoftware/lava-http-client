<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Support;

/**
 * A real HTTP server for the tests that need one.
 *
 * The pack's live tests could not use core's `ServedApp` helper: that one runs
 * `lava serve`, which boots a fixture app through the CLI — so a pack that
 * depended on it would stop being installable on its own, which is the claim
 * every pack in this repo has to keep. This harness is deliberately the
 * smallest thing that works: `php -S`, a router script in `tests/fixtures/`,
 * and a port.
 *
 * **One server per test process, stopped by a shutdown function.** Starting one
 * per test would add a few hundred milliseconds to each of them for no
 * isolation the tests need — the routes are read-only apart from `/flaky`,
 * which keys its counter by a caller-supplied id precisely so two tests cannot
 * interfere. The shutdown function is what keeps a failed test run from leaving
 * an orphaned server behind.
 */
final class LocalServer
{
    /** @var resource|null */
    private $process;

    private static ?self $shared = null;

    /**
     * @param resource $handle
     */
    private function __construct(
        private readonly string $base,
        private readonly string $log,
        mixed $handle,
    ) {
        /** @var resource $handle */
        $this->process = $handle;
    }

    /** Starts the server the first time it is asked for, and reuses it after. */
    public static function shared(): self
    {
        if (self::$shared !== null) {
            return self::$shared;
        }

        $router = dirname(__DIR__) . '/fixtures/server/router.php';
        $port = self::freePort();
        $log = (string) tempnam(sys_get_temp_dir(), 'lava-http-server-');

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            dirname($router),
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('could not start the fixture HTTP server');
        }

        // The write end of stdin is ours and nothing will ever use it; leaving
        // it open keeps a pipe descriptor alive for the life of the process.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $server = new self("http://127.0.0.1:{$port}", $log, $process);

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            if ($server->body('/ok') === 'ok') {
                self::$shared = $server;
                register_shutdown_function(static fn (): bool => $server->stop());

                return $server;
            }
            usleep(50_000);
        }

        $server->stop();

        throw new \RuntimeException(
            "the fixture HTTP server did not come up on port {$port}.\n"
            . (string) file_get_contents($log),
        );
    }

    /** An absolute URL for a fixture path, e.g. `/json`. */
    public function url(string $path): string
    {
        return $this->base . $path;
    }

    public function base(): string
    {
        return $this->base;
    }

    /** A unique id for `/flaky`, so tests never share a counter. */
    public static function flakyId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Where the fixture server keeps a counter, so a test can read one.
     *
     * The prefix is duplicated from `tests/fixtures/server/router.php` on
     * purpose: the server is a separate process with no autoloader, so it
     * cannot be asked. The name is asserted by a test, which is what keeps the
     * two copies honest.
     */
    public static function counterFile(string $name, string $id): string
    {
        return sys_get_temp_dir() . "/lava-http-fixture-{$name}-{$id}";
    }

    public static function counter(string $name, string $id): int
    {
        $file = self::counterFile($name, $id);

        return is_file($file) ? (int) file_get_contents($file) : 0;
    }

    /**
     * A plain `file_get_contents` GET, for the harness's own readiness check.
     *
     * Deliberately not the pack's client: the harness must be able to say "the
     * server is not up" without the code under test being involved in the
     * answer.
     */
    public function body(string $path): ?string
    {
        $context = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);
        $body = @file_get_contents($this->url($path), false, $context);

        return $body === false ? null : $body;
    }

    public function stop(): bool
    {
        if ($this->process === null) {
            return true;
        }

        proc_terminate($this->process);

        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if ($status['running'] === false) {
                break;
            }
            usleep(20_000);
        }

        proc_close($this->process);
        $this->process = null;

        return true;
    }

    /**
     * A port nobody is listening on, found by binding one and letting it go.
     *
     * There is a race — the port could be taken between the close and the
     * server's bind — and it is the accepted one: the alternative is a
     * hardcoded port that collides with whatever else the machine is running,
     * and the failure here is a clear "did not come up" with the server's own
     * log attached.
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        if ($socket === false) {
            throw new \RuntimeException("could not find a free port: {$message} ({$code})");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($name)) {
            throw new \RuntimeException('could not read the bound port');
        }

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }
}
