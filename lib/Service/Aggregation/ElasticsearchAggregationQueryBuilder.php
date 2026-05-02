<?php

/**
 * ElasticsearchAggregationQueryBuilder — translate AggregationQuery → ES query DSL.
 *
 * Turns an AggregationQuery value object into the JSON body posted to
 * the ES `/<index>/_search` endpoint.
 *
 *   - count + ungrouped     → `{ size: 0, track_total_hits: true, query: <bool> }`
 *   - count + groupBy       → `{ size: 0, query: <bool>, aggs: { <bucket>: { terms: {...} } } }`
 *   - sum/avg/min/max       → `{ size: 0, aggs: { metric: { <metric>: { field: ... } } } }`
 *   - sum/avg/min/max + grp → `{ size: 0, aggs: { <bucket>: { terms: {...},
 *                              aggs: { metric: { <metric>: { field: ... } } } } } }`
 *
 * Pure-PHP translation. The ES backend wraps a thin HTTP client around
 * this builder. Unit-testable independently.
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
 * @spec openspec/changes/aggregations-backend-native/tasks.md "ElasticsearchBackend::aggregate"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Builds Elasticsearch query DSL from an AggregationQuery.
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Each branch in build() emits a *different* aggs nesting shape (top-level metric vs. nested-under-terms); flattening would duplicate the metric-spec construction.
 */
class ElasticsearchAggregationQueryBuilder
{
    /**
     * Translate an AggregationQuery into an ES `_search` request body.
     *
     * @param AggregationQuery $query The cross-backend request.
     *
     * @return array<string, mixed> The ES request body.
     */
    public function build(AggregationQuery $query): array
    {
        $body = [
            'size'             => 0,
            'track_total_hits' => true,
        ];

        $bool = $this->translateFilters(filter: $query->filter);
        if ($bool !== []) {
            $body['query'] = ['bool' => $bool];
        }

        if ($query->metric === AggregationQuery::METRIC_COUNT) {
            if ($query->isGrouped() === true) {
                $field        = (string) $query->getGroupByField();
                $body['aggs'] = [
                    $field => [
                        'terms' => ['field' => $field, 'size' => 1000],
                    ],
                ];
            }

            if ($query->hasDateBucket() === true) {
                $bucket       = $query->dateBucket;
                $field        = (string) $bucket['field'];
                $body['aggs'] = [
                    $field => [
                        'date_histogram' => [
                            'field'             => $field,
                            'calendar_interval' => (string) $bucket['gap'],
                            'extended_bounds'   => [
                                'min' => (string) $bucket['start'],
                                'max' => (string) $bucket['end'],
                            ],
                        ],
                    ],
                ];
            }

            return $body;
        }//end if

        // Non-count metrics use the matching ES metric aggregation.
        $field      = (string) $query->field;
        $metricKey  = 'metric_'.$query->metric;
        $metricSpec = [
            $metricKey => [$query->metric => ['field' => $field]],
        ];

        if ($query->isGrouped() === true) {
            $groupField   = (string) $query->getGroupByField();
            $body['aggs'] = [
                $groupField => [
                    'terms' => ['field' => $groupField, 'size' => 1000],
                    'aggs'  => $metricSpec,
                ],
            ];
        } else {
            $body['aggs'] = $metricSpec;
        }

        return $body;

    }//end build()

    /**
     * Translate the filter map into a `bool` query.
     *
     * @param array<string, mixed> $filter The filter map.
     *
     * @return array<string, mixed> The `bool` clause body, or empty array when no filters.
     */
    public function translateFilters(array $filter): array
    {
        $must    = [];
        $mustNot = [];

        foreach ($filter as $field => $value) {
            if (is_array($value) === false) {
                $must[] = ['term' => [(string) $field => $value]];
                continue;
            }

            foreach ($value as $op => $opValue) {
                $this->collectOp(
                    field: (string) $field,
                    op: (string) $op,
                    value: $opValue,
                    must: $must,
                    mustNot: $mustNot
                );
            }
        }

        $bool = [];
        if ($must !== []) {
            $bool['must'] = $must;
        }

        if ($mustNot !== []) {
            $bool['must_not'] = $mustNot;
        }

        return $bool;

    }//end translateFilters()

    /**
     * Add the (field, op, value) triple to the must / must_not lists.
     *
     * @param string $field   The field name.
     * @param string $op      The operator.
     * @param mixed  $value   The operand.
     * @param array  $must    Accumulator for must clauses.
     * @param array  $mustNot Accumulator for must_not clauses.
     *
     * @return void
     */
    private function collectOp(string $field, string $op, mixed $value, array &$must, array &$mustNot): void
    {
        switch ($op) {
            case 'in':
                $list = is_array($value) === true ? $value : [];
                if (count($list) === 0) {
                    // `in` with empty list never matches.
                    $must[] = ['term' => ['_or_no_match_' => '___']];
                    return;
                }

                $must[] = ['terms' => [$field => array_values($list)]];
                return;
            case 'ne':
                $mustNot[] = ['term' => [$field => $value]];
                return;
            case 'gt':
                $must[] = ['range' => [$field => ['gt' => $value]]];
                return;
            case 'gte':
                $must[] = ['range' => [$field => ['gte' => $value]]];
                return;
            case 'lt':
                $must[] = ['range' => [$field => ['lt' => $value]]];
                return;
            case 'lte':
                $must[] = ['range' => [$field => ['lte' => $value]]];
                return;
            default:
                return;
        }//end switch

    }//end collectOp()
}//end class
