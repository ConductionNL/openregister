<?php

/**
 * The term engine printing its working: the Easter walk, the refused roll and
 * the promise that it writes nothing.
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
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic;
use OCA\OpenRegister\Service\Flow\Timer\WalkCollector;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifies the diagnostic requirement of `flow-business-timers`.
 */
class TermDiagnosticTest extends TestCase {

	/**
	 * The subject under test.
	 *
	 * @var TermDiagnostic
	 */
	private TermDiagnostic $diagnostic;

	/**
	 * The shipped national calendar.
	 *
	 * @var WorkingCalendar
	 */
	private WorkingCalendar $calendar;

	/**
	 * Build the subject from the SAME descriptor the instance imports.
	 *
	 * A hand-written calendar would let a test pass against holidays the
	 * product does not ship.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->diagnostic = new TermDiagnostic(calculator: new SlaCalculator());
		$this->calendar = WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
	}//end setUp()

	/**
	 * 🔴 The scenario the spec names: the working of a term across Easter.
	 *
	 * Easter 2026 is 5 April, so Goede Vrijdag is 3 April, Tweede Paasdag
	 * 6 April, and the Thursday before is 2 April.
	 *
	 * @return void
	 */
	public function testTheWorkingOfATermAcrossEasterIsPrinted(): void {
		$result = $this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: new DateTimeImmutable('2026-04-02T09:00:00+02:00'),
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$skipped = array_column($result['skipped'], 'kind', 'date');

		$this->assertArrayHasKey('2026-04-03', $skipped, 'Goede Vrijdag is skipped');
		$this->assertArrayHasKey('2026-04-04', $skipped, 'and the Saturday');
		$this->assertArrayHasKey('2026-04-05', $skipped, 'and the Sunday');
		$this->assertArrayHasKey('2026-04-06', $skipped, 'and Tweede Paasdag');

		$this->assertSame(
			WalkCollector::WEEKEND,
			$skipped['2026-04-04'],
			'a day the working week does not include has no rule, and is named as the weekend'
		);
		$this->assertNotSame(
			WalkCollector::WEEKEND,
			$skipped['2026-04-03'],
			'a day a RULE made non-working is named by that rule, not lumped in with the weekend'
		);

		// The spec's own scenario says "the following Wednesday", and the
		// engine agrees. Worth spelling out, because Tuesday is the intuitive
		// wrong answer: the anchor at 09:00 spends only 0.625 of Thursday, so
		// Tuesday is consumed whole and 0.375 of a day is still owed on
		// Wednesday morning. A term counted in FRACTIONS of working days is
		// not the same as a term counted in whole ones, and this is the case
		// where the difference shows.
		$this->assertSame(
			'2026-04-08',
			substr((string)$result['firesAt'], 0, 10),
			'two business days from Thursday 09:00, across four skipped days, lands on the Wednesday'
		);
	}//end testTheWorkingOfATermAcrossEasterIsPrinted()

	/**
	 * The walk names the days it counted as well as the ones it skipped.
	 *
	 * @return void
	 */
	public function testTheWalkNamesTheDaysItCounted(): void {
		$result = $this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: new DateTimeImmutable('2026-04-02T09:00:00+02:00'),
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$counted = array_values(
			array_filter($result['walk'], static fn (array $row): bool => ($row['counted'] === true))
		);

		$this->assertNotSame([], $counted, 'a walk that lists only what it skipped cannot be checked against the total');
		$this->assertSame('2026-04-02', $counted[0]['date'], 'the anchor day is the first day that counted');
		$this->assertSame(WalkCollector::WORKING, $counted[0]['kind']);
	}//end testTheWalkNamesTheDaysItCounted()

	/**
	 * The control: a term inside one working week skips nothing.
	 *
	 * Without it, the Easter test could be passing on a calendar that calls
	 * every day non-working.
	 *
	 * @return void
	 */
	public function testATermInsideOneWeekSkipsNothing(): void {
		$result = $this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: new DateTimeImmutable('2026-06-01T09:00:00+02:00'),
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$this->assertSame([], $result['skipped'], 'the control: a Monday-to-Wednesday term skips nothing');
		$this->assertSame('2026-06-03', substr((string)$result['firesAt'], 0, 10));
	}//end testATermInsideOneWeekSkipsNothing()

	/**
	 * The diagnostic reports the fire moment the ARM path would compute.
	 *
	 * The same calculator call, with and without a collector, must land on the
	 * same instant. A diagnostic that disagrees with the engine is worse than
	 * none, because it is believed.
	 *
	 * @return void
	 */
	public function testTheDiagnosticAgreesWithTheArmPath(): void {
		$anchor = new DateTimeImmutable('2026-04-02T09:00:00+02:00');
		$armed = (new SlaCalculator())->add(
			from: $anchor,
			value: 2.0,
			unit: SlaCalculator::UNIT_BUSINESS_DAYS,
			calendar: $this->calendar
		);

		$explained = $this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: $anchor,
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$this->assertSame(
			$armed->format(DATE_ATOM),
			$explained['firesAt'],
			'the narrated walk and the armed walk are the same walk'
		);
	}//end testTheDiagnosticAgreesWithTheArmPath()

	/**
	 * 🔴 The diagnostic holds nothing that can write.
	 *
	 * "It creates no timer, no ledger event and no audit row" is checked here
	 * structurally rather than promised in a comment: the class has exactly
	 * one dependency, and it is the calculator.
	 *
	 * @return void
	 */
	public function testTheDiagnosticHoldsNothingThatCanWrite(): void {
		$constructor = (new ReflectionClass(TermDiagnostic::class))->getConstructor();
		$this->assertNotNull($constructor);

		$types = [];
		foreach ($constructor->getParameters() as $parameter) {
			$types[] = (string)$parameter->getType();
		}

		$this->assertSame(
			[SlaCalculator::class],
			$types,
			'a mapper, a connection or a dispatcher here would be something that could arm a timer'
		);
	}//end testTheDiagnosticHoldsNothingThatCanWrite()

	/**
	 * Ten calls leave the same answer and no accumulated state.
	 *
	 * @return void
	 */
	public function testTenCallsLeaveNoTrace(): void {
		$first = null;
		for ($i = 0; $i < 10; $i++) {
			$result = $this->diagnostic->explain(
				calendar: $this->calendar,
				anchor: new DateTimeImmutable('2026-04-02T09:00:00+02:00'),
				sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
			);

			if ($first === null) {
				$first = $result;
			}

			$this->assertSame($first, $result, 'call ' . $i . ' must answer exactly what call 0 answered');
		}
	}//end testTenCallsLeaveNoTrace()

	/**
	 * A ladder returns the instant of each rung, measured from the anchor.
	 *
	 * @return void
	 */
	public function testALadderReturnsTheInstantOfEachRung(): void {
		$result = $this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: new DateTimeImmutable('2026-06-01T09:00:00+02:00'),
			sla: ['value' => 5, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS],
			ladder: [
				['value' => 1, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS],
				['value' => 3, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS],
			]
		);

		$this->assertCount(2, $result['ladder']);
		$this->assertSame('2026-06-02', substr((string)$result['ladder'][0]['firesAt'], 0, 10));
		$this->assertSame(
			'2026-06-04',
			substr((string)$result['ladder'][1]['firesAt'], 0, 10),
			'each rung is measured from the ANCHOR, not from the rung before it'
		);
	}//end testALadderReturnsTheInstantOfEachRung()

	/**
	 * 🔴 The roll is NARRATED, through the engine's own roll.
	 *
	 * This test used to assert a refusal, and correctly: `SlaCalculator` had no
	 * roll, so applying one here would have printed a fire moment the arm path
	 * never produces — believed precisely because it came from the diagnostic.
	 * The engine has the roll now and this calls it, rather than walking the
	 * calendar a second time.
	 *
	 * 2 April 2026 + 2 calendar days is Saturday 4 April; Easter Sunday is the
	 * 5th and Tweede Paasdag the 6th, so `next` lands on Tuesday the 7th.
	 *
	 * @return void
	 */
	public function testARequestedRollIsNarratedThroughTheEnginesOwnRoll(): void {
		$result = $this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: new DateTimeImmutable('2026-04-02T09:00:00+02:00'),
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_CALENDAR_DAYS, 'rollToWorkingDay' => 'next']
		);

		$this->assertSame('next', $result['roll']);
		$this->assertSame('2026-04-07', substr((string)$result['firesAt'], 0, 10));
		$this->assertSame('2026-04-04', substr((string)$result['unrolledAt'], 0, 10));
		$this->assertSame('weekend', $result['rolledBy'], 'the Saturday stopped it, not the Monday it walked past');
		$this->assertTrue($result['firesOnWorkingDay']);
	}//end testARequestedRollIsNarratedThroughTheEnginesOwnRoll()

	/**
	 * A roll outside the vocabulary is still refused, not defaulted.
	 *
	 * @return void
	 */
	public function testARollOutsideTheVocabularyIsRefused(): void {
		try {
			$this->diagnostic->explain(
				calendar: $this->calendar,
				anchor: new DateTimeImmutable('2026-04-02T09:00:00+02:00'),
				sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_CALENDAR_DAYS, 'rollToWorkingDay' => 'nextWorkingDay']
			);
			$this->fail('an unknown roll must not be read as none');
		} catch (FlowTimerValidationException $e) {
			$this->assertStringContainsString('refused', $e->getMessage());
		}
	}//end testARollOutsideTheVocabularyIsRefused()

	/**
	 * The control: no roll asked for is `none`, and explains fine.
	 *
	 * @return void
	 */
	public function testNoRollAskedForExplainsFine(): void {
		$result = $this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: new DateTimeImmutable('2026-04-02T09:00:00+02:00'),
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_CALENDAR_DAYS]
		);

		$this->assertSame('none', $result['roll']);
		$this->assertSame('2026-04-04', substr((string)$result['firesAt'], 0, 10));
		$this->assertFalse(
			$result['firesOnWorkingDay'],
			'and the caller is told the landing is not a working day, which is what a roll would have been for'
		);
	}//end testNoRollAskedForExplainsFine()

	/**
	 * An unknown roll value is refused as an unknown word.
	 *
	 * @return void
	 */
	public function testAnUnknownRollValueIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);

		$this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: new DateTimeImmutable('2026-04-02T09:00:00+02:00'),
			sla: ['value' => 2, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS, 'rollToWorkingDay' => 'sideways']
		);
	}//end testAnUnknownRollValueIsRefused()

	/**
	 * A ladder longer than the ceiling is refused.
	 *
	 * @return void
	 */
	public function testALadderLongerThanTheCeilingIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);

		$this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: new DateTimeImmutable('2026-06-01T09:00:00+02:00'),
			sla: ['value' => 5, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS],
			ladder: array_fill(0, (TermDiagnostic::MAX_RUNGS + 1), ['value' => 1, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS])
		);
	}//end testALadderLongerThanTheCeilingIsRefused()

	/**
	 * A long walk truncates its NARRATION and says so, without moving the
	 * fire moment.
	 *
	 * @return void
	 */
	public function testALongWalkTruncatesItsNarrationAndSaysSo(): void {
		$result = $this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: new DateTimeImmutable('2026-01-05T09:00:00+01:00'),
			sla: ['value' => 900, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$this->assertTrue($result['walkTruncated'], 'a short list must not read as a short walk');
		$this->assertCount(WalkCollector::MAX_ROWS, $result['walk']);
		$this->assertGreaterThan(
			WalkCollector::MAX_ROWS,
			$result['examinedDays'],
			'the total is reported even though the rows are not'
		);

		$armed = (new SlaCalculator())->add(
			from: new DateTimeImmutable('2026-01-05T09:00:00+01:00'),
			value: 900.0,
			unit: SlaCalculator::UNIT_BUSINESS_DAYS,
			calendar: $this->calendar
		);
		$this->assertSame(
			$armed->format(DATE_ATOM),
			$result['firesAt'],
			'truncating the narration must not truncate the walk'
		);
	}//end testALongWalkTruncatesItsNarrationAndSaysSo()

	/**
	 * The calendar's zone is reported, because a term is counted in it.
	 *
	 * @return void
	 */
	public function testTheZoneIsReported(): void {
		$result = $this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: new DateTimeImmutable('2026-06-01T09:00:00+02:00'),
			sla: ['value' => 1, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);

		$this->assertSame($this->calendar->getTimezone(), $result['zone']);
		$this->assertSame($this->calendar->getSlug(), $result['calendar']);
	}//end testTheZoneIsReported()

	/**
	 * A refused SLA is refused before any walking happens.
	 *
	 * @return void
	 */
	public function testARefusedSlaIsRefused(): void {
		$this->expectException(FlowTimerValidationException::class);

		$this->diagnostic->explain(
			calendar: $this->calendar,
			anchor: new DateTimeImmutable('2026-06-01T09:00:00+02:00'),
			sla: ['value' => 0, 'unit' => SlaCalculator::UNIT_BUSINESS_DAYS]
		);
	}//end testARefusedSlaIsRefused()
}//end class
