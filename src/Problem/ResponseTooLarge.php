<?php

declare(strict_types=1);

namespace Lava\HttpClient\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\HttpClient\Url;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The response body passed the ceiling, so the transfer was abandoned.
 *
 * The ceiling exists because a response body is buffered in memory: without one,
 * an upstream that answers with more bytes than `memory_limit` ends the request
 * in a fatal error, which is the one failure this framework cannot turn into a
 * problem with a fix — no error page, no log line, no `--json` envelope, just a
 * dead worker. A timeout is no defence either, since a slow dribble exhausts
 * memory inside any time budget.
 *
 * **Deliberately not a `NetworkExceptionInterface`.** That interface is the
 * pack's retry signal, and asking for the same oversized body again would only
 * spend the memory twice more. It is a `ClientExceptionInterface`, so a library
 * expecting PSR-18 still catches it, and {@see \Lava\HttpClient\HttpClient}
 * lets it through untouched.
 *
 * The bytes counted are what arrived before the abort, not a `Content-Length`:
 * the header is the upstream's claim, and this number is what was really read.
 */
final class ResponseTooLarge extends LavaProblem implements ClientExceptionInterface
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

    public static function of(RequestInterface $request, int $limit, int $received): self
    {
        $method = $request->getMethod();
        $url = Url::redact((string) $request->getUri());

        return new self(
            "{$method} {$url} answered with more than {$limit} bytes, so the response was abandoned after {$received}.",
            "Raise 'max_response_bytes' in config/http_client.php, or \$options->maxResponseBytes for one call, "
            . 'if a body this size is expected. If it is not, the URL is probably answering with something other '
            . 'than the API resource — a file, a dump, or an error page that keeps writing.',
            ['method' => $method, 'url' => $url, 'limit_bytes' => $limit, 'received_bytes' => $received],
            $request,
        );
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function code(): string
    {
        return 'response_too_large';
    }

    /** The upstream sent something this app cannot hold: the gateway failed, not this app's code. */
    public function httpStatus(): int
    {
        return 502;
    }
}
