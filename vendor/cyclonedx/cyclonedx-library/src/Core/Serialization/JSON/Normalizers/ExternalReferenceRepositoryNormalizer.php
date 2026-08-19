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

use CycloneDX\Core\Collections\ExternalReferenceRepository;
use CycloneDX\Core\Serialization\JSON\_BaseNormalizer;
use DomainException;
use UnexpectedValueException;

/**
 * @author jkowalleck
 */
class ExternalReferenceRepositoryNormalizer extends _BaseNormalizer
{
    /**
     * @return array[]
     *
     * @psalm-return list<array>
     */
    public function normalize(ExternalReferenceRepository $repo): array
    {
        $normalizer = $this->getNormalizerFactory()->makeForExternalReference();

        $externalReferences = [];
        foreach ($repo->getItems() as $externalReference) {
            try {
                $item = $normalizer->normalize($externalReference);
            } catch (DomainException|UnexpectedValueException) {
                continue;
            }
            if (false === empty($item)) {
                $externalReferences[] = $item;
            }
        }

        return $externalReferences;
    }
}
