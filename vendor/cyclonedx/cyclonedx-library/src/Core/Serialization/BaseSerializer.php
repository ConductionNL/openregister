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
use CycloneDX\Core\Models\BomRef;
use Exception;
use Override;

/**
 * @template TNormalizedBom
 *
 * @author jkowalleck
 */
abstract class BaseSerializer implements Serializer
{
    /**
     * Get a list of all {@see BomRef} in {@see Bom}.
     * The list might contain duplicates.
     *
     * @return BomRef[]
     *
     * @psalm-return list<BomRef>
     */
    private function getAllBomRefs(Bom $bom): array
    {
        $allBomRefs = [];
        $allComponents = $bom->getComponents()->getItems();
        $metadataComponent = $bom->getMetadata()->getComponent();
        if (null !== $metadataComponent) {
            $allComponents[] = $metadataComponent;
        }
        foreach ($allComponents as $component) {
            $allBomRefs[] = $component->getBomRef();
            array_push($allBomRefs, ...$component->getDependencies()->getItems());
        }

        return $allBomRefs;
    }

    /**
     * Normalize for serialization.
     *
     * Also utilizes {@see BomRefDiscriminator}
     * to guarantee that each BomRef has a unique value.
     *
     * @throws Exception
     *
     * @return TNormalizedBom a version of the Bom that was normalized for serialization
     */
    private function normalize(Bom $bom)
    {
        $bomRefDiscriminator = new BomRefDiscriminator(...$this->getAllBomRefs($bom));
        $bomRefDiscriminator->discriminate();
        // This IS NOT the place to put meaning to the BomRef values. This would be out of scope.
        // This IS the place to make BomRef values (temporary) unique in their own document scope.
        try {
            return $this->realNormalize($bom);
        } finally {
            $bomRefDiscriminator->reset();
        }
    }

    /**
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    #[Override]
    final public function serialize(Bom $bom, ?bool $prettyPrint = null): string
    {
        return $this->realSerialize(
            $this->normalize($bom),
            $prettyPrint
        );
    }

    /**
     * Normalize a {@see Bom} to the data structure that {@see realSerialize()} can handle.
     *
     * @throws Exception
     *
     * @return TNormalizedBom a version of the Bom that was normalized for serialization
     */
    abstract protected function realNormalize(Bom $bom) /* : TNormalizedBom */;
    // no typehint for return type, as it is not actually `mixed` but a templated type.

    /**
     * Serialize a {@see realNormalize() normalized} version of a {@see Bom}.
     *
     * @param TNormalizedBom $normalizedBom a version of the Bom that was normalized for serialization
     *
     * @throws Exception
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    abstract protected function realSerialize(/* TNormalizedBom */ $normalizedBom, ?bool $prettyPrint): string;
    // no typehint for `$normalizedBom` parameter, as it is not actually `mixed` but a templated type.
}
