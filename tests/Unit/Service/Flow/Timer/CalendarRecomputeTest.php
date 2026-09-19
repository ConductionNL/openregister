<?php

/**
 * A calendar change re-projects the deadlines that cross it: the three
 * dependency verdicts, the moved-only supersession and the idempotency key.
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
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTime;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\CalendarDependency;
use OCA\OpenRegister\Service\Flow\Timer\CalendarRecompute;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the recompute requirements of `flow-business-timers`.
 */
class CalendarRecomputeTest extends TestCase {

	/**
	 * What app config holds.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The calendar the timers are measured against.
	 *
	 * @var WorkingCalendar
	 */
	private WorkingCalendar $calendar;

	/**
	 * The calendar with 2027-05-05 closed.
	 *
	 * @var WorkingCalendar
	 */
	private WorkingCalendar $withClosure;

	/**
	 * Build both calendars from the SHIPPED descriptor, plus one exception.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$definition = WorkingCalendarTest::nlNational();
		$this->calendar = WorkingCalendar::fromArray(definition: $definition);

		$closed = $definition;
		// The descriptor spells exceptions as a LIST of {date, name}, not as a
		// map keyed by date. Building the fixture the other way made every
		// test error rather than fail, which is the right noise: a calendar
		// that cannot be constructed is not a calendar the engine would have
		// accepted either.
		$closed['exceptions'] = array_merge(
			(array)($definition['exceptions'] ?? []),
			[['date' => '2027-05-05', 'name' => 'Gemeentelijke sluiting']]
		);
		$this->withClosure = WorkingCalendar::fromArray(definition: $closed);
	}//end setUp()

	/**
	 * An app-config double over an array.
	 *
	 * @return IAppConfig The double.
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return ($this->config[$key] ?? $default);
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return $config;
	}//end appConfig()

	/**
	 * A calendar service answering with one calendar for `nl-national`.
	 *
	 * @param WorkingCalendar $calendar What `nl-national` resolves to.
	 * @param bool            $throws   Whether resolution fails.
	 *
	 * @return WorkingCalendarService The double.
	 */
	private function calendars(WorkingCalendar $calendar, bool $throws = false): WorkingCalendarService {
		$service = $this->createMock(WorkingCalendarService::class);
		if ($throws === true) {
			$service->method('resolve')->willThrowException(
				new FlowTimerValidationException(message: 'Working calendar does not exist')
			);

			return $service;
		}

		$service->method('resolve')->willReturnCallback(
			function (?string $calendarSlug, ?string $organisation) use ($calendar): WorkingCalendar {
				// The resolution order the real service uses: a named slug
				// wins, then the organisation's, then the default.
				if (trim((string)$calendarSlug) === 'other') {
					return WorkingCalendar::fromArray(
						definition: array_merge(WorkingCalendarTest::nlNational(), ['slug' => 'other'])
					);
				}

				return $calendar;
			}
		);

		return $service;
	}//end calendars()

	/**
	 * The subject under test.
	 *
	 * @param WorkingCalendar $calendar What the calendar resolves to.
	 * @param bool            $throws   Whether resolution fails.
	 *
	 * @return CalendarRecompute The service.
	 */
	private function recompute(WorkingCalendar $calendar, bool $throws = false): CalendarRecompute {
		$calendars = $this->calendars(calendar: $calendar, throws: $throws);

		return new CalendarRecompute(
			dependency: new CalendarDependency(calendars: $calendars),
			calendars: $calendars,
			calculator: new SlaCalculator(),
			appConfig: $this->appConfig(),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end recompute()

	/**
	 * An armed timer, with its fire moment computed under a given calendar.
	 *
	 * @param string          $uuid       The timer.
	 * @param string          $start      The running-since instant.
	 * @param float           $budget     The budget in business days.
	 * @param WorkingCalendar $calendar   The calendar its stored moment came from.
	 * @param string|null     $slug       The calendar it names, if any.
	 * @param string          $state      Its state.
	 *
	 * @return FlowTimer The timer.
	 */
	private function timer(
		string $uuid,
		string $start,
		float $budget,
		WorkingCalendar $calendar,
		?string $slug = null,
		string $state = FlowTimer::STATE_ARMED
	): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid($uuid);
		$timer->setState($state);
		$timer->setCalendarSlug($slug);
		$timer->setOrganisation('gemeente');
		$timer->setBudgetValue($budget);
		$timer->setBudgetUnit(SlaCalculator::UNIT_BUSINESS_DAYS);
		$timer->setConsumedValue(0.0);
		$timer->setRunningSince(new DateTime($start));

		$fireAt = (new SlaCalculator())->add(
			from: new DateTime($start),
			value: $budget,
			unit: SlaCalculator::UNIT_BUSINESS_DAYS,
			calendar: $calendar
		);
		$timer->setFireAt(new DateTime($fireAt->format(DATE_ATOM)));

		return $timer;
	}//end timer()

	/**
	 * 🔴 The spec's first scenario: a new closure day moves the deadlines that
	 * cross it, and leaves the one that ends before it alone.
	 *
	 * @return void
	 */
	public function testANewClosureDayMovesOnlyTheDeadlinesThatCrossIt(): void {
		$spanningOne = $this->timer(uuid: 'a', start: '2027-05-03T09:00:00+02:00', budget: 5.0, calendar: $this->calendar);
		$spanningTwo = $this->timer(uuid: 'b', start: '2027-05-04T09:00:00+02:00', budget: 4.0, calendar: $this->calendar);
		$before = $this->timer(uuid: 'c', start: '2027-04-26T09:00:00+02:00', budget: 2.0, calendar: $this->calendar);

		$moved = [];
		$counts = $this->recompute(calendar: $this->withClosure)->recomputeBatch(
			slug: 'nl-national',
			version: '7',
			timers: [$spanningOne, $spanningTwo, $before],
			supersede: static function (FlowTimer $timer) use (&$moved): void {
				$moved[] = (string)$timer->getUuid();
			}
		);

		$this->assertSame(['a', 'b'], $moved, 'the two timers spanning the new closure day move');
		$this->assertSame(3, $counts['examined']);
		$this->assertSame(2, $counts['moved']);
		$this->assertSame(1, $counts['unchanged'], 'and the one that ends before it is left untouched');
	}//end testANewClosureDayMovesOnlyTheDeadlinesThatCrossIt()

	/**
	 * The control: with the calendar UNCHANGED, nothing moves.
	 *
	 * Without this, the test above could be passing on a recompute that
	 * supersedes everything it examines.
	 *
	 * @return void
	 */
	public function testWithAnUnchangedCalendarNothingMoves(): void {
		$timers = [
			$this->timer(uuid: 'a', start: '2027-05-03T09:00:00+02:00', budget: 5.0, calendar: $this->calendar),
			$this->timer(uuid: 'b', start: '2027-05-04T09:00:00+02:00', budget: 4.0, calendar: $this->calendar),
		];

		$counts = $this->recompute(calendar: $this->calendar)->recomputeBatch(
			slug: 'nl-national',
			version: '7',
			timers: $timers,
			supersede: static function (FlowTimer $timer): void {
			}
		);

		$this->assertSame(0, $counts['moved'], 'the control: a calendar that did not really change moves nothing');
		$this->assertSame(2, $counts['unchanged']);
	}//end testWithAnUnchangedCalendarNothingMoves()

	/**
	 * A timer naming ANOTHER calendar is independent of this change.
	 *
	 * @return void
	 */
	public function testATimerOnAnotherCalendarIsIndependent(): void {
		$timer = $this->timer(
			uuid: 'z',
			start: '2027-05-03T09:00:00+02:00',
			budget: 5.0,
			calendar: $this->calendar,
			slug: 'other'
		);

		$counts = $this->recompute(calendar: $this->withClosure)->recomputeBatch(
			slug: 'nl-national',
			version: '7',
			timers: [$timer],
			supersede: static function (FlowTimer $t): void {
				TestCase::fail('a timer on another calendar must not be superseded');
			}
		);

		$this->assertSame(1, $counts['unchanged']);
		$this->assertSame(0, $counts['moved']);
	}//end testATimerOnAnotherCalendarIsIndependent()

	/**
	 * 🔴 The spec's second scenario: a timer that INHERITS the default calendar
	 * is examined, and moved when its moment changed.
	 *
	 * @return void
	 */
	public function testATimerInheritingTheDefaultCalendarIsIncluded(): void {
		$timer = $this->timer(
			uuid: 'inherited',
			start: '2027-05-03T09:00:00+02:00',
			budget: 5.0,
			calendar: $this->calendar,
			slug: null
		);

		$moved = [];
		$counts = $this->recompute(calendar: $this->withClosure)->recomputeBatch(
			slug: 'nl-national',
			version: '7',
			timers: [$timer],
			supersede: static function (FlowTimer $t) use (&$moved): void {
				$moved[] = (string)$t->getUuid();
			}
		);

		$this->assertSame(['inherited'], $moved, 'a timer naming no calendar still depends on the one it resolves to');
		$this->assertSame(1, $counts['moved']);
	}//end testATimerInheritingTheDefaultCalendarIsIncluded()

	/**
	 * 🔴 The same calendar version is not recomputed twice.
	 *
	 * @return void
	 */
	public function testTheSameVersionIsNotRecomputedTwice(): void {
		$service = $this->recompute(calendar: $this->withClosure);
		$timer = $this->timer(uuid: 'a', start: '2027-05-03T09:00:00+02:00', budget: 5.0, calendar: $this->calendar);

		$service->markRan(slug: 'nl-national', version: '7');

		$counts = $service->recomputeBatch(
			slug: 'nl-national',
			version: '7',
			timers: [$timer],
			supersede: static function (FlowTimer $t): void {
				TestCase::fail('a duplicate event must examine nothing');
			}
		);

		$this->assertTrue($counts['skipped']);
		$this->assertSame(0, $counts['examined'], 'no timer is examined and the job logs the skip');
	}//end testTheSameVersionIsNotRecomputedTwice()

	/**
	 * 🔴 But a LATER version does run: the key is (slug, version), not the slug.
	 *
	 * Keying on the slug alone would make the second edit of the day a no-op,
	 * and that failure surfaces months later as a deadline that never moved.
	 *
	 * @return void
	 */
	public function testALaterVersionOfTheSameCalendarStillRuns(): void {
		$service = $this->recompute(calendar: $this->withClosure);
		$service->markRan(slug: 'nl-national', version: '7');

		$timer = $this->timer(uuid: 'a', start: '2027-05-03T09:00:00+02:00', budget: 5.0, calendar: $this->calendar);

		$counts = $service->recomputeBatch(
			slug: 'nl-national',
			version: '8',
			timers: [$timer],
			supersede: static function (FlowTimer $t): void {
			}
		);

		$this->assertFalse($counts['skipped'], 'a second EDIT is not a duplicate EVENT');
		$this->assertSame(1, $counts['examined']);
	}//end testALaterVersionOfTheSameCalendarStillRuns()

	/**
	 * 🔴 A suspended timer is deferred, not superseded, and is counted apart
	 * from the unchanged ones.
	 *
	 * @return void
	 */
	public function testASuspendedTimerIsDeferredRatherThanSuperseded(): void {
		$timer = $this->timer(
			uuid: 'paused',
			start: '2027-05-03T09:00:00+02:00',
			budget: 5.0,
			calendar: $this->calendar,
			slug: null,
			state: FlowTimer::STATE_SUSPENDED
		);

		$counts = $this->recompute(calendar: $this->withClosure)->recomputeBatch(
			slug: 'nl-national',
			version: '7',
			timers: [$timer],
			supersede: static function (FlowTimer $t): void {
				TestCase::fail('a suspended timer has no stored fire moment to move');
			}
		);

		$this->assertSame(1, $counts['deferred'], '"will be correct at resume" is a different fact from "is correct now"');
		$this->assertSame(0, $counts['unchanged']);
		$this->assertSame(0, $counts['moved']);
	}//end testASuspendedTimerIsDeferredRatherThanSuperseded()

	/**
	 * 🔴 A timer whose calendar cannot be resolved is COUNTED, never silently
	 * treated as independent.
	 *
	 * Those are the timers most likely to be on a stale deadline, so reading
	 * "cannot resolve" as "does not depend" would skip exactly the wrong ones.
	 *
	 * @return void
	 */
	public function testAnUnresolvableCalendarIsCountedNotSkipped(): void {
		$timer = $this->timer(
			uuid: 'orphan',
			start: '2027-05-03T09:00:00+02:00',
			budget: 5.0,
			calendar: $this->calendar,
			slug: null
		);

		$counts = $this->recompute(calendar: $this->withClosure, throws: true)->recomputeBatch(
			slug: 'nl-national',
			version: '7',
			timers: [$timer],
			supersede: static function (FlowTimer $t): void {
			}
		);

		$this->assertSame(1, $counts['unresolvable']);
		$this->assertSame(0, $counts['unchanged'], 'an unjudgeable timer must not be reported as fine');
	}//end testAnUnresolvableCalendarIsCountedNotSkipped()

	/**
	 * One timer that cannot be superseded does not abandon the rest of the
	 * batch.
	 *
	 * @return void
	 */
	public function testOneFailingTimerDoesNotAbandonTheBatch(): void {
		$first = $this->timer(uuid: 'a', start: '2027-05-03T09:00:00+02:00', budget: 5.0, calendar: $this->calendar);
		$second = $this->timer(uuid: 'b', start: '2027-05-04T09:00:00+02:00', budget: 4.0, calendar: $this->calendar);

		$seen = [];
		$counts = $this->recompute(calendar: $this->withClosure)->recomputeBatch(
			slug: 'nl-national',
			version: '7',
			timers: [$first, $second],
			supersede: static function (FlowTimer $t) use (&$seen): void {
				$seen[] = (string)$t->getUuid();
				if ((string)$t->getUuid() === 'a') {
					throw new \RuntimeException('row is locked');
				}
			}
		);

		$this->assertSame(['a', 'b'], $seen, 'the batch is thousands of other people\'s deadlines');
		$this->assertSame(1, $counts['moved']);
		$this->assertSame(1, $counts['unresolvable']);
	}//end testOneFailingTimerDoesNotAbandonTheBatch()

	/**
	 * The candidate narrowing: only an unnamed slug or the changed one needs
	 * the resolver asked.
	 *
	 * @return void
	 */
	public function testTheCandidateNarrowingIsWhatAnIndexCanDo(): void {
		$dependency = new CalendarDependency(calendars: $this->calendars(calendar: $this->calendar));

		$this->assertTrue($dependency->isCandidate(timerCalendarSlug: null, changedSlug: 'nl-national'));
		$this->assertTrue($dependency->isCandidate(timerCalendarSlug: 'nl-national', changedSlug: 'nl-national'));
		$this->assertFalse(
			$dependency->isCandidate(timerCalendarSlug: 'other', changedSlug: 'nl-national'),
			'a named slug short-circuits the resolution order, so another name cannot resolve to this one'
		);
	}//end testTheCandidateNarrowingIsWhatAnIndexCanDo()

	/**
	 * The projection is the engine's own formula: budget minus consumed, from
	 * `runningSince`.
	 *
	 * @return void
	 */
	public function testTheProjectionIsTheEnginesOwnFormula(): void {
		$timer = $this->timer(uuid: 'a', start: '2027-05-03T09:00:00+02:00', budget: 5.0, calendar: $this->calendar);
		$timer->setConsumedValue(2.0);

		$expected = (new SlaCalculator())->add(
			from: new DateTime('2027-05-03T09:00:00+02:00'),
			value: 3.0,
			unit: SlaCalculator::UNIT_BUSINESS_DAYS,
			calendar: $this->withClosure
		)->getTimestamp();

		$this->assertSame(
			$expected,
			$this->recompute(calendar: $this->withClosure)->projectedFireAt(timer: $timer, slug: 'nl-national'),
			'a projection that disagrees with the one that stores the result moves the wrong timers'
		);
	}//end testTheProjectionIsTheEnginesOwnFormula()
}//end class
