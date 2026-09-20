<?php

declare(strict_types=1);

namespace Lava\HttpClient\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * A body that can be read once and cannot be rewound — a pipe, a socket, an
 * upload being forwarded straight through.
 *
 * PSR-7 allows exactly this (`isSeekable()` is part of the interface because
 * not every stream is), and it is the shape that makes a retry dangerous: the
 * second attempt would find the stream at its end and send nothing, to an
 * endpoint that has no way to know the difference.
 */
final class OneShotStream implements StreamInterface
{
    private bool $read = false;

    public function __construct(private readonly string $contents)
    {
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function getContents(): string
    {
        if ($this->read) {
            return '';
        }

        $this->read = true;

        return $this->contents;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function rewind(): void
    {
        throw new \RuntimeException('this stream cannot be rewound');
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('this stream cannot be seeked');
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function eof(): bool
    {
        return $this->read;
    }

    public function tell(): int
    {
        return $this->read ? strlen($this->contents) : 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        return substr($this->getContents(), 0, $length);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('this stream is not writable');
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
