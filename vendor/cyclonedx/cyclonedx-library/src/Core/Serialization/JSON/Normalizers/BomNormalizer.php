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
use CycloneDX\Core\Collections\PropertyRepository;
use CycloneDX\Core\Models\Bom;
use CycloneDX\Core\Models\Metadata;
use CycloneDX\Core\Serialization\JSON\_BaseNormalizer;
use CycloneDX\Core\Spec\Format;
use CycloneDX\Core\Spec\Version;

/**
 * @author jkowalleck
 */
class BomNormalizer extends _BaseNormalizer
{
    /** @var string */
    private const BOM_FORMAT = 'CycloneDX';

    /** @psalm-pure  */
    private function getSchema(Version $version): ?string
    {
        return match ($version) {
            Version::v1dot2 => 'http://cyclonedx.org/schema/bom-1.2b.schema.json',
            Version::v1dot3 => 'http://cyclonedx.org/schema/bom-1.3a.schema.json',
            Version::v1dot4 => 'http://cyclonedx.org/schema/bom-1.4.schema.json',
            Version::v1dot5 => 'http://cyclonedx.org/schema/bom-1.5.schema.json',
            Version::v1dot6 => 'http://cyclonedx.org/schema/bom-1.6.schema.json',
            Version::v1dot7 => 'http://cyclonedx.org/schema/bom-1.7.schema.json',
            default => null,
        };
    }

    /**
     * @psalm-return array<string, mixed>
     */
    public function normalize(Bom $bom): array
    {
        $factory = $this->getNormalizerFactory();
        $specVersion = $factory->getSpec()->getVersion();

        return array_filter(
            [
                '$schema' => $this->getSchema($specVersion),
                'bomFormat' => self::BOM_FORMAT,
                'specVersion' => $specVersion->value,
                'serialNumber' => $this->normalizeSerialNumber($bom->getSerialNumber()),
                'version' => $bom->getVersion(),
                'metadata' => $this->normalizeMetadata($bom->getMetadata()),
                'components' => $factory->makeForComponentRepository()->normalize($bom->getComponents()),
                // services
                'externalReferences' => $this->normalizeExternalReferences($bom),
                'dependencies' => $this->normalizeDependencies($bom),
                // compositions
                // vulnerabilities
                'properties' => $this->normalizeProperties($bom->getProperties()),
                // signature
            ],
            Predicate::isNotNull(...)
        );
    }

    private function normalizeSerialNumber(?string $serialNumber): ?string
    {
        // @TODO have the regex configurable per Spec
        return \is_string($serialNumber)
            && 1 === preg_match('/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $serialNumber)
                 ? $serialNumber
                 : null;
    }

    private function normalizeMetadata(Metadata $metadata): ?array
    {
        $factory = $this->getNormalizerFactory();

        if (false === $factory->getSpec()->supportsMetadata()) {
            return null;
        }

        $data = $factory->makeForMetadata()->normalize($metadata);

        return empty($data)
            ? null
            : $data;
    }

    private function normalizeExternalReferences(Bom $bom): ?array
    {
        $factory = $this->getNormalizerFactory();

        $extRefs = $bom->getExternalReferences();

        if (false === $factory->getSpec()->supportsMetadata()) {
            // prevent possible information loss: metadata cannot be rendered -> put it to bom
            $mcr = $bom->getMetadata()->getComponent()?->getExternalReferences();
            if (null !== $mcr) {
                $extRefs = (clone $extRefs)->addItems(...$mcr->getItems());
            }
            unset($mcr);
        }

        if (0 === \count($extRefs)) {
            return null;
        }

        $data = $factory->makeForExternalReferenceRepository()->normalize($extRefs);

        return empty($data)
            ? null
            : $data;
    }

    private function normalizeDependencies(Bom $bom): ?array
    {
        $factory = $this->getNormalizerFactory();

        if (false === $factory->getSpec()->supportsDependencies()) {
            return null;
        }

        $data = $factory->makeForDependencies()->normalize($bom);

        return empty($data)
            ? null
            : $data;
    }

    private function normalizeProperties(PropertyRepository $properties): ?array
    {
        if (false === $this->getNormalizerFactory()->getSpec()->supportsBomProperties(Format::JSON)) {
            return null;
        }

        return 0 === \count($properties)
            ? null
            : $this->getNormalizerFactory()->makeForPropertyRepository()->normalize($properties);
    }
}
