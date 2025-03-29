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

namespace cooldogedev\Spectrum;

use Closure;
use cooldogedev\Spectrum\api\APIThread;
use cooldogedev\Spectrum\client\packet\ProxyPacketIds;
use cooldogedev\Spectrum\network\ProxyInterface;
use cooldogedev\Spectrum\util\ComposerRegisterAsyncTask;
use pocketmine\event\EventPriority;
use pocketmine\event\server\NetworkInterfaceRegisterEvent;
use pocketmine\network\mcpe\encryption\EncryptionContext;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\login\AuthenticationData;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\query\DedicatedQueryNetworkInterface;
use pocketmine\plugin\PluginBase;
use function is_file;
use const spectrum\COMPOSER_AUTOLOADER_PATH;

require_once "CoreConstants.php";

final class Spectrum extends PluginBase
{
    private array $decode = [
		ProxyPacketIds::CONNECTION_RESPONSE => true,
		ProxyPacketIds::FLUSH => true,
		ProxyPacketIds::LATENCY => true,
		ProxyPacketIds::TRANSFER => true,

		ProtocolInfo::ADD_ACTOR_PACKET => true,
		ProtocolInfo::ADD_ITEM_ACTOR_PACKET => true,
		ProtocolInfo::ADD_PAINTING_PACKET => true,
		ProtocolInfo::ADD_PLAYER_PACKET => true,

		ProtocolInfo::BOSS_EVENT_PACKET => true,

		ProtocolInfo::CHUNK_RADIUS_UPDATED_PACKET => true,

		ProtocolInfo::ITEM_REGISTRY_PACKET => true,

		ProtocolInfo::MOB_EFFECT_PACKET => true,

		ProtocolInfo::PLAY_STATUS_PACKET => true,
		ProtocolInfo::PLAYER_LIST_PACKET => true,

		ProtocolInfo::REMOVE_ACTOR_PACKET => true,
		ProtocolInfo::REMOVE_OBJECTIVE_PACKET => true,

		ProtocolInfo::SET_DISPLAY_OBJECTIVE_PACKET => true,
		ProtocolInfo::START_GAME_PACKET => true,
	];

    public readonly ProxyInterface $interface;
	public readonly ?APIThread $api;
	/**
	 * @var Closure(AuthenticationData $authenticationData, string $token):bool | null
	 */
	public ?Closure $authenticator;

    protected function onLoad(): void
    {
        if (is_file(COMPOSER_AUTOLOADER_PATH)) {
            require_once(COMPOSER_AUTOLOADER_PATH);

            $asyncPool = $this->getServer()->getAsyncPool();
            $asyncPool->addWorkerStartHook(static function (int $workerId) use ($asyncPool): void {
                $asyncPool->submitTaskToWorker(new ComposerRegisterAsyncTask(COMPOSER_AUTOLOADER_PATH), $workerId);
            });
        } else {
            $this->getLogger()->error("Composer autoloader not found at " . COMPOSER_AUTOLOADER_PATH);
            $this->getLogger()->error("Please install or update Composer dependencies or use provided builds.");
            $this->getServer()->shutdown();
        }
    }

    protected function onEnable(): void
    {
		EncryptionContext::$ENABLED = false;
        if ($this->getConfig()->getNested("api.enabled"))  {
            $this->api = new APIThread(
                logger: $this->getServer()->getLogger(),
                token: $this->getConfig()->getNested("api.token"),
                address: $this->getConfig()->getNested("api.address"),
                port: $this->getConfig()->getNested("api.port"),
            );
            $this->api->start();
        } else {
            $this->api = null;
        }

		$this->authenticator = fn (AuthenticationData $authenticationData, string $token): bool => $token === $this->getConfig()->get("secret");
		$this->interface = new ProxyInterface($this, $this->decode);
        $server = $this->getServer();
        $server->getNetwork()->registerInterface($this->interface);
        if ($this->getConfig()->get("disable-raklib")) {
            $server->getPluginManager()->registerEvent(
                event: NetworkInterfaceRegisterEvent::class,
                handler: static function (NetworkInterfaceRegisterEvent $event): void {
                    $interface = $event->getInterface();
                    if ($interface instanceof DedicatedQueryNetworkInterface || $interface instanceof RakLibInterface) {
                        $event->cancel();
                    }
                },
                priority: EventPriority::MONITOR,
                plugin: $this,
            );
        }
    }

	public function registerPacketDecode(int $packetID, bool $value): void
	{
		$this->decode[$packetID] = $value;
	}
}
