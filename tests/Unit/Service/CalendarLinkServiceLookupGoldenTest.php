<?php

/**
 * Golden test for the calendar picker and event lookup of CalendarLinkService.
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
use OCA\OpenRegister\Db\CalendarLinkMapper;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\LinkSessionRuntime;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the rows, CalDAV reads and log lines of getEventsForCalendar() and
 * the event lookup behind linkEvent().
 *
 * @covers \OCA\OpenRegister\Service\CalendarLinkService
 * @uses   \OCA\OpenRegister\Service\CalendarPickerScan
 * @uses   \OCA\OpenRegister\Db\CalendarLink
 */
class CalendarLinkServiceLookupGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/calendar-link-service-lookup-golden.json';

	private const SET = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';

	private array $calls = [];

	private static function ics(array $lines): string {
		return implode("\r\n", array_merge(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//T//EN'], $lines, ['END:VCALENDAR', '']));
	}

	private static function event(string $uid, array $props): string {
		return self::ics(array_merge(['BEGIN:VEVENT', 'UID:' . $uid, 'DTSTAMP:20260101T000000Z'], $props, ['END:VEVENT']));
	}

	private static function objects(): array {
		return [
			'a.ics' => self::event('ev-a', ['SUMMARY:Late', 'DTSTART:20260701T100000Z', 'DTEND:20260701T110000Z', 'LOCATION:HQ']),
			'b.ics' => self::event('ev-b', ['DTSTART:20260501T090000Z']),
			'c.ics' => self::event('ev-c', ['SUMMARY:No start']),
			'd.ics' => self::ics(['BEGIN:VTODO', 'UID:todo-1', 'END:VTODO']),
			'e.ics' => 'BEGIN:VCALENDAR' . "\r\n" . 'BEGIN:VEVENT broken',
			'f.ics' => null,
			'g.ics' => '',
			'h.ics' => self::ics(['BEGIN:VEVENT', 'SUMMARY:No uid', 'END:VEVENT']),
			'i.ics' => self::event('ev-i', ['SUMMARY:Early', 'DTSTART;VALUE=DATE:20260301', 'LOCATION:Room 2']),
			'j.ics' => self::event('ev-a-copy', ['SUMMARY:Mentions ev-a', 'DTSTART:20260801T100000Z']),
		];
	}

	private static function calendars(): array {
		return [
			['id' => 1, 'uri' => 'tasks', self::SET => 'VTODO'],
			['id' => 2, 'uri' => 'work', self::SET => 'VEVENT,VTODO'],
			['id' => 3, 'uri' => 'iter', self::SET => ['VTODO', 'vevent']],
			['id' => 4, 'uri' => 'bare'],
			['id' => 5, 'uri' => 'obj', self::SET => new class {
				public function getValue(): array {
					return ['VEVENT'];
				}
			}],
		];
	}

	private static function cases(): array {
		return [
			'events no user' => ['events', [null, 'work', null, null]],
			'events unknown uri' => ['events', ['alice', 'nope', null, null]],
			'events non vevent calendar' => ['events', ['alice', 'tasks', null, null]],
			'events no component set' => ['events', ['alice', 'bare', null, null]],
			'events all' => ['events', ['alice', 'work', 100, null]],
			'events null limit' => ['events', ['alice', 'iter', null, null]],
			'events limit two' => ['events', ['alice', 'obj', 2, null]],
			'events zero limit' => ['events', ['alice', 'work', 0, null]],
			'events after cutoff' => ['events', ['alice', 'work', 100, '2026-05-15T00:00:00Z']],
			'events cutoff at a start' => ['events', ['alice', 'work', 100, '2026-05-01T09:00:00Z']],
			'link no user' => ['link', [null, 'work', 'ev-a']],
			'link unknown calendar' => ['link', ['alice', 'nope', 'ev-a']],
			'link found' => ['link', ['alice', 'work', 'ev-a']],
			'link found no extras' => ['link', ['alice', 'tasks', 'ev-c']],
			'link date start' => ['link', ['alice', 'bare', 'ev-i']],
			'link missing uid' => ['link', ['alice', 'work', 'ev-zzz']],
			'link substring only' => ['link', ['alice', 'work', 'ev-a-cop']],
			'link broken object' => ['link', ['alice', 'work', 'VEVENT broken']],
			'link todo' => ['link', ['alice', 'work', 'todo-1']],
		];
	}

	public function testPickerAndLookupMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => [$kind, $args]) {
			$this->calls = [];
			$service = $this->makeService($args[0]);
			$outcome = [];
			try {
				$outcome['result'] = $this->exercise($service, $kind, $args);
			} catch (\Exception $e) {
				$outcome['thrown'] = get_class($e) . ': ' . $e->getMessage();
			}

			$outcome['calls'] = $this->calls;
			$actual[$name] = $outcome;
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testPickerAndLookupMatchGolden()

	private function exercise(CalendarLinkService $service, string $kind, array $args): array {
		if ($kind === 'events') {
			$after = $args[3] === null ? null : new DateTime($args[3]);
			return $service->getEventsForCalendar($args[1], $args[2], $after);
		}

		$link = $service->linkEvent('obj-1', 3, 4, $args[1], $args[2]);
		$out = [];
		foreach (['calendarId', 'eventUri', 'summary', 'location', 'linkedBy', 'eventUid', 'calendarUri'] as $field) {
			$out[$field] = $link->{'get' . ucfirst($field)}();
		}

		$out['dtstart'] = $link->getDtstart()?->format(DATE_ATOM);
		$out['dtend'] = $link->getDtend()?->format(DATE_ATOM);
		$out['dtstartClass'] = $link->getDtstart() === null ? null : get_class($link->getDtstart());
		return $out;
	}//end exercise()

	private function makeService(?string $uid): CalendarLinkService {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$backend = $this->createMock(CalDavBackend::class);
		$backend->method('getCalendarsForUser')->willReturnCallback(
			function (string $principal): array {
				$this->calls[] = 'calendars ' . $principal;
				return self::calendars();
			}
		);
		$backend->method('getCalendarObjects')->willReturnCallback(
			function ($calendarId): array {
				$this->calls[] = 'objects ' . $calendarId;
				return array_map(fn (string $uri) => ['uri' => $uri], array_keys(self::objects()));
			}
		);
		$backend->method('getCalendarObject')->willReturnCallback(
			function ($calendarId, $uri): ?array {
				$this->calls[] = 'object ' . $calendarId . ' ' . $uri;
				$data = self::objects()[$uri];
				return $data === null ? null : ['calendardata' => $data];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = 'warning ' . preg_replace('/: .*$/s', ':', $message) . ' ' . json_encode($context);
			}
		);
		$mapper = $this->createMock(CalendarLinkMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		return new CalendarLinkService($mapper, $this->createMock(CalendarEventService::class), $backend, new LinkSessionRuntime($session, $logger));
	}//end makeService()
}//end class
