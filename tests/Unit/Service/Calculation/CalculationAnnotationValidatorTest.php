<?php

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use PHPUnit\Framework\TestCase;

class CalculationAnnotationValidatorTest extends TestCase
{
    private CalculationAnnotationValidator $v;

    protected function setUp(): void
    {
        $this->v = new CalculationAnnotationValidator();
    }

    public function testNoAnnotationIsValid(): void
    {
        $this->assertSame([], $this->v->validate(['properties' => []]));
    }

    public function testEmptyMapIsRejected(): void
    {
        $errors = $this->v->validate(['x-openregister-calculations' => [], 'properties' => []]);
        $this->assertSame('calculations-empty', $errors[0]['code']);
    }

    public function testRefToUnknownProp(): void
    {
        $errors = $this->v->validate([
            'x-openregister-calculations' => ['x' => ['type' => 'integer', 'expression' => ['prop' => 'unknown']]],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('calculation-prop-unknown', $codes);
    }

    public function testSelfFieldAllowed(): void
    {
        $errors = $this->v->validate([
            'x-openregister-calculations' => [
                'daysOpen' => [
                    'type' => 'integer',
                    'expression' => ['diffDays' => ['$now', ['prop' => '@self.created']]],
                ],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testSelfUnknownFieldRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-calculations' => [
                'x' => ['type' => 'integer', 'expression' => ['prop' => '@self.bogus']],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('calculation-self-unknown', $codes);
    }

    public function testCycleDetected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-calculations' => [
                'a' => ['type' => 'integer', 'expression' => ['prop' => 'b']],
                'b' => ['type' => 'integer', 'expression' => ['prop' => 'a']],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('calculation-cycle', $codes);
    }

    public function testUnknownOperatorRejected(): void
    {
        $errors = $this->v->validate([
            'x-openregister-calculations' => [
                'x' => ['type' => 'integer', 'expression' => ['frobnicate' => [1]]],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('calculation-unknown-op', $codes);
    }

    public function testValidNoErrors(): void
    {
        $errors = $this->v->validate([
            'x-openregister-calculations' => [
                'daysLate' => ['type' => 'integer', 'expression' => ['diffDays' => [['prop' => 'completedAt'], ['prop' => 'dueDate']]]],
                'isOverdue' => ['type' => 'boolean', 'expression' => ['lt' => [['prop' => 'dueDate'], '$now']]],
            ],
            'properties' => [
                'completedAt' => ['type' => 'string'],
                'dueDate'     => ['type' => 'string'],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    public function testAggregateTokenAcceptedWhenDeclared(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregate-refs' => [
                'billable' => ['schema' => 'TimeEntry', 'metric' => 'sum', 'field' => 'hours'],
            ],
            'x-openregister-calculations' => [
                'util' => ['type' => 'number', 'expression' => ['prop' => '@aggregate.billable']],
            ],
            'properties' => [],
        ]);
        $this->assertSame([], $errors);
    }

    public function testAggregateTokenRejectedWhenUndeclared(): void
    {
        $errors = $this->v->validate([
            'x-openregister-calculations' => [
                'util' => ['type' => 'number', 'expression' => ['prop' => '@aggregate.unknown']],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('calculation-aggregate-unknown', $codes);
    }

    public function testAggregateRefShapeErrors(): void
    {
        $errors = $this->v->validate([
            'x-openregister-aggregate-refs' => [
                'noSchema'  => ['metric' => 'count'],
                'badMetric' => ['schema' => 'X', 'metric' => 'median'],
                'noField'   => ['schema' => 'X', 'metric' => 'sum'],
            ],
            'x-openregister-calculations' => [
                'c' => ['type' => 'integer', 'expression' => 1],
            ],
            'properties' => [],
        ]);
        $codes = array_column($errors, 'code');
        $this->assertContains('aggregate-ref-no-schema', $codes);
        $this->assertContains('aggregate-ref-bad-metric', $codes);
        $this->assertContains('aggregate-ref-no-field', $codes);
    }

    public function testSha256OpAccepted(): void
    {
        $errors = $this->v->validate([
            'x-openregister-calculations' => [
                'hash' => ['type' => 'string', 'expression' => ['sha256' => [['prop' => 'name']]]],
            ],
            'properties' => ['name' => ['type' => 'string']],
        ]);
        $this->assertSame([], $errors);
    }
}
