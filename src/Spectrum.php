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

use cooldogedev\Spectrum\api\APIThread;
use cooldogedev\Spectrum\network\ProxyInterface;
use pocketmine\event\EventPriority;
use pocketmine\event\server\NetworkInterfaceRegisterEvent;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\plugin\PluginBase;

final class Spectrum extends PluginBase
{
    public readonly ?APIThread $api;
    public readonly ProxyInterface $interface;

    protected function onEnable(): void
    {
        if ($this->getConfig()->getNested("api.enabled"))  {
            $this->api = new APIThread(
                logger: $this->getServer()->getLogger(),
                address: $this->getConfig()->getNested("api.address"),
                port: $this->getConfig()->getNested("api.port"),
            );
            $this->api->start();
        } else {
            $this->api = null;
        }

        $this->interface = new ProxyInterface($this);

        $server = $this->getServer();
        $server->getNetwork()->registerInterface($this->interface);
        if ($this->getConfig()->get("disable-raklib")) {
            $server->getPluginManager()->registerEvent(
                event: NetworkInterfaceRegisterEvent::class,
                handler: function (NetworkInterfaceRegisterEvent $event): void {
                    if ($event->getInterface() instanceof RakLibInterface) {
                        $event->cancel();
                    }
                },
                priority: EventPriority::MONITOR,
                plugin: $this,
            );
        }
    }
}
