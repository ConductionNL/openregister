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

namespace CycloneDX\Core\Serialization;

use CycloneDX\Core\Models\Bom;
use DOMDocument;
use DOMElement;
use Override;

/**
 * Transform data models to XML.
 *
 * @psalm-type TNormalizedBom = DOMElement
 *
 * @template-extends BaseSerializer<TNormalizedBom>
 *
 * @author jkowalleck
 */
class XmlSerializer extends BaseSerializer
{
    public function __construct(
        protected readonly DOM\NormalizerFactory $normalizerFactory,
        protected readonly string $xmlVersion = '1.0',
        protected readonly string $xmlEncoding = 'UTF-8',
    ) {
    }

    #[Override]
    protected function realNormalize(Bom $bom): DOMElement
    {
        return $this->normalizerFactory
            ->makeForBom()
            ->normalize($bom);
    }

    #[Override]
    protected function realSerialize(/* DOMElement */ $normalizedBom, ?bool $prettyPrint): string
    {
        $document = new DOMDocument($this->xmlVersion, $this->xmlEncoding);
        $document->appendChild(
            $document->importNode(
                $normalizedBom,
                true
            )
        );

        if (null !== $prettyPrint) {
            $document->formatOutput = $prettyPrint;
        }

        // option `LIBXML_NOEMPTYTAG` might lead to errors in consumers, do not use it.
        $xml = $document->saveXML();
        \assert(false !== $xml);

        return $xml;
    }
}
