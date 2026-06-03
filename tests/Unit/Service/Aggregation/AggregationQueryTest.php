<?php

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AggregationQuery::toArray() stability and field coverage.
 *
 * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.1
 */
class AggregationQueryTest extends TestCase
{

    /**
     * toArray() includes all fields.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.1
     */
    public function testToArrayContainsAllFields(): void
    {
        $query = new AggregationQuery(
            metric: 'count',
            field: '_created',
            filter: ['status' => 'open'],
            groupBy: '_created',
            dateBucket: 'day'
        );

        $arr = $query->toArray();

        $this->assertSame('count', $arr['metric']);
        $this->assertSame('_created', $arr['field']);
        $this->assertSame(['status' => 'open'], $arr['filter']);
        $this->assertSame('_created', $arr['groupBy']);
        $this->assertSame('day', $arr['dateBucket']);
    }

    /**
     * toArray() returns null-safe optional fields when not set.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.1
     */
    public function testToArrayNullableFieldsAreNull(): void
    {
        $query = new AggregationQuery(
            metric: 'count',
            field: null,
            filter: [],
            groupBy: null,
            dateBucket: null
        );

        $arr = $query->toArray();

        $this->assertNull($arr['field']);
        $this->assertNull($arr['groupBy']);
        $this->assertNull($arr['dateBucket']);
    }

    /**
     * Two queries with filter keys in different order produce the same toArray hash.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.1
     */
    public function testToArrayStableUnderFilterKeyReordering(): void
    {
        $queryA = new AggregationQuery(
            metric: 'count',
            field: null,
            filter: ['status' => 'open', 'priority' => 'high'],
            groupBy: null,
            dateBucket: null
        );

        $queryB = new AggregationQuery(
            metric: 'count',
            field: null,
            filter: ['priority' => 'high', 'status' => 'open'],
            groupBy: null,
            dateBucket: null
        );

        $hashA = sha1(string: json_encode(value: $queryA->toArray()));
        $hashB = sha1(string: json_encode(value: $queryB->toArray()));

        $this->assertSame($hashA, $hashB, 'Filter key reordering must not change the cache hash');
    }

    /**
     * Nested filter arrays are also ksorted so nested key reordering does not affect the hash.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.1
     */
    public function testToArrayStableUnderNestedFilterKeyReordering(): void
    {
        $queryA = new AggregationQuery(
            metric: 'count',
            field: null,
            filter: ['meta' => ['z' => 1, 'a' => 2]],
            groupBy: null,
            dateBucket: null
        );

        $queryB = new AggregationQuery(
            metric: 'count',
            field: null,
            filter: ['meta' => ['a' => 2, 'z' => 1]],
            groupBy: null,
            dateBucket: null
        );

        $hashA = sha1(string: json_encode(value: $queryA->toArray()));
        $hashB = sha1(string: json_encode(value: $queryB->toArray()));

        $this->assertSame($hashA, $hashB, 'Nested filter key reordering must not change the cache hash');
    }

    /**
     * toArray() includes dateBucket when set.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.1
     */
    public function testToArrayIncludesDateBucket(): void
    {
        foreach (['minute', 'hour', 'day', 'month', 'year', 'week', 'quarter'] as $gap) {
            $query = new AggregationQuery(
                metric: 'count',
                field: null,
                filter: [],
                groupBy: null,
                dateBucket: $gap
            );

            $this->assertSame($gap, $query->toArray()['dateBucket']);
        }
    }

    /**
     * Structurally different queries produce different hashes.
     *
     * @spec openspec/changes/add-aggregation-enhancements/tasks.md#task-3.1
     */
    public function testDifferentQueriesHaveDifferentHashes(): void
    {
        $queryA = new AggregationQuery(
            metric: 'count',
            field: null,
            filter: [],
            groupBy: null,
            dateBucket: 'day'
        );

        $queryB = new AggregationQuery(
            metric: 'count',
            field: null,
            filter: [],
            groupBy: null,
            dateBucket: 'hour'
        );

        $hashA = sha1(string: json_encode(value: $queryA->toArray()));
        $hashB = sha1(string: json_encode(value: $queryB->toArray()));

        $this->assertNotSame($hashA, $hashB, 'Different queries must produce different hashes');
    }
}//end class
