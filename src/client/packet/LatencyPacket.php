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

final class LatencyPacket extends ProxyPacket
{
    public const NETWORK_ID = ProxyPacketIds::LATENCY;

    public int $latency;
    public int $timestamp;

    public static function create(int $latency, int $timestamp): LatencyPacket
    {
        $packet = new LatencyPacket();
        $packet->latency = $latency;
        $packet->timestamp = $timestamp;
        return $packet;
    }

    protected function decodePayload(PacketSerializer $in): void
    {
        $this->latency = $in->getLLong();
        $this->timestamp = $in->getLLong();
    }

    protected function encodePayload(PacketSerializer $out): void
    {
        $out->putLLong($this->latency);
        $out->putLLong($this->timestamp);
    }
}

