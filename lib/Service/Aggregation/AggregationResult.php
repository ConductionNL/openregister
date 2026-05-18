<?php

/**
 * AggregationResult
 *
 * Immutable value object for an aggregation response. Carries the scalar
 * value (or per-group breakdown), the backend that produced it, and a cached
 * flag so callers can inspect cache provenance without parsing JSON.
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
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

/**
 * Aggregation result value object.
 *
 * @spec openspec/changes/aggregations-backend-native/tasks.md#task-5
 */
class AggregationResult
{
    /**
     * Constructor.
     *
     * @param int|float  $value   Scalar result (count/sum/avg/min/max).
     * @param array|null $groups  Per-group results [{group: string, value: int|float}].
     * @param string     $backend Backend used: postgres|solr|elasticsearch|php-fallback|stub.
     * @param bool       $cached  Whether this result came from the cache.
     */
    public function __construct(
        public readonly int|float $value,
        public readonly ?array $groups,
        public readonly string $backend,
        public readonly bool $cached=false
    ) {
    }//end __construct()

    /**
     * Return this result as a plain array for JSON serialisation.
     *
     * @return array
     *
     * @spec openspec/changes/aggregations-backend-native/tasks.md#task-5
     */
    public function toArray(): array
    {
        $result = [
            'value'   => $this->value,
            'backend' => $this->backend,
        ];

        if ($this->groups !== null) {
            $result['groups'] = $this->groups;
        }

        if ($this->cached === true) {
            $result['cached'] = true;
        }

        return $result;
    }//end toArray()

    /**
     * Return a copy of this result marked as cached.
     *
     * @return self
     */
    public function asCached(): self
    {
        return new self(
            value: $this->value,
            groups: $this->groups,
            backend: $this->backend,
            cached: true
        );
    }//end asCached()
}//end class
