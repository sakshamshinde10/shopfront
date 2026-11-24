<?php

namespace App\Services\Extraction;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/** A download target that keeps only the first $max bytes and quietly discards the rest. */
final class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(StreamInterface $stream, private readonly int $max)
    {
        $this->stream = $stream;
    }

    public function write(string $string): int
    {
        $room = $this->max - (int) $this->stream->getSize();

        if ($room > 0) {
            $this->stream->write(substr($string, 0, $room));
        }

        // Report everything as written so the transfer is not treated as failed.
        return strlen($string);
    }

    protected function createStream(): StreamInterface
    {
        return $this->stream;
    }
}
