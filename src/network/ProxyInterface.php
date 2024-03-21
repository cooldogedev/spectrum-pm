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

namespace cooldogedev\Spectrum\network;

use Closure;
use cooldogedev\Spectrum\client\ClientThread;
use cooldogedev\Spectrum\client\packet\ConnectPacket;
use cooldogedev\Spectrum\client\packet\DisconnectPacket;
use cooldogedev\Spectrum\client\packet\LatencyPacket;
use cooldogedev\Spectrum\client\packet\LoginPacket;
use cooldogedev\Spectrum\client\packet\ProxyPacket;
use cooldogedev\Spectrum\client\packet\ProxyPacketPool;
use cooldogedev\Spectrum\client\packet\ProxySerializer;
use cooldogedev\Spectrum\Spectrum;
use cooldogedev\Spectrum\util\JsonUtils;
use Exception;
use pmmp\thread\ThreadSafeArray;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\PacketBroadcaster;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\types\login\AuthenticationData;
use pocketmine\network\mcpe\protocol\types\login\ClientData;
use pocketmine\network\mcpe\protocol\types\login\ClientDataToSkinDataHelper;
use pocketmine\network\mcpe\StandardEntityEventBroadcaster;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\network\NetworkInterface;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\Player;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\scheduler\ClosureTask;
use pocketmine\snooze\SleeperHandlerEntry;
use pocketmine\utils\Utils;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Socket;
use function floor;
use function microtime;
use function socket_close;
use function socket_create_pair;
use function socket_write;
use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;

final class ProxyInterface implements NetworkInterface
{
    private ThreadSafeArray $in;
    private ThreadSafeArray $out;

    private readonly ClientThread $thread;
    private readonly Socket $writer;
    private readonly SleeperHandlerEntry $sleeperHandlerEntry;

    private readonly TypeConverter $typeConverter;
    private readonly PacketBroadcaster $packetBroadcaster;
    private readonly StandardEntityEventBroadcaster $entityEventBroadcaster;

    private int $sentBytes = 0;
    private int $receivedBytes = 0;

    /**
     * @var array<int, NetworkSession>
     */
    private array $sessions = [];

    public function __construct(public readonly Spectrum $plugin)
    {
        $this->in = new ThreadSafeArray();
        $this->out = new ThreadSafeArray();

        $server = $this->plugin->getServer();
        $this->sleeperHandlerEntry = $server->getTickSleeper()->addNotifier($this->handleIncoming(...));

        if (!socket_create_pair(Utils::getOS() !== Utils::OS_WINDOWS ? AF_UNIX : AF_INET, SOCK_STREAM, 0, $ipc)) {
            throw new RuntimeException("Failed to create socket pair");
        }

        [$this->writer, $reader] = $ipc;
        $this->thread = new ClientThread(
            reader: $reader,
            sleeperHandlerEntry: $this->sleeperHandlerEntry,

            in: $this->in,
            out: $this->out,

            logger: $server->getLogger(),
            port: $server->getPort(),
        );

        $this->typeConverter = TypeConverter::getInstance();
        $this->packetBroadcaster = new StandardPacketBroadcaster($server);
        $this->entityEventBroadcaster = new StandardEntityEventBroadcaster($this->packetBroadcaster, $this->typeConverter);

        $bandwidthTracker = $server->getNetwork()->getBandwidthTracker();
        $this->plugin->getScheduler()->scheduleDelayedRepeatingTask(new ClosureTask(function () use ($bandwidthTracker): void {
            $bandwidthTracker->add($this->sentBytes, $this->receivedBytes);
            $this->sentBytes = 0;
            $this->receivedBytes = 0;
        }), 20, 20);
    }

    private function handleIncoming(): void
    {
        while (($in = $this->in->shift()) !== null) {
            [$identifier, $buffer] = ProxySerializer::decodeRaw($in);
            $this->receivedBytes += strlen($buffer);

            $packet = ProxyPacketPool::getInstance()->getPacket($buffer);

            if ($packet === null) {
                $this->plugin->getLogger()->debug("Received unknown packet from client " . $identifier);
                $this->disconnect($identifier, true);
                continue;
            }

            $session = $this->sessions[$identifier] ?? null;

            if (!$packet instanceof ProxyPacket) {
                try {
                    $session?->handleDataPacket($packet, $buffer);
                } catch (PacketHandlingException $exception) {
                    $this->plugin->getLogger()->logException($exception);
                    $this->disconnect($identifier, true);
                }
                continue;
            }

            $packet->decode(PacketSerializer::decoder($buffer, 0));

            match (true) {
                $packet instanceof LoginPacket => $this->login($identifier, $packet->address, $packet->port),
                $packet instanceof ConnectPacket && $session !== null => $this->connect($session, $packet->address, $packet->entityId, $packet->clientData, $packet->identityData),
                $packet instanceof LatencyPacket && $session !== null => $this->latency($session, $identifier, $packet->latency, $packet->timestamp),
                $packet instanceof DisconnectPacket => $this->disconnect($identifier, false),
                default => null,
            };
        }
    }

    private function login(int $identifier, string $address, int $port): void
    {
        $session = new NetworkSession(
            server: $this->plugin->getServer(),
            manager: $this->plugin->getServer()->getNetwork()->getSessionManager(),

            packetPool: ProxyPacketPool::getInstance(),

            sender: new ProxySender($this, $identifier),

            broadcaster: $this->packetBroadcaster,
            entityEventBroadcaster: $this->entityEventBroadcaster,

            compressor: NoopCompressor::getInstance(),
            typeConverter: $this->typeConverter,

            ip: $address,
            port: $port,
        );

        Closure::bind(fn() => $this->onSessionStartSuccess(), $session, $session)->call($session);
        $this->sessions[$identifier] = $session;
    }

    private function connect(NetworkSession $session, string $address, int $entityId, array $clientData, array $identityData): void
    {
        $server = $this->plugin->getServer();

        $clientData = JsonUtils::map($clientData, new ClientData());
        $identityData = JsonUtils::map($identityData, new AuthenticationData());

        if ($clientData === null || $identityData === null) {
            $session->disconnect("Invalid client data");
            return;
        }

        if (!Player::isValidUserName($identityData->displayName)) {
            $session->disconnectWithError(KnownTranslationFactory::disconnectionScreen_invalidName());
            return;
        }

        try {
            $skin = $session->getTypeConverter()->getSkinAdapter()->fromSkinData(ClientDataToSkinDataHelper::fromClientData($clientData));
        } catch (Exception $exception) {
            $session->getLogger()->debug("Invalid skin: " . $exception->getMessage());
            $session->disconnectWithError(KnownTranslationFactory::disconnectionScreen_invalidSkin());
            return;
        }

        $playerInfo = new XboxLivePlayerInfo(
            xuid: $identityData->XUID,
            username: $identityData->displayName,
            uuid: Uuid::fromString($identityData->identity),
            skin: $skin,
            locale: $clientData->LanguageCode,
            extraData: (array)$clientData,
        );

        Closure::bind(function () use ($address, $playerInfo): void {
            $this->ip = $address;
            $this->info = $playerInfo;
            $this->logger->setPrefix($this->getLogPrefix());
            $this->manager->markLoginReceived($this);
        }, $session, $session)->call($session);

        $event = new PlayerPreLoginEvent($playerInfo, $session->getIp(), $session->getPort(), $server->requiresAuthentication());

        if ($server->getNetwork()->getValidConnectionCount() > $server->getMaxPlayers()) {
            $event->setKickFlag(PlayerPreLoginEvent::KICK_FLAG_SERVER_FULL, KnownTranslationFactory::disconnectionScreen_serverFull());
        }

        if (!$server->isWhitelisted($playerInfo->getUsername())) {
            $event->setKickFlag(PlayerPreLoginEvent::KICK_FLAG_SERVER_WHITELISTED, KnownTranslationFactory::pocketmine_disconnect_whitelisted());
        }

        $banMessage = null;

        if (($banEntry = $server->getNameBans()->getEntry($playerInfo->getUsername())) !== null) {
            $banReason = $banEntry->getReason();
            $banMessage = $banReason === "" ? KnownTranslationFactory::pocketmine_disconnect_ban_noReason() : KnownTranslationFactory::pocketmine_disconnect_ban($banReason);
        } elseif (($banEntry = $server->getIPBans()->getEntry($session->getIp())) !== null) {
            $banReason = $banEntry->getReason();
            $banMessage = KnownTranslationFactory::pocketmine_disconnect_ban($banReason !== "" ? $banReason : KnownTranslationFactory::pocketmine_disconnect_ban_ip());
        }

        if ($banMessage !== null) {
            $event->setKickFlag(PlayerPreLoginEvent::KICK_FLAG_BANNED, $banMessage);
        }

        $event->call();

        if (!$event->isAllowed()) {
            $session->disconnect($event->getFinalDisconnectReason(), $event->getFinalDisconnectScreenMessage());
            return;
        }

        Closure::bind(function () use ($entityId): void {
            $onPlayerCreated = $this->onPlayerCreated(...);
            $onFail = $this->disconnectWithError(...);

            $this->setAuthenticationStatus(true, true, null, "");
            $this->server->createPlayer($this, $this->info, $this->authenticated, $this->cachedOfflinePlayerData)->onCompletion(
                function (Player $player) use ($entityId, $onPlayerCreated): void {
                    Closure::bind(function () use ($entityId, $onPlayerCreated): void {
                        $this->getWorld()->getEntity($entityId)?->close();
                        $this->getWorld()->removeEntity($this);
                        $this->id = $entityId;
                        $this->getWorld()->addEntity($this);
                        $onPlayerCreated($this);
                    }, $player, $player)->call($player);
                },
                fn () => $onFail("Failed to create player", KnownTranslationFactory::pocketmine_disconnect_error_internal())
            );
        }, $session, $session)->call($session);
    }

    private function latency(NetworkSession $session, int $identifier, int $latency, int $timestamp): void
    {
        $now = (int)floor(microtime(true) * 1000);
        $latency += $now - $timestamp;

        Closure::bind(fn() => $this->ping = $latency, $session, $session)->call($session);
        $this->sendOutgoing($identifier, LatencyPacket::create($latency, $now));
    }

    public  function disconnect(int $identifier, bool $notifyThread): void
    {
        $session = $this->sessions[$identifier] ?? null;
        if ($session === null) {
            return;
        }

        unset($this->sessions[$identifier]);
        $session->onClientDisconnect("");

        if ($notifyThread) {
            $this->sendOutgoing($identifier, DisconnectPacket::create());
        }
    }

    public function sendOutgoing(int|NetworkSession $identifier, ProxyPacket $packet): void
    {
        $buffer = ProxySerializer::encode($identifier, $packet);
        $this->thread->out[] = $buffer;
        $this->sentBytes += strlen($buffer);
        socket_write($this->writer, "\00");
    }

    public function sendOutgoingRaw(int $identifier, string $packet): void
    {
        $buffer = ProxySerializer::encodeRaw($identifier, $packet);
        $this->thread->out[] = $buffer;
        $this->sentBytes += strlen($buffer);
        @socket_write($this->writer, "\00");
    }

    public function getIdentifier(NetworkSession $session): ?int
    {
        $identifier = array_search($session, $this->sessions, true);
        return $identifier !== false ? $identifier : null;
    }

    public function start(): void
    {
        $this->thread->start();
    }

    public function setName(string $name): void {}
    public function tick(): void {}

    public function shutdown(): void
    {
        @socket_write($this->writer, "\00");
        $this->thread->quit();
        $this->plugin->getServer()->getTickSleeper()->removeNotifier($this->sleeperHandlerEntry->getNotifierId());
        @socket_close($this->writer);
    }
}
