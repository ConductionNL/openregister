<?php

/**
 * Characterisation tests for the calendar provider's branching units.
 *
 * Pins the outcomes of the branches a complexity refactor touches in
 * AppointmentAttendeeService, DeadlineDateResolver, ObjectDateDeclaration,
 * ObjectDateEventBuilder, ObjectCalendarFeedService and IcalendarWriter, so a
 * restructuring that changes any of them fails here. No assertion depends on
 * today's date: every moment is a fixed input.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calendar;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Service\Calendar\AppointmentAttendeeService;
use OCA\OpenRegister\Service\Calendar\DeadlineDateResolver;
use OCA\OpenRegister\Service\Calendar\DeadlineLookup;
use OCA\OpenRegister\Service\Calendar\CalendarYearSpan;
use OCA\OpenRegister\Service\Calendar\IcalendarWriter;
use OCA\OpenRegister\Service\Calendar\CalendarContents;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedRendering;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedSources;
use OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration;
use OCA\OpenRegister\Service\Calendar\ObjectDateEventBuilder;
use OCA\OpenRegister\Service\Calendar\ObjectDateOccurrence;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IDateTimeZone;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class CalendarCharacterisationTest extends TestCase {

	private function attendeeObject(array $data = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$object->setRegister('7');
		$object->setSchema('9');
		$object->setObject(array_merge(['title' => 'Hoorzitting'], $data));

		return $object;
	}

	public function testAnAnswerIsTrimmedUppercasedAndSavedOnTheObjectsOwnCoordinates(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($this->attendeeObject());
		$objects->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(
					static function (array $data): bool {
						return $data['title'] === 'Hoorzitting'
							&& count($data[AppointmentAttendeeService::RESPONSES_PROPERTY]) === 1;
					}
				),
				[],
				'7',
				'9',
				'object-uuid'
			)
			->willReturn($this->attendeeObject());

		$service = new AppointmentAttendeeService(objects: $objects);
		$responses = $service->recordResponse(
			objectUuid: 'object-uuid',
			attendee: '  ana@example.org ',
			status: ' tentative ',
			respondedBy: null
		);

		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $responses[0]['respondedAt']);
		unset($responses[0]['respondedAt']);
		$this->assertSame(
			[
				[
					'attendee' => 'ana@example.org',
					'status' => 'TENTATIVE',
					'respondedBy' => null,
				],
			],
			$responses
		);
	}

	public function testAnAnswerWithoutAMomentIsStampedWithAnIsoMoment(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($this->attendeeObject());
		$objects->method('saveObject')->willReturn($this->attendeeObject());

		$service = new AppointmentAttendeeService(objects: $objects);
		$responses = $service->recordResponse(objectUuid: 'object-uuid', attendee: 'ana', status: 'ACCEPTED');

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
			$responses[0]['respondedAt']
		);
	}

	public function testTheRefusalsKeepTheirMessages(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn(null);
		$objects->expects($this->never())->method('saveObject');
		$service = new AppointmentAttendeeService(objects: $objects);

		$messages = [];
		foreach ([['', 'ACCEPTED'], ['ana', 'maybe'], ['ana', 'ACCEPTED']] as [$attendee, $status]) {
			try {
				$service->recordResponse(objectUuid: 'missing', attendee: $attendee, status: $status);
			} catch (InvalidArgumentException $refused) {
				$messages[] = $refused->getMessage();
			}
		}

		$this->assertSame(
			[
				'An attendee response must name the attendee.',
				"'MAYBE' is not an attendee answer: use ACCEPTED, DECLINED, TENTATIVE, NEEDS-ACTION.",
				"No object 'missing' to record an attendee response on.",
			],
			$messages
		);
	}

	public function testStoredResponsesThatAreNotAListOrNameNobodyAreDropped(): void {
		$service = new AppointmentAttendeeService(objects: $this->createMock(ObjectService::class));

		$this->assertSame(
			[],
			$service->responsesOn(
				object: $this->attendeeObject([AppointmentAttendeeService::RESPONSES_PROPERTY => 'ACCEPTED'])
			)
		);
		$this->assertSame(
			[['attendee' => 'bram', 'status' => 'DECLINED']],
			$service->responsesOn(
				object: $this->attendeeObject(
					[
						AppointmentAttendeeService::RESPONSES_PROPERTY => [
							['attendee' => '   ', 'status' => 'ACCEPTED'],
							['attendee' => 42, 'status' => 'ACCEPTED'],
							['attendee' => 'bram', 'status' => 'DECLINED'],
						],
					]
				)
			)
		);
	}

	private function weekdays(array $weekdays = [1, 2, 3, 4, 5], array $exceptions = []): WorkingCalendar {
		return WorkingCalendar::fromArray(
			[
				'slug' => 'test-weekdays',
				'hoursPerWorkingDay' => 8,
				'workingWeekdays' => $weekdays,
				'rules' => [['kind' => 'fixed', 'month' => 1, 'day' => 1, 'name' => 'Nieuwjaarsdag']],
				'exceptions' => $exceptions,
			]
		);
	}

	/**
	 * A Monday-only calendar whose every Monday for 420 days is closed.
	 *
	 * @return WorkingCalendar The calendar.
	 */
	private function alwaysClosed(): WorkingCalendar {
		$closed = [];
		$monday = new DateTimeImmutable('2026-10-19');
		for ($week = 0; $week < 60; $week++) {
			$closed[] = $monday->modify('+' . (7 * $week) . ' days')->format('Y-m-d');
		}

		return $this->weekdays([1], $closed);
	}

	public function testATimerReadFailureLogsAndFallsBackToTheRawDate(): void {
		$timers = $this->createMock(FlowTimerMapper::class);
		$timers->method('findBySubject')->willThrowException(new RuntimeException('db down'));
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->method('resolve')->willReturn($this->weekdays());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('[DeadlineDateResolver] Could not read timers for object object-uuid: db down');

		$resolver = new DeadlineDateResolver(timers: $timers, calendars: $calendars, logger: $logger);
		$resolved = $resolver->resolve(new DeadlineLookup(
			objectUuid: 'object-uuid',
			declaration: ObjectDateDeclaration::fromArray(
				property: 'termijn',
				config: ['kind' => 'deadline', 'timerPurpose' => 'due']
			),
			rawDate: new DateTimeImmutable('2026-10-17T00:00:00+02:00'),
			organisation: null
		));

		$this->assertNotNull($resolved);
		$this->assertSame('2026-10-19', $resolved->format('Y-m-d'));
	}

	public function testATimerWithoutAFireMomentIsPassedOverForTheNextOne(): void {
		$unfired = new FlowTimer();
		$unfired->setPurpose(FlowTimer::PURPOSE_DUE);
		$fired = new FlowTimer();
		$fired->setPurpose(FlowTimer::PURPOSE_DUE);
		$fired->setFireAt(new DateTime('2026-11-02T09:00:00+01:00'));
		$later = new FlowTimer();
		$later->setPurpose(FlowTimer::PURPOSE_DUE);
		$later->setFireAt(new DateTime('2026-12-02T09:00:00+01:00'));

		$timers = $this->createMock(FlowTimerMapper::class);
		$timers->method('findBySubject')->willReturn([$unfired, $fired, $later]);
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->expects($this->never())->method('resolve');

		$resolver = new DeadlineDateResolver(
			timers: $timers,
			calendars: $calendars,
			logger: $this->createMock(LoggerInterface::class)
		);
		$resolved = $resolver->resolve(new DeadlineLookup(
			objectUuid: 'object-uuid',
			declaration: ObjectDateDeclaration::fromArray(
				property: 'termijn',
				config: ['kind' => 'deadline', 'timerPurpose' => 'due']
			),
			rawDate: null,
			organisation: null
		));

		$this->assertNotNull($resolved);
		$this->assertSame('2026-11-02T09:00:00+01:00', $resolved->format('c'));
	}

	public function testABoundTimerThatIsAbsentAndNoRawDatePublishesNothing(): void {
		$timers = $this->createMock(FlowTimerMapper::class);
		$timers->expects($this->once())->method('findBySubject')->willReturn([]);
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->expects($this->never())->method('resolve');

		$resolver = new DeadlineDateResolver(
			timers: $timers,
			calendars: $calendars,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertNull(
			$resolver->resolve(new DeadlineLookup(
				objectUuid: 'object-uuid',
				declaration: ObjectDateDeclaration::fromArray(
					property: 'termijn',
					config: ['kind' => 'deadline', 'timerPurpose' => 'expiry']
				),
				rawDate: null,
				organisation: null
			))
		);
	}

	public function testACalendarFailureIsLoggedNamingTheProperty(): void {
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->expects($this->once())
			->method('resolve')
			->with('typo', 'org-uuid')
			->willThrowException(new RuntimeException('no such calendar'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('[DeadlineDateResolver] No working calendar for termijn: no such calendar');

		$resolver = new DeadlineDateResolver(
			timers: $this->createMock(FlowTimerMapper::class),
			calendars: $calendars,
			logger: $logger
		);

		$this->assertNull(
			$resolver->resolve(new DeadlineLookup(
				objectUuid: 'object-uuid',
				declaration: ObjectDateDeclaration::fromArray(
					property: 'termijn',
					config: ['kind' => 'deadline', 'calendar' => 'typo']
				),
				rawDate: new DateTimeImmutable('2026-10-17T00:00:00+02:00'),
				organisation: 'org-uuid'
			))
		);
	}

	public function testACalendarWithNoWorkingDayInReachPublishesNothing(): void {
		$calendars = $this->createMock(WorkingCalendarService::class);
		$calendars->method('resolve')->willReturn($this->alwaysClosed());
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'[DeadlineDateResolver] Calendar test-weekdays has no working day within 400 days of 2026-10-17;'
				. ' publishing nothing.'
			);

		$resolver = new DeadlineDateResolver(
			timers: $this->createMock(FlowTimerMapper::class),
			calendars: $calendars,
			logger: $logger
		);

		$this->assertNull(
			$resolver->resolve(new DeadlineLookup(
				objectUuid: 'object-uuid',
				declaration: ObjectDateDeclaration::fromArray(property: 'termijn', config: ['kind' => 'deadline']),
				rawDate: new DateTimeImmutable('2026-10-17T00:00:00+02:00'),
				organisation: null
			))
		);
	}

	public function testEveryFieldOfAnAppointmentIsRead(): void {
		$declaration = ObjectDateDeclaration::fromArray(
			property: 'hoorzitting',
			config: [
				'kind' => 'appointment',
				'summaryTemplate' => ' Hoorzitting {{zaak}} ',
				'endProperty' => 'einde',
				'durationMinutes' => 45,
				'calendar' => ' nl ',
				'attendeesProperty' => 'genodigden',
			]
		);

		$this->assertSame(
			[
				'hoorzitting',
				'appointment',
				'Hoorzitting {{zaak}}',
				null,
				'einde',
				45,
				null,
				'nl',
				'genodigden',
			],
			[
				$declaration->property,
				$declaration->kind,
				$declaration->summaryTemplate,
				$declaration->alarmOffsetDays,
				$declaration->endProperty,
				$declaration->durationMinutes,
				$declaration->timerPurpose,
				$declaration->calendarSlug,
				$declaration->attendeesProperty,
			]
		);
	}

	public function testAnEmptyDeclarationLeavesEveryOptionalFieldNull(): void {
		$declaration = ObjectDateDeclaration::fromArray(
			property: 'termijn',
			config: ['kind' => 'deadline', 'summaryTemplate' => '  ', 'calendar' => 7, 'alarmOffsetDays' => 0]
		);

		$this->assertSame(
			[null, 0, null, null, null, null, null],
			[
				$declaration->summaryTemplate,
				$declaration->alarmOffsetDays,
				$declaration->endProperty,
				$declaration->durationMinutes,
				$declaration->timerPurpose,
				$declaration->calendarSlug,
				$declaration->attendeesProperty,
			]
		);
	}

	public function testTheDeclarationRefusalsKeepTheirMessages(): void {
		$cases = [
			['kind' => 'deadline', 'alarmOffsetDays' => '3'],
			['kind' => 'deadline', 'alarmOffsetDays' => 2.5],
			['kind' => 'period', 'endProperty' => 'eind', 'alarmOffsetDays' => 1],
			['kind' => 'appointment', 'timerPurpose' => 'due'],
			['kind' => 'deadline', 'timerPurpose' => 'start'],
			['kind' => 'appointment', 'durationMinutes' => 0],
			['kind' => 'period'],
			['kind' => 42],
		];

		$messages = [];
		foreach ($cases as $config) {
			try {
				ObjectDateDeclaration::fromArray(property: 'p', config: $config);
				$messages[] = 'accepted';
			} catch (InvalidArgumentException $refused) {
				$messages[] = $refused->getMessage();
			}
		}

		$this->assertSame(
			[
				'calendarProvider.dates.p declares alarmOffsetDays that is not a whole number of days at or above zero.',
				'calendarProvider.dates.p declares alarmOffsetDays that is not a whole number of days at or above zero.',
				'calendarProvider.dates.p declares alarmOffsetDays on a period; only a deadline carries an alarm.',
				'calendarProvider.dates.p declares timerPurpose on a appointment; only a deadline is computed by the term engine.',
				"calendarProvider.dates.p declares timerPurpose 'start': use due or expiry.",
				'calendarProvider.dates.p declares durationMinutes that is not a whole number of minutes above zero.',
				'calendarProvider.dates.p is a period and must declare endProperty, the property carrying its end.',
				"calendarProvider.dates.p declares kind 'int', which is not a date kind: use deadline, appointment, period.",
			],
			$messages
		);
	}

	private function builder(?AppointmentAttendeeService $attendees = null): ObjectDateEventBuilder {
		if ($attendees === null) {
			$attendees = $this->createMock(AppointmentAttendeeService::class);
			$attendees->method('responsesByAttendee')->willReturn([]);
		}

		return new ObjectDateEventBuilder(
			deadlines: $this->createMock(DeadlineDateResolver::class),
			attendees: $attendees
		);
	}

	private function dated(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$object->setRegister('7');
		$object->setSchema('9');
		$object->setUpdated(new DateTime('2026-09-14T10:00:00+02:00'));
		$object->setObject($data);

		return $object;
	}

	private function timing(array $lines): array {
		return array_values(
			array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'DT') && str_starts_with($line, 'DTSTAMP') === false)
		);
	}

	public function testAnEndBeforeTheStartIsIgnored(): void {
		$amsterdam = new DateTimeZone('Europe/Amsterdam');

		$period = $this->builder()->build(new ObjectDateOccurrence(
			object: $this->dated(['van' => '2026-10-10', 'tot' => '2026-10-01']),
			schemaId: 9,
			declaration: ObjectDateDeclaration::fromArray(property: 'van', config: ['kind' => 'period', 'endProperty' => 'tot']),
			timeZone: $amsterdam
		));
		$appointment = $this->builder()->build(new ObjectDateOccurrence(
			object: $this->dated(['op' => '2026-10-10T14:00:00+02:00', 'tot' => 'not a date']),
			schemaId: 9,
			declaration: ObjectDateDeclaration::fromArray(
				property: 'op',
				config: ['kind' => 'appointment', 'endProperty' => 'tot', 'durationMinutes' => 30]
			),
			timeZone: $amsterdam
		));
		$appointmentWithEnd = $this->builder()->build(new ObjectDateOccurrence(
			object: $this->dated(['op' => '2026-10-10T14:00:00+02:00', 'tot' => '2026-10-10T12:15:00Z']),
			schemaId: 9,
			declaration: ObjectDateDeclaration::fromArray(
				property: 'op',
				config: ['kind' => 'appointment', 'endProperty' => 'tot']
			),
			timeZone: $amsterdam
		));

		$this->assertSame(['DTSTART;VALUE=DATE:20261010', 'DTEND;VALUE=DATE:20261011'], $this->timing($period['lines']));
		$this->assertSame(
			['DTSTART;TZID=Europe/Amsterdam:20261010T140000', 'DTEND;TZID=Europe/Amsterdam:20261010T143000'],
			$this->timing($appointment['lines'])
		);
		$this->assertSame(
			['DTSTART;TZID=Europe/Amsterdam:20261010T140000', 'DTEND;TZID=Europe/Amsterdam:20261010T141500'],
			$this->timing($appointmentWithEnd['lines'])
		);
		$this->assertSame([2026, 2026], $period['years']);
	}

	public function testAPeriodWithATimedEndIsNotAllDay(): void {
		$event = $this->builder()->build(new ObjectDateOccurrence(
			object: $this->dated(['van' => '2026-10-01', 'tot' => '2026-10-02T12:00:00+02:00']),
			schemaId: 9,
			declaration: ObjectDateDeclaration::fromArray(property: 'van', config: ['kind' => 'period', 'endProperty' => 'tot']),
			timeZone: new DateTimeZone('Europe/Amsterdam')
		));

		$this->assertSame(
			['DTSTART;TZID=Europe/Amsterdam:20261001T000000', 'DTEND;TZID=Europe/Amsterdam:20261002T120000'],
			$this->timing($event['lines'])
		);
	}

	public function testInviteesOfEveryShapeBecomeAttendeeLines(): void {
		$attendees = $this->createMock(AppointmentAttendeeService::class);
		$attendees->method('responsesByAttendee')->willReturn(['bram' => ['status' => 'TENTATIVE']]);

		$event = $this->builder($attendees)->build(new ObjectDateOccurrence(
			object: $this->dated(
				[
					'op' => '2026-10-10T14:00:00+02:00',
					'wie' => [
						' ana@example.org ',
						'   ',
						42,
						['email' => '', 'uid' => 'bram'],
						['name' => 'Chris de Vries'],
						['email' => 'dirk@example.org', 'uid' => 'dirk'],
						['role' => 'chair'],
					],
				]
			),
			schemaId: 9,
			declaration: ObjectDateDeclaration::fromArray(
				property: 'op',
				config: ['kind' => 'appointment', 'attendeesProperty' => 'wie']
			),
			timeZone: new DateTimeZone('Europe/Amsterdam')
		));

		$this->assertSame(
			[
				'ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:ana@example.org',
				'ATTENDEE;PARTSTAT=TENTATIVE:urn:openregister:attendee:bram',
				'ATTENDEE;PARTSTAT=NEEDS-ACTION:urn:openregister:attendee:Chris%20de%20Vries',
				'ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:dirk@example.org',
			],
			array_values(array_filter($event['lines'], static fn (string $line): bool => str_starts_with($line, 'ATTENDEE')))
		);
	}

	public function testAttendeesAreOnlyReadForAnAppointmentWithAListOfThem(): void {
		$attendees = $this->createMock(AppointmentAttendeeService::class);
		$attendees->expects($this->never())->method('responsesByAttendee');
		$builder = $this->builder($attendees);
		$zone = new DateTimeZone('Europe/Amsterdam');

		$period = $builder->build(new ObjectDateOccurrence(
			object: $this->dated(['van' => '2026-10-01', 'tot' => '2026-10-02', 'wie' => ['ana']]),
			schemaId: 9,
			declaration: ObjectDateDeclaration::fromArray(
				property: 'van',
				config: ['kind' => 'period', 'endProperty' => 'tot', 'attendeesProperty' => 'wie']
			),
			timeZone: $zone
		));
		$notAList = $builder->build(new ObjectDateOccurrence(
			object: $this->dated(['op' => '2026-10-10T14:00:00+02:00', 'wie' => 'ana']),
			schemaId: 9,
			declaration: ObjectDateDeclaration::fromArray(
				property: 'op',
				config: ['kind' => 'appointment', 'attendeesProperty' => 'wie']
			),
			timeZone: $zone
		));

		$this->assertStringNotContainsString('ATTENDEE', implode("\n", $period['lines']));
		$this->assertStringNotContainsString('ATTENDEE', implode("\n", $notAList['lines']));
	}

	public function testOnlyTheEventsThatBuildReachTheFeedInObjectAndDeclarationOrder(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('runAs')->willReturnCallback(
			static fn (IUser $user, callable $operation) => $operation()
		);

		$first = $this->dated(['a' => '2026-10-01', 'b' => '2026-10-02']);
		$second = $this->dated(['a' => '2031-10-01', 'b' => '2031-10-02']);
		$second->setUuid('second-uuid');
		$objects->method('searchObjects')->willReturn([$first, $second]);

		$schema = new Schema();
		$schema->setId(9);
		$schema->setTitle('Bezwaren');
		$schema->setConfiguration(
			[
				'calendarProvider' => [
					'enabled' => true,
					'dtstart' => 'a',
					'titleTemplate' => '{{title}}',
					'dates' => ['a' => ['kind' => 'deadline'], 'b' => ['kind' => 'deadline']],
				],
			]
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('caseworker');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);

		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

		$builder = $this->createMock(ObjectDateEventBuilder::class);
		$builder->expects($this->exactly(4))
			->method('build')
			->willReturnCallback(
				static function (ObjectDateOccurrence $occurrence): ?array {
					$object = $occurrence->object;
					$schemaId = $occurrence->schemaId;
					$declaration = $occurrence->declaration;
					if ($declaration->property === 'a' && $object->getUuid() === 'object-uuid') {
						return null;
					}

					$tag = $object->getUuid() . '-' . $declaration->property . '-' . $schemaId;

					return ['lines' => ['BEGIN:VEVENT', 'UID:' . $tag, 'END:VEVENT'], 'years' => [2031, 2032]];
				}
			);

		$service = new ObjectCalendarFeedService(
			new ObjectCalendarFeedSources($objects, $schemas, $this->createMock(ViewMapper::class), $users),
			new ObjectCalendarFeedRendering($zone, $builder, new IcalendarWriter()),
			$this->createMock(LoggerInterface::class)
		);

		$token = new CalendarFeedToken();
		$token->setToken('feed-token');
		$token->setUserId('caseworker');
		$token->setScopeType(CalendarFeedToken::SCOPE_SCHEMA);
		$token->setScopeId('9');

		$body = (string)$service->render(token: $token);

		preg_match_all('/^UID:(.*)\r$/m', $body, $uids);
		$this->assertSame(['object-uuid-b-9', 'second-uuid-a-9', 'second-uuid-b-9'], $uids[1]);
		$this->assertStringContainsString('X-WR-CALNAME:Bezwaren', $body);
		// The VTIMEZONE covers the years the events touch.
		$this->assertStringContainsString('DTSTART:2032', $body);
	}

	public function testACalendarWithoutAZoneCarriesNoTimeZoneParts(): void {
		$body = (new IcalendarWriter())->calendar(new CalendarContents(
			calendarName: 'Zaken; open',
			timeZone: null,
			componentLines: ['BEGIN:VEVENT', 'END:VEVENT'],
			years: new CalendarYearSpan(fromYear: 2026, toYear: 2026)
		));

		$this->assertSame(
			"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:" . IcalendarWriter::PRODID . "\r\nCALSCALE:GREGORIAN\r\n"
			. "METHOD:PUBLISH\r\nX-WR-CALNAME:Zaken\\; open\r\nBEGIN:VEVENT\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			$body
		);
	}

	public function testACalendarWithAZoneCoversTheRequestedYears(): void {
		$body = (new IcalendarWriter())->calendar(new CalendarContents(
			calendarName: 'Zaken',
			timeZone: new DateTimeZone('Europe/Amsterdam'),
			componentLines: [],
			years: new CalendarYearSpan(fromYear: 2027, toYear: 2028)
		));

		$this->assertStringContainsString("X-WR-TIMEZONE:Europe/Amsterdam\r\nBEGIN:VTIMEZONE\r\n", $body);
		$this->assertStringContainsString('DTSTART:2027', $body);
		$this->assertStringContainsString('DTSTART:2028', $body);
		$this->assertStringEndsWith("END:VTIMEZONE\r\nEND:VCALENDAR\r\n", $body);
	}
}
