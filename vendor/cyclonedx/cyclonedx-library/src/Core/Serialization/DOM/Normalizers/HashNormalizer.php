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
use CycloneDX\Core\Enums\HashAlgorithm;
use CycloneDX\Core\Serialization\DOM\_BaseNormalizer;
use DomainException;
use DOMElement;

/**
 * @author jkowalleck
 */
class HashNormalizer extends _BaseNormalizer
{
    /**
     * @throws DomainException
     */
    public function normalize(HashAlgorithm $algorithm, string $content): DOMElement
    {
        $spec = $this->getNormalizerFactory()->getSpec();
        if (false === $spec->isSupportedHashAlgorithm($algorithm)) {
            throw new DomainException("Invalid hash algorithm: $algorithm->name", 1);
        }
        if (false === $spec->isSupportedHashContent($content)) {
            throw new DomainException("Invalid hash content: $content", 2);
        }

        $element = SimpleDOM::makeSafeTextElement(
            $this->getNormalizerFactory()->getDocument(),
            'hash',
            $content
        );
        \assert(null !== $element);
        SimpleDOM::setAttributes($element, ['alg' => $algorithm->value]);

        return $element;
    }
}
