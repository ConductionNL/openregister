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

namespace CycloneDX\Core;

/**
 * @internal as this class may be affected by breaking changes without notice
 *
 * @author jkowalleck
 */
final class Resources
{
    // ANY path is a concat of `__DIR__` and a relative path,
    // so IDEs are able to refactor paths easily.

    public const DIR_ROOT = __DIR__.'/../../res/';

    public const DIR_SCHEMA = __DIR__.'/../../res/schema/';

    public const FILE_CDX_XML_SCHEMA_1_0 = __DIR__.'/../../res/schema/bom-1.0.SNAPSHOT.xsd';
    public const FILE_CDX_XML_SCHEMA_1_1 = __DIR__.'/../../res/schema/bom-1.1.SNAPSHOT.xsd';
    public const FILE_CDX_XML_SCHEMA_1_2 = __DIR__.'/../../res/schema/bom-1.2.SNAPSHOT.xsd';
    public const FILE_CDX_XML_SCHEMA_1_3 = __DIR__.'/../../res/schema/bom-1.3.SNAPSHOT.xsd';
    public const FILE_CDX_XML_SCHEMA_1_4 = __DIR__.'/../../res/schema/bom-1.4.SNAPSHOT.xsd';
    public const FILE_CDX_XML_SCHEMA_1_5 = __DIR__.'/../../res/schema/bom-1.5.SNAPSHOT.xsd';
    public const FILE_CDX_XML_SCHEMA_1_6 = __DIR__.'/../../res/schema/bom-1.6.SNAPSHOT.xsd';
    public const FILE_CDX_XML_SCHEMA_1_7 = __DIR__.'/../../res/schema/bom-1.7.SNAPSHOT.xsd';

    // v1.0 is not defined in JSON
    // v1.1 is not defined in JSON
    public const FILE_CDX_JSON_SCHEMA_1_2 = __DIR__.'/../../res/schema/bom-1.2.SNAPSHOT.schema.json';
    public const FILE_CDX_JSON_SCHEMA_1_3 = __DIR__.'/../../res/schema/bom-1.3.SNAPSHOT.schema.json';
    public const FILE_CDX_JSON_SCHEMA_1_4 = __DIR__.'/../../res/schema/bom-1.4.SNAPSHOT.schema.json';
    public const FILE_CDX_JSON_SCHEMA_1_5 = __DIR__.'/../../res/schema/bom-1.5.SNAPSHOT.schema.json';
    public const FILE_CDX_JSON_SCHEMA_1_6 = __DIR__.'/../../res/schema/bom-1.6.SNAPSHOT.schema.json';
    public const FILE_CDX_JSON_SCHEMA_1_7 = __DIR__.'/../../res/schema/bom-1.7.SNAPSHOT.schema.json';

    // v1.1 and before is not defined in JSON
    public const FILE_CDX_JSON_STRICT_SCHEMA_1_2 = __DIR__.'/../../res/schema/bom-1.2-strict.SNAPSHOT.schema.json';
    public const FILE_CDX_JSON_STRICT_SCHEMA_1_3 = __DIR__.'/../../res/schema/bom-1.3-strict.SNAPSHOT.schema.json';
    // v1.4 and later is already strict - no special file here

    public const FILE_SPDX_XML_SCHEMA = __DIR__.'/../../res/schema/spdx.SNAPSHOT.xsd';
    public const FILE_SPDX_JSON_SCHEMA = __DIR__.'/../../res/schema/spdx.SNAPSHOT.schema.json';

    public const FILE_JSF_JSON_SCHEMA = __DIR__.'/../../res/schema/jsf-0.82.SNAPSHOT.schema.json';

    public const FILE_CRYPTOGRAPHYDEFS_JSON_SCHEMA = __DIR__.'/../../res/schema/cryptography-defs.SNAPSHOT.schema.json';
}
