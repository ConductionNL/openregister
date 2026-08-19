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
use Override;

/**
 * Transform data models to JSON.
 *
 * @psalm-type TNormalizedBom = array<string, mixed>
 *
 * @template-extends BaseSerializer<TNormalizedBom>
 *
 * @SuppressWarnings(PHPMD.ConstantNamingConventions)
 *
 * @author jkowalleck
 */
class JsonSerializer extends BaseSerializer
{
    /**
     * List of allowed options for {@see jsonEncodeFlags}.
     *
     * Bit mask consisting of JSON_*.
     *
     * Some JSON flags could break the output, so they are not whitelisted.
     *
     * @see https://www.php.net/manual/en/json.constants.php
     *
     * @var int
     */
    private const JsonEncodeFlagsAllowedOptions = 0
        | \JSON_HEX_TAG
        | \JSON_HEX_AMP
        | \JSON_HEX_APOS
        | \JSON_HEX_QUOT
        | \JSON_PRETTY_PRINT
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE
    ;

    /**
     * Defaults of {@see $jsonEncodeFlags}.
     *
     * Bit mask consisting of JSON_*.
     *
     * These defaults are required to have valid output in the end.
     *
     * @see https://www.php.net/manual/en/json.constants.php
     *
     * @var int
     */
    private const JsonEncodeFlagsDefaults = 0
        | \JSON_THROW_ON_ERROR // prevent unexpected data
        | \JSON_PRESERVE_ZERO_FRACTION // float/double not converted to int
    ;

    /**
     * List of mandatory options for $jsonEncodeFlags.
     *
     * Bit mask consisting of JSON_*.
     *
     * @see https://www.php.net/manual/en/json.constants.php
     *
     * @var int
     */
    private const JsonEncodeFlagsDefaultOptions = 0
        | \JSON_UNESCAPED_SLASHES // urls become shorter
    ;

    /**
     * Flags for {@see \json_encode()}.
     *
     * Bit mask consisting of JSON_*.
     *
     * @see https://www.php.net/manual/en/json.constants.php
     */
    protected readonly int $jsonEncodeFlags;

    /**
     * @param int $jsonEncodeFlags Bitmask consisting of JSON_*. see {@see JsonEncodeFlagsAllowedOptions}
     */
    public function __construct(
        protected readonly JSON\NormalizerFactory $normalizerFactory,
        int $jsonEncodeFlags = self::JsonEncodeFlagsDefaultOptions,
    ) {
        $this->jsonEncodeFlags = self::JsonEncodeFlagsDefaults
            | ($jsonEncodeFlags & self::JsonEncodeFlagsAllowedOptions);
    }

    #[Override]
    protected function realNormalize(Bom $bom): array
    {
        return $this->normalizerFactory
            ->makeForBom()
            ->normalize($bom);
    }

    #[Override]
    protected function realSerialize(/* array */ $normalizedBom, ?bool $prettyPrint): string
    {
        $jsonEncodeFlags = match ($prettyPrint) {
            // reminder: JSON_PRETTY_PRINT could be in $this->jsonEncodeFlags already
            null => $this->jsonEncodeFlags,
            true => $this->jsonEncodeFlags | \JSON_PRETTY_PRINT,
            false => $this->jsonEncodeFlags & ~\JSON_PRETTY_PRINT,
        };

        $json = json_encode($normalizedBom, $jsonEncodeFlags);
        \assert(false !== $json); // as option JSON_THROW_ON_ERROR is expected to be set

        return $json;
    }
}
