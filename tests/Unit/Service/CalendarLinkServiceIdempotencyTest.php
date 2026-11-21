<?php

/**
 * Unit tests for CalendarLinkService's idempotent linking and reverse lookup.
 *
 * A repeated link of the same object, event and calendar returns the row that
 * is already there and inserts nothing; getObjectsForEvent() returns the rows
 * of one VEVENT, optionally scoped to one calendar.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapper;
use OCA\OpenRegister\Service\CalendarEventKey;
use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;
use OCA\OpenRegister\Service\LinkSessionRuntime;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Idempotent linking and the event-to-objects lookup.
 */
class CalendarLinkServiceIdempotencyTest extends TestCase {
	/**
	 * The link-table mapper.
	 *
	 * @var CalendarLinkMapper&MockObject
	 */
	private CalendarLinkMapper&MockObject $linkMapper;

	/**
	 * The legacy CalDAV-property service.
	 *
	 * @var CalendarEventService&MockObject
	 */
	private CalendarEventService&MockObject $calendarEventService;

	/**
	 * The CalDAV backend.
	 *
	 * @var CalDavBackend&MockObject
	 */
	private CalDavBackend&MockObject $calDavBackend;

	/**
	 * The service under test.
	 *
	 * @var CalendarLinkService
	 */
	private CalendarLinkService $service;

	protected function setUp(): void {
		$this->linkMapper = $this->createMock(CalendarLinkMapper::class);
		$this->calendarEventService = $this->createMock(CalendarEventService::class);
		$this->calDavBackend = $this->createMock(CalDavBackend::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->service = new CalendarLinkService(
			$this->linkMapper,
			$this->calendarEventService,
			$this->calDavBackend,
			new LinkSessionRuntime($session, $this->createMock(LoggerInterface::class))
		);
	}

	private function existingLink(): CalendarLink {
		$link = new CalendarLink();
		$link->setId(11);
		$link->setObjectUuid('obj-1');
		$link->setEventUid('E1');
		$link->setCalendarUri('personal');
		return $link;
	}

	public function testARepeatedLinkReturnsTheExistingRowAndInsertsNothing(): void {
		$existing = $this->existingLink();
		$this->linkMapper->expects($this->once())->method('findLink')
			->with('obj-1', 'E1', 'personal')
			->willReturn($existing);
		$this->linkMapper->expects($this->never())->method('insert');
		$this->calDavBackend->expects($this->never())->method('getCalendarsForUser');

		$result = $this->service->linkLocatedEvent(
			target: (new LinkTarget(objectUuid: 'obj-1', registerId: 1))->inSchema(schemaId: 2),
			key: new CalendarEventKey(calendarUri: 'personal', eventUid: 'E1')
		);

		$this->assertSame($existing, $result);
	}

	public function testAFirstLinkInsertsARow(): void {
		$this->linkMapper->method('findLink')->willReturn(null);
		$this->calDavBackend->method('getCalendarsForUser')->willReturn([['id' => 7, 'uri' => 'personal']]);
		$this->calDavBackend->method('getCalendarObjects')->willReturn([['uri' => 'e1.ics']]);
		$this->calDavBackend->method('getCalendarObject')->willReturn([
			'calendardata' => implode(
				"\r\n",
				['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//T//EN', 'BEGIN:VEVENT', 'UID:E1', 'DTSTAMP:20260101T000000Z',
					'SUMMARY:Kickoff', 'DTSTART:20260601T100000Z', 'END:VEVENT', 'END:VCALENDAR', '',
				]
			),
		]);
		$this->linkMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$result = $this->service->linkLocatedEvent(
			target: (new LinkTarget(objectUuid: 'obj-1', registerId: 1))->inSchema(schemaId: 2),
			key: new CalendarEventKey(calendarUri: 'personal', eventUid: 'E1')
		);

		$this->assertSame('Kickoff', $result->getSummary());
	}

	public function testCreatingAnEventAlreadyLinkedReturnsTheExistingRow(): void {
		$existing = $this->existingLink();
		$this->calendarEventService->method('createEvent')->willReturn([
			'id' => 'e1.ics',
			'uid' => 'E1',
			'calendarId' => '7',
			'summary' => 'New meeting',
		]);
		$this->calDavBackend->method('getCalendarsForUser')->willReturn([['id' => 7, 'uri' => 'personal']]);
		$this->linkMapper->expects($this->once())->method('findLink')
			->with('obj-1', 'E1', 'personal')
			->willReturn($existing);
		$this->linkMapper->expects($this->never())->method('insert');

		$result = $this->service->createLinkedEvent((new LinkTarget('obj-1', 1))->inSchema(2), ['summary' => 'New meeting']);

		$this->assertSame($existing, $result);
	}

	public function testGetObjectsForEventReturnsTheRowsOfTheEvent(): void {
		$rows = [$this->existingLink()];
		$this->linkMapper->expects($this->exactly(2))->method('findByEventUid')
			->willReturnCallback(
				static function (string $eventUid, ?string $calendarUri=null) use ($rows): array {
					if ($eventUid === 'E1' && in_array($calendarUri, [null, 'personal'], true) === true) {
						return $rows;
					}

					return [];
				}
			);

		$this->assertSame($rows, $this->service->getObjectsForEvent('E1'));
		$this->assertSame($rows, $this->service->getObjectsForEvent('E1', 'personal'));
	}

	public function testGetObjectsForEventIgnoresABlankUid(): void {
		$this->linkMapper->expects($this->never())->method('findByEventUid');

		$this->assertSame([], $this->service->getObjectsForEvent('  '));
	}
}//end class
