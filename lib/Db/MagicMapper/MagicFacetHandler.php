<?php

/**
 * MagicMapper Facet Handler
 *
 * This handler provides advanced faceting and aggregation capabilities for dynamic
 * schema-based tables. It implements sophisticated faceting functionality including
 * terms facets, date histograms, range facets, and statistical aggregations
 * optimized for schema-specific table structures.
 *
 * KEY RESPONSIBILITIES:
 * - Terms faceting for categorical data in dynamic tables
 * - Date histogram faceting for temporal data analysis
 * - Range faceting for numerical data analysis
 * - Statistical aggregations (min, max, avg, sum, count)
 * - Schema-aware faceting with automatic field discovery
 * - Optimized facet queries for performance
 *
 * FACETING CAPABILITIES:
 * - Metadata facets (register, schema, owner, organization, etc.)
 * - Schema property facets based on JSON schema definitions
 * - Combined faceting with complex filtering
 * - Cardinality estimation for facet optimization
 * - Multi-level aggregations and drill-down support
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper faceting capabilities
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use DateTime;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Faceting and aggregation handler for MagicMapper dynamic tables
 *
 * This class provides comprehensive faceting functionality for dynamically created
 * schema-based tables, offering better performance than generic table faceting
 * due to schema-specific optimizations.
 */
class MagicFacetHandler
{
    /**
     * Constructor for MagicFacetHandler
     *
     * @param IDBConnection   $db     Database connection for queries
     * @param LoggerInterface $logger Logger for debugging and error reporting
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Get facet data for a metadata field
     *
     * @param string $field     Metadata field name
     * @param array  $config    Facet configuration
     * @param array  $baseQuery Base query filters
     * @param string $tableName Target table name
     *
     * @return ((int|mixed|null|string)[][]|int|string)[] Facet data for the metadata field
     *
     * @psalm-return array{type: 'date_histogram'|'range'|'terms', field: string,
     *     buckets: list<array{count: int, from?: mixed|null,
     *     key?: mixed|string, to?: mixed|null, value?: mixed}>,
     *     total_buckets: int<0, max>, error?: string, interval?: string}
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Reserved for future facet implementation
     */
    private function getMetadataFieldFacet(string $field, array $config, array $baseQuery, string $tableName): array
    {
        $type       = $config['type'] ?? 'terms';
        $columnName = '_'.$field;
        // Metadata columns are prefixed with _.
        switch ($type) {
            case 'terms':
                return $this->getTermsFacet(columnName: $columnName, baseQuery: $baseQuery, tableName: $tableName);

            case 'date_histogram':
                $interval = $config['interval'] ?? 'month';
                return $this->getDateHistogramFacet(
                    columnName: $columnName,
                    interval: $interval,
                    baseQuery: $baseQuery,
                    tableName: $tableName
                );

            case 'range':
                $ranges = $config['ranges'] ?? [];
                return $this->getRangeFacet(
                    columnName: $columnName,
                    ranges: $ranges,
                    baseQuery: $baseQuery,
                    tableName: $tableName
                );

            default:
                return $this->getTermsFacet(
                    columnName: $columnName,
                    baseQuery: $baseQuery,
                    tableName: $tableName
                );
        }//end switch
    }//end getMetadataFieldFacet()

    /**
     * Get facet data for a schema property field
     *
     * @param string $field     Schema property field name
     * @param array  $config    Facet configuration
     * @param array  $baseQuery Base query filters
     * @param Schema $schema    Schema context
     * @param string $tableName Target table name
     *
     * @return ((int|mixed|null|string)[][]|int|string)[] Facet data for the schema property field
     *
     * @psalm-return array{type?: 'date_histogram'|'range'|'terms',
     *     field?: string,
     *     buckets?: list<array{count: int, from?: mixed|null,
     *     key?: mixed|string, to?: mixed|null, value?: mixed}>,
     *     total_buckets?: int<0, max>, error?: string, interval?: string}
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Reserved for future facet implementation
     */
    private function getSchemaPropertyFacet(
        string $field,
        array $config,
        array $baseQuery,
        Schema $schema,
        string $tableName
    ): array {
        $type       = $config['type'] ?? 'terms';
        $columnName = $this->sanitizeColumnName($field);

        // Verify field exists in schema.
        $properties = $schema->getProperties();
        if (isset($properties[$field]) === false) {
            return [];
        }

        switch ($type) {
            case 'terms':
                return $this->getTermsFacet(
                    columnName: $columnName,
                    baseQuery: $baseQuery,
                    tableName: $tableName
                );

            case 'date_histogram':
                $interval = $config['interval'] ?? 'month';
                return $this->getDateHistogramFacet(
                    columnName: $columnName,
                    interval: $interval,
                    baseQuery: $baseQuery,
                    tableName: $tableName
                );

            case 'range':
                $ranges = $config['ranges'] ?? [];
                return $this->getRangeFacet(
                    columnName: $columnName,
                    ranges: $ranges,
                    baseQuery: $baseQuery,
                    tableName: $tableName
                );

            default:
                return $this->getTermsFacet(
                    columnName: $columnName,
                    baseQuery: $baseQuery,
                    tableName: $tableName
                );
        }//end switch
    }//end getSchemaPropertyFacet()

    /**
     * Get terms facet for a specific column
     *
     * @param string $columnName Column name to facet on
     * @param array  $baseQuery  Base query filters
     * @param string $tableName  Target table name
     * @param int    $limit      Maximum number of terms to return
     *
     * @return ((int|mixed)[][]|int|string)[] Terms facet data
     *
     * @psalm-return array{type: 'terms', field: string,
     *     buckets: list<array{count: int, value: mixed}>,
     *     total_buckets: int<0, max>, error?: string}
     */
    private function getTermsFacet(string $columnName, array $baseQuery, string $tableName, int $limit=100): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select($columnName, $qb->createFunction('COUNT(*) as count'))
            ->from($tableName, 't')
            ->where($qb->expr()->isNotNull($columnName))
            ->groupBy($columnName)
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit);

        // Apply base query filters.
        $this->applyBaseFilters(qb: $qb, baseQuery: $baseQuery);

        try {
            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();

            $terms = [];
            foreach ($rows as $row) {
                $terms[] = [
                    'value' => $row[$columnName],
                    'count' => (int) $row['count'],
                ];
            }

            return [
                'type'          => 'terms',
                'field'         => $columnName,
                'buckets'       => $terms,
                'total_buckets' => count($terms),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'Terms facet query failed',
                [
                    'columnName' => $columnName,
                    'tableName'  => $tableName,
                    'error'      => $e->getMessage(),
                ]
            );

            return [
                'type'          => 'terms',
                'field'         => $columnName,
                'buckets'       => [],
                'total_buckets' => 0,
                'error'         => $e->getMessage(),
            ];
        }//end try
    }//end getTermsFacet()

    /**
     * Get date histogram facet for a date/datetime column
     *
     * @param string $columnName Column name to facet on
     * @param string $interval   Date interval (day, week, month, year)
     * @param array  $baseQuery  Base query filters
     * @param string $tableName  Target table name
     *
     * @return ((int|mixed)[][]|int|string)[] Date histogram facet data
     *
     * @psalm-return array{
     *     type: 'date_histogram',
     *     field: string,
     *     interval: string,
     *     buckets: list<array{count: int, key: mixed}>,
     *     total_buckets: int<0, max>,
     *     error?: string
     * }
     */
    private function getDateHistogramFacet(string $columnName, string $interval, array $baseQuery, string $tableName): array
    {
        $qb = $this->db->getQueryBuilder();

        // Generate date truncation based on interval.
        $dateFormat = $this->getDateFormatForInterval($interval);
        $dateTrunc  = "DATE_FORMAT({$columnName}, '{$dateFormat}')";

        $qb->selectAlias($dateTrunc, 'period')
            ->addSelect($qb->createFunction('COUNT(*) as count'))
            ->from($tableName, 't')
            ->where($qb->expr()->isNotNull($columnName))
            ->groupBy('period')
            ->orderBy('period', 'ASC');

        // Apply base query filters.
        $this->applyBaseFilters(qb: $qb, baseQuery: $baseQuery);

        try {
            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();

            $buckets = [];
            foreach ($rows as $row) {
                $buckets[] = [
                    'key'   => $row['period'],
                    'count' => (int) $row['count'],
                ];
            }

            return [
                'type'          => 'date_histogram',
                'field'         => $columnName,
                'interval'      => $interval,
                'buckets'       => $buckets,
                'total_buckets' => count($buckets),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                'Date histogram facet query failed',
                [
                    'columnName' => $columnName,
                    'interval'   => $interval,
                    'tableName'  => $tableName,
                    'error'      => $e->getMessage(),
                ]
            );

            return [
                'type'          => 'date_histogram',
                'field'         => $columnName,
                'interval'      => $interval,
                'buckets'       => [],
                'total_buckets' => 0,
                'error'         => $e->getMessage(),
            ];
        }//end try
    }//end getDateHistogramFacet()

    /**
     * Get range facet for numerical columns
     *
     * @param string $columnName Column name to facet on
     * @param array  $ranges     Range configuration
     * @param array  $baseQuery  Base query filters
     * @param string $tableName  Target table name
     *
     * @return ((int|mixed|null|string)[][]|int|string)[] Range facet data
     *
     * @psalm-return array{
     *     type: 'range',
     *     field: string,
     *     buckets: list<array{
     *         count: int,
     *         from: mixed|null,
     *         key: mixed|string,
     *         to: mixed|null
     *     }>,
     *     total_buckets: int<0, max>
     * }
     */
    private function getRangeFacet(string $columnName, array $ranges, array $baseQuery, string $tableName): array
    {
        if ($ranges === []) {
            // Auto-generate ranges based on data distribution.
            $ranges = $this->generateAutoRanges(columnName: $columnName, tableName: $tableName);
        }

        $buckets = [];

        foreach ($ranges as $range) {
            $from = $range['from'] ?? null;
            $to   = $range['to'] ?? null;
            $key  = $range['key'] ?? ($from.'-'.$to);

            $qb = $this->db->getQueryBuilder();
            $qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
                ->from($tableName, 't')
                ->where($qb->expr()->isNotNull($columnName));

            // Add range conditions.
            if ($from !== null) {
                $qb->andWhere($qb->expr()->gte($columnName, $qb->createNamedParameter($from)));
            }

            if ($to !== null) {
                $qb->andWhere($qb->expr()->lt($columnName, $qb->createNamedParameter($to)));
            }

            // Apply base query filters.
            $this->applyBaseFilters($qb, $baseQuery);

            try {
                $result = $qb->executeQuery();
                $count  = (int) $result->fetchOne();

                $buckets[] = [
                    'key'   => $key,
                    'from'  => $from,
                    'to'    => $to,
                    'count' => $count,
                ];
            } catch (\Exception $e) {
                $this->logger->error(
                    'Range facet query failed for range',
                    [
                        'columnName' => $columnName,
                        'range'      => $range,
                        'error'      => $e->getMessage(),
                    ]
                );
            }
        }//end foreach

        return [
            'type'          => 'range',
            'field'         => $columnName,
            'buckets'       => $buckets,
            'total_buckets' => count($buckets),
        ];
    }//end getRangeFacet()

    /**
     * Apply base query filters to facet queries
     *
     * @param IQueryBuilder $qb        Query builder to modify
     * @param array         $baseQuery Base query filters
     *
     * @return void
     */
    private function applyBaseFilters(IQueryBuilder $qb, array $baseQuery): void
    {
        // Apply basic filters.
        $includeDeleted = $baseQuery['_includeDeleted'] ?? false;
        $published      = $baseQuery['_published'] ?? false;

        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull('t._deleted'));
        }

        if ($published === true) {
            $now = (new DateTime())->format('Y-m-d H:i:s');
            $qb->andWhere(
                $qb->expr()->andX(
                    $qb->expr()->isNotNull('t._published'),
                    $qb->expr()->lte('t._published', $qb->createNamedParameter($now)),
                    $qb->expr()->orX(
                        $qb->expr()->isNull('t._depublished'),
                        $qb->expr()->gt('t._depublished', $qb->createNamedParameter($now))
                    )
                )
            );
        }

        // Apply metadata filters (@self).
        if (($baseQuery['@self'] ?? null) !== null && is_array($baseQuery['@self']) === true) {
            foreach ($baseQuery['@self'] ?? [] as $field => $value) {
                $columnName = '_'.$field;

                if ($value === 'IS NOT NULL') {
                    $qb->andWhere($qb->expr()->isNotNull("t.{$columnName}"));
                } else if ($value === 'IS NULL') {
                    $qb->andWhere($qb->expr()->isNull("t.{$columnName}"));
                } else if (is_array($value) === true) {
                    $paramValue = $qb->createNamedParameter(
                        $value,
                        \Doctrine\DBAL\Connection::PARAM_STR_ARRAY
                    );
                    $qb->andWhere($qb->expr()->in("t.{$columnName}", $paramValue));
                    continue;
                }

                $qb->andWhere($qb->expr()->eq("t.{$columnName}", $qb->createNamedParameter($value)));
            }
        }

        // Apply object field filters.
        $objectFilters = array_filter(
            $baseQuery,
            function ($key) {
                return $key !== '@self' && str_starts_with($key, '_') === false;
            },
            ARRAY_FILTER_USE_KEY
        );

        foreach ($objectFilters as $field => $value) {
            $columnName = $this->sanitizeColumnName($field);

            if ($value === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull("t.{$columnName}"));
            } else if ($value === 'IS NULL') {
                $qb->andWhere($qb->expr()->isNull("t.{$columnName}"));
            } else if (is_array($value) === true) {
                $paramValue = $qb->createNamedParameter(
                    $value,
                    \Doctrine\DBAL\Connection::PARAM_STR_ARRAY
                );
                $qb->andWhere($qb->expr()->in("t.{$columnName}", $paramValue));
                continue;
            }

            $qb->andWhere($qb->expr()->eq("t.{$columnName}", $qb->createNamedParameter($value)));
        }
    }//end applyBaseFilters()

    /**
     * Get facetable metadata fields
     *
     * @return (string[])[][] Array of metadata fields that can be faceted
     *
     * @psalm-return array{
     *     register: array{
     *         type: 'integer',
     *         title: 'Register',
     *         description: 'Register ID',
     *         facet_types: list{'terms'}
     *     },
     *     schema: array{
     *         type: 'integer',
     *         title: 'Schema',
     *         description: 'Schema ID',
     *         facet_types: list{'terms'}
     *     },
     *     owner: array{
     *         type: 'string',
     *         title: 'Owner',
     *         description: 'Object owner',
     *         facet_types: list{'terms'}
     *     },
     *     organisation: array{
     *         type: 'string',
     *         title: 'Organisation',
     *         description: 'Organisation UUID',
     *         facet_types: list{'terms'}
     *     },
     *     created: array{
     *         type: 'string',
     *         format: 'date-time',
     *         title: 'Created',
     *         description: 'Creation timestamp',
     *         facet_types: list{'date_histogram', 'range'}
     *     },
     *     updated: array{
     *         type: 'string',
     *         format: 'date-time',
     *         title: 'Updated',
     *         description: 'Last update timestamp',
     *         facet_types: list{'date_histogram', 'range'}
     *     }
     * }
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Reserved for future facet implementation
     */
    private function getMetadataFacetableFields(): array
    {
        return [
            'register'     => [
                'type'        => 'integer',
                'title'       => 'Register',
                'description' => 'Register ID',
                'facet_types' => ['terms'],
            ],
            'schema'       => [
                'type'        => 'integer',
                'title'       => 'Schema',
                'description' => 'Schema ID',
                'facet_types' => ['terms'],
            ],
            'owner'        => [
                'type'        => 'string',
                'title'       => 'Owner',
                'description' => 'Object owner',
                'facet_types' => ['terms'],
            ],
            'organisation' => [
                'type'        => 'string',
                'title'       => 'Organisation',
                'description' => 'Organisation UUID',
                'facet_types' => ['terms'],
            ],
            'created'      => [
                'type'        => 'string',
                'format'      => 'date-time',
                'title'       => 'Created',
                'description' => 'Creation timestamp',
                'facet_types' => ['date_histogram', 'range'],
            ],
            'updated'      => [
                'type'        => 'string',
                'format'      => 'date-time',
                'title'       => 'Updated',
                'description' => 'Last update timestamp',
                'facet_types' => ['date_histogram', 'range'],
            ],
        ];
    }//end getMetadataFacetableFields()

    /**
     * Get facetable fields from schema properties
     *
     * @param Schema $schema Schema to analyze
     *
     * @return (mixed|string[])[][]
     *
     * @psalm-return array<array{type: 'string'|mixed, format: ''|mixed,
     *     title: mixed, description: mixed|string,
     *     facet_types: list{0: 'date_histogram'|'range'|'terms',
     *     1?: 'range'|'terms'}}>
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Reserved for future facet implementation
     */
    private function getSchemaFacetableFields(Schema $schema): array
    {
        $properties      = $schema->getProperties();
        $facetableFields = [];

        foreach ($properties ?? [] as $propertyName => $propertyConfig) {
            if (($propertyConfig['facetable'] ?? false) === true) {
                $facetableFields[$propertyName] = [
                    'type'        => $propertyConfig['type'] ?? 'string',
                    'format'      => $propertyConfig['format'] ?? '',
                    'title'       => $propertyConfig['title'] ?? $propertyName,
                    'description' => $propertyConfig['description'] ?? "Schema property: {$propertyName}",
                    'facet_types' => $this->determineFacetTypes($propertyConfig),
                ];
            }
        }

        return $facetableFields;
    }//end getSchemaFacetableFields()

    /**
     * Determine appropriate facet types for a property
     *
     * @param array $propertyConfig Property configuration
     *
     * @return string[] Array of suitable facet types
     *
     * @psalm-return list{0: 'date_histogram'|'range'|'terms', 1?: 'range'|'terms'}
     */
    private function determineFacetTypes(array $propertyConfig): array
    {
        $type   = $propertyConfig['type'] ?? 'string';
        $format = $propertyConfig['format'] ?? '';

        switch ($type) {
            case 'string':
                if ($format === 'date' || $format === 'date-time') {
                    return ['date_histogram', 'range'];
                }
                return ['terms'];

            case 'integer':
            case 'number':
                return ['range', 'terms'];

            case 'boolean':
                return ['terms'];

            case 'array':
                return ['terms'];

            default:
                return ['terms'];
        }//end switch
    }//end determineFacetTypes()

    /**
     * Get date format string for SQL DATE_FORMAT based on interval
     *
     * @param string $interval Date interval (day, week, month, year)
     *
     * @return string MySQL DATE_FORMAT string
     */
    private function getDateFormatForInterval(string $interval): string
    {
        switch (strtolower($interval)) {
            case 'day':
                return '%Y-%m-%d';
            case 'week':
                return '%Y-%u';
            // Year-week.
            case 'month':
                return '%Y-%m';
            case 'year':
                return '%Y';
            default:
                return '%Y-%m';
            // Default to month.
        }
    }//end getDateFormatForInterval()

    /**
     * Generate automatic ranges based on data distribution
     *
     * @param string $columnName Column to analyze
     * @param string $tableName  Target table name
     *
     * @return (float|null|string)[][] Array of auto-generated ranges
     *
     * @psalm-return list<array{from: float, key: non-empty-string, to: float|null}>
     */
    private function generateAutoRanges(string $columnName, string $tableName): array
    {
        try {
            // Get min and max values.
            $qb = $this->db->getQueryBuilder();
            $qb->select(
                $qb->createFunction("MIN({$columnName}) as min_val"),
                $qb->createFunction("MAX({$columnName}) as max_val")
            )
                ->from($tableName, 't')
                ->where($qb->expr()->isNotNull($columnName));

            $result = $qb->executeQuery();
            $stats  = $result->fetch();

            if ($stats === false || $stats['min_val'] === null || $stats['max_val'] === null) {
                return [];
            }

            $min   = (float) $stats['min_val'];
            $max   = (float) $stats['max_val'];
            $range = $max - $min;

            // Generate 5 equal ranges.
            $numRanges = 5;
            $step      = $range / $numRanges;
            $ranges    = [];

            for ($i = 0; $i < $numRanges; $i++) {
                $from = $min + ($i * $step);
                $to   = $min + (($i + 1) * $step);
                if ($i === $numRanges - 1) {
                    $to = null;
                }

                $key = "{$from}-{$to}";
                if ($to === null) {
                    $key = "{$from}+";
                }

                $ranges[] = [
                    'key'  => $key,
                    'from' => $from,
                    'to'   => $to,
                ];
            }

            return $ranges;
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to generate auto ranges',
                [
                    'columnName' => $columnName,
                    'tableName'  => $tableName,
                    'error'      => $e->getMessage(),
                ]
            );

            return [];
        }//end try
    }//end generateAutoRanges()

    /**
     * Sanitize column name for safe database usage
     *
     * @param string $name Column name to sanitize
     *
     * @return string Sanitized column name
     */
    private function sanitizeColumnName(string $name): string
    {
        // Convert to lowercase and replace non-alphanumeric with underscores.
        $sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $name));

        // Ensure it starts with a letter or underscore.
        if (preg_match('/^[a-zA-Z_]/', $sanitized) === 0) {
            $sanitized = 'col_'.$sanitized;
        }

        // Limit length to 64 characters (MySQL limit).
        return substr($sanitized, 0, 64);
    }//end sanitizeColumnName()
}//end class
