<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\OperatorEvaluator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class OperatorEvaluatorTest extends TestCase
{
    private OperatorEvaluator $evaluator;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->evaluator = new OperatorEvaluator($this->logger);
    }

    // ── $eq ──

    public function testEqMatchesStrictly(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(5, ['$eq' => 5]));
    }

    public function testEqRejectsLooseMatch(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('5', ['$eq' => 5]));
    }

    public function testEqRejectsDifferentValue(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(4, ['$eq' => 5]));
    }

    // ── $ne ──

    public function testNeRejectsSameValue(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(5, ['$ne' => 5]));
    }

    public function testNeMatchesDifferentValue(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(4, ['$ne' => 5]));
    }

    // ── $in ──

    public function testInMatchesValueInArray(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator('a', ['$in' => ['a', 'b', 'c']]));
    }

    public function testInRejectsValueNotInArray(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('d', ['$in' => ['a', 'b', 'c']]));
    }

    public function testInReturnsFalseForNonArrayOperand(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('a', ['$in' => 'not-an-array']));
    }

    public function testInUsesStrictComparison(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('1', ['$in' => [1, 2, 3]]));
    }

    // ── $nin ──

    public function testNinMatchesValueNotInArray(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator('d', ['$nin' => ['a', 'b', 'c']]));
    }

    public function testNinRejectsValueInArray(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('a', ['$nin' => ['a', 'b', 'c']]));
    }

    public function testNinReturnsTrueForNonArrayOperand(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator('a', ['$nin' => 'not-an-array']));
    }

    // ── $exists ──

    public function testExistsTrueMatchesNonNull(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator('something', ['$exists' => true]));
    }

    public function testExistsTrueRejectsNull(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(null, ['$exists' => true]));
    }

    public function testExistsFalseMatchesNull(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(null, ['$exists' => false]));
    }

    public function testExistsFalseRejectsNonNull(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator('something', ['$exists' => false]));
    }

    // ── $gt / $gte / $lt / $lte ──

    public function testGtMatches(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(10, ['$gt' => 5]));
    }

    public function testGtRejectsEqual(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(5, ['$gt' => 5]));
    }

    public function testGteMatchesEqual(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(5, ['$gte' => 5]));
    }

    public function testGteMatchesGreater(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(6, ['$gte' => 5]));
    }

    public function testLtMatches(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(3, ['$lt' => 5]));
    }

    public function testLtRejectsEqual(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(5, ['$lt' => 5]));
    }

    public function testLteMatchesEqual(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(5, ['$lte' => 5]));
    }

    public function testLteMatchesLess(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(4, ['$lte' => 5]));
    }

    // ── Combined operators ──

    public function testMultipleOperatorsMustAllPass(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator(7, ['$gt' => 5, '$lt' => 10]));
    }

    public function testMultipleOperatorsFailIfOneDoesNot(): void
    {
        $this->assertFalse($this->evaluator->valueMatchesOperator(12, ['$gt' => 5, '$lt' => 10]));
    }

    // ── Unknown operator ──

    public function testUnknownOperatorReturnsTrueAndLogsWarning(): void
    {
        $this->logger->expects($this->once())->method('warning');
        $this->assertTrue($this->evaluator->valueMatchesOperator(5, ['$unknown' => 1]));
    }

    // ── Empty operators ──

    public function testEmptyOperatorsReturnsTrue(): void
    {
        $this->assertTrue($this->evaluator->valueMatchesOperator('anything', []));
    }
}
