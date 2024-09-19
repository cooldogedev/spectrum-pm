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

use cooldogedev\spectral\Address;
use cooldogedev\Spectrum\client\packet\DisconnectPacket;
use cooldogedev\Spectrum\client\packet\LoginPacket;
use cooldogedev\Spectrum\client\packet\ProxyPacketPool;
use cooldogedev\Spectrum\client\packet\ProxySerializer;
use cooldogedev\spectral\Listener;
use cooldogedev\spectral\ServerConnection;
use cooldogedev\spectral\Stream;
use Exception;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\utils\Binary;
use Socket;
use function strlen;
use function substr;
use function snappy_compress;
use function snappy_uncompress;

final class ClientListener
{
    private const PACKET_DECODE_NEEDED = 0x00;
    private const PACKET_DECODE_NOT_NEEDED = 0x01;

    private readonly Listener $listener;

    /**
     * @phpstan-var array<int, Stream>
     */
    private array $streams = [];

    private int $nextId = 0;

    public function __construct(
        private readonly Socket           $reader,
        private readonly ThreadSafeArray  $decode,
        private readonly ThreadSafeLogger $logger,

        private ThreadSafeArray           $in,
        private ThreadSafeArray           $out,

        private readonly int              $port,
    ) {}

    public function start(): void
    {
        $streamAcceptor = function (Address $address, Stream $stream): void {
            $identifier = $this->nextId;
            $this->nextId++;
            $stream->registerCloseHandler(fn () => $this->remove($identifier, true));
            $stream->registerReader(function (string $data) use ($address, $identifier): void {
                $payload = @snappy_uncompress(substr($data, 4));
                if ($payload === false) {
                    return;
                }

                $offset = 0;
                $pid = Binary::readUnsignedVarInt($payload, $offset) & DataPacket::PID_MASK;
                if ($pid === ProtocolInfo::DISCONNECT_PACKET) {
                    $this->disconnect($identifier, false);
                    return;
                }
                $this->in[] = Binary::writeInt($identifier) . $payload;
            });
            $this->streams[$identifier] = $stream;
            $this->in[] = ProxySerializer::encode($identifier, LoginPacket::create($address->address, $address->port));
            $this->logger->debug("Accepted client " . $this->nextId . " from " . $address->toString());
        };
        $this->listener = Listener::listen("0.0.0.0", $this->port);
        $this->listener->setConnectionAcceptor(function (ServerConnection $connection) use ($streamAcceptor): void {
            $this->logger->debug("Accepted connection from " . $connection->getRemoteAddress()->toString());
            $connection->setStreamAcceptor(fn (Stream $stream) => $streamAcceptor($connection->getRemoteAddress(), $stream));
        });
        $this->listener->registerSocketInterruption($this->reader);
    }

    public function tick(): void
    {
        $this->listener->tick();
        $this->write();
    }

    private function write(): void
    {
        while (($out = $this->out->shift()) !== null) {
            [$identifier, $buffer] = ProxySerializer::decodeRaw($out);
            $stream = $this->streams[$identifier] ?? null;
            if ($stream === null) {
                continue;
            }

            $packet = ProxyPacketPool::getInstance()->getPacket($buffer);
            if ($packet instanceof DisconnectPacket) {
                $this->disconnect($identifier, false);
                continue;
            }

            $decodeNeeded = $this->decode[$packet->pid()] ?? false;
            try {
                $compressed = @snappy_compress($buffer);
                if ($compressed !== false) {
                    $stream->write(
                        Binary::writeInt(strlen($compressed) + 1) .
                        Binary::writeByte(($decodeNeeded ? ClientListener::PACKET_DECODE_NEEDED : ClientListener::PACKET_DECODE_NOT_NEEDED)) .
                        $compressed
                    );
                }
            } catch (Exception $exception) {
                $this->disconnect($identifier, true);
                $this->logger->logException($exception);
            }
        }
    }

    private function disconnect(int $streamId, bool $notifyMain): void
    {
        $stream = $this->streams[$streamId] ?? null;
        if ($stream === null) {
            return;
        }
        $stream->close();
        $this->remove($streamId, $notifyMain);
    }

    private function remove(int $streamId, bool $notifyMain): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        if ($notifyMain) {
            $this->in[] = ProxySerializer::encode($streamId, DisconnectPacket::create());
        }
        unset($this->streams[$streamId]);
    }

    public function close(): void
    {
        foreach ($this->streams as $id => $stream) {
            $stream->close();
            unset($this->streams[$id]);
        }
        $this->listener->close();
    }
}
