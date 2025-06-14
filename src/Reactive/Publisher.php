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

use SplObjectStorage;
use WeakMap;

/**
 * @internal
 *
 * @template T
 */
final class Publisher
{
    /** @var WeakMap<Subscriber, bool> */
    private WeakMap $subscribers;
    /**
     * @param T $state
     */
    public function __construct(
        private mixed $state
    ) {
        $this->subscribers = new WeakMap;
    }

    public function __serialize(): array
    {
        $subscribers = new SplObjectStorage;
        foreach ($this->subscribers as $subscriber => $v) {
            $subscribers[$subscriber] = $v;
        }
        return ['state' => $this->state, 'subscribers' => $subscribers];
    }

    public function __unserialize(array $data): void
    {
        $this->state = $data['state'];
        $this->subscribers = new WeakMap;
        foreach ($data['subscribers'] as $subscriber => $v) {
            $this->subscribers[$subscriber] = $v;
            $subscriber->onAttach($this->state);
        }
    }

    public function subscribe(Subscriber $subscriber): void
    {
        if (!isset($this->subscribers[$subscriber])) {
            $this->subscribers[$subscriber] = false;
            $subscriber->onAttach($this->state);
        }
    }

    public function subscribeActor(Subscriber $subscriber): void
    {
        if (!isset($this->subscribers[$subscriber])) {
            $this->subscribers[$subscriber] = true;
            $subscriber->onAttach($this->state);
        }
    }

    public function publish($state): void
    {
        if ($state !== $this->state) {
            $prev = $this->state;
            foreach ($this->subscribers as $subscriber => $actor) {
                $subscriber->onStateChange($prev, $state);
            }
            $this->state = $state;
        }
    }
}
