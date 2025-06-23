<?php

/**
 * OpenRegister MariaDB Facet Handler
 *
 * This file contains the handler for managing JSON object field facets
 * using MariaDB JSON functions in the OpenRegister application.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Db\ObjectHandlers
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\ObjectHandlers;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Handler for JSON object field facets using MariaDB
 *
 * This handler provides faceting capabilities for JSON object fields
 * using MariaDB's JSON functions to extract and aggregate data.
 */
class MariaDbFacetHandler
{

    /**
     * Constructor for the MariaDbFacetHandler
     *
     * @param IDBConnection $db The database connection
     */
    public function __construct(
        private readonly IDBConnection $db
    ) {
    }//end __construct()


    /**
     * Get terms facet for a JSON object field
     *
     * Returns unique values and their counts for categorical JSON fields.
     *
     * @param string $field     The JSON field name (supports dot notation)
     * @param array  $baseQuery Base query filters to apply
     *
     * @phpstan-param string $field
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param string $field
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Terms facet data with buckets containing key and results
     */
    public function getTermsFacet(string $field, array $baseQuery = []): array
    {
        $queryBuilder = $this->db->getQueryBuilder();
        
        // Build JSON path for the field
        $jsonPath = '$.' . $field;
        
        // Build aggregation query for JSON field
        $queryBuilder->selectAlias(
                $queryBuilder->createFunction("JSON_UNQUOTE(JSON_EXTRACT(object, " . $queryBuilder->createNamedParameter($jsonPath) . "))"),
                'field_value'
            )
            ->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'doc_count')
            ->from('openregister_objects')
            ->where($queryBuilder->expr()->isNotNull(
                $queryBuilder->createFunction("JSON_EXTRACT(object, " . $queryBuilder->createNamedParameter($jsonPath) . ")")
            ))
            ->groupBy('field_value')
            ->orderBy('doc_count', 'DESC'); // Note: Still using doc_count in ORDER BY as it's the SQL alias

        // Apply base filters
        $this->applyBaseFilters($queryBuilder, $baseQuery);

        $result = $queryBuilder->executeQuery();
        $buckets = [];

        while ($row = $result->fetch()) {
            $key = $row['field_value'];
            if ($key !== null && $key !== '') {
                $buckets[] = [
                    'key' => $key,
                    'results' => (int) $row['doc_count']
                ];
            }
        }

        return [
            'type' => 'terms',
            'buckets' => $buckets
        ];

    }//end getTermsFacet()


    /**
     * Get date histogram facet for a JSON object field
     *
     * Returns time-based buckets with counts for date JSON fields.
     *
     * @param string $field     The JSON field name (supports dot notation)
     * @param string $interval  The histogram interval (day, week, month, year)
     * @param array  $baseQuery Base query filters to apply
     *
     * @phpstan-param string $field
     * @phpstan-param string $interval
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param string $field
     * @psalm-param string $interval
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Date histogram facet data
     */
    public function getDateHistogramFacet(string $field, string $interval, array $baseQuery = []): array
    {
        $queryBuilder = $this->db->getQueryBuilder();
        
        $jsonPath = '$.' . $field;
        $dateFormat = $this->getDateFormatForInterval($interval);
        
        $queryBuilder->selectAlias(
                $queryBuilder->createFunction(
                    "DATE_FORMAT(JSON_UNQUOTE(JSON_EXTRACT(object, " . $queryBuilder->createNamedParameter($jsonPath) . ")), '$dateFormat')"
                ),
                'date_key'
            )
            ->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'doc_count')
            ->from('openregister_objects')
            ->where($queryBuilder->expr()->isNotNull(
                $queryBuilder->createFunction("JSON_EXTRACT(object, " . $queryBuilder->createNamedParameter($jsonPath) . ")")
            ))
            ->groupBy('date_key')
            ->orderBy('date_key', 'ASC');

        // Apply base filters
        $this->applyBaseFilters($queryBuilder, $baseQuery);

        $result = $queryBuilder->executeQuery();
        $buckets = [];

        while ($row = $result->fetch()) {
            if ($row['date_key'] !== null) {
                $buckets[] = [
                    'key' => $row['date_key'],
                    'results' => (int) $row['doc_count']
                ];
            }
        }

        return [
            'type' => 'date_histogram',
            'interval' => $interval,
            'buckets' => $buckets
        ];

    }//end getDateHistogramFacet()


    /**
     * Get range facet for a JSON object field
     *
     * Returns range buckets with counts for numeric JSON fields.
     *
     * @param string $field     The JSON field name (supports dot notation)
     * @param array  $ranges    Range definitions with 'from' and/or 'to' keys
     * @param array  $baseQuery Base query filters to apply
     *
     * @phpstan-param string $field
     * @phpstan-param array<array<string, mixed>> $ranges
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param string $field
     * @psalm-param array<array<string, mixed>> $ranges
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Range facet data
     */
    public function getRangeFacet(string $field, array $ranges, array $baseQuery = []): array
    {
        $buckets = [];
        $jsonPath = '$.' . $field;

        foreach ($ranges as $range) {
            $queryBuilder = $this->db->getQueryBuilder();
            
            $queryBuilder->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'doc_count')
                ->from('openregister_objects')
                ->where($queryBuilder->expr()->isNotNull(
                    $queryBuilder->createFunction("JSON_EXTRACT(object, " . $queryBuilder->createNamedParameter($jsonPath) . ")")
                ));

            // Apply range conditions
            if (isset($range['from'])) {
                $queryBuilder->andWhere(
                    $queryBuilder->createFunction(
                        "CAST(JSON_UNQUOTE(JSON_EXTRACT(object, " . $queryBuilder->createNamedParameter($jsonPath) . ")) AS DECIMAL(10,2))"
                    ) . ' >= ' . $queryBuilder->createNamedParameter($range['from'])
                );
            }
            if (isset($range['to'])) {
                $queryBuilder->andWhere(
                    $queryBuilder->createFunction(
                        "CAST(JSON_UNQUOTE(JSON_EXTRACT(object, " . $queryBuilder->createNamedParameter($jsonPath) . ")) AS DECIMAL(10,2))"
                    ) . ' < ' . $queryBuilder->createNamedParameter($range['to'])
                );
            }

            // Apply base filters
            $this->applyBaseFilters($queryBuilder, $baseQuery);

            $result = $queryBuilder->executeQuery();
            $count = (int) $result->fetchOne();

            // Generate range key
            $key = $this->generateRangeKey($range);

            $bucket = [
                'key' => $key,
                'results' => $count
            ];

            if (isset($range['from'])) {
                $bucket['from'] = $range['from'];
            }
            if (isset($range['to'])) {
                $bucket['to'] = $range['to'];
            }

            $buckets[] = $bucket;
        }

        return [
            'type' => 'range',
            'buckets' => $buckets
        ];

    }//end getRangeFacet()


    /**
     * Apply base query filters to the query builder
     *
     * This method applies the base search filters to ensure facets
     * are calculated within the context of the current search.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param array         $baseQuery    The base query filters
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @return void
     */
    private function applyBaseFilters(IQueryBuilder $queryBuilder, array $baseQuery): void
    {
        // Apply basic filters like deleted, published, etc.
        $includeDeleted = $baseQuery['_includeDeleted'] ?? false;
        $published = $baseQuery['_published'] ?? false;

        // By default, only include objects where 'deleted' is NULL unless $includeDeleted is true
        if ($includeDeleted === false) {
            $queryBuilder->andWhere($queryBuilder->expr()->isNull('deleted'));
        }

        // If published filter is set, only include objects that are currently published
        if ($published === true) {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $queryBuilder->andWhere(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->isNotNull('published'),
                    $queryBuilder->expr()->lte('published', $queryBuilder->createNamedParameter($now)),
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->isNull('depublished'),
                        $queryBuilder->expr()->gt('depublished', $queryBuilder->createNamedParameter($now))
                    )
                )
            );
        }

        // Apply metadata filters from @self
        if (isset($baseQuery['@self']) && is_array($baseQuery['@self'])) {
            foreach ($baseQuery['@self'] as $field => $value) {
                if ($value === 'IS NOT NULL') {
                    $queryBuilder->andWhere($queryBuilder->expr()->isNotNull($field));
                } else if ($value === 'IS NULL') {
                    $queryBuilder->andWhere($queryBuilder->expr()->isNull($field));
                } else if (is_array($value)) {
                    $queryBuilder->andWhere($queryBuilder->expr()->in($field, $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
                } else {
                    $queryBuilder->andWhere($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)));
                }
            }
        }

        // Apply JSON object field filters (non-@self filters)
        $objectFilters = array_filter($baseQuery, function($key) {
            return $key !== '@self' && !str_starts_with($key, '_');
        }, ARRAY_FILTER_USE_KEY);

        foreach ($objectFilters as $field => $value) {
            $jsonPath = '$.' . $field;
            
            if ($value === 'IS NOT NULL') {
                $queryBuilder->andWhere($queryBuilder->expr()->isNotNull(
                    $queryBuilder->createFunction("JSON_EXTRACT(object, " . $queryBuilder->createNamedParameter($jsonPath) . ")")
                ));
            } else if ($value === 'IS NULL') {
                $queryBuilder->andWhere($queryBuilder->expr()->isNull(
                    $queryBuilder->createFunction("JSON_EXTRACT(object, " . $queryBuilder->createNamedParameter($jsonPath) . ")")
                ));
            } else if (is_array($value)) {
                // For array values, check if any of the values match
                $orConditions = $queryBuilder->expr()->orX();
                foreach ($value as $val) {
                    $orConditions->add(
                        $queryBuilder->expr()->eq(
                            $queryBuilder->createFunction("JSON_UNQUOTE(JSON_EXTRACT(object, " . $queryBuilder->createNamedParameter($jsonPath) . "))"),
                            $queryBuilder->createNamedParameter($val)
                        )
                    );
                }
                $queryBuilder->andWhere($orConditions);
            } else {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq(
                        $queryBuilder->createFunction("JSON_UNQUOTE(JSON_EXTRACT(object, " . $queryBuilder->createNamedParameter($jsonPath) . "))"),
                        $queryBuilder->createNamedParameter($value)
                    )
                );
            }
        }

    }//end applyBaseFilters()


    /**
     * Get date format string for histogram interval
     *
     * @param string $interval The interval (day, week, month, year)
     *
     * @phpstan-param string $interval
     *
     * @psalm-param string $interval
     *
     * @return string MySQL date format string
     */
    private function getDateFormatForInterval(string $interval): string
    {
        switch ($interval) {
            case 'day':
                return '%Y-%m-%d';
            case 'week':
                return '%Y-%u';
            case 'month':
                return '%Y-%m';
            case 'year':
                return '%Y';
            default:
                return '%Y-%m';
        }

    }//end getDateFormatForInterval()


    /**
     * Generate a human-readable key for a range
     *
     * @param array $range Range definition with 'from' and/or 'to' keys
     *
     * @phpstan-param array<string, mixed> $range
     *
     * @psalm-param array<string, mixed> $range
     *
     * @return string Human-readable range key
     */
    private function generateRangeKey(array $range): string
    {
        if (isset($range['from']) && isset($range['to'])) {
            return $range['from'] . '-' . $range['to'];
        } else if (isset($range['from'])) {
            return $range['from'] . '+';
        } else if (isset($range['to'])) {
            return '0-' . $range['to'];
        } else {
            return 'all';
        }

    }//end generateRangeKey()


    /**
     * Get facetable object fields by analyzing JSON data in the database
     *
     * This method analyzes the JSON object data to determine which fields
     * can be used for faceting and what types of facets are appropriate.
     * It samples objects to determine field types and characteristics.
     *
     * @param array $baseQuery Base query filters to apply for context
     * @param int   $sampleSize Maximum number of objects to analyze (default: 100)
     *
     * @phpstan-param array<string, mixed> $baseQuery
     * @phpstan-param int $sampleSize
     *
     * @psalm-param array<string, mixed> $baseQuery
     * @psalm-param int $sampleSize
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Facetable object fields with their configuration
     */
    public function getFacetableFields(array $baseQuery = [], int $sampleSize = 100): array
    {
        // Get sample objects to analyze
        $sampleObjects = $this->getSampleObjects($baseQuery, $sampleSize);
        
        if (empty($sampleObjects)) {
            return [];
        }

        // Analyze fields across all sample objects
        $fieldAnalysis = [];
        
        foreach ($sampleObjects as $objectData) {
            $this->analyzeObjectFields($objectData, $fieldAnalysis);
        }

        // Convert analysis to facetable field configuration
        $facetableFields = [];
        
        foreach ($fieldAnalysis as $fieldPath => $analysis) {
            // Only include fields that appear in at least 10% of objects
            $appearanceRate = $analysis['count'] / count($sampleObjects);
            if ($appearanceRate >= 0.1) {
                $fieldConfig = $this->determineFieldConfiguration($fieldPath, $analysis);
                if ($fieldConfig !== null) {
                    $facetableFields[$fieldPath] = $fieldConfig;
                }
            }
        }

        return $facetableFields;

    }//end getFacetableFields()


    /**
     * Get sample objects for field analysis
     *
     * @param array $baseQuery  Base query filters to apply
     * @param int   $sampleSize Maximum number of objects to sample
     *
     * @phpstan-param array<string, mixed> $baseQuery
     * @phpstan-param int $sampleSize
     *
     * @psalm-param array<string, mixed> $baseQuery
     * @psalm-param int $sampleSize
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Array of object data for analysis
     */
    private function getSampleObjects(array $baseQuery, int $sampleSize): array
    {
        $queryBuilder = $this->db->getQueryBuilder();
        
        $queryBuilder->select('object')
            ->from('openregister_objects')
            ->where($queryBuilder->expr()->isNotNull('object'))
            ->setMaxResults($sampleSize);

        // Apply base filters
        $this->applyBaseFilters($queryBuilder, $baseQuery);

        $result = $queryBuilder->executeQuery();
        $objects = [];

        while ($row = $result->fetch()) {
            $objectData = json_decode($row['object'], true);
            if (is_array($objectData)) {
                $objects[] = $objectData;
            }
        }

        return $objects;

    }//end getSampleObjects()


    /**
     * Analyze fields in an object recursively
     *
     * @param array  $objectData    The object data to analyze
     * @param array  &$fieldAnalysis Reference to field analysis array
     * @param string $prefix        Current field path prefix
     * @param int    $depth         Current recursion depth
     *
     * @phpstan-param array<string, mixed> $objectData
     * @phpstan-param array<string, mixed> $fieldAnalysis
     * @phpstan-param string $prefix
     * @phpstan-param int $depth
     *
     * @psalm-param array<string, mixed> $objectData
     * @psalm-param array<string, mixed> $fieldAnalysis
     * @psalm-param string $prefix
     * @psalm-param int $depth
     *
     * @return void
     */
    private function analyzeObjectFields(array $objectData, array &$fieldAnalysis, string $prefix = '', int $depth = 0): void
    {
        // Limit recursion depth to avoid infinite loops and performance issues
        if ($depth > 2) {
            return;
        }

        foreach ($objectData as $key => $value) {
            $fieldPath = $prefix === '' ? $key : $prefix . '.' . $key;
            
            // Skip system fields
            if (str_starts_with($key, '@') || str_starts_with($key, '_')) {
                continue;
            }

            // Initialize field analysis if not exists
            if (!isset($fieldAnalysis[$fieldPath])) {
                $fieldAnalysis[$fieldPath] = [
                    'count' => 0,
                    'types' => [],
                    'sample_values' => [],
                    'is_array' => false,
                    'is_nested' => false,
                    'unique_values' => 0
                ];
            }

            $fieldAnalysis[$fieldPath]['count']++;

            // Analyze value type and characteristics
            if (is_array($value)) {
                $fieldAnalysis[$fieldPath]['is_array'] = true;
                
                // Check if it's an array of objects (nested structure)
                if (!empty($value) && is_array($value[0])) {
                    $fieldAnalysis[$fieldPath]['is_nested'] = true;
                    // Recursively analyze nested objects
                    if (is_array($value[0])) {
                        $this->analyzeObjectFields($value[0], $fieldAnalysis, $fieldPath, $depth + 1);
                    }
                } else {
                    // Array of simple values
                    foreach ($value as $item) {
                        $this->recordValueType($fieldAnalysis[$fieldPath], $item);
                        $this->recordSampleValue($fieldAnalysis[$fieldPath], $item);
                    }
                }
            } else if (is_object($value) || (is_array($value) && !empty($value))) {
                $fieldAnalysis[$fieldPath]['is_nested'] = true;
                // Recursively analyze nested object
                if (is_array($value)) {
                    $this->analyzeObjectFields($value, $fieldAnalysis, $fieldPath, $depth + 1);
                }
            } else {
                // Simple value
                $this->recordValueType($fieldAnalysis[$fieldPath], $value);
                $this->recordSampleValue($fieldAnalysis[$fieldPath], $value);
            }
        }

    }//end analyzeObjectFields()


    /**
     * Record the type of a value in field analysis
     *
     * @param array &$fieldAnalysis Reference to field analysis data
     * @param mixed $value          The value to analyze
     *
     * @phpstan-param array<string, mixed> $fieldAnalysis
     * @phpstan-param mixed $value
     *
     * @psalm-param array<string, mixed> $fieldAnalysis
     * @psalm-param mixed $value
     *
     * @return void
     */
    private function recordValueType(array &$fieldAnalysis, mixed $value): void
    {
        $type = $this->determineValueType($value);
        
        if (!isset($fieldAnalysis['types'][$type])) {
            $fieldAnalysis['types'][$type] = 0;
        }
        $fieldAnalysis['types'][$type]++;

    }//end recordValueType()


    /**
     * Record a sample value in field analysis
     *
     * @param array &$fieldAnalysis Reference to field analysis data
     * @param mixed $value          The value to record
     *
     * @phpstan-param array<string, mixed> $fieldAnalysis
     * @phpstan-param mixed $value
     *
     * @psalm-param array<string, mixed> $fieldAnalysis
     * @psalm-param mixed $value
     *
     * @return void
     */
    private function recordSampleValue(array &$fieldAnalysis, mixed $value): void
    {
        // Convert value to string for storage
        $stringValue = $this->valueToString($value);
        
        if (!in_array($stringValue, $fieldAnalysis['sample_values']) && count($fieldAnalysis['sample_values']) < 20) {
            $fieldAnalysis['sample_values'][] = $stringValue;
        }

    }//end recordSampleValue()


    /**
     * Determine the type of a value
     *
     * @param mixed $value The value to analyze
     *
     * @phpstan-param mixed $value
     *
     * @psalm-param mixed $value
     *
     * @return string The determined type
     */
    private function determineValueType(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        
        if (is_bool($value)) {
            return 'boolean';
        }
        
        if (is_int($value)) {
            return 'integer';
        }
        
        if (is_float($value)) {
            return 'float';
        }
        
        if (is_string($value)) {
            // Check if it looks like a date
            if ($this->looksLikeDate($value)) {
                return 'date';
            }
            
            // Check if it's numeric
            if (is_numeric($value)) {
                return 'numeric_string';
            }
            
            return 'string';
        }
        
        return 'unknown';

    }//end determineValueType()


    /**
     * Check if a string value looks like a date
     *
     * @param string $value The string to check
     *
     * @phpstan-param string $value
     *
     * @psalm-param string $value
     *
     * @return bool True if it looks like a date
     */
    private function looksLikeDate(string $value): bool
    {
        // Common date patterns
        $datePatterns = [
            '/^\d{4}-\d{2}-\d{2}$/',                    // YYYY-MM-DD
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',  // ISO 8601
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',  // YYYY-MM-DD HH:MM:SS
            '/^\d{2}\/\d{2}\/\d{4}$/',                 // MM/DD/YYYY
            '/^\d{2}-\d{2}-\d{4}$/',                   // MM-DD-YYYY
        ];

        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;

    }//end looksLikeDate()


    /**
     * Convert a value to string representation
     *
     * @param mixed $value The value to convert
     *
     * @phpstan-param mixed $value
     *
     * @psalm-param mixed $value
     *
     * @return string String representation of the value
     */
    private function valueToString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        
        return (string) $value;

    }//end valueToString()


    /**
     * Determine field configuration based on analysis
     *
     * @param string $fieldPath The field path
     * @param array  $analysis  The field analysis data
     *
     * @phpstan-param string $fieldPath
     * @phpstan-param array<string, mixed> $analysis
     *
     * @psalm-param string $fieldPath
     * @psalm-param array<string, mixed> $analysis
     *
     * @return array|null Field configuration or null if not suitable for faceting
     */
    private function determineFieldConfiguration(string $fieldPath, array $analysis): ?array
    {
        // Skip nested objects and arrays of objects
        if ($analysis['is_nested']) {
            return null;
        }

        // Determine primary type
        $primaryType = $this->getPrimaryType($analysis['types']);
        
        if ($primaryType === null) {
            return null;
        }

        $config = [
            'type' => $primaryType,
            'description' => "Object field: $fieldPath",
            'sample_values' => array_slice($analysis['sample_values'], 0, 10),
            'appearance_rate' => $analysis['count']
        ];

        // Configure facet types based on field type
        switch ($primaryType) {
            case 'string':
                $uniqueValueCount = count($analysis['sample_values']);
                if ($uniqueValueCount <= 50) {
                    // Low cardinality - good for terms facet
                    $config['facet_types'] = ['terms'];
                    $config['cardinality'] = 'low';
                } else {
                    // High cardinality - not suitable for faceting
                    return null;
                }
                break;
                
            case 'integer':
            case 'float':
            case 'numeric_string':
                $config['facet_types'] = ['range', 'terms'];
                $config['cardinality'] = 'numeric';
                break;
                
            case 'date':
                $config['facet_types'] = ['date_histogram', 'range'];
                $config['intervals'] = ['day', 'week', 'month', 'year'];
                break;
                
            case 'boolean':
                $config['facet_types'] = ['terms'];
                $config['cardinality'] = 'binary';
                break;
                
            default:
                return null;
        }

        return $config;

    }//end determineFieldConfiguration()


    /**
     * Get the primary type from type analysis
     *
     * @param array $types Type counts from analysis
     *
     * @phpstan-param array<string, int> $types
     *
     * @psalm-param array<string, int> $types
     *
     * @return string|null The primary type or null if no clear primary type
     */
    private function getPrimaryType(array $types): ?string
    {
        if (empty($types)) {
            return null;
        }

        // Sort by count descending
        arsort($types);
        
        $totalCount = array_sum($types);
        $primaryType = array_key_first($types);
        $primaryCount = $types[$primaryType];
        
        // Primary type should represent at least 70% of values
        if ($primaryCount / $totalCount >= 0.7) {
            return $primaryType;
        }

        return null;

    }//end getPrimaryType()

}//end class 