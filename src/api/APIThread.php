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

namespace cooldogedev\Spectrum\api;

use cooldogedev\Spectrum\api\packet\ConnectionRequestPacket;
use cooldogedev\Spectrum\api\packet\ConnectionResponsePacket;
use cooldogedev\Spectrum\api\packet\Packet;
use GlobalLogger;
use pmmp\thread\Thread as NativeThread;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\Thread;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\Utils;
use Socket;
use function gc_enable;
use function ini_set;
use function sleep;
use function socket_close;
use function socket_connect;
use function socket_create;
use function socket_last_error;
use function socket_recv;
use function socket_strerror;
use function socket_write;
use function strlen;
use const AF_INET;
use const MSG_DONTWAIT;
use const SOCK_STREAM;
use const SOL_TCP;

final class APIThread extends Thread
{
    private const PACKET_LENGTH_SIZE = 4;

    public bool $running = false;
    private ?Socket $socket;

    public function __construct(
        private readonly ThreadSafeLogger $logger,
        private readonly string           $token,
        private readonly string           $address,
        private readonly int              $port,
    ) {}

    public function start(int $options = NativeThread::INHERIT_NONE): bool
    {
        $this->running = true;
        return parent::start($options);
    }

    protected function onRun(): void
    {
        gc_enable();

        ini_set("display_errors", "1");
        ini_set("display_startup_errors", "1");
        ini_set("memory_limit", "512M");

        GlobalLogger::set($this->logger);

        $this->running = true;
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->connect();
        $this->write(ConnectionRequestPacket::create($this->token));

        $connectionResponseBytes = $this->read();
        if ($connectionResponseBytes === null) {
            $this->logger->error("Failed to read connection response");
            return;
        }

        try {
            $packet = new ConnectionResponsePacket();
            $packet->decode(new BinaryStream($connectionResponseBytes));
            if ($packet->response !== ConnectionResponsePacket::RESPONSE_SUCCESS) {
                $this->logger->error("Connection failed, code " . $packet->response);
                return;
            }
        } catch (BinaryDataException) {
            $this->logger->error("Failed to decode connection response");
            return;
        }

        $this->logger->info("Successfully connected to the API");
    }

    public function quit(): void
    {
        $this->synchronized(function (): void {
            $this->running = false;
            socket_close($this->socket);
            $this->logger->debug("Disconnected from API");
            $this->notify();
        });
        parent::quit();
    }

    public function write(Packet $packet): void
    {
        if (!$this->running) {
            return;
        }

        $stream = new BinaryStream();
        $packet->encode($stream);
        $buffer = $stream->getBuffer();
        if (@socket_write($this->socket, Binary::writeInt(strlen($buffer)) . $buffer) === false) {
            $this->connect();
            $this->write($packet);
        }
    }

    public function read(): ?string
    {
        $lengthBytes = $this->internalRead(APIThread::PACKET_LENGTH_SIZE);
        try {
            $length = Binary::readInt($lengthBytes);
        } catch (BinaryDataException) {
            return null;
        }
        return $this->internalRead($length);
    }

    private function internalRead(int $length): ?string
    {
        $bytes = @socket_recv($this->socket, $buffer, $length, Utils::getOS() === Utils::OS_WINDOWS ? 0 : MSG_DONTWAIT);
        if ($bytes === false || $buffer === null) {
            return null;
        }
        return $buffer;
    }

    private function connect(): void
    {
        while (!@socket_connect($this->socket, $this->address, $this->port)) {
            if (!$this->running) {
                return;
            }
            $this->logger->debug("Socket failed to connect due to: " . socket_strerror(socket_last_error()) . ", retrying again in 3 seconds...");
            sleep(3);
        }

        $this->logger->debug("Socket successfully connected");
    }
}
