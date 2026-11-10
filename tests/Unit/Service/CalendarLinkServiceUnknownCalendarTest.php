<?php

/**
 * CalendarLinkServiceUnknownCalendarTest
 *
 * The picker's refusal of a calendar the user does not have carries code 404,
 * so the controller can answer 404 instead of 500.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/nextcloud-entity-relations/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTimeImmutable;
use Exception;
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

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\CalendarLinkService
 */
class CalendarLinkServiceUnknownCalendarTest extends TestCase {

	/**
	 * A service whose user owns exactly one event calendar, `work`.
	 *
	 * @return CalendarLinkService
	 */
	private function service(): CalendarLinkService {
		$dav = $this->createMock(CalDavBackend::class);
		$dav->method('getCalendarsForUser')->willReturn([['id' => 3, 'uri' => 'work', '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => 'VEVENT']]);
		$dav->method('getCalendarObjects')->willReturn([]);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new CalendarLinkService(
			$this->createMock(CalendarLinkMapper::class),
			$this->createMock(CalendarEventService::class),
			$dav,
			new LinkSessionRuntime($session, $this->createMock(LoggerInterface::class))
		);
	}//end service()

	/**
	 * An unknown calendar uri is refused with code 404 and the uri in the message.
	 *
	 * @return void
	 */
	public function testUnknownCalendarIsRefusedWithCode404(): void {
		try {
			$this->service()->getEventsForCalendar('nope', new CalendarPickerWindow(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 10));
			$this->fail('An unknown calendar must be refused.');
		} catch (Exception $e) {
			$this->assertSame(404, $e->getCode());
			$this->assertSame('Calendar nope not found for user', $e->getMessage());
		}
	}//end testUnknownCalendarIsRefusedWithCode404()

	/**
	 * The user's own calendar is not refused: it lists its (here: no) events.
	 *
	 * @return void
	 */
	public function testOwnCalendarListsItsEvents(): void {
		$rows = $this->service()->getEventsForCalendar('work', new CalendarPickerWindow(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 10));

		$this->assertSame([], $rows);
	}//end testOwnCalendarListsItsEvents()
}//end class
