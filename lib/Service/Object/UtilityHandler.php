<?php

/**
 * UtilityHandler
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

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * Handles utility operations for ObjectService.
 *
 * This handler provides common utility functions:
 * - UUID validation
 * - Entity normalization (ID/string to object)
 * - Array normalization
 * - URL separator detection
 * - Efficiency calculations
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class UtilityHandler
{
    /**
     * Constructor for UtilityHandler.
     *
     * @param RegisterMapper $registerMapper Mapper for register entities.
     * @param SchemaMapper   $schemaMapper   Mapper for schema entities.
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper
    ) {

    }//end __construct()

    /**
     * Check if a value is a valid UUID string.
     *
     * Validates UUID format using regex pattern.
     *
     * @param mixed $value The value to check.
     *
     * @return bool True if the value is a valid UUID string.
     *
     * @psalm-return   bool
     * @phpstan-return bool
     */
    public function isUuid($value): bool
    {
        if (is_string($value) === false) {
            return false;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;

    }//end isUuid()

    /**
     * Normalize a value to an array.
     *
     * If the value is already an array, return it as-is.
     * Otherwise, wrap it in an array.
     *
     * @param mixed $value The value to normalize.
     *
     * @return array The normalized array.
     *
     * @psalm-return   array
     * @phpstan-return array
     */
    public function normalizeToArray($value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        return [$value];

    }//end normalizeToArray()

    /**
     * Get the appropriate URL separator for pagination.
     *
     * Returns '?' if the URL doesn't have a query string yet,
     * otherwise returns '&' to append parameters.
     *
     * @param string $url The URL to check.
     *
     * @return string The separator ('?' or '&').
     *
     * @psalm-return   '&'|'?'
     * @phpstan-return '&'|'?'
     */
    public function getUrlSeparator(string $url): string
    {
        if (strpos($url, '?') === false) {
            return '?';
        }

        return '&';

    }//end getUrlSeparator()

    /**
     * Normalize entity identifier to entity object.
     *
     * Converts string/int identifiers to actual entity objects
     * by loading them from the appropriate mapper.
     *
     * @param mixed  $entity The entity identifier (string/int) or object.
     * @param string $type   The entity type ('register' or 'schema').
     *
     * @return mixed The entity object.
     *
     * @psalm-return   mixed
     * @phpstan-return mixed
     */
    public function normalizeEntity($entity, string $type)
    {
        if (is_string($entity) === true || is_int($entity) === true) {
            if ($type === 'register') {
                return $this->registerMapper->find($entity);
            }

            return $this->schemaMapper->find($entity);
        }

        return $entity;

    }//end normalizeEntity()

    /**
     * Calculate efficiency metric for bulk operations.
     *
     * Computes average time per object for performance tracking.
     *
     * @param array $lookupMap The lookup map of loaded objects.
     * @param float $totalTime Total execution time in milliseconds.
     *
     * @psalm-param array<string, mixed> $lookupMap
     *
     * @phpstan-param array<string, mixed> $lookupMap
     *
     * @psalm-return   string
     * @phpstan-return string
     */
    public function calculateEfficiency(array $lookupMap, float $totalTime): string
    {
        $count = count($lookupMap);
        if ($count > 0) {
            return round($totalTime / $count, 2).'ms/object';
        }

        return 'no_objects';

    }//end calculateEfficiency()

    /**
     * Clean query parameters by removing internal/system parameters.
     *
     * Removes parameters starting with underscore and specific
     * system parameters that shouldn't be exposed.
     *
     * @param array $parameters The query parameters to clean.
     *
     * @return array The cleaned parameters.
     *
     * @psalm-param    array<string, mixed> $parameters
     * @phpstan-param  array<string, mixed> $parameters
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function cleanQuery(array $parameters): array
    {
        $newParameters = [];

        // List of parameters to exclude (internal/system parameters).
        $excludeParams = [
            'extend',
            'unset',
            'fields',
            'filter',
            'page',
            'limit',
            'offset',
            'order',
            'search',
            'ids',
            'uses',
            'views',
            'source',
            'facets',
            'facetable',
            'sample_size',
            'published',
            'count',
            'performance',
            'aggregations',
        ];

        foreach ($parameters as $key => $value) {
            // Skip parameters starting with underscore.
            if (str_starts_with($key, '_') === true) {
                continue;
            }

            // Skip excluded parameters.
            if (in_array($key, $excludeParams) === true) {
                continue;
            }

            // Skip @self parameters.
            if (str_starts_with($key, '@self') === true) {
                continue;
            }

            // Keep parameter if it passes all filters.
            $newParameters[$key] = $value;
        }//end foreach

        return $newParameters;

    }//end cleanQuery()
}//end class
