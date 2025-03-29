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

use cooldogedev\Spectrum\client\packet\FlushPacket;
use pocketmine\network\mcpe\PacketSender;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\utils\BinaryStream;
use function substr;

final class ProxySender implements PacketSender
{
    private bool $closed = false;

    public function __construct(private readonly ProxyInterface $interface, private readonly int $identifier) {}

    public function send(string $payload, bool $immediate, ?int $receiptId): void
    {
        if ($this->closed) {
            return;
        }

        $stream = new BinaryStream(substr($payload, 1));
        foreach (PacketBatch::decodeRaw($stream) as $packet) {
            $this->interface->sendOutgoingRaw($this->identifier, $packet, null);
        }
        $this->interface->sendOutgoing($this->identifier, FlushPacket::create(), $receiptId);
    }

    public function close(string $reason = "unknown reason"): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->interface->disconnect($this->identifier, true, $reason);
    }
}
