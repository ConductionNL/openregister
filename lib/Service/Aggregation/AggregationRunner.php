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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use RuntimeException;

/**
 * Runs a named aggregation against a schema.
 */
final class AggregationRunner
{

    public function __construct(
        private readonly MagicMapper $magicMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly PlaceholderResolver $placeholders
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

        // Pull all objects in (register, schema) and filter in PHP.
        // V1 trades performance for correctness; backend-native
        // aggregation comes in a follow-up.
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
            return [
                'name'   => $name,
                'metric' => $metric,
                'field'  => is_string($field) === true ? $field : null,
                'groups' => $this->computeGrouped($rows, $metric, $field, (string) $groupBy['field']),
            ];
        }

        return [
            'name'   => $name,
            'metric' => $metric,
            'field'  => is_string($field) === true ? $field : null,
            'value'  => $this->computeMetric($rows, $metric, $field),
        ];
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
