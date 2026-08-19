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

namespace CycloneDX\Core\Validation;

use Throwable;

/**
 * @author jkowalleck
 */
class ValidationError
{
    /**
     * keep for internal debug purposes.
     */
    protected ?object $error = null;

    final protected function __construct(
        private readonly string $message,
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getError(): ?object
    {
        return $this->error;
    }

    /**
     * @internal as this function may be affected by breaking changes without notice
     */
    public static function fromThrowable(Throwable $error): static
    {
        $instance = new static($error->getMessage());
        $instance->error = $error;

        return $instance;
    }
}
