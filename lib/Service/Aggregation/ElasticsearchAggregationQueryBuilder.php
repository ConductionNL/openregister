<?php

/**
 * ElasticsearchAggregationQueryBuilder
 *
 * Translates an AggregationQuery value object into an Elasticsearch _search request body.
 * The resulting array is JSON-serialised and posted to the ES _search endpoint.
 *
 * Supported translation:
 *   count           → { size:0, track_total_hits:true, query:{bool:{...}} }
 *   count+groupBy   → aggs.<field>.terms (bucket aggregation)
 *   sum/avg/min/max → aggs.metric_<metric>.<metric>:{field} (metric aggregation)
 *   grouped stats   → nested terms + metric agg
 *   Filters         → bool.must: term / terms / range / must_not.term
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
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Translates an AggregationQuery into an ES _search request body.
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-4
 */
class ElasticsearchAggregationQueryBuilder
{
    /**
     * Build the ES request body for an aggregation request.
     *
     * @param AggregationQuery $query Aggregation query to translate.
     *
     * @return array ES _search request body.
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-4
     */
    public function build(AggregationQuery $query): array
    {
        $body = [
            'size'             => 0,
            'track_total_hits' => true,
        ];

        // Build query clause from filters.
        $boolClauses = $this->buildBoolQuery(filters: $query->filter);
        if (empty($boolClauses) === false) {
            $body['query'] = ['bool' => $boolClauses];
        }

        // Build aggregation clause.
        $body['aggs'] = $this->buildAggs(query: $query);

        return $body;
    }//end build()

    /**
     * Build the ES bool query from a filter map.
     *
     * @param array $filters Filter map: {field: value|{operator: operand}}.
     *
     * @return array Bool query clauses (must / must_not).
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-4
     */
    public function buildBoolQuery(array $filters): array
    {
        $must    = [];
        $mustNot = [];

        foreach ($filters as $field => $value) {
            if (is_array($value) === false) {
                // Scalar equality → term.
                $must[] = ['term' => [$field => $value]];
                continue;
            }

            if (isset($value['in']) === true) {
                if (empty($value['in']) === true) {
                    // Empty in-list → never matches (term against impossible value).
                    $must[] = ['term' => [$field => '__EMPTY_IN_NEVER_MATCH__']];
                } else {
                    $must[] = ['terms' => [$field => $value['in']]];
                }

                continue;
            }

            if (isset($value['ne']) === true) {
                $mustNot[] = ['term' => [$field => $value['ne']]];
                continue;
            }

            // Range operators.
            $range = [];
            if (isset($value['gte']) === true) {
                $range['gte'] = $value['gte'];
            }

            if (isset($value['gt']) === true) {
                $range['gt'] = $value['gt'];
            }

            if (isset($value['lte']) === true) {
                $range['lte'] = $value['lte'];
            }

            if (isset($value['lt']) === true) {
                $range['lt'] = $value['lt'];
            }

            if (empty($range) === false) {
                $must[] = ['range' => [$field => $range]];
            }
        }//end foreach

        $bool = [];
        if (empty($must) === false) {
            $bool['must'] = $must;
        }

        if (empty($mustNot) === false) {
            $bool['must_not'] = $mustNot;
        }

        return $bool;
    }//end buildBoolQuery()

    /**
     * Build the ES aggs clause for the given query.
     *
     * @param AggregationQuery $query Aggregation query.
     *
     * @return array ES aggs clause.
     */
    private function buildAggs(AggregationQuery $query): array
    {
        if ($query->metric === 'count' && $query->groupBy === null) {
            // Pure count — no extra agg needed; total_hits covers it.
            return [];
        }

        if ($query->metric === 'count' && $query->groupBy !== null) {
            // Count with group-by → terms bucket agg.
            return [
                $query->groupBy['field'] => [
                    'terms' => [
                        'field' => $query->groupBy['field'],
                        'size'  => 10000,
                    ],
                ],
            ];
        }

        $metricAgg = [$query->metric => ['field' => $query->field]];

        if ($query->groupBy === null) {
            // Ungrouped metric.
            return ['metric_'.$query->metric => $metricAgg];
        }

        // Grouped metric → nested terms + metric agg.
        return [
            $query->groupBy['field'] => [
                'terms' => [
                    'field' => $query->groupBy['field'],
                    'size'  => 10000,
                ],
                'aggs'  => ['metric_'.$query->metric => $metricAgg],
            ],
        ];
    }//end buildAggs()
}//end class
