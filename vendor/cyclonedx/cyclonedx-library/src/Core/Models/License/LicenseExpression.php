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

use CycloneDX\Core\Enums\LicenseAcknowledgement;
use DomainException;

/**
 * (SPDX) License Expression.
 *
 * No validation is done internally.
 * You may validate with {@see \Composer\Spdx\SpdxLicenses::isValidLicenseString()}.
 * You may assert valid objects with {@see \CycloneDX\Core\Factories\LicenseFactory::makeExpression()}.
 *
 * @author jkowalleck
 */
class LicenseExpression
{
    /**
     * @psalm-var non-empty-string
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    private string $expression;

    private ?LicenseAcknowledgement $acknowledgement = null;

    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * @psalm-assert non-empty-string $expression
     *
     * @throws DomainException if `$expression` is empty string
     *
     * @return $this
     */
    public function setExpression(string $expression): static
    {
        if ('' === $expression) {
            throw new DomainException('expression must not be empty');
        }
        $this->expression = $expression;

        return $this;
    }

    public function getAcknowledgement(): ?LicenseAcknowledgement
    {
        return $this->acknowledgement;
    }

    /** @return $this */
    public function setAcknowledgement(?LicenseAcknowledgement $acknowledgement): static
    {
        $this->acknowledgement = $acknowledgement;

        return $this;
    }

    /**
     * @psalm-assert non-empty-string $expression
     *
     * @throws DomainException if `$expression` is empty string
     */
    public function __construct(string $expression)
    {
        $this->setExpression($expression);
    }
}
