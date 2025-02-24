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
use pocketmine\utils\Binary;
use function substr;

final class ProxySerializer
{
    public static function encode(int $identifier, ProxyPacket $packet): string
    {
        $encoder = PacketSerializer::encoder();
        $encoder->putInt($identifier);
        $packet->encode($encoder);
        return $encoder->getBuffer();
    }

    /**
     * @return array{0: int, 1: string}
     */
    public static function decodeRaw(string $buffer): array
    {
        return [Binary::readInt(substr($buffer, 0, 4)), substr($buffer, 4)];
    }

    /**
     * @return array{0: int, 1: bool, 2: string}
     */
    public static function decodeRawWithDecodeNecessity(string $buffer): array
    {
        return [Binary::readInt(substr($buffer, 0, 4)), Binary::readBool(substr($buffer, 4, 1)), substr($buffer, 5)];
    }
}
