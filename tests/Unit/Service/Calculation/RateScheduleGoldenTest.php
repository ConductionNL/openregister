<?php

/**
 * RateSchedule golden test
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calculation;

use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Pins the outcomes of RateSchedule through the graduated and bandRate
 * operators: amounts, the cap, null operands, and the exact refusal message
 * (and which refusal wins) for every malformed operand and schedule.
 *
 * @covers \OCA\OpenRegister\Service\Calculation\RateSchedule
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
class RateScheduleGoldenTest extends TestCase {

	private const BANDS = [
		['upTo' => 3, 'rate' => 2],
		['upTo' => 8, 'rate' => 3],
		['rate' => 5],
	];

	private const CLOSED = [
		['upTo' => 2, 'rate' => 1.5],
		['upTo' => 4, 'rate' => 2],
	];

	private CalculationEvaluator $eval;

	protected function setUp(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->eval = new CalculationEvaluator(new PlaceholderResolver($session));
	}//end setUp()

	/**
	 * Evaluate graduated over literal operands.
	 *
	 * @param mixed $quantity The quantity.
	 * @param mixed $bands    The schedule.
	 * @param mixed $cap      The cap, or null to leave it out.
	 *
	 * @return mixed The amount.
	 */
	private function graduated(mixed $quantity, mixed $bands, mixed $cap=null): mixed {
		$object = ['q' => $quantity, 'b' => $bands, 'c' => $cap];
		$args   = [['prop' => 'q'], ['prop' => 'b']];
		if ($cap !== null) {
			$args[] = ['prop' => 'c'];
		}

		return $this->eval->evaluate($object, ['graduated' => $args]);
	}//end graduated()

	/**
	 * Evaluate bandRate over literal operands.
	 *
	 * @param mixed $unit  The unit.
	 * @param mixed $bands The schedule.
	 *
	 * @return mixed The rate.
	 */
	private function bandRate(mixed $unit, mixed $bands): mixed {
		return $this->eval->evaluate(['u' => $unit, 'b' => $bands], ['bandRate' => [['prop' => 'u'], ['prop' => 'b']]]);
	}//end bandRate()

	/**
	 * Amounts, caps, rates and nulls.
	 *
	 * @return void
	 */
	public function testPinnedValues(): void {
		$this->assertSame(0, $this->graduated(0, self::BANDS));
		$this->assertSame(0, $this->graduated(-3, self::BANDS));
		$this->assertSame(9, $this->graduated(4, self::BANDS));
		$this->assertSame(9, $this->graduated('4', self::BANDS));
		$this->assertSame(7.5, $this->graduated(3.5, self::BANDS));
		$this->assertSame(46, $this->graduated(13, self::BANDS));
		$this->assertSame(40, $this->graduated(13, self::BANDS, 40));
		$this->assertSame(40.5, $this->graduated(13, self::BANDS, '40.5'));
		$this->assertSame(21, $this->graduated(8, self::BANDS, 40));
		$this->assertSame(7.0, $this->graduated(10, self::CLOSED));
		$this->assertSame(6.0, $this->graduated(3.5, self::CLOSED, 99));
		$this->assertNull($this->graduated(null, self::BANDS));
		$this->assertNull($this->graduated(4, null));

		$this->assertSame(2, $this->bandRate(1, self::BANDS));
		$this->assertSame(3, $this->bandRate(8, self::BANDS));
		$this->assertSame(5, $this->bandRate('9', self::BANDS));
		$this->assertSame(1.5, $this->bandRate(2, self::CLOSED));
		$this->assertNull($this->bandRate(5, self::CLOSED));
		$this->assertNull($this->bandRate(0, self::BANDS));
		$this->assertNull($this->bandRate(null, self::BANDS));
		$this->assertNull($this->bandRate(3, null));
	}//end testPinnedValues()

	/**
	 * Every refusal and the message it carries.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: mixed, 3: mixed, 4: string}>
	 */
	public static function refusals(): array {
		$rate    = 'Band %d of a rate schedule needs a numeric rate.';
		$rise    = 'The upTo bounds of a rate schedule must rise from band to band.';
		$open    = 'Only the last band of a rate schedule may leave out upTo.';
		$list    = 'A rate schedule must be a non-empty list of bands.';
		$operand = 'graduated/bandRate needs a numeric quantity and a list of bands.';
		$cap     = 'The cap of graduated must be numeric.';

		return [
			'graduated text quantity'       => ['graduated', 'four', self::BANDS, null, $operand],
			'graduated scalar schedule'     => ['graduated', 4, 'bands', null, $operand],
			'bandRate text unit'            => ['bandRate', 'x', self::BANDS, null, $operand],
			'cap not numeric'               => ['graduated', 4, self::BANDS, 'lots', $cap],
			'cap checked before schedule'   => ['graduated', 4, [], 'lots', $cap],
			'empty schedule'                => ['graduated', 4, [], null, $list],
			'empty schedule at zero'        => ['graduated', 0, [], null, $list],
			'map schedule'                  => ['bandRate', 1, ['a' => ['rate' => 1]], null, $list],
			'empty schedule bandRate at 0'  => ['bandRate', 0, [], null, $list],
			'band not an object'            => ['graduated', 4, [5], null, sprintf($rate, 1)],
			'second band no rate'           => ['graduated', 4, [['upTo' => 3, 'rate' => 1], ['upTo' => 5]], null, sprintf($rate, 2)],
			'text rate'                     => ['bandRate', 1, [['rate' => 'x']], null, sprintf($rate, 1)],
			'open band first'               => ['graduated', 4, [['rate' => 1], ['upTo' => 3, 'rate' => 2]], null, $open],
			'open band before bad rate'     => ['graduated', 4, [['rate' => 1], ['upTo' => 3]], null, $open],
			'bad rate before open band'     => ['graduated', 4, [['upTo' => 3, 'rate' => 'x'], ['rate' => 1], ['rate' => 2]], null, sprintf($rate, 1)],
			'falling bounds'                => ['graduated', 4, [['upTo' => 5, 'rate' => 1], ['upTo' => 3, 'rate' => 2]], null, $rise],
			'equal bounds'                  => ['graduated', 4, [['upTo' => 5, 'rate' => 1], ['upTo' => 5, 'rate' => 2]], null, $rise],
			'zero bound'                    => ['graduated', 4, [['upTo' => 0, 'rate' => 1]], null, $rise],
			'text bound'                    => ['graduated', 4, [['upTo' => 'x', 'rate' => 1]], null, $rise],
			'falling bound before bad rate' => ['graduated', 4, [['upTo' => 5, 'rate' => 1], ['upTo' => 3, 'rate' => 2], ['upTo' => 9]], null, $rise],
			'bad rate before falling bound' => ['graduated', 4, [['upTo' => 5, 'rate' => 1], ['upTo' => 3]], null, sprintf($rate, 2)],
			'open then falling'             => ['bandRate', 1, [['upTo' => 5, 'rate' => 1], ['rate' => 2], ['upTo' => 3, 'rate' => 3]], null, $open],
			'malformed past the quantity'   => ['graduated', 1, [['upTo' => 3, 'rate' => 1], ['upTo' => 2, 'rate' => 2]], null, $rise],
		];
	}//end refusals()

	/**
	 * Each refusal carries its pinned message.
	 *
	 * @param string $operator The operator: graduated or bandRate.
	 * @param mixed  $quantity The quantity or unit.
	 * @param mixed  $bands    The schedule.
	 * @param mixed  $cap      The cap, or null.
	 * @param string $message  The pinned message.
	 *
	 * @dataProvider refusals
	 *
	 * @return void
	 */
	public function testRefusesWithThePinnedMessage(string $operator, mixed $quantity, mixed $bands, mixed $cap, string $message): void {
		$this->expectException(EvaluationException::class);
		$this->expectExceptionMessage($message);

		if ($operator === 'bandRate') {
			$this->bandRate($quantity, $bands);
			return;
		}

		$this->graduated($quantity, $bands, $cap);
	}//end testRefusesWithThePinnedMessage()
}//end class
