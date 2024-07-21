<?php

/**
 * MIT License
 *
 * Copyright (c) 2024 cooldogedev
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @auto-license
 */

declare(strict_types=1);

namespace cooldogedev\Spectrum\client;

use Closure;
use NetherGames\Quiche\io\QueueWriter;
use NetherGames\Quiche\stream\BiDirectionalQuicheStream;
use pmmp\encoding\ByteBuffer;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use function snappy_compress;
use function snappy_uncompress;
use function strlen;

final class Client
{
    private const PACKET_LENGTH_SIZE = 4;

    private const PACKET_DECODE_NEEDED = 0x00;
    private const PACKET_DECODE_NOT_NEEDED = 0x01;

    private readonly ByteBuffer $buffer;
    private readonly ByteBuffer $writeBuffer;

    private readonly QueueWriter $writer;

    private int $length = 0;
    private bool $closed = false;

    public function __construct(
        public readonly BiDirectionalQuicheStream $stream,
        public readonly ThreadSafeLogger          $logger,
        public readonly Closure                   $reader,
        public readonly int                       $id,
    ) {
        $this->buffer = new ByteBuffer();
        $this->writer = $this->stream->setupWriter();
        $this->stream->setOnDataArrival(function (string $data): void {
            $this->buffer->writeByteArray($data);
            $this->read();
        });
    }

    public function read(): void
    {
        if ($this->length === 0 && $this->buffer->getUsedLength() >= Client::PACKET_LENGTH_SIZE) {
            try {
                $length = $this->buffer->readUnsignedIntBE();
            } catch (BinaryDataException) {
                return;
            }
            $this->length = $length;
            $this->buffer->reserve($length);
        }

        if ($this->length === 0 || $this->length > $this->buffer->getUsedLength() - $this->buffer->getReadOffset()) {
            return;
        }

        $payload = snappy_uncompress($this->buffer->readByteArray($this->length));
        if ($payload !== false) {
            ($this->reader)($payload);
        }

        $remaining = $this->buffer->readByteArray($this->buffer->getUsedLength() - $this->buffer->getReadOffset());
        $this->buffer->clear();
        $this->buffer->setReadOffset(0);
        $this->buffer->setWriteOffset(0);
        $this->buffer->writeByteArray($remaining);
        $this->length = 0;
        if ($this->buffer->getUsedLength() >= Client::PACKET_LENGTH_SIZE) {
            $this->read();
        }
    }

    public function write(string $buffer, bool $decodeNeeded): void
    {
        $compressed = snappy_compress($buffer);
        $length = strlen($compressed);
        $this->writeBuffer->clear();
        $this->writeBuffer->reserve($length + Client::PACKET_LENGTH_SIZE + 1);
        $this->writeBuffer->setWriteOffset(0);
        $this->writeBuffer->writeUnsignedIntBE($length + 1);
        $this->writeBuffer->writeUnsignedByte($decodeNeeded ? Client::PACKET_DECODE_NEEDED : Client::PACKET_DECODE_NOT_NEEDED);
        $this->writeBuffer->writeByteArray($compressed);
        $this->writer->write($this->writeBuffer->toString());
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->stream->setOnDataArrival(static fn () => null);
        if ($this->stream->isWritable()) {
            $this->stream->gracefulShutdownWriting();
        }
        $this->logger->debug("Closed client " . $this->id);
    }

    public function __destruct()
    {
        $this->logger->debug("Garbage collected client " . $this->id);
    }
}
