<?php

/**
 * MetadataHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use Exception;

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
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
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
     */
    public function getValueFromPath(array $data, string $path): mixed
    {
        $keys    = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (is_array($current) === true && array_key_exists($key, $current) === true) {
                $current = $current[$key];
            } else {
                return null;
            }
        }

        return $current;

    }//end getValueFromPath()

    /**
     * Generate a slug from a given value.
     *
     * METADATA ENHANCEMENT: Simplified slug generation for ObjectService metadata hydration.
     *
     * @param string $value The value to convert to a slug.
     *
     * @return null|string
     *
     * @psalm-param string $value
     *
     * @phpstan-param string $value
     *
     * @psalm-return   string|null
     * @phpstan-return string|null
     */
    public function generateSlugFromValue(string $value): string|null
    {
        try {
            if (empty($value) === true) {
                return null;
            }

            // Generate the base slug.
            $slug = $this->createSlugHelper($value);

            // Add timestamp for uniqueness.
            $timestamp  = time();
            $uniqueSlug = $slug.'-'.$timestamp;

            return $uniqueSlug;
        } catch (Exception $e) {
            return null;
        }

    }//end generateSlugFromValue()

    /**
     * Creates a URL-friendly slug from a string.
     *
     * @param string $text The text to convert to a slug.
     *
     * @psalm-param string $text
     *
     * @phpstan-param string $text
     *
     * @psalm-return   string
     * @phpstan-return string
     */
    public function createSlugHelper(string $text): string
    {
        // Convert to lowercase.
        $text = strtolower($text);

        // Replace non-alphanumeric characters with hyphens.
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);

        // Remove leading and trailing hyphens.
        $text = trim($text, '-');

        // Ensure the slug is not empty.
        if (empty($text) === true) {
            $text = 'object';
        }

        return $text;

    }//end createSlugHelper()
}//end class
