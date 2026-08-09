<?php

/**
 * MetadataHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;


/**
 * Handles metadata operations for ObjectService.
 *
 * This handler is responsible for:
 * - Extracting values from nested paths using dot notation
 * - Generating URL-friendly slugs
 * - Processing metadata fields
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class MetadataHandler
{
    /**
     * Get a value from a nested array using dot notation.
     *
     * @param array  $data The data array to search.
     * @param string $path The dot-notation path (e.g., 'user.profile.name').
     *
     * @psalm-param   array<string, mixed> $data
     * @psalm-param   string $path
     * @phpstan-param array<string, mixed> $data
     * @phpstan-param string $path
     *
     * @return mixed The value at the path, or null if not found.
     *
     * @psalm-return   mixed
     * @phpstan-return mixed
     *
     * @spec openspec/specs/object-lifecycle/spec.md
     */
    public function getValueFromPath(array $data, string $path): mixed
    {
        $keys    = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (is_array($current) === false || array_key_exists($key, $current) === false) {
                return null;
            }

            $current = $current[$key];
        }

        return $current;
    }//end getValueFromPath()

    /*
     * SLUG GENERATION DOES NOT LIVE HERE.
     *
     * `generateSlugFromValue()` + `createSlugHelper()` were removed: they had
     * no callers, and were a byte-identical copy of the same pair on
     * `DataManipulationHandler` (also removed). Object slugs are produced by
     * `SaveObject\MetadataHydrationHandler::generateSlug(array $data, Schema
     * $schema)` — schema-aware, so it reads the schema's configured slug
     * source rather than slugifying an arbitrary string.
     */

}//end class
