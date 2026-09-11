<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A transport that answers from a script instead of a socket.
 *
 * The queue holds closures, not responses, for one reason: a fake failure has
 * to carry the request that produced it (`NetworkExceptionInterface` requires
 * it) and only `sendRequest()` knows what that request is. A closure makes the
 * failure lazy without the fake having to know anything about HTTP.
 *
 * Retry counts are the interesting assertions, and they are only meaningful
 * against a transport that never sleeps, never flakes on its own, and records
 * every request it was handed. That is exactly this.
 */
final class FakeTransport implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<\Closure(RequestInterface): ResponseInterface> */
    private array $queue = [];

    public function __construct(
        private readonly ResponseFactoryInterface&StreamFactoryInterface $factory = new Psr17Factory(),
    ) {
    }

    /** @param \Closure(RequestInterface): ResponseInterface $answer */
    public function push(\Closure $answer): self
    {
        $this->queue[] = $answer;

        return $this;
    }

    public function pushResponse(int $status, string $body = ''): self
    {
        return $this->push(fn (RequestInterface $_): ResponseInterface => $this->factory
            ->createResponse($status)
            ->withBody($this->factory->createStream($body)));
    }

    public function pushNetworkFailure(string $message = 'connection refused'): self
    {
        return $this->push(static function (RequestInterface $request) use ($message): ResponseInterface {
            throw new FakeNetworkFailure($request, $message);
        });
    }

    /**
     * The same answer, N times — for a transport that fails until the retries
     * run out. Pushing one answer would not do it: the queue is consumed, and
     * an empty queue means "succeed", so a single failure would look like a
     * flaky service that recovered on the second attempt.
     *
     * @param \Closure(RequestInterface): ResponseInterface $answer
     */
    public function repeat(int $times, \Closure $answer): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->push($answer);
        }

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $answer = array_shift($this->queue);

        if ($answer === null) {
            return $this->factory->createResponse(200)->withBody($this->factory->createStream(''));
        }

        return $answer($request);
    }

    public function attempts(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): RequestInterface
    {
        $last = end($this->requests);
        if (!$last instanceof RequestInterface) {
            throw new \LogicException('no request was sent');
        }

        return $last;
    }
}
