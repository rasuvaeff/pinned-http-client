<?php

declare(strict_types=1);

namespace Rasuvaeff\PinnedHttpClient\Internal;

use Psr\Http\Message\StreamInterface;

/**
 * Приёмник тела с потолком: запись сверх лимита возвращает 0 байт, и cURL обрывает передачу (ошибка записи).
 * Остальное — обычный поток, в который Guzzle пишет тело и из которого его читает вызывающий. Параметры без
 * нативных типов: так класс совместим и с `psr/http-message` 1.1, и с 2.0.
 *
 * @internal
 */
final class BoundedSink implements StreamInterface
{
    private int $written = 0;

    private bool $exceeded = false;

    /**
     * @param positive-int $limit
     */
    public function __construct(private readonly StreamInterface $stream, private readonly int $limit) {}

    public function exceeded(): bool
    {
        return $this->exceeded;
    }

    /**
     * @param string $string
     */
    #[\Override]
    public function write($string): int
    {
        if ($this->written + \strlen($string) > $this->limit) {
            $this->exceeded = true;

            return 0;
        }

        $this->written += \strlen($string);

        return $this->stream->write($string);
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->stream->__toString();
    }

    #[\Override]
    public function close(): void
    {
        $this->stream->close();
    }

    #[\Override]
    public function detach()
    {
        return $this->stream->detach();
    }

    #[\Override]
    public function getSize(): ?int
    {
        return $this->stream->getSize();
    }

    #[\Override]
    public function tell(): int
    {
        return $this->stream->tell();
    }

    #[\Override]
    public function eof(): bool
    {
        return $this->stream->eof();
    }

    #[\Override]
    public function isSeekable(): bool
    {
        return $this->stream->isSeekable();
    }

    /**
     * @param int $offset
     * @param int $whence
     */
    #[\Override]
    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
    }

    #[\Override]
    public function rewind(): void
    {
        $this->stream->rewind();
    }

    #[\Override]
    public function isWritable(): bool
    {
        return $this->stream->isWritable();
    }

    #[\Override]
    public function isReadable(): bool
    {
        return $this->stream->isReadable();
    }

    /**
     * @param int $length
     */
    #[\Override]
    public function read($length): string
    {
        return $this->stream->read($length);
    }

    #[\Override]
    public function getContents(): string
    {
        return $this->stream->getContents();
    }

    /**
     * @param string|null $key
     */
    #[\Override]
    public function getMetadata($key = null)
    {
        return $this->stream->getMetadata($key);
    }
}
