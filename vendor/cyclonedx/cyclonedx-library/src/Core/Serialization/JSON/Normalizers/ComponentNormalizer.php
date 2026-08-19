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
use CycloneDX\Core\Collections\ExternalReferenceRepository;
use CycloneDX\Core\Collections\HashDictionary;
use CycloneDX\Core\Collections\LicenseRepository;
use CycloneDX\Core\Collections\PropertyRepository;
use CycloneDX\Core\Models\Component;
use CycloneDX\Core\Serialization\JSON\_BaseNormalizer;
use DomainException;

/**
 * @author jkowalleck
 */
class ComponentNormalizer extends _BaseNormalizer
{
    /**
     * @throws DomainException if component has unsupported type
     */
    public function normalize(Component $component): array
    {
        $spec = $this->getNormalizerFactory()->getSpec();

        $name = $component->getName();
        $group = $component->getGroup();
        $version = $component->getVersion();

        $type = $component->getType();
        if (false === $spec->isSupportedComponentType($type)) {
            $reportFQN = "$group/$name";
            if (null !== $version) {
                $reportFQN .= "@$version";
            }
            throw new DomainException("Component '$reportFQN' has unsupported type: $type->name");
        }

        $bomRef = $spec->supportsBomRef()
            ? $component->getBomRef()->getValue()
            : null;
        $evidence = $spec->supportsComponentEvidence()
            ? $component->getEvidence()
            : null;

        return array_filter(
            [
                'bom-ref' => $bomRef,
                'type' => $type->value,
                'name' => $name,
                'version' => null === $version && $spec->requiresComponentVersion()
                    ? ''
                    : $version,
                'group' => $group,
                'description' => $component->getDescription(),
                'author' => $component->getAuthor(),
                'licenses' => $this->normalizeLicenses($component->getLicenses()),
                'copyright' => $component->getCopyright(),
                'evidence' => null === $evidence
                    ? null
                    : $this->getNormalizerFactory()->makeForComponentEvidence()->normalize($evidence),
                'hashes' => $this->normalizeHashes($component->getHashes()),
                'purl' => $this->normalizePurl($component->getPackageUrl()),
                'externalReferences' => $this->normalizeExternalReferences($component->getExternalReferences()),
                'properties' => $this->normalizeProperties($component->getProperties()),
            ],
            Predicate::isNotNull(...)
        );
    }

    private function normalizeLicenses(LicenseRepository $licenses): ?array
    {
        return 0 === \count($licenses)
            ? null
            : $this->getNormalizerFactory()->makeForLicenseRepository()->normalize($licenses);
    }

    private function normalizeHashes(HashDictionary $hashes): ?array
    {
        return 0 === \count($hashes)
            ? null
            : $this->getNormalizerFactory()->makeForHashDictionary()->normalize($hashes);
    }

    /**  @SuppressWarnings(PHPMD.StaticAccess) */
    private function normalizePurl(?string $purl): ?string
    {
        return null === $purl
            ? null
            : JsonHelper::encodeIriReferenceBE($purl);
    }

    private function normalizeExternalReferences(ExternalReferenceRepository $extRefs): ?array
    {
        return 0 === \count($extRefs)
            ? null
            : $this->getNormalizerFactory()->makeForExternalReferenceRepository()->normalize($extRefs);
    }

    private function normalizeProperties(PropertyRepository $properties): ?array
    {
        if (false === $this->getNormalizerFactory()->getSpec()->supportsComponentProperties()) {
            return null;
        }

        return 0 === \count($properties)
            ? null
            : $this->getNormalizerFactory()->makeForPropertyRepository()->normalize($properties);
    }
}
