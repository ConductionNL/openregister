<?php

/**
 * Which calendar a term measures against: record type, then unit, then
 * instance; a person's working pattern laid over it when an app answers; and
 * the diagnostic naming which applied.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\CalendarBlackoutRefusal;
use OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerArithmetic;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerDefinitionStore;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerFactory;
use OCA\OpenRegister\Service\Flow\Timer\RecordTypeCalendars;
use OCA\OpenRegister\Service\Flow\Timer\RegisterWorkingPatternProvidersEvent;
use OCA\OpenRegister\Service\Flow\Timer\ResolvedWorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\SlaAddition;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic;
use OCA\OpenRegister\Service\Flow\Timer\TermQuery;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarScope;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\Flow\Timer\WorkingPatternProviderInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingPatternProviders;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService
 * @covers \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarScope
 * @covers \OCA\OpenRegister\Service\Flow\Timer\ResolvedWorkingCalendar
 * @covers \OCA\OpenRegister\Service\Flow\Timer\TermDiagnostic
 * @covers \OCA\OpenRegister\Service\Flow\Timer\TermQuery
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerFactory
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingPatternProviders
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\RegisterWorkingPatternProvidersEvent
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\CalendarBlackoutRefusal
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\CalendarBlackouts
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\CalendarWeekNumbering
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\EscalationLadderService
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\EscalationRule
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\FlowTimerArithmetic
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\FlowTimerValues
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\MomentShift
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\RollRule
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHours
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursClock
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\ServiceHoursDeclaration
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\SlaAddition
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\SlaCalculator
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\SlaDeclaration
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\UnitConversion
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WalkCollector
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinition
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarHolidays
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarRules
 * @uses   \OCA\OpenRegister\Service\Flow\Timer\WorkingDayRoll
 * @uses   \OCA\OpenRegister\Db\FlowTimer
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 */
class WorkingCalendarScopeResolutionTest extends TestCase {

	private const STEMBUS = ['name' => 'Stembusperiode', 'from' => '2026-03-09', 'to' => '2026-03-20', 'refuses' => ['hearing']];

	private FlowTimerDefinitionStore&MockObject $definitions;

	private RecordTypeCalendars&MockObject $recordTypes;

	private LoggerInterface&MockObject $logger;

	/**
	 * Providers the dispatcher registers on the first question.
	 *
	 * @var array<int, WorkingPatternProviderInterface>
	 */
	private array $providers = [];

	protected function setUp(): void {
		parent::setUp();
		$this->definitions = $this->createMock(FlowTimerDefinitionStore::class);
		$this->definitions->method('calendars')->willReturn(
			[
				'nl-national' => self::definition(slug: 'nl-national', weekdays: [1, 2, 3, 4, 5]),
				// Counter hours: the desk is shut on Fridays.
				'counter-hours' => self::definition(slug: 'counter-hours', weekdays: [1, 2, 3, 4]),
				'gemeente-a' => self::definition(slug: 'gemeente-a', weekdays: [1, 2, 3, 4, 5]) + ['organisation' => 'org-a'],
				'elections' => self::definition(slug: 'elections', weekdays: [1, 2, 3, 4, 5]) + ['blackouts' => [self::STEMBUS]],
			]
		);
		$this->definitions->method('ladders')->willReturn([]);
		$this->recordTypes = $this->createMock(RecordTypeCalendars::class);
		$this->recordTypes->method('calendarOf')->willReturnMap([['burgerzaken', 'counter-hours'], ['vergunningen', null]]);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * @param array<int, int> $weekdays
	 *
	 * @return array<string, mixed>
	 */
	private static function definition(string $slug, array $weekdays): array {
		return ['slug' => $slug, 'workingWeekdays' => $weekdays, 'hoursPerWorkingDay' => 8, 'timezone' => 'Europe/Amsterdam'];
	}//end definition()

	private function patterns(): WorkingPatternProviders {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (RegisterWorkingPatternProvidersEvent $event): void {
				foreach ($this->providers as $provider) {
					$event->register(provider: $provider);
				}
			}
		);

		return new WorkingPatternProviders(dispatcher: $dispatcher, logger: $this->logger);
	}//end patterns()

	private function service(bool $withPatterns = true): WorkingCalendarService {
		return new WorkingCalendarService(
			definitions: $this->definitions,
			patterns: ($withPatterns === true ? $this->patterns() : null),
			recordTypes: $this->recordTypes
		);
	}//end service()

	/**
	 * A provider that answers for one user only.
	 *
	 * @param array<string, mixed>|null $pattern
	 */
	private static function provider(string $appId, string $userId, ?array $pattern): WorkingPatternProviderInterface {
		return new class($appId, $userId, $pattern) implements WorkingPatternProviderInterface {
			/**
			 * @param array<string, mixed>|null $pattern
			 */
			public function __construct(private string $appId, private string $userId, private ?array $pattern) {
			}

			public function appId(): string {
				return $this->appId;
			}

			public function patternFor(string $userId): ?array {
				return ($userId === $this->userId ? $this->pattern : null);
			}
		};
	}//end provider()

	private function factory(WorkingCalendarService $calendars): FlowTimerFactory {
		$calculator = new SlaCalculator();

		return new FlowTimerFactory(
			new FlowTimerArithmetic($calendars, $calculator, new EscalationLadderService(definitions: $this->definitions, calculator: $calculator))
		);
	}//end factory()

	/**
	 * @param array<string, mixed> $extra
	 */
	private function timer(WorkingCalendarService $calendars, array $extra = []): FlowTimer {
		return $this->factory(calendars: $calendars)->build(
			config: ['subjectType' => 'object', 'subjectUuid' => 'obj-1', 'sla' => ['value' => 5, 'unit' => 'businessDays']] + $extra,
			act: new FlowTimerAct(actor: 'alice', now: $this->at('2026-03-02 09:00'))
		);
	}//end timer()

	private function at(string $moment): DateTimeImmutable {
		return new DateTimeImmutable($moment, new DateTimeZone('Europe/Amsterdam'));
	}//end at()

	/**
	 * Five business days from Monday 2 March 2026, 09:00.
	 */
	private function fiveDaysOn(WorkingCalendar $calendar): string {
		return (new SlaCalculator())->add(
			addition: new SlaAddition(start: $this->at('2026-03-02 09:00'), value: 5.0, unit: SlaCalculatorInterface::UNIT_BUSINESS_DAYS, calendar: $calendar)
		)->format('Y-m-d');
	}//end fiveDaysOn()

	public function testTwoSchemasOnTwoCalendars(): void {
		$service     = $this->service();
		$burgerzaken = $this->timer(calendars: $service, extra: ['schema' => 'burgerzaken']);
		$vergunning  = $this->timer(calendars: $service, extra: ['schema' => 'vergunningen']);

		$this->assertSame('counter-hours', $burgerzaken->getCalendarSlug());
		$this->assertSame(['scope' => 'recordType', 'recordType' => 'burgerzaken'], $burgerzaken->getMetadata()[WorkingCalendarScope::METADATA_KEY]);
		$this->assertNull($vergunning->getCalendarSlug(), 'a schema naming no calendar names none');
		$this->assertNull($vergunning->getMetadata(), 'and leaves the timer as it was');

		$counter  = $service->resolveForTimer(timer: $burgerzaken);
		$instance = $service->resolveForTimer(timer: $vergunning);
		$this->assertSame(['counter-hours', 'recordType'], [$counter->calendar->getSlug(), $counter->scope]);
		$this->assertSame(['nl-national', 'instance'], [$instance->calendar->getSlug(), $instance->scope]);
		// The desk's Friday is not a working day, so the same term ends later.
		$this->assertSame('2026-03-10', $this->fiveDaysOn(calendar: $counter->calendar));
		$this->assertSame('2026-03-09', $this->fiveDaysOn(calendar: $instance->calendar));
	}

	public function testATimerThatNamesItsOwnCalendarIsNotOverriddenByItsRecordType(): void {
		$timer = $this->timer(calendars: $this->service(), extra: ['schema' => 'burgerzaken', 'calendar' => 'gemeente-a']);

		$this->assertSame('gemeente-a', $timer->getCalendarSlug());
		$this->assertNull($timer->getMetadata());
		$this->assertSame('timer', $this->service()->resolveForTimer(timer: $timer)->scope);
	}

	public function testNothingDeclaredResolvesAsBefore(): void {
		$service = $this->service();
		$timer   = $this->timer(calendars: $service);

		$this->assertNull($timer->getCalendarSlug());
		$this->assertNull($timer->getMetadata());
		$resolved = $service->resolveScoped(scope: new WorkingCalendarScope());
		$this->assertSame($service->resolve(calendarSlug: null, organisation: null), $resolved->calendar, 'the same calendar the plain resolution gives');
		$this->assertSame(
			['calendar' => 'nl-national', 'calendarScope' => 'instance', 'workingPattern' => 'calendar', 'person' => null, 'workingPatternSource' => null],
			$resolved->describe()
		);
	}

	public function testWithoutARecordTypeReaderNoSchemaNamesACalendar(): void {
		$service = new WorkingCalendarService(definitions: $this->definitions);

		$this->assertNull($service->recordTypeCalendar(schema: 'burgerzaken'));
		$this->assertNull($this->timer(calendars: $service, extra: ['schema' => 'burgerzaken'])->getCalendarSlug());
	}

	public function testTheUnitCalendarComesBeforeTheInstanceOne(): void {
		$resolved = $this->service()->resolveScoped(scope: new WorkingCalendarScope(organisation: 'org-a'));

		$this->assertSame(['gemeente-a', 'unit'], [$resolved->calendar->getSlug(), $resolved->scope]);
	}

	public function testARecordTypeCalendarThatDoesNotExistIsRefused(): void {
		$recordTypes = $this->createMock(RecordTypeCalendars::class);
		$recordTypes->method('calendarOf')->willReturn('mars');
		$service = new WorkingCalendarService(definitions: $this->definitions, recordTypes: $recordTypes);
		$timer   = $this->timer(calendars: new WorkingCalendarService(definitions: $this->definitions), extra: ['schema' => 'x']);
		$timer->setCalendarSlug('mars');

		$this->expectException(FlowTimerValidationException::class);
		$this->expectExceptionMessage("Working calendar 'mars' does not exist");
		$service->resolveForTimer(timer: $timer);
	}

	public function testAPartTimeHandlersTermRespectsTheirPattern(): void {
		$this->providers = [
			self::provider(appId: 'other', userId: 'bob', pattern: ['workingWeekdays' => [1]]),
			self::provider(appId: 'humaniq', userId: 'alice', pattern: ['workingWeekdays' => [1, 2, 3], 'absences' => ['2026-03-03']]),
		];
		$service = $this->service();
		$timer   = $this->timer(calendars: $service, extra: ['person' => ' alice ']);

		$this->assertSame(['person' => 'alice'], $timer->getMetadata()[WorkingCalendarScope::METADATA_KEY]);
		$resolved = $service->resolveForTimer(timer: $timer);
		$this->assertSame(
			['calendar' => 'nl-national', 'calendarScope' => 'instance', 'workingPattern' => 'person', 'person' => 'alice', 'workingPatternSource' => 'humaniq'],
			$resolved->describe()
		);
		$this->assertFalse($resolved->calendar->isWorkingDay($this->at('2026-03-05 12:00')), 'Thursday is not hers');
		$this->assertFalse($resolved->calendar->isWorkingDay($this->at('2026-03-03 12:00')), 'nor the day she is away');
		$this->assertTrue($resolved->calendar->isWorkingDay($this->at('2026-03-04 12:00')));
		$this->assertSame($resolved->calendar, WorkingCalendarScope::ofTimer(timer: $timer)->calendarFrom(calendars: $service), 'the engine path applies it too');
		// Counted from Monday 2: Wed 4, Mon 9, Tue 10, Wed 11, Mon 16 (Tue 3 she is away).
		$this->assertSame('2026-03-16', $this->fiveDaysOn(calendar: $resolved->calendar));

		$explained = (new TermDiagnostic(calculator: new SlaCalculator()))->explain(
			query: TermQuery::resolved(resolution: $resolved, anchor: $this->at('2026-03-02 09:00'), sla: ['value' => 5, 'unit' => 'businessDays'])
		);
		$this->assertSame(['nl-national', 'instance', 'person', 'alice', 'humaniq'], [$explained['calendar'], $explained['calendarScope'], $explained['workingPattern'], $explained['person'], $explained['workingPatternSource']]);
	}

	public function testNothingAnswersAndTheCalendarDecides(): void {
		$this->providers = [self::provider(appId: 'humaniq', userId: 'bob', pattern: ['workingWeekdays' => [1]])];
		$service  = $this->service();
		$resolved = $service->resolveScoped(scope: new WorkingCalendarScope(organisation: 'org-a', person: 'alice'));

		$this->assertSame(
			['calendar' => 'gemeente-a', 'calendarScope' => 'unit', 'workingPattern' => 'calendar', 'person' => 'alice', 'workingPatternSource' => null],
			$resolved->describe()
		);
		$this->assertTrue($resolved->calendar->isWorkingDay($this->at('2026-03-05 12:00')));

		$explained = (new TermDiagnostic(calculator: new SlaCalculator()))->explain(
			query: TermQuery::resolved(resolution: $resolved, anchor: $this->at('2026-03-02 09:00'), sla: ['value' => 5, 'unit' => 'businessDays'])
		);
		$this->assertSame('calendar', $explained['workingPattern'], 'the diagnostic says the calendar decided');
		$this->assertSame('2026-03-09T09:00:00+01:00', $explained['firesAt']);
	}

	public function testWithoutAnyProviderRegistryThePersonIsNamedAndTheCalendarDecides(): void {
		$resolved = $this->service(withPatterns: false)->resolveScoped(scope: new WorkingCalendarScope(person: 'alice'));

		$this->assertSame(['calendar', 'alice'], [$resolved->pattern, $resolved->person]);
	}

	public function testAFailingProviderLeavesTheCalendarToDecide(): void {
		$throwing = new class implements WorkingPatternProviderInterface {
			public function appId(): string {
				return 'broken';
			}

			public function patternFor(string $userId): ?array {
				throw new RuntimeException('down');
			}
		};
		$this->providers = [$throwing, self::provider(appId: 'malformed', userId: 'alice', pattern: ['workingWeekdays' => [8]])];
		$this->logger->expects($this->exactly(2))->method('warning');

		$resolved = $this->service()->resolveScoped(scope: new WorkingCalendarScope(person: 'alice'));

		$this->assertSame(ResolvedWorkingCalendar::PATTERN_CALENDAR, $resolved->pattern);
		$this->assertSame([1, 2, 3, 4, 5], $resolved->calendar->getWorkingWeekdays());
	}

	public function testAPatternTheCalendarCannotCarryIsNotApplied(): void {
		// Every weekday off: valid as an answer, but no working calendar may have zero working days.
		$this->providers = [self::provider(appId: 'humaniq', userId: 'alice', pattern: ['workingWeekdays' => []])];

		$resolved = $this->service()->resolveScoped(scope: new WorkingCalendarScope(person: 'alice'));

		$this->assertSame('calendar', $resolved->pattern);
	}

	public function testTheResolutionIsMemoisedPerPassAndForgottenOnReset(): void {
		$calls = 0;
		$this->providers = [
			new class($calls) implements WorkingPatternProviderInterface {
				public function __construct(private int &$calls) {
				}

				public function appId(): string {
					return 'humaniq';
				}

				public function patternFor(string $userId): ?array {
					$this->calls++;
					return ['workingWeekdays' => [1, 2]];
				}
			},
		];
		$service = $this->service();
		$first   = $service->resolveScoped(scope: new WorkingCalendarScope(person: 'alice'));

		$this->assertSame($first, $service->resolveScoped(scope: new WorkingCalendarScope(person: 'alice')));
		$this->assertSame(1, $calls);
		$service->reset();
		$service->resolveScoped(scope: new WorkingCalendarScope(person: 'alice'));
		$this->assertSame(2, $calls);
	}

	public function testABookingInABlackoutIsRefusedWhileTheTermRunsThroughIt(): void {
		$resolved = $this->service()->resolveScoped(scope: new WorkingCalendarScope(calendarSlug: 'elections'));

		// The term counts the blackout's days exactly as the plain five-day calendar does.
		$this->assertSame('2026-03-09', $this->fiveDaysOn(calendar: $resolved->calendar));
		$this->assertTrue($resolved->calendar->isWorkingDay($this->at('2026-03-10 12:00')));

		$this->expectException(CalendarBlackoutRefusal::class);
		$this->expectExceptionMessage('Stembusperiode');
		$resolved->calendar->assertBookable(moment: $this->at('2026-03-10 10:00'), kind: 'hearing');
	}

	public function testADefinitionGivenAsIsReadsAsGiven(): void {
		$explained = (new TermDiagnostic(calculator: new SlaCalculator()))->explain(
			query: new TermQuery(
				calendar: WorkingCalendar::fromArray(definition: self::definition(slug: 'draft', weekdays: [1, 2, 3, 4, 5])),
				anchor: $this->at('2026-03-02 09:00'),
				sla: ['value' => 1, 'unit' => 'businessDays']
			)
		);

		$this->assertSame(['given', 'calendar', null], [$explained['calendarScope'], $explained['workingPattern'], $explained['person']]);
	}

	public function testATimerArmedBeforeScopesExistedReadsAsItsOwnCalendar(): void {
		$timer = new FlowTimer();
		$timer->setCalendarSlug('gemeente-a');
		$timer->setOrganisation('org-b');
		$timer->setMetadata([WorkingCalendarScope::METADATA_KEY => 'not a record']);

		$scope = WorkingCalendarScope::ofTimer(timer: $timer);

		$this->assertSame(['gemeente-a', 'timer', 'org-b', null], [$scope->calendarSlug, $scope->namedBy, $scope->organisation, $scope->person]);
	}
}//end class
