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
use CycloneDX\Core\Models\License\LicenseExpression;
use CycloneDX\Core\Models\License\NamedLicense;
use CycloneDX\Core\Models\License\SpdxLicense;
use CycloneDX\Core\Serialization\DOM\_BaseNormalizer;
use DOMElement;

/**
 * @author jkowalleck
 */
class LicenseNormalizer extends _BaseNormalizer
{
    public function normalize(LicenseExpression|SpdxLicense|NamedLicense $license): DOMElement
    {
        return $license instanceof LicenseExpression
            ? $this->normalizeExpression($license)
            : $this->normalizeDisjunctive($license);
    }

    private function normalizeExpression(LicenseExpression $license): DOMElement
    {
        // TODO: IMPLEMENTED IF NEEDED: may throw, if not supported by the spec
        // $this->getNormalizerFactory()->getSpec()->supportsLicenseExpression()

        $element = SimpleDOM::makeSafeTextElement(
            $this->getNormalizerFactory()->getDocument(),
            'expression',
            $license->getExpression()
        );
        \assert(null !== $element);

        return SimpleDOM::setAttributes(
            $element,
            [
                'acknowledgement' => $this->getNormalizerFactory()->getSpec()->supportsLicenseAcknowledgement()
                    ? $license->getAcknowledgement()?->value
                    : null,
            ]
        );
    }

    /**
     * @SuppressWarnings(PHPMD.ShortVariable)
     */
    private function normalizeDisjunctive(SpdxLicense|NamedLicense $license): DOMElement
    {
        $factory = $this->getNormalizerFactory();

        [$id, $name] = $license instanceof SpdxLicense
            ? [$license->getId(), null]
            : [null, $license->getName()];

        if (null !== $id && !$factory->getSpec()->isSupportedLicenseIdentifier($id)) {
            [$id, $name] = [null, $id];
        }

        $document = $factory->getDocument();

        return SimpleDOM::appendChildren(
            SimpleDOM::setAttributes(
                $document->createElement('license'),
                [
                    'acknowledgement' => $this->getNormalizerFactory()->getSpec()->supportsLicenseAcknowledgement()
                        ? $license->getAcknowledgement()?->value
                        : null,
                ]),
            [
                SimpleDOM::makeSafeTextElement($document, 'id', $id),
                SimpleDOM::makeSafeTextElement($document, 'name', $name),
                SimpleDOM::makeSafeTextElement($document, 'url', XmlHelper::encodeAnyUriBE($license->getUrl())),
            ]
        );
    }
}
