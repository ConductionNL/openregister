<?php

/**
 * The listener registrar registers the same listeners, on the same events, in the same order.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\ListenerRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact registration sequence.
 *
 * Listener order on one event is behaviour: a preflight registered first refuses
 * before any other listener works, a withdrawal runs before a delivery. The
 * fingerprint below was taken from the registrar before its methods were split,
 * so any reordering, addition or removal shows up here as a changed sequence.
 */
class ListenerRegistrationOrderGoldenTest extends TestCase {

	/**
	 * The number of event listener registrations.
	 */
	private const LISTENER_COUNT = 266;

	/**
	 * The sha256 of every registration call, one `method|arg|arg...` line each, in order.
	 *
	 * Last changed when Search started contributing the widget declaration check: one
	 * SearchDeclarationChecksListener registration appended to the SchemaDeclarationChecksEvent
	 * listeners; every other registration is unchanged and in the same order. Then two
	 * TaskAbsenceListener registrations (OutOfOfficeStartedEvent, OutOfOfficeEndedEvent). The platform
	 * share provider then appended one PlatformShareRoutingListener registration on
	 * BeforeShareCreatedEvent in AccessListenerRegistrar. Then the ServiceAccountLoginListener
	 * (PostLoginEvent) was added (count 258 -> 259). The new-notes notification then added
	 * one AnnotationNotificationListener registration on ObjectNoteAddedEvent (count 259 -> 260).
	 * Then SlotOccupantAuditListener took the three person-link events in the Access slot
	 * (grants-that-follow-a-slot-a-relation-or-a-reason, count 260 -> 263).
	 * Then three FlowCalendarIndexListener registrations after FlowTriggerListener on the object
	 * created, updated and deleted events (263 -> 266).
	 */
	private const SEQUENCE_SHA256 = '51dc01bc6acd92efb171e3f8dd3f6658d527318a27581b24fd7e2936caabb3a4';

	/**
	 * The registration calls, in call order, with every argument.
	 *
	 * @return list<string>
	 */
	private function recordedSequence(): array {
		$calls   = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener, int $priority = 0) use (&$calls): void {
				$calls[] = 'registerEventListener|'.$event.'|'.$listener.'|'.$priority;
			}
		);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$calls): void {
				$calls[] = 'registerServiceAlias|'.$alias.'|'.$target;
			}
		);

		ListenerRegistrar::register(context: $context);

		return $calls;
	}//end recordedSequence()

	/**
	 * Every registration happens, in the same order, with the same arguments.
	 *
	 * @return void
	 */
	public function testTheRegistrationSequenceIsUnchanged(): void {
		$calls     = $this->recordedSequence();
		$listeners = array_filter(
			$calls,
			static fn (string $call): bool => str_starts_with($call, 'registerEventListener|')
		);

		$this->assertCount(self::LISTENER_COUNT, $listeners);
		$this->assertSame(self::SEQUENCE_SHA256, hash('sha256', implode("\n", $calls)));
	}//end testTheRegistrationSequenceIsUnchanged()
}//end class
