<?php

/**
 * Golden test for CalendarLinkService::getEventsForCalendar.
 *
 * Records the picker rows for a fixed calendar, per scenario, in
 * fixtures/calendar-link-picker-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Db\CalendarLinkMapper;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\CalendarPickerWindow;
use OCA\OpenRegister\Service\LinkSessionRuntime;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @covers \OCA\OpenRegister\Service\CalendarLinkService
 * @covers \OCA\OpenRegister\Service\CalendarPickerScan
 */
class CalendarLinkServicePickerGoldenTest extends TestCase {

	/**
	 * An iCal document with one VEVENT built from the given lines.
	 *
	 * @param array<int, string> $lines VEVENT property lines.
	 *
	 * @return string
	 */
	private static function ical(array $lines): string {
		return implode("\r\n", array_merge(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//T//EN', 'BEGIN:VEVENT', 'DTSTAMP:20260101T000000Z'], $lines, ['END:VEVENT', 'END:VCALENDAR', '']));
	}

	/**
	 * The calendar objects by uri; null data means getCalendarObject returns null.
	 *
	 * @return array<string, string|null>
	 */
	private static function objects(): array {
		return [
			'a.ics' => self::ical(['UID:a', 'SUMMARY:Late', 'DTSTART:20260901T100000Z', 'DTEND:20260901T110000Z', 'LOCATION:HQ']),
			'b.ics' => self::ical(['UID:b', 'SUMMARY:Early', 'DTSTART:20260101T090000Z']),
			'c.ics' => self::ical(['UID:c', 'SUMMARY:No start']),
			'd.ics' => self::ical(['SUMMARY:No uid', 'DTSTART:20260301T100000Z']),
			'e.ics' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:t\r\nEND:VTODO\r\nEND:VCALENDAR\r\n",
			'f.ics' => null,
			'g.ics' => '',
			'h.ics' => 'VEVENT but not ical',
			'i.ics' => self::ical(['UID:i', 'DTSTART;VALUE=DATE:20260501', 'DTEND;VALUE=DATE:20260502']),
		];
	}

	/**
	 * Scenarios: [calendar uri, limit, after].
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		return [
			'all' => ['work', 100, null],
			'limit two' => ['work', 2, null],
			'limit zero' => ['work', 0, null],
			'no limit' => ['work', null, null],
			'after april' => ['work', 100, '2026-04-01T00:00:00Z'],
			'after everything' => ['work', 100, '2027-01-01T00:00:00Z'],
			'todo calendar' => ['tasks', 100, null],
			'unknown calendar' => ['nope', 100, null],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testPickerGolden(string $uri, ?int $limit, ?string $after): void {
		$warnings = [];
		$dav = $this->createMock(CalDavBackend::class);
		$dav->method('getCalendarsForUser')->willReturn(
			[
				['id' => 4, 'uri' => 'tasks', '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => 'VTODO'],
				['id' => 5, 'uri' => 'work'],
				['id' => 3, 'uri' => 'work', '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => 'VEVENT,VTODO'],
			]
		);
		$dav->method('getCalendarObjects')->willReturnCallback(
			fn (int $id) => array_map(fn ($u) => ['uri' => $u], array_keys(self::objects()))
		);
		$dav->method('getCalendarObject')->willReturnCallback(
			function (int $id, string $objectUri) {
				$data = self::objects()[$objectUri];
				return $data === null ? null : ['calendardata' => $data];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [substr($message, 0, 50), $context];
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$service = new CalendarLinkService($this->createMock(CalendarLinkMapper::class), $this->createMock(CalendarEventService::class), $dav, new LinkSessionRuntime($session, $logger));

		$afterValue = $after === null ? null : new DateTimeImmutable($after);
		try {
			$actual = ['rows' => $service->getEventsForCalendar($uri, new CalendarPickerWindow($afterValue, $limit))];
		} catch (Throwable $e) {
			$actual = ['error' => [get_class($e), $e->getMessage()]];
		}

		$actual['warnings'] = $warnings;
		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE), true);
		$file = __DIR__ . '/fixtures/calendar-link-picker-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			ksort($all);
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame($all[$name], $actual);
	}
}
