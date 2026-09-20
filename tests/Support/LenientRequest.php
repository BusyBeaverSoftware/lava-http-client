<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * A PSR-7 request that validates nothing.
 *
 * `sendRequest()` is the PSR-18 boundary: it takes any request object, and
 * nyholm's — the one the pack's own API builds — refuses a header carrying a
 * line break before the pack ever sees it. That makes nyholm's guard untestable
 * as *this pack's* guard, and it is not this pack's guard: an app is free to
 * hand the transport a request from any implementation, including one that
 * checks nothing. This double is that implementation, so the transport's own
 * refusal is what the test proves.
 *
 * Only the methods the transport calls do anything. The rest satisfy the
 * interface and say so if they are ever used.
 */
final class LenientRequest implements RequestInterface
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    private StreamInterface $body;

    private UriInterface $uri;

    public function __construct(
        private readonly string $method,
        string $url,
        string $body = '',
    ) {
        $factory = new Psr17Factory();
        $this->uri = $factory->createUri($url);
        $this->body = $factory->createStream($body);
    }

    /** Sets a header with no validation whatsoever — the whole point of the class. */
    public function withRawHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = [$value];

        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[$name] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): self
    {
        return $this->withRawHeader($name, is_array($value) ? implode(', ', $value) : (string) $value);
    }

    public function withAddedHeader(string $name, $value): self
    {
        return $this->withHeader($name, $value);
    }

    public function withoutHeader(string $name): self
    {
        $clone = clone $this;
        unset($clone->headers[$name]);

        return $clone;
    }

    public function withBody(StreamInterface $body): self
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): self
    {
        $clone = clone $this;
        $clone->uri = $uri;

        return $clone;
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): self
    {
        return $this;
    }

    public function getRequestTarget(): string
    {
        return (string) $this->uri;
    }

    public function withRequestTarget(string $requestTarget): self
    {
        throw new \LogicException('the tests never set a request target');
    }

    public function withMethod(string $method): self
    {
        return new self($method, (string) $this->uri);
    }
}
