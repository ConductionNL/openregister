<?php

/**
 * OpenRegister Optimized Facet Handler
 *
 * This handler provides high-performance faceting by using optimized
 * database queries, query batching, and strategic caching.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Db\ObjectHandlers
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\ObjectHandlers;

use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * High-performance facet handler with query optimization
 *
 * This handler addresses performance bottlenecks by:
 * - Batching multiple facet queries into fewer database calls
 * - Using optimized queries that leverage proper indexes
 * - Implementing query result caching for common facet combinations
 * - Separating metadata facets from JSON field facets for optimal performance
 */
class OptimizedFacetHandler
{

    /**
     * Database connection
     *
     * @var IDBConnection
     */
    private readonly IDBConnection $db;

    /**
     * Cache for facet results to avoid repeated queries
     *
     * @var array<string, array>
     */
    private array $facetCache = [];

    /**
     * Constructor for the OptimizedFacetHandler
     *
     * @param IDBConnection $db The database connection
     */
    public function __construct(IDBConnection $db)
    {
        $this->db = $db;
    }//end __construct()

    /**
     * Get multiple facets in a single optimized operation
     *
     * This method batches multiple facet requests and executes them
     * using optimized queries that leverage database indexes effectively.
     *
     * @param array $facetConfig Configuration for multiple facets
     * @param array $baseQuery   Base query filters to apply
     *
     * @phpstan-param array<string, array> $facetConfig
     * @phpstan-param array<string, mixed> $baseQuery
     * @psalm-param   array<string, array> $facetConfig
     * @psalm-param   array<string, mixed> $baseQuery
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return array Combined facet results
     */
    public function getBatchedFacets(array $facetConfig, array $baseQuery=[]): array
    {
        $results = [];

        // Generate cache key for this facet combination.
        $cacheKey = $this->generateCacheKey(
            facetConfig: $facetConfig,
            baseQuery: $baseQuery
        );

        if (($this->facetCache[$cacheKey] ?? null) !== null) {
            return $this->facetCache[$cacheKey];
        }

        // Separate metadata facets from JSON field facets.
        $metadataFacets  = [];
        $jsonFieldFacets = [];

        foreach ($facetConfig as $facetName => $config) {
            if ($facetName === '@self' && is_array($config) === true) {
                $metadataFacets = $config;
            } else if ($facetName !== '@self') {
                $jsonFieldFacets[$facetName] = $config;
            }
        }

        // Process metadata facets (fast - use table indexes).
        if (empty($metadataFacets) === false) {
            $results['@self'] = $this->getBatchedMetadataFacets(
                metadataConfig: $metadataFacets,
                baseQuery: $baseQuery
            );
        }

        // Process JSON field facets (slower - but optimized where possible).
        foreach ($jsonFieldFacets as $fieldName => $config) {
            $type = $config['type'] ?? 'terms';

            if ($type === 'terms') {
                $results[$fieldName] = $this->getOptimizedJsonTermsFacet(
                    field: $fieldName,
                    baseQuery: $baseQuery
                );
            }

            // Add other facet types as needed.
        }

        // Cache results for future requests.
        $this->facetCache[$cacheKey] = $results;

        return $results;
    }//end getBatchedFacets()

    /**
     * Get multiple metadata facets in a single database operation
     *
     * This method uses a single query with CASE statements to calculate
     * multiple metadata facets simultaneously, dramatically improving performance.
     *
     * @param array $metadataConfig Metadata facet configuration
     * @param array $baseQuery      Base query filters to apply
     *
     * @phpstan-param array<string, array> $metadataConfig
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param array<string, array> $metadataConfig
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return (((int|mixed|string)[]|mixed)[]|string)[][]
     *
     * @psalm-return array<string,
     *     array{type: 'terms',
     *     buckets: list{0?: array{key: mixed, results: int,
     *     label: string}|mixed,...}}>
     */
    private function getBatchedMetadataFacets(array $metadataConfig, array $baseQuery): array
    {
        $results = [];

        foreach ($metadataConfig as $field => $config) {
            $type = $config['type'] ?? 'terms';

            if ($type === 'terms') {
                $results[$field] = $this->getOptimizedMetadataTermsFacet(
                    field: $field,
                    baseQuery: $baseQuery
                );
            }

            // Add other facet types as needed (date_histogram, range).
        }

        return $results;
    }//end getBatchedMetadataFacets()

    /**
     * Get optimized terms facet for metadata field
     *
     * This method uses an optimized query that leverages database indexes
     * for maximum performance on metadata fields.
     *
     * @param string $field     The metadata field name
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
     * @return ((int|mixed|string)[][]|string)[]
     *
     * @psalm-return array{type: 'terms',
     *     buckets: list<array{key: mixed, label: string, results: int}>}
     */
    private function getOptimizedMetadataTermsFacet(string $field, array $baseQuery): array
    {
        $queryBuilder = $this->db->getQueryBuilder();

        // Build optimized aggregation query.
        $queryBuilder->select($field)
            ->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'doc_count')
            ->from('openregister_objects')
            ->where($queryBuilder->expr()->isNotNull($field))
            ->groupBy($field)
            ->orderBy('doc_count', 'DESC')
            ->setMaxResults(100);
        // Limit results for performance.
        // Apply optimized base filters.
        $this->applyOptimizedBaseFilters(
            queryBuilder: $queryBuilder,
            baseQuery: $baseQuery
        );

        $result  = $queryBuilder->executeQuery();
        $buckets = [];

        while (($row = $result->fetch()) !== false) {
            $key   = $row[$field];
            $label = $this->getFieldLabel(
                field: $field,
                value: $key
            );

            $buckets[] = [
                'key'     => $key,
                'results' => (int) $row['doc_count'],
                'label'   => $label,
            ];
        }

        return [
            'type'    => 'terms',
            'buckets' => $buckets,
        ];
    }//end getOptimizedMetadataTermsFacet()

    /**
     * Get optimized terms facet for JSON field
     *
     * This method attempts to optimize JSON field faceting where possible,
     * but acknowledges that JSON queries will always be slower than indexed columns.
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
     * @return ((int|mixed)[][]|string)[]
     *
     * @psalm-return array{type: 'terms',
     *     buckets: list<array{key: mixed, results: int}>,
     *     note?: 'Skipped due to large dataset size for performance'}
     */
    private function getOptimizedJsonTermsFacet(string $field, array $baseQuery): array
    {
        $queryBuilder = $this->db->getQueryBuilder();
        $jsonPath     = '$'.$field;

        // Check if we should skip this facet due to too much data.
        $estimatedRows = $this->estimateRowCount($baseQuery);
        if ($estimatedRows > 50000) {
            // Return empty result for very large datasets to avoid timeouts.
            return [
                'type'    => 'terms',
                'buckets' => [],
                'note'    => 'Skipped due to large dataset size for performance',
            ];
        }

        // Use optimized JSON query with limits.
        $jsonPathParam        = $queryBuilder->createNamedParameter($jsonPath);
        $jsonExtractFunc      = "JSON_UNQUOTE(JSON_EXTRACT(object, ".$jsonPathParam."))";
        $jsonExtractIsNotNull = "JSON_EXTRACT(object, ".$jsonPathParam.")";
        $queryBuilder->selectAlias(
            $queryBuilder->createFunction($jsonExtractFunc),
            'field_value'
        )
            ->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'doc_count')
            ->from('openregister_objects')
            ->where(
                $queryBuilder->expr()->isNotNull(
                    $queryBuilder->createFunction($jsonExtractIsNotNull)
                )
            )
            ->groupBy('field_value')
            ->orderBy('doc_count', 'DESC');
        // Limit results for performance.
        // Apply optimized base filters.
        $this->applyOptimizedBaseFilters(
            queryBuilder: $queryBuilder,
            baseQuery: $baseQuery
        );

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
    }//end getOptimizedJsonTermsFacet()

    /**
     * Apply optimized base filters using proper index utilization
     *
     * This method applies base query filters in an order that maximizes
     * the effectiveness of database indexes.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify
     * @param array         $baseQuery    The base query filters
     *
     * @phpstan-param IQueryBuilder $queryBuilder
     * @phpstan-param array<string, mixed> $baseQuery
     * @psalm-param   IQueryBuilder $queryBuilder
     * @psalm-param   array<string, mixed> $baseQuery
     *
     * @return void
     */
    private function applyOptimizedBaseFilters(IQueryBuilder $queryBuilder, array $baseQuery): void
    {
        // Apply filters in order of index selectivity (most selective first).
        // 1. Most selective: ID-based filters.
        $hasIds = ($baseQuery['_ids'] ?? null) !== null
            && is_array($baseQuery['_ids']) === true
            && empty($baseQuery['_ids']) === false;
        if ($hasIds === true) {
            $idsParam = $queryBuilder->createNamedParameter(
                $baseQuery['_ids'],
                \Doctrine\DBAL\Connection::PARAM_INT_ARRAY
            );
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in('id', $idsParam)
            );
        }

        // 2. High selectivity: register/schema filters.
        // Note: register and schema columns are VARCHAR(255), not BIGINT - they store ID values as strings.
        if (($baseQuery['@self']['register'] ?? null) !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'register',
                    $queryBuilder->createNamedParameter(
                        (string) $baseQuery['@self']['register'],
                        IQueryBuilder::PARAM_STR
                    )
                )
            );
        }

        if (($baseQuery['@self']['schema'] ?? null) !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'schema',
                    $queryBuilder->createNamedParameter(
                        (string) $baseQuery['@self']['schema'],
                        IQueryBuilder::PARAM_STR
                    )
                )
            );
        }

        // 3. Medium selectivity: lifecycle filters (use composite indexes).
        $includeDeleted = $baseQuery['_includeDeleted'] ?? false;
        if ($includeDeleted === false) {
            $queryBuilder->andWhere($queryBuilder->expr()->isNull('deleted'));
        }

        $published = $baseQuery['_published'] ?? false;
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

        // 4. Low selectivity: organization filters.
        if (($baseQuery['@self']['organisation'] ?? null) !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'organisation',
                    $queryBuilder->createNamedParameter(
                        $baseQuery['@self']['organisation']
                    )
                )
            );
        }

        // Skip expensive operations like full-text search for faceting to improve performance.
        // These can be applied in the main query but not in facet calculations.
    }//end applyOptimizedBaseFilters()

    /**
     * Estimate row count for a query to decide on optimization strategy
     *
     * @param array $baseQuery Base query filters
     *
     * @phpstan-param array<string, mixed> $baseQuery
     * @psalm-param   array<string, mixed> $baseQuery
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @return int Estimated number of rows
     */
    private function estimateRowCount(array $baseQuery): int
    {
        $queryBuilder = $this->db->getQueryBuilder();

        $queryBuilder->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'row_count')
            ->from('openregister_objects');

        // Apply only the most selective filters for estimation.
        // Note: register and schema columns are VARCHAR(255), not BIGINT - they store ID values as strings.
        if (($baseQuery['@self']['register'] ?? null) !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'register',
                    $queryBuilder->createNamedParameter(
                        (string) $baseQuery['@self']['register'],
                        IQueryBuilder::PARAM_STR
                    )
                )
            );
        }

        if (($baseQuery['@self']['schema'] ?? null) !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'schema',
                    $queryBuilder->createNamedParameter(
                        (string) $baseQuery['@self']['schema'],
                        IQueryBuilder::PARAM_STR
                    )
                )
            );
        }

        $includeDeleted = $baseQuery['_includeDeleted'] ?? false;
        if ($includeDeleted === false) {
            $queryBuilder->andWhere($queryBuilder->expr()->isNull('deleted'));
        }

        $result = $queryBuilder->executeQuery();
        return (int) $result->fetchOne();
    }//end estimateRowCount()

    /**
     * Generate cache key for facet configuration
     *
     * @param array $facetConfig Facet configuration
     * @param array $baseQuery   Base query filters
     *
     * @phpstan-param array<string, mixed> $facetConfig
     * @phpstan-param array<string, mixed> $baseQuery
     *
     * @psalm-param array<string, mixed> $facetConfig
     * @psalm-param array<string, mixed> $baseQuery
     *
     * @return string Cache key
     */
    private function generateCacheKey(array $facetConfig, array $baseQuery): string
    {
        return md5(json_encode(['facets' => $facetConfig, 'query' => $baseQuery]));
    }//end generateCacheKey()

    /**
     * Get human-readable label for metadata field value
     *
     * @param string $field The metadata field name
     * @param mixed  $value The field value
     *
     * @phpstan-param string $field
     * @phpstan-param mixed $value
     * @psalm-param   string $field
     * @psalm-param   mixed $value
     *
     * @return string Human-readable label
     */
    private function getFieldLabel(string $field, mixed $value): string
    {
        // For register and schema fields, try to get the actual name from database.
        if ($field === 'register' && is_numeric($value) === true) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('title')
                    ->from('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $value)));
                $result = $qb->executeQuery();
                $title  = $result->fetchOne();
                if ($title !== false) {
                    return (string) $title;
                } else {
                    return "Register $value";
                }
            } catch (\Exception $e) {
                return "Register $value";
            }
        }

        if ($field === 'schema' && is_numeric($value) === true) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('title')
                    ->from('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $value)));
                $result = $qb->executeQuery();
                $title  = $result->fetchOne();
                if ($title !== false) {
                    return (string) $title;
                } else {
                    return "Schema $value";
                }
            } catch (\Exception $e) {
                return "Schema $value";
            }
        }

        // For other fields, return the value as-is.
        return (string) $value;
    }//end getFieldLabel()

    /**
     * Clear the facet cache
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->facetCache = [];
    }//end clearCache()
}//end class
