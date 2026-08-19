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

use CycloneDX\Core\_helpers\Predicate;
use CycloneDX\Core\Collections\ExternalReferenceRepository;
use CycloneDX\Core\Collections\HashDictionary;
use CycloneDX\Core\Models\Tool;
use CycloneDX\Core\Serialization\JSON\_BaseNormalizer;

/**
 * @author jkowalleck
 */
class ToolNormalizer extends _BaseNormalizer
{
    public function normalize(Tool $tool): array
    {
        return array_filter(
            [
                'vendor' => $tool->getVendor(),
                'name' => $tool->getName(),
                'version' => $tool->getVersion(),
                'hashes' => $this->normalizeHashes($tool->getHashes()),
                'externalReferences' => $this->normalizeExternalReferences($tool->getExternalReferences()),
            ],
            Predicate::isNotNull(...)
        );
    }

    private function normalizeHashes(HashDictionary $hashes): ?array
    {
        return 0 === \count($hashes)
            ? null
            : $this->getNormalizerFactory()->makeForHashDictionary()->normalize($hashes);
    }

    private function normalizeExternalReferences(ExternalReferenceRepository $extRefs): ?array
    {
        $factory = $this->getNormalizerFactory();

        if (false === $factory->getSpec()->supportsToolExternalReferences()) {
            return null;
        }

        return 0 === \count($extRefs)
            ? null
            : $factory->makeForExternalReferenceRepository()->normalize($extRefs);
    }
}
