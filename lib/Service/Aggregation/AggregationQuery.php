<?php

/**
 * AggregationQuery — cross-backend aggregation request value object.
 *
 * Captures the parameters of a single aggregation request in a shape
 * that's portable across backend implementations (Postgres / Solr /
 * Elasticsearch). Each backend has its own translator that turns this
 * value object into native query parameters.
 *
 * Supported metrics: count / sum / avg / min / max.
 * Supported filter operators (per field): scalar equality + in / notIn /
 * gt / gte / lt / lte / ne (mirrors the inline magic-table SQL path that
 * lived in `AggregationRunner::tryNativeAggregation`). `in` and `notIn`
 * take an array operand; the rest take a scalar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md "SearchBackendInterface::aggregate"
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use InvalidArgumentException;

/**
 * Backend-portable aggregation request.
 *
 * @spec openspec/changes/aggregation-multi-field-groupby/specs/aggregation-api/spec.md
 */
class AggregationQuery
{

    public const METRIC_COUNT = 'count';

    public const METRIC_SUM = 'sum';

    public const METRIC_AVG = 'avg';

    public const METRIC_MIN = 'min';

    public const METRIC_MAX = 'max';

    private const METRICS = [
        self::METRIC_COUNT,
        self::METRIC_SUM,
        self::METRIC_AVG,
        self::METRIC_MIN,
        self::METRIC_MAX,
    ];

    /**
     * Allowed gap units for dateBucket. Aligns with Solr's `facet.range.gap`
     * and ES's `date_histogram.calendar_interval` vocabularies.
     *
     * @var string[]
     */
    private const DATE_BUCKET_GAPS = ['minute', 'hour', 'day', 'week', 'month', 'quarter', 'year'];

    /**
     * Constructor — use the static factory.
     *
     * @param string                    $metric     Aggregation metric.
     * @param ?string                   $field      Field for sum/avg/min/max; required when metric != count.
     * @param array<string, mixed>      $filter     Filter conditions (see class docblock for shapes).
     * @param array<string, mixed>|null $groupBy    Optional grouping spec. Accepts a single-field shape
     *                                              (`{field: 'status'}`), a multi-field cross-tab shape
     *                                              (`{fields: ['vendorId', 'dueDateBucket']}`), or a plain
     *                                              ordered list of field names (`['vendorId', 'dueDateBucket']`).
     * @param array<string, mixed>|null $dateBucket Optional date-bucket spec; `{field, start, end, gap}`.
     */
    private function __construct(
        public readonly string $metric,
        public readonly ?string $field,
        public readonly array $filter,
        public readonly ?array $groupBy,
        public readonly ?array $dateBucket=null
    ) {

    }//end __construct()

    /**
     * Construct an aggregation query — fails fast on bad input.
     *
     * @param string                    $metric     One of METRIC_*.
     * @param ?string                   $field      Field for non-count metrics; null for count.
     * @param array<string, mixed>      $filter     Filter map.
     * @param array<string, mixed>|null $groupBy    Optional groupBy. Single-field (`{field: 'x'}`),
     *                                              multi-field (`{fields: ['a','b']}`), or a plain list
     *                                              (`['a','b']`). Multi-field yields one grouped row per
     *                                              distinct field tuple.
     * @param array<string, mixed>|null $dateBucket Optional dateBucket spec `{field, start, end, gap}`.
     *
     * @return self
     *
     * @throws InvalidArgumentException When the input is invalid.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *   Fail-fast validation chain: each `if` is one independent guard
     *   against bad input (including the single-/multi-field groupBy shape
     *   checks). Extracting them would reduce the count but obscure the
     *   per-rule error messages.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
     * @spec openspec/changes/aggregation-multi-field-groupby/specs/aggregation-api/spec.md
     */
    public static function create(
        string $metric,
        ?string $field=null,
        array $filter=[],
        ?array $groupBy=null,
        ?array $dateBucket=null
    ): self {
        if (in_array($metric, self::METRICS, true) === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'aggregation metric MUST be one of: %s (got: %s)',
                    implode(', ', self::METRICS),
                    $metric
                )
            );
        }

        if ($metric !== self::METRIC_COUNT && ($field === null || $field === '')) {
            throw new InvalidArgumentException(
                sprintf('aggregation metric "%s" MUST specify a field', $metric)
            );
        }

        if ($groupBy !== null) {
            $groupFields = self::normaliseGroupByFields(groupBy: $groupBy);
            foreach ($groupFields as $groupField) {
                if (is_string($groupField) === false || $groupField === '') {
                    throw new InvalidArgumentException(
                        'groupBy MUST include a non-empty `field`; every group field MUST be a non-empty string'
                    );
                }
            }

            if (count($groupFields) === 0) {
                throw new InvalidArgumentException(
                    'groupBy MUST include a non-empty `field` or a non-empty `fields` list'
                );
            }

            if (count($groupFields) !== count(array_unique($groupFields))) {
                throw new InvalidArgumentException('groupBy fields MUST be distinct (duplicate field in groupBy list)');
            }
        }//end if

        if ($dateBucket !== null) {
            self::assertValidDateBucket(spec: $dateBucket);
        }

        if ($groupBy !== null && $dateBucket !== null) {
            throw new InvalidArgumentException(
                'groupBy and dateBucket MUST NOT be combined — pick one bucketing strategy'
            );
        }

        return new self(
            metric: $metric,
            field: $field,
            filter: $filter,
            groupBy: $groupBy,
            dateBucket: $dateBucket
        );

    }//end create()

    /**
     * Validate a dateBucket spec.
     *
     * @param array<string, mixed> $spec The dateBucket spec.
     *
     * @return void
     *
     * @throws InvalidArgumentException When malformed.
     */
    private static function assertValidDateBucket(array $spec): void
    {
        foreach (['field', 'start', 'end', 'gap'] as $required) {
            if (isset($spec[$required]) === false || $spec[$required] === '') {
                throw new InvalidArgumentException(
                    'dateBucket MUST include non-empty `field`, `start`, `end`, `gap`'
                );
            }
        }

        if (in_array($spec['gap'], self::DATE_BUCKET_GAPS, true) === false) {
            throw new InvalidArgumentException(
                sprintf(
                    'dateBucket gap MUST be one of: %s (got: %s)',
                    implode(', ', self::DATE_BUCKET_GAPS),
                    (string) $spec['gap']
                )
            );
        }

    }//end assertValidDateBucket()

    /**
     * Test whether the request includes a groupBy clause.
     *
     * @return bool
     *
     * @spec openspec/changes/aggregation-multi-field-groupby/specs/aggregation-api/spec.md
     */
    public function isGrouped(): bool
    {
        return ($this->groupBy !== null);

    }//end isGrouped()

    /**
     * Get the FIRST groupBy field (or null when ungrouped).
     *
     * Retained for backward compatibility with single-field callers. For a
     * multi-field cross-tab groupBy this returns the first grouping field
     * only; use {@see getGroupByFields()} to obtain the full ordered tuple.
     *
     * @return ?string
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-18
     * @spec openspec/changes/aggregation-multi-field-groupby/specs/aggregation-api/spec.md
     */
    public function getGroupByField(): ?string
    {
        $fields = $this->getGroupByFields();
        if (count($fields) === 0) {
            return null;
        }

        return $fields[0];

    }//end getGroupByField()

    /**
     * Get the ordered list of groupBy fields (empty when ungrouped).
     *
     * Normalises every accepted groupBy shape to a flat, ordered list of
     * field names:
     *  - `{field: 'status'}`                  → `['status']`
     *  - `{fields: ['vendorId', 'bucket']}`   → `['vendorId', 'bucket']`
     *  - `['vendorId', 'bucket']`             → `['vendorId', 'bucket']`
     *  - `null`                               → `[]`
     *
     * @return array<int, string> Ordered group field names.
     *
     * @spec openspec/changes/aggregation-multi-field-groupby/specs/aggregation-api/spec.md
     */
    public function getGroupByFields(): array
    {
        if ($this->groupBy === null) {
            return [];
        }

        $fields = [];
        foreach (self::normaliseGroupByFields(groupBy: $this->groupBy) as $field) {
            if (is_string($field) === true) {
                $fields[] = $field;
            }
        }

        return $fields;

    }//end getGroupByFields()

    /**
     * Test whether this is a multi-field (cross-tab) groupBy.
     *
     * @return bool True when more than one grouping field is present.
     *
     * @spec openspec/changes/aggregation-multi-field-groupby/specs/aggregation-api/spec.md
     */
    public function isMultiFieldGroupBy(): bool
    {
        return (count($this->getGroupByFields()) > 1);

    }//end isMultiFieldGroupBy()

    /**
     * Normalise any accepted groupBy shape to a flat, ordered candidate list.
     *
     * Shared canonicaliser used by both the value object and the runner so
     * the single-field, multi-field, and plain-list shapes are honoured
     * identically across the native-SQL and PHP-fallback paths. Returns the
     * raw candidate values (which the caller validates) rather than filtering
     * silently — dropping invalid entries here would reintroduce the very
     * silent-ignore bug this feature fixes.
     *
     * @param mixed $groupBy The raw groupBy spec (array, list, or null).
     *
     * @return array<int, mixed> Ordered candidate field values.
     *
     * @spec openspec/changes/aggregation-multi-field-groupby/specs/aggregation-api/spec.md
     */
    public static function normaliseGroupByFields(mixed $groupBy): array
    {
        if (is_array($groupBy) === false) {
            return [];
        }

        // Plain ordered list of field names: `['a', 'b']`.
        if (array_is_list($groupBy) === true) {
            return $groupBy;
        }

        // Multi-field cross-tab shape: `{fields: ['a', 'b']}`.
        if (isset($groupBy['fields']) === true && is_array($groupBy['fields']) === true) {
            return array_values($groupBy['fields']);
        }

        // Single-field shape: `{field: 'a'}`.
        if (array_key_exists('field', $groupBy) === true) {
            return [$groupBy['field']];
        }

        return [];

    }//end normaliseGroupByFields()

    /**
     * Test whether the request includes a dateBucket clause.
     *
     * @return bool
     *
     * @spec openspec/changes/aggregation-multi-field-groupby/specs/aggregation-api/spec.md
     */
    public function hasDateBucket(): bool
    {
        return ($this->dateBucket !== null);

    }//end hasDateBucket()

    /**
     * Serialise the query to a stable associative array.
     *
     * The output is the canonical input to the ad-hoc aggregation cache
     * key (see {@see AggregationCache::getAdhoc()}). Filter sub-arrays are
     * recursively ksort-sorted so two structurally-equivalent queries
     * produce identical JSON encodings — `{a: 1, b: 2}` and `{b: 2, a: 1}`
     * hash to the same cache key.
     *
     * @return array{
     *   metric: string,
     *   field: ?string,
     *   filter: array<string, mixed>,
     *   groupBy: array<string, mixed>|null,
     *   dateBucket: array<string, mixed>|null
     * } Canonical wire shape of the query.
     *
     * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-1
     */
    public function toArray(): array
    {
        return [
            'metric'     => $this->metric,
            'field'      => $this->field,
            'filter'     => self::canonicaliseFilter(filter: $this->filter),
            'groupBy'    => $this->groupBy,
            'dateBucket' => $this->dateBucket,
        ];

    }//end toArray()

    /**
     * Recursively ksort the filter map.
     *
     * Operator sub-arrays (e.g. `{gt: 5, lte: 10}`) get the same treatment
     * so the resulting JSON encoding is stable across input orderings.
     *
     * @param array<string, mixed> $filter Filter map to canonicalise.
     *
     * @return array<string, mixed> Sorted filter map.
     */
    private static function canonicaliseFilter(array $filter): array
    {
        ksort($filter);
        foreach ($filter as $key => $value) {
            if (is_array($value) === true) {
                $sub = $value;
                ksort($sub);
                $filter[$key] = $sub;
            }
        }

        return $filter;

    }//end canonicaliseFilter()
}//end class
