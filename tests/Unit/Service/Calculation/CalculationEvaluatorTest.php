<?php

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class CalculationEvaluatorTest extends TestCase
{
    private CalculationEvaluator $eval;
    /** @var IUserSession&MockObject */
    private $userSession;

    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userSession->method('getUser')->willReturn(null);
        $resolver = new PlaceholderResolver($this->userSession);
        $this->eval = new CalculationEvaluator($resolver);
    }

    public function testBareScalar(): void
    {
        $this->assertSame(42, $this->eval->evaluate([], 42));
        $this->assertSame('hello', $this->eval->evaluate([], 'hello'));
    }

    public function testProp(): void
    {
        $this->assertSame('Anna', $this->eval->evaluate(['name' => 'Anna'], ['prop' => 'name']));
        $this->assertNull($this->eval->evaluate([], ['prop' => 'missing']));
    }

    public function testDottedProp(): void
    {
        $obj = ['@self' => ['created' => '2026-01-01']];
        $this->assertSame('2026-01-01', $this->eval->evaluate($obj, ['prop' => '@self.created']));
        $this->assertNull($this->eval->evaluate($obj, ['prop' => '@self.notthere']));
    }

    public function testConcat(): void
    {
        $obj = ['first' => 'Anna', 'last' => 'Smith'];
        $this->assertSame('Anna Smith', $this->eval->evaluate(
            $obj,
            ['concat' => [['prop' => 'first'], ' ', ['prop' => 'last']]]
        ));
    }

    public function testIfTrue(): void
    {
        $this->assertSame('yes', $this->eval->evaluate([], ['if' => [true, 'yes', 'no']]));
        $this->assertSame('no',  $this->eval->evaluate([], ['if' => [false, 'yes', 'no']]));
    }

    public function testArithmetic(): void
    {
        $this->assertSame(6,   $this->eval->evaluate([], ['+' => [1, 2, 3]]));
        $this->assertSame(2,   $this->eval->evaluate([], ['-' => [10, 5, 3]]));
        $this->assertSame(-7,  $this->eval->evaluate([], ['-' => [7]]));
        $this->assertSame(24,  $this->eval->evaluate([], ['*' => [2, 3, 4]]));
        $this->assertSame(2.5, $this->eval->evaluate([], ['/' => [5, 2]]));
        $this->assertSame(1.0, $this->eval->evaluate([], ['%' => [5, 2]]));
    }

    public function testComparison(): void
    {
        $this->assertTrue($this->eval->evaluate([], ['eq' => [3, 3]]));
        $this->assertFalse($this->eval->evaluate([], ['eq' => [3, 4]]));
        $this->assertTrue($this->eval->evaluate([], ['ne' => [3, 4]]));
        $this->assertTrue($this->eval->evaluate([], ['lt' => [2, 3]]));
        $this->assertTrue($this->eval->evaluate([], ['gte' => [3, 3]]));
    }

    public function testDateComparison(): void
    {
        $obj = ['date' => '2026-01-01T00:00:00+00:00'];
        $this->assertTrue($this->eval->evaluate($obj, ['lt' => [['prop' => 'date'], '2026-12-01']]));
        $this->assertFalse($this->eval->evaluate($obj, ['gt' => [['prop' => 'date'], '2026-12-01']]));
    }

    public function testLogical(): void
    {
        $this->assertTrue($this->eval->evaluate([], ['and' => [true, true]]));
        $this->assertFalse($this->eval->evaluate([], ['and' => [true, false]]));
        $this->assertTrue($this->eval->evaluate([], ['or' => [false, true]]));
        $this->assertTrue($this->eval->evaluate([], ['not' => false]));
    }

    public function testDiffDays(): void
    {
        $diff = $this->eval->evaluate([], ['diffDays' => ['2026-01-10', '2026-01-01']]);
        $this->assertSame(9, $diff);

        $negDiff = $this->eval->evaluate([], ['diffDays' => ['2026-01-01', '2026-01-10']]);
        $this->assertSame(-9, $negDiff);

        $this->assertNull($this->eval->evaluate([], ['diffDays' => [null, '2026-01-01']]));
    }

    public function testFormatDate(): void
    {
        $this->assertSame('2026-01-01', $this->eval->evaluate([], ['formatDate' => ['2026-01-01T10:00:00Z', 'Y-m-d']]));
    }

    public function testUnknownOperatorThrows(): void
    {
        $this->expectException(EvaluationException::class);
        $this->eval->evaluate([], ['frobnicate' => [1]]);
    }

    public function testNonNumericArithmeticThrows(): void
    {
        $this->expectException(EvaluationException::class);
        $this->eval->evaluate([], ['+' => ['a', 'b']]);
    }

    public function testDivisionByZeroThrows(): void
    {
        $this->expectException(EvaluationException::class);
        $this->eval->evaluate([], ['/' => [1, 0]]);
    }
}
