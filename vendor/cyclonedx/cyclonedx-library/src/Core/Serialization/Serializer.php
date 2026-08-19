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
use Throwable;

/**
 * @author jkowalleck
 */
interface Serializer
{
    /**
     * Serialize a {@see Bom} to string.
     *
     * @param Bom  $bom         the BOM to serialize
     * @param bool $prettyPrint whether to beatify the resulting string. A `null` value means no preference.
     *
     * @throws Throwable
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function serialize(Bom $bom, ?bool $prettyPrint = null): string;
}
