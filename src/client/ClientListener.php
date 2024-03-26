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
use cooldogedev\Spectrum\client\packet\ProxyPacketIds;
use cooldogedev\Spectrum\client\packet\ProxyPacketPool;
use cooldogedev\Spectrum\client\packet\ProxySerializer;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\thread\log\ThreadSafeLogger;
use pocketmine\utils\Binary;
use pocketmine\utils\ServerException;
use Socket;
use function count;
use function in_array;
use function socket_accept;
use function socket_bind;
use function socket_close;
use function socket_create;
use function socket_getpeername;
use function socket_last_error;
use function socket_listen;
use function socket_read;
use function socket_select;
use function socket_set_nonblock;
use function socket_set_option;
use function socket_strerror;
use const AF_INET;
use const SO_LINGER;
use const SO_RCVBUF;
use const SO_RCVTIMEO;
use const SO_REUSEADDR;
use const SO_SNDBUF;
use const SO_SNDTIMEO;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SOL_TCP;
use const TCP_NODELAY;

final class ClientListener
{
    private const SOCKET_READER_ID = -1;

    private const SOCKET_BACKLOG = 10;

    private const SOCKET_TIMEOUT_SELECT = 5;
    private const SOCKET_TIMEOUT_READ_WRITE = 5;

    private const SOCKET_BUFFER = 1024 * 1024 * 8;
    private const SOCKET_READER_LENGTH = 1024 * 64;

    private const PACKETS_DECODE = [
        ProxyPacketIds::LATENCY,
        ProxyPacketIds::TRANSFER,

        ProtocolInfo::ADD_ACTOR_PACKET,
        ProtocolInfo::ADD_ITEM_ACTOR_PACKET,
        ProtocolInfo::ADD_PAINTING_PACKET,
        ProtocolInfo::ADD_PLAYER_PACKET,

        ProtocolInfo::BOSS_EVENT_PACKET,

        ProtocolInfo::MOB_EFFECT_PACKET,

        ProtocolInfo::PLAYER_LIST_PACKET,

        ProtocolInfo::REMOVE_ACTOR_PACKET,
        ProtocolInfo::REMOVE_OBJECTIVE_PACKET,

        ProtocolInfo::SET_DISPLAY_OBJECTIVE_PACKET,
    ];

    private readonly Socket $socket;

    /**
     * @phpstan-var array<int, Client>
     */
    private array $clients = [];

    private int $nextId = 0;

    public function __construct(
        private readonly Socket           $reader,
        private readonly ThreadSafeLogger $logger,

        private ThreadSafeArray           $in,
        private ThreadSafeArray           $out,

        private readonly int              $port,
    ) {}

    public function start(): void
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        socket_set_nonblock($this->socket);

        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_option($this->socket, SOL_TCP, TCP_NODELAY, 1);

        socket_set_option($this->socket, SOL_SOCKET, SO_SNDBUF, ClientListener::SOCKET_BUFFER);
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVBUF, ClientListener::SOCKET_BUFFER);

        socket_bind($this->socket, "0.0.0.0", $this->port);
        socket_listen($this->socket, ClientListener::SOCKET_BACKLOG);
    }

    public function tick(): void
    {
        $this->accept();
        $this->read();
        $this->write();
        $this->flush();
    }

    private function accept(): void
    {
        $socket = socket_accept($this->socket);

        if ($socket === false) {
            return;
        }

        socket_set_nonblock($socket);

        socket_set_option($socket, SOL_SOCKET, SO_LINGER, ["l_onoff" => 1, "l_linger" => 0]);

        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, ["sec" => ClientListener::SOCKET_TIMEOUT_READ_WRITE, "usec" => 0]);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ["sec" => ClientListener::SOCKET_TIMEOUT_READ_WRITE, "usec" => 0]);

        socket_getpeername($socket, $address, $port);

        $this->nextId++;
        $this->clients[$this->nextId] = new Client(
            socket: $socket,
            logger: $this->logger,
            id: $this->nextId,
        );

        $this->in[] = ProxySerializer::encode($this->nextId, LoginPacket::create($address, $port));
        $this->logger->debug("Accepted client " . $this->nextId . " from " . $address . ":" . $port);
    }

    private function read(): void
    {
        $read = [];
        $read[ClientListener::SOCKET_READER_ID] = $this->reader;

        foreach ($this->clients as $id => $client) {
            $read[$id] = $client->socket;
        }

        $write = null;
        $except = null;

        if (count($read) === 0) {
            return;
        }

        $changed = socket_select($read, $write, $except, ClientListener::SOCKET_TIMEOUT_SELECT);

        if ($changed === false) {
            throw new ServerException("Failed to select sockets: " . socket_strerror(socket_last_error($this->socket)));
        }

        if ($changed === 0) {
            return;
        }

        foreach ($read as $id => $socket) {
            if ($id === ClientListener::SOCKET_READER_ID) {
                socket_read($socket, ClientListener::SOCKET_READER_LENGTH);
                continue;
            }

            $client = $this->clients[$id] ?? null;

            if ($client === null) {
                continue;
            }

            try {
                if (($buffer = $client->read()) !== null) {
                    $packet = ProxyPacketPool::getInstance()->getPacket($buffer);

                    if ($packet instanceof DisconnectPacket) {
                        $this->disconnect($client, false);
                    }

                    $this->in[] = Binary::writeInt($client->id) . $buffer;
                }
            } catch (SocketException $exception) {
                $this->disconnect($client, true);
                if (!$exception instanceof SocketClosedException) {
                    $this->logger->logException($exception);
                }
            }
        }
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
                $this->disconnect($client, false);
                continue;
            }

            try {
                $client->write($buffer, in_array($packet->pid(), ClientListener::PACKETS_DECODE));
            } catch (SocketException $exception) {
                $this->disconnect($client, true);
                if (!$exception instanceof SocketClosedException) {
                    $this->logger->logException($exception);
                }
            }
        }
    }

    private function flush(): void
    {
        foreach ($this->clients as $client) {
            try {
                $client->flush();
            } catch (SocketException $exception) {
                $this->disconnect($client, true);
                if (!$exception instanceof SocketClosedException) {
                    $this->logger->logException($exception);
                }
            }
        }
    }

    private function disconnect(Client $client, bool $notifyMain): void
    {
        if (!isset($this->clients[$client->id])) {
            return;
        }

        $client->close();
        unset($this->clients[$client->id]);

        if ($notifyMain) {
            $this->in[] = ProxySerializer::encode($client->id, DisconnectPacket::create());
        }
    }

    public function close(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
            unset($this->clients[$client->id]);
        }

        socket_close($this->socket);
    }
}
