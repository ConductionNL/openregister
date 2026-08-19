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
use CycloneDX\Core\_helpers\XML as XmlHelper;
use CycloneDX\Core\Collections\ExternalReferenceRepository;
use CycloneDX\Core\Collections\HashDictionary;
use CycloneDX\Core\Collections\LicenseRepository;
use CycloneDX\Core\Collections\PropertyRepository;
use CycloneDX\Core\Models\Component;
use CycloneDX\Core\Serialization\DOM\_BaseNormalizer;
use DomainException;
use DOMElement;

/**
 * @author jkowalleck
 */
class ComponentNormalizer extends _BaseNormalizer
{
    /**
     * @throws DomainException if component has unsupported type
     */
    public function normalize(Component $component): DOMElement
    {
        $name = $component->getName();
        $group = $component->getGroup();
        $version = $component->getVersion();

        $factory = $this->getNormalizerFactory();
        $spec = $factory->getSpec();

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

        $document = $factory->getDocument();

        return SimpleDOM::appendChildren(
            SimpleDOM::setAttributes(
                $document->createElement('component'),
                [
                    'type' => $type->value,
                    'bom-ref' => $bomRef,
                ]
            ),
            [
                // supplier
                $spec->supportsComponentAuthor()
                    ? SimpleDOM::makeSafeTextElement($document, 'author', $component->getAuthor())
                    : null,
                // publisher
                SimpleDOM::makeSafeTextElement($document, 'group', $group),
                SimpleDOM::makeSafeTextElement($document, 'name', $name),
                SimpleDOM::makeSafeTextElement($document, 'version',
                    null === $version && $spec->requiresComponentVersion()
                        ? ''
                        : $version
                ),
                SimpleDOM::makeSafeTextElement($document, 'description', $component->getDescription()),
                // scope
                $this->normalizeHashes($component->getHashes()),
                $this->normalizeLicenses($component->getLicenses()),
                SimpleDOM::makeSafeTextElement($document, 'copyright', $component->getCopyright()),
                // cpe
                $this->normalizePurl($component->getPackageUrl()),
                // swid
                // modified
                // pedigree
                $this->normalizeExternalReferences($component->getExternalReferences()),
                $this->normalizeProperties($component->getProperties()),
                // components
                null === $evidence
                    ? null
                    : $this->getNormalizerFactory()->makeForComponentEvidence()->normalize($evidence),
            ]
        );
    }

    private function normalizeLicenses(LicenseRepository $licenses): ?DOMElement
    {
        $factory = $this->getNormalizerFactory();

        return 0 === \count($licenses)
            ? null
            : SimpleDOM::appendChildren(
                $factory->getDocument()->createElement('licenses'),
                $factory->makeForLicenseRepository()->normalize($licenses)
            );
    }

    private function normalizeHashes(HashDictionary $hashes): ?DOMElement
    {
        $factory = $this->getNormalizerFactory();

        return 0 === \count($hashes)
            ? null
            : SimpleDOM::appendChildren(
                $factory->getDocument()->createElement('hashes'),
                $factory->makeForHashDictionary()->normalize($hashes)
            );
    }

    private function normalizePurl(?string $purl): ?DOMElement
    {
        return null === $purl
            ? null
            : SimpleDOM::makeSafeTextElement(
                $this->getNormalizerFactory()->getDocument(),
                'purl',
                XmlHelper::encodeAnyUriBE($purl)
            );
    }

    private function normalizeExternalReferences(ExternalReferenceRepository $extRefs): ?DOMElement
    {
        $factory = $this->getNormalizerFactory();

        return 0 === \count($extRefs)
            ? null
            : SimpleDOM::appendChildren(
                $factory->getDocument()->createElement('externalReferences'),
                $factory->makeForExternalReferenceRepository()->normalize($extRefs)
            );
    }

    private function normalizeProperties(PropertyRepository $properties): ?DOMElement
    {
        if (false === $this->getNormalizerFactory()->getSpec()->supportsComponentProperties()) {
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
