<?php

/**
 * Unit tests for the inbound calendar-link listener registration.
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

use OCA\OpenRegister\Listener\CalendarObjectChangedListener;
use OCA\OpenRegister\Listener\CalendarObjectDeletedListener;
use OCA\OpenRegister\Listener\Registrar\WorkListenerRegistrar;
use OCA\OpenRegister\Listener\TaskVtodoWriteBackListener;
use OCA\OpenRegister\Service\CalendarEvent\CalendarLinkListenerRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectMovedEvent;
use OCP\Calendar\Events\CalendarObjectMovedToTrashEvent;
use OCP\Calendar\Events\CalendarObjectRestoredEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CalendarLinkListenerRegistrar::class)]
#[UsesClass(WorkListenerRegistrar::class)]
class CalendarLinkListenerRegistrarTest extends TestCase {

	public function testEveryCalendarLinkListenerIsRegisteredBelowTheDefaultPriority(): void {
		$registered = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[] = [$event, $listener, $priority];
			}
		);

		CalendarLinkListenerRegistrar::register($context);

		$this->assertSame(
			[
				[CalendarObjectCreatedEvent::class, CalendarObjectChangedListener::class, -1],
				[CalendarObjectUpdatedEvent::class, CalendarObjectChangedListener::class, -1],
				[CalendarObjectMovedEvent::class, CalendarObjectChangedListener::class, -1],
				[CalendarObjectRestoredEvent::class, CalendarObjectChangedListener::class, -1],
				[CalendarObjectDeletedEvent::class, CalendarObjectDeletedListener::class, -1],
				[CalendarObjectMovedToTrashEvent::class, CalendarObjectDeletedListener::class, -1],
			],
			$registered
		);
	}

	public function testTheWriteBackHookRunsBeforeTheLinkListenersOnTheSharedEvents(): void {
		$priorities = [];
		$context    = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$priorities): void {
				$priorities[$event][$listener] = $priority;
			}
		);

		// Link listeners first, to show the order no longer depends on which registrar runs first.
		CalendarLinkListenerRegistrar::register($context);
		WorkListenerRegistrar::register($context);

		foreach ([CalendarObjectUpdatedEvent::class => CalendarObjectChangedListener::class, CalendarObjectDeletedEvent::class => CalendarObjectDeletedListener::class] as $event => $link) {
			// A higher priority is triggered earlier.
			$this->assertGreaterThan($priorities[$event][$link], $priorities[$event][TaskVtodoWriteBackListener::class]);
		}
	}

	public function testAnAbsentEventClassIsSkipped(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerEventListener')
			->with(CalendarObjectUpdatedEvent::class, CalendarObjectChangedListener::class, CalendarLinkListenerRegistrar::PRIORITY);

		$count = CalendarLinkListenerRegistrar::registerPresent(
			$context,
			[
				'OCA\\DAV\\Events\\NoSuchCalendarEvent' => CalendarObjectChangedListener::class,
				CalendarObjectUpdatedEvent::class => CalendarObjectChangedListener::class,
			]
		);

		$this->assertSame(1, $count);
	}
}
