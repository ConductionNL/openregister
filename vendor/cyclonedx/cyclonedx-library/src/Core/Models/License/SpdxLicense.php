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

namespace CycloneDX\Core\Models\License;

use DomainException;

/**
 * Disjunctive license with (SPDX-)ID - aka SpdxLicense.
 *
 * No validation is done internally.
 * You may validate with {@see \CycloneDX\Core\Spdx\LicenseIdentifiers::isKnownLicense()}.
 * You may assert valid objects with {@see \CycloneDX\Core\Factories\LicenseFactory::makeSpdxLicense()}.
 *
 * @SuppressWarnings(PHPMD.ShortVariable) $id
 *
 * @author jkowalleck
 */
class SpdxLicense
{
    use _DisjunctiveLicenseBase;

    /**
     * A valid supported SPDX license ID.
     *
     * @psalm-var non-empty-string
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    private string $id;

    /**
     * @psalm-return non-empty-string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @psalm-assert non-empty-string $id
     *
     * @throws DomainException if `$id` is empty string
     *
     * @return $this
     */
    public function setId(string $id): static
    {
        if ('' === $id) {
            throw new DomainException('ID must not be empty');
        }
        $this->id = $id;

        return $this;
    }

    /**
     * @psalm-assert non-empty-string $id
     *
     * @throws DomainException if `$id` is empty string
     */
    public function __construct(string $id)
    {
        $this->setId($id);
    }
}
