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

namespace CycloneDX\Contrib\License\Factories;

use CycloneDX\Contrib\License\Validators\SpdxLicenseExpressionValidatorStub;
use CycloneDX\Core\Models\License\LicenseExpression;
use CycloneDX\Core\Models\License\NamedLicense;
use CycloneDX\Core\Models\License\SpdxLicense;
use CycloneDX\Core\Spdx\LicenseIdentifiers;
use DomainException;
use InvalidArgumentException;

/**
 * License Factory.
 *
 * @author jkowalleck
 *
 * @SuppressWarnings(PHPMD.LongVariable) spdxLicenseExpressionValidator
 */
class LicenseFactory
{
    private readonly LicenseIdentifiers $licenseIdentifiers;

    /**
     * SPDX License Expression validator.
     * Something compatible with {@see SpdxLicenseExpressionValidator}.
     *
     * Cannot use Type Constraints, to be compatible with 3rd party implementations on runtime.
     *
     * @var SpdxLicenseExpressionValidatorStub
     */
    private readonly object $spdxLicenseExpressionValidator;

    /**
     * @param object $spdxLicenseExpressionValidator Something compatible with {@see SpdxLicenseExpressionValidatorStub}
     */
    public function __construct(
        LicenseIdentifiers $licenseIdentifiers,
        object $spdxLicenseExpressionValidator,
    ) {
        $this->licenseIdentifiers = $licenseIdentifiers;
        /** @psalm-var SpdxLicenseExpressionValidatorStub spdxLicenseExpressionValidator */
        $this->spdxLicenseExpressionValidator = $spdxLicenseExpressionValidator;
    }

    public function getLicenseIdentifiers(): LicenseIdentifiers
    {
        return $this->licenseIdentifiers;
    }

    /**
     * @return SpdxLicenseExpressionValidatorStub
     */
    public function getSpdxLicensesExpressionValidator(): object
    {
        return $this->spdxLicenseExpressionValidator;
    }

    public function makeFromString(string $license): SpdxLicense|LicenseExpression|NamedLicense
    {
        try {
            return $this->makeSpdxLicense($license);
        } catch (DomainException) {
            /* pass */
        }
        try {
            return $this->makeExpression($license);
        } catch (DomainException) {
            /* pass */
        }

        return $this->makeNamedLicense($license);
    }

    public function makeDisjunctive(string $license): SpdxLicense|NamedLicense
    {
        try {
            return $this->makeSpdxLicense($license);
        } catch (DomainException) {
            return $this->makeNamedLicense($license);
        }
    }

    /**
     * @throws DomainException when the SPDX license expressions was invalid
     */
    public function makeExpression(string $license): LicenseExpression
    {
        try {
            $valid = $this->spdxLicenseExpressionValidator->validate($license);
        } catch (InvalidArgumentException) {
            $valid = false;
        }
        if (true === $valid) {
            return new LicenseExpression($license);
        }
        throw new DomainException("invalid SPDX license expressions: $license");
    }

    /**
     * @throws DomainException when the SPDX license ID is unknown
     */
    public function makeSpdxLicense(string $license): SpdxLicense
    {
        $fixed = $this->licenseIdentifiers->fixLicense($license);
        if (null === $fixed) {
            throw new DomainException("unknown SPDX license ID: $license");
        }

        return new SpdxLicense($fixed);
    }

    public function makeNamedLicense(string $license): NamedLicense
    {
        return new NamedLicense($license);
    }
}
