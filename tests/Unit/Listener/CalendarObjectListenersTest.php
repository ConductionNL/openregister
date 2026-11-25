<?php

/**
 * Unit tests for the inbound calendar-link listeners: routing and failure isolation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
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

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\CalendarObjectChangedListener;
use OCA\OpenRegister\Listener\CalendarObjectDeletedListener;
use OCA\OpenRegister\Service\CalendarEvent\CalendarLinkInboundSync;
use OCA\OpenRegister\Service\CalendarEvent\InboundCalendarObject;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectRestoredEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(CalendarObjectChangedListener::class)]
#[CoversClass(CalendarObjectDeletedListener::class)]
#[UsesClass(InboundCalendarObject::class)]
class CalendarObjectListenersTest extends TestCase {

	/** @var CalendarLinkInboundSync&MockObject */
	private CalendarLinkInboundSync $sync;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var array<int, string> */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();
		$this->sync = $this->createMock(CalendarLinkInboundSync::class);
		$describe   = static fn (InboundCalendarObject $o): string => $o->calendarId.'/'.$o->calendarUri.'/'.$o->eventUri;
		$this->sync->method('refresh')->willReturnCallback(
			function (InboundCalendarObject $object, InboundCalendarObject $source) use ($describe): int {
				$this->calls[] = 'refresh '.$describe($object).' from '.$describe($source);
				return 1;
			}
		);
		$this->sync->method('ensureLinked')->willReturnCallback(
			function (InboundCalendarObject $object) use ($describe): bool {
				$this->calls[] = 'ensure '.$describe($object);
				return true;
			}
		);
		$this->sync->method('remove')->willReturnCallback(
			function (InboundCalendarObject $object) use ($describe): int {
				$this->calls[] = 'remove '.$describe($object);
				return 1;
			}
		);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function changed(): CalendarObjectChangedListener {
		return new CalendarObjectChangedListener($this->sync, $this->logger);
	}

	private function deleted(): CalendarObjectDeletedListener {
		return new CalendarObjectDeletedListener($this->sync, $this->logger);
	}

	private static function payload(string $class): Event {
		return new $class(7, ['uri' => 'personal'], [], ['uri' => 'e1.ics', 'calendardata' => "UID:E1\r\n"]);
	}

	public function testAnUpdateRefreshesInPlace(): void {
		$this->changed()->handle(self::payload(CalendarObjectUpdatedEvent::class));

		$this->assertSame(['refresh 7/personal/e1.ics from 7/personal/e1.ics'], $this->calls);
	}

	public function testACreateOrRestoreEnsuresTheLinkThenRefreshes(): void {
		$this->changed()->handle(self::payload(CalendarObjectCreatedEvent::class));
		$this->changed()->handle(self::payload(CalendarObjectRestoredEvent::class));

		$this->assertSame(
			[
				'ensure 7/personal/e1.ics',
				'refresh 7/personal/e1.ics from 7/personal/e1.ics',
				'ensure 7/personal/e1.ics',
				'refresh 7/personal/e1.ics from 7/personal/e1.ics',
			],
			$this->calls
		);
	}

	public function testAMoveRefreshesFromTheSourceOntoTheTarget(): void {
		$event = new CalendarObjectMovedEvent(7, ['uri' => 'personal'], 9, ['uri' => 'team'], [], [], ['uri' => 'e1.ics', 'calendardata' => "UID:E1\r\n"]);

		$this->changed()->handle($event);

		$this->assertSame(['refresh 9/team/e1.ics from 7/personal/e1.ics'], $this->calls);
	}

	public function testTheChangedListenerIgnoresDeletionsAndForeignEvents(): void {
		$this->changed()->handle(self::payload(CalendarObjectDeletedEvent::class));
		$this->changed()->handle(self::payload(CalendarObjectMovedToTrashEvent::class));
		$this->changed()->handle(new Event());

		$this->assertSame([], $this->calls);
	}

	public function testADeleteOrTrashRemovesTheLinks(): void {
		$this->deleted()->handle(self::payload(CalendarObjectDeletedEvent::class));
		$this->deleted()->handle(self::payload(CalendarObjectMovedToTrashEvent::class));

		$this->assertSame(['remove 7/personal/e1.ics', 'remove 7/personal/e1.ics'], $this->calls);
	}

	public function testTheDeletedListenerIgnoresEverythingElse(): void {
		$this->deleted()->handle(self::payload(CalendarObjectUpdatedEvent::class));
		$this->deleted()->handle(self::payload(CalendarObjectCreatedEvent::class));
		$this->deleted()->handle(new Event());

		$this->assertSame([], $this->calls);
	}

	public function testAFailingRefreshIsLoggedNeverThrown(): void {
		$sync = $this->createMock(CalendarLinkInboundSync::class);
		$sync->method('refresh')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->equalTo('[CalendarObjectChangedListener] Calendar link refresh failed: db down'));

		(new CalendarObjectChangedListener($sync, $this->logger))->handle(self::payload(CalendarObjectUpdatedEvent::class));
	}

	public function testAFailingRemovalIsLoggedNeverThrown(): void {
		$sync = $this->createMock(CalendarLinkInboundSync::class);
		$sync->method('remove')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->equalTo('[CalendarObjectDeletedListener] Calendar link removal failed: db down'));

		(new CalendarObjectDeletedListener($sync, $this->logger))->handle(self::payload(CalendarObjectDeletedEvent::class));
	}
}
