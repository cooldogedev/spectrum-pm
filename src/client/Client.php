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
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use function strlen;
use function substr;
use function libdeflate_deflate_compress;
use function zlib_decode;

final class Client
{
    private const PACKET_LENGTH_SIZE = 4;

    private const PACKET_DECODE_NEEDED = 0x00;
    private const PACKET_DECODE_NOT_NEEDED = 0x01;

    public readonly QueueWriter $writer;

    private string $buffer = "";
    private int $length = 0;

    private bool $closed = false;

    public function __construct(
        public readonly BiDirectionalQuicheStream $stream,
        public readonly ThreadSafeLogger          $logger,
        public readonly Closure                   $reader,
        public readonly int                       $id,
    ) {
        $this->writer = $this->stream->setupWriter();
        $this->stream->setOnDataArrival(function (string $data): void {
            $this->buffer .= $data;
            $this->read();
        });
    }

    public function read(): void
    {
        if ($this->length === 0 && strlen($this->buffer) >= Client::PACKET_LENGTH_SIZE) {
            try {
                $length = Binary::readInt(substr($this->buffer, 0, Client::PACKET_LENGTH_SIZE));
            } catch (BinaryDataException) {
                return;
            }
            $this->buffer = substr($this->buffer, Client::PACKET_LENGTH_SIZE);
            $this->length = $length;
        }

        if ($this->length === 0 || $this->length > strlen($this->buffer)) {
            return;
        }

        $payload = @zlib_decode(substr($this->buffer, 0, $this->length));
        if ($payload !== false) {
            ($this->reader)($payload);
        }

        $this->buffer = substr($this->buffer, $this->length);
        $this->length = 0;
        if (strlen($this->buffer) >= Client::PACKET_LENGTH_SIZE) {
            $this->read();
        }
    }

    public function write(string $buffer, bool $decodeNeeded): void
    {
        $buffer = Binary::writeByte($decodeNeeded ? Client::PACKET_DECODE_NEEDED : Client::PACKET_DECODE_NOT_NEEDED) . libdeflate_deflate_compress($buffer);
        $this->writer->write(Binary::writeInt(strlen($buffer)) . $buffer);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->stream->onConnectionClose(false);
        $this->logger->debug("Closed client " . $this->id);
    }
}
