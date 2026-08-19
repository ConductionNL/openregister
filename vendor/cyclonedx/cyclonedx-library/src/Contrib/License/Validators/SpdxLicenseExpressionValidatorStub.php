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

namespace CycloneDX\Contrib\License\Validators;

use InvalidArgumentException;

/**
 * Validates an SPDX License Expression.
 *
 * Suggested 3rd‑party implementation:
 * - {@link https://packagist.org/packages/composer/spdx-licenses composer/spdx-licenses}
 *
 * @internal This interface serves only as a type‑hinting protocol.
 *           It is not intended for downstream implementation or strict type enforcement.
 */
interface SpdxLicenseExpressionValidatorStub
{
    /**
     * Validates an SPDX License Expression.
     *
     * The parameter and return type remain intentionally untyped to preserve
     * compatibility with older PHP versions and existing 3rd‑party implementations.
     *
     * @param string $value
     *
     * @throws InvalidArgumentException
     *
     * @return bool true when the expression is valid, false otherwise
     */
    public function validate($value);
}
