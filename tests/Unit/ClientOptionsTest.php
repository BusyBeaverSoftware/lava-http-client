<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Unit;

use Lava\Core\Config\Config;
use Lava\Core\Problem\InvalidConfig;
use Lava\HttpClient\ClientOptions;
use PHPUnit\Framework\TestCase;

final class ClientOptionsTest extends TestCase
{
    public function testAnAppThatConfiguresNothingGetsUsableDefaults(): void
    {
        $options = ClientOptions::of(new Config());

        self::assertSame(10.0, $options->timeout);
        self::assertSame(5.0, $options->connectTimeout);
        self::assertSame(2, $options->retries);
        self::assertSame(100, $options->backoffMs);
        self::assertSame('lava/http-client', $options->userAgent);
    }

    public function testItReadsEveryKeyFromThePacksConfigFile(): void
    {
        $options = ClientOptions::of(new Config([
            'http_client.timeout' => 7,
            'http_client.connect_timeout' => 3,
            'http_client.retries' => 4,
            'http_client.backoff_ms' => 250,
            'http_client.user_agent' => 'my-app/2.0',
        ]));

        self::assertSame(7.0, $options->timeout);
        self::assertSame(3.0, $options->connectTimeout);
        self::assertSame(4, $options->retries);
        self::assertSame(250, $options->backoffMs);
        self::assertSame('my-app/2.0', $options->userAgent);
    }

    public function testRetriesMayBeZeroAndThatMeansNoSecondAttempt(): void
    {
        // Zero is a legitimate setting — an app that must never send a request
        // twice — and it is the boundary the range check has to let through.
        self::assertSame(0, ClientOptions::of(new Config(['http_client.retries' => 0]))->retries);
    }

    public function testANegativeTimeoutIsRefusedWithTheKeyAndTheFile(): void
    {
        // The type check cannot catch this: -1 is a perfectly good int, and it
        // reaches curl, which rejects it with a message naming neither the key
        // nor the file. This is the whole reason outOfRange exists.
        try {
            ClientOptions::of(new Config(['http_client.timeout' => -1]));
            self::fail('a negative timeout should be refused');
        } catch (InvalidConfig $problem) {
            self::assertSame('invalid_config', $problem->code());
            self::assertStringContainsString("'http_client.timeout'", $problem->getMessage());
            self::assertStringContainsString('-1', $problem->getMessage());
            self::assertStringContainsString("'timeout'", $problem->fix);
        }
    }

    public function testAZeroTimeoutIsRefusedToo(): void
    {
        // curl reads 0 as "no timeout at all" — the opposite of what someone
        // writing `'timeout' => 0` means.
        $this->expectException(InvalidConfig::class);
        ClientOptions::of(new Config(['http_client.timeout' => 0]));
    }

    public function testANegativeRetryCountIsRefused(): void
    {
        $this->expectException(InvalidConfig::class);
        ClientOptions::of(new Config(['http_client.retries' => -1]));
    }

    public function testANegativeBackoffIsRefused(): void
    {
        $this->expectException(InvalidConfig::class);
        ClientOptions::of(new Config(['http_client.backoff_ms' => -1]));
    }

    public function testTheFixNamesTheFileTheValueCameFrom(): void
    {
        // The pack's config file is optional, so a value nobody set has no
        // provenance — and then the fix has to name the file the app would have
        // to create, which is the honest answer for "this key is out of range
        // but you never wrote it".
        try {
            ClientOptions::of(new Config(['http_client.retries' => -3]));
            self::fail('a negative retry count should be refused');
        } catch (InvalidConfig $problem) {
            self::assertStringContainsString('config/http_client.php', $problem->fix);
        }

        try {
            ClientOptions::of(new Config(
                ['http_client.retries' => -3],
                ['http_client.retries' => 'config/http_client.php'],
            ));
            self::fail('a negative retry count should be refused');
        } catch (InvalidConfig $problem) {
            self::assertStringContainsString('config/http_client.php', $problem->fix);
        }
    }

    public function testAStringWhereANumberBelongsIsTheTypeCheckNotTheRangeCheck(): void
    {
        // Two different problems with two different fixes: 'lots' is
        // invalid_config from Config::int(), and the message has to say the
        // type rather than pretend it is a range question.
        try {
            ClientOptions::of(new Config(['http_client.timeout' => 'lots']));
            self::fail('a string timeout should be refused');
        } catch (InvalidConfig $problem) {
            self::assertStringContainsString('must be', $problem->getMessage());
            self::assertStringContainsString('integer', $problem->getMessage());
        }
    }

    public function testTheConstructorDoesNoValidating(): void
    {
        // Deliberate: range checks need the key name and the file, and a
        // literal in app code is the same trust level as any other literal
        // there. Documented so nobody "fixes" it into throwing.
        self::assertSame(-1.0, (new ClientOptions(timeout: -1.0))->timeout);
    }
}
