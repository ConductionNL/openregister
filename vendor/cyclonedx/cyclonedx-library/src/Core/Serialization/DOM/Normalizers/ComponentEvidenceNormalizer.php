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
use CycloneDX\Core\Models\ComponentEvidence;
use CycloneDX\Core\Serialization\DOM\_BaseNormalizer;
use DOMElement;

/**
 * @author jkowalleck
 */
class ComponentEvidenceNormalizer extends _BaseNormalizer
{
    public function normalize(ComponentEvidence $evidence): DOMElement
    {
        $factory = $this->getNormalizerFactory();
        $document = $factory->getDocument();

        $licenses = $evidence->getLicenses();
        $copyright = $evidence->getCopyright();

        return SimpleDOM::appendChildren(
            $document->createElement('evidence'),
            [
                0 === \count($licenses)
                    ? null
                    : SimpleDOM::appendChildren(
                        $document->createElement('licenses'),
                        $this->getNormalizerFactory()->makeForLicenseRepository()->normalize($licenses)
                    ),
                0 === \count($copyright)
                    ? null
                    : SimpleDOM::appendChildren(
                        $document->createElement('copyright'),
                        array_map(
                            static fn (string $item) => SimpleDOM::makeSafeTextElement($document, 'text', $item),
                            $copyright->getItems()
                        )
                    ),
            ]
        );
    }
}
