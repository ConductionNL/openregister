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

namespace CycloneDX\Core\Serialization\JSON\Normalizers;

use CycloneDX\Core\_helpers\JSON as JsonHelper;
use CycloneDX\Core\_helpers\Predicate;
use CycloneDX\Core\Models\License\LicenseExpression;
use CycloneDX\Core\Models\License\NamedLicense;
use CycloneDX\Core\Models\License\SpdxLicense;
use CycloneDX\Core\Serialization\JSON\_BaseNormalizer;

/**
 * @author jkowalleck
 */
class LicenseNormalizer extends _BaseNormalizer
{
    public function normalize(LicenseExpression|SpdxLicense|NamedLicense $license): array
    {
        return $license instanceof LicenseExpression
            ? $this->normalizeExpression($license)
            : $this->normalizeDisjunctive($license);
    }

    private function normalizeExpression(LicenseExpression $license): array
    {
        // TODO: IMPLEMENTED IF NEEDED: may throw, if not supported by the spec
        // $this->getNormalizerFactory()->getSpec()->supportsLicenseExpression()

        return array_filter(
            [
                'expression' => $license->getExpression(),
                'acknowledgement' => $this->getNormalizerFactory()->getSpec()->supportsLicenseAcknowledgement()
                    ? $license->getAcknowledgement()?->value
                    : null,
            ],
            Predicate::isNotNull(...)
        );
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable) $id
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function normalizeDisjunctive(SpdxLicense|NamedLicense $license): array
    {
        [$id, $name] = $license instanceof SpdxLicense
            ? [$license->getId(), null]
            : [null, $license->getName()];
        if (null !== $id && !$this->getNormalizerFactory()->getSpec()->isSupportedLicenseIdentifier($id)) {
            [$id, $name] = [null, $id];
        }

        return ['license' => array_filter(
            [
                'id' => $id,
                'name' => $name,
                'url' => JsonHelper::encodeIriReferenceBE($license->getUrl()),
                'acknowledgement' => $this->getNormalizerFactory()->getSpec()->supportsLicenseAcknowledgement()
                    ? $license->getAcknowledgement()?->value
                    : null,
            ],
            Predicate::isNotNull(...)
        )];
    }
}
