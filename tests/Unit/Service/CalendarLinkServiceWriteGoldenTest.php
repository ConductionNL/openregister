<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

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
use Throwable;

/**
 * Records, per scenario, the createEvent call, the CalDAV lookups and the whole inserted
 * row of linkEvent() and createAndLinkEvent(), against
 * fixtures/calendar-link-write-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 * The linkedAt timestamp is replaced by a marker before comparing.
 *
 * @covers \OCA\OpenRegister\Service\CalendarLinkService
 * @uses   \OCA\OpenRegister\Db\CalendarLink
 */
class CalendarLinkServiceWriteGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/calendar-link-write-golden.json';

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * The service over one scenario.
	 *
	 * @param array $s Scenario settings: user, created (createEvent result), calendars.
	 *
	 * @return CalendarLinkService
	 */
	private function service(array $s): CalendarLinkService {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($s['user'] === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}
		$session->method('getUser')->willReturn($user);

		$events = $this->createMock(CalendarEventService::class);
		$events->method('createEvent')->willReturnCallback(function (...$args) use ($s) {
			$this->calls[] = ['events.create', $args];
			return $s['created'];
		});

		$dav = $this->createMock(CalDavBackend::class);
		$dav->method('getCalendarsForUser')->willReturnCallback(function ($principal) use ($s) {
			$this->calls[] = ['dav.calendars', $principal];
			return $s['calendars'];
		});
		$dav->method('getCalendarObjects')->willReturnCallback(function ($calendarId) {
			$this->calls[] = ['dav.objects', $calendarId];
			return [['uri' => 'one.ics']];
		});
		$dav->method('getCalendarObject')->willReturnCallback(function ($calendarId, $uri) {
			$this->calls[] = ['dav.object', $calendarId, $uri];
			return ['calendardata' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:ev-1\r\nSUMMARY:Kickoff\r\n"
				. "DTSTART:20260601T100000Z\r\nDTEND:20260601T110000Z\r\nLOCATION:HQ\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"];
		});

		$mapper = $this->createMock(CalendarLinkMapper::class);
		$mapper->method('insert')->willReturnCallback(function (CalendarLink $link) {
			$row = $link->jsonSerialize();
			$row['linkedAt'] = $row['linkedAt'] === null ? null : 'CLOCK';
			$row['dtstartClass'] = $link->getDtstart() === null ? null : get_class($link->getDtstart());
			$this->calls[] = ['link.insert', $row];
			return $link;
		});

		return new CalendarLinkService($mapper, $events, $dav, new LinkSessionRuntime($session, $this->createMock(LoggerInterface::class)));
	}

	/**
	 * The scenarios.
	 *
	 * @return array<string,array>
	 */
	public static function scenarios(): array {
		$full = [
			'id' => 'new.ics', 'uid' => 'new-uid', 'calendarId' => '7', 'summary' => 'Meeting',
			'dtstart' => '2026-06-01T10:00:00+00:00', 'dtend' => '2026-06-01T11:00:00+00:00', 'location' => 'HQ',
		];
		$calendars = [['id' => 7, 'uri' => 'personal'], ['id' => 8, 'uri' => 'work']];
		$base = ['user' => true, 'created' => $full, 'calendars' => $calendars];
		return [
			'create full' => ['create', ['objectTitle' => 'Zaak 1', 'summary' => 'Meeting'] + [], $base],
			'create title falls back to uuid' => ['create', ['summary' => 'S'], $base],
			'create non-string title' => ['create', ['objectTitle' => 42], $base],
			'create sparse event' => ['create', [], ['created' => ['calendarId' => '9']] + $base],
			'create empty event' => ['create', [], ['created' => []] + $base],
			'create bad dates and numeric location' => ['create', [], ['created' => ['dtstart' => 'not a date', 'dtend' => 'also bad', 'location' => 12] + $full] + $base],
			'create null location' => ['create', [], ['created' => ['location' => null] + $full] + $base],
			'create calendar not resolvable' => ['create', [], ['calendars' => []] + $base],
			'create returns null' => ['create', [], ['created' => null] + $base],
			'create no user' => ['create', [], ['user' => false] + $base],
			'link found' => ['link', ['work', 'ev-1'], $base],
			'link not found' => ['link', ['work', 'ev-2'], $base],
			'link unknown calendar' => ['link', ['nope', 'ev-1'], $base],
			'link no user' => ['link', ['work', 'ev-1'], ['user' => false] + $base],
		];
	}

	/**
	 * Each write keeps its calls, its inserted row and its failures.
	 *
	 * @param string $kind create or link.
	 * @param array  $args The event data, or [calendarUri, eventUid].
	 * @param array  $s    Scenario settings.
	 *
	 * @dataProvider scenarios
	 *
	 * @return void
	 */
	public function testWriteMatchesGolden(string $kind, array $args, array $s): void {
		$service = $this->service($s);
		try {
			if ($kind === 'create') {
				$service->createAndLinkEvent('obj-1', 3, 4, $args);
			} else {
				$service->linkEvent('obj-1', 3, 4, $args[0], $args[1]);
			}
		} catch (Throwable $e) {
			$this->calls[] = ['thrown', get_class($e), $e->getMessage(), $e->getCode()];
		}

		$all = is_file(self::FIXTURE) === true ? json_decode((string)file_get_contents(self::FIXTURE), true) : [];
		$actual = json_decode((string)json_encode($this->calls, JSON_UNESCAPED_UNICODE), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$this->dataName()] = $actual;
			file_put_contents(self::FIXTURE, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}
		$this->assertSame($all[$this->dataName()], $actual);
	}
}
