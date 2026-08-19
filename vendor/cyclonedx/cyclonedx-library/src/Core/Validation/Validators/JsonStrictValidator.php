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

namespace CycloneDX\Core\Validation\Validators;

use CycloneDX\Core\Resources;
use CycloneDX\Core\Spec\Version;
use Override;

/**
 * @author jkowalleck
 */
class JsonStrictValidator extends JsonValidator
{
    /**
     * @internal
     */
    #[Override]
    protected static function listSchemaFiles(): array
    {
        return [
            Version::v1dot1->value => null, // unsupported version
            Version::v1dot2->value => Resources::FILE_CDX_JSON_STRICT_SCHEMA_1_2,
            Version::v1dot3->value => Resources::FILE_CDX_JSON_STRICT_SCHEMA_1_3,
            Version::v1dot4->value => Resources::FILE_CDX_JSON_SCHEMA_1_4, // 1.4 is already strict
            Version::v1dot5->value => Resources::FILE_CDX_JSON_SCHEMA_1_5, // 1.5 is already strict
            Version::v1dot6->value => Resources::FILE_CDX_JSON_SCHEMA_1_6, // 1.6 is already strict
            Version::v1dot7->value => Resources::FILE_CDX_JSON_SCHEMA_1_7, // 1.7 is already strict
        ];
    }
}
