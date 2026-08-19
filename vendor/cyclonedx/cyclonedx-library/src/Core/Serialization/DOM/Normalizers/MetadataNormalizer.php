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
use CycloneDX\Core\Collections\PropertyRepository;
use CycloneDX\Core\Collections\ToolRepository;
use CycloneDX\Core\Models\Component;
use CycloneDX\Core\Models\Metadata;
use CycloneDX\Core\Serialization\DOM\_BaseNormalizer;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use DomainException;
use DOMElement;

/**
 * @author jkowalleck
 */
class MetadataNormalizer extends _BaseNormalizer
{
    public function normalize(Metadata $metadata): DOMElement
    {
        return SimpleDOM::appendChildren(
            $this->getNormalizerFactory()->getDocument()->createElement('metadata'),
            [
                $this->normalizeTimestamp($metadata->getTimestamp()),
                $this->normalizeTools($metadata->getTools()),
                // authors
                $this->normalizeComponent($metadata->getComponent()),
                // manufacture
                // supplier
                $this->normalizeProperties($metadata->getProperties()),
            ]
        );
    }

    private function normalizeTimestamp(?DateTimeInterface $timestamp): ?DOMElement
    {
        if (null === $timestamp) {
            return null;
        }

        $dtZulu = DateTime::createFromInterface($timestamp)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\\TH:i:sp');

        return SimpleDOM::makeSafeTextElement(
            $this->getNormalizerFactory()->getDocument(),
            'timestamp',
            $dtZulu
        );
    }

    private function normalizeTools(ToolRepository $tools): ?DOMElement
    {
        return 0 === \count($tools)
            ? null
            : SimpleDOM::appendChildren(
                $this->getNormalizerFactory()->getDocument()->createElement('tools'),
                $this->getNormalizerFactory()->makeForToolRepository()->normalize($tools)
            );
    }

    private function normalizeComponent(?Component $component): ?DOMElement
    {
        if (null === $component) {
            return null;
        }

        try {
            return $this->getNormalizerFactory()->makeForComponent()->normalize($component);
        } catch (DomainException) {
            return null;
        }
    }

    private function normalizeProperties(PropertyRepository $properties): ?DOMElement
    {
        if (false === $this->getNormalizerFactory()->getSpec()->supportsMetadataProperties()) {
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
