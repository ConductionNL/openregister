<?php

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\ElasticsearchAggregationQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ElasticsearchAggregationQueryBuilder.
 *
 * @covers \OCA\OpenRegister\Service\Aggregation\ElasticsearchAggregationQueryBuilder
 */
class ElasticsearchAggregationQueryBuilderTest extends TestCase
{

    private ElasticsearchAggregationQueryBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ElasticsearchAggregationQueryBuilder();
    }

    public function testCountBuildHasSize0(): void
    {
        $q    = AggregationQuery::create(metric: 'count');
        $body = $this->builder->build(query: $q);

        $this->assertSame(expected: 0, actual: $body['size']);
        $this->assertTrue(condition: $body['track_total_hits']);
    }

    public function testCountWithGroupByUsesBucketAgg(): void
    {
        $q    = AggregationQuery::create(metric: 'count', groupBy: ['field' => 'status']);
        $body = $this->builder->build(query: $q);

        $this->assertArrayHasKey(key: 'status', array: $body['aggs']);
        $this->assertArrayHasKey(key: 'terms', array: $body['aggs']['status']);
    }

    public function testSumBuildHasMetricAgg(): void
    {
        $q    = AggregationQuery::create(metric: 'sum', field: 'amount');
        $body = $this->builder->build(query: $q);

        $this->assertArrayHasKey(key: 'metric_sum', array: $body['aggs']);
        $this->assertSame(expected: 'amount', actual: $body['aggs']['metric_sum']['sum']['field']);
    }

    public function testGroupedSumHasNestedAgg(): void
    {
        $q    = AggregationQuery::create(metric: 'sum', field: 'amount', groupBy: ['field' => 'type']);
        $body = $this->builder->build(query: $q);

        $this->assertArrayHasKey(key: 'type', array: $body['aggs']);
        $this->assertArrayHasKey(key: 'metric_sum', array: $body['aggs']['type']['aggs']);
    }

    public function testFilterScalarProducesMustTerm(): void
    {
        $bool = $this->builder->buildBoolQuery(filters: ['status' => 'open']);

        $this->assertSame(expected: 'open', actual: $bool['must'][0]['term']['status']);
    }

    public function testFilterInProducesTerms(): void
    {
        $bool = $this->builder->buildBoolQuery(filters: ['status' => ['in' => ['open', 'draft']]]);

        $this->assertSame(expected: ['open', 'draft'], actual: $bool['must'][0]['terms']['status']);
    }

    public function testFilterEmptyInNeverMatches(): void
    {
        $bool = $this->builder->buildBoolQuery(filters: ['status' => ['in' => []]]);

        $this->assertArrayHasKey(key: 'must', array: $bool);
        $term = $bool['must'][0]['term']['status'] ?? null;
        $this->assertSame(expected: '__EMPTY_IN_NEVER_MATCH__', actual: $term);
    }

    public function testFilterNeProducesMustNot(): void
    {
        $bool = $this->builder->buildBoolQuery(filters: ['status' => ['ne' => 'deleted']]);

        $this->assertSame(expected: 'deleted', actual: $bool['must_not'][0]['term']['status']);
    }

}//end class
