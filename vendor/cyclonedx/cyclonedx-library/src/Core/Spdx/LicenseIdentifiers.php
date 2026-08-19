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

namespace CycloneDX\Core\Spdx;

use CycloneDX\Core\Resources;
use JsonException;
use RuntimeException;

/**
 * Work with SPDX licences known to CycloneDX.
 *
 * @author jkowalleck
 */
class LicenseIdentifiers
{
    /**
     * @var string[]|null
     *
     * @psalm-var array<string, string>|null
     */
    private ?array $values = null;

    /**
     * @psalm-suppress MissingThrowsDocblock -- as all options to throw were prevented by tests.
     */
    public function __construct()
    {
        $this->loadLicenses();
    }

    public function getResourcesFile(): string
    {
        return Resources::FILE_SPDX_JSON_SCHEMA;
    }

    /**
     * @return string[]
     *
     * @psalm-return list<string>
     */
    public function getKnownLicenses(): array
    {
        return array_values($this->values ?? []);
    }

    public function isKnownLicense(string $value): bool
    {
        return \in_array($value, $this->values ?? [], true);
    }

    /**
     * Return the "fixed" supported SPDX license ID, or null if unsupported.
     */
    public function fixLicense(string $value): ?string
    {
        return $this->values[strtolower($value)] ?? null;
    }

    /**
     * @psalm-assert array<string, string> $this->licenses
     *
     * @throws RuntimeException when licenses could not be loaded
     */
    private function loadLicenses(): void
    {
        if (null !== $this->values) {
            // @codeCoverageIgnoreStart
            return;
            // @codeCoverageIgnoreEnd
        }

        $file = $this->getResourcesFile();
        $json = file_exists($file)
            ? file_get_contents($file)
            : throw new RuntimeException("Missing licenses file: $file");
        if (false === $json) {
            throw new RuntimeException("Failed to get content from licenses file: $file");
        }

        try {
            /**
             * list of non-empty-string, as asserted by an integration test:
             * {@see \CycloneDX\Tests\Core\Spdx\LicenseIdentifierTest::testShippedLicensesFile()}.
             *
             * @psalm-var non-empty-list<non-empty-string> $values
             *
             * @psalm-suppress MixedArrayAccess
             * @psalm-suppress MixedAssignment
             */
            ['enum' => $values] = json_decode($json, true, 3, \JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Malformed licenses file: $file", previous: $exception);
        }

        $this->values = array_combine(
            array_map(strtolower(...), $values),
            $values
        );
    }
}
