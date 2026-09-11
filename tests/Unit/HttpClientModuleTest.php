<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Unit;

use Lava\Core\Boot\AppContext;
use Lava\Core\Config\Config;
use Lava\Core\Container\Container;
use Lava\Core\Features\Features;
use Lava\Core\Features\FeatureSet;
use Lava\Core\Features\FeatureSettings;
use Lava\Core\Problem\InvalidConfig;
use Lava\HttpClient\ClientOptions;
use Lava\HttpClient\CurlTransport;
use Lava\HttpClient\HttpClient;
use Lava\HttpClient\HttpClientModule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

final class HttpClientModuleTest extends TestCase
{
    /**
     * The config array is keyed the way the loader keys it — `http_client.timeout`,
     * not `timeout` — because that is what `ConfigFile::load()` produces and
     * what the module actually reads. A test keyed the other way would take
     * every default and prove nothing.
     *
     * @param array<string, mixed> $config
     */
    private function register(array $config = []): Container
    {
        $container = new Container();
        $ctx = new AppContext(
            sys_get_temp_dir(),
            'dev',
            new Config($config),
            new Features(new FeatureSet(), new FeatureSettings(), null, 'dev'),
        );

        (new HttpClientModule())->register($container, $ctx);

        return $container;
    }

    public function testItDeclaresItsIdentityAndItsConfigFile(): void
    {
        $pack = (new HttpClientModule())->pack();

        self::assertSame('lava/http-client', $pack->package);
        self::assertSame('http_client', $pack->feature);
        self::assertSame(['http_client'], $pack->configFiles, 'the stem LoadPackConfig looks for');
        self::assertSame([], $pack->envVars, 'config is the only source of settings');
    }

    public function testItRegistersThreeIdsAndNothingElse(): void
    {
        // A small surface is the point: an agent reading `lava services` sees
        // the whole pack. A fourth id would need a reason.
        $container = $this->register();

        self::assertSame(
            [ClientOptions::class, CurlTransport::class, HttpClient::class],
            $container->ids(),
        );
    }

    public function testTheOptionsComeFromConfigAtRegisterTime(): void
    {
        $options = $this->register(['http_client.timeout' => 7, 'http_client.retries' => 1])
            ->get(ClientOptions::class);

        self::assertInstanceOf(ClientOptions::class, $options);
        self::assertSame(7.0, $options->timeout);
        self::assertSame(1, $options->retries);
    }

    public function testTheClientResolvesThroughItsOwnTransport(): void
    {
        // Resolving it is what ValidateWiring does at boot, so this is the
        // assertion that the whole graph builds — not just that the ids exist.
        $container = $this->register();

        self::assertInstanceOf(HttpClient::class, $container->get(HttpClient::class));
        self::assertInstanceOf(CurlTransport::class, $container->get(CurlTransport::class));
        self::assertInstanceOf(ClientInterface::class, $container->get(CurlTransport::class));
    }

    public function testBothTheClientAndTheTransportAreSingletons(): void
    {
        $container = $this->register();

        self::assertSame($container->get(HttpClient::class), $container->get(HttpClient::class));
        self::assertSame($container->get(CurlTransport::class), $container->get(CurlTransport::class));
    }

    public function testTheStandardPsr18IdIsLeftFree(): void
    {
        // Deliberate: aliasing ClientInterface would occupy the standard id, so
        // an app that wants its own PSR-18 client under that id would get a
        // duplicate_service at boot and no way around it. An app with different
        // needs constructs its own instead.
        self::assertFalse($this->register()->has(ClientInterface::class));
    }

    public function testAnOutOfRangeTimeoutIsABootProblemNotARequestTimeFailure(): void
    {
        // register() throws and the boot step that calls it turns the throw
        // into a problem — so a negative timeout is reported with the key and
        // the file at boot, rather than reaching curl on the first request.
        $this->expectException(InvalidConfig::class);
        $this->register(['http_client.timeout' => -5]);
    }

    public function testAUserAgentIsReadToo(): void
    {
        $options = $this->register(['http_client.user_agent' => 'my-app/3.1'])->get(ClientOptions::class);

        self::assertInstanceOf(ClientOptions::class, $options);
        self::assertSame('my-app/3.1', $options->userAgent);
    }
}
