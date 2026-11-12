<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Kernel\Contracts\TaskLocation;
use OCA\OpenRegister\Service\TaskService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins the read side of TaskService — getTasksForObject() with its deep links,
 * and what vtodoToArray() makes of iCalendar data — per scenario, against
 * fixtures/task-service-read-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 * Every date carries an explicit UTC value so the record does not depend on the
 * PHP timezone setting.
 *
 * @covers \OCA\OpenRegister\Service\TaskService
 */
class TaskServiceReadGoldenTest extends TestCase {

	/**
	 * Collaborator calls.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Wrap a component in a calendar.
	 *
	 * @param string $body Component lines.
	 *
	 * @return string
	 */
	private static function ics(string $body): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//EN\r\n" . $body . "END:VCALENDAR\r\n";
	}

	/**
	 * A VTODO linked to an object.
	 *
	 * @param string $uid Task UID.
	 * @param string $obj Linked object.
	 *
	 * @return string
	 */
	private static function task(string $uid, string $obj): string {
		return self::ics(
			"BEGIN:VTODO\r\nUID:" . $uid . "\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:T " . $uid . "\r\nDUE:20260301T090000Z\r\n"
			. "X-OPENREGISTER-OBJECT:" . $obj . "\r\nX-OPENREGISTER-REGISTER:3\r\nX-OPENREGISTER-SCHEMA:4\r\nEND:VTODO\r\n"
		);
	}

	/**
	 * Build the service.
	 *
	 * @param array $opt Options: user (?string), route (string|'throw'), calendarUri (string|null).
	 *
	 * @return TaskService
	 */
	private function service(array $opt): TaskService {
		$set = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
		$objects = [
			'a.ics' => self::task('a', 'obj-1'),
			'b.ics' => self::task('b', 'obj-2'),
			'c.ics' => self::task('c', 'obj-1x'),
			'ev.ics' => self::ics("BEGIN:VEVENT\r\nUID:e\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:obj-1\r\nEND:VEVENT\r\n"),
			'empty.ics' => '',
			'gone.ics' => null,
			'broken.ics' => "VTODO obj-1 broken",
			'other-broken.ics' => "VTODO broken without the uuid",
			'a2.ics' => self::task('a2', 'obj-1'),
		];
		$cal = $this->createMock(CalDavBackend::class);
		$cal->method('getCalendarsForUser')->willReturn([
			['id' => 1, 'uri' => 'events', $set => 'VEVENT'],
			['id' => 5, 'uri' => ($opt['calendarUri'] ?? 'tasks list'), $set => 'VTODO'],
		]);
		$cal->method('getCalendarObjects')->willReturnCallback(
			function ($id) use ($objects) {
				$this->calls[] = ['objects', $id];
				return array_map(fn ($uri) => ['uri' => $uri], array_keys($objects));
			}
		);
		$cal->method('getCalendarObject')->willReturnCallback(
			function ($id, $uri) use ($objects) {
				$this->calls[] = ['object', $id, $uri];
				if ($objects[$uri] === null) {
					return null;
				}
				return ['calendardata' => $objects[$uri]];
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (array_key_exists('user', $opt) === false || $opt['user'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}
		$session->method('getUser')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function ($message, $context = []) {
				$this->calls[] = ['warning', $message, $context];
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		if (($opt['route'] ?? '') === 'throw') {
			$urls->method('linkToRoute')->willThrowException(new RuntimeException('no tasks app'));
		} else {
			$urls->method('linkToRoute')->willReturn($opt['route'] ?? '/index.php/apps/tasks/');
		}

		return new TaskService($cal, $session, $logger, $urls);
	}//end service()

	/**
	 * Scenario table for getTasksForObject.
	 *
	 * @return array<string, array{string, array}>
	 */
	public static function objectScenarios(): array {
		return [
			'object with tasks' => ['obj-1', []],
			'object, route throws' => ['obj-1', ['route' => 'throw']],
			'object, route without slash' => ['obj-2', ['route' => '/apps/tasks']],
			'object, empty calendar uri' => ['obj-1', ['calendarUri' => '']],
			'object with none' => ['obj-9', []],
			'empty uuid' => ['', []],
			'no user' => ['obj-1', ['user' => null]],
		];
	}//end objectScenarios()

	/**
	 * Scenario table for vtodoToArray.
	 *
	 * @return array<string, array{string}>
	 */
	public static function parseScenarios(): array {
		$data = base64_encode((string)json_encode(['kenmerk' => 'Z-1', 'n' => 2]));
		return [
			'full' => [self::ics(
				"BEGIN:VTODO\r\nUID:t-1\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:Bel terug\r\nDESCRIPTION:Over de zaak\r\nSTATUS:IN-PROCESS\r\n"
				. "PRIORITY:3\r\nDUE:20260301T090000Z\r\nCOMPLETED:20260302T100000Z\r\nCREATED:20260101T080000Z\r\n"
				. "X-OPENREGISTER-OBJECT:obj-1\r\nX-OPENREGISTER-REGISTER:7\r\nX-OPENREGISTER-SCHEMA:8\r\nX-OPENREGISTER-DATA:" . $data . "\r\nEND:VTODO\r\n"
			)],
			'bare' => [self::ics("BEGIN:VTODO\r\nDTSTAMP:20260101T000000Z\r\nEND:VTODO\r\n")],
			'data not base64' => [self::ics("BEGIN:VTODO\r\nUID:t-2\r\nDTSTAMP:20260101T000000Z\r\nX-OPENREGISTER-DATA:@@@\r\nEND:VTODO\r\n")],
			'data not json' => [self::ics("BEGIN:VTODO\r\nUID:t-3\r\nDTSTAMP:20260101T000000Z\r\nX-OPENREGISTER-DATA:" . base64_encode('nope') . "\r\nEND:VTODO\r\n")],
			'data json scalar' => [self::ics("BEGIN:VTODO\r\nUID:t-4\r\nDTSTAMP:20260101T000000Z\r\nX-OPENREGISTER-DATA:" . base64_encode('5') . "\r\nEND:VTODO\r\n")],
			'event only' => [self::ics("BEGIN:VEVENT\r\nUID:e\r\nDTSTAMP:20260101T000000Z\r\nEND:VEVENT\r\n")],
		];
	}//end parseScenarios()

	/**
	 * @dataProvider objectScenarios
	 *
	 * @param string $uuid The object.
	 * @param array  $opt  Options.
	 *
	 * @return void
	 */
	public function testTasksForObject(string $uuid, array $opt): void {
		$this->calls = [];
		try {
			$result = ['return' => $this->service($opt)->getTasksForObject($uuid)];
		} catch (Exception $e) {
			$result = ['exception' => get_class($e), 'message' => $e->getMessage()];
		}

		$result['calls'] = $this->calls;
		$this->assertGolden($result);
	}//end testTasksForObject()

	/**
	 * @dataProvider parseScenarios
	 *
	 * @param string $ics The calendar data.
	 *
	 * @return void
	 */
	public function testVtodoToArray(string $ics): void {
		$actual = (new ReflectionMethod(TaskService::class, 'vtodoToArray'))->invoke($this->service([]), $ics, new TaskLocation(calendarId: '5', uri: 'x.ics'));
		$this->assertGolden(['return' => $actual]);
	}//end testVtodoToArray()

	/**
	 * Compare with, or record into, the fixture.
	 *
	 * @param array $result The observed result.
	 *
	 * @return void
	 */
	private function assertGolden(array $result): void {
		$actual = json_decode(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true);
		$file = __DIR__ . '/fixtures/task-service-read-golden.json';
		$name = $this->name() . ' ' . $this->dataName();
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
	}//end assertGolden()
}//end class
