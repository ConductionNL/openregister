<?php

/**
 * The term diagnostic's own reading of `rollToWorkingDay`: the boolean
 * shorthands and the refusal, checked against a calculator that accepts any
 * SLA so the diagnostic's rule is the only one in play.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/term-engine-diagnostic/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTimeImmutable;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\RollRule;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic;
use OCA\OpenRegister\Service\Flow\Timer\TermQuery;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * `true` rolls to the next working day, other non-strings mean none, unknown words are refused.
 */
class TermDiagnosticRollTest extends TestCase {

	/**
	 * A calculator that validates everything and records the roll it is asked for.
	 *
	 * @var SlaCalculatorInterface&MockObject
	 */
	private SlaCalculatorInterface&MockObject $calculator;

	/**
	 * The shipped national calendar.
	 *
	 * @var WorkingCalendar
	 */
	private WorkingCalendar $calendar;

	/**
	 * Build the subject's collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calendar   = WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
		$this->calculator = $this->createMock(SlaCalculatorInterface::class);
		$this->calculator->method('validateSla')->willReturn(['value' => 1, 'unit' => SlaCalculatorInterface::UNIT_CALENDAR_DAYS]);
		$this->calculator->method('addWatched')->willReturn(new DateTimeImmutable('2026-06-02T09:00:00+02:00'));
	}//end setUp()

	/**
	 * Explain a one-day term with the given roll value.
	 *
	 * @param mixed $roll The submitted `rollToWorkingDay`.
	 *
	 * @return array<string, mixed>
	 */
	private function explainWith(mixed $roll): array {
		return (new TermDiagnostic(calculator: $this->calculator))->explain(
			query: new TermQuery(
				calendar: $this->calendar,
				anchor: new DateTimeImmutable('2026-06-01T09:00:00+02:00'),
				sla: ['value' => 1, 'unit' => SlaCalculatorInterface::UNIT_CALENDAR_DAYS, 'rollToWorkingDay' => $roll]
			)
		);
	}//end explainWith()

	/**
	 * Expect the engine's roll to be called with the given roll word.
	 *
	 * @param string $expected The roll the engine must be asked for.
	 *
	 * @return void
	 */
	private function expectEngineRoll(string $expected): void {
		$this->calculator->expects($this->once())
			->method('roll')
			->with(
				$this->anything(),
				$this->callback(fn (RollRule $rule): bool => $rule->roll === $expected && $rule->calendar === $this->calendar)
			)
			->willReturn(['at' => new DateTimeImmutable('2026-06-02T09:00:00+02:00'), 'unrolledAt' => null, 'rolledBy' => null]);
	}//end expectEngineRoll()

	/**
	 * Boolean true is the shorthand for `next`, and is read before any normalising.
	 *
	 * @return void
	 */
	public function testTrueMeansRollToTheNextWorkingDay(): void {
		$this->expectEngineRoll(expected: 'next');

		$this->assertSame('next', $this->explainWith(roll: true)['roll']);
	}//end testTrueMeansRollToTheNextWorkingDay()

	/**
	 * Boolean false, null and other non-strings mean no roll.
	 *
	 * @return void
	 */
	public function testFalseMeansNoRoll(): void {
		$this->expectEngineRoll(expected: 'none');

		$result = $this->explainWith(roll: false);

		$this->assertSame('none', $result['roll']);
		$this->assertNull($result['unrolledAt']);
	}//end testFalseMeansNoRoll()

	/**
	 * A number is not a roll the vocabulary knows, so it is none rather than refused.
	 *
	 * @return void
	 */
	public function testANumberMeansNoRoll(): void {
		$this->expectEngineRoll(expected: 'none');

		$this->assertSame('none', $this->explainWith(roll: 1)['roll']);
	}//end testANumberMeansNoRoll()

	/**
	 * A word outside the vocabulary is refused by the diagnostic itself and never rolled.
	 *
	 * @return void
	 */
	public function testAnUnknownRollWordIsRefusedWithTheVocabulary(): void {
		$this->calculator->expects($this->never())->method('addWatched');
		$this->calculator->expects($this->never())->method('roll');

		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("rollToWorkingDay 'sideways' is refused: use one of none, next, previous.");

		$this->explainWith(roll: 'sideways');
	}//end testAnUnknownRollWordIsRefusedWithTheVocabulary()

	/**
	 * Each word of the vocabulary passes through unchanged.
	 *
	 * @return void
	 */
	public function testPreviousPassesThrough(): void {
		$this->expectEngineRoll(expected: 'previous');

		$this->assertSame('previous', $this->explainWith(roll: 'previous')['roll']);
	}//end testPreviousPassesThrough()
}//end class
