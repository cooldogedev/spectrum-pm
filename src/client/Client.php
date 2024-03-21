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
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\Utils;
use Socket;
use function array_shift;
use function count;
use function socket_setopt;
use function socket_recv;
use function socket_write;
use function socket_last_error;
use function socket_strerror;
use function socket_close;
use function strlen;
use function libdeflate_deflate_compress;
use function zlib_decode;
use function microtime;
use const MSG_DONTWAIT;
use const SOCKET_EAGAIN;
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

    private const FLUSH_THRESHOLD = 0.05;
    private const FLUSH_AMOUNT = 5;

    private string $readBuffer = "";
    private int $readRemaining = 0;

    /**
     * @var array<int, string>
     */
    private array $sendQueue = [];
    private float $lastSend = 0.0;

    private bool $closed = false;

    public function __construct(
        public readonly Socket           $socket,
        public readonly ThreadSafeLogger $logger,
        public readonly int              $id,
    ) {
        socket_setopt($this->socket, SOL_SOCKET, SO_SNDBUF, Client::SOCKET_BUFFER_SIZE);
        socket_setopt($this->socket, SOL_SOCKET, SO_RCVBUF, Client::SOCKET_BUFFER_SIZE);
    }

    public function read(): ?string
    {
        if ($this->readRemaining === 0) {
            $length = $this->internalRead(Client::PACKET_LENGTH_SIZE);
            if ($length === null) {
                return null;
            }

            try {
                $length = Binary::readInt($length);
            } catch (BinaryDataException) {
                return null;
            }

            $this->readBuffer = "";
            $this->readRemaining = $length;
        }

        $buffer = $this->internalRead($this->readRemaining);
        if ($buffer === null) {
            return null;
        }

        $this->readBuffer .= $buffer;
        $this->readRemaining -= strlen($buffer);
        if ($this->readRemaining > 0) {
            return null;
        }

        $buffer = @zlib_decode($this->readBuffer);
        $this->readBuffer = "";
        $this->readRemaining = 0;
        return $buffer !== false ? $buffer : null;
    }

    public function write(string $buffer): void
    {
        $buffer = libdeflate_deflate_compress($buffer, 9);
        $buffer = Binary::writeInt(strlen($buffer)) . $buffer;

        if (strlen($buffer) <= Client::PACKET_FRAME_SIZE) {
            $this->internalWrite($buffer);
            return;
        }

        $this->sendQueue[] = $buffer;
    }

    public function flush(): void
    {
        if (count($this->sendQueue) === 0) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastSend < Client::FLUSH_THRESHOLD) {
            return;
        }

        $this->lastSend = $now;
        for ($i = 0; $i < Client::FLUSH_AMOUNT && count($this->sendQueue) > 0; ++$i) {
            $this->internalWrite(array_shift($this->sendQueue));
        }
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

    private function internalRead(int $length): ?string
    {
        if ($length > Client::PACKET_FRAME_SIZE) {
            $length = Client::PACKET_FRAME_SIZE;
        }

        $bytes = @socket_recv($this->socket, $buffer, $length, Utils::getOS() === Utils::OS_WINDOWS ? 0 : MSG_DONTWAIT);
        if ($bytes === false || $buffer === null) {
            $this->checkErrors();
            return null;
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

    private function checkErrors(): void
    {
        $error = socket_last_error($this->socket);
        if ($error === 0) {
            return;
        }

        if ($error === SOCKET_EWOULDBLOCK || $error === SOCKET_EAGAIN) {
            return;
        }

        if ($error === SOCKET_ECONNRESET || $error === 10004 || $error === 10053 || $error === 10054) {
            throw new SocketClosedException("socket is closed");
        }

        throw new SocketException(socket_strerror($error));
    }
}
