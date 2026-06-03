<?php

/**
 * AggregationQuery — value object for ad-hoc aggregation requests.
 *
 * Carries the user-supplied parameters that describe a single aggregation
 * request.  The {@see toArray()} method produces a stable, order-normalised
 * representation used as the input to the ad-hoc cache key hash.
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
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Immutable value object representing a single aggregation request.
 *
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.1
 */
class AggregationQuery
{
    /**
     * Constructor.
     *
     * @param string      $metric     Aggregation metric ('count', 'sum', 'avg', 'min', 'max').
     * @param string|null $field      Field to aggregate on (required for sum/avg/min/max).
     * @param array       $filter     Key-value filter map to apply before aggregation.
     * @param string|null $groupBy    Field to group results by.
     * @param string|null $dateBucket Time-bucket gap ('minute','hour','day','month','year','week','quarter').
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.1
     */
    public function __construct(
        private readonly string $metric,
        private readonly ?string $field,
        private readonly array $filter,
        private readonly ?string $groupBy,
        private readonly ?string $dateBucket
    ) {
    }//end __construct()

    /**
     * Get the aggregation metric.
     *
     * @return string
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.1
     */
    public function getMetric(): string
    {
        return $this->metric;
    }//end getMetric()

    /**
     * Get the aggregation field.
     *
     * @return string|null
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.1
     */
    public function getField(): ?string
    {
        return $this->field;
    }//end getField()

    /**
     * Get the filter map.
     *
     * @return array
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.1
     */
    public function getFilter(): array
    {
        return $this->filter;
    }//end getFilter()

    /**
     * Get the group-by field.
     *
     * @return string|null
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.1
     */
    public function getGroupBy(): ?string
    {
        return $this->groupBy;
    }//end getGroupBy()

    /**
     * Get the date-bucket gap.
     *
     * @return string|null
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.1
     */
    public function getDateBucket(): ?string
    {
        return $this->dateBucket;
    }//end getDateBucket()

    /**
     * Return a stable associative array used as the cache-key hash input.
     *
     * Filter sub-arrays are ksort-sorted recursively so two structurally-
     * equivalent filters hash identically regardless of key insertion order.
     *
     * @return array{metric: string, field: string|null, filter: array, groupBy: string|null, dateBucket: string|null}
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.1
     */
    public function toArray(): array
    {
        return [
            'metric'     => $this->metric,
            'field'      => $this->field,
            'filter'     => $this->sortFilterRecursive(filter: $this->filter),
            'groupBy'    => $this->groupBy,
            'dateBucket' => $this->dateBucket,
        ];
    }//end toArray()

    /**
     * Recursively ksort a filter array so key order does not affect the hash.
     *
     * @param array $filter Filter array to sort.
     *
     * @return array
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-2.1
     */
    private function sortFilterRecursive(array $filter): array
    {
        ksort($filter);
        foreach ($filter as $key => $value) {
            if (is_array(value: $value) === true) {
                $filter[$key] = $this->sortFilterRecursive(filter: $value);
            }
        }

        return $filter;
    }//end sortFilterRecursive()
}//end class
