<?php

/**
 * AggregationQuery
 *
 * Immutable value object representing a single aggregation request in a
 * backend-portable shape. Static factory ::create() enforces the constraint
 * set (metric ∈ {count,sum,avg,min,max}; non-count requires a field;
 * groupBy requires a non-empty field key).
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
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use InvalidArgumentException;

/**
 * Immutable value object for a backend-portable aggregation request.
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-1
 */
class AggregationQuery
{

    /**
     * Allowed metric names.
     *
     * @var string[]
     */
    private const ALLOWED_METRICS = ['count', 'sum', 'avg', 'min', 'max'];

    /**
     * Private constructor — use AggregationQuery::create().
     *
     * @param string      $metric  Aggregation metric.
     * @param string|null $field   Field to aggregate on (required for non-count metrics).
     * @param array       $filter  Already-compiled filter map.
     * @param array|null  $groupBy Optional group-by spec: {field: string, bucket?: string}.
     */
    private function __construct(
        public readonly string $metric,
        public readonly ?string $field,
        public readonly array $filter,
        public readonly ?array $groupBy
    ) {
    }//end __construct()

    /**
     * Create a validated AggregationQuery.
     *
     * @param string      $metric  Aggregation metric: count|sum|avg|min|max.
     * @param string|null $field   Field to aggregate; required for non-count metrics.
     * @param array       $filter  Already-compiled filter map (operator-keyed).
     * @param array|null  $groupBy Optional {field: string, bucket?: day|week|month|year}.
     *
     * @return self
     *
     * @throws InvalidArgumentException When constraints are violated.
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-1
     */
    public static function create(
        string $metric,
        ?string $field=null,
        array $filter=[],
        ?array $groupBy=null
    ): self {
        if (in_array(needle: $metric, haystack: self::ALLOWED_METRICS, strict: true) === false) {
            throw new InvalidArgumentException(
                'Invalid metric "'.$metric.'". Allowed: '.implode(separator: ', ', array: self::ALLOWED_METRICS)
            );
        }

        if ($metric !== 'count' && (empty($field) === true)) {
            throw new InvalidArgumentException(
                'Non-count metric "'.$metric.'" requires a field.'
            );
        }

        if ($groupBy !== null && empty($groupBy['field']) === true) {
            throw new InvalidArgumentException(
                'groupBy requires a non-empty "field" key.'
            );
        }

        return new self(
            metric: $metric,
            field: $field,
            filter: $filter,
            groupBy: $groupBy
        );
    }//end create()
}//end class
