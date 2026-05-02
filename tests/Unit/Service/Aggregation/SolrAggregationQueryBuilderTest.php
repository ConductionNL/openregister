<?php

/**
 * Unit tests for SolrAggregationQueryBuilder.
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
use OCA\OpenRegister\Service\Aggregation\SolrAggregationQueryBuilder;
use PHPUnit\Framework\TestCase;

class SolrAggregationQueryBuilderTest extends TestCase
{

    private SolrAggregationQueryBuilder $builder;


    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SolrAggregationQueryBuilder();

    }//end setUp()


    public function testCountUngroupedReturnsRowsZero(): void
    {
        $params = $this->builder->build(
            query: AggregationQuery::create(metric: 'count')
        );
        $this->assertSame('*:*', $params['q']);
        $this->assertSame(0, $params['rows']);
        $this->assertArrayNotHasKey('facet', $params);
        $this->assertArrayNotHasKey('stats', $params);

    }//end testCountUngroupedReturnsRowsZero()


    public function testCountGroupedAddsFacetField(): void
    {
        $params = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'count',
                groupBy: ['field' => 'status']
            )
        );
        $this->assertSame('true', $params['facet']);
        $this->assertSame('status', $params['facet.field']);
        $this->assertSame(1, $params['facet.mincount']);

    }//end testCountGroupedAddsFacetField()


    public function testSumUngroupedUsesStatsComponent(): void
    {
        $params = $this->builder->build(
            query: AggregationQuery::create(metric: 'sum', field: 'amount')
        );
        $this->assertSame('true', $params['stats']);
        $this->assertSame('amount', $params['stats.field']);

    }//end testSumUngroupedUsesStatsComponent()


    public function testAvgGroupedUsesJsonFacetApi(): void
    {
        $params = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'avg',
                field: 'amount',
                groupBy: ['field' => 'category']
            )
        );
        $this->assertArrayHasKey('json.facet', $params);
        $facet = json_decode($params['json.facet'], true);
        $this->assertSame('terms', $facet['category']['type']);
        $this->assertSame('category', $facet['category']['field']);
        $this->assertSame('avg(amount)', $facet['category']['facet']['m']);

    }//end testAvgGroupedUsesJsonFacetApi()


    public function testFiltersScalarEqualityToFq(): void
    {
        $params = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['status' => 'open']
            )
        );
        $this->assertSame(['status:"open"'], $params['fq']);

    }//end testFiltersScalarEqualityToFq()


    public function testFiltersInOperatorToOrClause(): void
    {
        $params = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['priority' => ['in' => ['high', 'medium', 'low']]]
            )
        );
        $this->assertSame(['priority:("high" OR "medium" OR "low")'], $params['fq']);

    }//end testFiltersInOperatorToOrClause()


    public function testFiltersGteAndLtToRangeClauses(): void
    {
        $params = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['amount' => ['gte' => 100, 'lt' => 1000]]
            )
        );
        $this->assertContains('amount:[100 TO *]', $params['fq']);
        $this->assertContains('amount:{* TO 1000}', $params['fq']);

    }//end testFiltersGteAndLtToRangeClauses()


    public function testFiltersNeUsesNegation(): void
    {
        $params = $this->builder->build(
            query: AggregationQuery::create(
                metric: 'count',
                filter: ['status' => ['ne' => 'closed']]
            )
        );
        $this->assertSame(['-status:"closed"'], $params['fq']);

    }//end testFiltersNeUsesNegation()


    public function testDateBucketEmitsFacetRangeParams(): void
    {
        $params = $this->builder->build(
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
        $this->assertSame('true', $params['facet']);
        $this->assertSame('created', $params['facet.range']);
        $this->assertSame('2026-01-01T00:00:00Z', $params['facet.range.start']);
        $this->assertSame('2026-12-31T23:59:59Z', $params['facet.range.end']);
        $this->assertSame('+1MONTH', $params['facet.range.gap']);

    }//end testDateBucketEmitsFacetRangeParams()


}//end class
