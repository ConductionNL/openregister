<?php

/**
 * AggregationRunner
 *
 * Executes named aggregations against the most capable available backend.
 * Dispatch order: Solr → Elasticsearch → Postgres native SQL → PHP fallback.
 * Results are cached for 60 seconds via AggregationCache.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Index\Backends\ElasticsearchBackend;
use OCA\OpenRegister\Service\Index\Backends\SolrBackend;
use OCA\OpenRegister\Service\IndexService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches aggregation requests to the best available backend.
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-5
 */
class AggregationRunner
{
    /**
     * Constructor.
     *
     * @param IDBConnection    $db           Database connection for Postgres native path.
     * @param MagicMapper      $magicMapper  Magic mapper for PHP fallback path.
     * @param IndexService     $indexService Search index service (provides backend).
     * @param AggregationCache $cache        Aggregation result cache.
     * @param LoggerInterface  $logger       Logger.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly MagicMapper $magicMapper,
        private readonly IndexService $indexService,
        private readonly AggregationCache $cache,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Execute an aggregation and return the result.
     *
     * Checks the cache first, then dispatches to the best available backend.
     * Every result carries a `backend` field and a `cached` flag.
     *
     * @param AggregationQuery $query    Aggregation query.
     * @param Register         $register Register context.
     * @param Schema           $schema   Schema context.
     * @param string           $name     Named aggregation identifier (for cache key).
     * @param string           $uid      Authenticated user UID (for RBAC cache scoping).
     *
     * @return AggregationResult
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-5
     */
    public function run(
        AggregationQuery $query,
        Register $register,
        Schema $schema,
        string $name,
        string $uid
    ): AggregationResult {
        $registerSlug = $register->getSlug() ?? (string) $register->getId();
        $schemaSlug   = $schema->getSlug() ?? (string) $schema->getId();
        $cacheKey     = $this->cache->buildKey(
            registerSlug: $registerSlug,
            schemaSlug: $schemaSlug,
            name: $name,
            filters: $query->filter,
            rbacScope: $uid
        );

        // Cache hit fast path.
        $cached = $this->cache->get(key: $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->dispatch(query: $query, register: $register, schema: $schema);

        $this->cache->set(key: $cacheKey, result: $result);

        return $result;
    }//end run()

    /**
     * Dispatch to the best available backend.
     *
     * @param AggregationQuery $query    Aggregation query.
     * @param Register         $register Register context.
     * @param Schema           $schema   Schema context.
     *
     * @return AggregationResult
     */
    private function dispatch(
        AggregationQuery $query,
        Register $register,
        Schema $schema
    ): AggregationResult {
        $backend = $this->indexService->getBackend();

        // 1. Solr path.
        if ($backend instanceof SolrBackend && $backend->isAvailable() === true) {
            try {
                return $backend->aggregate(query: $query);
            } catch (Throwable $e) {
                $this->logger->warning(
                    message: '[AggregationRunner] Solr aggregate failed; falling back to Postgres.',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
            }
        }

        // 2. Elasticsearch path.
        if ($backend instanceof ElasticsearchBackend && $backend->isAvailable() === true) {
            try {
                return $backend->aggregate(query: $query);
            } catch (Throwable $e) {
                $this->logger->warning(
                    message: '[AggregationRunner] Elasticsearch aggregate failed; falling back to Postgres.',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
            }
        }

        // 3. Postgres native SQL path.
        $tableName = 'oc_openregister_table_'.$register->getId().'_'.$schema->getId();
        $native    = $this->tryNativeAggregation(query: $query, tableName: $tableName);
        if ($native !== null) {
            return $native;
        }

        // 4. PHP fallback.
        return $this->phpFallback(query: $query, register: $register, schema: $schema);
    }//end dispatch()

    /**
     * Attempt a native Postgres SQL aggregation on the magic table.
     *
     * Translates in/gte/lte/gt/lt/ne operators and binds placeholders.
     * Returns null when the filter shape is unsupported.
     *
     * @param AggregationQuery $query     Aggregation query.
     * @param string           $tableName Full magic-table name (with oc_ prefix).
     *
     * @return AggregationResult|null Result, or null to fall back to PHP.
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-2
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function tryNativeAggregation(
        AggregationQuery $query,
        string $tableName
    ): ?AggregationResult {
        [$conditions, $bindings] = $this->buildSqlConditions(filters: $query->filter);

        if ($conditions === null) {
            return null;
        }

        $where = empty($conditions) === false ? 'WHERE '.implode(separator: ' AND ', array: $conditions) : '';

        $field = $query->field !== null ? $this->sanitiseColumnName(name: $query->field) : null;

        $groupCol = $query->groupBy !== null ? $this->sanitiseColumnName(name: $query->groupBy['field']) : null;

        if ($query->metric === 'count') {
            $aggExpr = 'COUNT(*)';
        } else {
            if ($field === null) {
                return null;
            }

            $aggExpr = strtoupper($query->metric).'('.$field.')';
        }

        try {
            if ($groupCol === null) {
                // Ungrouped.
                $sql  = 'SELECT '.$aggExpr.' AS agg_value FROM '.$tableName.' '.$where;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($bindings);
                $row = $stmt->fetch();
                $val = $row !== false ? (float) $row['agg_value'] : 0.0;

                return new AggregationResult(
                    value: $val,
                    groups: null,
                    backend: 'postgres'
                );
            }

            // Grouped.
            $sql  = 'SELECT '.$groupCol.' AS grp, '.$aggExpr.' AS agg_value';
            $sql .= ' FROM '.$tableName.' '.$where.' GROUP BY '.$groupCol;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);
            $rows   = $stmt->fetchAll();
            $total  = 0.0;
            $groups = [];

            foreach ($rows as $row) {
                $groupVal = (float) $row['agg_value'];
                $total   += $groupVal;
                $groups[] = ['group' => $row['grp'], 'value' => $groupVal];
            }

            return new AggregationResult(
                value: $total,
                groups: $groups,
                backend: 'postgres'
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[AggregationRunner] Postgres native aggregation failed.',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end tryNativeAggregation()

    /**
     * Build SQL WHERE conditions from a filter map.
     *
     * Returns [conditions[], bindings[]] on success, or [null, []] when a filter
     * operator is unsupported and we must fall back to PHP.
     *
     * @param array $filters Filter map from AggregationQuery.
     *
     * @return array{0: string[]|null, 1: array} [conditions, bindings]
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-2
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function buildSqlConditions(array $filters): array
    {
        $conditions = [];
        $bindings   = [];

        foreach ($filters as $field => $value) {
            $col = $this->sanitiseColumnName(name: $field);

            if (is_array($value) === false) {
                // Scalar equality.
                $conditions[] = $col.' = ?';
                $bindings[]   = $this->normaliseBinding(value: $value);
                continue;
            }

            if (isset($value['in']) === true) {
                if (empty($value['in']) === true) {
                    // Empty in-list → never matches.
                    $conditions[] = '1 = 0';
                } else {
                    $count        = count($value['in']);
                    $placeholders = implode(separator: ', ', array: array_fill(start_index: 0, count: $count, value: '?'));
                    $conditions[] = $col.' IN ('.$placeholders.')';
                    foreach ($value['in'] as $v) {
                        $bindings[] = $this->normaliseBinding(value: $v);
                    }
                }

                continue;
            }

            if (isset($value['ne']) === true) {
                $conditions[] = $col.' <> ?';
                $bindings[]   = $this->normaliseBinding(value: $value['ne']);
                continue;
            }

            if (isset($value['gte']) === true) {
                $conditions[] = $col.' >= ?';
                $bindings[]   = $this->normaliseBinding(value: $value['gte']);
            } else if (isset($value['gt']) === true) {
                $conditions[] = $col.' > ?';
                $bindings[]   = $this->normaliseBinding(value: $value['gt']);
            }

            if (isset($value['lte']) === true) {
                $conditions[] = $col.' <= ?';
                $bindings[]   = $this->normaliseBinding(value: $value['lte']);
            } else if (isset($value['lt']) === true) {
                $conditions[] = $col.' < ?';
                $bindings[]   = $this->normaliseBinding(value: $value['lt']);
            }
        }//end foreach

        return [$conditions, $bindings];
    }//end buildSqlConditions()

    /**
     * PHP fallback: pull all rows and compute the metric in PHP.
     *
     * @param AggregationQuery $query    Aggregation query.
     * @param Register         $register Register context.
     * @param Schema           $schema   Schema context.
     *
     * @return AggregationResult
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function phpFallback(
        AggregationQuery $query,
        Register $register,
        Schema $schema
    ): AggregationResult {
        $objects = $this->magicMapper->findAllInRegisterSchemaTable(
            register: $register,
            schema: $schema,
            filters: $query->filter
        );

        if ($query->groupBy !== null) {
            return $this->computeGrouped(query: $query, objects: $objects);
        }

        return $this->computeScalar(query: $query, objects: $objects);
    }//end phpFallback()

    /**
     * Compute a scalar (ungrouped) metric in PHP.
     *
     * @param AggregationQuery $query   Aggregation query.
     * @param ObjectEntity[]   $objects Loaded objects.
     *
     * @return AggregationResult
     */
    private function computeScalar(AggregationQuery $query, array $objects): AggregationResult
    {
        if ($query->metric === 'count') {
            return new AggregationResult(
                value: count($objects),
                groups: null,
                backend: 'php-fallback'
            );
        }

        $values = [];
        foreach ($objects as $object) {
            $objArray = $object->getObject();
            $v        = $objArray[$query->field] ?? null;
            if ($v !== null && is_numeric(value: $v) === true) {
                $values[] = (float) $v;
            }
        }

        $scalar = match ($query->metric) {
            'sum'   => empty($values) === true ? 0.0 : array_sum($values),
            'avg'   => empty($values) === true ? 0.0 : array_sum($values) / count($values),
            'min'   => empty($values) === true ? 0.0 : min($values),
            'max'   => empty($values) === true ? 0.0 : max($values),
            default => 0.0,
        };

        return new AggregationResult(
            value: $scalar,
            groups: null,
            backend: 'php-fallback'
        );
    }//end computeScalar()

    /**
     * Compute a grouped metric in PHP.
     *
     * @param AggregationQuery $query   Aggregation query.
     * @param ObjectEntity[]   $objects Loaded objects.
     *
     * @return AggregationResult
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function computeGrouped(AggregationQuery $query, array $objects): AggregationResult
    {
        $groupField = $query->groupBy['field'];
        $buckets    = [];

        foreach ($objects as $object) {
            $objArray = $object->getObject();
            $groupKey = (string) ($objArray[$groupField] ?? '__null__');
            $fieldVal = $query->field !== null ? ($objArray[$query->field] ?? null) : null;

            if (isset($buckets[$groupKey]) === false) {
                $buckets[$groupKey] = ['values' => [], 'count' => 0];
            }

            $buckets[$groupKey]['count']++;
            if ($fieldVal !== null && is_numeric(value: $fieldVal) === true) {
                $buckets[$groupKey]['values'][] = (float) $fieldVal;
            }
        }

        $groups = [];
        $total  = 0.0;

        foreach ($buckets as $groupKey => $bucket) {
            $vals = $bucket['values'];
            $v    = match ($query->metric) {
                'count' => (float) $bucket['count'],
                'sum'   => empty($vals) === true ? 0.0 : array_sum($vals),
                'avg'   => empty($vals) === true ? 0.0 : array_sum($vals) / count($vals),
                'min'   => empty($vals) === true ? 0.0 : min($vals),
                'max'   => empty($vals) === true ? 0.0 : max($vals),
                default => 0.0,
            };

            $total   += $query->metric === 'count' ? $v : 0;
            $groups[] = ['group' => $groupKey, 'value' => $v];
        }

        if ($query->metric === 'count') {
            $totalVal = $total;
        } else {
            $allValues = array_merge(...array_column($buckets, 'values'));
            $totalVal  = match ($query->metric) {
                'sum'   => empty($allValues) === true ? 0.0 : array_sum($allValues),
                'avg'   => empty($allValues) === true ? 0.0 : array_sum($allValues) / count($allValues),
                'min'   => empty($allValues) === true ? 0.0 : min($allValues),
                'max'   => empty($allValues) === true ? 0.0 : max($allValues),
                default => 0.0,
            };
        }

        return new AggregationResult(
            value: $totalVal,
            groups: $groups,
            backend: 'php-fallback'
        );
    }//end computeGrouped()

    /**
     * Normalise a binding value for SQL prepared statements.
     *
     * DateTimeInterface → ATOM string; bool → 'true'/'false'; others → string.
     *
     * @param mixed $value Value to normalise.
     *
     * @return string|float|int Normalised value.
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-2
     */
    private function normaliseBinding(mixed $value): string|float|int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(format: \DATE_ATOM);
        }

        if (is_bool(value: $value) === true) {
            return $value === true ? 'true' : 'false';
        }

        if (is_numeric(value: $value) === true) {
            return $value + 0;
        }

        return (string) $value;
    }//end normaliseBinding()

    /**
     * Sanitise a field name for use in SQL (strip non-alphanumeric, allow underscore).
     *
     * @param string $name Field name to sanitise.
     *
     * @return string Sanitised column name.
     */
    private function sanitiseColumnName(string $name): string
    {
        return preg_replace(pattern: '/[^a-zA-Z0-9_]/', replacement: '', subject: $name) ?? $name;
    }//end sanitiseColumnName()
}//end class
