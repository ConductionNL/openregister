<?php

declare(strict_types=1);

/*
 * This file is part of CycloneDX PHP Library.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * SPDX-License-Identifier: Apache-2.0
 * Copyright (c) OWASP Foundation. All Rights Reserved.
 */

namespace CycloneDX\Core\Collections;

use Countable;
use CycloneDX\Core\Models\Property;
use Override;

/**
 * Unique collection of {@see Property}.
 *
 * @author jkowalleck
 */
class PropertyRepository implements Countable
{
    /**
     * @var Property[]
     *
     * @psalm-var list<Property>
     */
    private array $items = [];

    public function __construct(Property ...$items)
    {
        $this->addItems(...$items);
    }

    /**
     * Please be aware: Currently there is no functionality that checks for
     * unique (property-key, property-value) combinations in the repository.
     *
     * @return $this
     */
    public function addItems(Property ...$items): static
    {
        foreach ($items as $item) {
            if (\in_array($item, $this->items, true)) {
                continue;
            }
            $this->items[] = $item;
        }

        return $this;
    }

    /**
     * @return Property[]
     *
     * @psalm-return list<Property>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @psalm-return 0|positive-int
     */
    #[Override]
    public function count(): int
    {
        return \count($this->items);
    }
}
