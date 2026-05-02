<?php

/**
 * Unit tests for AggregationQuery value object.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use PHPUnit\Framework\TestCase;

class AggregationQueryTest extends TestCase
{


    public function testCountQueryDoesNotRequireField(): void
    {
        $q = AggregationQuery::create(metric: 'count');
        $this->assertSame('count', $q->metric);
        $this->assertNull($q->field);
        $this->assertFalse($q->isGrouped());

    }//end testCountQueryDoesNotRequireField()


    public function testNonCountMetricsRequireField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MUST specify a field');
        AggregationQuery::create(metric: 'sum', field: null);

    }//end testNonCountMetricsRequireField()


    public function testRejectsUnknownMetric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('aggregation metric MUST be one of');
        AggregationQuery::create(metric: 'median', field: 'amount');

    }//end testRejectsUnknownMetric()


    public function testGroupedQueryExposesGroupByField(): void
    {
        $q = AggregationQuery::create(
            metric: 'count',
            field: null,
            filter: [],
            groupBy: ['field' => 'status']
        );
        $this->assertTrue($q->isGrouped());
        $this->assertSame('status', $q->getGroupByField());

    }//end testGroupedQueryExposesGroupByField()


    public function testGroupByMustHaveField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('groupBy MUST include a non-empty `field`');
        AggregationQuery::create(
            metric: 'count',
            groupBy: ['field' => '']
        );

    }//end testGroupByMustHaveField()


    public function testFilterIsCarriedThrough(): void
    {
        $q = AggregationQuery::create(
            metric: 'sum',
            field: 'amount',
            filter: ['status' => 'open', 'priority' => ['in' => ['high', 'medium']]]
        );
        $this->assertSame(
            ['status' => 'open', 'priority' => ['in' => ['high', 'medium']]],
            $q->filter
        );

    }//end testFilterIsCarriedThrough()


}//end class
