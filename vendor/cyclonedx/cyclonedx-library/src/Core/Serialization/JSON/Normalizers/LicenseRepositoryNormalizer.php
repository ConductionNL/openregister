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

use CycloneDX\Core\Collections\LicenseRepository;
use CycloneDX\Core\Models\License\LicenseExpression;
use CycloneDX\Core\Serialization\JSON\_BaseNormalizer;

/**
 * @author jkowalleck
 */
class LicenseRepositoryNormalizer extends _BaseNormalizer
{
    /**
     * If there is any {@see LicenseExpression} in `$repo`, then this is the only item that is normalized.
     */
    public function normalize(LicenseRepository $repo): array
    {
        $licenses = $repo->getItems();

        if (\count($licenses) > 1) {
            /** @var LicenseExpression[] $expressions */
            $expressions = array_filter(
                $licenses,
                static fn ($license): bool => $license instanceof LicenseExpression
            );
            if (\count($expressions) > 0) {
                /**
                 * could have thrown {@see \DomainException} when there is more than one only {@see LicenseExpression}.
                 * but let's be graceful and just normalize to the most relevant choice: any expression.
                 */
                $licenses = [reset($expressions)];
            }
        }

        return array_map(
            $this->getNormalizerFactory()->makeForLicense()->normalize(...),
            $licenses
        );
    }
}
