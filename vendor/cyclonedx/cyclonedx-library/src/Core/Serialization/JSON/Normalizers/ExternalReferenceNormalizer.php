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
use CycloneDX\Core\Collections\HashDictionary;
use CycloneDX\Core\Enums\ExternalReferenceType;
use CycloneDX\Core\Models\ExternalReference;
use CycloneDX\Core\Serialization\JSON\_BaseNormalizer;
use DomainException;
use UnexpectedValueException;

/**
 * @author jkowalleck
 */
class ExternalReferenceNormalizer extends _BaseNormalizer
{
    /**
     * @throws UnexpectedValueException when the url is invalid to IriReference format
     * @throws DomainException          when the type was not supported by the spec
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function normalize(ExternalReference $externalReference): array
    {
        $refURI = $externalReference->getUrl();
        $refIRI = JsonHelper::encodeIriReferenceBE($refURI)
            ?? throw new UnexpectedValueException("invalid to format 'IriReference': $refURI");

        $spec = $this->getNormalizerFactory()->getSpec();
        $type = $externalReference->getType();
        if (false === $spec->isSupportedExternalReferenceType($type)) {
            // prevent information-loss -> try transfer to OTHER
            $type = ExternalReferenceType::Other;
            if (false === $spec->isSupportedExternalReferenceType($type)) {
                throw new DomainException('ExternalReference has unsupported type: '.$externalReference->getType()->name);
            }
        }

        return array_filter(
            [
                'type' => $type->value,
                'url' => $refIRI,
                'comment' => $externalReference->getComment(),
                'hashes' => $this->normalizeHashes($externalReference->getHashes()),
            ],
            Predicate::isNotNull(...)
        );
    }

    private function normalizeHashes(HashDictionary $hashes): ?array
    {
        $factory = $this->getNormalizerFactory();

        if (false === $factory->getSpec()->supportsExternalReferenceHashes()) {
            return null;
        }

        return 0 === \count($hashes)
            ? null
            : $factory->makeForHashDictionary()->normalize($hashes);
    }
}
