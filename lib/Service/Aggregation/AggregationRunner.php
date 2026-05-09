<?php

/**
 * OpenRegister AggregationRunner
 *
 * Loads a schema's `x-openregister-aggregations` annotation, fetches the
 * matched objects via the existing findAll path (RBAC + multi-tenancy
 * still applied), and computes the metric in PHP.
 *
 * v1 trades performance for simplicity: backend-native aggregation
 * (Postgres GROUP BY / Solr facets / ES aggs) ships in a follow-up.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Index\SearchBackendInterface;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use ReflectionClass;
use OCP\IDBConnection;
use RuntimeException;

/**
 * Runs a named aggregation against a schema.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AggregationRunner
{
    /**
     * Constructor.
     *
     * @param MagicMapper                 $magicMapper    Magic-table mapper used for the PHP fallback path.
     * @param RegisterMapper              $registerMapper Register loader.
     * @param SchemaMapper                $schemaMapper   Schema loader.
     * @param PlaceholderResolver         $placeholders   Resolves dynamic placeholders inside filters.
     * @param IDBConnection               $db             Database connection for the Postgres-native fast path.
     * @param AggregationCache            $cache          60s aggregation result cache.
     * @param SearchBackendInterface|null $searchBackend  Optional Solr/ES backend for native aggregation.
     *
     * @return void
     */
    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly PlaceholderResolver $placeholders,
        private readonly IDBConnection $db,
        private readonly AggregationCache $cache,
        private readonly ?SearchBackendInterface $searchBackend=null
    ) {
    }//end __construct()

    /**
     * Run the named aggregation on the given (register, schema).
     *
     * @param string $registerRef Register slug/uuid/id.
     * @param string $schemaRef   Schema slug/uuid/id.
     * @param string $name        Aggregation name (key in the annotation).
     *
     * @return array{
     *   name: string,
     *   metric: string,
     *   field: ?string,
     *   value?: int|float|null,
     *   groups?: array<int, array{key: mixed, value: int|float|null}>
     * }
     *
     * @throws RuntimeException When the schema/aggregation is missing.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function run(string $registerRef, string $schemaRef, string $name): array
    {
        $schema     = $this->loadSchema(schemaRef: $schemaRef);
        $register   = $this->loadRegister(registerRef: $registerRef);
        $annotation = $this->getAnnotation(schema: $schema);
        if ($annotation === null) {
            throw new RuntimeException(
                sprintf('Schema "%s" does not declare x-openregister-aggregations.', $schemaRef)
            );
        }

        if (isset($annotation[$name]) === false || is_array($annotation[$name]) === false) {
            throw new RuntimeException(sprintf('Aggregation "%s" is not declared on this schema.', $name));
        }

        $spec    = $annotation[$name];
        $metric  = (string) ($spec['metric'] ?? '');
        $field   = ($spec['field'] ?? null);
        $filter  = (array) ($spec['filter'] ?? []);
        $groupBy = ($spec['groupBy'] ?? null);

        $resolvedFilter = $this->placeholders->resolveArray($filter);

        // Cache lookup: the resolved filter (with placeholders concrete)
        // is the cache key together with the user's RBAC scope. 60s TTL.
        $cacheKey = [
            'metric'  => $metric,
            'field'   => $field,
            'filter'  => $resolvedFilter,
            'groupBy' => $groupBy,
        ];
        $cached   = $this->cache->get(
            registerSlug: (string) $register->getSlug(),
            schemaSlug: (string) $schema->getSlug(),
            name: $name,
            filter: $cacheKey
        );
        if ($cached !== null) {
            $cached['cached'] = true;
            return $cached;
        }

        // Try the configured external search backend (Solr / ES) first
        // when one is wired in. The backend returns null when it can't
        // execute the query (unsupported metric, unreachable instance,
        // etc) — we then fall through to the Postgres-native fast path
        // and finally to the PHP fallback.
        if ($this->searchBackend !== null) {
            try {
                $portableQuery = AggregationQuery::create(
                    metric: $metric,
                    field: is_string($field) === true ? $field : null,
                    filter: $resolvedFilter,
                    groupBy: is_array($groupBy) === true ? $groupBy : null
                );
                $external      = $this->searchBackend->aggregate(query: $portableQuery);
                if ($external !== null) {
                    $backendName = $this->detectBackendName(backend: $this->searchBackend);
                    $result      = [
                        'name'    => $name,
                        'metric'  => $metric,
                        'field'   => is_string($field) === true ? $field : null,
                        'backend' => $backendName,
                    ] + $external;
                    $this->cache->set(
                        registerSlug: (string) $register->getSlug(),
                        schemaSlug: (string) $schema->getSlug(),
                        name: $name,
                        filter: $cacheKey,
                        result: $result
                    );
                    return $result;
                }
            } catch (\Throwable $e) {
                // External backend errored — fall through to native /
                // PHP path so a flaky Solr/ES never breaks aggregations.
            }//end try
        }//end if

        // Try the Postgres-native fast path. Falls back to PHP when the
        // query shape isn't supported (operator filters, complex values,
        // non-Postgres DB, etc).
        $native = $this->tryNativeAggregation(
            register: $register,
            schema: $schema,
            metric: $metric,
            field: is_string($field) === true ? $field : null,
            filter: $resolvedFilter,
            groupBy: is_array($groupBy) === true ? $groupBy : null
        );
        if ($native !== null) {
            $result = [
                'name'    => $name,
                'metric'  => $metric,
                'field'   => is_string($field) === true ? $field : null,
                'backend' => 'postgres',
            ] + $native;
            $this->cache->set(
                registerSlug: (string) $register->getSlug(),
                schemaSlug: (string) $schema->getSlug(),
                name: $name,
                filter: $cacheKey,
                result: $result
            );
            return $result;
        }

        // Fall back: pull all objects and filter in PHP.
        $objects = $this->magicMapper->findAllInRegisterSchemaTable(
            register: $register,
            schema: $schema,
            limit: 100000
        );

        $rows = [];
        foreach ($objects as $entity) {
            $rows[] = $entity instanceof \OCA\OpenRegister\Db\ObjectEntity ? $entity->getObject() : (array) $entity;
        }

        // Apply post-filter for operator shapes the underlying mapper
        // doesn't natively support (e.g. gte/lte). Equality filters were
        // already applied above, so re-applying them is a no-op.
        $rows = $this->applyFilter(rows: $rows, filter: $resolvedFilter);

        $result = [
            'name'    => $name,
            'metric'  => $metric,
            'field'   => is_string($field) === true ? $field : null,
            'backend' => 'php-fallback',
        ];

        if (is_array($groupBy) === true && isset($groupBy['field']) === true) {
            $result['groups'] = $this->computeGrouped(
                rows: $rows,
                metric: $metric,
                field: $field,
                groupField: (string) $groupBy['field']
            );
        }

        if (isset($result['groups']) === false) {
            $result['value'] = $this->computeMetric(rows: $rows, metric: $metric, field: $field);
        }

        $this->cache->set(
            registerSlug: (string) $register->getSlug(),
            schemaSlug: (string) $schema->getSlug(),
            name: $name,
            filter: $cacheKey,
            result: $result
        );
        return $result;
    }//end run()

    /**
     * Compute a single scalar metric over the given rows.
     *
     * @param array<int, array<string, mixed>> $rows   Already-filtered rows.
     * @param string                           $metric One of count/sum/avg/min/max/count_distinct.
     * @param mixed                            $field  Field name to aggregate over (ignored for count).
     *
     * @return int|float|null The metric result, or null when no rows match.
     */
    private function computeMetric(array $rows, string $metric, mixed $field): int|float|null
    {
        $sumReducer = fn(float $a, float $b) => $a + $b;
        $minReducer = fn(float $a, float $b) => min($a, $b);
        $maxReducer = fn(float $a, float $b) => max($a, $b);
        $distinct   = array_unique(
            array_filter(
                array_map(fn(array $r) => $r[(string) $field] ?? null, $rows),
                fn($v) => $v !== null
            ),
            SORT_REGULAR
        );

        return match ($metric) {
            'count'          => count($rows),
            'sum'            => $this->reduceNumeric(rows: $rows, field: (string) $field, reducer: $sumReducer, initial: 0.0),
            'avg'            => $this->avg(rows: $rows, field: (string) $field),
            'min'            => $this->reduceNumeric(rows: $rows, field: (string) $field, reducer: $minReducer, initial: null),
            'max'            => $this->reduceNumeric(rows: $rows, field: (string) $field, reducer: $maxReducer, initial: null),
            'count_distinct' => count($distinct),
            default          => null,
        };
    }//end computeMetric()

    /**
     * Compute a grouped metric, bucketing rows by `$groupField`.
     *
     * @param array<int, array<string, mixed>> $rows       Already-filtered rows.
     * @param string                           $metric     One of count/sum/avg/min/max/count_distinct.
     * @param mixed                            $field      Field to aggregate over.
     * @param string                           $groupField Field used as the bucket key.
     *
     * @return array<int, array{key: mixed, value: int|float|null}>
     */
    private function computeGrouped(array $rows, string $metric, mixed $field, string $groupField): array
    {
        $buckets = [];
        foreach ($rows as $row) {
            $bucket = $row[$groupField] ?? null;
            $key    = is_scalar($bucket) === true ? (string) $bucket : json_encode($bucket);
            if (isset($buckets[$key]) === false) {
                $buckets[$key] = ['key' => $bucket, 'rows' => []];
            }

            $buckets[$key]['rows'][] = $row;
        }

        $out = [];
        foreach ($buckets as $b) {
            $out[] = [
                'key'   => $b['key'],
                'value' => $this->computeMetric(rows: $b['rows'], metric: $metric, field: $field),
            ];
        }

        return $out;
    }//end computeGrouped()

    /**
     * Reduce a numeric column using the given binary reducer.
     *
     * @param array<int, array<string, mixed>> $rows    Rows to reduce.
     * @param string                           $field   Column to read.
     * @param callable                         $reducer Binary reducer applied to (acc, value).
     * @param mixed                            $initial Initial accumulator value (null is allowed).
     *
     * @return int|float|null The reduced value, or null when no numeric rows were seen.
     */
    private function reduceNumeric(array $rows, string $field, callable $reducer, mixed $initial): int|float|null
    {
        $acc   = $initial;
        $count = 0;
        foreach ($rows as $row) {
            $value = $row[$field] ?? null;
            if (is_numeric($value) === false) {
                continue;
            }

            $count++;
            $acc = $acc === null ? (float) $value : $reducer((float) $acc, (float) $value);
        }

        if ($count === 0 && $acc === null) {
            return null;
        }

        return $acc;
    }//end reduceNumeric()

    /**
     * Compute the arithmetic mean of a numeric column.
     *
     * @param array<int, array<string, mixed>> $rows  Rows to average.
     * @param string                           $field Column to read.
     *
     * @return float|null The mean, or null when no numeric rows were seen.
     */
    private function avg(array $rows, string $field): float|null
    {
        $sum   = 0.0;
        $count = 0;
        foreach ($rows as $row) {
            $value = $row[$field] ?? null;
            if (is_numeric($value) === false) {
                continue;
            }

            $sum += (float) $value;
            $count++;
        }

        return $count === 0 ? null : ($sum / $count);
    }//end avg()

    /**
     * Apply operator-style filters (gte/lte/gt/lt/in/ne) in PHP.
     *
     * @param array<int, array<string, mixed>> $rows   Rows to filter.
     * @param array<string, mixed>             $filter Filter map (scalar = eq, array = operator map).
     *
     * @return array<int, array<string, mixed>> Filtered rows.
     */
    private function applyFilter(array $rows, array $filter): array
    {
        $result = [];
        foreach ($rows as $row) {
            $keep = true;
            foreach ($filter as $field => $criterion) {
                $value = $row[$field] ?? null;
                if (is_array($criterion) === false) {
                    if ($value !== $criterion) {
                        $keep = false;
                        break;
                    }

                    continue;
                }

                foreach ($criterion as $op => $opValue) {
                    if ($this->checkOp(value: $value, op: (string) $op, opValue: $opValue) === false) {
                        $keep = false;
                        break 2;
                    }
                }
            }

            if ($keep === true) {
                $result[] = $row;
            }
        }//end foreach

        return $result;
    }//end applyFilter()

    /**
     * Apply a single operator check.
     *
     * @param mixed  $value   The value extracted from the row.
     * @param string $op      Operator name ('eq','ne','gt','gte','lt','lte','in').
     * @param mixed  $opValue The operand value to compare against.
     *
     * @return bool True when the value satisfies the operator.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function checkOp(mixed $value, string $op, mixed $opValue): bool
    {
        $cmp = $this->normaliseForCompare(v: $value);
        $rhs = $this->normaliseForCompare(v: $opValue);
        return match ($op) {
            'eq'  => $cmp === $rhs,
            'ne'  => $cmp !== $rhs,
            'gt'  => $cmp !== null && $rhs !== null && $cmp > $rhs,
            'gte' => $cmp !== null && $rhs !== null && $cmp >= $rhs,
            'lt'  => $cmp !== null && $rhs !== null && $cmp < $rhs,
            'lte' => $cmp !== null && $rhs !== null && $cmp <= $rhs,
            'in'  => is_array($opValue) === true && in_array($value, $opValue, true),
            default => true,
        };
    }//end checkOp()

    /**
     * Coerce date-like scalars to integer timestamps for ordered comparisons.
     *
     * @param mixed $v The value to normalise.
     *
     * @return mixed Integer timestamp for date-like values, original otherwise.
     */
    private function normaliseForCompare(mixed $v): mixed
    {
        if ($v instanceof DateTimeInterface) {
            return $v->getTimestamp();
        }

        if (is_string($v) === true && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1) {
            try {
                return (new DateTimeImmutable($v))->getTimestamp();
            } catch (\Throwable) {
                return $v;
            }
        }

        return $v;
    }//end normaliseForCompare()

    /**
     * Try to compute the aggregation directly in SQL on the magic table.
     *
     * Supports: count/sum/avg/min/max + simple equality filters
     * + optional groupBy on a single field. Postgres only.
     *
     * Returns the result fragment ('value' or 'groups') on success, null
     * to signal the caller should fall back to PHP-side aggregation.
     *
     * @param Register                  $register Register the schema belongs to.
     * @param Schema                    $schema   Schema being aggregated.
     * @param string                    $metric   Metric name (count/sum/avg/min/max).
     * @param string|null               $field    Field to aggregate over (ignored for count).
     * @param array<string, mixed>      $filter   Already placeholder-resolved filter map.
     * @param array<string, mixed>|null $groupBy  Optional group spec ({field: ...}).
     *
     * @return array{value: int|float|null}|array{groups: array<int, array{key: mixed, value: int|float|null}>}|null
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function tryNativeAggregation(
        Register $register,
        Schema $schema,
        string $metric,
        ?string $field,
        array $filter,
        ?array $groupBy
    ): ?array {
        $platform = $this->db->getDatabasePlatform();
        if (stripos($platform::class, 'PostgreSQL') === false) {
            return null;
        }

        if (in_array($metric, ['count', 'sum', 'avg', 'min', 'max'], true) === false) {
            return null;
        }

        // Validate filter shapes are translatable. Supported:
        // {field: scalar}              → field = ?
        // {field: {in: [...]}}         → field IN (?, ?, ?)
        // {field: {gt|gte|lt|lte: x}}  → field > / >= / < / <= ?
        // {field: {ne: x}}             → field <> ?
        // Reject anything else.
        foreach ($filter as $value) {
            if (is_array($value) === true) {
                foreach (array_keys($value) as $op) {
                    if (in_array((string) $op, ['in', 'gt', 'gte', 'lt', 'lte', 'ne'], true) === false) {
                        return null;
                    }
                }
            }
        }

        $tableName = $this->magicMapper->getTableNameForRegisterSchema(
            register: $register,
            schema: $schema
        );
        if ($tableName === null || $tableName === '') {
            return null;
        }

        $fullTable = '"oc_'.$tableName.'"';

        $whereParts = ["(_deleted IS NULL OR _deleted = 'null'::jsonb)"];
        $bindings   = [];
        foreach ($filter as $f => $v) {
            $col = $this->sanitizeColumnName(name: (string) $f);
            if (is_array($v) === false) {
                $whereParts[] = '"'.$col.'" = ?';
                $bindings[]   = $this->bindValue(value: $v);
                continue;
            }

            foreach ($v as $op => $opValue) {
                if ($op === 'in') {
                    $list = is_array($opValue) === true ? $opValue : [];
                    if (count($list) === 0) {
                        // `in` with empty list never matches; emit a no-op
                        // condition that returns no rows.
                        $whereParts[] = '1 = 0';
                        continue;
                    }

                    $placeholders = implode(', ', array_fill(0, count($list), '?'));
                    $whereParts[] = '"'.$col.'" IN ('.$placeholders.')';
                    foreach ($list as $item) {
                        $bindings[] = $this->bindValue(value: $item);
                    }

                    continue;
                }

                $sqlOp = match ((string) $op) {
                    'gt'  => '>',
                    'gte' => '>=',
                    'lt'  => '<',
                    'lte' => '<=',
                    'ne'  => '<>',
                    default => null,
                };

                if ($sqlOp === null) {
                    continue;
                }

                $whereParts[] = '"'.$col.'" '.$sqlOp.' ?';
                $bindings[]   = $this->bindValue(value: $opValue);
            }//end foreach
        }//end foreach

        $whereSql = implode(' AND ', $whereParts);

        // Aggregate clause.
        $aggCol = $field !== null ? '"'.$this->sanitizeColumnName(name: $field).'"' : null;
        $aggSql = match ($metric) {
            'count' => 'COUNT(*)',
            'sum'   => 'SUM(NULLIF('.$aggCol.'::text, \'\')::numeric)',
            'avg'   => 'AVG(NULLIF('.$aggCol.'::text, \'\')::numeric)',
            'min'   => 'MIN(NULLIF('.$aggCol.'::text, \'\')::numeric)',
            'max'   => 'MAX(NULLIF('.$aggCol.'::text, \'\')::numeric)',
        };

        try {
            if ($groupBy !== null && isset($groupBy['field']) === true) {
                $groupCol = '"'.$this->sanitizeColumnName(name: (string) $groupBy['field']).'"';
                $sql      = "SELECT {$groupCol} AS bucket, {$aggSql} AS agg
                             FROM {$fullTable}
                             WHERE {$whereSql}
                             GROUP BY {$groupCol}";
                $stmt     = $this->db->prepare($sql);
                $stmt->execute($bindings);
                $groups = [];
                while (($row = $stmt->fetch()) !== false) {
                    $value = $row['agg'];
                    if ($metric !== 'count' && is_string($value) === true) {
                        $value = (float) $value;
                    } else if ($value !== null) {
                        $value = (int) $value;
                    }

                    $groups[] = ['key' => $row['bucket'], 'value' => $value];
                }

                return ['groups' => $groups];
            }//end if

            $sql  = "SELECT {$aggSql} AS agg FROM {$fullTable} WHERE {$whereSql}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);
            $row   = $stmt->fetch();
            $value = $row !== false ? $row['agg'] : null;
            if ($metric === 'count') {
                return ['value' => (int) ($value ?? 0)];
            }

            if ($value === null) {
                return ['value' => null];
            }

            return ['value' => is_string($value) === true ? (float) $value : $value];
        } catch (\Throwable $e) {
            // Native path failed (table not found, column not found, etc) —
            // tell the caller to fall back to PHP.
            return null;
        }//end try
    }//end tryNativeAggregation()

    /**
     * Convert a value to its SQL bind shape.
     *
     * DateTimeImmutable values come from the PlaceholderResolver
     * (e.g. `$startOfMonth`) — coerce them to ISO-8601 so they bind
     * cleanly against text/date columns. Other values pass through
     * as strings.
     *
     * @param mixed $value Raw value to bind.
     *
     * @return string SQL-ready string representation.
     */
    private function bindValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_bool($value) === true) {
            return $value === true ? 'true' : 'false';
        }

        return (string) $value;
    }//end bindValue()

    /**
     * Convert a property name to its magic-table column name. Mirrors
     * MagicMapper::sanitizeColumnName so we don't expose a public API there.
     *
     * @param string $name Raw property name.
     *
     * @return string Sanitised column name.
     */
    private function sanitizeColumnName(string $name): string
    {
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $name = strtolower((string) $name);
        $name = preg_replace('/[^a-z0-9_]/', '_', (string) $name);
        if (preg_match('/^[a-z_]/', $name) === 0) {
            $name = 'col_'.$name;
        }

        $name = preg_replace('/_+/', '_', $name);
        return rtrim((string) $name, '_');
    }//end sanitizeColumnName()

    /**
     * Load a schema by ref, throwing a RuntimeException when missing.
     *
     * @param string $schemaRef Schema slug/uuid/id.
     *
     * @return Schema The loaded schema.
     *
     * @throws RuntimeException When the schema can't be found.
     */
    private function loadSchema(string $schemaRef): Schema
    {
        try {
            return $this->schemaMapper->find($schemaRef, _multitenancy: false);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('Schema "%s" not found.', $schemaRef), 0, $e);
        }
    }//end loadSchema()

    /**
     * Load a register by ref, throwing a RuntimeException when missing.
     *
     * @param string $registerRef Register slug/uuid/id.
     *
     * @return Register The loaded register.
     *
     * @throws RuntimeException When the register can't be found.
     */
    private function loadRegister(string $registerRef): \OCA\OpenRegister\Db\Register
    {
        try {
            return $this->registerMapper->find($registerRef, _multitenancy: false);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('Register "%s" not found.', $registerRef), 0, $e);
        }
    }//end loadRegister()

    /**
     * Read the `x-openregister-aggregations` annotation off a schema.
     *
     * @param Schema $schema The schema to read.
     *
     * @return array<string, mixed>|null The annotation map, or null when absent.
     */
    private function getAnnotation(Schema $schema): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-aggregations'] ?? null);
        return is_array($value) === true ? $value : null;
    }//end getAnnotation()

    /**
     * Map a SearchBackendInterface implementation to its short backend
     * label for the result envelope. Falls back to `'external'` when the
     * concrete class name doesn't match a known prefix.
     *
     * @param SearchBackendInterface $backend The backend instance.
     *
     * @return string Short backend label ('solr', 'elasticsearch', or 'external').
     */
    private function detectBackendName(SearchBackendInterface $backend): string
    {
        $shortName = (new ReflectionClass($backend))->getShortName();
        if (str_contains($shortName, 'Solr') === true) {
            return 'solr';
        }

        if (str_contains($shortName, 'Elasticsearch') === true) {
            return 'elasticsearch';
        }

        return 'external';

    }//end detectBackendName()
}//end class
