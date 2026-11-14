<?php

/**
 * Unit tests for the inbound calendar-link listener registration in WorkListenerRegistrar.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener\Registrar
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

namespace OCA\OpenRegister\Tests\Unit\Listener\Registrar;

use OCA\OpenRegister\Listener\CalendarObjectChangedListener;
use OCA\OpenRegister\Listener\CalendarObjectDeletedListener;
use OCA\OpenRegister\Listener\Registrar\WorkListenerRegistrar;
use OCA\OpenRegister\Listener\TaskVtodoWriteBackListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectRestoredEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkListenerRegistrar::class)]
class WorkListenerRegistrarCalendarLinkTest extends TestCase {

	public function testTheWriteBackHookComesFirstThenEveryCalendarLinkListener(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		WorkListenerRegistrar::register($context);

		$this->assertSame(
			[
				[CalendarObjectUpdatedEvent::class, TaskVtodoWriteBackListener::class],
				[CalendarObjectDeletedEvent::class, TaskVtodoWriteBackListener::class],
				[CalendarObjectCreatedEvent::class, CalendarObjectChangedListener::class],
				[CalendarObjectUpdatedEvent::class, CalendarObjectChangedListener::class],
				[CalendarObjectMovedEvent::class, CalendarObjectChangedListener::class],
				[CalendarObjectRestoredEvent::class, CalendarObjectChangedListener::class],
				[CalendarObjectDeletedEvent::class, CalendarObjectDeletedListener::class],
				[CalendarObjectMovedToTrashEvent::class, CalendarObjectDeletedListener::class],
			],
			$registered
		);
	}

	public function testAnAbsentEventClassIsSkipped(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerEventListener')
			->with(CalendarObjectUpdatedEvent::class, CalendarObjectChangedListener::class);

		$count = WorkListenerRegistrar::registerPresent(
			$context,
			[
				'OCA\\DAV\\Events\\NoSuchCalendarEvent' => CalendarObjectChangedListener::class,
				CalendarObjectUpdatedEvent::class => CalendarObjectChangedListener::class,
			]
		);

		$this->assertSame(1, $count);
	}
}
