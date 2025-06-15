<?php

declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2025 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\Reactive;

use danog\Loop\Loop;
use SplQueue;
use WeakMap;
use Webmozart\Assert\Assert;

/** @template T */
final class Actor extends Loop implements Subscriber {

    /** @var SplQueue<list{T}|list{T, T}> */
    private readonly SplQueue $queue;
    /** @var WeakMap<Subscriber, self> */
    private static WeakMap $storage;

    /** 
     * @template TT
     * @param Subscriber<TT> $subscriber
     * @return self<TT>
     */
    public static function from(Subscriber $subscriber): self
    {
        if ($subscriber instanceof self) {
            return $subscriber;
        }
        /** @var WeakMap<Subscriber, self> */
        self::$storage ??= new WeakMap;
        if (isset(self::$storage[$subscriber])) {
            return self::$storage[$subscriber];
        }
        self::$storage[$subscriber] = $s = new self($subscriber);
        return $s;
    }

    private function __construct(
        /** @var Subscriber<T> $subscriber */
        private readonly Subscriber $subscriber
    )
    {
        /** @var SplQueue<list{T}|list{T, T}> */
        $this->queue = new SplQueue;
    }

    public function __wakeup()
    {
        self::$storage ??= new WeakMap;
        self::$storage[$this->subscriber] = $this;
    }

    #[\Override]
    public function onAttach($initState): void
    {
        $this->queue->enqueue([$initState]);
        if ($this->isRunning()) {
            $this->resume(true);
        } else {
            $this->start();
        }
    }

    #[\Override]
    public function onStateChange($prevState, $state): void
    {
        $this->queue->enqueue([$prevState, $state]);
        if ($this->isRunning()) {
            $this->resume(true);
        } else {
            $this->start();
        }
    }

    #[\Override]
    public function loop(): ?float
    {
        foreach ($this->queue as $item) {
            if (count($item) === 1) {
                $this->subscriber->onAttach($item[0]);
            } else {
                $this->subscriber->onStateChange($item[0], $item[1]);
            }
        }
        return self::PAUSE;
    }
}