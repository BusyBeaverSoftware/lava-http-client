<?php

declare(strict_types=1);

namespace Lava\HttpClient;

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;
use Lava\Core\Modules\Module;
use Lava\Core\Modules\PackInfo;
use Lava\Core\Problem\InvalidConfig;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;

/**
 * lavaphp/http-client's entry point.
 *
 * Three ids, all singletons, and the order between them is the whole design:
 * `ClientOptions` is read from config **at register time** and the value is
 * captured by the other two factories. Reading config inside a factory that
 * runs later would make a service's behaviour depend on when it was first
 * resolved, which is the class of thing this framework exists to remove — and
 * it would move a bad `timeout` from a boot problem to whichever request
 * happened to touch the client first.
 *
 * **No `ClientInterface` alias.** It would be convenient — type-hint the PSR-18
 * interface, get the pack's client — but it would also *occupy* the standard
 * id, leaving an app that wants its own PSR-18 client under that id with a
 * `duplicate_service` at boot and no way around it. An app that wants different
 * behaviour constructs its own: `new HttpClient($myTransport, $factory, $options)`,
 * or its own class entirely. The pack registers its own two ids and nothing
 * else, which is the same surface `lavaphp/view` has.
 *
 * `CurlTransport` is registered separately from `HttpClient` so that both are
 * reachable: `HttpClient` for the retries and the problems, `CurlTransport` for
 * a caller that wants one unadorned request. It is also the seam a test or an
 * app replaces — `ValidateWiring` resolves every id at boot, so a wrong
 * registration under either id is a boot problem naming the id, not a 500 on
 * request N+1.
 *
 * Config is the only source of settings; the pack reads no environment
 * variable of its own. An app that wants `HTTP_CLIENT_TIMEOUT` to win can read
 * it in `config/http_client.php`, where the precedence is visible in one file
 * instead of split between a config reader and a module.
 */
final class HttpClientModule implements Module
{
    public function pack(): PackInfo
    {
        // `http_client` is the gate. The pack defines it — writing a `define`
        // entry for it in config/features.php is a boot problem, not a harmless
        // duplicate — and it can be turned off from `set` or from
        // LAVA_FEATURE_HTTP_CLIENT.
        return PackInfo::of('lavaphp/http-client', 'http_client', configFiles: ['http_client']);
    }

    public function register(Container $container, AppContext $ctx): void
    {
        // Throws InvalidConfig for a wrong type or an out-of-range value; the
        // boot step that calls register() turns that into a boot problem, so a
        // negative timeout is reported with the key and the file rather than
        // reaching curl.
        $options = ClientOptions::of($ctx->config);
        $factory = new Psr17Factory();

        $container->singleton(ClientOptions::class, static fn (): ClientOptions => $options);

        $container->singleton(
            CurlTransport::class,
            static fn (): CurlTransport => new CurlTransport($options, $factory),
        );

        $container->singleton(
            HttpClient::class,
            static function (Container $c) use ($options, $factory): HttpClient {
                $transport = $c->get(CurlTransport::class);
                if (!$transport instanceof ClientInterface) {
                    throw InvalidConfig::wrongService(CurlTransport::class, ClientInterface::class, $transport);
                }

                return new HttpClient($transport, $factory, $options);
            },
        );
    }
}
