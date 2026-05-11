<?php

declare(strict_types=1);

namespace Unit\Service\Aggregation;

use OCA\OpenRegister\Service\Aggregation\AggregationAnnotationValidator;
use PHPUnit\Framework\TestCase;

class AggregationAnnotationValidatorTest extends TestCase
{
    private AggregationAnnotationValidator $v;

    protected function setUp(): void
    {
        $this->v = new AggregationAnnotationValidator();
    }

    public function testNoAnnotationIsValid(): void
    {
        $this->assertSame([], $this->v->validate(['properties' => []]));
    }

    public function testEmptyMapIsRejected(): void
    {
        $errors = $this->v->validate(['x-openregister-aggregations' => [], 'properties' => []]);
        $this->assertSame('aggregations-empty', $errors[0]['code']);
    }

    public function testCountMetricNeedsNoField(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => ['total' => ['metric' => 'count']],
            'properties' => ['x' => ['type' => 'string']],
        ]);
        $this->assertSame([], $errors);
    }

    public function testSumMetricRequiresField(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => ['totalAmount' => ['metric' => 'sum']],
            'properties' => ['amount' => ['type' => 'integer']],
        ]);
        $this->assertSame('aggregation-field-missing', $errors[0]['code']);
    }

    public function testFieldMustExistOnSchema(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => ['totalAmount' => ['metric' => 'sum', 'field' => 'unknown']],
            'properties' => ['amount' => ['type' => 'integer']],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('aggregation-field-not-in-schema', $codes);
    }

    public function testFilterFieldMustExist(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => ['x' => ['metric' => 'count', 'filter' => ['unknown' => 'a']]],
            'properties' => ['known' => ['type' => 'string']],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('aggregation-filter-field-unknown', $codes);
    }

    public function testGroupByFieldMustExist(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => ['x' => ['metric' => 'count', 'groupBy' => ['field' => 'unknown']]],
            'properties' => ['known' => ['type' => 'string']],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('aggregation-groupby-field-unknown', $codes);
    }

    public function testUnknownMetricIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => ['x' => ['metric' => 'percentile99']],
            'properties' => [],
        ]);
        $this->assertSame('aggregation-bad-metric', $errors[0]['code']);
    }

    public function testValidAnnotationProducesNoErrors(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => [
                'total'    => ['metric' => 'count'],
                'byStatus' => ['metric' => 'count', 'groupBy' => ['field' => 'status']],
                'avgDays'  => ['metric' => 'avg', 'field' => 'days', 'filter' => ['status' => 'open']],
            ],
            'properties' => [
                'status' => ['type' => 'string'],
                'days'   => ['type' => 'integer'],
            ],
        ]);
        $this->assertSame([], $errors);
    }
}
