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

namespace danog\MadelineProto\FileRefExtractor\BuildMode;

use danog\MadelineProto\FileRefExtractor\BuildMode;
use danog\MadelineProto\FileRefExtractor\TLContext;

final class Ast implements BuildMode
{
    private array $output = [];
    private ?string $needsParent = null;

    public function __construct(
        private readonly bool $allowBackrefs,
    ) {
    }

    public function getOutput(): array
    {
        return $this->output;
    }

    public function addNode(TLContext $ctx, array $node): void
    {
        if ($this->needsParent !== null) {
            $node['needsParent'] = $this->needsParent;
        }
        $this->output[$ctx->position][] = $node;
        $this->needsParent = null;
    }

    public function getNeedsParent(): ?string
    {
        return $this->needsParent;
    }

    public function setNeedsParent(string $needsParent): void
    {
        if (!$this->allowBackrefs) {
            throw new \LogicException('Cannot set needsParent when backreferences are not allowed.');
        }
        if ($this->needsParent !== null && $this->needsParent !== $needsParent) {
            throw new \LogicException("Cannot change needsParent from {$this->needsParent} to {$needsParent} once it has been set.");
        }
        $this->needsParent = $needsParent;
    }
}
