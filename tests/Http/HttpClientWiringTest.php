<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Http;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Testing\TestApp;
use Lava\HttpClient\ClientOptions;
use Lava\HttpClient\CurlTransport;
use Lava\HttpClient\HttpClient;
use Lava\HttpClient\Problem\TransportFailed;
use Lava\HttpClient\Tests\Support\LocalServer;
use PHPUnit\Framework\TestCase;

/**
 * The pack as an app actually gets it: a real boot, the container's own client,
 * and a request over a real socket.
 *
 * Everything else in this suite tests a piece. This one tests the seam — that
 * the config file is read, that the module turns it into options, that
 * `ValidateWiring` can resolve the whole graph, and that the object a handler
 * would be handed really talks to a server. A pack can pass every unit test and
 * still be unusable because its ids do not resolve at boot, which is precisely
 * the failure this file exists to catch.
 */
final class HttpClientWiringTest extends TestCase
{
    private static LocalServer $server;

    private static ?App $app = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalServer::shared();
        self::$app = self::booted();
    }

    /**
     * The fixture, booted — with the boot problems in the failure message.
     *
     * Written this way because a bare `assertInstanceOf(App::class, …)` on a
     * `BootFailure` reports nothing about *why* the app did not boot, and a
     * fixture that fails to boot fails every test in the class identically.
     */
    private static function booted(): App
    {
        $app = TestApp::boot(dirname(__DIR__) . '/fixtures/apps/http-app');

        if ($app instanceof BootFailure) {
            $lines = [];
            foreach ($app->problems->problems() as $problem) {
                $lines[] = $problem->code() . ': ' . $problem->getMessage()
                    . ' — ' . $problem->fix
                    . ($problem->source === null ? '' : ' (' . $problem->source . ')');
            }
            self::fail("the fixture app did not boot:\n  " . implode("\n  ", $lines));
        }

        return $app;
    }

    private static function app(): App
    {
        if (self::$app === null) {
            self::fail('the fixture app was not booted');
        }

        return self::$app;
    }

    /** @return array<string, mixed> */
    private static function echo(): array
    {
        $client = self::app()->container->get(HttpClient::class);
        self::assertInstanceOf(HttpClient::class, $client);

        $decoded = json_decode((string) $client->get(self::$server->url('/echo'))->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testTheFixtureAppBootsWithThePackEnabled(): void
    {
        self::assertSame('dev', self::app()->env);
        self::assertArrayHasKey(
            \Lava\HttpClient\HttpClientModule::class,
            self::app()->packs,
            'the pack is enabled, so its manifest is in the boot record',
        );
    }

    public function testThePacksOwnConfigFileIsReadAndItsProvenanceRecorded(): void
    {
        // `HttpClientModule::pack()` declares `configFiles: ['http_client']`,
        // and this is the only thing that proves the declaration is not dead:
        // rename the fixture's config/http_client.php and this fails with
        // '(unset)'.
        $config = self::app()->config;

        self::assertSame(7, $config->int('http_client.timeout', -1));
        self::assertSame('config/http_client.php', $config->provenance('http_client.timeout'));
    }

    public function testTheContainerHoldsTheClientTheTransportAndTheOptions(): void
    {
        $container = self::app()->container;

        self::assertInstanceOf(HttpClient::class, $container->get(HttpClient::class));
        self::assertInstanceOf(CurlTransport::class, $container->get(CurlTransport::class));
        self::assertInstanceOf(ClientOptions::class, $container->get(ClientOptions::class));
    }

    public function testTheOptionsCarryEveryValueFromTheFixtureConfig(): void
    {
        // Every value in the fixture's config file is deliberately NOT the
        // default, so this proves the file was read rather than that the
        // defaults happen to be right.
        $options = self::app()->container->get(ClientOptions::class);

        self::assertInstanceOf(ClientOptions::class, $options);
        self::assertSame(7.0, $options->timeout);
        self::assertSame(3.0, $options->connectTimeout);
        self::assertSame(1, $options->retries);
        self::assertSame(0, $options->backoffMs);
        self::assertSame('lava-http-fixture/1.0', $options->userAgent);
    }

    public function testTheClientsUserAgentReachesTheServer(): void
    {
        // Config → options → the curl handle, proven from the other end of a
        // socket. Nothing short of a real request shows this.
        self::assertSame('lava-http-fixture/1.0', self::echo()['user_agent']);
    }

    public function testTheContainersClientDecodesJson(): void
    {
        $decoded = self::app()->container
            ->get(HttpClient::class)
            ->getJson(self::$server->url('/json'));

        self::assertSame(['hello' => 'world', 'n' => 1], $decoded);
    }

    public function testAnIdempotentRequestIsRetriedOverARealSocket(): void
    {
        // The fixture configures `retries => 1`, so a GET that produces no
        // response is sent twice. The counter is in the server's own process,
        // so it is the attempt count and not the client's opinion of it.
        $id = LocalServer::flakyId();
        $client = self::app()->container->get(HttpClient::class);
        self::assertInstanceOf(HttpClient::class, $client);

        try {
            $client->get(self::$server->url("/drop?id={$id}"));
            self::fail('a truncated response should be a transport failure');
        } catch (TransportFailed) {
            self::assertSame(2, LocalServer::counter('drop', $id), 'retries => 1 means two attempts');
        }
    }

    public function testAPostIsNotRetriedOverARealSocket(): void
    {
        // The rule the pack exists for, proven end to end: the same failure,
        // the same configuration, one attempt — because a POST sent twice is
        // two creates.
        $id = LocalServer::flakyId();
        $client = self::app()->container->get(HttpClient::class);
        self::assertInstanceOf(HttpClient::class, $client);

        try {
            $client->post(self::$server->url("/drop?id={$id}"), '{"a":1}');
            self::fail('a truncated response should be a transport failure');
        } catch (TransportFailed) {
            self::assertSame(1, LocalServer::counter('drop', $id), 'a POST gets exactly one attempt');
        }
    }

    public function testTheTransportIsInjectableOnItsOwn(): void
    {
        // `CurlTransport` is registered separately from `HttpClient` so a
        // caller can have one unadorned request — no retries, no opinions.
        $transport = self::app()->container->get(CurlTransport::class);
        self::assertInstanceOf(CurlTransport::class, $transport);

        $response = $transport->sendRequest(
            (new \Nyholm\Psr7\Factory\Psr17Factory())->createRequest('GET', self::$server->url('/ok')),
        );

        self::assertSame('ok', (string) $response->getBody());
    }
}
