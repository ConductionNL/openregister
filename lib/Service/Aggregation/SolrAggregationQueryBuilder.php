<?php

/**
 * SolrAggregationQueryBuilder
 *
 * Translates an AggregationQuery value object into a Solr request-parameter map.
 * The resulting map is passed directly to the Solr HTTP client via query string.
 *
 * Supported translation:
 *   count             → rows=0&q=*:*&fq=<filters>
 *   count+groupBy     → facet=true&facet.field=<col>&facet.mincount=1
 *   sum/avg/min/max   → StatsComponent (stats=true&stats.field=<col>) for ungrouped;
 *                       JSON Facet API for grouped
 *   Filters           → fq clauses: scalar, in-list, range operators, ne
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
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Translates an AggregationQuery into a Solr request-parameter map.
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-3
 */
class SolrAggregationQueryBuilder
{
    /**
     * Build the Solr query parameter map for an aggregation request.
     *
     * @param AggregationQuery $query Aggregation query to translate.
     *
     * @return array<string, string|int> Solr request parameter map.
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-3
     */
    public function build(AggregationQuery $query): array
    {
        $params = [
            'wt'   => 'json',
            'rows' => 0,
            'q'    => '*:*',
        ];

        // Translate filter map to fq clauses.
        $fq = $this->buildFilterQueries(filters: $query->filter);
        if (empty($fq) === false) {
            $params['fq'] = implode(separator: ' AND ', array: $fq);
        }

        if ($query->metric === 'count' && $query->groupBy === null) {
            // Plain count — rows=0 is sufficient; Solr returns numFound.
            return $params;
        }

        if ($query->metric === 'count' && $query->groupBy !== null) {
            // Count + groupBy → facet.field.
            $params['facet']          = 'true';
            $params['facet.field']    = $query->groupBy['field'];
            $params['facet.mincount'] = 1;
            $params['facet.limit']    = -1;
            return $params;
        }

        if ($query->groupBy === null) {
            // Ungrouped sum/avg/min/max → Solr StatsComponent.
            $params['stats']       = 'true';
            $params['stats.field'] = $query->field ?? '';
            return $params;
        }

        // Grouped sum/avg/min/max → JSON Facet API.
        $metricExpr           = $query->metric.'('.$query->field.')';
        $groupField           = $query->groupBy['field'];
        $params['json.facet'] = json_encode(
                value: [
                    $groupField => [
                        'type'  => 'terms',
                        'field' => $groupField,
                        'limit' => -1,
                        'facet' => ['metric' => $metricExpr],
                    ],
                ]
                );

        return $params;
    }//end build()

    /**
     * Translate a filter map to an array of Solr fq clause strings.
     *
     * @param array $filters Filter map: {field: value|{operator: operand}}.
     *
     * @return string[] Array of fq clause strings.
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-3
     */
    public function buildFilterQueries(array $filters): array
    {
        $clauses = [];

        foreach ($filters as $field => $value) {
            if (is_array($value) === false) {
                // Scalar equality.
                $clauses[] = $field.':'.$this->quoteValue(value: $value);
                continue;
            }

            if (isset($value['in']) === true) {
                if (empty($value['in']) === true) {
                    // Empty in-list → never-match sentinel.
                    $clauses[] = $field.':(NEVER_MATCH_SENTINEL_XYZ_)';
                } else {
                    $quoted    = array_map(
                        callback: fn($v) => $this->quoteValue(value: $v),
                        array: $value['in']
                    );
                    $clauses[] = $field.':('.implode(separator: ' OR ', array: $quoted).')';
                }

                continue;
            }

            if (isset($value['ne']) === true) {
                $clauses[] = '-'.$field.':'.$this->quoteValue(value: $value['ne']);
                continue;
            }

            // Range operators: gt, gte, lt, lte.
            $lower          = '*';
            $upper          = '*';
            $lowerExclusive = false;
            $upperExclusive = false;

            if (isset($value['gte']) === true) {
                $lower = $this->solrRangeValue(value: $value['gte']);
            } else if (isset($value['gt']) === true) {
                $lower          = $this->solrRangeValue(value: $value['gt']);
                $lowerExclusive = true;
            }

            if (isset($value['lte']) === true) {
                $upper = $this->solrRangeValue(value: $value['lte']);
            } else if (isset($value['lt']) === true) {
                $upper          = $this->solrRangeValue(value: $value['lt']);
                $upperExclusive = true;
            }

            $lBracket  = $lowerExclusive === true ? '{' : '[';
            $rBracket  = $upperExclusive === true ? '}' : ']';
            $clauses[] = $field.':'.$lBracket.$lower.' TO '.$upper.$rBracket;
        }//end foreach

        return $clauses;
    }//end buildFilterQueries()

    /**
     * Quote a scalar value for use in a Solr fq clause.
     *
     * Strings get double-quoted with Solr-special-char escaping.
     * Numerics and booleans pass through without quotes.
     *
     * @param mixed $value Value to quote.
     *
     * @return string Quoted value.
     */
    private function quoteValue(mixed $value): string
    {
        if (is_bool(value: $value) === true) {
            return $value === true ? 'true' : 'false';
        }

        if (is_numeric(value: $value) === true) {
            return (string) $value;
        }

        // Escape Solr special characters inside quoted string.
        $escaped = str_replace(
            search: ['"', '\\'],
            replace: ['\\"', '\\\\'],
            subject: (string) $value
        );
        return '"'.$escaped.'"';
    }//end quoteValue()

    /**
     * Format a value for use inside a Solr range query.
     *
     * @param mixed $value Value to format.
     *
     * @return string Formatted value.
     */
    private function solrRangeValue(mixed $value): string
    {
        if (is_bool(value: $value) === true) {
            return $value === true ? 'true' : 'false';
        }

        if (is_numeric(value: $value) === true) {
            return (string) $value;
        }

        return '"'.str_replace(search: '"', replace: '\\"', subject: (string) $value).'"';
    }//end solrRangeValue()
}//end class
