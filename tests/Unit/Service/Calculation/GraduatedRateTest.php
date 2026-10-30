<?php

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The graduated and bandRate operators, through the real evaluator.
 *
 * The fixture is a neutral one, a parking fee that steps up: 2 per hour for
 * the first 3 hours, 3 per hour up to hour 8, then 5 per hour, capped at 40.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
class GraduatedRateTest extends TestCase {

	private const BANDS = [
		['upTo' => 3, 'rate' => 2],
		['upTo' => 8, 'rate' => 3],
		['rate' => 5],
	];

	private CalculationEvaluator $eval;

	protected function setUp(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->eval = new CalculationEvaluator(new PlaceholderResolver($session));
	}

	private function fee(mixed $hours, mixed $bands = self::BANDS, mixed $cap = null): mixed {
		$args = [['prop' => 'hours'], ['prop' => 'bands']];
		if ($cap !== null) {
			$args[] = ['prop' => 'cap'];
		}

		return $this->eval->evaluate(['hours' => $hours, 'bands' => $bands, 'cap' => $cap], ['graduated' => $args]);
	}

	public function testEachBandChargesItsOwnRate(): void {
		$this->assertSame(0, $this->fee(0));
		$this->assertSame(2, $this->fee(1));
		$this->assertSame(6, $this->fee(3));
		$this->assertSame(9, $this->fee(4));
		$this->assertSame(21, $this->fee(8));
		$this->assertSame(26, $this->fee(9));
		$this->assertSame(46, $this->fee(13));
	}

	public function testTheSumEqualsTheDayByDaySeries(): void {
		$running = 0;
		for ($unit = 1; $unit <= 40; $unit++) {
			$running += $this->eval->evaluate(['u' => $unit, 'b' => self::BANDS], ['bandRate' => [['prop' => 'u'], ['prop' => 'b']]]);
			$this->assertSame($running, $this->fee($unit), sprintf('unit %d', $unit));
		}
	}

	public function testTheCapHoldsTheAmount(): void {
		$this->assertSame(21, $this->fee(8, cap: 40));
		$this->assertSame(40, $this->fee(13, cap: 40));
		$this->assertSame(40, $this->fee(500, cap: 40));
	}

	public function testANegativeQuantityChargesNothing(): void {
		$this->assertSame(0, $this->fee(-4));
	}

	public function testUnitsPastAClosedScheduleAreNotCharged(): void {
		$closed = [['upTo' => 2, 'rate' => 10], ['upTo' => 4, 'rate' => 1]];
		$this->assertSame(22, $this->fee(4, $closed));
		$this->assertSame(22, $this->fee(10, $closed));
		$this->assertNull($this->eval->evaluate(['b' => $closed], ['bandRate' => [5, ['prop' => 'b']]]));
	}

	public function testFractionalQuantitiesAndRates(): void {
		$this->assertEqualsWithDelta(7.5, $this->fee(3.5), 0.0001);
		$this->assertEqualsWithDelta(1.25, $this->fee(5, [['rate' => 0.25]]), 0.0001);
	}

	public function testALiteralScheduleInTheExpression(): void {
		$expr = ['graduated' => [['prop' => 'hours'], ['lit' => self::BANDS], 40]];
		$this->assertSame(26, $this->eval->evaluate(['hours' => 9], $expr));
	}

	public function testBandRateAtTheBoundaries(): void {
		$rate = fn (int $u): mixed => $this->eval->evaluate([], ['bandRate' => [$u, ['lit' => self::BANDS]]]);
		$this->assertNull($rate(0));
		$this->assertSame(2, $rate(1));
		$this->assertSame(2, $rate(3));
		$this->assertSame(3, $rate(4));
		$this->assertSame(3, $rate(8));
		$this->assertSame(5, $rate(9));
		$this->assertSame(5, $rate(1000));
	}

	public function testANullOperandYieldsNull(): void {
		$this->assertNull($this->fee(null));
		$this->assertNull($this->fee(4, null));
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformedSchedules(): array {
		return [
			'empty' => [[]],
			'not a list' => [['a' => ['rate' => 1]]],
			'no rate' => [[['upTo' => 3]]],
			'open band first' => [[['rate' => 1], ['upTo' => 3, 'rate' => 2]]],
			'falling bounds' => [[['upTo' => 5, 'rate' => 1], ['upTo' => 3, 'rate' => 2]]],
			'zero bound' => [[['upTo' => 0, 'rate' => 1]]],
		];
	}

	/**
	 * @dataProvider malformedSchedules
	 */
	public function testAMalformedScheduleIsRefused(mixed $bands): void {
		$this->expectException(EvaluationException::class);
		$this->fee(4, $bands);
	}

	/**
	 * `bandRate` refuses a malformed schedule at a unit of zero or less too, as `graduated` does at any quantity.
	 *
	 * @dataProvider malformedSchedules
	 */
	public function testAMalformedScheduleIsRefusedByBandRateAtAUnitOfZero(mixed $bands): void {
		$this->expectException(EvaluationException::class);
		$this->eval->evaluate(['u' => 0, 'b' => $bands], ['bandRate' => [['prop' => 'u'], ['prop' => 'b']]]);
	}

	public function testBandRateRefusesAMalformedScheduleAtANegativeUnitAndStillAnswersNullForAGoodOne(): void {
		$this->assertNull($this->eval->evaluate(['u' => -2, 'b' => self::BANDS], ['bandRate' => [['prop' => 'u'], ['prop' => 'b']]]));
		$this->expectException(EvaluationException::class);
		$this->eval->evaluate(['u' => -2, 'b' => []], ['bandRate' => [['prop' => 'u'], ['prop' => 'b']]]);
	}

	public function testANonNumericQuantityIsRefused(): void {
		$this->expectException(EvaluationException::class);
		$this->fee('four');
	}

	public function testANonNumericCapIsRefused(): void {
		$this->expectException(EvaluationException::class);
		$this->fee(4, cap: 'lots');
	}

	public function testAMissingScheduleOperandIsRefused(): void {
		$this->expectException(EvaluationException::class);
		$this->eval->evaluate(['hours' => 2], ['graduated' => [['prop' => 'hours']]]);
	}

	public function testTheValidatorAcceptsALiteralSchedule(): void {
		$errors = (new CalculationAnnotationValidator())->validate([
			'x-openregister-calculations' => [
				'fee' => [
					'type' => 'number',
					'expression' => ['graduated' => [['prop' => 'hours'], ['lit' => self::BANDS], 40]],
				],
			],
			'properties' => ['hours' => ['type' => 'integer']],
		]);
		$this->assertSame([], $errors);
	}
}
