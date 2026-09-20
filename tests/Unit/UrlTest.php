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

    /**
     * The names OAuth 2 and the cloud SDKs actually use. The rule used to
     * anchor on a word boundary, and `_` is a word character, so every one of
     * these went out in clear beside a masked `token=***` — which made the
     * guard look like it had worked (security review, 2026-09-20).
     *
     * @return iterable<string, array{string}>
     */
    public static function secretNames(): iterable
    {
        foreach ([
            'token', 'api_key', 'access_token', 'secret', 'password', 'signature',
            'client_secret', 'refresh_token', 'id_token', 'private_key', 'session_token',
            'app_secret', 'shared_secret', 'webhook_secret', 'aws_secret_access_key',
            'sas_token', 'authToken', 'X-Api-Key', 'user_credential',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('secretNames')]
    public function testItMasksASecretWhateverPrefixItsNameCarries(string $name): void
    {
        $redacted = Url::redact("https://api.example.com/v1/x?{$name}=SUPERSECRET&page=2");

        self::assertStringNotContainsString('SUPERSECRET', $redacted, "'{$name}' leaked its value");
        self::assertStringContainsString("{$name}=***", $redacted);
        self::assertStringContainsString('page=2', $redacted, 'an ordinary parameter is still readable');
    }

    public function testItMasksAPasswordThatContainsAnAtSign(): void
    {
        // The match runs to the LAST @ before the path; stopping at the first
        // one left the tail of the password in the string.
        self::assertSame('https://***@host/x', Url::redact('https://user:p@ssw0rd@host/x'));
    }

    public function testMaskingAValueKeepsTheSentenceAroundIt(): void
    {
        // curl echoes the URL back inside its own error text, and the redaction
        // runs over that text too — so it must mask the value without eating
        // the reason, which is the part a reader needs.
        self::assertSame(
            'GET https://h/x?api_key=*** could not be sent: timeout',
            Url::redact('GET https://h/x?api_key=SUPERSECRET could not be sent: timeout'),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET', true];
        yield 'PATCH' => ['PATCH', true];
        yield 'a custom token' => ['X-LOCK_1.2', true];
        yield 'empty' => ['', false];
        yield 'a space' => ['GET /x', false];
        yield 'CRLF, a second request' => ["GET / HTTP/1.1\r\nX-Injected: yes\r\n\r\nGET", false];
        yield 'a bare newline' => ["GET\n", false];
        yield 'a null byte' => ["GET\0", false];
    }

    #[DataProvider('methods')]
    public function testItDecidesWhatCanBeSentAsAMethod(string $method, bool $sendable): void
    {
        $reason = Url::whyBadMethod($method);

        if ($sendable) {
            self::assertNull($reason, "'{$method}' is a valid RFC 9110 token");

            return;
        }

        self::assertIsString($reason);
        self::assertNotSame('', $reason);
    }
}
