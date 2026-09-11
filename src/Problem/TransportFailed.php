<?php

declare(strict_types=1);

namespace Lava\HttpClient\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\HttpClient\Url;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * No response arrived — the connection failed, the host did not resolve, or the
 * request timed out.
 *
 * This is the pack's PSR-18 boundary, and it is the *only* failure the client
 * retries: a request that never produced a response is a request that can be
 * made again. A response that arrived and said 500 is a response, and
 * {@see UnexpectedStatus} is where it is reported — see {@see \Lava\HttpClient\HttpClient}
 * for why that line is drawn where it is.
 *
 * Implements `NetworkExceptionInterface` because that is the standard way to
 * say "no response", so an app that hands this client to a library expecting a
 * PSR-18 client gets the failure it knows how to catch. The `getRequest()` it
 * requires is why the request is kept — and it is kept, not printed: a request
 * carries headers, and an `Authorization` header must never reach a report.
 */
final class TransportFailed extends LavaProblem implements NetworkExceptionInterface
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        string $message,
        string $fix,
        array $context,
        private readonly RequestInterface $request,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $fix, $context, null, $previous);
    }

    /**
     * @param string $reason curl's own error text, or a transport's message.
     *                       Redacted before printing, because curl echoes the
     *                       URL back in several of its errors.
     */
    public static function of(RequestInterface $request, string $reason): self
    {
        $method = $request->getMethod();
        $url = Url::redact((string) $request->getUri());
        $reason = Url::redact($reason);

        return new self(
            "{$method} {$url} could not be sent: {$reason}",
            'Check that the host is reachable from where the app runs — a container cannot see the host '
            . 'machine\'s localhost. If the service is up and slow, raise the timeout: '
            . "'timeout' in config/http_client.php, or \$options->timeout for one call.",
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
        return 'transport_failed';
    }

    /**
     * The app's own response, not the upstream's: our server could not complete
     * a request it depends on. 502 says "the gateway failed" — which is exactly
     * true, and is a different thing from a 500, which would blame this app's
     * code for a failure that is somewhere else.
     */
    public function httpStatus(): int
    {
        return 502;
    }
}
