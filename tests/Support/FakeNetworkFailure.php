<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Support;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The "no response arrived" failure, built from the request that caused it.
 *
 * It has to be constructed inside `sendRequest()` rather than ahead of time —
 * `NetworkExceptionInterface` requires the request, and a test only knows which
 * request it is once the client under test has built it.
 */
final class FakeNetworkFailure extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        string $message = 'connection refused',
    ) {
        parent::__construct($message);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
