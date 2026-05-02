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
 * Supported filter operators (per field): scalar equality + in / gt /
 * gte / lt / lte / ne (mirrors the inline magic-table SQL path that
 * lived in `AggregationRunner::tryNativeAggregation`).
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use InvalidArgumentException;

/**
 * Backend-portable aggregation request.
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
     * @param array<string, mixed>|null $groupBy    Optional grouping spec; e.g. `{field: 'status'}`.
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
     * @param array<string, mixed>|null $groupBy    Optional groupBy.
     * @param array<string, mixed>|null $dateBucket Optional dateBucket spec `{field, start, end, gap}`.
     *
     * @return self
     *
     * @throws InvalidArgumentException When the input is invalid.
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

        if ($groupBy !== null && (isset($groupBy['field']) === false || $groupBy['field'] === '')) {
            throw new InvalidArgumentException('groupBy MUST include a non-empty `field`');
        }

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
     */
    public function isGrouped(): bool
    {
        return ($this->groupBy !== null);

    }//end isGrouped()

    /**
     * Get the groupBy field (or null when ungrouped).
     *
     * @return ?string
     */
    public function getGroupByField(): ?string
    {
        if ($this->groupBy === null) {
            return null;
        }

        $field = ($this->groupBy['field'] ?? null);
        return is_string($field) === true ? $field : null;

    }//end getGroupByField()

    /**
     * Test whether the request includes a dateBucket clause.
     *
     * @return bool
     */
    public function hasDateBucket(): bool
    {
        return ($this->dateBucket !== null);

    }//end hasDateBucket()
}//end class
