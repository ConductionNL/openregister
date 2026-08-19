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

namespace CycloneDX\Contrib\Bom\Utils;

use Exception;

/**
 * Utilities regarding:
 * - {@see \CycloneDX\Core\Models\Bom}.
 *
 * @author jkowalleck
 */
abstract class BomUtils
{
    /**
     * Generate valid random SerialNumbers for {@see \CycloneDX\Core\Models\Bom::setSerialNumber()}.
     *
     * @throws Exception if an appropriate source of randomness cannot be found
     *
     * @author jkowalleck
     */
    public static function randomSerialNumber(): string
    {
        return \sprintf(
            'urn:uuid:%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            // UUID version 4
            random_int(0, 0x0FFF) | 0x4000,
            // UUID version 4 variant 1
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
        );
    }
}
