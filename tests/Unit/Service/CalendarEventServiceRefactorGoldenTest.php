<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Service\CalendarEventService;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what the public CalendarEventService operations return and do to the
 * CalDAV backend, the user config and the log, per scenario, against
 * fixtures/calendar-event-refactor-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * The random UID and the wall-clock DTSTAMP of a created event are masked so the
 * record does not depend on the run.
 *
 * @covers \OCA\OpenRegister\Service\CalendarEventService
 */
class CalendarEventServiceRefactorGoldenTest extends TestCase {

	/**
	 * Everything the service did to its collaborators.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Build an iCalendar document around one component body.
	 *
	 * @param string $body The component lines.
	 *
	 * @return string
	 */
	private static function ics(string $body): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//EN\r\n" . $body . "END:VCALENDAR\r\n";
	}

	/**
	 * A VEVENT linked to an object.
	 *
	 * @param string $uid   Event UID.
	 * @param string $start DTSTART value.
	 * @param string $obj   Linked object UUID.
	 *
	 * @return string
	 */
	private static function event(string $uid, string $start, string $obj): string {
		return self::ics(
			"BEGIN:VEVENT\r\nUID:" . $uid . "\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:" . $start . "\r\nSUMMARY:S " . $uid
			. "\r\nX-OPENREGISTER-OBJECT:" . $obj . "\r\nX-OPENREGISTER-REGISTER:3\r\nX-OPENREGISTER-SCHEMA:4\r\n"
			. "LINK;LINKREL=\"related\";LABEL=\"x\";VALUE=URI:/apps/openregister/api/objects/3/4/" . $obj . "\r\n"
			. "LINK;VALUE=URI:https://elsewhere.example/a\r\nEND:VEVENT\r\n"
		);
	}

	/**
	 * A component-set value exposing getValue(), as Sabre's property does.
	 *
	 * @param array $values Component names.
	 *
	 * @return object
	 */
	private static function componentSet(array $values): object {
		return new class($values) {
			/**
			 * @param array $values Component names.
			 */
			public function __construct(private array $values) {
			}

			/**
			 * @return array
			 */
			public function getValue(): array {
				return $this->values;
			}
		};
	}

	/**
	 * The calendars and their objects most scenarios run against.
	 *
	 * @return array
	 */
	private static function world(): array {
		$set = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
		return [
			'calendars' => [
				['id' => 1, 'uri' => 'contact_birthdays', $set => self::componentSet(['VEVENT'])],
				['id' => 2, 'uri' => 'tasks', $set => 'VTODO'],
				['id' => 3, 'uri' => 'personal', $set => 'VEVENT,VTODO'],
				['id' => 4, 'uri' => 'nocomp'],
				['id' => 5, 'uri' => 'iter', $set => new \ArrayIterator(['vtodo', 'vevent'])],
				['id' => 6, 'uri' => 'objnone', $set => self::componentSet(['VTODO'])],
				['id' => 7, 'uri' => 'arrnone', $set => ['VTODO']],
				['id' => 8, 'uri' => 'scalar', $set => 42],
			],
			'objects' => [
				1 => [
					'b1.ics' => self::event('b1', '20260301T090000Z', 'obj-1'),
				],
				2 => [
					't1.ics' => self::ics("BEGIN:VTODO\r\nUID:t1\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:obj-1\r\nEND:VTODO\r\n"),
				],
				3 => [
					'a.ics' => self::event('a', '20260210T090000Z', 'obj-1'),
					'b.ics' => self::event('b', '20260110T090000Z', 'obj-2'),
					'c.ics' => self::event('c', '20260110T080000Z', 'obj-1x'),
					'mention.ics' => self::ics("BEGIN:VEVENT\r\nUID:m\r\nDTSTAMP:20260101T000000Z\r\nDESCRIPTION:about obj-1\r\nEND:VEVENT\r\n"),
					'empty.ics' => '',
					'gone.ics' => null,
					'broken.ics' => "VEVENT obj-1 not ical",
					'todo.ics' => self::ics("BEGIN:VTODO\r\nUID:t2\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:obj-1\r\nEND:VTODO\r\n"),
				],
				5 => [
					'i.ics' => self::event('i', '20251231T090000Z', 'obj-1'),
				],
			],
		];
	}

	/**
	 * Build the service over a scenario's world.
	 *
	 * @param array $opt Scenario options.
	 *
	 * @return CalendarEventService
	 */
	private function service(array $opt): CalendarEventService {
		$world = ($opt['world'] ?? self::world());
		$backend = $this->createMock(CalDavBackend::class);
		$backend->method('getCalendarsForUser')->willReturnCallback(
			function ($principal) use ($world) {
				$this->calls[] = ['getCalendarsForUser', $principal];
				return $world['calendars'];
			}
		);
		$backend->method('getCalendarObjects')->willReturnCallback(
			function ($id) use ($world) {
				$this->calls[] = ['getCalendarObjects', $id];
				return array_map(fn ($uri) => ['uri' => $uri], array_keys($world['objects'][$id] ?? []));
			}
		);
		$backend->method('getCalendarObject')->willReturnCallback(
			function ($id, $uri) use ($world) {
				$data = ($world['objects'][$id][$uri] ?? null);
				if ($data === null) {
					return null;
				}
				return ['calendardata' => $data];
			}
		);
		$backend->method('createCalendarObject')->willReturnCallback(
			function ($id, $uri, $data) {
				$this->calls[] = ['createCalendarObject', $id, $uri, $data];
				return '"etag"';
			}
		);
		$backend->method('updateCalendarObject')->willReturnCallback(
			function ($id, $uri, $data) {
				$this->calls[] = ['updateCalendarObject', $id, $uri, $data];
				return '"etag"';
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (array_key_exists('user', $opt) === false || $opt['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($opt['user'] ?? 'alice');
		}
		$session->method('getUser')->willReturn($user);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn($opt['pin'] ?? '');
		$config->method('setUserValue')->willReturnCallback(
			function (...$args) use ($opt) {
				$this->calls[] = array_merge(['setUserValue'], $args);
				if (($opt['pinFails'] ?? false) === true) {
					throw new Exception('db down');
				}
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, $context = []) use ($level) {
					$this->calls[] = [$level, $message, $context];
				}
			);
		}

		$urls = $this->createMock(IURLGenerator::class);
		$route = ($opt['route'] ?? '/index.php/apps/calendar/');
		if ($route === 'throw') {
			$urls->method('linkToRoute')->willThrowException(new RuntimeException('no calendar app'));
		} else {
			$urls->method('linkToRoute')->willReturn($route);
		}
		$urls->method('getWebroot')->willReturn($opt['webroot'] ?? '/nc');

		return new CalendarEventService($backend, $session, $config, $logger, $urls);
	}//end service()

	/**
	 * Scenario table.
	 *
	 * @return array<string, array{string, array, array}>
	 */
	public static function scenarios(): array {
		$set = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
		$noVevent = [
			'calendars' => [['id' => 2, 'uri' => 'tasks', $set => 'VTODO'], ['id' => 4, 'uri' => 'nocomp']],
			'objects' => [],
		];
		$noPersonal = [
			'calendars' => [
				['id' => 2, 'uri' => 'tasks', $set => 'VTODO'],
				['id' => 5, 'uri' => 'iter', $set => ['VEVENT']],
				['id' => 9, 'uri' => 'work', $set => 'VEVENT'],
			],
			'objects' => [5 => ['x.ics' => self::event('x', '20260101T090000Z', 'obj-1')]],
		];
		$full = [
			'summary' => "Hoorzitting; a,b\\c\nnext",
			'dtstart' => '2026-03-01T09:00:00+00:00',
			'dtend' => '2026-03-01T10:30:00+00:00',
			'location' => 'Zaal, 2',
			'description' => 'Met de aanvrager',
			'attendees' => ['a@example.org', 'b@example.org'],
		];

		return [
			'events for object, no pin' => ['getEventsForObject', ['obj-1'], []],
			'events for object, pinned iter' => ['getEventsForObject', ['obj-1'], ['pin' => 'iter']],
			'events for object, stale pin' => ['getEventsForObject', ['obj-1'], ['pin' => 'tasks']],
			'events for object, unknown pin' => ['getEventsForObject', ['obj-2'], ['pin' => 'nope', 'pinFails' => true]],
			'events for object, route throws' => ['getEventsForObject', ['obj-1'], ['pin' => 'personal', 'route' => 'throw']],
			'events for object, route empty' => ['getEventsForObject', ['obj-1'], ['pin' => 'personal', 'route' => '']],
			'events for object, webroot' => ['getEventsForObject', ['obj-1'], ['pin' => 'personal', 'webroot' => '', 'route' => '/apps/calendar']],
			'events for object, no personal' => ['getEventsForObject', ['obj-1'], ['world' => $noPersonal]],
			'events for object, no vevent calendar' => ['getEventsForObject', ['obj-1'], ['world' => $noVevent]],
			'events for object, no user' => ['getEventsForObject', ['obj-1'], ['user' => null]],
			'all events' => ['getAllUserEvents', [], []],
			'all events, paged' => ['getAllUserEvents', [2, 1], []],
			'all events, offset beyond' => ['getAllUserEvents', [5, 50], []],
			'all events, no user' => ['getAllUserEvents', [], ['user' => null]],
			'create full' => ['createEvent', [3, 4, 'obj-9', 'Zaak; 1, "a"', $full], ['pin' => 'personal']],
			'create minimal' => ['createEvent', [3, 4, 'obj-9', 'T', ['dtstart' => '2026-03-01T09:00:00+00:00']], []],
			'create attendees not list' => ['createEvent', [1, 2, 'o', 'T', ['dtstart' => '2026-03-01T00:00:00Z', 'attendees' => 'x@y', 'location' => '', 'summary' => 'S']], ['pin' => 'personal']],
			'create no start' => ['createEvent', [1, 2, 'o', 'T', ['summary' => 'S']], []],
			'create bad end' => ['createEvent', [1, 2, 'o', 'T', ['dtstart' => '2026-03-01T00:00:00Z', 'dtend' => 'not a date']], ['pin' => 'personal', 'maskMessage' => true]],
			'link event' => ['linkEvent', [3, 'b.ics', 7, 8, 'obj-7'], []],
			'link missing' => ['linkEvent', [3, 'nope.ics', 7, 8, 'obj-7'], []],
			'link todo' => ['linkEvent', [3, 'todo.ics', 7, 8, 'obj-7'], []],
			'unlink event' => ['unlinkEvent', ['3', 'a.ics'], []],
			'unlink missing' => ['unlinkEvent', ['3', 'nope.ics'], []],
			'unlink todo' => ['unlinkEvent', ['2', 't1.ics'], []],
			'unlink for object' => ['unlinkEventsForObject', ['obj-1'], ['pin' => 'personal']],
		];
	}//end scenarios()

	/**
	 * @dataProvider scenarios
	 *
	 * @param string $method Public method under test.
	 * @param array  $args   Its arguments.
	 * @param array  $opt    Scenario options.
	 */
	public function testGolden(string $method, array $args, array $opt): void {
		$this->calls = [];
		$service = $this->service($opt);
		try {
			$result = ['return' => $service->$method(...$args)];
		} catch (Exception $e) {
			$result = ['exception' => get_class($e), 'message' => $e->getMessage(), 'code' => $e->getCode()];
			if (($opt['maskMessage'] ?? false) === true) {
				// The date parser's wording and class differ per PHP version.
				$result = ['exception' => 'thrown'];
			}
		}

		$result['calls'] = $this->calls;
		$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$json = preg_replace('/[0-9A-F]{32}/', '<UID>', $json);
		$json = preg_replace('/DTSTAMP:\d{8}T\d{6}Z/', 'DTSTAMP:<NOW>', $json);
		$actual = json_decode($json, true);

		$file = __DIR__ . '/fixtures/calendar-event-refactor-golden.json';
		$name = $this->dataName();
		$all = [];
		if (is_file($file) === true) {
			$all = json_decode(file_get_contents($file), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertArrayHasKey($name, $all);
		$this->assertSame($all[$name], $actual);
	}//end testGolden()
}//end class
