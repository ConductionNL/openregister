<?php

declare(strict_types=1);

/*
 * DataManipulationHandler - Data Transformation and Manipulation Handler
 *
 * Handles data transformation, property mapping, slug generation, and path-based access.
 * This handler consolidates utility functions for manipulating object data,
 * making these operations more testable and maintainable.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Object;

use Exception;

/**
 * DataManipulationHandler class
 *
 * Handles data manipulation operations including:
 * - Nested value extraction via path notation
 * - Slug generation for URLs and identifiers
 * - Property mapping between data structures
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 */
class DataManipulationHandler
{


    /**
     * Get a value from nested array using dot notation path
     *
     * Traverses a nested array structure using a dot-separated path string.
     * Returns null if the path doesn't exist at any level.
     *
     * Example:
     * ```php
     * $data = ['user' => ['profile' => ['name' => 'John']]];
     * getValueFromPath($data, 'user.profile.name'); // Returns 'John'
     * ```
     *
     * @param array<string, mixed> $data The data array to search.
     * @param string               $path The dot-separated path (e.g., 'user.profile.name').
     *
     * @return mixed The value at the path, or null if path doesn't exist
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
     * Generate a unique slug from a given value
     *
     * Creates a URL-friendly slug with a timestamp suffix for uniqueness.
     * Used for generating identifiers for objects based on their names or titles.
     *
     * @param string $value The value to convert to a slug.
     *
     * @return string|null The generated slug or null if generation failed
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
     * Create a URL-friendly slug from a string
     *
     * Converts text to lowercase, replaces non-alphanumeric characters with hyphens,
     * and removes leading/trailing hyphens. Ensures the slug is never empty.
     *
     * @param string $text The text to convert to a slug.
     *
     * @return string The generated slug
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


    /**
     * Map properties from source data to target structure
     *
     * Performs simple key-based property mapping. Only maps properties that exist
     * in the source data - missing properties are not included in the result.
     *
     * Example:
     * ```php
     * $source = ['firstName' => 'John', 'lastName' => 'Doe'];
     * $mapping = ['name' => 'firstName', 'surname' => 'lastName'];
     * mapObjectProperties($source, $mapping);
     * // Returns: ['name' => 'John', 'surname' => 'Doe']
     * ```
     *
     * @param array<string, mixed>  $sourceData The source data array.
     * @param array<string, string> $mapping    Mapping array (target => source property names).
     *
     * @return array<string, mixed> The mapped data
     */
    public function mapObjectProperties(array $sourceData, array $mapping): array
    {
        $mappedData = [];

        // Simple mapping: keys are target properties, values are source properties.
        foreach ($mapping as $targetProperty => $sourceProperty) {
            // Only map if the source property exists in the source data.
            if (array_key_exists($sourceProperty, $sourceData) === true) {
                $mappedData[$targetProperty] = $sourceData[$sourceProperty];
            }
        }

        return $mappedData;

    }//end mapObjectProperties()


}//end class
