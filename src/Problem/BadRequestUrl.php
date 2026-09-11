<?php

declare(strict_types=1);

namespace Lava\HttpClient\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\HttpClient\Url;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The URL is not something an HTTP client can send, caught before curl sees it.
 *
 * Implements `RequestExceptionInterface` — the standard "this request is
 * malformed" signal — and it is deliberately *not* a `NetworkExceptionInterface`:
 * a bad URL is not retried, because sending it again produces the same bad URL.
 *
 * Raised by {@see \Lava\HttpClient\HttpClient::sendRequest()}, not by the
 * transport, so the rule holds whatever transport an app injected. A fake
 * client in a test that receives `file:///etc/passwd` refuses it for the same
 * reason the real one does.
 */
final class BadRequestUrl extends LavaProblem implements RequestExceptionInterface
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        string $message,
        string $fix,
        array $context,
        private readonly RequestInterface $request,
    ) {
        parent::__construct($message, $fix, $context);
    }

    /**
     * @param string $reason why it cannot be fetched, from {@see Url::whyUnusable()}
     */
    public static function of(RequestInterface $request, string $reason): self
    {
        $method = $request->getMethod();
        $url = Url::redact((string) $request->getUri());

        return new self(
            "{$method} was given the URL '{$url}', which cannot be fetched: {$reason}.",
            "Build the URL as 'https://host/path' — scheme, host, then path — and make sure any value "
            . 'interpolated into it was URL-encoded. The base URL usually belongs in config/http_client.php '
            . 'or a service, not at the call site.',
            ['method' => $method, 'url' => $url, 'reason' => $reason],
            $request,
        );
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function code(): string
    {
        return 'bad_request_url';
    }
}
