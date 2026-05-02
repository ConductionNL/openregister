<?php

/**
 * SolrAggregationQueryBuilder — translate AggregationQuery → Solr params.
 *
 * Turns an AggregationQuery value object into the Solr request-parameter
 * map that the Solr HTTP client posts to the `/select` endpoint:
 *
 *   - count + ungrouped     → `rows=0&q=*:*&fq=<filters>`
 *   - count + groupBy       → `rows=0&facet=true&facet.field=<col>`
 *   - sum/avg/min/max       → `stats=true&stats.field=<col>` (StatsComponent)
 *   - sum/avg/min/max + grp → `json.facet={<col>:{type:terms, field:<col>,
 *                              facet:{m:"<metric>(<field>)"}}}`
 *
 * Pure-PHP translation — no HTTP. The Solr backend wraps a thin HTTP
 * client around this builder. Unit-testable independently.
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
 * @spec openspec/changes/aggregations-backend-native/tasks.md "SolrSearchBackend::aggregate"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Builds Solr request parameters from an AggregationQuery.
 */
class SolrAggregationQueryBuilder
{
    /**
     * Translate an AggregationQuery into Solr request parameters.
     *
     * @param AggregationQuery $query The cross-backend request.
     *
     * @return array<string, mixed> The Solr request parameter map.
     */
    public function build(AggregationQuery $query): array
    {
        $params = [
            'q'    => '*:*',
            'rows' => 0,
            'wt'   => 'json',
        ];

        $fq = $this->translateFilters(filter: $query->filter);
        if ($fq !== []) {
            $params['fq'] = $fq;
        }

        if ($query->metric === AggregationQuery::METRIC_COUNT) {
            if ($query->isGrouped() === true) {
                $params['facet']          = 'true';
                $params['facet.field']    = $query->getGroupByField();
                $params['facet.mincount'] = 1;
            }

            if ($query->hasDateBucket() === true) {
                $bucket          = $query->dateBucket;
                $params['facet'] = 'true';
                $params['facet.range']       = (string) $bucket['field'];
                $params['facet.range.start'] = (string) $bucket['start'];
                $params['facet.range.end']   = (string) $bucket['end'];
                $params['facet.range.gap']   = '+1'.strtoupper((string) $bucket['gap']);
            }

            return $params;
        }

        // Non-count metrics: use Solr StatsComponent for ungrouped + JSON
        // Facet API for grouped — both standard since Solr 7.x.
        $field = (string) $query->field;
        if ($query->isGrouped() === true) {
            $groupField           = (string) $query->getGroupByField();
            $params['json.facet'] = (string) json_encode(
                    [
                        $groupField => [
                            'type'  => 'terms',
                            'field' => $groupField,
                            'facet' => ['m' => $query->metric.'('.$field.')'],
                        ],
                    ]
                    );
        } else {
            $params['stats']       = 'true';
            $params['stats.field'] = $field;
        }

        return $params;

    }//end build()

    /**
     * Translate the AggregationQuery filter map into a list of Solr `fq`
     * filter-query strings. Each entry is one independent filter (Solr
     * AND-composes them).
     *
     * Supported per-field shapes:
     *   - scalar              → `field:"value"`
     *   - {in: [a, b, c]}     → `field:(a OR b OR c)`
     *   - {gt|gte|lt|lte: x}  → `field:[x TO *]` etc.
     *   - {ne: x}             → `-field:"x"`
     *
     * @param array<string, mixed> $filter The filter map.
     *
     * @return string[] List of Solr `fq` strings.
     */
    public function translateFilters(array $filter): array
    {
        $out = [];
        foreach ($filter as $field => $value) {
            if (is_array($value) === false) {
                $out[] = $field.':'.$this->quote(value: $value);
                continue;
            }

            foreach ($value as $op => $opValue) {
                $clause = $this->translateOp(field: (string) $field, op: (string) $op, value: $opValue);
                if ($clause !== null) {
                    $out[] = $clause;
                }
            }
        }

        return $out;

    }//end translateFilters()

    /**
     * Translate a single (op, value) pair on a field to Solr fq syntax.
     *
     * @param string $field The field name.
     * @param string $op    The operator (in / gt / gte / lt / lte / ne).
     * @param mixed  $value The operand.
     *
     * @return ?string The fq clause, or null when the op is unrecognised.
     */
    private function translateOp(string $field, string $op, mixed $value): ?string
    {
        switch ($op) {
            case 'in':
                $list = is_array($value) === true ? $value : [];
                if (count($list) === 0) {
                    return $field.':("__or_no_match__")';
                }

                $quoted = array_map(fn($v) => $this->quote(value: $v), $list);
                return $field.':('.implode(' OR ', $quoted).')';
            case 'ne':
                return '-'.$field.':'.$this->quote(value: $value);
            case 'gt':
                return $field.':{'.$this->bound(value: $value).' TO *}';
            case 'gte':
                return $field.':['.$this->bound(value: $value).' TO *]';
            case 'lt':
                return $field.':{* TO '.$this->bound(value: $value).'}';
            case 'lte':
                return $field.':[* TO '.$this->bound(value: $value).']';
            default:
                return null;
        }//end switch

    }//end translateOp()

    /**
     * Quote a scalar for Solr fq syntax. Strings get double-quoted and
     * escaped; numerics + booleans pass through.
     *
     * @param mixed $value The value to quote.
     *
     * @return string
     */
    private function quote(mixed $value): string
    {
        if (is_bool($value) === true) {
            return $value === true ? 'true' : 'false';
        }

        if (is_int($value) === true || is_float($value) === true) {
            return (string) $value;
        }

        $s = (string) $value;
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';

    }//end quote()

    /**
     * Format a range bound value for Solr fq.
     *
     * @param mixed $value The bound.
     *
     * @return string
     */
    private function bound(mixed $value): string
    {
        if (is_int($value) === true || is_float($value) === true) {
            return (string) $value;
        }

        return $this->quote(value: $value);

    }//end bound()
}//end class
