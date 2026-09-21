<?php

/**
 * Relative time: the spec's two clock scenarios, the compiled comparison and
 * the calendar that is refused rather than downgraded.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
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
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Rules\ConditionRefusedException;
use OCA\OpenRegister\Service\Rules\RelativeTimeCondition;
use OCA\OpenRegister\Tests\Unit\Service\Flow\Timer\WorkingCalendarTest;
use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-RCT-003.
 */
class RelativeTimeConditionTest extends TestCase {

	/**
	 * The subject under test.
	 *
	 * @var RelativeTimeCondition
	 */
	private RelativeTimeCondition $condition;

	/**
	 * The shipped national calendar.
	 *
	 * @var WorkingCalendar
	 */
	private WorkingCalendar $calendar;

	/**
	 * Build against the SHIPPED calendar, not a hand-written one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->condition = new RelativeTimeCondition(calculator: new SlaCalculator());
		$this->calendar = WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
	}//end setUp()

	/**
	 * "createdAt is more than 3 working hours ago".
	 *
	 * @return array<string, mixed> The node.
	 */
	private function threeWorkingHoursOld(): array {
		return [
			RelativeTimeCondition::KEY => [
				'property' => 'createdAt',
				RelativeTimeCondition::MORE_THAN => [
					'value' => 3,
					'unit' => RelativeTimeCondition::UNIT_WORKING_HOURS,
				],
			],
		];
	}//end threeWorkingHoursOld()

	/**
	 * 🔴 The spec's first scenario: created Friday 16:30, evaluated Monday
	 * 09:30, more than three working hours old.
	 *
	 * @return void
	 */
	public function testEscalateAfterThreeWorkingHours(): void {
		$this->assertTrue(
			$this->condition->holds(
				node: $this->threeWorkingHoursOld(),
				document: ['createdAt' => '2026-09-11T16:30:00+02:00'],
				now: new DateTimeImmutable('2026-09-14T09:30:00+02:00'),
				calendar: $this->calendar
			),
			'Friday afternoon to Monday morning is more than three working hours'
		);
	}//end testEscalateAfterThreeWorkingHours()

	/**
	 * 🔴 The spec's second scenario: the same object on Saturday morning does
	 * NOT hold, because the weekend does not count.
	 *
	 * This is the whole feature. A wall-clock offset would answer true here,
	 * and that is the escalation firing on a Saturday that the row exists to
	 * prevent.
	 *
	 * @return void
	 */
	public function testTheWeekendDoesNotCount(): void {
		$this->assertFalse(
			$this->condition->holds(
				node: $this->threeWorkingHoursOld(),
				document: ['createdAt' => '2026-09-11T16:30:00+02:00'],
				now: new DateTimeImmutable('2026-09-12T09:30:00+02:00'),
				calendar: $this->calendar
			),
			'seventeen wall-clock hours have passed, but not three working ones'
		);
	}//end testTheWeekendDoesNotCount()

	/**
	 * The control: the same offset in WALL-CLOCK hours does hold on Saturday.
	 *
	 * Without this, the test above could be passing because the condition
	 * never holds at all.
	 *
	 * @return void
	 */
	public function testTheSameOffsetInWallClockHoursHoldsOnTheSaturday(): void {
		$node = $this->threeWorkingHoursOld();
		$node[RelativeTimeCondition::KEY][RelativeTimeCondition::MORE_THAN]['unit'] = RelativeTimeCondition::UNIT_HOURS;

		$this->assertTrue(
			$this->condition->holds(
				node: $node,
				document: ['createdAt' => '2026-09-11T16:30:00+02:00'],
				now: new DateTimeImmutable('2026-09-12T09:30:00+02:00'),
				calendar: $this->calendar
			),
			'the control: in wall-clock hours the weekend counts, and that is the difference the unit makes'
		);
	}//end testTheSameOffsetInWallClockHoursHoldsOnTheSaturday()

	/**
	 * 🔴 The comparison compiles to one indexed comparison, not a loop.
	 *
	 * @return void
	 */
	public function testTheComparisonCompilesToOneIndexedComparison(): void {
		$compiled = $this->condition->compile(
			node: $this->threeWorkingHoursOld(),
			now: new DateTimeImmutable('2026-09-14T09:30:00+02:00'),
			calendar: $this->calendar
		);

		$this->assertSame('createdAt', $compiled['property']);
		$this->assertSame('<=', $compiled['operator'], '"older than" selects rows at or before the threshold');
		$this->assertNotSame('', $compiled['value'], 'and the threshold is ONE instant, so the sweep is a query');

		// The threshold is a real instant and the walk happened once, here.
		$threshold = new DateTimeImmutable($compiled['value']);
		$this->assertSame(
			'2026-09-14T00:30:00+02:00',
			$threshold->format('c'),
			'three working hours back from Monday 09:30 is 9 hours of the working day, landing at 00:30'
		);
	}//end testTheComparisonCompilesToOneIndexedComparison()

	/**
	 * `lessThan` inverts the operator, and selects the young rows.
	 *
	 * Asserted because getting this inversion wrong selects exactly the
	 * objects that are NOT due, which reads as "the rule does nothing".
	 *
	 * @return void
	 */
	public function testLessThanInvertsTheOperator(): void {
		$node = [
			RelativeTimeCondition::KEY => [
				'property' => 'createdAt',
				RelativeTimeCondition::LESS_THAN => ['value' => 3, 'unit' => RelativeTimeCondition::UNIT_HOURS],
			],
		];

		$compiled = $this->condition->compile(
			node: $node,
			now: new DateTimeImmutable('2026-09-14T09:30:00+02:00'),
			calendar: $this->calendar
		);

		$this->assertSame('>', $compiled['operator']);
		$this->assertTrue(
			$this->condition->holds(
				node: $node,
				document: ['createdAt' => '2026-09-14T09:00:00+02:00'],
				now: new DateTimeImmutable('2026-09-14T09:30:00+02:00'),
				calendar: $this->calendar
			),
			'half an hour old is younger than three hours'
		);
	}//end testLessThanInvertsTheOperator()

	/**
	 * 🔴 A business unit with no calendar is refused AT SAVE, naming it.
	 *
	 * @return void
	 */
	public function testABusinessUnitWithNoCalendarIsRefusedAtSave(): void {
		$refusal = $this->condition->refusalFor(node: $this->threeWorkingHoursOld(), calendar: null);

		$this->assertNotNull($refusal, 'it would silently become wall-clock time, which is a different deadline');
		$this->assertStringContainsString('working calendar', (string)$refusal);
		$this->assertStringContainsString(RelativeTimeCondition::UNIT_WORKING_HOURS, (string)$refusal);
	}//end testABusinessUnitWithNoCalendarIsRefusedAtSave()

	/**
	 * And it is NOT downgraded at evaluation either: it refuses there too.
	 *
	 * The same check does both, deliberately: `compile()` runs `refusalFor()`
	 * on every evaluation, so a condition stored before the validator existed —
	 * through an import, a fixture, a direct write — meets the refusal at the
	 * moment it would otherwise have quietly changed meaning. `SlaCalculator`
	 * refuses a business unit with a null calendar as well, so a caller that
	 * skipped this class entirely still cannot get wall-clock time by accident.
	 *
	 * @return void
	 */
	public function testABusinessUnitWithNoCalendarIsNotDowngradedAtEvaluation(): void {
		$this->expectException(ConditionRefusedException::class);

		$this->condition->holds(
			node: $this->threeWorkingHoursOld(),
			document: ['createdAt' => '2026-09-11T16:30:00+02:00'],
			now: new DateTimeImmutable('2026-09-14T09:30:00+02:00'),
			calendar: null
		);
	}//end testABusinessUnitWithNoCalendarIsNotDowngradedAtEvaluation()

	/**
	 * The control: a wall-clock unit needs no calendar at all.
	 *
	 * An author saying "two days" should not have to invent a calendar.
	 *
	 * @return void
	 */
	public function testAWallClockUnitNeedsNoCalendar(): void {
		$node = [
			RelativeTimeCondition::KEY => [
				'property' => 'createdAt',
				RelativeTimeCondition::MORE_THAN => ['value' => 2, 'unit' => RelativeTimeCondition::UNIT_CALENDAR_DAYS],
			],
		];

		$this->assertNull($this->condition->refusalFor(node: $node, calendar: null));
		$this->assertTrue(
			$this->condition->holds(
				node: $node,
				document: ['createdAt' => '2026-09-10T09:00:00+02:00'],
				now: new DateTimeImmutable('2026-09-14T09:00:00+02:00'),
				calendar: null
			)
		);
	}//end testAWallClockUnitNeedsNoCalendar()

	/**
	 * An unknown unit is refused, naming the ones that exist.
	 *
	 * @return void
	 */
	public function testAnUnknownUnitIsRefused(): void {
		$node = $this->threeWorkingHoursOld();
		$node[RelativeTimeCondition::KEY][RelativeTimeCondition::MORE_THAN]['unit'] = 'fortnights';

		$refusal = $this->condition->refusalFor(node: $node, calendar: $this->calendar);

		$this->assertNotNull($refusal);
		$this->assertStringContainsString('fortnights', (string)$refusal);
	}//end testAnUnknownUnitIsRefused()

	/**
	 * A condition naming no property, and one naming no comparison, are both
	 * refused.
	 *
	 * @return void
	 */
	public function testAMalformedConditionIsRefused(): void {
		$this->assertNotNull(
			$this->condition->refusalFor(
				node: [RelativeTimeCondition::KEY => [RelativeTimeCondition::MORE_THAN => ['value' => 1, 'unit' => 'hours']]],
				calendar: $this->calendar
			),
			'a condition that names no property compares nothing'
		);

		$this->assertNotNull(
			$this->condition->refusalFor(
				node: [RelativeTimeCondition::KEY => ['property' => 'createdAt']],
				calendar: $this->calendar
			),
			'and one that names no comparison is not a comparison'
		);
	}//end testAMalformedConditionIsRefused()

	/**
	 * A zero, a negative and an oversized offset are refused.
	 *
	 * @return void
	 */
	public function testAnOffsetOutsideTheBoundsIsRefused(): void {
		foreach ([0, -1, (RelativeTimeCondition::MAX_OFFSET + 1)] as $value) {
			$node = $this->threeWorkingHoursOld();
			$node[RelativeTimeCondition::KEY][RelativeTimeCondition::MORE_THAN]['value'] = $value;

			$this->assertNotNull(
				$this->condition->refusalFor(node: $node, calendar: $this->calendar),
				sprintf('an offset of %d must be refused where an author reads it', $value)
			);
		}
	}//end testAnOffsetOutsideTheBoundsIsRefused()

	/**
	 * 🔴 An object whose date is missing or unreadable REFUSES; it is not
	 * quietly "not due".
	 *
	 * @return void
	 */
	public function testAnUnreadableDateRefusesRatherThanReadingAsNotDue(): void {
		foreach ([[], ['createdAt' => ''], ['createdAt' => 'ooit']] as $document) {
			try {
				$this->condition->holds(
					node: $this->threeWorkingHoursOld(),
					document: $document,
					now: new DateTimeImmutable('2026-09-14T09:30:00+02:00'),
					calendar: $this->calendar
				);
				$this->fail('an object the rule cannot judge must not be silently excluded from every sweep');
			} catch (ConditionRefusedException $e) {
				$this->assertSame(RelativeTimeCondition::KEY, $e->getConditionName());
			}
		}
	}//end testAnUnreadableDateRefusesRatherThanReadingAsNotDue()

	/**
	 * The PHP verdict and the compiled comparison agree.
	 *
	 * A sweep selects by query and a save evaluates in PHP, so the two
	 * disagreeing is a rule that fires on a list it then refuses to act on.
	 *
	 * @return void
	 */
	public function testThePhpVerdictAndTheCompiledComparisonAgree(): void {
		$now = new DateTimeImmutable('2026-09-14T09:30:00+02:00');
		$compiled = $this->condition->compile(node: $this->threeWorkingHoursOld(), now: $now, calendar: $this->calendar);
		$threshold = new DateTimeImmutable($compiled['value']);

		foreach (['2026-09-11T16:30:00+02:00', '2026-09-14T09:29:00+02:00', '2026-09-14T00:30:00+02:00'] as $created) {
			$byQuery = ((new DateTimeImmutable($created))->getTimestamp() <= $threshold->getTimestamp());
			$byPhp = $this->condition->holds(
				node: $this->threeWorkingHoursOld(),
				document: ['createdAt' => $created],
				now: $now,
				calendar: $this->calendar
			);

			$this->assertSame($byQuery, $byPhp, sprintf('the two verdicts differ for %s', $created));
		}
	}//end testThePhpVerdictAndTheCompiledComparisonAgree()

	/**
	 * A node that is not a relative-time condition is left alone.
	 *
	 * @return void
	 */
	public function testAnOrdinaryNodeIsLeftAlone(): void {
		$this->assertFalse($this->condition->isRelativeTime(node: ['==' => [['var' => 'status'], 'open']]));
		$this->assertNull($this->condition->refusalFor(node: ['==' => []], calendar: null));
		$this->assertNull(
			$this->condition->compile(node: ['==' => []], now: new DateTimeImmutable(), calendar: null)
		);
	}//end testAnOrdinaryNodeIsLeftAlone()
}//end class
