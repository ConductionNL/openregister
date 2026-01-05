<?php

/**
 * OpenRegister Schema Service
 *
 * This file contains the service class for handling schema exploration and analysis
 * operations in the OpenRegister application.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use DateInterval;
use stdClass;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Class SchemaService
 *
 * Service class for schema exploration and analysis operations.
 * Provides functionality to analyze objects belonging to schemas and discover
 * properties that may not be defined in the schema definition.
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Schema analysis requires comprehensive exploration methods
 * @SuppressWarnings(PHPMD.TooManyMethods)           Many methods required for schema analysis and property discovery
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex schema analysis and property inference logic
 */
class SchemaService
{

    /**
     * Schema mapper for schema operations
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Object entity mapper for object queries
     *
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $objectEntityMapper;

    /**
     * Logger for debugging and monitoring
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * SchemaService constructor
     *
     * @param SchemaMapper       $schemaMapper       Schema mapper for schema operations.
     * @param ObjectEntityMapper $objectEntityMapper Object entity mapper for object queries.
     * @param LoggerInterface    $logger             Logger for debugging and monitoring.
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        ObjectEntityMapper $objectEntityMapper,
        LoggerInterface $logger
    ) {
        $this->schemaMapper       = $schemaMapper;
        $this->objectEntityMapper = $objectEntityMapper;
        $this->logger = $logger;
    }//end __construct()

    /**
     * Explore objects and discover new properties for a schema
     *
     * This method analyzes all objects belonging to a specific schema and identifies
     * properties that exist in the object data but are not defined in the schema.
     *
     * PROCESS:
     * 1. Retrieves all objects for the specified schema
     * 2. Analyzes the 'object' JSON field of each object
     * 3. Creates a summary of property usage and types
     * 4. Compares discovered properties against existing schema properties
     * 5. Returns suggestions for schema updates
     *
     * @param int $schemaId The ID of the schema to explore
     *
     * @return array Exploration results with discovered properties
     *
     * @throws \Exception If schema not found or analysis fails
     */
    public function exploreSchemaProperties(int $schemaId): array
    {
        $this->logger->info(message: 'Starting schema exploration for schema ID: '.$schemaId);

        // Get the schema to validate it exists.
        try {
            $schema = $this->schemaMapper->find($schemaId);
        } catch (\Exception $e) {
            throw new Exception('Schema not found with ID: '.$schemaId);
        }

        // Get all objects for this schema.
        $objects = $this->objectEntityMapper->findBySchema($schemaId);

        $this->logger->info(message: 'Found '.count($objects).' objects to analyze');

        if (empty($objects) === true) {
            return [
                'schema_id'             => $schemaId,
                'schema_title'          => $schema->getTitle(),
                'total_objects'         => 0,
                'discovered_properties' => [],
                'existing_properties'   => $schema->getProperties(),
                'suggestions'           => [],
                'analysis_date'         => (new DateTime('now'))->format(format: 'c'),
                'message'               => 'No objects found for analysis',
            ];
        }

        // Analyze all object data.
        $propertyAnalysis = $this->analyzeObjectProperties(objects: $objects, _existingProperties: $schema->getProperties());

        // Generate suggestions for both new and existing properties.
        $newPropSuggestions   = $this->generateSuggestions(
            discoveredProperties: $propertyAnalysis['discovered'],
            existingProperties: $schema->getProperties()
        );
        $existPropSuggestions = $this->analyzeExistingProperties(
            existingProperties: $schema->getProperties(),
            discoveredProperties: $propertyAnalysis['discovered'],
            _usageStats: $propertyAnalysis['usage_stats']
        );

        return [
            'schema_id'             => $schemaId,
            'schema_title'          => $schema->getTitle(),
            'total_objects'         => count($objects),
            'discovered_properties' => $propertyAnalysis['discovered'],
            'existing_properties'   => $schema->getProperties(),
            'property_usage_stats'  => $propertyAnalysis['usage_stats'],
            'suggestions'           => array_merge($newPropSuggestions, $existPropSuggestions),
            'analysis_date'         => (new DateTime())->format('c'),
            'data_types'            => $propertyAnalysis['data_types'],
            'analysis_summary'      => [
                'new_properties_count'             => count($newPropSuggestions),
                'existing_properties_improvements' => count($existPropSuggestions),
                'total_recommendations'            => count($newPropSuggestions) + count($existPropSuggestions),
            ],
        ];
    }//end exploreSchemaProperties()

    /**
     * Analyze object properties from a collection of objects
     *
     * Iterates through all objects and analyzes their JSON data to discover
     * properties, data types, and usage patterns.
     *
     * @param array $objects             Array of ObjectEntity objects
     * @param array $_existingProperties Current schema properties for comparison
     *
     * @return (array|float|int|mixed|null|true)[][][]
     *
     * @psalm-return array{discovered: array<array{name: mixed,
     *     types: array<never, never>, examples: array<never, never>,
     *     nullable: true, enum_values: array<never, never>, max_length: 0,
     *     min_length: int<1, max>, object_structure: null,
     *     array_structure: null, detected_format: null,
     *     string_patterns: array<never, never>, numeric_range: null,
     *     usage_count: int, usage_percentage?: float}>,
     *     usage_stats: array{counts?: array<int>, percentages?: array<float>},
     *     data_types: array<never, never>}
     */
    private function analyzeObjectProperties(array $objects, array $_existingProperties=[]): array
    {
        $discoveredProperties = [];
        $usageStats           = [];
        $dataTypes            = [];

        foreach ($objects as $object) {
            $objectData = $object->getObject();

            // Skip the '@self' metadata field in analysis.
            unset($objectData['@self']);

            foreach ($objectData as $propertyName => $propertyValue) {
                // Track usage count.
                if (isset($usageStats['counts'][$propertyName]) === false) {
                    $usageStats['counts'][$propertyName] = 0;
                }

                $usageStats['counts'][$propertyName]++;

                // Skip if null or empty.
                if ($propertyValue === null || $propertyValue === '') {
                    continue;
                }

                // Analyze data type and characteristics.
                $propertyAnalysis = $this->analyzePropertyValue($propertyValue);

                if (isset($discoveredProperties[$propertyName]) === false) {
                    $discoveredProperties[$propertyName] = [
                        'name'             => $propertyName,
                        'types'            => [],
                        'examples'         => [],
                        'nullable'         => true,
                        'enum_values'      => [],
                        'max_length'       => 0,
                        'min_length'       => PHP_INT_MAX,
                        'object_structure' => null,
                        'array_structure'  => null,
                        'detected_format'  => null,
                        'string_patterns'  => [],
                        'numeric_range'    => null,
                        'usage_count'      => 0,
                    ];
                }

                // Merge type analysis.
                $existingAnalysis = &$discoveredProperties[$propertyName];
                $this->mergePropertyAnalysis(existingAnalysis: $existingAnalysis, newAnalysis: $propertyAnalysis);

                // Track total usage for percentage calculation.
                $discoveredProperties[$propertyName]['usage_count']++;
            }//end foreach
        }//end foreach

        // Calculate usage percentages.
        $totalObjects = count($objects);
        foreach ($usageStats['counts'] as $propertyName => $count) {
            $usageStats['percentages'][$propertyName] = round(($count / $totalObjects) * 100, 2);

            if (($discoveredProperties[$propertyName] ?? null) !== null) {
                $discoveredProperties[$propertyName]['usage_percentage'] = $usageStats['percentages'][$propertyName];
            }
        }

        return [
            'discovered'  => $discoveredProperties,
            'usage_stats' => $usageStats,
            'data_types'  => $dataTypes,
        ];
    }//end analyzeObjectProperties()

    /**
     * Analyze a single property value and extract comprehensive type information
     *
     * @param mixed $value The property value to analyze
     *
     * @return array Analysis results with type and structure info
     */
    private function analyzePropertyValue($value): array
    {
        $analysis = [
            'types'            => [],
            'examples'         => [$value],
            'max_length'       => 0,
            'min_length'       => PHP_INT_MAX,
            'object_structure' => null,
            'array_structure'  => null,
            'detected_format'  => null,
            'numeric_range'    => null,
            'string_patterns'  => [],
        ];

        // Determine data type.
        $type = gettype($value);
        $analysis['types'][] = $type;

        // Type-specific analysis.
        switch ($type) {
            case 'string':
                $length = strlen($value);
                $analysis['max_length'] = $length;
                $analysis['min_length'] = $length;

                // Detect format based on string patterns.
                $analysis['detected_format']   = $this->detectStringFormat($value);
                $analysis['string_patterns'][] = $this->analyzeStringPattern($value);
                break;

            case 'integer':
                $analysis['numeric_range'] = ['min' => $value, 'max' => $value, 'type' => 'integer'];
                break;

            case 'double':
                $analysis['numeric_range'] = ['min' => $value, 'max' => $value, 'type' => 'number'];
                break;

            case 'array':
                if (empty($value) === true) {
                    break;
                }

                // Check if this is an associative array (object-like).
                if (array_is_list($value) === true) {
                    // Analyze array structure for list arrays.
                    $analysis['array_structure'] = $this->analyzezArrayStructure($value);
                    break;
                }

                // Treat associative arrays as objects.
                $analysis['object_structure'] = $this->analyzeObjectStructure($value);
                break;

            case 'object':
                // Analyze object structure.
                $analysis['object_structure'] = $this->analyzeObjectStructure($value);
                break;
        }//end switch

        return $analysis;
    }//end analyzePropertyValue()

    /**
     * Detect format based on string patterns (date, email, url, uuid, etc.)
     *
     * @param string $value The string value to analyze
     *
     * @return null|string Detected format or null if none
     *
     * @SuppressWarnings(PHPMD.StaticAccess)         DateTime::createFromFormat is standard PHP date pattern
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Many format patterns require individual checks
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple format detection paths are necessary
     */
    private function detectStringFormat(string $value): string|null
    {
        // Date formats.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $parsed = \DateTime::createFromFormat('Y-m-d', $value);
            if ($parsed !== false && $parsed->format('Y-m-d') === $value) {
                return 'date';
            }
        }

        // Date-Time formats.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) === 1) {
            $parsed = DateTime::createFromFormat(DATE_ISO8601, $value);
            if ($parsed !== false) {
                return 'date-time';
            }
        }

        // RFC3339 format.
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
            return 'date-time';
        }

        // UUID format.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1) {
            return 'uuid';
        }

        // Email format.
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return 'email';
        }

        // URL format.
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return 'url';
        }

        // Time format (HH:MM:SS).
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return 'time';
        }

        // Duration format (ISO 8601 duration like PT1H30M).
        if (preg_match('/^P(\d+Y)?(\d+M)?(\d+D)?(T(\d+H)?(\d+M)?(\d+S)?)?$/', $value) === 1) {
            return 'duration';
        }

        // Color format (hex, rgb, etc.).
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
            return 'color';
        }

        // Hostname format.
        if (preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $value) === 1) {
            return 'hostname';
        }

        // IPv4 format.
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return 'ipv4';
        }

        // IPv6 format.
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return 'ipv6';
        }

        return null;
    }//end detectStringFormat()

    /**
     * Analyze string patterns for additional type hints
     *
     * @param string $value The string value to analyze
     *
     * @return string[]
     *
     * @psalm-return list{0?: string, 1?: string, 2?: string, 3?: string,
     *     4?: string, 5?: 'SCREAMING_SNAKE_CASE'|'filename'|'path',
     *     6?: 'filename'|'path', 7?: 'path'}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple pattern checks are required for analysis
     * @SuppressWarnings(PHPMD.NPathComplexity)      Pattern detection requires many conditional paths
     */
    private function analyzeStringPattern(string $value): array
    {
        $patterns = [];

        // Check for numeric strings (could be integers).
        if (is_numeric($value) === true) {
            if (ctype_digit($value) === false) {
                $patterns[] = 'float_string';
            }

            if (ctype_digit($value) === true) {
                $patterns[] = 'integer_string';
            }
        }

        // Check for boolean-like strings.
        if (in_array(strtolower($value), ['true', 'false', 'yes', 'no', 'on', 'off', '1', '0']) === true) {
            $patterns[] = 'boolean_string';
        }

        // Check for enum-like patterns (camelCase, PascalCase, etc.).
        if (preg_match('/^[a-z]+[A-Z][a-zA-Z]*$/', $value) === 1) {
            $patterns[] = 'camelCase';
        }

        if (preg_match('/^[A-Z][a-z]*([A-Z][a-z]*)*$/', $value) === 1) {
            $patterns[] = 'PascalCase';
        }

        if (preg_match('/^[a-z]+(_[a-z]+)*$/', $value) === 1) {
            $patterns[] = 'snake_case';
        }

        if (preg_match('/^[A-Z]+(_[A-Z]+)*$/', $value) === 1) {
            $patterns[] = 'SCREAMING_SNAKE_CASE';
        }

        // Check for filename patterns.
        if (preg_match('/^[^<>:"/\\|?*]+\.[a-zA-Z0-9]+$/', $value) === 1) {
            $patterns[] = 'filename';
        }

        // Check for directory patterns.
        if (str_contains($value, '/') === true || str_contains($value, '\\') === true) {
            $patterns[] = 'path';
        }

        return $patterns;
    }//end analyzeStringPattern()

    /**
     * Merge property analysis data from multiple objects
     *
     * @param array $existingAnalysis Existing analysis data
     * @param array $newAnalysis      New analysis data to merge
     *
     * @return void Updates the existing analysis in place
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex merging logic for multiple analysis aspects
     * @SuppressWarnings(PHPMD.NPathComplexity)      Many merge scenarios require individual handling
     */
    private function mergePropertyAnalysis(array &$existingAnalysis, array $newAnalysis): void
    {
        // Merge types.
        foreach ($newAnalysis['types'] as $type) {
            if (in_array($type, $existingAnalysis['types']) === false) {
                $existingAnalysis['types'][] = $type;
            }
        }

        // Add unique examples (limit to avoid memory issues).
        if (count($existingAnalysis['examples']) < 10) {
            foreach ($newAnalysis['examples'] as $example) {
                if (in_array($example, $existingAnalysis['examples'], true) === false) {
                    $existingAnalysis['examples'][] = $example;
                }
            }

            $existingAnalysis['examples'] = array_slice($existingAnalysis['examples'], 0, 5);
        }

        if (count($existingAnalysis['examples']) >= 10) {
            $existingAnalysis['examples'][] = $newAnalysis['examples'][0];
            $existingAnalysis['examples']   = array_unique($existingAnalysis['examples'], SORT_REGULAR);
            $existingAnalysis['examples']   = array_slice($existingAnalysis['examples'], 0, 5);
        }

        // Update length ranges.
        if (($newAnalysis['max_length'] ?? null) !== null && $newAnalysis['max_length'] > $existingAnalysis['max_length']) {
            $existingAnalysis['max_length'] = $newAnalysis['max_length'];
        }

        if (($newAnalysis['min_length'] ?? null) !== null && $newAnalysis['min_length'] < $existingAnalysis['min_length']) {
            $existingAnalysis['min_length'] = $newAnalysis['min_length'];
        }

        // Merge detected formats (if consistent patterns emerge).
        if (($newAnalysis['detected_format'] ?? null) !== null && ($newAnalysis['detected_format'] !== null) === true) {
            $existingAnalysis['detected_format'] = $this->consolidateFormatDetection(
                existingFormat: $existingAnalysis['detected_format'] ?? null,
                newFormat: $newAnalysis['detected_format']
            );
        }

        // Merge string patterns.
        if (empty($newAnalysis['string_patterns']) === false) {
            $existingAnalysis['string_patterns'] = array_unique(
                array_merge($existingAnalysis['string_patterns'] ?? [], $newAnalysis['string_patterns'])
            );
        }

        // Merge numeric ranges.
        if (empty($newAnalysis['numeric_range']) === false) {
            $existingAnalysis['numeric_range'] = $this->mergeNumericRanges(
                existingRange: $existingAnalysis['numeric_range'] ?? null,
                newRange: $newAnalysis['numeric_range']
            );
        }

        // Merge object structure analysis.
        if ($newAnalysis['object_structure'] !== null) {
            if ($existingAnalysis['object_structure'] !== null) {
                $this->mergeObjectStructures(
                    existingStructure: $existingAnalysis['object_structure'],
                    newStructure: $newAnalysis['object_structure']
                );
            }

            if ($existingAnalysis['object_structure'] === null) {
                $existingAnalysis['object_structure'] = $newAnalysis['object_structure'];
            }
        }

        // Merge array structure analysis.
        if ($newAnalysis['array_structure'] === true) {
            if (($existingAnalysis['array_structure'] === false)) {
                $existingAnalysis['array_structure'] = $newAnalysis['array_structure'];
            }
        }
    }//end mergePropertyAnalysis()

    /**
     * Consolidate format detection across multiple values
     *
     * @param string|null $existingFormat Currently detected format
     * @param string      $newFormat      New format to consider
     *
     * @return string Consolidated format
     */
    private function consolidateFormatDetection(?string $existingFormat, string $newFormat): string
    {
        // If existing format is null, use the new format.
        if ($existingFormat === null) {
            return $newFormat;
        }

        // If formats match, keep the format.
        if ($existingFormat === $newFormat) {
            return $existingFormat;
        }

        // If formats differ, prioritize more specific formats.
        $formatPriority = [
            'date-time' => 10,
            'date'      => 9,
            'time'      => 8,
            'uuid'      => 7,
            'email'     => 6,
            'url'       => 5,
            'hostname'  => 4,
            'ipv4'      => 3,
            'ipv6'      => 3,
            'color'     => 2,
            'duration'  => 1,
        ];

        $existingPriority = $formatPriority[$existingFormat] ?? 0;
        $newPriority      = $formatPriority[$newFormat] ?? 0;

        if ($newPriority > $existingPriority) {
            return $newFormat;
        }

        return $existingFormat;
    }//end consolidateFormatDetection()

    /**
     * Merge numeric ranges from multiple values
     *
     * @param array|null $existingRange Existing numeric range
     * @param array      $newRange      New numeric range to merge
     *
     * @return array Consolidated numeric range
     */
    private function mergeNumericRanges(?array $existingRange, array $newRange): array
    {
        if ($existingRange === null) {
            return $newRange;
        }

        // Ensure类型匹配.
        if ($existingRange['type'] !== $newRange['type']) {
            // Handle type promotion (integer -> number).
            if ($existingRange['type'] === 'integer' && $newRange['type'] === 'number') {
                $existingRange['type'] = 'number';
            }

            if ($existingRange['type'] === 'number' && $newRange['type'] === 'integer') {
                // Keep as number.
            }

            if ($existingRange['type'] !== 'integer' && $existingRange['type'] !== 'number') {
                // Incompatible types, default to number.
                return [
                    'type' => 'number',
                    'min'  => min($existingRange['min'], $newRange['min']),
                    'max'  => max($existingRange['max'], $newRange['max']),
                ];
            }
        }

        return [
            'type' => $existingRange['type'],
            'min'  => min($existingRange['min'], $newRange['min']),
            'max'  => max($existingRange['max'], $newRange['max']),
        ];
    }//end mergeNumericRanges()

    /**
     * Analyze array structure for nested property analysis
     *
     * @param array $array The array to analyze
     *
     * @return ((int|string)[]|int|mixed|null|string)[] Array structure analysis
     *
     * @psalm-return array{
     *     type: 'associative'|'empty'|'list',
     *     keys?: non-empty-list<array-key>,
     *     length?: int<1, max>,
     *     item_types?: array<string, 0|1|2>,
     *     sample_item?: mixed|null
     * }
     */
    private function analyzezArrayStructure(array $array): array
    {
        if (empty($array) === true) {
            return ['type' => 'empty', 'item_types' => []];
        }

        // Check if it's a list (indexed array) or object (associative array).
        $isList = array_is_list($array);

        if ($isList === true) {
            // Analyze item types in the list.
            $itemTypes = [];
            foreach ($array as $item) {
                $type = gettype($item);
                if (isset($itemTypes[$type]) === false) {
                    $itemTypes[$type] = 0;
                }

                $itemTypes[$type]++;
            }

            return [
                'type'        => 'list',
                'length'      => count($array),
                'item_types'  => $itemTypes,
                'sample_item' => $array[0] ?? null,
            ];
        }

        // It's an associative array, analyze as object.
        return [
            'type'   => 'associative',
            'keys'   => array_keys($array),
            'length' => count($array),
        ];
    }//end analyzezArrayStructure()

    /**
     * Analyze object structure for nested properties
     *
     * @param mixed $object The object or array to analyze
     *
     * @return ((int|string)[]|int|mixed|string)[] Object structure analysis
     *
     * @psalm-return array{type: 'object'|'scalar',
     *     keys?: list<array-key>, key_count?: int<0, max>, value?: mixed}
     */
    private function analyzeObjectStructure($object): array
    {
        if (is_object($object) === true) {
            $object = get_object_vars($object);
        }

        if (is_array($object) === false) {
            return ['type' => 'scalar', 'value' => $object];
        }

        $keys = array_keys($object);

        return [
            'type'      => 'object',
            'keys'      => $keys,
            'key_count' => count($keys),
        ];
    }//end analyzeObjectStructure()

    /**
     * Merge object structure analyses from multiple objects
     *
     * @param array $existingStructure Current structure analysis
     * @param array $newStructure      New structure to merge
     *
     * @return void Updates existing structure in place
     */
    private function mergeObjectStructures(array &$existingStructure, array $newStructure): void
    {
        if ($newStructure['type'] === 'object' && $existingStructure['type'] === 'object') {
            // Merge keys.
            $existingStructure['keys']      = array_unique(array_merge($existingStructure['keys'], $newStructure['keys']));
            $existingStructure['key_count'] = count($existingStructure['keys']);
        }
    }//end mergeObjectStructures()

    /**
     * Generate suggestions for schema updates based on discovered properties
     *
     * Creates structured suggestions for adding new properties to the schema,
     * including recommended data types and configurations.
     *
     * @param array $discoveredProperties Properties found in object analysis
     * @param array $existingProperties   Current schema properties
     *
     * @return ((int|string)|(mixed|string[])[]|mixed|null|true)[][]
     *
     * @psalm-return list<array{confidence: 'high'|'low'|'medium',
     *     description: 'Enum-like property with predefined values'|
     *     'Property discovered through object analysis',
     *     detected_format: mixed|null, enum?: list<mixed>, examples: array,
     *     items?: array{type: string}, maxLength?: 1000|mixed,
     *     max_length: mixed|null, min_length: mixed|null, nullable: true,
     *     numeric_range: mixed|null,
     *     properties?: array<array-key, array{description: 'Nested property discovered through analysis', type: 'string'}>,
     *     property_name: array-key, recommended_type: string,
     *     string_patterns: array<never, never>|mixed,
     *     type?: 'array'|'object'|'string', type_variations: mixed|null,
     *     usage_count: mixed, usage_percentage: 0|mixed}>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex suggestion generation with multiple property types
     * @SuppressWarnings(PHPMD.NPathComplexity)       Many property type scenarios require individual handling
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive suggestion logic requires extensive code
     */
    private function generateSuggestions(array $discoveredProperties, array $existingProperties): array
    {
        $suggestions    = [];
        $existPropNames = array_keys($existingProperties);

        foreach ($discoveredProperties as $propertyName => $analysis) {
            // Skip properties that already exist in the schema.
            if (in_array($propertyName, $existPropNames) === true) {
                continue;
            }

            // Skip internal/metadata properties.
            if ($this->isInternalProperty($propertyName) === true) {
                continue;
            }

            // Calculate confidence based on usage percentage.
            $usagePercentage = $analysis['usage_percentage'] ?? 0;
            $confidence      = 'low';

            if ($usagePercentage >= 80) {
                $confidence = 'high';
            } else if ($usagePercentage >= 50) {
                $confidence = 'medium';
            }

            // Determine recommended type.
            $recommendedType = $this->recommendPropertyType($analysis);

            // Determine max length value.
            $maxLengthValue = null;
            if ($analysis['max_length'] > 0) {
                $maxLengthValue = $analysis['max_length'];
            }

            // Determine min length value.
            $minLengthValue = null;
            if (isset($analysis['min_length']) === true && $analysis['min_length'] < PHP_INT_MAX) {
                $minLengthValue = $analysis['min_length'];
            }

            // Determine type variations.
            $typeVariations = null;
            if (count($analysis['types']) > 1) {
                $typeVariations = $analysis['types'];
            }

            $suggestion = [
                'property_name'    => $propertyName,
                'confidence'       => $confidence,
                'usage_percentage' => $usagePercentage,
                'usage_count'      => $analysis['usage_count'],
                'recommended_type' => $recommendedType,
                'examples'         => array_slice($analysis['examples'], 0, 3),
                'max_length'       => $maxLengthValue,
                'min_length'       => $minLengthValue,
                'nullable'         => true,
            // Default to nullable unless evidence suggests otherwise.
                'description'      => 'Property discovered through object analysis',
                'detected_format'  => $analysis['detected_format'] ?? null,
                'string_patterns'  => $analysis['string_patterns'] ?? [],
                'numeric_range'    => $analysis['numeric_range'] ?? null,
                'type_variations'  => $typeVariations,
            ];

            // Add specific type recommendations.
            if ($recommendedType === 'string' && $analysis['max_length'] > 0) {
                $suggestion['maxLength'] = min($analysis['max_length'] * 2, 1000);
                // Allow some buffer.
            }

            // Handle enum-like properties.
            if ($this->detectEnumLike($analysis) === true) {
                $suggestion['type']        = 'string';
                $suggestion['enum']        = $this->extractEnumValues($analysis['examples']);
                $suggestion['description'] = 'Enum-like property with predefined values';
            }

            // Handle nested objects.
            if (empty($analysis['object_structure']) === false && $analysis['object_structure']['type'] === 'object') {
                $suggestion['type']       = 'object';
                $suggestion['properties'] = $this->generateNestedProperties($analysis['object_structure']);
            }

            // Handle arrays.
            if (empty($analysis['array_structure']) === false && $analysis['array_structure']['type'] === 'list') {
                $suggestion['type']  = 'array';
                $suggestion['items'] = $this->generateArrayItemType($analysis['array_structure']);
            }

            $suggestions[] = $suggestion;
        }//end foreach

        // Sort suggestions by confidence and usage.
        usort(
            $suggestions,
            function ($a, $b) {
                $confidenceOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
                $confCompare     = $confidenceOrder[$a['confidence']] - $confidenceOrder[$b['confidence']];

                if ($confCompare !== 0) {
                    return $confCompare;
                }

                return $b['usage_percentage'] - $a['usage_percentage'];
            }
        );

        return $suggestions;
    }//end generateSuggestions()

    /**
     * Analyze existing schema properties for potential improvements
     *
     * Compares existing schema properties with object analysis data to identify
     * opportunities for enhancements, missing constraints, or configuration improvements.
     *
     * @param array $existingProperties   Current schema properties
     * @param array $discoveredProperties Properties found in object analysis
     * @param array $_usageStats          Usage statistics for all properties
     *
     * @return array List of property improvements
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Analysis of existing properties requires multiple checks
     */
    private function analyzeExistingProperties(
        array $existingProperties,
        array $discoveredProperties,
        array $_usageStats
    ): array {
        $improvements = [];

        foreach ($existingProperties as $propertyName => $propertyConfig) {
            // Skip if we don't have analysis data for this property.
            if (isset($discoveredProperties[$propertyName]) === false) {
                continue;
            }

            $analysis      = $discoveredProperties[$propertyName];
            $currentConfig = $propertyConfig;
            $improvement   = $this->comparePropertyWithAnalysis(currentConfig: $currentConfig, analysis: $analysis);

            if (empty($improvement['issues']) === false) {
                $usagePercentage = $analysis['usage_percentage'] ?? 0;
                $confidence      = 'low';
                if ($usagePercentage >= 50) {
                    $confidence = 'medium';
                }

                if ($usagePercentage >= 80) {
                    $confidence = 'high';
                }

                // Determine max length value.
                $maxLengthValue = null;
                if ($analysis['max_length'] > 0) {
                    $maxLengthValue = $analysis['max_length'];
                }

                // Determine min length value.
                $minLengthValue = null;
                if (isset($analysis['min_length']) === true && $analysis['min_length'] < PHP_INT_MAX) {
                    $minLengthValue = $analysis['min_length'];
                }

                // Determine type variations.
                $typeVariations = null;
                if (count($analysis['types']) > 1) {
                    $typeVariations = $analysis['types'];
                }

                $suggestion = [
                    'property_name'      => $propertyName,
                    'confidence'         => $confidence,
                    'usage_percentage'   => $usagePercentage,
                    'usage_count'        => $analysis['usage_count'],
                    'recommended_type'   => $improvement['recommended_type'],
                    'current_type'       => $propertyConfig['type'] ?? 'undefined',
                    'improvement_status' => 'existing',
                    'issues'             => $improvement['issues'],
                    'suggestions'        => $improvement['suggestions'],
                    'examples'           => array_slice($analysis['examples'], 0, 3),
                    'max_length'         => $maxLengthValue,
                    'min_length'         => $minLengthValue,
                    'detected_format'    => $analysis['detected_format'] ?? null,
                    'string_patterns'    => $analysis['string_patterns'] ?? [],
                    'numeric_range'      => $analysis['numeric_range'] ?? null,
                    'type_variations'    => $typeVariations,
                ];

                $improvements[] = $suggestion;
            }//end if
        }//end foreach

        // Sort improvements by confidence and usage.
        usort(
            $improvements,
            function ($a, $b) {
                $confidenceOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
                $confCompare     = $confidenceOrder[$a['confidence']] - $confidenceOrder[$b['confidence']];

                if ($confCompare !== 0) {
                    return $confCompare;
                }

                return $b['usage_percentage'] - $a['usage_percentage'];
            }
        );

        return $improvements;
    }//end analyzeExistingProperties()

    /**
     * Compare a property configuration with analysis data to identify improvements
     *
     * @param array $currentConfig Current property configuration
     * @param array $analysis      Analysis data from objects
     *
     * @return array Comparison results with issues and suggestions
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Comprehensive comparison requires many checks
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple comparison aspects create many code paths
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Detailed comparison logic requires extensive code
     */
    private function comparePropertyWithAnalysis(array $currentConfig, array $analysis): array
    {
        $issues          = [];
        $suggestions     = [];
        $recommendedType = $this->recommendPropertyType($analysis);

        // Delegate to focused comparison methods for each aspect.
        $typeComparison = $this->compareType(
            currentConfig: $currentConfig,
            recommendedType: $recommendedType
        );
        $issues         = array_merge($issues, $typeComparison['issues']);
        $suggestions    = array_merge($suggestions, $typeComparison['suggestions']);

        $stringComparison = $this->compareStringConstraints(
            currentConfig: $currentConfig,
            analysis: $analysis,
            recommendedType: $recommendedType
        );
        $issues           = array_merge($issues, $stringComparison['issues']);
        $suggestions      = array_merge($suggestions, $stringComparison['suggestions']);

        $numericComparison = $this->compareNumericConstraints(
            currentConfig: $currentConfig,
            analysis: $analysis,
            recommendedType: $recommendedType
        );
        $issues            = array_merge($issues, $numericComparison['issues']);
        $suggestions       = array_merge($suggestions, $numericComparison['suggestions']);

        $nullableComparison = $this->compareNullableConstraint(
            currentConfig: $currentConfig,
            analysis: $analysis
        );
        $issues      = array_merge($issues, $nullableComparison['issues']);
        $suggestions = array_merge($suggestions, $nullableComparison['suggestions']);

        $enumComparison = $this->compareEnumConstraint(
            currentConfig: $currentConfig,
            analysis: $analysis
        );
        $issues         = array_merge($issues, $enumComparison['issues']);
        $suggestions    = array_merge($suggestions, $enumComparison['suggestions']);

        return [
            'issues'           => $issues,
            'suggestions'      => $suggestions,
            'recommended_type' => $recommendedType,
        ];
    }//end comparePropertyWithAnalysis()

    /**
     * Compare the type between current config and recommended type.
     *
     * @param array  $currentConfig   Current property configuration
     * @param string $recommendedType Recommended type from analysis
     *
     * @return array Type comparison results
     */
    private function compareType(array $currentConfig, string $recommendedType): array
    {
        $issues      = [];
        $suggestions = [];
        $currentType = $currentConfig['type'] ?? null;

        // Check if type is missing.
        if ($currentType === null) {
            $suggestions[] = [
                'type'        => 'type',
                'field'       => 'type',
                'current'     => null,
                'recommended' => $recommendedType,
                'description' => "Consider adding type '{$recommendedType}' based on analysis",
            ];
        } else if ($currentType !== $recommendedType) {
            // Types don't match.
            $issues[]      = "Type mismatch: current type is '{$currentType}', recommended type is '{$recommendedType}'";
            $suggestions[] = [
                'type'        => 'type',
                'field'       => 'type',
                'current'     => $currentType,
                'recommended' => $recommendedType,
                'description' => "Analysis suggests type '{$recommendedType}' but schema defines '{$currentType}'",
            ];
        }

        return [
            'issues'      => $issues,
            'suggestions' => $suggestions,
        ];
    }//end compareType()

    /**
     * Compare string constraints (maxLength, format, pattern).
     *
     * @param array  $currentConfig   Current property configuration
     * @param array  $analysis        Property analysis data
     * @param string $recommendedType Recommended type
     *
     * @return array String constraint comparison results
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple string constraints require individual checks
     * @SuppressWarnings(PHPMD.NPathComplexity)      String validation has many conditional paths
     */
    private function compareStringConstraints(array $currentConfig, array $analysis, string $recommendedType): array
    {
        $issues      = [];
        $suggestions = [];
        $currentType = $currentConfig['type'] ?? null;

        // Only check string constraints if type is or should be string.
        if ($recommendedType !== 'string' && $currentType !== 'string') {
            return ['issues' => $issues, 'suggestions' => $suggestions];
        }

        // Check for missing or insufficient maxLength.
        if (($analysis['max_length'] ?? null) !== null && $analysis['max_length'] > 0) {
            $currentMaxLength = $currentConfig['maxLength'] ?? null;

            if ($currentMaxLength === null || $currentMaxLength === 0) {
                $issues[]           = "missing_max_length";
                $suggestedMaxLength = min($analysis['max_length'] * 2, 1000);
                $suggestions[]      = [
                    'type'        => 'constraint',
                    'field'       => 'maxLength',
                    'current'     => 'unlimited',
                    'recommended' => $suggestedMaxLength,
                    'description' => "Objects have max length of {$analysis['max_length']} characters",
                ];
            } else if ($currentMaxLength < $analysis['max_length']) {
                $issues[]        = "max_length_too_small";
                $observedMax     = $analysis['max_length'];
                $descriptionText = "Schema maxLength ({$currentMaxLength}) is smaller than observed max ({$observedMax})";
                $suggestions[]   = [
                    'type'        => 'constraint',
                    'field'       => 'maxLength',
                    'current'     => $currentMaxLength,
                    'recommended' => $observedMax,
                    'description' => $descriptionText,
                ];
            }//end if
        }//end if

        // Check for missing format.
        if (($analysis['detected_format'] ?? null) !== null
            && ($analysis['detected_format'] !== null) === true
            && ($analysis['detected_format'] !== '') === true
        ) {
            $currentFormat = $currentConfig['format'] ?? null;
            if ($currentFormat === null || $currentFormat === '') {
                $issues[]      = "missing_format";
                $suggestions[] = [
                    'type'        => 'format',
                    'field'       => 'format',
                    'current'     => 'none',
                    'recommended' => $analysis['detected_format'],
                    'description' => "Objects appear to have '{$analysis['detected_format']}' format pattern",
                ];
            }
        }

        // Check for missing pattern.
        if (empty($analysis['string_patterns']) === false) {
            $currentPattern = $currentConfig['pattern'] ?? null;
            $mainPattern    = $analysis['string_patterns'][0];
            if ($currentPattern === null || $currentPattern === '') {
                $issues[]      = "missing_pattern";
                $suggestions[] = [
                    'type'        => 'pattern',
                    'field'       => 'pattern',
                    'current'     => 'none',
                    'recommended' => $mainPattern,
                    'description' => "Strings follow '{$mainPattern}' pattern",
                ];
            }
        }

        return [
            'issues'      => $issues,
            'suggestions' => $suggestions,
        ];
    }//end compareStringConstraints()

    /**
     * Compare numeric constraints (minimum, maximum).
     *
     * @param array  $currentConfig   Current property configuration
     * @param array  $analysis        Property analysis data
     * @param string $recommendedType Recommended type
     *
     * @return array Numeric constraint comparison results
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Numeric range validation requires multiple comparisons
     */
    private function compareNumericConstraints(array $currentConfig, array $analysis, string $recommendedType): array
    {
        $issues      = [];
        $suggestions = [];
        $currentType = $currentConfig['type'] ?? null;

        // Only check numeric constraints if type is or should be numeric.
        $isNumericType = in_array($recommendedType, ['number', 'integer'], true)
            || in_array($currentType, ['number', 'integer'], true);

        if ($isNumericType === false || ($analysis['numeric_range'] ?? null) === null) {
            return ['issues' => $issues, 'suggestions' => $suggestions];
        }

        $range = $analysis['numeric_range'];

        // Check for missing or incorrect minimum.
        $currentMin = $currentConfig['minimum'] ?? null;
        if (($currentMin === false) && $range['min'] !== $range['max']) {
            $issues[]      = "missing_minimum";
            $suggestions[] = [
                'type'        => 'constraint',
                'field'       => 'minimum',
                'current'     => 'unlimited',
                'recommended' => $range['min'],
                'description' => "Observed range starts at {$range['min']}",
            ];
        } else if ($currentMin > $range['min']) {
            $issues[]      = "minimum_too_high";
            $suggestions[] = [
                'type'        => 'constraint',
                'field'       => 'minimum',
                'current'     => $currentMin,
                'recommended' => $range['min'],
                'description' => "Schema minimum ({$currentMin}) is higher than observed min ({$range['min']})",
            ];
        }

        // Check for missing or incorrect maximum.
        $currentMax = $currentConfig['maximum'] ?? null;
        if (($currentMax === false) && $range['min'] !== $range['max']) {
            $issues[]      = "missing_maximum";
            $suggestions[] = [
                'type'        => 'constraint',
                'field'       => 'maximum',
                'current'     => 'unlimited',
                'recommended' => $range['max'],
                'description' => "Observed range ends at {$range['max']}",
            ];
        } else if ($currentMax < $range['max']) {
            $issues[]      = "maximum_too_low";
            $suggestions[] = [
                'type'        => 'constraint',
                'field'       => 'maximum',
                'current'     => $currentMax,
                'recommended' => $range['max'],
                'description' => "Schema maximum ({$currentMax}) is lower than observed max ({$range['max']})",
            ];
        }

        return [
            'issues'      => $issues,
            'suggestions' => $suggestions,
        ];
    }//end compareNumericConstraints()

    /**
     * Compare nullable/required constraint.
     *
     * @param array $currentConfig Current property configuration
     * @param array $analysis      Property analysis data
     *
     * @return array Nullable constraint comparison results
     */
    private function compareNullableConstraint(array $currentConfig, array $analysis): array
    {
        $issues      = [];
        $suggestions = [];

        // Check if property should be nullable based on analysis.
        $isNullable = ($analysis['nullable'] ?? false) === true ||
                     (isset($analysis['nullable_variation']) && $analysis['nullable_variation'] === true);

        if ($isNullable === true) {
            $currentRequired = isset($currentConfig['required']) && $currentConfig['required'] === true;
            if ($currentRequired === true) {
                $issues[]      = "Property contains null values but is marked as required";
                $suggestions[] = [
                    'type'        => 'behavior',
                    'field'       => 'required',
                    'current'     => 'true',
                    'recommended' => 'false',
                    'description' => "Some objects have null values for this property",
                ];
            }

            // Check if schema doesn't allow null type.
            $currentType = $currentConfig['type'] ?? null;
            if ($currentType !== null && $currentType !== 'null') {
                $suggestions[] = [
                    'type'        => 'type',
                    'field'       => 'type',
                    'current'     => $currentType,
                    'recommended' => [$currentType, 'null'],
                    'description' => "Consider making this property nullable since data contains null values",
                ];
            }
        }//end if

        return [
            'issues'      => $issues,
            'suggestions' => $suggestions,
        ];
    }//end compareNullableConstraint()

    /**
     * Compare enum constraint.
     *
     * @param array $currentConfig Current property configuration
     * @param array $analysis      Property analysis data
     *
     * @return array Enum constraint comparison results
     *
     * @SuppressWarnings(PHPMD.ElseExpression) Enum comparison requires else for value difference detection
     */
    private function compareEnumConstraint(array $currentConfig, array $analysis): array
    {
        $issues      = [];
        $suggestions = [];

        // Check if analysis suggests enum values.
        $enumValues = $analysis['enum_values'] ?? null;

        if ($enumValues !== null && is_array($enumValues) === true) {
            $currentEnum = $currentConfig['enum'] ?? null;

            // Limit enum suggestions to reasonable number (e.g., 20).
            if (count($enumValues) <= 20) {
                if ($currentEnum === null || empty($currentEnum) === true) {
                    // Suggest adding enum.
                    $suggestions[] = [
                        'type'        => 'enum',
                        'field'       => 'enum',
                        'current'     => 'unlimited',
                        'recommended' => implode(', ', $enumValues),
                        'description' => "Property appears to have predefined values: ".implode(', ', $enumValues),
                    ];
                } else {
                    // Check if current enum differs from analysis.
                    $currentEnumSorted  = $currentEnum;
                    $analysisEnumSorted = $enumValues;
                    sort($currentEnumSorted);
                    sort($analysisEnumSorted);

                    if ($currentEnumSorted !== $analysisEnumSorted) {
                        $issues[]      = "Enum values in schema differ from values found in data";
                        $suggestions[] = [
                            'type'        => 'enum',
                            'field'       => 'enum',
                            'current'     => implode(', ', $currentEnum),
                            'recommended' => implode(', ', $enumValues),
                            'description' => "Data contains enum values not defined in schema",
                        ];
                    }
                }//end if
            }//end if
        }//end if

        return [
            'issues'      => $issues,
            'suggestions' => $suggestions,
        ];
    }//end compareEnumConstraint()

    /**
     * Check if a property name should be treated as internal
     *
     * @param string $propertyName The property name to check
     *
     * @return bool True if the property should be considered internal
     */
    private function isInternalProperty(string $propertyName): bool
    {
        $internalPatterns = [
            'id',
            'uuid',
            '_id',
            '_uuid',
            'created',
            'updated',
            'created_at',
            'updated_at',
            'deleted',
            'deleted_at',
            '@self',
            '$schema',
            '$id',
        ];

        $lowerPropertyName = strtolower($propertyName);
        return in_array($lowerPropertyName, $internalPatterns, true);
    }//end isInternalProperty()

    /**
     * Recommend the most appropriate property type based on analysis
     *
     * @param array $analysis Property analysis data
     *
     * @return string Recommended property type
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Type recommendation requires checking many type variations
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple type inference paths are necessary
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive type analysis requires extensive logic
     */
    private function recommendPropertyType(array $analysis): string
    {
        $types = $analysis['types'];

        // Try format-based recommendation first (most specific).
        $formatType = $this->getTypeFromFormat($analysis['detected_format'] ?? null);
        if ($formatType !== null) {
            return $formatType;
        }

        // Try pattern-based recommendation (e.g., numeric strings).
        $patternType = $this->getTypeFromPatterns($analysis['string_patterns'] ?? []);
        if ($patternType !== null) {
            return $patternType;
        }

        // If single type, handle it directly.
        if (count($types) === 1) {
            return $this->normalizeSingleType(phpType: $types[0], patterns: $analysis['string_patterns'] ?? []);
        }

        // Multiple types - analyze dominance.
            return $this->getDominantType(types: $types, patterns: $analysis['string_patterns'] ?? []);
    }//end recommendPropertyType()

    /**
     * Get JSON Schema type from detected format.
     *
     * @param string|null $format Detected format
     *
     * @return null|string JSON Schema type or null if format doesn't determine type
     *
     * @psalm-return 'string'|null
     */
    private function getTypeFromFormat(?string $format): string|null
    {
        if ($format === null || $format === '') {
            return null;
        }

        // Most formats are string-based in JSON Schema.
        $stringFormats = [
            'date',
            'date-time',
            'time',
            'email',
            'url',
            'hostname',
            'ipv4',
            'ipv6',
            'uuid',
            'color',
            'duration',
        ];

        if (in_array($format, $stringFormats, true) === true) {
            return 'string';
        }

        return null;
    }//end getTypeFromFormat()

    /**
     * Get JSON Schema type from string patterns (e.g., numeric strings).
     *
     * @param array $patterns String patterns detected in analysis
     *
     * @return null|string JSON Schema type or null if patterns don't determine type
     *
     * @psalm-return 'boolean'|'integer'|'number'|null
     */
    private function getTypeFromPatterns(array $patterns): string|null
    {
        // Boolean-like strings: "true", "false", "yes", "no".
        if (in_array('boolean_string', $patterns, true) === true) {
            return 'boolean';
        }

        // Integer strings: "123", "456".
        if (in_array('integer_string', $patterns, true) === true) {
            return 'integer';
        }

        // Float strings: "12.34", "56.78".
        if (in_array('float_string', $patterns, true) === true) {
            return 'number';
        }

        return null;
    }//end getTypeFromPatterns()

    /**
     * Normalize a single PHP type to JSON Schema type.
     *
     * @param string $phpType  PHP type from analysis
     * @param array  $patterns String patterns if type is string
     *
     * @return string JSON Schema type
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Type normalization requires handling many PHP types
     */
    private function normalizeSingleType(string $phpType, array $patterns): string
    {
        // Normalize type string to lowercase.
        $phpType = strtolower($phpType);

        switch ($phpType) {
            case 'string':
                // Check if it's a numeric string that should be a number.
                if (in_array('integer_string', $patterns, true) === true) {
                    return 'integer';
                }

                if (in_array('float_string', $patterns, true) === true) {
                    return 'number';
                }

                // Check if it's a boolean string.
                if (in_array('boolean_string', $patterns, true) === true) {
                    return 'boolean';
                }
                return 'string';

            case 'integer':
                return 'integer';

            case 'double':
            case 'float':
                return 'number';

            case 'boolean':
                return 'boolean';

            case 'array':
                return 'array';

            case 'object':
                return 'object';

            case 'null':
                return 'null';

            // JSON Schema standard types - preserve as-is.
            case 'number':
                return 'number';

            default:
                return 'string';
            // Safe fallback.
        }//end switch
    }//end normalizeSingleType()

    /**
     * Determine dominant type when multiple types are present.
     *
     * @param array $types    Array of PHP types found
     * @param array $patterns String patterns if dominant type is string
     *
     * @return string JSON Schema type
     */
    private function getDominantType(array $types, array $patterns): string
    {
        // Count type occurrences and sort by frequency.
        $typeCounts = array_count_values($types);
        arsort($typeCounts);
        $dominantType = array_key_first($typeCounts);

        // Special handling for string-dominated fields.
        if ($dominantType === 'string') {
            // If most values are consistently numeric strings, recommend the numeric type.
            if (in_array('integer_string', $patterns, true) === true
                && in_array('float_string', $patterns, true) === false
            ) {
                return 'integer';
            } else if (in_array('float_string', $patterns, true) === true) {
                return 'number';
            } else if (in_array('boolean_string', $patterns, true) === true) {
                return 'boolean';
            }

            return 'string';
        }

        // For other dominant types, normalize them.
        return $this->normalizeSingleType(phpType: $dominantType, patterns: $patterns);
    }//end getDominantType()

    /**
     * Detect if a property appears to be enum-like
     *
     * @param array $analysis Property analysis data
     *
     * @return bool True if property appears to be enum-like
     */
    private function detectEnumLike(array $analysis): bool
    {
        $examples = $analysis['examples'] ?? [];

        // Need at least 3 examples to detect enum pattern.
        if (count($examples) < 3) {
            return false;
        }

        // Count unique values.
        $uniqueValues  = array_unique($examples);
        $totalExamples = count($examples);
        $uniqueCount   = count($uniqueValues);

        // If we have relatively few unique values compared to total examples.
        // And all examples are strings, likely enum-like.
        return $uniqueCount <= ($totalExamples / 2) &&
               (empty($analysis['types']) === false) &&
               $analysis['types'][0] === 'string';
    }//end detectEnumLike()

    /**
     * Extract enum values from examples
     *
     * @param array $examples Property value examples
     *
     * @return array Array of unique enum values
     *
     * @psalm-return list<mixed>
     */
    private function extractEnumValues(array $examples): array
    {
        $uniqueValues = array_unique($examples);
        return array_values(
            array_filter(
                $uniqueValues,
                function ($value) {
                    return $value !== null && $value !== '';
                }
            )
        );
    }//end extractEnumValues()

    /**
     * Generate nested properties for object type suggestions
     *
     * @param array $objectStructure Analysis of object structure
     *
     * @return string[][] Nested property definitions
     *
     * @psalm-return array<array{type: 'string', description: 'Nested property discovered through analysis'}>
     */
    private function generateNestedProperties(array $objectStructure): array
    {
        $properties = [];

        if (($objectStructure['keys'] ?? null) !== null) {
            foreach ($objectStructure['keys'] as $key) {
                $properties[$key] = [
                    'type'        => 'string',
                // Default assumption.
                    'description' => 'Nested property discovered through analysis',
                ];
            }
        }

        return $properties;
    }//end generateNestedProperties()

    /**
     * Generate array item type for array type suggestions
     *
     * @param array $arrayStructure Analysis of array structure
     *
     * @return string[]
     *
     * @psalm-return array{type: string}
     */
    private function generateArrayItemType(array $arrayStructure): array
    {
        if (($arrayStructure['item_types'] ?? null) !== null) {
            $primaryType = array_key_first($arrayStructure['item_types']);
            if ($primaryType === null) {
                return ['type' => 'string'];
            }

            /*
             * Cast to string for type safety - array_key_first returns string|int|null.
             *
             * @psalm-suppress InvalidCast $primaryType is array key which can be cast to string.
             */

            $typeString = (string) $primaryType;

            switch ($typeString) {
                case 'string':
                    return ['type' => 'string'];
                case 'integer':
                    return ['type' => 'integer'];
                case 'double':
                case 'float':
                    return ['type' => 'number'];
                case 'boolean':
                    return ['type' => 'boolean'];
                case 'array':
                    return ['type' => 'array'];
                default:
                    return ['type' => 'string'];
            }//end switch
        }//end if

        return ['type' => 'string'];
    }//end generateArrayItemType()

    /**
     * Update schema properties based on exploration suggestions
     *
     * Applies user-confirmed property updates to a schema. This method validates
     * the updates and applies them to the schema definition.
     *
     * @param int   $schemaId        The schema ID to update
     * @param array $propertyUpdates Array of properties to add/update
     *
     * @throws \Exception If schema update fails
     *
     * @return Schema Updated schema entity
     */
    public function updateSchemaFromExploration(int $schemaId, array $propertyUpdates): Schema
    {
        $this->logger->info(message: 'Updating schema '.$schemaId.' with '.count($propertyUpdates).' property updates');

        try {
            // Get existing schema.
            $schema = $this->schemaMapper->find($schemaId);
            $existingProperties = $schema->getProperties();

            // Merge new properties with existing ones.
            foreach ($propertyUpdates as $propertyName => $propertyDefinition) {
                $existingProperties[$propertyName] = $propertyDefinition;
            }

            // Update schema properties.
            $schema->setProperties($existingProperties);

            // Regenerate facets if schema has facet generation enabled.
            $schema->regenerateFacetsFromProperties();

            // Save updated schema.
            $updatedSchema = $this->schemaMapper->update($schema);

            $this->logger->info(message: 'Schema '.$schemaId.' successfully updated with exploration results');

            return $updatedSchema;
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to update schema '.$schemaId.': '.$e->getMessage());
            throw new Exception('Failed to update schema properties: '.$e->getMessage());
        }//end try
    }//end updateSchemaFromExploration()
}//end class
