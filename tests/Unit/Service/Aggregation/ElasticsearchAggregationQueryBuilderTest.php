<?php

/**
 * Unit tests for ElasticsearchAggregationQueryBuilder.
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

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\ElasticsearchAggregationQueryBuilder;
use PHPUnit\Framework\TestCase;

class ElasticsearchAggregationQueryBuilderTest extends TestCase
{

    private ElasticsearchAggregationQueryBuilder $builder;


    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ElasticsearchAggregationQueryBuilder();

    }//end setUp()


    public function testCountUngroupedReturnsSizeZeroWithTrackTotalHits(): void
    {
        $body = $this->builder->build(
            query: AggregationQuery::create(metric: 'count')
        );
        $this->assertSame(0, $body['size']);
        $this->assertTrue($body['track_total_hits']);
        $this->assertArrayNotHasKey('aggs', $body);

    }//end testCountUngroupedReturnsSizeZeroWithTrackTotalHits()


    public function testCountGroupedAddsTermsBucket(): void
    {
        $body = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'count',
                groupBy: ['field' => 'status']
            )
        );
        $this->assertSame('status', $body['aggs']['status']['terms']['field']);
        $this->assertSame(1000, $body['aggs']['status']['terms']['size']);

    }//end testCountGroupedAddsTermsBucket()


    public function testSumUngroupedUsesMetricAggregation(): void
    {
        $body = $this->builder->build(
            query: AggregationQuery::create(metric: 'sum', field: 'amount')
        );
        $this->assertSame(['field' => 'amount'], $body['aggs']['metric_sum']['sum']);

    }//end testSumUngroupedUsesMetricAggregation()


    public function testAvgGroupedNestsMetricInsideTermsBucket(): void
    {
        $body = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'avg',
                field: 'amount',
                groupBy: ['field' => 'category']
            )
        );
        $this->assertSame('category', $body['aggs']['category']['terms']['field']);
        $this->assertSame(
            ['field' => 'amount'],
            $body['aggs']['category']['aggs']['metric_avg']['avg']
        );

    }//end testAvgGroupedNestsMetricInsideTermsBucket()


    public function testFiltersScalarEqualityBecomesTerm(): void
    {
        $body = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['status' => 'open']
            )
        );
        $this->assertSame(
            [['term' => ['status' => 'open']]],
            $body['query']['bool']['must']
        );

    }//end testFiltersScalarEqualityBecomesTerm()


    public function testFiltersInOperatorBecomesTerms(): void
    {
        $body = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['priority' => ['in' => ['high', 'medium']]]
            )
        );
        $this->assertSame(
            [['terms' => ['priority' => ['high', 'medium']]]],
            $body['query']['bool']['must']
        );

    }//end testFiltersInOperatorBecomesTerms()


    public function testFiltersGteAndLtBecomeRange(): void
    {
        $body = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['amount' => ['gte' => 100, 'lt' => 1000]]
            )
        );
        $must = $body['query']['bool']['must'];
        $this->assertContains(['range' => ['amount' => ['gte' => 100]]], $must);
        $this->assertContains(['range' => ['amount' => ['lt' => 1000]]], $must);

    }//end testFiltersGteAndLtBecomeRange()


    public function testFiltersNeBecomesMustNot(): void
    {
        $body = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['status' => ['ne' => 'closed']]
            )
        );
        $this->assertSame(
            [['term' => ['status' => 'closed']]],
            $body['query']['bool']['must_not']
        );

    }//end testFiltersNeBecomesMustNot()


    public function testDateBucketEmitsDateHistogramAgg(): void
    {
        $body = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'count',
                dateBucket: [
                    'field' => 'created',
                    'start' => '2026-01-01T00:00:00Z',
                    'end'   => '2026-12-31T23:59:59Z',
                    'gap'   => 'month',
                ]
            )
        );
        $this->assertSame('created', $body['aggs']['created']['date_histogram']['field']);
        $this->assertSame('month', $body['aggs']['created']['date_histogram']['calendar_interval']);
        $this->assertSame(
            ['min' => '2026-01-01T00:00:00Z', 'max' => '2026-12-31T23:59:59Z'],
            $body['aggs']['created']['date_histogram']['extended_bounds']
        );

    }//end testDateBucketEmitsDateHistogramAgg()


}//end class
