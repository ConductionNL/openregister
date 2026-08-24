<?php

/**
 * Unit tests for app-defined trigger events.
 *
 * An app that raises its own event — "booking.confirmed", "generation.draft" —
 * can have schema-declared notifications fire for it, without the engine
 * needing to know what a booking is. See ConductionNL/shillinq#1193.
 *
 * `matches()` is private, so these tests exercise it through reflection: the
 * alternative is a full dispatcher with a container, which would test the
 * wiring rather than the matching rule under scrutiny here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AppDefinedTriggerEventTest extends TestCase {

	/**
	 * Invoke the dispatcher's private matches() without constructing it.
	 *
	 * @param mixed  $triggerSpec The declared trigger.
	 * @param string $trigger     The active event.
	 *
	 * @return bool Whether the rule matches.
	 */
	private function triggerMatches($triggerSpec, string $trigger): bool {
		$method = new ReflectionMethod(AnnotationNotificationDispatcher::class, 'matches');
		$method->setAccessible(true);

		$dispatcher = (new \ReflectionClass(AnnotationNotificationDispatcher::class))->newInstanceWithoutConstructor();

		return (bool) $method->invoke($dispatcher, $triggerSpec, $trigger, []);
	}

	public function testABareStringTriggerMatchesItsOwnEvent(): void {
		self::assertTrue($this->triggerMatches('booking.confirmed', 'booking.confirmed'));
	}

	public function testABareStringTriggerIgnoresOtherEvents(): void {
		self::assertFalse($this->triggerMatches('booking.confirmed', 'booking.cancelled'));
		self::assertFalse($this->triggerMatches('booking.confirmed', 'created'));
	}

	public function testTheObjectFormMayNameAnAppEvent(): void {
		self::assertTrue($this->triggerMatches(['event' => 'generation.draft'], 'generation.draft'));
		self::assertFalse($this->triggerMatches(['event' => 'generation.draft'], 'generation.indexation'));
	}

	public function testAnAppEventRuleDoesNotMatchAReservedTrigger(): void {
		// The whole point of namespacing: raising `created` must never fire a
		// rule that declared an app event, and vice versa.
		self::assertFalse($this->triggerMatches('booking.created', 'created'));
		self::assertFalse($this->triggerMatches(['type' => 'created'], 'booking.created'));
	}

	public function testReservedTriggerTypesAreUnaffected(): void {
		self::assertTrue($this->triggerMatches(['type' => 'created'], 'created'));
		self::assertTrue($this->triggerMatches(['type' => 'updated'], 'updated'));
		self::assertFalse($this->triggerMatches(['type' => 'updated'], 'created'));
	}

	public function testAStringTriggerNoLongerReachesAnArrayParameter(): void {
		// Before this change `matches()` declared `array $triggerSpec`, so a
		// string trigger threw a TypeError mid-dispatch under strict_types
		// rather than simply not matching — taking the rest of the schema's
		// notifications down with it.
		self::assertFalse($this->triggerMatches('booking.confirmed', 'updated'));
	}

	public function testANonArrayNonStringTriggerFailsClosed(): void {
		self::assertFalse($this->triggerMatches(null, 'created'));
		self::assertFalse($this->triggerMatches(42, 'created'));
	}

}//end class
