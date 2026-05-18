<?php

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AggregationQuery value object.
 *
 * @covers \OCA\OpenRegister\Service\Aggregation\AggregationQuery
 */
class AggregationQueryTest extends TestCase
{

    public function testCreateCountWithNoField(): void
    {
        $q = AggregationQuery::create(metric: 'count');

        $this->assertSame(expected: 'count', actual: $q->metric);
        $this->assertNull(actual: $q->field);
        $this->assertSame(expected: [], actual: $q->filter);
        $this->assertNull(actual: $q->groupBy);
    }

    public function testCreateSumRequiresField(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/requires a field/');

        AggregationQuery::create(metric: 'sum');
    }

    public function testCreateSumWithField(): void
    {
        $q = AggregationQuery::create(metric: 'sum', field: 'amount');

        $this->assertSame(expected: 'sum', actual: $q->metric);
        $this->assertSame(expected: 'amount', actual: $q->field);
    }

    public function testInvalidMetricThrows(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/Invalid metric/');

        AggregationQuery::create(metric: 'median');
    }

    public function testGroupByRequiresField(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/groupBy requires/');

        AggregationQuery::create(metric: 'count', groupBy: ['bucket' => 'day']);
    }

    public function testGroupByWithField(): void
    {
        $q = AggregationQuery::create(
            metric: 'count',
            groupBy: ['field' => 'status']
        );

        $this->assertSame(expected: 'status', actual: $q->groupBy['field']);
    }

}//end class
