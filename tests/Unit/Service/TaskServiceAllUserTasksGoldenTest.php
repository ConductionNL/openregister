<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Service\TaskService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records the CalDAV reads, log lines and result of listUserTasks(), per scenario, against
 * fixtures/task-all-user-tasks-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\TaskService
 * @uses   \OCA\OpenRegister\Service\Task\VtodoCalendarLocator
 */
class TaskServiceAllUserTasksGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * A VTODO calendar object.
	 *
	 * @param string      $uid    The UID.
	 * @param string|null $due    DUE date-time (Ymd\THis\Z), or null.
	 * @param string      $status The STATUS.
	 *
	 * @return string The iCalendar data.
	 */
	private static function vtodo(string $uid, ?string $due, string $status): string {
		$dueLine = $due === null ? '' : "DUE:{$due}\r\n";
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//EN\r\nBEGIN:VTODO\r\nUID:{$uid}\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:Task {$uid}\r\n"
			. $dueLine . "STATUS:{$status}\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
	}

	/**
	 * Builds the service for one scenario.
	 *
	 * @param array $s Scenario settings.
	 *
	 * @return TaskService
	 */
	private function service(array $s): TaskService {
		$set = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
		$calendars = [
			['id' => 1, $set => 'VEVENT,VTODO'],
			['id' => 2, $set => 'VEVENT'],
			['id' => 3, $set => ['VTODO']],
		];
		$objects = [
			1 => [
				'a.ics' => self::vtodo('a', '20260310T090000Z', 'NEEDS-ACTION'),
				'b.ics' => self::vtodo('b', null, 'COMPLETED'),
				'e.ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//EN\r\nBEGIN:VEVENT\r\nUID:e\r\nDTSTAMP:20260101T000000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
				'gone.ics' => null,
				'empty.ics' => '',
				'broken.ics' => "BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nnot ical",
				'mention.ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//EN\r\nBEGIN:VEVENT\r\nUID:m\r\nDTSTAMP:20260101T000000Z\r\nSUMMARY:about VTODO\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
			],
			3 => [
				'c.ics' => self::vtodo('c', '20260201T090000Z', 'NEEDS-ACTION'),
				'd.ics' => self::vtodo('d', '20260401T090000Z', 'IN-PROCESS'),
			],
		];

		$cal = $this->createMock(CalDavBackend::class);
		$cal->method('getCalendarsForUser')->willReturnCallback(function ($principal) use ($calendars) {
			$this->calls[] = ['calendars', $principal];
			return $calendars;
		});
		$cal->method('getCalendarObjects')->willReturnCallback(function ($id) use ($objects) {
			$this->calls[] = ['objects', $id];
			return array_map(fn ($uri) => ['uri' => $uri], array_keys($objects[$id] ?? []));
		});
		$cal->method('getCalendarObject')->willReturnCallback(function ($id, $uri) use ($objects) {
			$this->calls[] = ['object', $id, $uri];
			$data = $objects[$id][$uri];
			return $data === null ? null : ['calendardata' => $data];
		});

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($s['user'] === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}
		$session->method('getUser')->willReturn($user);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function ($message, array $context = []) {
			$this->calls[] = ['log.warning', strtok($message, ':'), $context];
		});

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud/link');
		$urls->method('getAbsoluteURL')->willReturnCallback(fn ($u) => 'https://cloud' . $u);
		$urls->method('linkToRoute')->willReturn('/route');

		return new TaskService($cal, $session, $logger, $urls);
	}

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$base = ['user' => true, 'args' => []];
		return [
			'all, sorted by due' => [$base],
			'status filter, any case' => [['args' => ['status' => 'Needs-Action']] + $base],
			'page' => [['args' => ['limit' => 2, 'offset' => 1]] + $base],
			'page past the end' => [['args' => ['limit' => 2, 'offset' => 9]] + $base],
			'nobody logged in' => [['user' => false] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testAllUserTasksGolden(array $s): void {
		try {
			$result = $this->service($s)->listUserTasks(status: $s['args']['status'] ?? null, query: new ListQuery(limit: $s['args']['limit'] ?? 50, offset: $s['args']['offset'] ?? 0));
			$result['results'] = array_map(fn ($t) => [$t['uid'] ?? null, $t['status'] ?? null, $t['due'] ?? null, $t['calendarId'] ?? null, $t['id'] ?? null], $result['results']);
			$this->calls[] = ['result', $result];
		} catch (Throwable $e) {
			$this->calls[] = ['thrown', get_class($e), $e->getMessage()];
		}

		$file = __DIR__ . '/fixtures/task-all-user-tasks-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		$actual = json_decode(json_encode($this->calls, JSON_UNESCAPED_UNICODE), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
