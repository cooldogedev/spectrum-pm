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

use cooldogedev\Spectrum\client\exception\SocketClosedException;
use cooldogedev\Spectrum\client\exception\SocketException;
use pmmp\encoding\ByteBuffer;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\Utils;
use Socket;
use function snappy_compress;
use function snappy_uncompress;
use function socket_setopt;
use function socket_recv;
use function socket_write;
use function socket_last_error;
use function socket_strerror;
use function socket_close;
use function strlen;
use const MSG_DONTWAIT;
use const SOCKET_EWOULDBLOCK;
use const SOCKET_ECONNRESET;
use const SOL_SOCKET;
use const SO_SNDBUF;
use const SO_RCVBUF;

final class Client
{
    private const SOCKET_BUFFER_SIZE = 1024 * 1024 * 8;

    private const PACKET_LENGTH_SIZE = 4;
    private const PACKET_FRAME_SIZE = 1024 * 64;
    private const PACKET_DECODE_NEEDED = 0x00;
    private const PACKET_DECODE_NOT_NEEDED = 0x01;

    private readonly ByteBuffer $buffer;

    private int $length = 0;
    private bool $closed = false;

    public function __construct(
        public readonly Socket           $socket,
        public readonly ThreadSafeLogger $logger,
        public readonly int              $id,
    ) {
        $this->buffer = new ByteBuffer();
        socket_setopt($this->socket, SOL_SOCKET, SO_SNDBUF, Client::SOCKET_BUFFER_SIZE);
        socket_setopt($this->socket, SOL_SOCKET, SO_RCVBUF, Client::SOCKET_BUFFER_SIZE);
    }

    public function read(): ?string
    {
        if ($this->length === 0) {
            $this->buffer->writeByteArray($this->internalRead(Client::PACKET_LENGTH_SIZE));
            if ($this->getBufferLength() < Client::PACKET_LENGTH_SIZE) {
                return null;
            }

            try {
                $length = $this->buffer->readUnsignedIntBE();
            } catch (BinaryDataException) {
                return null;
            }
            $this->length = $length;
            $this->buffer->reserve($length);
        }

        $this->buffer->writeByteArray($this->internalRead($this->length - $this->getBufferLength()));
        if ($this->length === 0 || $this->length > $this->getBufferLength()) {
            return null;
        }

        $payload = snappy_uncompress($this->buffer->readByteArray($this->length));
        $remaining = $this->buffer->readByteArray($this->getBufferLength());
        $this->buffer->clear();
        $this->buffer->setReadOffset(0);
        $this->buffer->setWriteOffset(0);
        $this->buffer->writeByteArray($remaining);
        $this->length = 0;
        if ($this->buffer->getUsedLength() >= Client::PACKET_LENGTH_SIZE) {
            $this->read();
        } else {
            $this->buffer->trim();
        }
        return $payload !== false ? $payload : null;
    }

    public function write(string $buffer, bool $decodeNeeded): void
    {
        $compressed = Binary::writeByte($decodeNeeded ? Client::PACKET_DECODE_NEEDED : Client::PACKET_DECODE_NOT_NEEDED) . snappy_compress($buffer);
        $this->internalWrite(Binary::writeInt(strlen($compressed)) . $compressed);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        @socket_close($this->socket);
        $this->closed = true;
        $this->logger->debug("Closed client " . $this->id);
    }

    private function internalRead(int $length): string
    {
        if ($length > Client::PACKET_FRAME_SIZE) {
            $length = Client::PACKET_FRAME_SIZE;
        }

        $bytes = @socket_recv($this->socket, $buffer, $length, Utils::getOS() === Utils::OS_WINDOWS ? 0 : MSG_DONTWAIT);
        if ($bytes === false || $buffer === null) {
           $this->checkErrors();
            return "";
        }
        return $buffer;
    }

    private function internalWrite(string $bytes): void
    {
        $bytes = @socket_write($this->socket, $bytes, strlen($bytes));
        if ($bytes === false) {
            $this->checkErrors();
        }
    }

    private function getBufferLength(): int
    {
        return $this->buffer->getUsedLength() - $this->buffer->getReadOffset();
    }

    private function checkErrors(): void
    {
        $error = socket_last_error($this->socket);
        if ($error === 0) {
            return;
        }

        if ($error === SOCKET_EWOULDBLOCK || $error === 11) {
            return;
        }

        if ($error === SOCKET_ECONNRESET || $error === 10004 || $error === 10053 || $error === 10054) {
            throw new SocketClosedException("socket is closed");
        }

        throw new SocketException(socket_strerror($error));
    }
}
