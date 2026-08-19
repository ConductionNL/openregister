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
use CycloneDX\Core\Models\License\LicenseExpression;
use CycloneDX\Core\Models\License\NamedLicense;
use CycloneDX\Core\Models\License\SpdxLicense;
use Override;

/**
 * Unique collection of:
 * - {@see SpdxLicense}
 * - {@see NamedLicense}
 * - {@see LicenseExpression}
 *
 * @author jkowalleck
 */
class LicenseRepository implements Countable
{
    /**
     * @var (SpdxLicense|NamedLicense|LicenseExpression)[]
     *
     * @psalm-var list<SpdxLicense|NamedLicense|LicenseExpression>
     */
    private array $items = [];

    public function __construct(SpdxLicense|NamedLicense|LicenseExpression ...$items)
    {
        $this->addItems(...$items);
    }

    /**
     * Add licenses.
     *
     * @return $this
     */
    public function addItems(SpdxLicense|NamedLicense|LicenseExpression ...$items): static
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
     * @return SpdxLicense[]|NamedLicense[]|LicenseExpression[]
     *
     * @psalm-return list<SpdxLicense|NamedLicense|LicenseExpression>
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
