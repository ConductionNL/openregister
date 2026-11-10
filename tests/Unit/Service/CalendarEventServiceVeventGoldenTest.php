<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\LinkSessionRuntime;
use OCA\OpenRegister\Service\StoredEvent;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Records what veventToArray() makes of iCalendar data, per scenario, against
 * fixtures/calendar-vevent-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\CalendarEventService
 */
class CalendarEventServiceVeventGoldenTest extends TestCase {

	/**
	 * Scenario table.
	 *
	 * @return array<string, array{string}>
	 */
	public static function scenarios(): array {
		$wrap = fn (string $body) => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//EN\r\n" . $body . "END:VCALENDAR\r\n";
		return [
			'full event' => [$wrap(
				"BEGIN:VEVENT\r\nUID:ev-1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260105T090000Z\r\nDTEND:20260105T100000Z\r\n"
				. "SUMMARY:Hoorzitting\r\nLOCATION:Zaal 2\r\nDESCRIPTION:Met de aanvrager\r\nSTATUS:CONFIRMED\r\n"
				. "ATTENDEE:mailto:a@example.org\r\nATTENDEE:mailto:b@example.org\r\n"
				. "X-OPENREGISTER-OBJECT:obj-9\r\nX-OPENREGISTER-REGISTER:3\r\nX-OPENREGISTER-SCHEMA:4\r\nEND:VEVENT\r\n"
			)],
			'bare event' => [$wrap("BEGIN:VEVENT\r\nDTSTAMP:20260101T000000Z\r\nEND:VEVENT\r\n")],
			'single attendee, date only' => [$wrap(
				"BEGIN:VEVENT\r\nUID:ev-2\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:20260301\r\nATTENDEE:b@example.org\r\nSTATUS:tentative\r\nEND:VEVENT\r\n"
			)],
			'todo only' => [$wrap("BEGIN:VTODO\r\nUID:t-1\r\nDTSTAMP:20260101T000000Z\r\nEND:VTODO\r\n")],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testVeventGolden(string $ics): void {
		$service = new CalendarEventService(
			$this->createMock(CalDavBackend::class),
			new LinkSessionRuntime($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class)),
			$this->createMock(IConfig::class),
			$this->createMock(IURLGenerator::class)
		);
		$actual = (new ReflectionMethod(CalendarEventService::class, 'veventToArray'))->invoke($service, new StoredEvent(calendarId: '7', uri: 'ev.ics', calendarData: $ics));

		$file = __DIR__ . '/fixtures/calendar-vevent-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
