<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Unit;

use Lava\HttpClient\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlTest extends TestCase
{
    /**
     * The scheme rule is the pack's one security decision, so it is tested as
     * one: `file://` is refused not because it is unusual but because curl
     * would fetch it, and this pack's URLs arrive from outside.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'https' => ['https://api.example.com/v1/things', true];
        yield 'http with a port' => ['http://127.0.0.1:8080/ok?x=1', true];
        yield 'uppercase scheme' => ['HTTPS://api.example.com/x', true];
        yield 'no scheme' => ['api.example.com/x', false];
        yield 'protocol-relative' => ['//api.example.com/x', false];
        yield 'no host' => ['http:///x', false];
        yield 'empty' => ['', false];
        yield 'file' => ['file:///etc/passwd', false];
        yield 'ftp' => ['ftp://example.com/x', false];
        yield 'gopher' => ['gopher://example.com/x', false];
    }

    #[DataProvider('urls')]
    public function testItDecidesWhatCanBeFetched(string $url, bool $fetchable): void
    {
        $reason = Url::whyUnusable($url);

        if ($fetchable) {
            self::assertNull($reason, "'{$url}' should be fetchable");

            return;
        }

        self::assertIsString($reason, "'{$url}' should be refused, and the reason is the message");
        self::assertNotSame('', $reason);
    }

    public function testItNamesTheSchemeItRefused(): void
    {
        // The message is the fix: "not http or https" tells the reader what
        // happened without them having to look up what curl does with file://.
        self::assertStringContainsString("'file'", (string) Url::whyUnusable('file:///etc/passwd'));
    }

    public function testItMasksUserinfo(): void
    {
        self::assertSame(
            'https://***@api.example.com/x',
            Url::redact('https://user:s3cr3t@api.example.com/x'),
        );
    }

    public function testItMasksUserinfoWithNoPassword(): void
    {
        // A username alone is still a credential — a token used as a username
        // is a common Basic-auth shape.
        self::assertSame('https://***@api.example.com/x', Url::redact('https://token@api.example.com/x'));
    }

    public function testItMasksSecretShapedQueryParameters(): void
    {
        $redacted = Url::redact('https://api.example.com/x?token=abc123&api_key=k-9&page=2');

        self::assertStringNotContainsString('abc123', $redacted);
        self::assertStringNotContainsString('k-9', $redacted);
        self::assertStringContainsString('token=***', $redacted);
        self::assertStringContainsString('api_key=***', $redacted);
    }

    public function testItLeavesHarmlessQueryParametersAlone(): void
    {
        // The rule leans towards masking, but it must not swallow the whole
        // query: `?page=2` is what tells a reader which call this was.
        self::assertSame(
            'https://api.example.com/x?page=2&sort=name',
            Url::redact('https://api.example.com/x?page=2&sort=name'),
        );
    }

    public function testItDoesNotMistakeAPathForUserinfo(): void
    {
        // `[^/@]*` cannot cross a slash, so a path containing an @ is left
        // alone — a redaction rule that ate part of the path would make every
        // URL in every problem report useless.
        self::assertSame(
            'https://api.example.com/users/me@example.com',
            Url::redact('https://api.example.com/users/me@example.com'),
        );
    }
}
