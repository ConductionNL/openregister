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


    public function testMultiFieldGroupByFieldsShape(): void
    {
        $q = AggregationQuery::create(
            metric: 'sum',
            field: 'amount',
            filter: [],
            groupBy: ['fields' => ['vendorId', 'dueDateBucket']]
        );
        $this->assertTrue($q->isGrouped());
        $this->assertTrue($q->isMultiFieldGroupBy());
        $this->assertSame(['vendorId', 'dueDateBucket'], $q->getGroupByFields());
        // Backward-compatible accessor returns the FIRST field only.
        $this->assertSame('vendorId', $q->getGroupByField());

    }//end testMultiFieldGroupByFieldsShape()


    public function testMultiFieldGroupByPlainListShape(): void
    {
        $q = AggregationQuery::create(
            metric: 'count',
            groupBy: ['vendorId', 'dueDateBucket']
        );
        $this->assertTrue($q->isMultiFieldGroupBy());
        $this->assertSame(['vendorId', 'dueDateBucket'], $q->getGroupByFields());

    }//end testMultiFieldGroupByPlainListShape()


    public function testSingleFieldGroupByIsNotMultiField(): void
    {
        $q = AggregationQuery::create(
            metric: 'count',
            groupBy: ['field' => 'status']
        );
        $this->assertFalse($q->isMultiFieldGroupBy());
        $this->assertSame(['status'], $q->getGroupByFields());

    }//end testSingleFieldGroupByIsNotMultiField()


    public function testMultiFieldGroupByRejectsEmptyMember(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('groupBy MUST include a non-empty `field`');
        AggregationQuery::create(
            metric: 'count',
            groupBy: ['vendorId', '']
        );

    }//end testMultiFieldGroupByRejectsEmptyMember()


    public function testMultiFieldGroupByRejectsDuplicateFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('groupBy fields MUST be distinct');
        AggregationQuery::create(
            metric: 'count',
            groupBy: ['vendorId', 'vendorId']
        );

    }//end testMultiFieldGroupByRejectsDuplicateFields()


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


    public function testDateBucketIsExposedThroughHasDateBucket(): void
    {
        $q = AggregationQuery::create(
            metric: 'count',
            dateBucket: [
                'field' => 'created',
                'start' => '2026-01-01T00:00:00Z',
                'end'   => '2026-12-31T23:59:59Z',
                'gap'   => 'month',
            ]
        );
        $this->assertTrue($q->hasDateBucket());
        $this->assertSame('created', $q->dateBucket['field']);

    }//end testDateBucketIsExposedThroughHasDateBucket()


    public function testDateBucketRequiresAllFourFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dateBucket MUST include non-empty');
        AggregationQuery::create(
            metric: 'count',
            dateBucket: ['field' => 'created', 'start' => '2026-01-01']
        );

    }//end testDateBucketRequiresAllFourFields()


    public function testDateBucketGapMustBeKnownVocabulary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dateBucket gap MUST be one of');
        AggregationQuery::create(
            metric: 'count',
            dateBucket: [
                'field' => 'created',
                'start' => '2026-01-01',
                'end'   => '2026-12-31',
                'gap'   => 'fortnight',
            ]
        );

    }//end testDateBucketGapMustBeKnownVocabulary()


    public function testGroupByAndDateBucketAreMutuallyExclusive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MUST NOT be combined');
        AggregationQuery::create(
            metric: 'count',
            groupBy: ['field' => 'status'],
            dateBucket: [
                'field' => 'created',
                'start' => '2026-01-01',
                'end'   => '2026-12-31',
                'gap'   => 'month',
            ]
        );

    }//end testGroupByAndDateBucketAreMutuallyExclusive()


    public function testToArrayIncludesAllFields(): void
    {
        $q = AggregationQuery::create(
            metric: 'sum',
            field: 'amount',
            filter: ['status' => 'open'],
            dateBucket: [
                'field' => 'created',
                'start' => '2026-01-01T00:00:00Z',
                'end'   => '2026-02-01T00:00:00Z',
                'gap'   => 'day',
            ]
        );
        $arr = $q->toArray();
        $this->assertSame('sum', $arr['metric']);
        $this->assertSame('amount', $arr['field']);
        $this->assertSame(['status' => 'open'], $arr['filter']);
        $this->assertNull($arr['groupBy']);
        $this->assertSame('created', $arr['dateBucket']['field']);
        $this->assertSame('day', $arr['dateBucket']['gap']);

    }//end testToArrayIncludesAllFields()


    public function testToArrayIsStableUnderFilterKeyReordering(): void
    {
        $first = AggregationQuery::create(
            metric: 'count',
            filter: ['status' => 'open', 'priority' => 'high']
        );
        $second = AggregationQuery::create(
            metric: 'count',
            filter: ['priority' => 'high', 'status' => 'open']
        );
        $this->assertSame(
            sha1((string) json_encode($first->toArray())),
            sha1((string) json_encode($second->toArray())),
            'toArray() output MUST be stable under filter-key reordering'
        );

    }//end testToArrayIsStableUnderFilterKeyReordering()


    public function testToArrayCanonicalisesOperatorSubArrays(): void
    {
        $first = AggregationQuery::create(
            metric: 'count',
            filter: ['amount' => ['gt' => 0, 'lte' => 100]]
        );
        $second = AggregationQuery::create(
            metric: 'count',
            filter: ['amount' => ['lte' => 100, 'gt' => 0]]
        );
        $this->assertSame(
            sha1((string) json_encode($first->toArray())),
            sha1((string) json_encode($second->toArray())),
            'toArray() output MUST be stable under operator-key reordering inside filter sub-arrays'
        );

    }//end testToArrayCanonicalisesOperatorSubArrays()


    public function testToArrayReturnsNullForMissingOptionalFields(): void
    {
        $q   = AggregationQuery::create(metric: 'count');
        $arr = $q->toArray();
        $this->assertNull($arr['field']);
        $this->assertNull($arr['groupBy']);
        $this->assertNull($arr['dateBucket']);
        $this->assertSame([], $arr['filter']);

    }//end testToArrayReturnsNullForMissingOptionalFields()


}//end class
