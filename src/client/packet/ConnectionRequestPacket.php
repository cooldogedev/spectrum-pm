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

namespace cooldogedev\Spectrum\client\packet;

use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use function json_decode;
use function json_encode;
use const JSON_INVALID_UTF8_IGNORE;

final class ConnectionRequestPacket extends ProxyPacket
{
    public const NETWORK_ID = ProxyPacketIds::CONNECTION_REQUEST;

    public string $address;

    public array $clientData;
    public array $identityData;

    public int $protocolID;

    public string $cache;

    public static function create(string $address, array $clientData, array $identityData, int $protocolID, string $cache): ConnectionRequestPacket
    {
        $packet = new ConnectionRequestPacket();
        $packet->address = $address;
        $packet->clientData = $clientData;
        $packet->identityData = $identityData;
        $packet->protocolID = $protocolID;
        $packet->cache = $cache;
        return $packet;
    }

    public function decodePayload(PacketSerializer $in): void
    {
        $this->address = $in->getString();
        $this->clientData = json_decode($in->getString(), true, JSON_INVALID_UTF8_IGNORE);
        $this->identityData = json_decode($in->getString(), true, JSON_INVALID_UTF8_IGNORE);
        $this->protocolID = $in->getLInt();
        $this->cache = $in->getString();
    }

    public function encodePayload(PacketSerializer $out): void
    {
        $out->putString($this->address);
        $out->putString(json_encode($this->clientData, JSON_INVALID_UTF8_IGNORE));
        $out->putString(json_encode($this->identityData, JSON_INVALID_UTF8_IGNORE));
        $out->putLInt($this->protocolID);
        $out->putString($this->cache);
    }
}
