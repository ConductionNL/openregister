<?php

/**
 * Relative time over a schema: the due objects are selected by one indexed
 * comparison, and the query agrees with the PHP verdict.
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
use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\Rules\ConditionRefusedException;
use OCA\OpenRegister\Service\Rules\RelativeTimeCondition;
use OCA\OpenRegister\Service\Rules\RelativeTimeEvaluation;
use OCA\OpenRegister\Service\Rules\RelativeTimeSweep;
use OCA\OpenRegister\Tests\Unit\Service\Flow\Timer\WorkingCalendarTest;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Verifies REQ-RCT-003, scenario "the sweep is a query, not a loop".
 */
#[CoversClass(RelativeTimeSweep::class)]
#[UsesClass(RelativeTimeEvaluation::class)]
#[UsesClass(RelativeTimeCondition::class)]
#[UsesClass(SlaCalculator::class)]
#[UsesClass(WorkingCalendar::class)]
class RelativeTimeSweepTest extends TestCase {

	/**
	 * The object search.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * The calendar resolution.
	 *
	 * @var WorkingCalendarService&MockObject
	 */
	private WorkingCalendarService&MockObject $calendars;

	/**
	 * The subject under test.
	 *
	 * @var RelativeTimeSweep
	 */
	private RelativeTimeSweep $sweep;

	/**
	 * The condition, for the PHP verdict the query must agree with.
	 *
	 * @var RelativeTimeCondition
	 */
	private RelativeTimeCondition $condition;

	/**
	 * Monday 14 September 2026, 09:30 in Amsterdam.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new \DateTime('2026-09-14T09:30:00+02:00'));

		$this->calendars = $this->createMock(WorkingCalendarService::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->condition = new RelativeTimeCondition(calculator: new SlaCalculator());
		$this->sweep = new RelativeTimeSweep(
			evaluation: new RelativeTimeEvaluation(condition: $this->condition, calendars: $this->calendars, clock: $clock),
			objects: $this->objects
		);
	}//end setUp()

	/**
	 * "createdAt is more than 3 working hours ago".
	 *
	 * @return array<string, mixed> The node.
	 */
	private static function threeWorkingHoursOld(): array {
		return [
			RelativeTimeCondition::KEY => [
				'property' => 'createdAt',
				RelativeTimeCondition::MORE_THAN => ['value' => 3, 'unit' => RelativeTimeCondition::UNIT_WORKING_HOURS],
			],
		];
	}//end threeWorkingHoursOld()

	/**
	 * The national calendar, as the organisation's calendar.
	 *
	 * @return WorkingCalendar The calendar.
	 */
	private static function national(): WorkingCalendar {
		return WorkingCalendar::fromArray(definition: WorkingCalendarTest::nlNational());
	}//end national()

	/**
	 * 🔴 The spec's sweep scenario: ONE operator filter on the property, scoped
	 * to the register, schema and organisation, handed to the object search.
	 *
	 * The threshold is the instant the shared engine walks three working hours
	 * back to from Monday 09:30 (Amsterdam), in UTC: what the database compares
	 * against. That the instant is the right one is the PHP verdict's test,
	 * {@see testTheQueryAgreesWithThePhpVerdict()}.
	 *
	 * @return void
	 */
	public function testTheDueObjectsAreSelectedByOneIndexedComparison(): void {
		$this->calendars->expects($this->once())->method('resolve')
			->with(null, 'org-1')
			->willReturn(self::national());

		$this->objects->expects($this->once())->method('searchObjects')
			->with(
				[
					'status' => 'open',
					'@self' => ['register' => '4', 'schema' => '9', 'organisation' => 'org-1'],
					'createdAt' => ['lte' => '2026-09-13T22:30:00+00:00'],
				],
				false,
				false
			)
			->willReturn(['k1' => ['id' => 'a'], 'k2' => ['id' => 'b']]);

		$due = $this->sweep->dueObjects(
			register: 4,
			schema: 9,
			node: self::threeWorkingHoursOld(),
			organisation: 'org-1',
			filters: ['status' => 'open'],
			rbac: false,
			multitenancy: false
		);

		$this->assertSame([['id' => 'a'], ['id' => 'b']], $due);
	}//end testTheDueObjectsAreSelectedByOneIndexedComparison()

	/**
	 * The query and the PHP verdict agree on both sides of the threshold.
	 *
	 * @return void
	 */
	public function testTheQueryAgreesWithThePhpVerdict(): void {
		$this->calendars->method('resolve')->willReturn(self::national());
		$query = $this->sweep->query(register: 4, schema: 9, node: self::threeWorkingHoursOld(), organisation: 'org-1');
		$this->assertNotNull($query);
		$threshold = new DateTimeImmutable($query['createdAt']['lte']);

		$evaluation = new RelativeTimeEvaluation(
			condition: $this->condition,
			calendars: $this->calendars,
			clock: $this->fixedClock()
		);

		$this->assertTrue($evaluation->holds(node: self::threeWorkingHoursOld(), document: ['createdAt' => $threshold->format(DATE_ATOM)], organisation: 'org-1'));
		$this->assertFalse($evaluation->holds(node: self::threeWorkingHoursOld(), document: ['createdAt' => $threshold->modify('+1 minute')->format(DATE_ATOM)], organisation: 'org-1'));
	}//end testTheQueryAgreesWithThePhpVerdict()

	/**
	 * Objects without an organisation are swept on their own, never mixed with
	 * objects whose organisation counts in another calendar.
	 *
	 * @return void
	 */
	public function testObjectsWithoutAnOrganisationAreSweptAsSuch(): void {
		$this->calendars->expects($this->once())->method('resolve')->with(null, null)->willReturn(self::national());

		$query = $this->sweep->query(register: 4, schema: 9, node: self::threeWorkingHoursOld(), organisation: null);

		$this->assertSame('IS NULL', $query['@self']['organisation']);
	}//end testObjectsWithoutAnOrganisationAreSweptAsSuch()

	/**
	 * A calendar-day condition needs no calendar and compiles from the clock alone.
	 *
	 * @return void
	 */
	public function testALessThanConditionInDaysSelectsTheYoungerObjects(): void {
		$this->calendars->expects($this->never())->method('resolve');
		$node = [
			RelativeTimeCondition::KEY => [
				'property' => 'receivedAt',
				RelativeTimeCondition::LESS_THAN => ['value' => 2, 'unit' => RelativeTimeCondition::UNIT_CALENDAR_DAYS],
			],
		];

		$query = $this->sweep->query(register: 'r', schema: 's', node: $node, organisation: 'org-2');

		$this->assertSame(['gt' => '2026-09-12T07:30:00+00:00'], $query['receivedAt']);
	}//end testALessThanConditionInDaysSelectsTheYoungerObjects()

	/**
	 * A further filter on the compared property would silently widen or narrow
	 * the comparison: refused, and nothing is searched.
	 *
	 * @return void
	 */
	public function testAFilterOnTheComparedPropertyIsRefused(): void {
		$this->calendars->method('resolve')->willReturn(self::national());
		$this->objects->expects($this->never())->method('searchObjects');

		$this->expectException(InvalidArgumentException::class);
		$this->sweep->dueObjects(register: 4, schema: 9, node: self::threeWorkingHoursOld(), organisation: 'org-1', filters: ['createdAt' => 'x']);
	}//end testAFilterOnTheComparedPropertyIsRefused()

	/**
	 * A node that is not a `$age` node selects nothing and searches nothing.
	 *
	 * @return void
	 */
	public function testANodeThatIsNotRelativeTimeSelectsNothing(): void {
		$this->objects->expects($this->never())->method('searchObjects');

		$this->assertSame([], $this->sweep->dueObjects(register: 4, schema: 9, node: ['==' => [1, 1]], organisation: 'org-1'));
	}//end testANodeThatIsNotRelativeTimeSelectsNothing()

	/**
	 * A calendar that does not resolve refuses the sweep rather than counting wall-clock time.
	 *
	 * @return void
	 */
	public function testAnUnresolvableCalendarRefusesTheSweep(): void {
		$this->calendars->method('resolve')->willThrowException(
			new \OCA\OpenRegister\Exception\FlowTimerValidationException('calendar "gone" not found')
		);
		$this->objects->expects($this->never())->method('searchObjects');

		$this->expectException(ConditionRefusedException::class);
		$this->sweep->dueObjects(register: 4, schema: 9, node: self::threeWorkingHoursOld(), organisation: 'org-1');
	}//end testAnUnresolvableCalendarRefusesTheSweep()

	/**
	 * The same Monday 09:30 clock, for a second evaluation.
	 *
	 * @return ITimeFactory&MockObject The clock.
	 */
	private function fixedClock(): ITimeFactory&MockObject {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new \DateTime('2026-09-14T09:30:00+02:00'));
		return $clock;
	}//end fixedClock()
}//end class
