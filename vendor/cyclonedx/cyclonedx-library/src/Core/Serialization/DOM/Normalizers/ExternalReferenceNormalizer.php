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
use CycloneDX\Core\Collections\HashDictionary;
use CycloneDX\Core\Enums\ExternalReferenceType;
use CycloneDX\Core\Models\ExternalReference;
use CycloneDX\Core\Serialization\DOM\_BaseNormalizer;
use DomainException;
use DOMElement;
use UnexpectedValueException;

/**
 * @author jkowalleck
 */
class ExternalReferenceNormalizer extends _BaseNormalizer
{
    /**
     * @throws DomainException          when the type was not supported by the spec
     * @throws UnexpectedValueException when url was unable to convert to XML::anyURI
     */
    public function normalize(ExternalReference $externalReference): DOMElement
    {
        $refURI = $externalReference->getUrl();
        $anyURI = XmlHelper::encodeAnyUriBE($refURI)
            ?? throw new UnexpectedValueException("unable to make 'anyURI' from: $refURI");

        $factory = $this->getNormalizerFactory();
        $spec = $factory->getSpec();

        $type = $externalReference->getType();
        if (false === $spec->isSupportedExternalReferenceType($type)) {
            // prevent information-loss -> try transfer to OTHER
            $type = ExternalReferenceType::Other;
            if (false === $spec->isSupportedExternalReferenceType($type)) {
                throw new DomainException('ExternalReference has unsupported type: '.$externalReference->getType()->name);
            }
        }

        $doc = $factory->getDocument();

        return SimpleDOM::appendChildren(
            SimpleDOM::setAttributes(
                $doc->createElement('reference'),
                [
                    'type' => $type->value,
                ]
            ),
            [
                SimpleDOM::makeSafeTextElement($doc, 'url', $anyURI),
                SimpleDOM::makeSafeTextElement($doc, 'comment', $externalReference->getComment()),
                $this->normalizeHashes($externalReference->getHashes()),
            ]
        );
    }

    private function normalizeHashes(HashDictionary $hashes): ?DOMElement
    {
        if (0 === \count($hashes)) {
            return null;
        }

        $factory = $this->getNormalizerFactory();
        if (false === $factory->getSpec()->supportsExternalReferenceHashes()) {
            return null;
        }

        return SimpleDOM::appendChildren(
            $factory->getDocument()->createElement('hashes'),
            $factory->makeForHashDictionary()->normalize($hashes)
        );
    }
}
