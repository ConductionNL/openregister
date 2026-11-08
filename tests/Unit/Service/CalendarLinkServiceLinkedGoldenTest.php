<?php

/**
 * Characterisation golden for the unlink, linked-events and calendar listing paths of CalendarLinkService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapperInterface;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Pins results, backend and mapper calls and log lines of unlinkEvent(),
 * getLinkedEvents() and getAvailableCalendars(), compared with
 * tests/Unit/Service/fixtures/calendar-link-service-linked-golden.json.
 * Run with UPDATE_GOLDEN=1 to re-record.
 *
 * @covers \OCA\OpenRegister\Service\CalendarLinkService
 * @uses   \OCA\OpenRegister\Db\CalendarLink
 */
class CalendarLinkServiceLinkedGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/calendar-link-service-linked-golden.json';

	private const SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	private array $calls = [];

	private static function ics(array $lines): string {
		return implode("\r\n", array_merge(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//T//EN'], $lines, ['END:VCALENDAR', '']));
	}//end ics()

	private static function objects(): array {
		return [
			'a.ics' => self::ics(['BEGIN:VEVENT', 'UID:ev-a', 'DTSTAMP:20260101T000000Z', 'ATTENDEE;CN=Ann;PARTSTAT=ACCEPTED:mailto:ann@x.nl', 'ATTENDEE:MAILTO:bob@x.nl', 'END:VEVENT']),
			'b.ics' => self::ics(['BEGIN:VEVENT', 'UID:ev-b', 'DTSTAMP:20260101T000000Z', 'END:VEVENT']),
			'todo.ics' => self::ics(['BEGIN:VTODO', 'UID:t', 'END:VTODO']),
			'broken.ics' => 'BEGIN:VCALENDAR' . "\r\n" . 'BEGIN:VEVENT broken',
			'empty.ics' => '',
			'string.ics' => 'not-an-array',
		];
	}//end objects()

	private static function calendars(): array {
		return [
			['id' => 1, 'uri' => 'tasks', self::SET => 'VTODO'],
			['id' => 2, 'uri' => 'work', self::SET => 'VEVENT,VTODO', '{DAV:}displayname' => 'Work', '{http://apple.com/ns/ical/}calendar-color' => '#ff0000'],
			['id' => 3, 'uri' => 'iter', self::SET => ['VTODO', 'vevent']],
			['id' => 4, 'uri' => 'bare'],
			['id' => 5, 'uri' => 'obj', self::SET => new class {
				public function getValue(): array {
					return ['VEVENT'];
				}
			}],
			['id' => 6, 'uri' => 'num', self::SET => 42],
			['uri' => 'noid', self::SET => 'VTODO'],
		];
	}//end calendars()

	private static function world(array $over): array {
		return $over + [
			'uid' => 'alice',
			'existing' => null,
			'xor' => [],
			'xorThrows' => false,
			'unlinkThrows' => false,
			'calendarsThrow' => false,
			'linked' => [],
		];
	}//end world()

	private static function cases(): array {
		$tagged = ['taggedWithXor' => true, 'calendarId' => 2, 'eventUri' => 'a.ics'];
		$xor = [
			['uid' => 'ev-x', 'calendarId' => 2, 'id' => 'x.ics', 'summary' => 'X'],
			['uid' => 'ev-b', 'calendarId' => '2', 'id' => 'b.ics', 'summary' => 'Live B', 'location' => '', 'status' => 'CONFIRMED', 'dtstart' => null],
			['uid' => 'ev-a', 'calendarId' => 99, 'id' => 'a.ics', 'summary' => 'elsewhere'],
			['uid' => '', 'calendarId' => 2, 'id' => 'none.ics'],
			['calendarId' => 2, 'id' => 'nouid.ics'],
			['uid' => 'ev-y', 'id' => 'y.ics'],
		];
		$links = [
			['calendarUri' => 'work', 'eventUid' => 'ev-a', 'calendarId' => 2, 'eventUri' => 'a.ics', 'summary' => 'A'],
			['calendarUri' => 'work', 'eventUid' => 'ev-b', 'calendarId' => 2, 'eventUri' => 'b.ics', 'summary' => 'B'],
			['calendarUri' => 'tasks', 'eventUid' => 't', 'calendarId' => 1, 'eventUri' => 'todo.ics', 'summary' => 'T'],
			['calendarUri' => 'work', 'eventUid' => 'ev-z', 'calendarId' => 2, 'eventUri' => 'broken.ics', 'summary' => 'Z'],
			['calendarUri' => 'work', 'eventUid' => 'ev-e', 'calendarId' => 2, 'eventUri' => 'empty.ics', 'summary' => 'E'],
			['calendarUri' => 'work', 'eventUid' => 'ev-s', 'calendarId' => 2, 'eventUri' => 'string.ics', 'summary' => 'S'],
			['calendarUri' => 'work', 'eventUid' => 'ev-m', 'calendarId' => 2, 'eventUri' => 'missing.ics', 'summary' => 'M'],
		];
		return [
			'unlink tagged' => ['unlinkEvent', ['existing' => $tagged]],
			'unlink tagged strip fails' => ['unlinkEvent', ['existing' => $tagged, 'unlinkThrows' => true]],
			'unlink untagged legacy match' => ['unlinkEvent', ['existing' => ['taggedWithXor' => false] + $tagged, 'xor' => [['uid' => 'ev-q', 'calendarId' => 2, 'id' => 'q.ics'], ['uid' => 'ev-1', 'calendarId' => 3, 'id' => 'one.ics'], ['uid' => 'ev-1', 'calendarId' => 4, 'id' => 'two.ics']]]],
			'unlink tagged no calendar id' => ['unlinkEvent', ['existing' => ['calendarId' => null] + $tagged]],
			'unlink tagged no uri' => ['unlinkEvent', ['existing' => ['eventUri' => null] + $tagged]],
			'unlink no row no match' => ['unlinkEvent', ['xor' => [['uid' => 'other', 'calendarId' => 2, 'id' => 'o.ics']]]],
			'unlink no row scan fails' => ['unlinkEvent', ['xorThrows' => true]],
			'unlink no row legacy unlink fails' => ['unlinkEvent', ['xor' => [['uid' => 'ev-1', 'calendarId' => 3, 'id' => 'one.ics']], 'unlinkThrows' => true]],
			'linked nothing' => ['getLinkedEvents', []],
			'linked merge' => ['getLinkedEvents', ['linked' => $links, 'xor' => $xor]],
			'linked no user' => ['getLinkedEvents', ['uid' => null, 'linked' => $links, 'xor' => $xor]],
			'linked calendars fail' => ['getLinkedEvents', ['calendarsThrow' => true, 'linked' => $links, 'xor' => $xor]],
			'linked scan fails' => ['getLinkedEvents', ['linked' => $links, 'xorThrows' => true]],
			'linked xor only' => ['getLinkedEvents', ['xor' => $xor]],
			'calendars no user' => ['getAvailableCalendars', ['uid' => null]],
			'calendars' => ['getAvailableCalendars', []],
		];
	}//end cases()

	public function testLinkedPathsMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$method, $over]) {
			$this->calls = [];
			$service = $this->makeService(self::world($over));
			$args = match ($method) {
				'unlinkEvent' => ['obj-1', 'ev-1'],
				'getLinkedEvents' => ['obj-1'],
				default => [],
			};
			try {
				$result = $this->normalise($service->$method(...$args));
			} catch (Throwable $e) {
				$result = ['throws' => get_class($e), 'message' => $e->getMessage()];
			}

			$actual[$name] = ['result' => $result, 'calls' => $this->calls];
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testLinkedPathsMatchGolden()

	private function normalise(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn ($v) => $this->normalise($v), $value);
		}

		if (is_string($value) === true && preg_match('/^\d{4}-\d\d-\d\dT/', $value) === 1 && abs(strtotime($value) - time()) < 3600) {
			return 'NOW';
		}

		return $value;
	}//end normalise()

	private function linkRow(array $spec): CalendarLink {
		$link = new CalendarLink();
		foreach ($spec as $field => $value) {
			$link->{'set' . ucfirst($field)}($value);
		}

		$link->setLinkedAt(new DateTime('@1700000000'));
		return $link;
	}//end linkRow()

	private function makeService(array $w): CalendarLinkService {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($w['uid'] !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($w['uid']);
		}

		$session->method('getUser')->willReturn($user);
		$backend = $this->createMock(CalDavBackend::class);
		$backend->method('getCalendarsForUser')->willReturnCallback(
			function (string $principal) use ($w): array {
				$this->calls[] = 'calendars ' . $principal;
				if ($w['calendarsThrow'] === true) {
					throw new RuntimeException('dav down');
				}

				return self::calendars();
			}
		);
		$backend->method('getCalendarObject')->willReturnCallback(
			function ($calendarId, $uri) {
				$this->calls[] = 'object ' . $calendarId . ' ' . $uri;
				$data = self::objects()[$uri] ?? null;
				if ($data === null) {
					return null;
				}

				if ($data === 'not-an-array') {
					return $data;
				}

				return ['calendardata' => $data];
			}
		);
		$events = $this->createMock(CalendarEventService::class);
		$events->method('getEventsForObject')->willReturnCallback(
			function (string $uuid) use ($w): array {
				$this->calls[] = 'getEventsForObject ' . $uuid;
				if ($w['xorThrows'] === true) {
					throw new RuntimeException('scan down');
				}

				return $w['xor'];
			}
		);
		$events->method('unlinkEvent')->willReturnCallback(
			function (string $calendarId, string $eventUri) use ($w): void {
				$this->calls[] = 'unlinkEvent ' . $calendarId . ' ' . $eventUri;
				if ($w['unlinkThrows'] === true) {
					throw new RuntimeException('strip down');
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = $level . ' ' . $message;
				}
			);
		}

		$mapper = $this->createMock(CalendarLinkMapperInterface::class);
		$mapper->method('findByObjectAndEvent')->willReturnCallback(
			function (string $uuid, string $uid) use ($w): CalendarLink {
				$this->calls[] = 'findByObjectAndEvent ' . $uuid . ' ' . $uid;
				if ($w['existing'] === null) {
					throw new DoesNotExistException('none');
				}

				return $this->linkRow($w['existing']);
			}
		);
		$mapper->method('deleteByObjectAndEvent')->willReturnCallback(
			function (string $uuid, string $uid): int {
				$this->calls[] = 'deleteByObjectAndEvent ' . $uuid . ' ' . $uid;
				return 1;
			}
		);
		$mapper->method('findByObjectUuid')->willReturnCallback(
			function (string $uuid) use ($w): array {
				$this->calls[] = 'findByObjectUuid ' . $uuid;
				return array_map(fn (array $spec) => $this->linkRow($spec), $w['linked']);
			}
		);
		return new CalendarLinkService($mapper, $events, $backend, $session, $logger);
	}//end makeService()
}//end class
