<?php

declare(strict_types=1);

namespace cooldogedev\Spectrum\client\packet;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;

final class UpdateCachePacket extends ProxyPacket implements ClientboundPacket
{
	public const NETWORK_ID = ProxyPacketIds::UPDATE_CACHE;

	public string $cache;

	public static function create(string $cache): ConnectionRequestPacket
	{
		$packet = new ConnectionRequestPacket();
		$packet->cache = $cache;
		return $packet;
	}

	public function decodePayload(PacketSerializer $in): void
	{
		$this->cache = $in->getString();
	}

	public function encodePayload(PacketSerializer $out): void
	{
		$out->putString($this->cache);
	}
}
