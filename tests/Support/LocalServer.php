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
    /**
     * The counter-file prefix, duplicated from `tests/fixtures/server/router.php`
     * on purpose: the server is a separate process with no autoloader, so it
     * cannot be asked. A test asserts the two copies agree.
     */
    public const COUNTER_PREFIX = 'lava-http-fixture-';

    /** @var resource|null */
    private $process;

    private static ?self $shared = null;

    /**
     * The ids `flakyId()` has handed out, so `stop()` can remove the counter
     * files the router wrote for them. They are keyed by a random id, so nothing
     * will ever reuse one — without this they are litter that accumulates in
     * `/tmp` for the life of the machine.
     *
     * @var list<string>
     */
    private static array $counters = [];

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

        // Read the log BEFORE stopping: `stop()` deletes it, and the log is the
        // only thing that explains why the server never came up.
        $why = is_file($log) ? (string) file_get_contents($log) : '(no log)';

        $server->stop();

        throw new \RuntimeException(
            "the fixture HTTP server did not come up on port {$port}.\n" . $why,
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

    /**
     * A unique id for a counter the fixture server keeps, so tests never share
     * one. The id is recorded here because it is the only thing that ties a
     * counter file back to this process — see {@see self::stop()}.
     */
    public static function flakyId(): string
    {
        $id = bin2hex(random_bytes(8));
        self::$counters[] = $id;

        return $id;
    }

    /**
     * Where the fixture server keeps a counter, so a test can read one.
     *
     * The prefix is a constant shared with `tests/fixtures/server/router.php`
     * by duplication — the server is a separate process with no autoloader, so
     * it cannot be asked — and a test asserts the router file contains it,
     * which is what keeps the two copies honest.
     */
    public static function counterFile(string $name, string $id): string
    {
        return sys_get_temp_dir() . '/' . self::COUNTER_PREFIX . $name . '-' . $id;
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

        // Both of these are files this harness caused to exist, so both go away
        // with it. A run that leaves them behind leaves them behind for good:
        // the log is a tempnam and the counters are keyed by a random id, so
        // nothing ever reuses either. The counter name is not known here — an
        // id is handed out before a test decides which counter it wants — so
        // every counter file carrying this id goes, by glob. The id is hex, so
        // it cannot introduce a wildcard of its own.
        @unlink($this->log);
        foreach (self::$counters as $id) {
            foreach (glob(sys_get_temp_dir() . '/' . self::COUNTER_PREFIX . '*-' . $id) ?: [] as $counter) {
                @unlink($counter);
            }
        }
        self::$counters = [];

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
