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

use cooldogedev\Spectrum\api\packet\Packet;
use GlobalLogger;
use pmmp\thread\Thread as NativeThread;
use pmmp\thread\ThreadSafeArray;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\thread\Thread;
use pocketmine\utils\Binary;
use pocketmine\utils\BinaryStream;
use Socket;
use function gc_enable;
use function ini_set;
use function is_null;
use function socket_close;
use function socket_connect;
use function socket_create;
use function socket_last_error;
use function socket_set_nonblock;
use function socket_strerror;
use function socket_write;
use function strlen;
use const AF_INET;
use const SOCK_STREAM;
use const SOL_TCP;

final class APIThread extends Thread
{
    public bool $running = false;
    private ThreadSafeArray $buffer;

    public function __construct(
        private readonly ThreadSafeLogger $logger,
        private readonly string $address,
        private readonly int $port,
    ) {
        $this->buffer = new ThreadSafeArray();
    }

    public function start(int $options = NativeThread::INHERIT_NONE): bool
    {
        $this->running = true;
        return parent::start($options);
    }


	private function connect(): ?Socket
	{
		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if (!@socket_connect($socket, $this->address, $this->port)) {
			$this->logger->error("Failed to connect to API due to: " . socket_strerror(socket_last_error()));
			return null;
		}

		socket_set_nonblock($socket);
		return $socket;
	}

    protected function onRun(): void
    {
        gc_enable();

        ini_set("display_errors", "1");
        ini_set("display_startup_errors", "1");
        ini_set("memory_limit", "512M");

        GlobalLogger::set($this->logger);

        $socket = $this->connect();

        $this->running = true;
        $this->logger->info("Successfully connected to the API");
        while ($this->running) {
			if(is_null($socket)){
				$socket = $this->connect();
				continue;
			}
            $this->synchronized(function (): void {
                if ($this->running && $this->buffer->count() === 0) {
                    $this->wait();
                }
            });

            $out = $this->buffer->shift();
            if ($out === null) {
                continue;
            }

            $bytes = @socket_write($socket, Binary::writeInt(strlen($out)) . $out);
            if ($bytes === false) {
				$this->buffer[] = $out;
				$socket = $this->connect();
                $this->logger->error("Failed to write to API due to: " . socket_strerror(socket_last_error()));
            }
        }

        $this->logger->debug("Disconnected from API");
        socket_close($socket);
    }

    public function quit(): void
    {
        $this->synchronized(function (): void {
            $this->running = false;
            $this->notify();
        });
        parent::quit();
    }

    public function write(Packet $packet): void
    {
        $stream = new BinaryStream();
        $packet->encode($stream);
        $this->synchronized(function () use ($stream): void {
            $this->buffer[] = $stream->getBuffer();
            $this->notify();
        });
    }
}
