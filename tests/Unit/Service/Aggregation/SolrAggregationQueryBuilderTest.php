<?php

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\SolrAggregationQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SolrAggregationQueryBuilder.
 *
 * @covers \OCA\OpenRegister\Service\Aggregation\SolrAggregationQueryBuilder
 */
class SolrAggregationQueryBuilderTest extends TestCase
{

    private SolrAggregationQueryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SolrAggregationQueryBuilder();
    }

    public function testCountQueryHasRows0(): void
    {
        $q      = AggregationQuery::create(metric: 'count');
        $params = $this->builder->build(query: $q);

        $this->assertSame(expected: 0, actual: $params['rows']);
        $this->assertSame(expected: '*:*', actual: $params['q']);
        $this->assertArrayNotHasKey(key: 'facet', array: $params);
    }

    public function testCountGroupByUsesFacetField(): void
    {
        $q      = AggregationQuery::create(metric: 'count', groupBy: ['field' => 'status']);
        $params = $this->builder->build(query: $q);

        $this->assertSame(expected: 'true', actual: $params['facet']);
        $this->assertSame(expected: 'status', actual: $params['facet.field']);
    }

    public function testUngroupedSumUsesStatsComponent(): void
    {
        $q      = AggregationQuery::create(metric: 'sum', field: 'amount');
        $params = $this->builder->build(query: $q);

        $this->assertSame(expected: 'true', actual: $params['stats']);
        $this->assertSame(expected: 'amount', actual: $params['stats.field']);
    }

    public function testGroupedSumUsesJsonFacet(): void
    {
        $q      = AggregationQuery::create(metric: 'sum', field: 'amount', groupBy: ['field' => 'type']);
        $params = $this->builder->build(query: $q);

        $this->assertArrayHasKey(key: 'json.facet', array: $params);
        $decoded = json_decode($params['json.facet'], true);
        $this->assertArrayHasKey(key: 'type', array: $decoded);
    }

    public function testFilterTranslationScalarEquality(): void
    {
        $clauses = $this->builder->buildFilterQueries(filters: ['status' => 'open']);

        $this->assertStringContainsString(needle: 'status:"open"', haystack: $clauses[0]);
    }

    public function testFilterTranslationInList(): void
    {
        $clauses = $this->builder->buildFilterQueries(filters: ['status' => ['in' => ['open', 'draft']]]);

        $this->assertStringContainsString(needle: 'status:("open" OR "draft")', haystack: $clauses[0]);
    }

    public function testFilterTranslationEmptyInListNeverMatch(): void
    {
        $clauses = $this->builder->buildFilterQueries(filters: ['status' => ['in' => []]]);

        $this->assertStringContainsString(needle: 'NEVER_MATCH', haystack: $clauses[0]);
    }

    public function testFilterTranslationRangeGte(): void
    {
        $clauses = $this->builder->buildFilterQueries(filters: ['amount' => ['gte' => 100]]);

        $this->assertStringContainsString(needle: 'amount:[100 TO *]', haystack: $clauses[0]);
    }

}//end class
