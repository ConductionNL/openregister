<?php

/**
 * Unit tests for DeclaredDateCalendars: the platform calendar read through the feed's generator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Calendar;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Calendar\CalendarSearchQuery;
use OCA\OpenRegister\Calendar\DeclaredDateCalendars;
use OCA\OpenRegister\Calendar\ObjectDateCalendar;
use OCA\OpenRegister\Calendar\ObjectDateCalendarScope;
use OCA\OpenRegister\Calendar\VeventSearchShape;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Service\Calendar\AppointmentAttendeeService;
use OCA\OpenRegister\Service\Calendar\DeadlineDateResolver;
use OCA\OpenRegister\Service\Calendar\DeadlineLookup;
use OCA\OpenRegister\Service\Calendar\IcalendarWriter;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedRendering;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedSources;
use OCP\IDateTimeZone;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The join between the subscribable feed and the platform calendar provider.
 *
 * The event generator, the iCalendar writer and the feed are real; only the
 * boundaries are mocked: the object access path, the schema and view stores,
 * the user lookup, the tenant zone and the term engine.
 */
class DeclaredDateCalendarsTest extends TestCase {

	/**
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface $objects;

	/**
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface $schemas;

	/**
	 * @var ViewMapperInterface&MockObject
	 */
	private ViewMapperInterface $views;

	/**
	 * @var IUserManager&MockObject
	 */
	private IUserManager $userManager;

	/**
	 * @var DeadlineDateResolver&MockObject
	 */
	private DeadlineDateResolver $termEngine;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	private ObjectCalendarFeedService $feed;

	private DeclaredDateCalendars $calendars;

	/**
	 * The objects each principal may list, by user id.
	 *
	 * @var array<string, array<int, ObjectEntity>>
	 */
	private array $listable = [];

	/**
	 * The user each search ran as, with its arguments.
	 *
	 * @var array<int, array{user: string|null, query: array, rbac: bool, multitenancy: bool, views: array|null}>
	 */
	private array $searches = [];

	private ?string $currentUser = null;

	protected function setUp(): void {
		parent::setUp();

		$this->objects     = $this->createMock(ObjectOperationsInterface::class);
		$this->schemas     = $this->createMock(SchemaMapperInterface::class);
		$this->views       = $this->createMock(ViewMapperInterface::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->termEngine  = $this->createMock(DeadlineDateResolver::class);
		$this->logger      = $this->createMock(LoggerInterface::class);

		$this->objects->method('runAs')->willReturnCallback(
			function (IUser $user, callable $operation) {
				$previous = $this->currentUser;
				$this->currentUser = $user->getUID();
				try {
					return $operation();
				} finally {
					$this->currentUser = $previous;
				}
			}
		);

		$this->objects->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true, ?array $ids = null, ?string $uses = null, ?array $views = null): array {
				$this->searches[] = [
					'user' => $this->currentUser,
					'query' => $query,
					'rbac' => $_rbac,
					'multitenancy' => $_multitenancy,
					'views' => $views,
				];

				return ($this->listable[(string)$this->currentUser] ?? []);
			}
		);

		$this->userManager->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if (in_array($uid, ['alice', 'bob'], true) === false) {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);

				return $user;
			}
		);

		$this->schemas->method('find')->willReturn($this->deadlineSchema());

		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('Europe/Amsterdam'));

		$attendees = $this->createMock(AppointmentAttendeeService::class);
		$attendees->method('responsesByAttendee')->willReturn([]);

		$sources = new ObjectCalendarFeedSources($this->objects, $this->schemas, $this->views, $this->userManager);
		$this->feed = new ObjectCalendarFeedService(
			$sources,
			new ObjectCalendarFeedRendering(
				$zone,
				new \OCA\OpenRegister\Service\Calendar\ObjectDateEventBuilder($this->termEngine, $attendees),
				new IcalendarWriter()
			),
			$this->createMock(LoggerInterface::class)
		);

		$this->calendars = new DeclaredDateCalendars($this->feed, $sources, new VeventSearchShape(), $this->logger);
	}

	private function deadlineSchema(): Schema {
		$schema = new Schema();
		$schema->setId(9);
		$schema->setTitle('Bezwaren');
		$schema->setConfiguration(
			[
				'calendarProvider' => [
					'enabled' => true,
					'dtstart' => 'beslistermijn',
					'titleTemplate' => '{{title}}',
					'dates' => [
						'beslistermijn' => ['kind' => 'deadline', 'alarmOffsetDays' => 3],
					],
				],
			]
		);

		return $schema;
	}

	private function object(string $uuid, string $title, string $deadline): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('7');
		$object->setSchema('9');
		$object->setOrganisation('org-uuid');
		$object->setUpdated(new DateTime('2026-09-14T10:00:00+02:00'));
		$object->setObject(['title' => $title, 'beslistermijn' => $deadline]);

		return $object;
	}

	/**
	 * The term engine moves every deadline two days later, as a working calendar would over a weekend.
	 */
	private function termEngineMovesDeadlinesTwoDays(): void {
		$this->termEngine->method('resolve')->willReturnCallback(
			static function (DeadlineLookup $lookup): ?DateTimeImmutable {
				return $lookup->rawDate?->modify('+2 days');
			}
		);
	}

	private function schemaCalendar(string $principalUri): ObjectDateCalendar {
		$schema = $this->deadlineSchema();

		return $this->calendars->schemaCalendar(
			schema: $schema,
			config: (array)$schema->getCalendarProviderConfig(),
			principalUri: $principalUri
		);
	}

	public function testADeclaredDeadlineIsAnAllDayEventOnTheWorkingDayWithItsAlarm(): void {
		$this->termEngineMovesDeadlinesTwoDays();
		$this->listable['alice'] = [$this->object('zaak-1', 'Bezwaar 2026-114', '2026-10-17')];

		$results = $this->schemaCalendar('principals/users/alice')->search();

		$this->assertCount(1, $results);
		$this->assertSame('openregister-9-zaak-1-beslistermijn@openregister.app', $results[0]['id']);
		$this->assertSame('openregister-schema-9', $results[0]['calendar-key']);
		$vevent = $results[0]['objects'][0];
		// The stored 17th is a Saturday; the term engine's working-day date wins.
		$this->assertSame(['20261019', ['VALUE' => 'DATE']], $vevent['DTSTART']);
		$this->assertSame(['20261020', ['VALUE' => 'DATE']], $vevent['DTEND']);
		$this->assertSame('Bezwaar 2026-114', $vevent['SUMMARY'][0]);
		$this->assertSame(['OpenRegister', 'DEADLINE'], $vevent['CATEGORIES'][0]);
		$this->assertCount(1, $vevent['VALARM']);
		$this->assertSame(['-P3D', ['RELATED' => 'START']], $vevent['VALARM'][0]['TRIGGER']);
		$this->assertSame('DISPLAY', $vevent['VALARM'][0]['ACTION'][0]);
	}

	public function testTheFeedAndThePlatformCalendarHoldTheSameEventsOnTheSameDates(): void {
		$this->termEngineMovesDeadlinesTwoDays();
		$this->listable['alice'] = [
			$this->object('zaak-1', 'Bezwaar 1', '2026-10-17'),
			$this->object('zaak-2', 'Bezwaar 2', '2026-11-03'),
		];

		$token = new CalendarFeedToken();
		$token->setToken('feed-token');
		$token->setUserId('alice');
		$token->setScopeType(CalendarFeedToken::SCOPE_SCHEMA);
		$token->setScopeId('9');

		$body = (string)$this->feed->render($token);
		preg_match_all('/^UID:(.*)\r$/m', $body, $feedUids);
		preg_match_all('/^DTSTART;VALUE=DATE:(\d{8})\r$/m', $body, $feedStarts);
		preg_match_all('/^TRIGGER;RELATED=START:(.*)\r$/m', $body, $feedTriggers);

		$results = $this->schemaCalendar('principals/users/alice')->search();

		$this->assertSame(
			['openregister-9-zaak-1-beslistermijn@openregister.app', 'openregister-9-zaak-2-beslistermijn@openregister.app'],
			$feedUids[1]
		);
		$this->assertSame($feedUids[1], array_column($results, 'id'));
		$this->assertSame(['20261019', '20261105'], $feedStarts[1]);
		$this->assertSame(
			$feedStarts[1],
			array_map(static fn (array $result): string => $result['objects'][0]['DTSTART'][0], $results)
		);
		$this->assertSame(
			$feedTriggers[1],
			array_map(static fn (array $result): string => $result['objects'][0]['VALARM'][0]['TRIGGER'][0], $results)
		);
	}

	public function testEachPrincipalSeesOnlyTheObjectsTheyMayListThroughTheObjectAccessPath(): void {
		$this->termEngineMovesDeadlinesTwoDays();
		$this->listable['alice'] = [$this->object('zaak-a', 'Van Alice', '2026-10-17')];
		$this->listable['bob']   = [$this->object('zaak-b', 'Van Bob', '2026-10-17')];

		$alice = $this->schemaCalendar('principals/users/alice')->search();
		$bob   = $this->schemaCalendar('principals/users/bob')->search();

		$this->assertSame(['openregister-9-zaak-a-beslistermijn@openregister.app'], array_column($alice, 'id'));
		$this->assertSame(['openregister-9-zaak-b-beslistermijn@openregister.app'], array_column($bob, 'id'));

		$this->assertSame(['alice', 'bob'], array_column($this->searches, 'user'));
		foreach ($this->searches as $search) {
			$this->assertTrue($search['rbac']);
			$this->assertTrue($search['multitenancy']);
			$this->assertSame(['schema' => '9'], $search['query']['@self']);
			$this->assertNull($search['views']);
		}
	}

	public function testADeniedReadAnswersNoEvents(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('runAs')->willReturnCallback(static fn (IUser $user, callable $operation) => $operation());
		$objects->expects($this->once())->method('searchObjects')
			->willThrowException(new RuntimeException('Access denied'));

		$sources = new ObjectCalendarFeedSources($objects, $this->schemas, $this->views, $this->userManager);
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new DateTimeZone('UTC'));
		$feed = new ObjectCalendarFeedService(
			$sources,
			new ObjectCalendarFeedRendering(
				$zone,
				$this->createMock(\OCA\OpenRegister\Service\Calendar\ObjectDateEventBuilder::class),
				new IcalendarWriter()
			),
			$this->createMock(LoggerInterface::class)
		);
		$calendars = new DeclaredDateCalendars($feed, $sources, new VeventSearchShape(), $this->logger);

		$schema = $this->deadlineSchema();
		$calendar = $calendars->schemaCalendar($schema, (array)$schema->getCalendarProviderConfig(), 'principals/users/alice');

		$this->assertSame([], $calendar->search());
	}

	public function testAnUnknownOrNonUserPrincipalNeverReachesTheObjects(): void {
		$this->listable['alice'] = [$this->object('zaak-a', 'Van Alice', '2026-10-17')];

		$this->assertSame([], $this->schemaCalendar('principals/users/mallory')->search());
		$this->assertSame([], $this->schemaCalendar('principals/groups/admin')->search());
		$this->assertSame([], $this->searches);
	}

	public function testASavedCalendarViewIsACalendarHoldingOnlyItsObjects(): void {
		$this->termEngineMovesDeadlinesTwoDays();

		$calendarView = new View();
		$calendarView->setId(5);
		$calendarView->setName('Mijn termijnen');
		$calendarView->setPresentation(['viewType' => 'calendar', 'calendar' => ['color' => '#AA0000']]);
		$tableView = new View();
		$tableView->setId(6);
		$tableView->setName('Tabel');
		$tableView->setPresentation(['viewType' => 'table']);

		$viewOwners = [];
		$this->views->expects($this->once())->method('findAll')->with('alice')
			->willReturnCallback(
				function () use (&$viewOwners, $calendarView, $tableView): array {
					$viewOwners[] = $this->currentUser;
					return [$calendarView, $tableView];
				}
			);
		$this->views->method('find')->willReturn($calendarView);
		$this->listable['alice'] = [$this->object('zaak-v', 'In de view', '2026-10-17')];

		$calendars = $this->calendars->viewCalendars('principals/users/alice');

		$this->assertSame(['alice'], $viewOwners);
		$this->assertCount(1, $calendars);
		$this->assertSame('openregister-view-5', $calendars[0]->getUri());
		$this->assertSame('Mijn termijnen', $calendars[0]->getDisplayName());
		$this->assertSame('#AA0000', $calendars[0]->getDisplayColor());

		$results = $calendars[0]->search();

		$this->assertSame(['openregister-9-zaak-v-beslistermijn@openregister.app'], array_column($results, 'id'));
		$this->assertSame('openregister-view-5', $results[0]['calendar-key']);
		$this->assertSame(['5'], $this->searches[0]['views']);
		$this->assertArrayNotHasKey('@self', $this->searches[0]['query']);
		$this->assertTrue($this->searches[0]['rbac']);
	}

	public function testViewListingFailureAnswersNoViewCalendars(): void {
		$this->views->method('findAll')->willThrowException(new RuntimeException('no read permission'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('Could not list saved views'));

		$this->assertSame([], $this->calendars->viewCalendars('principals/users/alice'));
		$this->assertSame([], $this->calendars->viewCalendars('principals/users/mallory'));
	}

	public function testDeclaresDatesOnlyForASchemaThatDeclaresADateKind(): void {
		$schema = $this->deadlineSchema();

		$this->assertTrue($this->calendars->declaresDates($schema, ['enabled' => true, 'dates' => ['d' => ['kind' => 'deadline']]]));
		$this->assertFalse($this->calendars->declaresDates($schema, ['enabled' => true, 'dtstart' => 'datum']));
		$this->assertFalse($this->calendars->declaresDates($schema, ['enabled' => true, 'dates' => []]));
	}

	public function testAnUnreadableDeclarationKeepsTheVirtualCalendarAndIsLogged(): void {
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('Schema 9 declares unreadable date kinds'));

		$this->assertFalse(
			$this->calendars->declaresDates($this->deadlineSchema(), ['enabled' => true, 'dates' => ['d' => ['kind' => 'birthday']]])
		);
	}

	public function testTheSearchHonoursPatternTimerangeAndPaging(): void {
		$this->termEngineMovesDeadlinesTwoDays();
		$this->listable['alice'] = [
			$this->object('z1', 'Bezwaar A', '2026-10-01'),
			$this->object('z2', 'Bezwaar B', '2026-10-15'),
			$this->object('z3', 'Klacht C', '2026-10-29'),
		];
		$calendar = $this->schemaCalendar('principals/users/alice');

		$this->assertSame(
			['openregister-9-z1-beslistermijn@openregister.app', 'openregister-9-z2-beslistermijn@openregister.app'],
			array_column($calendar->search('bezwaar'), 'id')
		);

		$inOctoberMiddle = $calendar->search('', [], [
			'timerange' => [
				'start' => new DateTimeImmutable('2026-10-10T00:00:00+02:00'),
				'end' => '2026-10-20T00:00:00+02:00',
			],
		]);
		$this->assertSame(['openregister-9-z2-beslistermijn@openregister.app'], array_column($inOctoberMiddle, 'id'));

		$this->assertSame(
			['openregister-9-z2-beslistermijn@openregister.app'],
			array_column($calendar->search('', [], [], 1, 1), 'id')
		);
	}

	public function testSearchResultsAskTheFeedGeneratorForTheScopeAndPrincipal(): void {
		$feed = $this->createMock(ObjectCalendarFeedService::class);
		$feed->expects($this->once())->method('eventsFor')
			->with(
				$this->callback(static fn (IUser $user): bool => $user->getUID() === 'bob'),
				CalendarFeedToken::SCOPE_VIEW,
				'12'
			)
			->willReturn(null);

		$calendars = new DeclaredDateCalendars(
			$feed,
			new ObjectCalendarFeedSources($this->objects, $this->schemas, $this->views, $this->userManager),
			new VeventSearchShape(),
			$this->logger
		);

		$scope = new ObjectDateCalendarScope(
			key: 'openregister-view-12',
			displayName: 'View',
			color: '#000000',
			scopeType: CalendarFeedToken::SCOPE_VIEW,
			scopeId: '12',
			principalUri: 'principals/users/bob'
		);

		$this->assertSame([], $calendars->searchResults($scope, new CalendarSearchQuery('', [], null, null)));
	}
}
