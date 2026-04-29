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
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IDBConnection;
use RuntimeException;

/**
 * Runs a named aggregation against a schema.
 */
class AggregationRunner
{

    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly PlaceholderResolver $placeholders,
        private readonly IDBConnection $db,
        private readonly AggregationCache $cache
    ) {}//end __construct()

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
     */
    public function run(string $registerRef, string $schemaRef, string $name): array
    {
        $schema     = $this->loadSchema($schemaRef);
        $register   = $this->loadRegister($registerRef);
        $annotation = $this->getAnnotation($schema);
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
        $cached = $this->cache->get(
            registerSlug: (string) $register->getSlug(),
            schemaSlug: (string) $schema->getSlug(),
            name: $name,
            filter: $cacheKey
        );
        if ($cached !== null) {
            $cached['cached'] = true;
            return $cached;
        }

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
        $rows = $this->applyFilter($rows, $resolvedFilter);

        if (is_array($groupBy) === true && isset($groupBy['field']) === true) {
            $result = [
                'name'    => $name,
                'metric'  => $metric,
                'field'   => is_string($field) === true ? $field : null,
                'backend' => 'php-fallback',
                'groups'  => $this->computeGrouped($rows, $metric, $field, (string) $groupBy['field']),
            ];
        } else {
            $result = [
                'name'    => $name,
                'metric'  => $metric,
                'field'   => is_string($field) === true ? $field : null,
                'backend' => 'php-fallback',
                'value'   => $this->computeMetric($rows, $metric, $field),
            ];
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
     * @param array<int, array<string, mixed>> $rows
     */
    private function computeMetric(array $rows, string $metric, mixed $field): int|float|null
    {
        return match ($metric) {
            'count'          => count($rows),
            'sum'            => $this->reduceNumeric($rows, (string) $field, fn(float $a, float $b) => $a + $b, 0.0),
            'avg'            => $this->avg($rows, (string) $field),
            'min'            => $this->reduceNumeric($rows, (string) $field, fn(float $a, float $b) => min($a, $b), null),
            'max'            => $this->reduceNumeric($rows, (string) $field, fn(float $a, float $b) => max($a, $b), null),
            'count_distinct' => count(array_unique(array_filter(array_map(fn(array $r) => $r[(string) $field] ?? null, $rows), fn($v) => $v !== null), SORT_REGULAR)),
            default          => null,
        };
    }//end computeMetric()

    /**
     * @param array<int, array<string, mixed>> $rows
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
                'value' => $this->computeMetric($b['rows'], $metric, $field),
            ];
        }
        return $out;
    }//end computeGrouped()

    /**
     * @param array<int, array<string, mixed>> $rows
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
     * @param array<int, array<string, mixed>> $rows
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
     * Translate the spec's filter map to the shape ObjectEntityMapper
     * understands (equality only here; range/in operators are applied
     * in PHP via applyFilter).
     *
     * @param array<string, mixed> $filter
     *
     * @return array<string, mixed>
     */
    private function shapeFilters(array $filter): array
    {
        $simple = [];
        foreach ($filter as $field => $value) {
            if (is_array($value) === false) {
                $simple[$field] = $value;
            }
        }
        return $simple;
    }//end shapeFilters()

    /**
     * Apply operator-style filters (gte/lte/gt/lt/in/ne) in PHP.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed>             $filter
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyFilter(array $rows, array $filter): array
    {
        $result = [];
        foreach ($rows as $row) {
            $keep = true;
            foreach ($filter as $field => $criterion) {
                $value = $row[$field] ?? null;
                if (is_array($criterion) === false) {
                    if ($value !== $criterion) { $keep = false; break; }
                    continue;
                }

                foreach ($criterion as $op => $opValue) {
                    if ($this->checkOp($value, (string) $op, $opValue) === false) {
                        $keep = false; break 2;
                    }
                }
            }
            if ($keep === true) {
                $result[] = $row;
            }
        }
        return $result;
    }//end applyFilter()

    private function checkOp(mixed $value, string $op, mixed $opValue): bool
    {
        $cmp = $this->normaliseForCompare($value);
        $rhs = $this->normaliseForCompare($opValue);
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
     * @param array<string, mixed>      $filter  Already placeholder-resolved.
     * @param array<string, mixed>|null $groupBy
     *
     * @return array{value: int|float|null}|array{groups: array<int, array{key: mixed, value: int|float|null}>}|null
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
        //   {field: scalar}              → field = ?
        //   {field: {in: [...]}}         → field IN (?, ?, ?)
        //   {field: {gt|gte|lt|lte: x}}  → field > / >= / < / <= ?
        //   {field: {ne: x}}             → field <> ?
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

        $whereParts  = ["(_deleted IS NULL OR _deleted = 'null'::jsonb)"];
        $bindings    = [];
        foreach ($filter as $f => $v) {
            $col = $this->sanitizeColumnName((string) $f);
            if (is_array($v) === false) {
                $whereParts[] = '"'.$col.'" = ?';
                $bindings[]   = $this->bindValue($v);
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
                        $bindings[] = $this->bindValue($item);
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
                $bindings[]   = $this->bindValue($opValue);
            }
        }
        $whereSql = implode(' AND ', $whereParts);

        // Aggregate clause.
        $aggCol = $field !== null ? '"'.$this->sanitizeColumnName($field).'"' : null;
        $aggSql = match ($metric) {
            'count' => 'COUNT(*)',
            'sum'   => 'SUM(NULLIF('.$aggCol.'::text, \'\')::numeric)',
            'avg'   => 'AVG(NULLIF('.$aggCol.'::text, \'\')::numeric)',
            'min'   => 'MIN(NULLIF('.$aggCol.'::text, \'\')::numeric)',
            'max'   => 'MAX(NULLIF('.$aggCol.'::text, \'\')::numeric)',
        };

        try {
            if ($groupBy !== null && isset($groupBy['field']) === true) {
                $groupCol = '"'.$this->sanitizeColumnName((string) $groupBy['field']).'"';
                $sql      = "SELECT {$groupCol} AS bucket, {$aggSql} AS agg
                             FROM {$fullTable}
                             WHERE {$whereSql}
                             GROUP BY {$groupCol}";
                $stmt     = $this->db->prepare($sql);
                $stmt->execute($bindings);
                $groups   = [];
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
            }

            $sql  = "SELECT {$aggSql} AS agg FROM {$fullTable} WHERE {$whereSql}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($bindings);
            $row = $stmt->fetch();
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
        }
    }//end tryNativeAggregation()

    /**
     * Convert a value to its SQL bind shape.
     *
     * DateTimeImmutable values come from the PlaceholderResolver
     * (e.g. `$startOfMonth`) — coerce them to ISO-8601 so they bind
     * cleanly against text/date columns. Other values pass through
     * as strings.
     */
    private function bindValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if (is_bool($value) === true) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }//end bindValue()

    /**
     * Convert a property name to its magic-table column name. Mirrors
     * MagicMapper::sanitizeColumnName so we don't expose a public API there.
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

    private function loadSchema(string $schemaRef): Schema
    {
        try {
            return $this->schemaMapper->find($schemaRef, _multitenancy: false);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('Schema "%s" not found.', $schemaRef), 0, $e);
        }
    }//end loadSchema()

    private function loadRegister(string $registerRef): \OCA\OpenRegister\Db\Register
    {
        try {
            return $this->registerMapper->find($registerRef, _multitenancy: false);
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('Register "%s" not found.', $registerRef), 0, $e);
        }
    }//end loadRegister()

    /**
     * @return array<string, mixed>|null
     */
    private function getAnnotation(Schema $schema): ?array
    {
        $config = ($schema->getConfiguration() ?? []);
        $value  = ($config['x-openregister-aggregations'] ?? null);
        return is_array($value) === true ? $value : null;
    }//end getAnnotation()

}//end class
