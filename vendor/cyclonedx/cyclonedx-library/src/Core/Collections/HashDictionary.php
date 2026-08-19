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
use CycloneDX\Core\Enums\HashAlgorithm;
use Override;

/**
 * Dictionary of {@see HashAlgorithm} => {@see HashContent}.
 *
 * @psalm-type HashContent = string
 * @psalm-type HashAlgorithmContentTuple = list{HashAlgorithm,HashContent}
 *
 * @author jkowalleck
 */
class HashDictionary implements Countable
{
    /**
     * @psalm-var  array<string,HashAlgorithmContentTuple>
     */
    private array $items = [];

    /**
     * Ignores unknown hash algorithms.
     *
     * @param array $items list of tuples of [{@see HashAlgorithm} `$algorithm`, string `$content`]
     *
     * @psalm-param array<HashAlgorithmContentTuple> $items
     */
    public function __construct(array ...$items)
    {
        $this->setItems(...$items);
    }

    /**
     * Set the hashes.
     * Ignores unknown hash algorithms.
     *
     * @param array $items list of tuples of [{@see HashAlgorithm} `$algorithm`, string `$content`]
     *
     * @psalm-param array<HashAlgorithmContentTuple> $items
     *
     * @return $this
     */
    public function setItems(array ...$items): static
    {
        foreach ($items as [$algorithm, $content]) {
            $this->set($algorithm, $content);
        }

        return $this;
    }

    /**
     * @return array[] list of tuples of [{@see HashAlgorithm} `$algorithm`, string `$content`]
     *
     * @psalm-return list<HashAlgorithmContentTuple>
     */
    public function getItems(): array
    {
        return array_values($this->items);
    }

    /**
     * @return $this
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function set(HashAlgorithm $algorithm, ?string $content): static
    {
        $key = self::makeDictKey($algorithm);
        if (null === $content || '' === $content) {
            unset($this->items[$key]);
        } else {
            // no validation. content is schema-specific and may vary from CycloneDX spec to another.
            $this->items[$key] = [$algorithm, $content];
        }

        return $this;
    }

    public function get(HashAlgorithm $algorithm): ?string
    {
        return $this->items[self::makeDictKey($algorithm)][1]
            ?? null;
    }

    /** @psalm-pure  */
    private static function makeDictKey(HashAlgorithm $algorithm): string
    {
        return $algorithm->value;
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
