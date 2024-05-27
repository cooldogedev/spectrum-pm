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
use Exception;
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

    private string $readBuffer = "";
    private int $readRemaining = 0;

    private bool $closed = false;

    public function __construct(
        public readonly BiDirectionalQuicheStream $stream,
        public readonly ThreadSafeLogger          $logger,
        public readonly Closure                   $onRead,
        public readonly int                       $id,
    ) {
        $this->writer = $this->stream->setupWriter();
        $this->stream->setOnDataArrival(function (string $data): void {
            $this->readBuffer .= $data;
            if ($this->readRemaining === 0) {
                if (strlen($this->readBuffer) < Client::PACKET_LENGTH_SIZE) {
                    return;
                }

                try {
                    $length = Binary::readInt(substr($this->readBuffer, 0, 4));
                } catch (BinaryDataException) {
                    return;
                }

                $this->readBuffer = substr($this->readBuffer, 4);
                $this->readRemaining = $length - strlen($this->readBuffer);
                return;
            }

            $this->readRemaining -= strlen($data);
            if ($this->readRemaining <= 0) {
                ($this->onRead)(@zlib_decode($this->readBuffer));
                $this->readBuffer = "";
            }
        });
    }

    public function write(string $buffer, bool $decodeNeeded): void
    {
        try {
            $buffer = Binary::writeByte($decodeNeeded ? Client::PACKET_DECODE_NEEDED : Client::PACKET_DECODE_NOT_NEEDED) . libdeflate_deflate_compress($buffer, 9);
            $this->writer->write(Binary::writeInt(strlen($buffer)) . $buffer);
        } catch (Exception) {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->stream->onConnectionClose(false);
        $this->closed = true;
        $this->logger->debug("Closed client " . $this->id);
    }
}
