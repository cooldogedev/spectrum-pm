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
use cooldogedev\Spectrum\client\packet\DisconnectPacket;
use cooldogedev\Spectrum\client\packet\LoginPacket;
use cooldogedev\Spectrum\client\packet\ProxyPacketPool;
use cooldogedev\Spectrum\client\packet\ProxySerializer;
use NetherGames\Quiche\QuicheConnection;
use NetherGames\Quiche\socket\QuicheServerSocket;
use NetherGames\Quiche\SocketAddress;
use NetherGames\Quiche\stream\BiDirectionalQuicheStream;
use NetherGames\Quiche\stream\QuicheStream;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\utils\Binary;
use RuntimeException;
use Socket;
use function getenv;
use function socket_read;

final class ClientListener
{
    private const SOCKET_BUFFER = 1024 * 1024 * 10;
    private const SOCKET_READER_LENGTH = 1024 * 64;
    private const SOCKET_SELECT_TIMEOUT = 50;

    private const CONNECTION_MTU = 1350;

    private const CONNECTION_MAX_TIMEOUT = 10_000;
    private const CONNECTION_PING_INTERVAL = 5000;

    private const ENV_CERT_PATH = "CERT_PATH";
    private const ENV_KEY_PATH = "KEY_PATH";

    private readonly QuicheServerSocket $socket;

    /**
     * @phpstan-var array<int, Client>
     */
    private array $clients = [];

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
        $this->socket = new QuicheServerSocket([new SocketAddress("0.0.0.0", $this->port)], function (QuicheConnection $connection, ?QuicheStream $stream): void {
            if (!$stream instanceof BiDirectionalQuicheStream) {
                return;
            }

            $this->nextId++;
            $identifier = $this->nextId;
            $address = $connection->getPeerAddress();
            $stream->addShutdownReadingCallback(fn () => $this->disconnect($identifier, true));
            $this->clients[$identifier] = new Client(
                stream: $stream,
                logger: $this->logger,
                reader: function (string $data) use ($identifier): void {
                    $offset = 0;
                    $pid = Binary::readUnsignedVarInt($data, $offset) & DataPacket::PID_MASK;
                    if ($pid === ProtocolInfo::DISCONNECT_PACKET) {
                        $this->disconnect($identifier, false);
                        return;
                    }
                    $this->in[] = Binary::writeInt($identifier) . $data;
                },

                id: $identifier,
            );

            $this->in[] = ProxySerializer::encode($this->nextId, LoginPacket::create($address->getAddress(), $address->getPort()));
            $this->logger->debug("Accepted client " . $this->nextId . " from " . $address->getAddress());
        });

        $certPath = getenv(ClientListener::ENV_CERT_PATH);
        if ($certPath === false || !file_exists($certPath)) {
            throw new RuntimeException("cert path is not found");
        }

        $keyPath = getenv(ClientListener::ENV_KEY_PATH);
        if ($keyPath === false || !file_exists($keyPath)) {
            throw new RuntimeException("key path is not found");
        }

        $this->socket->getConfig()
            ->loadCertChainFromFile($certPath)
            ->loadPrivKeyFromFile($keyPath)

            ->enableBidirectionalStreams()

            ->setInitialMaxData(ClientListener::SOCKET_BUFFER)
            ->setMaxRecvUdpPayloadSize(ClientListener::CONNECTION_MTU)
            ->setMaxSendUdpPayloadSize(ClientListener::CONNECTION_MTU)

            ->setVerifyPeer(false)
            ->setApplicationProtos(["spectrum"])

            ->setMaxIdleTimeout(ClientListener::CONNECTION_MAX_TIMEOUT)
            ->setPingInterval(ClientListener::CONNECTION_PING_INTERVAL)

            ->setEnableActiveMigration(false)
            ->discoverPMTUD(true);
        $this->socket->registerSocket($this->reader, function (): void {
           $this->write();
           socket_read($this->reader, ClientListener::SOCKET_READER_LENGTH);
        });
    }

    public function tick(): void
    {
        $this->socket->selectSockets(ClientListener::SOCKET_SELECT_TIMEOUT);
    }

    private function write(): void
    {
        while (($out = $this->out->shift()) !== null) {
            [$identifier, $buffer] = ProxySerializer::decodeRaw($out);

            $client = $this->clients[$identifier] ?? null;
            if ($client === null) {
                continue;
            }

            $packet = ProxyPacketPool::getInstance()->getPacket($buffer);
            if ($packet instanceof DisconnectPacket) {
                $this->disconnect($identifier, false);
                continue;
            }

            try {
                $client->write($buffer, $this->decode[$packet->pid()] ?? false);
            } catch (SocketException $exception) {
                $this->disconnect($identifier, true);
                if (!$exception instanceof SocketClosedException) {
                    $this->logger->logException($exception);
                }
            }
        }
    }

    private function disconnect(int $clientId, bool $notifyMain): void
    {
        $client = $this->clients[$clientId] ?? null;
        if ($client === null) {
            return;
        }

        if ($notifyMain) {
            $this->in[] = ProxySerializer::encode($client->id, DisconnectPacket::create());
        }

        $client->close();
        unset($this->clients[$client->id]);
    }

    public function close(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
            unset($this->clients[$client->id]);
        }

        $this->socket->close(false, 0, "");
    }
}
