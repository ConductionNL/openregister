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

use DateTime;
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
     * Handles arrays by creating separate facet buckets for each array element.
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
     * @return (((int|mixed|string)[]|mixed)[]|string)[] Terms facet data with buckets containing key and results
     *
     * @psalm-return array{type: 'terms',
     *     buckets: list{0?: array{key: mixed|string, results: int},...}>
     */
    public function getTermsFacet(string $field, array $baseQuery=[]): array
    {
        // Build JSON path for the field.
        $jsonPath = '$.'.$field;

        // First, check if this field commonly contains arrays.
        if ($this->fieldContainsArrays(field: $field, baseQuery: $baseQuery) === true) {
            return $this->getTermsFacetForArrayField(field: $field, baseQuery: $baseQuery);
        }

        // For non-array fields, use the standard approach.
        $queryBuilder = $this->db->getQueryBuilder();

        // Build aggregation query for JSON field.
        $jsonPathParam = $queryBuilder->createNamedParameter($jsonPath);
        $queryBuilder->selectAlias(
            $queryBuilder->createFunction("JSON_UNQUOTE(JSON_EXTRACT(object, ".$jsonPathParam."))"),
            'field_value'
        )
            ->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'doc_count')
            ->from('openregister_objects')
            ->where(
                $queryBuilder->expr()->isNotNull(
                    $queryBuilder->createFunction("JSON_EXTRACT(object, ".$jsonPathParam.")")
                )
            )
            ->groupBy('field_value')
            ->orderBy('doc_count', 'DESC');
        // Note: Still using doc_count in ORDER BY as it's the SQL alias
        // Apply base filters.
        $this->applyBaseFilters(queryBuilder: $queryBuilder, baseQuery: $baseQuery);

        $result  = $queryBuilder->executeQuery();
        $buckets = [];

        while (($row = $result->fetch()) !== false) {
            $key = $row['field_value'];
            if ($key !== null && $key !== '') {
                $buckets[] = [
                    'key'     => $key,
                    'results' => (int) $row['doc_count'],
                ];
            }
        }

        return [
            'type'    => 'terms',
            'buckets' => $buckets,
        ];
    }//end getTermsFacet()

    /**
     * Check if a field commonly contains arrays
     *
     * Samples a few objects to determine if the field typically contains arrays.
     *
     * @param string $field     The JSON field name
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
     * @return bool True if the field commonly contains arrays
     */
    private function fieldContainsArrays(string $field, array $baseQuery): bool
    {
        $queryBuilder = $this->db->getQueryBuilder();
        $jsonPath     = '$.'.$field;

        // Sample a few objects to check if the field contains arrays.
        $queryBuilder->select('object')
            ->from('openregister_objects')
            ->where(
                $queryBuilder->expr()->isNotNull(
                    $queryBuilder->createFunction("JSON_EXTRACT(object, ".$queryBuilder->createNamedParameter($jsonPath).")")
                )
            )
            ->setMaxResults(10);

        // Apply base filters.
        $this->applyBaseFilters(queryBuilder: $queryBuilder, baseQuery: $baseQuery);

        $result     = $queryBuilder->executeQuery();
        $arrayCount = 0;
        $totalCount = 0;

        while (($row = $result->fetch()) !== false) {
            $objectData = json_decode($row['object'], true);
            if (($objectData !== null) === true && (($objectData[$field] ?? null) !== null) === true) {
                $totalCount++;
                if (is_array($objectData[$field]) === true) {
                    $arrayCount++;
                }
            }
        }

        // If more than 50% of sampled objects have arrays for this field, treat it as an array field.
        return $totalCount > 0 && ($arrayCount / $totalCount) > 0.5;
    }//end fieldContainsArrays()

    /**
     * Get terms facet for an array field
     *
     * Expands JSON arrays into individual values and creates separate facet buckets.
     *
     * @param string $field     The JSON field name
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
     * @return ((int|string)[][]|string)[]
     *
     * @psalm-return array{type: 'terms',
     *     buckets: list<array{key: non-empty-string,
     *     results: 0|1|2|3|4}>}
     */
    private function getTermsFacetForArrayField(string $field, array $baseQuery): array
    {
        // Get all objects that have this field.
        $queryBuilder = $this->db->getQueryBuilder();
        $jsonPath     = '$.'.$field;

        $queryBuilder->select('object')
            ->from('openregister_objects')
            ->where(
                $queryBuilder->expr()->isNotNull(
                    $queryBuilder->createFunction("JSON_EXTRACT(object, ".$queryBuilder->createNamedParameter($jsonPath).")")
                )
            );

        // Apply base filters.
        $this->applyBaseFilters(queryBuilder: $queryBuilder, baseQuery: $baseQuery);

        $result      = $queryBuilder->executeQuery();
        $valueCounts = [];

        // Process each object to extract individual array values.
        while (($row = $result->fetch()) !== false) {
            $objectData = json_decode($row['object'], true);
            if (($objectData !== null) === true && (($objectData[$field] ?? null) !== null) === true) {
                $fieldValue = $objectData[$field];

                // Handle both arrays and single values.
                if (is_array($fieldValue) === false) {
                    // For single values, count normally.
                    $stringValue = $this->normalizeValue($fieldValue);
                    if ($stringValue !== null && $stringValue !== '') {
                        if (isset($valueCounts[$stringValue]) === false) {
                            $valueCounts[$stringValue] = 0;
                        }

                        $valueCounts[$stringValue]++;
                    }

                    continue;
                }

                // For arrays, count each element separately.
                foreach ($fieldValue as $value) {
                    $stringValue = $this->normalizeValue($value);
                    if ($stringValue !== null && $stringValue !== '') {
                        if (isset($valueCounts[$stringValue]) === false) {
                            $valueCounts[$stringValue] = 0;
                        }

                        $valueCounts[$stringValue]++;
                    }
                }//end foreach
            }//end if
        }//end while

        // Sort by count descending.
        arsort($valueCounts);

        // Convert to buckets format.
        $buckets = [];
        foreach ($valueCounts as $key => $count) {
            $buckets[] = [
                'key'     => $key,
                'results' => $count,
            ];
        }

        return [
            'type'    => 'terms',
            'buckets' => $buckets,
        ];
    }//end getTermsFacetForArrayField()

    /**
     * Normalize a value for faceting
     *
     * Converts various value types to a consistent string representation.
     *
     * @param mixed $value The value to normalize
     *
     * @phpstan-param mixed $value
     *
     * @psalm-param mixed $value
     *
     * @return string|null The normalized string value or null if invalid
     */
    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value) === true) {
            if ($value !== true) {
                return 'false';
            }

            return 'true';
        }

        if (is_scalar($value) === true) {
            return trim((string) $value);
        }

        // Skip complex types (objects, nested arrays).
        return null;
    }//end normalizeValue()

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
     * @return ((int|mixed)[][]|string)[]
     *
     * @psalm-return array{type: 'date_histogram', interval: string,
     *     buckets: list<array{key: mixed, results: int}>}
     */
    public function getDateHistogramFacet(string $field, string $interval, array $baseQuery=[]): array
    {
        $queryBuilder = $this->db->getQueryBuilder();

        $jsonPath   = '$.'.$field;
        $dateFormat = $this->getDateFormatForInterval($interval);

        $jsonPathParam = $queryBuilder->createNamedParameter($jsonPath);
        $dateFormatSql = "DATE_FORMAT(JSON_UNQUOTE(JSON_EXTRACT(object, ".$jsonPathParam.")), '$dateFormat')";
        $queryBuilder->selectAlias(
            $queryBuilder->createFunction($dateFormatSql),
            'date_key'
        )
            ->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'doc_count')
            ->from('openregister_objects')
            ->where(
                $queryBuilder->expr()->isNotNull(
                    $queryBuilder->createFunction("JSON_EXTRACT(object, ".$jsonPathParam.")")
                )
            )
            ->groupBy('date_key')
            ->orderBy('date_key', 'ASC');

        // Apply base filters.
        $this->applyBaseFilters(queryBuilder: $queryBuilder, baseQuery: $baseQuery);

        $result  = $queryBuilder->executeQuery();
        $buckets = [];

        while (($row = $result->fetch()) !== false) {
            if ($row['date_key'] !== null) {
                $buckets[] = [
                    'key'     => $row['date_key'],
                    'results' => (int) $row['doc_count'],
                ];
            }
        }

        return [
            'type'     => 'date_histogram',
            'interval' => $interval,
            'buckets'  => $buckets,
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
     * @return ((int|mixed|string)[][]|string)[]
     *
     * @psalm-return array{type: 'range',
     *     buckets: list<array{from?: mixed, key: string, results: int,
     *     to?: mixed}>}
     */
    public function getRangeFacet(string $field, array $ranges, array $baseQuery=[]): array
    {
        $buckets  = [];
        $jsonPath = '$.'.$field;

        foreach ($ranges as $range) {
            $queryBuilder  = $this->db->getQueryBuilder();
            $jsonPathParam = $queryBuilder->createNamedParameter($jsonPath);
            $extractSql    = "JSON_EXTRACT(object, ".$jsonPathParam.")";
            $castSql       = "CAST(JSON_UNQUOTE(".$extractSql.") AS DECIMAL(10,2))";

            $queryBuilder->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'doc_count')
                ->from('openregister_objects')
                ->where(
                    $queryBuilder->expr()->isNotNull(
                        $queryBuilder->createFunction($extractSql)
                    )
                );

            // Apply range conditions.
            if (($range['from'] ?? null) !== null) {
                $fromParam = $queryBuilder->createNamedParameter($range['from']);
                $queryBuilder->andWhere(
                    $queryBuilder->createFunction($castSql).' >= '.$fromParam
                );
            }

            if (($range['to'] ?? null) !== null) {
                $toParam = $queryBuilder->createNamedParameter($range['to']);
                $queryBuilder->andWhere(
                    $queryBuilder->createFunction($castSql).' < '.$toParam
                );
            }

            // Apply base filters.
            $this->applyBaseFilters(queryBuilder: $queryBuilder, baseQuery: $baseQuery);

            $result = $queryBuilder->executeQuery();
            $count  = (int) $result->fetchOne();

            // Generate range key.
            $key = $this->generateRangeKey($range);

            $bucket = [
                'key'     => $key,
                'results' => $count,
            ];

            if (($range['from'] ?? null) !== null) {
                $bucket['from'] = $range['from'];
            }

            if (($range['to'] ?? null) !== null) {
                $bucket['to'] = $range['to'];
            }

            $buckets[] = $bucket;
        }//end foreach

        return [
            'type'    => 'range',
            'buckets' => $buckets,
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
        $published      = $baseQuery['_published'] ?? false;
        $search         = $baseQuery['_search'] ?? null;
        $ids            = $baseQuery['_ids'] ?? null;

        // By default, only include objects where 'deleted' is NULL unless $includeDeleted is true.
        if ($includeDeleted === false) {
            $queryBuilder->andWhere($queryBuilder->expr()->isNull('deleted'));
        }

        // If published filter is set, only include objects that are currently published.
        if ($published === true) {
            $now = (new DateTime())->format('Y-m-d H:i:s');
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

        // Apply full-text search if provided.
        if ($search !== null && trim($search) !== '') {
            $this->applyFullTextSearch(queryBuilder: $queryBuilder, searchTerm: trim($search));
        }

        // Apply IDs filter if provided.
        if ($ids !== null && is_array($ids) === true && empty($ids) === false) {
            $this->applyIdsFilter(queryBuilder: $queryBuilder, ids: $ids);
        }

        // Apply metadata filters from @self.
        if (($baseQuery['@self'] ?? null) !== null && is_array($baseQuery['@self']) === true) {
            $this->applyMetadataFilters(queryBuilder: $queryBuilder, metadataFilters: $baseQuery['@self']);
        }

        // Apply JSON object field filters (non-@self filters).
        $objectFilters = array_filter(
            $baseQuery,
            function ($key) {
                return $key !== '@self' && !str_starts_with($key, '_');
            },
            ARRAY_FILTER_USE_KEY
        );

        if (empty($objectFilters) === false) {
            $this->applyObjectFieldFilters(queryBuilder: $queryBuilder, objectFilters: $objectFilters);
        }
    }//end applyBaseFilters()

    /**
     * Apply full-text search to the query builder
     *
     * This method applies the same full-text search logic used in the main search
     * to ensure facets are calculated within the context of the current search.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param string        $searchTerm   The search term to apply
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param string $searchTerm
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param string $searchTerm
     *
     * @return void
     */
    private function applyFullTextSearch(IQueryBuilder $queryBuilder, string $searchTerm): void
    {
        // Split search terms by ' OR ' to handle multiple search words.
        $searchTerms = array_filter(
            array_map('trim', explode(' OR ', $searchTerm)),
            function ($term) {
                return empty($term) === false;
            }
        );

        // If no valid search terms, return without modifying the query.
        if (empty($searchTerms) === true) {
            return;
        }

        // Create OR conditions for each search term.
        $orConditions = $queryBuilder->expr()->orX();

        foreach ($searchTerms as $term) {
            // Clean the search term - remove wildcards and convert to lowercase.
            $cleanTerm = strtolower(trim($term));
            $cleanTerm = str_replace(['*', '%'], '', $cleanTerm);

            // Skip empty terms after cleaning.
            if (empty($cleanTerm) === true) {
                continue;
            }

            // Use case-insensitive JSON_SEARCH with partial matching.
            // This ensures the search is case-insensitive and supports partial matches.
            $searchParam    = $queryBuilder->createNamedParameter('%'.$cleanTerm.'%');
            $searchFunction = "JSON_SEARCH(LOWER(`object`), 'all', ".$searchParam.")";

            $orConditions->add(
                $queryBuilder->expr()->isNotNull(
                    $queryBuilder->createFunction($searchFunction)
                )
            );
        }//end foreach

        // Add the OR conditions to the query if we have any valid terms.
        if ($orConditions->count() > 0) {
            $queryBuilder->andWhere($orConditions);
        }
    }//end applyFullTextSearch()

    /**
     * Apply IDs filter to the query builder
     *
     * This method filters objects by specific IDs or UUIDs, supporting both
     * integer IDs and string UUIDs in the same array.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param array         $ids          Array of IDs/UUIDs to filter by
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param array<int|string> $ids
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param array<int|string> $ids
     *
     * @return void
     */
    private function applyIdsFilter(IQueryBuilder $queryBuilder, array $ids): void
    {
        $integerIds = [];
        $stringIds  = [];

        // Separate integer IDs from string UUIDs.
        foreach ($ids as $id) {
            if (is_numeric($id) === false) {
                $stringIds[] = (string) $id;
                continue;
            }

            $integerIds[] = (int) $id;
        }

        // Create OR condition for ID or UUID matching.
        $orConditions = $queryBuilder->expr()->orX();

        // Add integer ID condition if we have any.
        if (empty($integerIds) === false) {
            $orConditions->add(
                $queryBuilder->expr()->in(
                    'id',
                    $queryBuilder->createNamedParameter($integerIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
                )
            );
        }

        // Add UUID condition if we have any.
        if (empty($stringIds) === false) {
            $orConditions->add(
                $queryBuilder->expr()->in(
                    'uuid',
                    $queryBuilder->createNamedParameter($stringIds, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                )
            );
        }

        // Apply the OR condition if we have any IDs to filter by.
        if ($orConditions->count() > 0) {
            $queryBuilder->andWhere($orConditions);
        }
    }//end applyIdsFilter()

    /**
     * Apply metadata filters with advanced operator support
     *
     * This method processes @self metadata filters with support for all advanced
     * operators: gt, lt, gte, lte, ne, ~, ^, $, ===, exists, empty, null
     *
     * @param IQueryBuilder $queryBuilder    The query builder to modify
     * @param array         $metadataFilters Array of metadata field filters
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param array<string, mixed> $metadataFilters
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param array<string, mixed> $metadataFilters
     *
     * @return void
     */
    private function applyMetadataFilters(IQueryBuilder $queryBuilder, array $metadataFilters): void
    {
        foreach ($metadataFilters as $field => $value) {
            // Handle simple values (backwards compatibility).
            if (is_array($value) === false) {
                if ($value === 'IS NOT NULL') {
                    $queryBuilder->andWhere($queryBuilder->expr()->isNotNull($field));
                    continue;
                }

                if ($value === 'IS NULL') {
                    $queryBuilder->andWhere($queryBuilder->expr()->isNull($field));
                    continue;
                }

                // Simple equals (case insensitive for strings).
                $queryBuilder->andWhere($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)));
                continue;
            }

            // Handle array of values (OR condition).
            if (($value[0] ?? null) !== null && is_string($value[0]) === false) {
                // This is an array of values, not operators.
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->in(
                        $field,
                        $queryBuilder->createNamedParameter(
                            $value,
                                \Doctrine\DBAL\Connection::PARAM_STR_ARRAY
                        )
                    )
                );
                continue;
            }

            // Handle operator-based filters.
            foreach ($value as $operator => $operatorValue) {
                $opParam = $queryBuilder->createNamedParameter($operatorValue);
                switch ($operator) {
                    case 'gt':
                        $queryBuilder->andWhere($queryBuilder->expr()->gt($field, $opParam));
                        break;
                    case 'lt':
                        $queryBuilder->andWhere($queryBuilder->expr()->lt($field, $opParam));
                        break;
                    case 'gte':
                        $queryBuilder->andWhere($queryBuilder->expr()->gte($field, $opParam));
                        break;
                    case 'lte':
                        $queryBuilder->andWhere($queryBuilder->expr()->lte($field, $opParam));
                        break;
                    case 'ne':
                        $queryBuilder->andWhere($queryBuilder->expr()->neq($field, $opParam));
                        break;
                    case '~':
                        // Contains (case insensitive).
                        $likeParam = $queryBuilder->createNamedParameter('%'.$operatorValue.'%');
                        $queryBuilder->andWhere($queryBuilder->expr()->like($field, $likeParam));
                        break;
                    case '^':
                        // Starts with (case insensitive).
                        $startsParam = $queryBuilder->createNamedParameter($operatorValue.'%');
                        $queryBuilder->andWhere($queryBuilder->expr()->like($field, $startsParam));
                        break;
                    case '$':
                        // Ends with (case insensitive).
                        $endsParam = $queryBuilder->createNamedParameter('%'.$operatorValue);
                        $queryBuilder->andWhere($queryBuilder->expr()->like($field, $endsParam));
                        break;
                    case '===':
                        // Exact match (case sensitive).
                        $queryBuilder->andWhere($queryBuilder->expr()->eq($field, $opParam));
                        break;
                    case 'exists':
                        if ($operatorValue !== true && $operatorValue !== 'true') {
                            $queryBuilder->andWhere($queryBuilder->expr()->isNull($field));
                            break;
                        }

                        $queryBuilder->andWhere($queryBuilder->expr()->isNotNull($field));
                        break;
                    case 'empty':
                        if ($operatorValue !== true && $operatorValue !== 'true') {
                            $queryBuilder->andWhere(
                                $queryBuilder->expr()->andX(
                                    $queryBuilder->expr()->isNotNull($field),
                                    $queryBuilder->expr()->neq($field, $queryBuilder->createNamedParameter(''))
                                )
                            );
                            break;
                        }

                        $queryBuilder->andWhere(
                            $queryBuilder->expr()->orX(
                                $queryBuilder->expr()->isNull($field),
                                $queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter(''))
                            )
                        );
                        break;
                    case 'null':
                        if ($operatorValue !== true && $operatorValue !== 'true') {
                            $queryBuilder->andWhere($queryBuilder->expr()->isNotNull($field));
                            break;
                        }

                        $queryBuilder->andWhere($queryBuilder->expr()->isNull($field));
                        break;
                    default:
                        // Default to equals for unknown operators.
                        $defaultParam = $queryBuilder->createNamedParameter($operatorValue);
                        $queryBuilder->andWhere($queryBuilder->expr()->eq($field, $defaultParam));
                        break;
                }//end switch
            }//end foreach
        }//end foreach
    }//end applyMetadataFilters()

    /**
     * Apply object field filters with advanced operator support
     *
     * This method processes object field filters with support for all advanced
     * operators: gt, lt, gte, lte, ne, ~, ^, $, ===, exists, empty, null
     *
     * @param IQueryBuilder $queryBuilder  The query builder to modify
     * @param array         $objectFilters Array of object field filters
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param array<string, mixed> $objectFilters
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param array<string, mixed> $objectFilters
     *
     * @return void
     */
    private function applyObjectFieldFilters(IQueryBuilder $queryBuilder, array $objectFilters): void
    {
        foreach ($objectFilters as $field => $value) {
            $jsonPath = '$.'.$field;

            $jsonPathParam = $queryBuilder->createNamedParameter($jsonPath);
            $extractSql    = "JSON_EXTRACT(object, ".$jsonPathParam.")";

            // Handle simple values (backwards compatibility).
            if (is_array($value) === false) {
                if ($value === 'IS NOT NULL') {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->isNotNull(
                            $queryBuilder->createFunction($extractSql)
                        )
                    );
                    continue;
                }

                if ($value === 'IS NULL') {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->isNull(
                            $queryBuilder->createFunction($extractSql)
                        )
                    );
                    continue;
                }

                // Simple equals with both exact match and array containment.
                $this->applySimpleObjectFieldFilter(queryBuilder: $queryBuilder, jsonPath: $jsonPath, value: $value);
                continue;
            }//end if

            // Handle array of values (OR condition) - backwards compatibility.
            if (($value[0] ?? null) !== null && is_string($value[0]) === false) {
                // This is an array of values, not operators.
                $orConditions = $queryBuilder->expr()->orX();
                foreach ($value as $val) {
                    $this->addObjectFieldValueCondition(
                        queryBuilder: $queryBuilder,
                        conditions: $orConditions,
                        jsonPath: $jsonPath,
                        value: $val
                    );
                }

                $queryBuilder->andWhere($orConditions);
                continue;
            }

            // Handle operator-based filters.
            foreach ($value as $operator => $operatorValue) {
                $this->applyObjectFieldOperator(
                    queryBuilder: $queryBuilder,
                    jsonPath: $jsonPath,
                    operator: $operator,
                    operatorValue: $operatorValue
                );
            }
        }//end foreach
    }//end applyObjectFieldFilters()

    /**
     * Apply simple object field filter (backwards compatibility)
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param string        $jsonPath     The JSON path to the field
     * @param mixed         $value        The value to filter by
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param string $jsonPath
     * @phpstan-param mixed $value
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param string $jsonPath
     * @psalm-param mixed $value
     *
     * @return void
     */
    private function applySimpleObjectFieldFilter(IQueryBuilder $queryBuilder, string $jsonPath, mixed $value): void
    {
        $singleValueConditions = $queryBuilder->expr()->orX();
        $this->addObjectFieldValueCondition(
            queryBuilder: $queryBuilder,
            conditions: $singleValueConditions,
            jsonPath: $jsonPath,
            value: $value
        );
        $queryBuilder->andWhere($singleValueConditions);
    }//end applySimpleObjectFieldFilter()

    /**
     * Add object field value condition (exact match and array containment)
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param mixed         $conditions   The conditions object to add to
     * @param string        $jsonPath     The JSON path to the field
     * @param mixed         $value        The value to match
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param mixed $conditions
     * @phpstan-param string $jsonPath
     * @phpstan-param mixed $value
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param mixed $conditions
     * @psalm-param string $jsonPath
     * @psalm-param mixed $value
     *
     * @return void
     */
    private function addObjectFieldValueCondition(
        IQueryBuilder $queryBuilder,
        mixed $conditions,
        string $jsonPath,
        mixed $value
    ): void {
        $jsonPathParam = $queryBuilder->createNamedParameter($jsonPath);
        $valueParam    = $queryBuilder->createNamedParameter($value);
        $unquoteSql    = "JSON_UNQUOTE(JSON_EXTRACT(object, ".$jsonPathParam."))";

        // Check for exact match (single value).
        $conditions->add(
            $queryBuilder->expr()->eq(
                $queryBuilder->createFunction($unquoteSql),
                $valueParam
            )
        );

        // Check if the value exists within an array using JSON_CONTAINS.
        $extractSql       = "JSON_EXTRACT(object, ".$jsonPathParam.")";
        $jsonEncodedValue = $queryBuilder->createNamedParameter(json_encode($value));
        $containsSql      = "JSON_CONTAINS(".$extractSql.", ".$jsonEncodedValue.")";
        $conditions->add(
            $queryBuilder->expr()->eq(
                $queryBuilder->createFunction($containsSql),
                $queryBuilder->createNamedParameter(1)
            )
        );
    }//end addObjectFieldValueCondition()

    /**
     * Apply object field operator
     *
     * @param IQueryBuilder $queryBuilder  The query builder to modify
     * @param string        $jsonPath      The JSON path to the field
     * @param string        $operator      The operator to apply
     * @param mixed         $operatorValue The value for the operator
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param string $jsonPath
     * @phpstan-param string $operator
     * @phpstan-param mixed $operatorValue
     *
     * @psalm-param IQueryBuilder $queryBuilder
     * @psalm-param string $jsonPath
     * @psalm-param string $operator
     * @psalm-param mixed $operatorValue
     *
     * @return void
     */
    private function applyObjectFieldOperator(
        IQueryBuilder $queryBuilder,
        string $jsonPath,
        string $operator,
        mixed $operatorValue
    ): void {
        $jsonPathParam = $queryBuilder->createNamedParameter($jsonPath);
        $extractSql    = "JSON_EXTRACT(object, ".$jsonPathParam.")";
        $unquoteSql    = "JSON_UNQUOTE(".$extractSql.")";
        $jsonExtract   = $queryBuilder->createFunction($unquoteSql);
        $opParam       = $queryBuilder->createNamedParameter($operatorValue);

        switch ($operator) {
            case 'gt':
                $queryBuilder->andWhere($queryBuilder->expr()->gt($jsonExtract, $opParam));
                break;
            case 'lt':
                $queryBuilder->andWhere($queryBuilder->expr()->lt($jsonExtract, $opParam));
                break;
            case 'gte':
                $queryBuilder->andWhere($queryBuilder->expr()->gte($jsonExtract, $opParam));
                break;
            case 'lte':
                $queryBuilder->andWhere($queryBuilder->expr()->lte($jsonExtract, $opParam));
                break;
            case 'ne':
                $queryBuilder->andWhere($queryBuilder->expr()->neq($jsonExtract, $opParam));
                break;
            case '~':
                // Contains (case insensitive).
                $likeParam = $queryBuilder->createNamedParameter('%'.$operatorValue.'%');
                $queryBuilder->andWhere($queryBuilder->expr()->like($jsonExtract, $likeParam));
                break;
            case '^':
                // Starts with (case insensitive).
                $startsParam = $queryBuilder->createNamedParameter($operatorValue.'%');
                $queryBuilder->andWhere($queryBuilder->expr()->like($jsonExtract, $startsParam));
                break;
            case '$':
                // Ends with (case insensitive).
                $endsParam = $queryBuilder->createNamedParameter('%'.$operatorValue);
                $queryBuilder->andWhere($queryBuilder->expr()->like($jsonExtract, $endsParam));
                break;
            case '===':
                // Exact match (case sensitive).
                $queryBuilder->andWhere($queryBuilder->expr()->eq($jsonExtract, $opParam));
                break;
            case 'exists':
                $extractFunc = $queryBuilder->createFunction($extractSql);
                if ($operatorValue !== true && $operatorValue !== 'true') {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->isNull($extractFunc)
                    );
                    break;
                }

                $queryBuilder->andWhere(
                    $queryBuilder->expr()->isNotNull($extractFunc)
                );
                break;
            case 'empty':
                $extractFunc = $queryBuilder->createFunction($extractSql);
                $emptyParam  = $queryBuilder->createNamedParameter('');
                if ($operatorValue !== true && $operatorValue !== 'true') {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->andX(
                            $queryBuilder->expr()->isNotNull($extractFunc),
                            $queryBuilder->expr()->neq($jsonExtract, $emptyParam)
                        )
                    );
                    break;
                }

                $queryBuilder->andWhere(
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->isNull($extractFunc),
                        $queryBuilder->expr()->eq($jsonExtract, $emptyParam)
                    )
                );
                break;
            case 'null':
                $extractFunc = $queryBuilder->createFunction($extractSql);
                if ($operatorValue !== true && $operatorValue !== 'true') {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->isNotNull($extractFunc)
                    );
                    break;
                }

                $queryBuilder->andWhere(
                    $queryBuilder->expr()->isNull($extractFunc)
                );
                break;
            default:
                // Default to simple filter for unknown operators.
                $this->applySimpleObjectFieldFilter(
                    queryBuilder: $queryBuilder,
                    jsonPath: $jsonPath,
                    value: $operatorValue
                );
                break;
        }//end switch
    }//end applyObjectFieldOperator()

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
        if (($range['from'] ?? null) !== null && (($range['to'] ?? null) !== null) === true) {
            return $range['from'].'-'.$range['to'];
        }

        if (($range['from'] ?? null) !== null) {
            return $range['from'].'+';
        }

        if (($range['to'] ?? null) !== null) {
            return '0-'.$range['to'];
        }

        return 'all';
    }//end generateRangeKey()

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
     * @return array[]
     *
     * @psalm-return list<array>
     */
    private function getSampleObjects(array $baseQuery, int $sampleSize): array
    {
        $queryBuilder = $this->db->getQueryBuilder();

        $queryBuilder->select('object')
            ->from('openregister_objects')
            ->where($queryBuilder->expr()->isNotNull('object'))
            ->setMaxResults($sampleSize);

        // Apply base filters.
        $this->applyBaseFilters(queryBuilder: $queryBuilder, baseQuery: $baseQuery);

        $result  = $queryBuilder->executeQuery();
        $objects = [];

        while (($row = $result->fetch()) !== false) {
            $objectData = json_decode($row['object'], true);
            if (is_array($objectData) === true) {
                $objects[] = $objectData;
            }
        }

        return $objects;
    }//end getSampleObjects()

    /**
     * Analyze fields in an object recursively
     *
     * @param array  $objectData    The object data to analyze
     * @param array  $fieldAnalysis Reference to field analysis array
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
    private function analyzeObjectFields(array $objectData, array &$fieldAnalysis, string $prefix='', int $depth=0): void
    {
        // Limit recursion depth to avoid infinite loops and performance issues.
        if ($depth > 2) {
            return;
        }

        foreach ($objectData as $key => $value) {
            $fieldPath = $prefix.'.'.$key;
            if ($prefix === '') {
                $fieldPath = $key;
            }

            // Skip system fields.
            if (str_starts_with($key, '@') === true || str_starts_with($key, '_') === true) {
                continue;
            }

            // Initialize field analysis if not exists.
            if (isset($fieldAnalysis[$fieldPath]) === false) {
                $fieldAnalysis[$fieldPath] = [
                    'count'         => 0,
                    'types'         => [],
                    'sample_values' => [],
                    'is_array'      => false,
                    'is_nested'     => false,
                    'unique_values' => 0,
                ];
            }

            $fieldAnalysis[$fieldPath]['count']++;

            // Analyze value type and characteristics.
            if (is_array($value) === true) {
                $fieldAnalysis[$fieldPath]['is_array'] = true;

                // Check if it's an array of objects (nested structure).
                if (empty($value) === false && is_array($value[0]) === true) {
                    $fieldAnalysis[$fieldPath]['is_nested'] = true;
                    // Recursively analyze nested objects.
                    $this->analyzeObjectFields(
                        objectData: $value[0],
                        fieldAnalysis: $fieldAnalysis,
                        prefix: $fieldPath,
                        depth: $depth + 1
                    );
                    continue;
                }

                // Array of simple values - not nested.
                foreach ($value as $item) {
                    $this->recordValueType(fieldAnalysis: $fieldAnalysis[$fieldPath], value: $item);
                    $this->recordSampleValue(fieldAnalysis: $fieldAnalysis[$fieldPath], value: $item);
                }

                continue;
            }//end if

            if (is_object($value) === true) {
                $fieldAnalysis[$fieldPath]['is_nested'] = true;
                // Recursively analyze nested object.
                // Note: is_object($value) and is_array($value) are mutually exclusive.
                // This code path handles objects that are not arrays.
                // For array-like objects, convert to array first.
                if (method_exists($value, '__toArray') === true) {
                    $valueArray = (array) $value->__toArray();
                    $this->analyzeObjectFields(
                        objectData: $valueArray,
                        fieldAnalysis: $fieldAnalysis,
                        prefix: $fieldPath,
                        depth: $depth + 1
                    );
                }//end if

                continue;
            }//end if

            // Simple value.
            $this->recordValueType(fieldAnalysis: $fieldAnalysis[$fieldPath], value: $value);
            $this->recordSampleValue(fieldAnalysis: $fieldAnalysis[$fieldPath], value: $value);
        }//end foreach
    }//end analyzeObjectFields()

    /**
     * Record the type of a value in field analysis
     *
     * @param array $fieldAnalysis Reference to field analysis data
     * @param mixed $value         The value to analyze
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

        if (isset($fieldAnalysis['types'][$type]) === false) {
            $fieldAnalysis['types'][$type] = 0;
        }

        $fieldAnalysis['types'][$type]++;
    }//end recordValueType()

    /**
     * Record a sample value in field analysis
     *
     * @param array $fieldAnalysis Reference to field analysis data
     * @param mixed $value         The value to record
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
        // Convert value to string for storage.
        $stringValue = $this->valueToString($value);

        $isNotInSamples = in_array($stringValue, $fieldAnalysis['sample_values'], true) === false;
        $hasRoomForMore = count($fieldAnalysis['sample_values']) < 20;
        if ($isNotInSamples === true && $hasRoomForMore === true) {
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

        if (is_bool($value) === true) {
            return 'boolean';
        }

        if (is_int($value) === true) {
            return 'integer';
        }

        if (is_float($value) === true) {
            return 'float';
        }

        if (is_string($value) === true) {
            // Check if it looks like a date.
            if ($this->looksLikeDate($value) === true) {
                return 'date';
            }

            // Check if it's numeric.
            if (is_numeric($value) === true) {
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
        // Common date patterns.
        $datePatterns = [
            '/^\d{4}-\d{2}-\d{2}$/',
        // YYYY-MM-DD.
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
        // ISO 8601.
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',
        // YYYY-MM-DD HH:MM:SS.
            '/^\d{2}\/\d{2}\/\d{4}$/',
        // MM/DD/YYYY.
            '/^\d{2}-\d{2}-\d{4}$/',
        // MM-DD-YYYY.
        ];

        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, $value) === true) {
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

        if (is_bool($value) === true) {
            $result = 'true';
            if ($value !== true) {
                $result = 'false';
            }

            return $result;
        }

        if (is_array($value) === true || is_object($value) === true) {
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
     * @return (array|false|mixed|string)[]|null
     *
     * @psalm-return array{type: string, description: string,
     *     sample_values: array, appearance_rate: mixed, is_array: false|mixed,
     *     facet_types: list{0: 'date_histogram'|'range'|'terms',
     *     1?: 'range'|'terms'}, cardinality?: 'binary'|'low'|'numeric',
     *     intervals?: list{'day', 'week', 'month', 'year'}}|null
     */
    private function determineFieldConfiguration(string $fieldPath, array $analysis): array|null
    {
        // Skip nested objects and arrays of objects, but allow arrays of simple values.
        if (($analysis['is_nested'] === true) && $this->isArrayOfSimpleValues($analysis) === false) {
            return null;
        }

        // Determine primary type.
        $primaryType = $this->getPrimaryType($analysis['types']);

        if ($primaryType === null) {
            return null;
        }

        $config = [
            'type'            => $primaryType,
            'description'     => "Object field: $fieldPath",
            'sample_values'   => array_slice($analysis['sample_values'], 0, 10),
            'appearance_rate' => $analysis['count'],
            'is_array'        => $analysis['is_array'] ?? false,
        ];

        // Configure facet types based on field type.
        switch ($primaryType) {
            case 'string':
                $uniqueValueCount = count($analysis['sample_values']);
                if ($uniqueValueCount > 50) {
                    // High cardinality - not suitable for faceting.
                    return null;
                }

                // Low cardinality - good for terms facet.
                $config['facet_types'] = ['terms'];
                $config['cardinality'] = 'low';
                break;

            case 'integer':
            case 'float':
            case 'numeric_string':
                $config['facet_types'] = ['range', 'terms'];
                $config['cardinality'] = 'numeric';
                break;

            case 'date':
                $config['facet_types'] = ['date_histogram', 'range'];
                $config['intervals']   = ['day', 'week', 'month', 'year'];
                break;

            case 'boolean':
                $config['facet_types'] = ['terms'];
                $config['cardinality'] = 'binary';
                break;

            default:
                return null;
        }//end switch

        return $config;
    }//end determineFieldConfiguration()

    /**
     * Check if an analysis represents an array of simple values
     *
     * @param array $analysis The field analysis data
     *
     * @phpstan-param array<string, mixed> $analysis
     *
     * @psalm-param array<string, mixed> $analysis
     *
     * @return bool True if this is an array of simple values (not nested objects)
     */
    private function isArrayOfSimpleValues(array $analysis): bool
    {
        // If it's not an array, it's not an array of simple values.
        if (($analysis['is_array'] ?? false) === false) {
            return false;
        }

        // If it's nested, check if the types are simple.
        if ($analysis['is_nested'] ?? false) {
            $types = $analysis['types'] ?? [];

            // Check if all types are simple (string, integer, float, boolean, numeric_string, date).
            $simpleTypes = ['string', 'integer', 'float', 'boolean', 'numeric_string', 'date'];

            // If types array is empty, consider it valid (no types to check).
            if (empty($types) === true) {
                return true;
            }

            foreach (array_keys($types) as $type) {
                if (in_array($type, $simpleTypes, true) === false) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }//end isArrayOfSimpleValues()

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
        if (empty($types) === true) {
            return null;
        }

        // Sort by count descending.
        arsort($types);

        $totalCount   = array_sum($types);
        $primaryType  = array_key_first($types);
        $primaryCount = $types[$primaryType];

        // Primary type should represent at least 70% of values.
        if ($primaryCount / $totalCount >= 0.7) {
            return $primaryType;
        }

        return null;
    }//end getPrimaryType()
}//end class
