<?php

/**
 * OpenRegister Schema Service
 *
 * This file contains the service class for handling schema exploration and analysis
 * operations in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
use OCA\OpenRegister\Formats\UuidFormat;
use OCA\OpenRegister\Kernel\Contracts\SchemaExplorationInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
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
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SchemaService implements SchemaExplorationInterface {
	/**
	 * Maximum number of objects sampled when exploring a schema's properties.
	 *
	 * Property exploration is statistical, so a bounded sample is sufficient;
	 * loading every object of a schema (potentially hundreds of thousands) into
	 * memory to analyse it is an OOM hazard (bound-unbounded-query-memory).
	 */
	private const SCHEMA_EXPLORE_SAMPLE_SIZE = 1000;

	/**
	 * gettype() names that carry a numeric range, and the range type they get.
	 *
	 * @var array<string, string>
	 */
	private const NUMERIC_RANGE_TYPES = [
		'integer' => 'integer',
		'double' => 'number',
	];

	/**
	 * Simple string formats, in detection order, after dates and identifiers.
	 *
	 * @var array<string, string>
	 */
	private const FORMAT_PATTERNS = [
		// Time format (HH:MM:SS).
		'time' => '/^\d{2}:\d{2}:\d{2}$/',
		// Duration format (ISO 8601 duration like PT1H30M).
		'duration' => '/^P(\d+Y)?(\d+M)?(\d+D)?(T(\d+H)?(\d+M)?(\d+S)?)?$/',
		// Color format (hex).
		'color' => '/^#[0-9a-fA-F]{6}$/',
		// Hostname format.
		'hostname' => '/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
	];

	/**
	 * Naming-convention and filename patterns, in the order they are reported.
	 *
	 * The filename pattern uses ~ as the delimiter: the class contains a literal
	 * "/", which would prematurely close a /…/ pattern (PCRE delimiter scanning is
	 * naive), making preg_match error with "Unknown modifier '\'" and silently
	 * never match.
	 *
	 * @var array<string, string>
	 */
	private const STRING_PATTERNS = [
		'camelCase' => '/^[a-z]+[A-Z][a-zA-Z]*$/',
		'PascalCase' => '/^[A-Z][a-z]*([A-Z][a-z]*)*$/',
		'snake_case' => '/^[a-z]+(_[a-z]+)*$/',
		'SCREAMING_SNAKE_CASE' => '/^[A-Z]+(_[A-Z]+)*$/',
		'filename' => '~^[^<>:"/\\|?*]+\.[a-zA-Z0-9]+$~',
	];

	/**
	 * SchemaService constructor
	 *
	 * @param SchemaCatalogInterface $catalog The schema mapper (schema operations) and the magic mapper (object queries).
	 * @param LoggerInterface        $logger  Logger for debugging and monitoring.
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
		private readonly LoggerInterface $logger,
	) {
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
	 *
	 * @spec openspec/specs/schema-property-exploration/spec.md
	 */
	public function exploreSchemaProperties(int $schemaId): array {
		$this->logger->info(
			message: '[SchemaService] Starting schema exploration for schema ID: ' . $schemaId,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		// Get the schema to validate it exists.
		try {
			$schema = $this->catalog->schemas()->find($schemaId);
		} catch (\Exception $e) {
			throw new Exception('Schema not found with ID: ' . $schemaId);
		}

		// Sample a bounded set of objects for this schema. Exploration is
		// statistical, so a capped sample avoids loading the full (potentially
		// huge) object set into memory (bound-unbounded-query-memory).
		$objects = $this->catalog->tables()->findBySchema($schemaId, self::SCHEMA_EXPLORE_SAMPLE_SIZE);

		$this->logger->info(
			message: '[SchemaService] Analyzing a sample of ' . count($objects) . ' objects (cap ' . self::SCHEMA_EXPLORE_SAMPLE_SIZE . ')',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		if (empty($objects) === true) {
			return [
				'schema_id' => $schemaId,
				'schema_title' => $schema->getTitle(),
				'total_objects' => 0,
				'discovered_properties' => [],
				'existing_properties' => $schema->getProperties(),
				'suggestions' => [],
				'analysis_date' => (new DateTime('now'))->format(format: 'c'),
				'message' => 'No objects found for analysis',
			];
		}

		// Analyze all object data.
		$propertyAnalysis = $this->analyzeObjectProperties(objects: $objects, _existingProperties: $schema->getProperties());

		// Generate suggestions for both new and existing properties.
		$newPropSuggestions = $this->generateSuggestions(
			discoveredProperties: $propertyAnalysis['discovered'],
			existingProperties: $schema->getProperties()
		);
		$existPropSuggestions = $this->analyzeExistingProperties(
			existingProperties: $schema->getProperties(),
			discoveredProperties: $propertyAnalysis['discovered']
		);

		return [
			'schema_id' => $schemaId,
			'schema_title' => $schema->getTitle(),
			'total_objects' => count($objects),
			'discovered_properties' => $propertyAnalysis['discovered'],
			'existing_properties' => $schema->getProperties(),
			'property_usage_stats' => $propertyAnalysis['usage_stats'],
			'suggestions' => array_merge($newPropSuggestions, $existPropSuggestions),
			'analysis_date' => (new DateTime())->format('c'),
			'data_types' => $propertyAnalysis['data_types'],
			'analysis_summary' => [
				'new_properties_count' => count($newPropSuggestions),
				'existing_properties_improvements' => count($existPropSuggestions),
				'total_recommendations' => count($newPropSuggestions) + count($existPropSuggestions),
			],
		];
	}//end exploreSchemaProperties()

	/**
	 * Analyze object properties from a collection of objects
	 *
	 * Iterates through all objects and analyzes their JSON data to discover
	 * properties, data types, and usage patterns.
	 *
	 * @param array $objects Array of ObjectEntity objects
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
	private function analyzeObjectProperties(array $objects, array $_existingProperties = []): array {
		$run = new PropertyDiscoveryRun();
		$dataTypes = [];

		foreach ($objects as $object) {
			$objectData = $object->getObject();

			// Skip the '@self' metadata field in analysis.
			unset($objectData['@self']);

			$this->recordObjectProperties(run: $run, objectData: $objectData);
		}//end foreach

		$discoveredProperties = $run->discovered;
		$usageStats = $run->usageStats;

		// Calculate usage percentages.
		$totalObjects = count($objects);
		foreach ($usageStats['counts'] as $propertyName => $count) {
			$usageStats['percentages'][$propertyName] = round(($count / $totalObjects) * 100, 2);

			if (($discoveredProperties[$propertyName] ?? null) !== null) {
				$discoveredProperties[$propertyName]['usage_percentage'] = $usageStats['percentages'][$propertyName];
			}
		}

		return [
			'discovered' => $discoveredProperties,
			'usage_stats' => $usageStats,
			'data_types' => $dataTypes,
		];
	}//end analyzeObjectProperties()

	/**
	 * Count and analyse the properties of one object's data.
	 *
	 * @param PropertyDiscoveryRun $run        The pass: discovered properties and usage counts, extended in place
	 * @param array                $objectData The object's data, without '@self'
	 *
	 * @return void
	 */
	private function recordObjectProperties(PropertyDiscoveryRun $run, array $objectData): void {
		foreach ($objectData as $propertyName => $propertyValue) {
			// Track usage count.
			$run->usageStats['counts'][$propertyName] = (($run->usageStats['counts'][$propertyName] ?? 0) + 1);

			// Skip if null or empty.
			if ($propertyValue === null || $propertyValue === '') {
				continue;
			}

			$run->property = $propertyName;
			$this->recordPropertyValue(run: $run, propertyValue: $propertyValue);
		}
	}//end recordObjectProperties()

	/**
	 * Analyse one non-empty property value and merge it into the discovered properties.
	 *
	 * @param PropertyDiscoveryRun $run           The pass, on the property being recorded; its discoveries are extended in place
	 * @param mixed                $propertyValue The value
	 *
	 * @return void
	 */
	private function recordPropertyValue(PropertyDiscoveryRun $run, mixed $propertyValue): void {
		$propertyName = $run->property;
		$discoveredProperties = &$run->discovered;

		// Analyze data type and characteristics.
		$propertyAnalysis = $this->analyzePropertyValue(value: $propertyValue);

		if (isset($discoveredProperties[$propertyName]) === false) {
			$discoveredProperties[$propertyName] = [
				'name' => $propertyName,
				'types' => [],
				'examples' => [],
				'nullable' => true,
				'enum_values' => [],
				'max_length' => 0,
				'min_length' => PHP_INT_MAX,
				'object_structure' => null,
				'array_structure' => null,
				'detected_format' => null,
				'string_patterns' => [],
				'numeric_range' => null,
				'usage_count' => 0,
			];
		}

		// Merge type analysis.
		$existingAnalysis = &$discoveredProperties[$propertyName];
		$this->mergePropertyAnalysis(existingAnalysis: $existingAnalysis, newAnalysis: $propertyAnalysis);

		// Track total usage for percentage calculation.
		$discoveredProperties[$propertyName]['usage_count']++;
	}//end recordPropertyValue()

	/**
	 * Analyze a single property value and extract comprehensive type information
	 *
	 * @param mixed $value The property value to analyze
	 *
	 * @return array Analysis results with type and structure info
	 */
	private function analyzePropertyValue($value): array {
		$analysis = [
			'types' => [],
			'examples' => [$value],
			'max_length' => 0,
			'min_length' => PHP_INT_MAX,
			'object_structure' => null,
			'array_structure' => null,
			'detected_format' => null,
			'numeric_range' => null,
			'string_patterns' => [],
		];

		// Determine data type.
		$type = gettype($value);
		$analysis['types'][] = $type;

		// Type-specific analysis.
		return array_replace($analysis, $this->typeSpecificAnalysis(type: $type, value: $value));
	}//end analyzePropertyValue()

	/**
	 * The analysis fields a value's type adds to the base analysis.
	 *
	 * @param string $type  The value's gettype()
	 * @param mixed  $value The property value
	 *
	 * @return array The fields to replace in the base analysis
	 */
	private function typeSpecificAnalysis(string $type, mixed $value): array {
		if ($type === 'string') {
			return $this->stringAnalysis(value: $value);
		}

		if (isset(self::NUMERIC_RANGE_TYPES[$type]) === true) {
			return ['numeric_range' => ['min' => $value, 'max' => $value, 'type' => self::NUMERIC_RANGE_TYPES[$type]]];
		}

		if ($type === 'array') {
			return $this->arrayAnalysis(value: $value);
		}

		if ($type === 'object') {
			// Analyze object structure.
			return ['object_structure' => $this->analyzeObjectStructure(object: $value)];
		}

		return [];
	}//end typeSpecificAnalysis()

	/**
	 * The analysis fields of a string: its length, format and patterns.
	 *
	 * @param string $value The string
	 *
	 * @return array max_length, min_length, detected_format, string_patterns
	 */
	private function stringAnalysis(string $value): array {
		$length = strlen($value);

		return [
			'max_length' => $length,
			'min_length' => $length,
			// Detect format based on string patterns.
			'detected_format' => $this->detectStringFormat(value: $value),
			// AnalyzeStringPattern() returns a flat list of pattern-name
			// strings. Merge it (rather than append the whole array) so
			// string_patterns stays a flat string[] as every consumer
			// expects — getTypeFromPatterns' in_array checks, the
			// array_unique dedup, and the [0] "main pattern". Appending
			// nested it, which broke those lookups and triggered
			// array-to-string warnings.
			'string_patterns' => array_merge([], $this->analyzeStringPattern(value: $value)),
		];
	}//end stringAnalysis()

	/**
	 * The analysis field of an array: a list's structure, or an associative array as an object.
	 *
	 * @param array $value The array
	 *
	 * @return array array_structure or object_structure; nothing for an empty array
	 */
	private function arrayAnalysis(array $value): array {
		if (empty($value) === true) {
			return [];
		}

		// Check if this is an associative array (object-like).
		if (array_is_list($value) === true) {
			// Analyze array structure for list arrays.
			return ['array_structure' => $this->analyzezArrayStructure(array: $value)];
		}

		// Treat associative arrays as objects.
		return ['object_structure' => $this->analyzeObjectStructure(object: $value)];
	}//end arrayAnalysis()

	/**
	 * Detect format based on string patterns (date, email, url, uuid, etc.)
	 *
	 * @param string $value The string value to analyze
	 *
	 * @return null|string Detected format or null if none
	 */
	private function detectStringFormat(string $value): ?string {
		// Dates, then identifiers, then the simple patterns, then IP addresses: first match wins.
		return ($this->detectDateFormat(value: $value)
			?? $this->detectDateTimeFormat(value: $value)
			?? $this->detectIdentifierFormat(value: $value)
			?? $this->detectPatternFormat(value: $value)
			?? $this->detectIpFormat(value: $value));
	}//end detectStringFormat()

	/**
	 * 'date' for a real Y-m-d calendar date.
	 *
	 * @param string $value The string value to analyze
	 *
	 * @return string|null 'date' or null
	 */
	private function detectDateFormat(string $value): ?string {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			$parsed = date_create_from_format('Y-m-d', $value);
			if ($parsed !== false && $parsed->format('Y-m-d') === $value) {
				return 'date';
			}
		}

		return null;
	}//end detectDateFormat()

	/**
	 * 'date-time' for an ISO 8601 date-time with an offset.
	 *
	 * @param string $value The string value to analyze
	 *
	 * @return string|null 'date-time' or null
	 */
	private function detectDateTimeFormat(string $value): ?string {
		// Date-Time formats.
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) === 1
			&& date_create_from_format(DATE_ISO8601, $value) !== false
		) {
			return 'date-time';
		}

		// RFC3339 format.
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $value) === 1) {
			return 'date-time';
		}

		return null;
	}//end detectDateTimeFormat()

	/**
	 * 'uuid', 'email' or 'url', in that order.
	 *
	 * @param string $value The string value to analyze
	 *
	 * @return string|null The format or null
	 */
	private function detectIdentifierFormat(string $value): ?string {
		// UUID format.
		if (UuidFormat::isCanonical(value: $value) === true) {
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

		return null;
	}//end detectIdentifierFormat()

	/**
	 * The first of the time, duration, color and hostname patterns the string matches.
	 *
	 * @param string $value The string value to analyze
	 *
	 * @return string|null The format or null
	 */
	private function detectPatternFormat(string $value): ?string {
		foreach (self::FORMAT_PATTERNS as $format => $pattern) {
			if (preg_match($pattern, $value) === 1) {
				return $format;
			}
		}

		return null;
	}//end detectPatternFormat()

	/**
	 * 'ipv4' or 'ipv6'.
	 *
	 * @param string $value The string value to analyze
	 *
	 * @return string|null The format or null
	 */
	private function detectIpFormat(string $value): ?string {
		// IPv4 format.
		if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			return 'ipv4';
		}

		// IPv6 format.
		if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			return 'ipv6';
		}

		return null;
	}//end detectIpFormat()

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
	 */
	private function analyzeStringPattern(string $value): array {
		// Check for numeric strings (could be integers).
		$patterns = $this->numericStringPatterns(value: $value);

		// Check for boolean-like strings.
		if (in_array(strtolower($value), ['true', 'false', 'yes', 'no', 'on', 'off', '1', '0']) === true) {
			$patterns[] = 'boolean_string';
		}

		// Check for enum-like patterns (camelCase, PascalCase, etc.) and filenames.
		foreach (self::STRING_PATTERNS as $name => $pattern) {
			if (preg_match($pattern, $value) === 1) {
				$patterns[] = $name;
			}
		}

		// Check for directory patterns.
		if ($this->isPathLike(value: $value) === true) {
			$patterns[] = 'path';
		}

		return $patterns;
	}//end analyzeStringPattern()

	/**
	 * 'integer_string' or 'float_string' for a numeric string.
	 *
	 * @param string $value The string value to analyze
	 *
	 * @return string[] Zero or one pattern
	 */
	private function numericStringPatterns(string $value): array {
		if (is_numeric($value) === false) {
			return [];
		}

		if (ctype_digit($value) === true) {
			return ['integer_string'];
		}

		return ['float_string'];
	}//end numericStringPatterns()

	/**
	 * Whether a string contains a path separator.
	 *
	 * @param string $value The string value to analyze
	 *
	 * @return bool True for a / or \ anywhere
	 */
	private function isPathLike(string $value): bool {
		return str_contains($value, '/') === true || str_contains($value, '\\') === true;
	}//end isPathLike()

	/**
	 * Merge property analysis data from multiple objects
	 *
	 * @param array $existingAnalysis Existing analysis data
	 * @param array $newAnalysis New analysis data to merge
	 *
	 * @return void Updates the existing analysis in place
	 */
	private function mergePropertyAnalysis(array &$existingAnalysis, array $newAnalysis): void {
		// Merge types.
		$existingAnalysis['types'] = $this->mergedTypes(existingTypes: $existingAnalysis['types'], newTypes: $newAnalysis['types']);

		// Add unique examples (limit to avoid memory issues).
		$existingAnalysis['examples'] = $this->mergedExamples(
			existingExamples: $existingAnalysis['examples'],
			newExamples: $newAnalysis['examples']
		);

		// Update length ranges.
		$this->widenLengths(existingAnalysis: $existingAnalysis, newAnalysis: $newAnalysis);

		// Merge detected formats, string patterns and numeric ranges.
		$this->mergeValueHints(existingAnalysis: $existingAnalysis, newAnalysis: $newAnalysis);

		// Merge object structure analysis.
		$this->mergeObjectStructureAnalysis(existingAnalysis: $existingAnalysis, newStructure: $newAnalysis['object_structure']);

		// Merge array structure analysis.
		if ($newAnalysis['array_structure'] === true && $existingAnalysis['array_structure'] === false) {
			$existingAnalysis['array_structure'] = $newAnalysis['array_structure'];
		}
	}//end mergePropertyAnalysis()

	/**
	 * The existing types plus any new ones, in order of first sight.
	 *
	 * @param array $existingTypes The types seen so far
	 * @param array $newTypes      The new value's types
	 *
	 * @return array The merged types
	 */
	private function mergedTypes(array $existingTypes, array $newTypes): array {
		foreach ($newTypes as $type) {
			if (in_array($type, $existingTypes) === false) {
				$existingTypes[] = $type;
			}
		}

		return $existingTypes;
	}//end mergedTypes()

	/**
	 * The examples kept after adding new ones: unique, at most five.
	 *
	 * @param array $existingExamples The examples kept so far
	 * @param array $newExamples      The new value's examples
	 *
	 * @return array The examples to keep
	 */
	private function mergedExamples(array $existingExamples, array $newExamples): array {
		if (count($existingExamples) < 10) {
			foreach ($newExamples as $example) {
				if (in_array($example, $existingExamples, true) === false) {
					$existingExamples[] = $example;
				}
			}

			$existingExamples = array_slice($existingExamples, 0, 5);
		}

		if (count($existingExamples) >= 10) {
			$existingExamples[] = $newExamples[0];
			$existingExamples = array_unique($existingExamples, SORT_REGULAR);
			$existingExamples = array_slice($existingExamples, 0, 5);
		}

		return $existingExamples;
	}//end mergedExamples()

	/**
	 * Widen the max and min length to include the new value's.
	 *
	 * @param array $existingAnalysis Existing analysis data; updated in place
	 * @param array $newAnalysis      New analysis data
	 *
	 * @return void
	 */
	private function widenLengths(array &$existingAnalysis, array $newAnalysis): void {
		if (($newAnalysis['max_length'] ?? null) !== null && $newAnalysis['max_length'] > $existingAnalysis['max_length']) {
			$existingAnalysis['max_length'] = $newAnalysis['max_length'];
		}

		if (($newAnalysis['min_length'] ?? null) !== null && $newAnalysis['min_length'] < $existingAnalysis['min_length']) {
			$existingAnalysis['min_length'] = $newAnalysis['min_length'];
		}
	}//end widenLengths()

	/**
	 * Merge the detected format, the string patterns and the numeric range the new value carries.
	 *
	 * @param array $existingAnalysis Existing analysis data; updated in place
	 * @param array $newAnalysis      New analysis data
	 *
	 * @return void
	 */
	private function mergeValueHints(array &$existingAnalysis, array $newAnalysis): void {
		// Merge detected formats (if consistent patterns emerge).
		if (($newAnalysis['detected_format'] ?? null) !== null) {
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
	}//end mergeValueHints()

	/**
	 * Merge a new object structure into the existing analysis, or take it when there is none yet.
	 *
	 * @param array      $existingAnalysis Existing analysis data; updated in place
	 * @param array|null $newStructure     The new value's object structure
	 *
	 * @return void
	 */
	private function mergeObjectStructureAnalysis(array &$existingAnalysis, ?array $newStructure): void {
		if ($newStructure === null) {
			return;
		}

		if ($existingAnalysis['object_structure'] !== null) {
			$this->mergeObjectStructures(
				existingStructure: $existingAnalysis['object_structure'],
				newStructure: $newStructure
			);
		}

		if ($existingAnalysis['object_structure'] === null) {
			$existingAnalysis['object_structure'] = $newStructure;
		}
	}//end mergeObjectStructureAnalysis()

	/**
	 * Consolidate format detection across multiple values
	 *
	 * @param string|null $existingFormat Currently detected format
	 * @param string $newFormat New format to consider
	 *
	 * @return string Consolidated format
	 */
	private function consolidateFormatDetection(?string $existingFormat, string $newFormat): string {
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
			'date' => 9,
			'time' => 8,
			'uuid' => 7,
			'email' => 6,
			'url' => 5,
			'hostname' => 4,
			'ipv4' => 3,
			'ipv6' => 3,
			'color' => 2,
			'duration' => 1,
		];

		$existingPriority = $formatPriority[$existingFormat] ?? 0;
		$newPriority = $formatPriority[$newFormat] ?? 0;

		if ($newPriority > $existingPriority) {
			return $newFormat;
		}

		return $existingFormat;
	}//end consolidateFormatDetection()

	/**
	 * Merge numeric ranges from multiple values
	 *
	 * @param array|null $existingRange Existing numeric range
	 * @param array $newRange New numeric range to merge
	 *
	 * @return array Consolidated numeric range
	 */
	private function mergeNumericRanges(?array $existingRange, array $newRange): array {
		if ($existingRange === null) {
			return $newRange;
		}

		return [
			'type' => $this->mergedRangeType(existingType: $existingRange['type'], newType: $newRange['type']),
			'min' => min($existingRange['min'], $newRange['min']),
			'max' => max($existingRange['max'], $newRange['max']),
		];
	}//end mergeNumericRanges()

	/**
	 * The type of two merged numeric ranges.
	 *
	 * The same type stays; an integer meeting a number is promoted to a number; a
	 * number stays a number; an existing type that is neither defaults to a number.
	 *
	 * @param mixed $existingType The existing range's type
	 * @param mixed $newType      The new range's type
	 *
	 * @return mixed The merged type
	 */
	private function mergedRangeType(mixed $existingType, mixed $newType): mixed {
		if ($existingType === $newType) {
			return $existingType;
		}

		// Incompatible types, default to number.
		if (in_array($existingType, ['integer', 'number'], true) === false) {
			return 'number';
		}

		// Handle type promotion (integer -> number).
		if ($existingType === 'integer' && $newType === 'number') {
			return 'number';
		}

		return $existingType;
	}//end mergedRangeType()

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
	private function analyzezArrayStructure(array $array): array {
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
				'type' => 'list',
				'length' => count($array),
				'item_types' => $itemTypes,
				'sample_item' => $array[0] ?? null,
			];
		}

		// It's an associative array, analyze as object.
		return [
			'type' => 'associative',
			'keys' => array_keys($array),
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
	private function analyzeObjectStructure($object): array {
		if (is_object($object) === true) {
			$object = get_object_vars($object);
		}

		if (is_array($object) === false) {
			return ['type' => 'scalar', 'value' => $object];
		}

		$keys = array_keys($object);

		return [
			'type' => 'object',
			'keys' => $keys,
			'key_count' => count($keys),
		];
	}//end analyzeObjectStructure()

	/**
	 * Merge object structure analyses from multiple objects
	 *
	 * @param array $existingStructure Current structure analysis
	 * @param array $newStructure New structure to merge
	 *
	 * @return void Updates existing structure in place
	 */
	private function mergeObjectStructures(array &$existingStructure, array $newStructure): void {
		if ($newStructure['type'] === 'object' && $existingStructure['type'] === 'object') {
			// Merge keys.
			$existingStructure['keys'] = array_unique(array_merge($existingStructure['keys'], $newStructure['keys']));
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
	 * @param array $existingProperties Current schema properties
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
	 */
	private function generateSuggestions(array $discoveredProperties, array $existingProperties): array {
		$suggestions = [];
		$existPropNames = array_keys($existingProperties);

		foreach ($discoveredProperties as $propertyName => $analysis) {
			// Skip properties already in the schema, and internal/metadata properties.
			if (in_array($propertyName, $existPropNames) === true
				|| $this->isInternalProperty(propertyName: $propertyName) === true
			) {
				continue;
			}

			$suggestions[] = $this->refineSuggestion(
				suggestion: $this->baseSuggestion(propertyName: $propertyName, analysis: $analysis),
				analysis: $analysis
			);
		}

		return $this->sortByConfidence(suggestions: $suggestions);
	}//end generateSuggestions()

	/**
	 * The suggestion for one discovered property: confidence from usage, recommended type and
	 * the observed lengths, formats and type variations.
	 *
	 * @param int|string $propertyName The property name.
	 * @param array      $analysis     The property's analysis.
	 *
	 * @return array The suggestion.
	 */
	private function baseSuggestion(int|string $propertyName, array $analysis): array {
		$observed = $this->observedFields(analysis: $analysis);
		return array_merge(
			$this->suggestionHead(propertyName: $propertyName, analysis: $analysis),
			array_slice($observed, 0, 3),
			[
				// Default to nullable unless evidence suggests otherwise.
				'nullable' => true,
				'description' => 'Property discovered through object analysis',
			],
			array_slice($observed, 3)
		);
	}//end baseSuggestion()

	/**
	 * The leading fields of a suggestion: name, confidence from usage, usage and recommended type.
	 *
	 * @param int|string $propertyName The property name.
	 * @param array      $analysis     The property's analysis.
	 *
	 * @return array The fields.
	 */
	private function suggestionHead(int|string $propertyName, array $analysis): array {
		$usagePercentage = $analysis['usage_percentage'] ?? 0;
		$confidence = 'low';
		if ($usagePercentage >= 80) {
			$confidence = 'high';
		} elseif ($usagePercentage >= 50) {
			$confidence = 'medium';
		}

		return [
			'property_name' => $propertyName,
			'confidence' => $confidence,
			'usage_percentage' => $usagePercentage,
			'usage_count' => $analysis['usage_count'],
			'recommended_type' => $this->recommendPropertyType(analysis: $analysis),
		];
	}//end suggestionHead()

	/**
	 * What the analysis observed: examples, lengths, format, patterns, numeric range and the
	 * type variations when there is more than one type.
	 *
	 * @param array $analysis The property's analysis.
	 *
	 * @return array The fields.
	 */
	private function observedFields(array $analysis): array {
		$maxLengthValue = null;
		if ($analysis['max_length'] > 0) {
			$maxLengthValue = $analysis['max_length'];
		}

		$minLengthValue = null;
		if (isset($analysis['min_length']) === true && $analysis['min_length'] < PHP_INT_MAX) {
			$minLengthValue = $analysis['min_length'];
		}

		$typeVariations = null;
		if (count($analysis['types']) > 1) {
			$typeVariations = $analysis['types'];
		}

		return [
			'examples' => array_slice($analysis['examples'], 0, 3),
			'max_length' => $maxLengthValue,
			'min_length' => $minLengthValue,
			'detected_format' => $analysis['detected_format'] ?? null,
			'string_patterns' => $analysis['string_patterns'] ?? [],
			'numeric_range' => $analysis['numeric_range'] ?? null,
			'type_variations' => $typeVariations,
		];
	}//end observedFields()

	/**
	 * Sort suggestions by confidence, then by usage percentage descending.
	 *
	 * @param array $suggestions The suggestions.
	 *
	 * @return array The sorted suggestions.
	 */
	private function sortByConfidence(array $suggestions): array {
		usort(
			$suggestions,
			function ($a, $b) {
				$confidenceOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
				$confCompare = $confidenceOrder[$a['confidence']] - $confidenceOrder[$b['confidence']];
				if ($confCompare !== 0) {
					return $confCompare;
				}

				return $b['usage_percentage'] - $a['usage_percentage'];
			}
		);

		return $suggestions;
	}//end sortByConfidence()

	/**
	 * Add the type-specific parts of a suggestion: a buffered maxLength for strings, enum values
	 * for enum-like properties, and the shape of nested objects and lists.
	 *
	 * @param array $suggestion The base suggestion.
	 * @param array $analysis   The property's analysis.
	 *
	 * @return array The suggestion.
	 */
	private function refineSuggestion(array $suggestion, array $analysis): array {
		if ($suggestion['recommended_type'] === 'string' && $analysis['max_length'] > 0) {
			$suggestion['maxLength'] = min($analysis['max_length'] * 2, 1000);
		}

		if ($this->detectEnumLike(analysis: $analysis) === true) {
			$suggestion['type'] = 'string';
			$suggestion['enum'] = $this->extractEnumValues(examples: $analysis['examples']);
			$suggestion['description'] = 'Enum-like property with predefined values';
		}

		if (empty($analysis['object_structure']) === false && $analysis['object_structure']['type'] === 'object') {
			$suggestion['type'] = 'object';
			$suggestion['properties'] = $this->generateNestedProperties(objectStructure: $analysis['object_structure']);
		}

		if (empty($analysis['array_structure']) === false && $analysis['array_structure']['type'] === 'list') {
			$suggestion['type'] = 'array';
			$suggestion['items'] = $this->generateArrayItemType(arrayStructure: $analysis['array_structure']);
		}

		return $suggestion;
	}//end refineSuggestion()

	/**
	 * Analyze existing schema properties for potential improvements
	 *
	 * Compares existing schema properties with object analysis data to identify
	 * opportunities for enhancements, missing constraints, or configuration improvements.
	 *
	 * @param array $existingProperties Current schema properties
	 * @param array $discoveredProperties Properties found in object analysis
	 *
	 * @return array List of property improvements
	 */
	private function analyzeExistingProperties(array $existingProperties, array $discoveredProperties): array {
		$improvements = [];
		foreach ($existingProperties as $propertyName => $propertyConfig) {
			// Skip if we don't have analysis data for this property.
			if (isset($discoveredProperties[$propertyName]) === false) {
				continue;
			}

			$analysis = $discoveredProperties[$propertyName];
			$improvement = $this->comparePropertyWithAnalysis(currentConfig: $propertyConfig, analysis: $analysis);
			if (empty($improvement['issues']) === true) {
				continue;
			}

			$improvements[] = array_merge(
				$this->suggestionHead(propertyName: $propertyName, analysis: $analysis),
				[
					'current_type' => $propertyConfig['type'] ?? 'undefined',
					'improvement_status' => 'existing',
					'issues' => $improvement['issues'],
					'suggestions' => $improvement['suggestions'],
				],
				$this->observedFields(analysis: $analysis)
			);
		}//end foreach

		return $this->sortByConfidence(suggestions: $improvements);
	}//end analyzeExistingProperties()

	/**
	 * Compare a property configuration with analysis data to identify improvements
	 *
	 * @param array $currentConfig Current property configuration
	 * @param array $analysis Analysis data from objects
	 *
	 * @return array Comparison results with issues and suggestions
	 */
	private function comparePropertyWithAnalysis(array $currentConfig, array $analysis): array {
		$recommendedType = $this->recommendPropertyType(analysis: $analysis);
		$currentType = $currentConfig['type'] ?? null;

		$comparisons = [$this->compareType(currentConfig: $currentConfig, recommendedType: $recommendedType)];
		// String constraints apply when the type is or should be string, numeric ones likewise.
		if ($recommendedType === 'string' || $currentType === 'string') {
			$comparisons[] = $this->compareStringConstraints(currentConfig: $currentConfig, analysis: $analysis);
		}

		if (in_array($recommendedType, ['number', 'integer'], true) === true
			|| in_array($currentType, ['number', 'integer'], true) === true
		) {
			$comparisons[] = $this->compareNumericConstraints(currentConfig: $currentConfig, analysis: $analysis);
		}

		$comparisons[] = $this->compareNullableConstraint(currentConfig: $currentConfig, analysis: $analysis);
		$comparisons[] = $this->compareEnumConstraint(currentConfig: $currentConfig, analysis: $analysis);

		return [
			'issues' => array_merge(...array_column($comparisons, 'issues')),
			'suggestions' => array_merge(...array_column($comparisons, 'suggestions')),
			'recommended_type' => $recommendedType,
		];
	}//end comparePropertyWithAnalysis()

	/**
	 * Compare the type between current config and recommended type.
	 *
	 * @param array $currentConfig Current property configuration
	 * @param string $recommendedType Recommended type from analysis
	 *
	 * @return array Type comparison results
	 */
	private function compareType(array $currentConfig, string $recommendedType): array {
		$issues = [];
		$suggestions = [];
		$currentType = $currentConfig['type'] ?? null;
		$typeLabel = $currentType;
		if (is_array($currentType) === true) {
			$typeLabel = implode(', ', $currentType);
		}

		// Check if type is missing.
		if ($currentType === null) {
			$suggestions[] = [
				'type' => 'type',
				'field' => 'type',
				'current' => null,
				'recommended' => $recommendedType,
				'description' => "Consider adding type '{$recommendedType}' based on analysis",
			];
		} elseif ($currentType !== $recommendedType
			&& (is_array($currentType) === false || in_array($recommendedType, $currentType, true) === false)
		) {
			// Types don't match; a union type matches when it lists the recommended type.
			$issues[] = "Type mismatch: current type is '{$typeLabel}', recommended type is '{$recommendedType}'";
			$suggestions[] = [
				'type' => 'type',
				'field' => 'type',
				'current' => $currentType,
				'recommended' => $recommendedType,
				'description' => "Analysis suggests type '{$recommendedType}' but schema defines '{$typeLabel}'",
			];
		}

		return [
			'issues' => $issues,
			'suggestions' => $suggestions,
		];
	}//end compareType()

	/**
	 * Compare string constraints (maxLength, format, pattern).
	 *
	 * @param array $currentConfig Current property configuration
	 * @param array $analysis      Property analysis data
	 *
	 * @return array String constraint comparison results
	 */
	private function compareStringConstraints(array $currentConfig, array $analysis): array {
		return $this->findingsResult(
			findings: [
				$this->maxLengthFinding(currentConfig: $currentConfig, analysis: $analysis),
				$this->formatFinding(currentConfig: $currentConfig, analysis: $analysis),
				$this->patternFinding(currentConfig: $currentConfig, analysis: $analysis),
			]
		);
	}//end compareStringConstraints()

	/**
	 * Issues and suggestions from a list of findings.
	 *
	 * @param array<array{0: string, 1: array}|null> $findings Each an issue with its suggestion, or null.
	 *
	 * @return array{issues: list<string>, suggestions: list<array>}
	 */
	private function findingsResult(array $findings): array {
		$result = ['issues' => [], 'suggestions' => []];
		foreach ($findings as $finding) {
			if ($finding !== null) {
				$result['issues'][] = $finding[0];
				$result['suggestions'][] = $finding[1];
			}
		}

		return $result;
	}//end findingsResult()

	/**
	 * A missing or too small maxLength, compared with the observed maximum length.
	 *
	 * @param array $currentConfig Current property configuration
	 * @param array $analysis      Property analysis data
	 *
	 * @return array{0: string, 1: array}|null The issue and suggestion, or null.
	 */
	private function maxLengthFinding(array $currentConfig, array $analysis): ?array {
		if (($analysis['max_length'] ?? null) === null || ($analysis['max_length'] > 0) === false) {
			return null;
		}

		$observedMax = $analysis['max_length'];
		$currentMaxLength = $currentConfig['maxLength'] ?? null;
		if ($currentMaxLength === null || $currentMaxLength === 0) {
			return [
				'missing_max_length',
				[
					'type' => 'constraint',
					'field' => 'maxLength',
					'current' => 'unlimited',
					'recommended' => min($observedMax * 2, 1000),
					'description' => "Objects have max length of {$observedMax} characters",
				],
			];
		}

		if (($currentMaxLength < $observedMax) === false) {
			return null;
		}

		return [
			'max_length_too_small',
			[
				'type' => 'constraint',
				'field' => 'maxLength',
				'current' => $currentMaxLength,
				'recommended' => $observedMax,
				'description' => "Schema maxLength ({$currentMaxLength}) is smaller than observed max ({$observedMax})",
			],
		];
	}//end maxLengthFinding()

	/**
	 * A missing format when the analysis detected one.
	 *
	 * @param array $currentConfig Current property configuration
	 * @param array $analysis      Property analysis data
	 *
	 * @return array{0: string, 1: array}|null The issue and suggestion, or null.
	 */
	private function formatFinding(array $currentConfig, array $analysis): ?array {
		$detected = $analysis['detected_format'] ?? null;
		$currentFormat = $currentConfig['format'] ?? null;
		if ($detected === null || $detected === '' || ($currentFormat !== null && $currentFormat !== '')) {
			return null;
		}

		return [
			'missing_format',
			[
				'type' => 'format',
				'field' => 'format',
				'current' => 'none',
				'recommended' => $detected,
				'description' => "Objects appear to have '{$detected}' format pattern",
			],
		];
	}//end formatFinding()

	/**
	 * A missing pattern when the strings follow one.
	 *
	 * @param array $currentConfig Current property configuration
	 * @param array $analysis      Property analysis data
	 *
	 * @return array{0: string, 1: array}|null The issue and suggestion, or null.
	 */
	private function patternFinding(array $currentConfig, array $analysis): ?array {
		if (empty($analysis['string_patterns']) === true) {
			return null;
		}

		$mainPattern = $analysis['string_patterns'][0];
		$currentPattern = $currentConfig['pattern'] ?? null;
		if ($currentPattern !== null && $currentPattern !== '') {
			return null;
		}

		return [
			'missing_pattern',
			[
				'type' => 'pattern',
				'field' => 'pattern',
				'current' => 'none',
				'recommended' => $mainPattern,
				'description' => "Strings follow '{$mainPattern}' pattern",
			],
		];
	}//end patternFinding()

	/**
	 * Compare numeric constraints (minimum, maximum) with the observed numeric range.
	 *
	 * @param array $currentConfig Current property configuration
	 * @param array $analysis      Property analysis data
	 *
	 * @return array Numeric constraint comparison results
	 */
	private function compareNumericConstraints(array $currentConfig, array $analysis): array {
		$range = $analysis['numeric_range'] ?? null;
		if ($range === null) {
			return ['issues' => [], 'suggestions' => []];
		}

		return $this->findingsResult(
			findings: [
				$this->minimumFinding(currentMin: $currentConfig['minimum'] ?? null, range: $range),
				$this->maximumFinding(currentMax: $currentConfig['maximum'] ?? null, range: $range),
			]
		);
	}//end compareNumericConstraints()

	/**
	 * A missing or too high minimum, compared with the observed range.
	 *
	 * @param mixed $currentMin The schema's minimum; null or false marks it as unset.
	 * @param array $range      The observed range, with min and max.
	 *
	 * @return array{0: string, 1: array}|null The issue and suggestion, or null.
	 */
	private function minimumFinding(mixed $currentMin, array $range): ?array {
		if (($currentMin === null || $currentMin === false) && $range['min'] !== $range['max']) {
			return [
				'missing_minimum',
				[
					'type' => 'constraint',
					'field' => 'minimum',
					'current' => 'unlimited',
					'recommended' => $range['min'],
					'description' => "Observed range starts at {$range['min']}",
				],
			];
		}

		if ($currentMin === null || $currentMin === false || ($currentMin > $range['min']) === false) {
			return null;
		}

		return [
			'minimum_too_high',
			[
				'type' => 'constraint',
				'field' => 'minimum',
				'current' => $currentMin,
				'recommended' => $range['min'],
				'description' => "Schema minimum ({$currentMin}) is higher than observed min ({$range['min']})",
			],
		];
	}//end minimumFinding()

	/**
	 * A missing or too low maximum, compared with the observed range.
	 *
	 * @param mixed $currentMax The schema's maximum; null or false marks it as unset.
	 * @param array $range      The observed range, with min and max.
	 *
	 * @return array{0: string, 1: array}|null The issue and suggestion, or null.
	 */
	private function maximumFinding(mixed $currentMax, array $range): ?array {
		if (($currentMax === null || $currentMax === false) && $range['min'] !== $range['max']) {
			return [
				'missing_maximum',
				[
					'type' => 'constraint',
					'field' => 'maximum',
					'current' => 'unlimited',
					'recommended' => $range['max'],
					'description' => "Observed range ends at {$range['max']}",
				],
			];
		}

		if ($currentMax === null || $currentMax === false || ($currentMax < $range['max']) === false) {
			return null;
		}

		return [
			'maximum_too_low',
			[
				'type' => 'constraint',
				'field' => 'maximum',
				'current' => $currentMax,
				'recommended' => $range['max'],
				'description' => "Schema maximum ({$currentMax}) is lower than observed max ({$range['max']})",
			],
		];
	}//end maximumFinding()

	/**
	 * Compare nullable/required constraint.
	 *
	 * @param array $currentConfig Current property configuration
	 * @param array $analysis Property analysis data
	 *
	 * @return array Nullable constraint comparison results
	 */
	private function compareNullableConstraint(array $currentConfig, array $analysis): array {
		$issues = [];
		$suggestions = [];

		// Check if property should be nullable based on analysis.
		$isNullable = ($analysis['nullable'] ?? false) === true
					 || (isset($analysis['nullable_variation']) && $analysis['nullable_variation'] === true);

		if ($isNullable === true) {
			$currentRequired = isset($currentConfig['required']) && $currentConfig['required'] === true;
			if ($currentRequired === true) {
				$issues[] = 'Property contains null values but is marked as required';
				$suggestions[] = [
					'type' => 'behavior',
					'field' => 'required',
					'current' => 'true',
					'recommended' => 'false',
					'description' => 'Some objects have null values for this property',
				];
			}

			// Check if schema doesn't allow null type.
			$currentType = $currentConfig['type'] ?? null;
			if ($currentType !== null && $currentType !== 'null') {
				$suggestions[] = [
					'type' => 'type',
					'field' => 'type',
					'current' => $currentType,
					'recommended' => [$currentType, 'null'],
					'description' => 'Consider making this property nullable since data contains null values',
				];
			}
		}//end if

		return [
			'issues' => $issues,
			'suggestions' => $suggestions,
		];
	}//end compareNullableConstraint()

	/**
	 * Compare enum constraint.
	 *
	 * @param array $currentConfig Current property configuration
	 * @param array $analysis Property analysis data
	 *
	 * @return array Enum constraint comparison results
	 *
	 * Enum comparison requires else for value difference detection
	 */
	private function compareEnumConstraint(array $currentConfig, array $analysis): array {
		$issues = [];
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
						'type' => 'enum',
						'field' => 'enum',
						'current' => 'unlimited',
						'recommended' => implode(', ', $enumValues),
						'description' => 'Property appears to have predefined values: ' . implode(', ', $enumValues),
					];
				}

				if ($currentEnum !== null && empty($currentEnum) !== true) {
					// Check if current enum differs from analysis.
					$currentEnumSorted = $currentEnum;
					$analysisEnumSorted = $enumValues;
					sort($currentEnumSorted);
					sort($analysisEnumSorted);

					if ($currentEnumSorted !== $analysisEnumSorted) {
						$issues[] = 'Enum values in schema differ from values found in data';
						$suggestions[] = [
							'type' => 'enum',
							'field' => 'enum',
							'current' => implode(', ', $currentEnum),
							'recommended' => implode(', ', $enumValues),
							'description' => 'Data contains enum values not defined in schema',
						];
					}
				}//end if
			}//end if
		}//end if

		return [
			'issues' => $issues,
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
	private function isInternalProperty(string $propertyName): bool {
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
	private function recommendPropertyType(array $analysis): string {
		$types = $analysis['types'];

		// Try format-based recommendation first (most specific).
		$formatType = $this->getTypeFromFormat(format: $analysis['detected_format'] ?? null);
		if ($formatType !== null) {
			return $formatType;
		}

		// Try pattern-based recommendation (e.g., numeric strings).
		$patternType = $this->getTypeFromPatterns(patterns: $analysis['string_patterns'] ?? []);
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
	private function getTypeFromFormat(?string $format): ?string {
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
	private function getTypeFromPatterns(array $patterns): ?string {
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
	 * @param string $phpType PHP type from analysis
	 * @param array $patterns String patterns if type is string
	 *
	 * @return string JSON Schema type
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Type normalization requires handling many PHP types
	 */
	private function normalizeSingleType(string $phpType, array $patterns): string {
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
	 * @param array $types Array of PHP types found
	 * @param array $patterns String patterns if dominant type is string
	 *
	 * @return string JSON Schema type
	 */
	private function getDominantType(array $types, array $patterns): string {
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
			} elseif (in_array('float_string', $patterns, true) === true) {
				return 'number';
			} elseif (in_array('boolean_string', $patterns, true) === true) {
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
	private function detectEnumLike(array $analysis): bool {
		$examples = $analysis['examples'] ?? [];

		// Need at least 3 examples to detect enum pattern.
		if (count($examples) < 3) {
			return false;
		}

		// Count unique values.
		$uniqueValues = array_unique($examples);
		$totalExamples = count($examples);
		$uniqueCount = count($uniqueValues);

		// If we have relatively few unique values compared to total examples.
		// And all examples are strings, likely enum-like.
		return $uniqueCount <= ($totalExamples / 2)
			   && (empty($analysis['types']) === false)
			   && $analysis['types'][0] === 'string';
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
	private function extractEnumValues(array $examples): array {
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
	private function generateNestedProperties(array $objectStructure): array {
		$properties = [];

		if (($objectStructure['keys'] ?? null) !== null) {
			foreach ($objectStructure['keys'] as $key) {
				$properties[$key] = [
					'type' => 'string',
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
	private function generateArrayItemType(array $arrayStructure): array {
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

			$typeString = (string)$primaryType;

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
	 * @param int $schemaId The schema ID to update
	 * @param array $propertyUpdates Array of properties to add/update
	 *
	 * @throws \Exception If schema update fails
	 *
	 * @return Schema Updated schema entity
	 *
	 * @spec openspec/specs/schema-property-exploration/spec.md
	 */
	public function updateSchemaFromExploration(int $schemaId, array $propertyUpdates): Schema {
		$this->logger->info(
			message: '[SchemaService] Updating schema ' . $schemaId . ' with ' . count($propertyUpdates) . ' property updates',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		try {
			// Get existing schema.
			$schema = $this->catalog->schemas()->find($schemaId);
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
			$updatedSchema = $this->catalog->schemas()->update($schema);

			$this->logger->info(
				message: '[SchemaService] Schema ' . $schemaId . ' successfully updated with exploration results',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return $updatedSchema;
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[SchemaService] Failed to update schema ' . $schemaId . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception('Failed to update schema properties: ' . $e->getMessage());
		}//end try
	}//end updateSchemaFromExploration()
}//end class
