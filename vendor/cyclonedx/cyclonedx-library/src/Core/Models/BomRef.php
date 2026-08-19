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

namespace CycloneDX\Core\Models;

/**
 * Identifier-DataType for interlinked elements.
 *
 * Class is currently final, to enforce proper usage.
 *
 * Implementation is intended to prevent memory leaks.
 * See {@link file://../../../docs/dev/decisions/BomDependencyDataModel.md BomDependencyDataModel docs}
 *
 * @author jkowalleck
 */
final class BomRef
{
    /** @psalm-var non-empty-string|null  */
    private ?string $value;

    public function __construct(?string $value = null)
    {
        $this->setValue($value);
    }

    /** @psalm-return non-empty-string|null */
    public function getValue(): ?string
    {
        return $this->value;
    }

    /**
     * @return $this
     */
    public function setValue(?string $value): static
    {
        $this->value = '' === $value
            ? null
            : $value;

        return $this;
    }
}
