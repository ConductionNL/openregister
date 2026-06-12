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

    // -----------------------------------------------------------------------
    // Cross-schema DSL (`from` key) tests
    // -----------------------------------------------------------------------

    public function testSelectAliasIsAcceptedForIntraSchema(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => ['total' => ['select' => 'count']],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testWhereAliasIsAcceptedForIntraSchema(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => [
                'openCount' => ['metric' => 'count', 'where' => ['status' => 'open']],
            ],
            'properties' => ['status' => ['type' => 'string']],
        ]);
        $this->assertSame([], $errors);
    }

    public function testValidCrossSchemaCountSpec(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => [
                'mandatoryEnrolledCount' => [
                    'from'   => 'scholiq-enrolment',
                    'where'  => ['mandatory' => true, 'regulationSlug' => '@self.slug'],
                    'select' => 'count',
                ],
            ],
            'properties' => ['slug' => ['type' => 'string']],
        ]);
        $this->assertSame([], $errors);
    }

    public function testCrossSchemaSpecWithoutMetricDefaultsToCount(): void
    {
        // `metric`/`select` is optional for cross-schema specs (defaults to count).
        $errors = $this->v->validate([
            'x-openregister-aggregations' => [
                'enrolCount' => ['from' => 'scholiq-enrolment'],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testCrossSchemaSpecWithEmptyFromIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => [
                'enrolCount' => ['from' => ''],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('aggregation-from-empty', $codes);
    }

    public function testCrossSchemaSpecWithBadMetricIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => [
                'enrolCount' => ['from' => 'other-schema', 'select' => 'percentile99'],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('aggregation-bad-metric', $codes);
    }

    public function testCrossSchemaWhereAsNonMapIsRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregations' => [
                'enrolCount' => ['from' => 'other-schema', 'where' => 'not-a-map'],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('aggregation-filter-malformed', $codes);
    }

    public function testCrossSchemaSkipsFieldExistenceChecks(): void
    {
        // For cross-schema specs the target schema's properties are unknown
        // at annotation-save time, so field names in `where` are not validated.
        $errors = $this->v->validate([
            'x-openregister-aggregations' => [
                'enrolCount' => [
                    'from'  => 'other-schema',
                    'where' => ['anyFieldName' => 'anyValue'],
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }
}
