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

namespace CycloneDX\Core\Serialization\DOM\Normalizers;

use CycloneDX\Core\_helpers\SimpleDOM;
use CycloneDX\Core\Collections\ComponentRepository;
use CycloneDX\Core\Collections\PropertyRepository;
use CycloneDX\Core\Models\Bom;
use CycloneDX\Core\Models\Metadata;
use CycloneDX\Core\Serialization\DOM\_BaseNormalizer;
use CycloneDX\Core\Spec\Format;
use DOMElement;

/**
 * @author jkowalleck
 */
class BomNormalizer extends _BaseNormalizer
{
    /** @var string */
    private const XML_NAMESPACE_PATTERN = 'http://cyclonedx.org/schema/bom/%s';

    public function normalize(Bom $bom): DOMElement
    {
        $factory = $this->getNormalizerFactory();
        $document = $factory->getDocument();

        $element = $document->createElementNS(
            \sprintf(self::XML_NAMESPACE_PATTERN, $factory->getSpec()->getVersion()->value),
            'bom' // no namespace = defaultNS - so children w/o NS fall under this NS
        );
        SimpleDOM::setAttributes(
            $element,
            [
                'version' => $bom->getVersion(),
                'serialNumber' => $this->normalizeSerialNumber($bom->getSerialNumber()),
            ]
        );

        SimpleDOM::appendChildren(
            $element,
            [
                $this->normalizeMetadata($bom->getMetadata()),
                $this->normalizeComponents($bom->getComponents()),
                // services
                $this->normalizeExternalReferences($bom),
                $this->normalizeDependencies($bom),
                // compositions
                $this->normalizeProperties($bom->getProperties()),
            ]
        );

        return $element;
    }

    private function normalizeSerialNumber(?string $serialNumber): ?string
    {
        // @TODO have the regex configurable per Spec
        return \is_string($serialNumber)
            && 1 === preg_match('/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$|^\\{[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\\}$/', $serialNumber)
                ? $serialNumber
                : null;
    }

    private function normalizeComponents(ComponentRepository $components): DOMElement
    {
        $factory = $this->getNormalizerFactory();

        return SimpleDOM::appendChildren(
            $factory->getDocument()->createElement('components'),
            $factory->makeForComponentRepository()->normalize($components)
        );
    }

    private function normalizeMetadata(Metadata $metadata): ?DOMElement
    {
        $factory = $this->getNormalizerFactory();

        if (false === $factory->getSpec()->supportsMetadata()) {
            return null;
        }

        $elem = $factory->makeForMetadata()->normalize($metadata);

        return $elem->hasChildNodes()
            ? $elem
            : null;
    }

    private function normalizeExternalReferences(Bom $bom): ?DOMElement
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

        $refs = $factory->makeForExternalReferenceRepository()->normalize($extRefs);

        return 0 === \count($refs)
            ? null
            : SimpleDOM::appendChildren(
                $factory->getDocument()->createElement('externalReferences'),
                $refs
            );
    }

    private function normalizeDependencies(Bom $bom): ?DOMElement
    {
        $factory = $this->getNormalizerFactory();

        if (false === $factory->getSpec()->supportsDependencies()) {
            return null;
        }

        $deps = $factory->makeForDependencies()->normalize($bom);

        return empty($deps)
            ? null
            : SimpleDOM::appendChildren(
                $factory->getDocument()->createElement('dependencies'),
                $deps
            );
    }

    private function normalizeProperties(PropertyRepository $properties): ?DOMElement
    {
        if (false === $this->getNormalizerFactory()->getSpec()->supportsBomProperties(Format::XML)) {
            return null;
        }

        return 0 === \count($properties)
            ? null
            : SimpleDOM::appendChildren(
                $this->getNormalizerFactory()->getDocument()->createElement('properties'),
                $this->getNormalizerFactory()->makeForPropertyRepository()->normalize($properties)
            );
    }
}
