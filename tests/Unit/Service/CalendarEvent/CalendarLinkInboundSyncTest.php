<?php

/**
 * Unit tests for CalendarLinkInboundSync: refresh, move, create and delete of link rows.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\CalendarEvent
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/calendar-leaf-inbound/specs/integration-calendar/spec.md#requirement-inbound-calendar-changes-refresh-the-link-table-req-cal-inb-001
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\CalendarEvent;

use DateTime;
use OCA\OpenRegister\Db\CalendarLink;
use OCA\OpenRegister\Db\CalendarLinkMapperInterface;
use OCA\OpenRegister\Service\CalendarEvent\CalendarLinkInboundSync;
use OCA\OpenRegister\Service\CalendarEvent\InboundCalendarObject;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(CalendarLinkInboundSync::class)]
#[UsesClass(InboundCalendarObject::class)]
#[UsesClass(CalendarLink::class)]
class CalendarLinkInboundSyncTest extends TestCase {

	/** @var CalendarLinkMapperInterface&MockObject */
	private CalendarLinkMapperInterface $mapper;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var array<int, CalendarLink> */
	private array $updated = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(CalendarLinkMapperInterface::class);
		$this->mapper->method('update')->willReturnCallback(
			function (Entity $entity): Entity {
				$this->updated[] = $entity;
				return $entity;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function sync(): CalendarLinkInboundSync {
		return new CalendarLinkInboundSync($this->mapper, $this->logger);
	}

	private static function ics(string $uid, string $start, string $end, array $extra = []): string {
		$lines = array_merge(
			['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//EN', 'BEGIN:VEVENT', 'UID:'.$uid, 'DTSTAMP:20260101T000000Z', 'DTSTART:'.$start, 'DTEND:'.$end],
			$extra,
			['END:VEVENT', 'END:VCALENDAR']
		);
		return implode("\r\n", $lines)."\r\n";
	}

	private static function link(int $id, string $object, ?int $calendarId, string $calendarUri = 'personal'): CalendarLink {
		$link = new CalendarLink();
		$link->setId($id);
		$link->setObjectUuid($object);
		$link->setEventUid('E1');
		$link->setCalendarId($calendarId);
		$link->setCalendarUri($calendarUri);
		$link->setEventUri('old.ics');
		$link->setSummary('Old');
		$link->setDtstart(new DateTime('2026-05-01 10:00:00'));
		return $link;
	}

	public function testAMovedMeetingRefreshesTheTimesOfEveryLinkedObject(): void {
		$rows = [self::link(1, 'O1', 7), self::link(2, 'O2', 7)];
		$this->mapper->expects($this->once())->method('findByEventUid')->with('E1')->willReturn($rows);

		$object = new InboundCalendarObject(
			7,
			'personal',
			'e1.ics',
			self::ics('E1', '20260501T140000Z', '20260501T150000Z', ['SUMMARY:Intake', 'LOCATION:Room 2'])
		);

		$this->assertSame(2, $this->sync()->refresh($object, $object));

		$this->assertSame($rows, $this->updated);
		foreach ($rows as $row) {
			$this->assertSame('2026-05-01T14:00:00+00:00', $row->getDtstart()->format('c'));
			$this->assertSame('2026-05-01T15:00:00+00:00', $row->getDtend()->format('c'));
			$this->assertSame('Intake', $row->getSummary());
			$this->assertSame('Room 2', $row->getLocation());
			$this->assertSame('e1.ics', $row->getEventUri());
		}
	}

	public function testRowsOnAnotherCalendarAreLeftAlone(): void {
		$here       = self::link(1, 'O1', 7);
		$elsewhere  = self::link(2, 'O2', 8, 'work');
		$legacyHere = self::link(3, 'O3', null, 'personal');
		$legacyNone = self::link(4, 'O4', null, '');
		$legacyElse = self::link(5, 'O5', 0, 'work');
		$this->mapper->method('findByEventUid')->willReturn([$here, $elsewhere, $legacyHere, $legacyNone, $legacyElse]);

		$object = new InboundCalendarObject(7, 'personal', 'e1.ics', self::ics('E1', '20260501T140000Z', '20260501T150000Z'));

		$this->assertSame(3, $this->sync()->refresh($object, $object));
		$this->assertSame([$here, $legacyHere, $legacyNone], $this->updated);
		$this->assertSame('Old', $elsewhere->getSummary());
	}

	public function testAMoveMatchesOnTheSourceCalendarAndRecordsTheTarget(): void {
		$row = self::link(1, 'O1', 7);
		$this->mapper->method('findByEventUid')->willReturn([$row, self::link(2, 'O2', 9, 'team')]);

		$text   = self::ics('E1', '20260501T140000Z', '20260501T150000Z');
		$source = new InboundCalendarObject(7, 'personal', 'e1.ics', $text);
		$target = new InboundCalendarObject(9, 'team', 'e1.ics', $text);

		$this->assertSame(1, $this->sync()->refresh($target, $source));
		$this->assertSame([$row], $this->updated);
		$this->assertSame(9, $row->getCalendarId());
		$this->assertSame('team', $row->getCalendarUri());
	}

	public function testAnEventWithoutRowsIsNotParsed(): void {
		$this->mapper->method('findByEventUid')->willReturn([]);
		$this->mapper->expects($this->never())->method('update');

		// Unparseable text: proves no parse is attempted when nothing matches.
		$object = new InboundCalendarObject(7, 'personal', 'x.ics', "UID:E1\r\nnot ical");

		$this->assertSame(0, $this->sync()->refresh($object, $object));
	}

	public function testAnObjectWithoutAUidQueriesNothing(): void {
		$this->mapper->expects($this->never())->method('findByEventUid');

		$object = new InboundCalendarObject(7, 'personal', 'x.ics', "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

		$this->assertSame(0, $this->sync()->refresh($object, $object));
		$this->assertSame(0, $this->sync()->remove($object));
	}

	public function testATaskWithALinkedUidIsNotTreatedAsAnEvent(): void {
		$this->mapper->method('findByEventUid')->willReturn([self::link(1, 'O1', 7)]);
		$this->mapper->expects($this->never())->method('update');

		$todo = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//T//EN\r\nBEGIN:VTODO\r\nUID:E1\r\nDTSTAMP:20260101T000000Z\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

		$this->assertSame(0, $this->sync()->refresh(new InboundCalendarObject(7, 'personal', 't.ics', $todo), new InboundCalendarObject(7, 'personal', 't.ics', $todo)));
	}

	public function testOneRowThatCannotBeSavedDoesNotStopTheOthers(): void {
		$rows   = [self::link(1, 'O1', 7), self::link(2, 'O2', 7)];
		$this->mapper->method('findByEventUid')->willReturn($rows);
		$mapper = $this->createMock(CalendarLinkMapperInterface::class);
		$mapper->method('findByEventUid')->willReturn($rows);
		$saved  = [];
		$mapper->method('update')->willReturnCallback(
			static function (Entity $entity) use (&$saved): Entity {
				if ($entity->getObjectUuid() === 'O1') {
					throw new RuntimeException('constraint');
				}

				$saved[] = $entity->getObjectUuid();
				return $entity;
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with($this->equalTo('Calendar link refresh failed for event E1: constraint'));

		$object = new InboundCalendarObject(7, 'personal', 'e1.ics', self::ics('E1', '20260501T140000Z', '20260501T150000Z'));

		$this->assertSame(1, (new CalendarLinkInboundSync($mapper, $this->logger))->refresh($object, $object));
		$this->assertSame(['O2'], $saved);
	}

	public function testATaggedCreatedEventGainsItsLinkRow(): void {
		$this->mapper->expects($this->once())->method('findLink')->with('O1', 'E1', 'personal')->willReturn(null);
		$inserted = null;
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Entity $entity) use (&$inserted): Entity {
				$inserted = $entity;
				return $entity;
			}
		);

		$object = new InboundCalendarObject(
			7,
			'personal',
			'e1.ics',
			self::ics('E1', '20260501T140000Z', '20260501T150000Z', ['SUMMARY:Intake', 'X-OPENREGISTER-REGISTER:4', 'X-OPENREGISTER-SCHEMA:5', 'X-OPENREGISTER-OBJECT:O1'])
		);

		$this->assertTrue($this->sync()->ensureLinked($object));
		$this->assertInstanceOf(CalendarLink::class, $inserted);
		$this->assertSame('O1', $inserted->getObjectUuid());
		$this->assertSame(4, $inserted->getRegisterId());
		$this->assertSame(5, $inserted->getSchemaId());
		$this->assertSame('E1', $inserted->getEventUid());
		$this->assertSame('personal', $inserted->getCalendarUri());
		$this->assertSame(7, $inserted->getCalendarId());
		$this->assertSame('e1.ics', $inserted->getEventUri());
		$this->assertSame('Intake', $inserted->getSummary());
		$this->assertSame('2026-05-01T14:00:00+00:00', $inserted->getDtstart()->format('c'));
		$this->assertSame('system:calendar', $inserted->getLinkedBy());
		$this->assertInstanceOf(DateTime::class, $inserted->getLinkedAt());
		$this->assertTrue($inserted->getTaggedWithXor());
	}

	public function testAnExistingLinkIsNotInsertedTwice(): void {
		$this->mapper->method('findLink')->willReturn(self::link(1, 'O1', 7));
		$this->mapper->expects($this->never())->method('insert');

		$object = new InboundCalendarObject(7, 'personal', 'e1.ics', self::ics('E1', '20260501T140000Z', '20260501T150000Z', ['X-OPENREGISTER-OBJECT:O1']));

		$this->assertFalse($this->sync()->ensureLinked($object));
	}

	public function testAnUntaggedOrIncompleteEventIsNotLinked(): void {
		$this->mapper->expects($this->never())->method('findLink');
		$this->mapper->expects($this->never())->method('insert');

		$untagged = new InboundCalendarObject(7, 'personal', 'e1.ics', self::ics('E1', '20260501T140000Z', '20260501T150000Z'));
		$blankTag = new InboundCalendarObject(7, 'personal', 'e1.ics', self::ics('E1', '20260501T140000Z', '20260501T150000Z', ['X-OPENREGISTER-OBJECT: ']));
		$garbage  = new InboundCalendarObject(7, 'personal', 'e1.ics', "X-OPENREGISTER-OBJECT:O1\r\nnot ical at all");

		$this->assertFalse($this->sync()->ensureLinked($untagged));
		$this->assertFalse($this->sync()->ensureLinked($blankTag));
		$this->assertFalse($this->sync()->ensureLinked($garbage));
	}

	public function testALostInsertRaceIsNotAnError(): void {
		$this->mapper->method('findLink')->willReturn(null);
		$this->mapper->method('insert')->willThrowException(new RuntimeException('unique violation'));
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->once())->method('info')
			->with($this->equalTo('Calendar link for event E1 not inserted: unique violation'));

		$object = new InboundCalendarObject(7, 'personal', 'e1.ics', self::ics('E1', '20260501T140000Z', '20260501T150000Z', ['X-OPENREGISTER-OBJECT:O1']));

		$this->assertFalse($this->sync()->ensureLinked($object));
	}

	public function testADeletedEventRemovesItsRowsOnThatCalendarOnly(): void {
		$here      = self::link(1, 'O1', 7);
		$elsewhere = self::link(2, 'O2', 8, 'work');
		$this->mapper->method('findByEventUid')->with('E1')->willReturn([$here, $elsewhere]);
		$deleted = [];
		$this->mapper->method('delete')->willReturnCallback(
			static function (Entity $entity) use (&$deleted): Entity {
				$deleted[] = $entity;
				return $entity;
			}
		);

		$this->assertSame(1, $this->sync()->remove(new InboundCalendarObject(7, 'personal', 'e1.ics', "UID:E1\r\n")));
		$this->assertSame([$here], $deleted);
	}

	public function testADeleteFailureIsLoggedAndTheRestStillGo(): void {
		$this->mapper->method('findByEventUid')->willReturn([self::link(1, 'O1', 7), self::link(2, 'O2', 7)]);
		$this->mapper->method('delete')->willReturnCallback(
			static function (Entity $entity): Entity {
				if ($entity->getObjectUuid() === 'O1') {
					throw new RuntimeException('locked');
				}

				return $entity;
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with($this->equalTo('Calendar link delete failed for event E1: locked'));

		$this->assertSame(1, $this->sync()->remove(new InboundCalendarObject(7, 'personal', 'e1.ics', "UID:E1\r\n")));
	}
}
