<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Event\RegistrySubscriptionEndedEvent;
use OCA\OpenRegister\Event\RegistrySubscriptionRequestedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * Pins what a connector reads off the registry subscription events.
 */
class RegistrySubscriptionEventsGoldenTest extends TestCase {
	private function argumentsOf(string $class): array {
		return array_map(
			static fn (ReflectionParameter $parameter): string => $parameter->getName(),
			(new ReflectionClass($class))->getConstructor()->getParameters()
		);
	}//end argumentsOf()

	public function testARequestCarriesItsContext(): void {
		$event = new RegistrySubscriptionRequestedEvent(objectUuid: 'o-1', register: 'r', schema: 's', registry: 'kvk', identityValue: '123');

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame('o-1', $event->getObjectUuid());
		$this->assertSame('r', $event->getRegister());
		$this->assertSame('s', $event->getSchema());
		$this->assertSame('kvk', $event->getRegistry());
		$this->assertSame('123', $event->getIdentityValue());
	}//end testARequestCarriesItsContext()

	public function testThePayloadKeepsItsKeysAndOrder(): void {
		$event = new RegistrySubscriptionRequestedEvent('o-1', 'r', 's', 'kvk', '123');

		$this->assertSame(
			['objectUuid' => 'o-1', 'register' => 'r', 'schema' => 's', 'registry' => 'kvk', 'identityValue' => '123'],
			$event->getPayload()
		);
	}//end testThePayloadKeepsItsKeysAndOrder()

	public function testAnEndCarriesTheSubscription(): void {
		$event = new RegistrySubscriptionEndedEvent(objectUuid: 'o-1', registry: 'kvk', identityValue: '123');

		$this->assertInstanceOf(Event::class, $event);
		$this->assertSame('o-1', $event->getObjectUuid());
		$this->assertSame('kvk', $event->getRegistry());
		$this->assertSame('123', $event->getIdentityValue());
	}//end testAnEndCarriesTheSubscription()

	public function testTheArgumentsStayInOrder(): void {
		$this->assertSame(['objectUuid', 'register', 'schema', 'registry', 'identityValue'], $this->argumentsOf(class: RegistrySubscriptionRequestedEvent::class));
		$this->assertSame(['objectUuid', 'registry', 'identityValue'], $this->argumentsOf(class: RegistrySubscriptionEndedEvent::class));
	}//end testTheArgumentsStayInOrder()

	public function testNeitherEventIsTheOther(): void {
		$this->assertFalse(is_subclass_of(RegistrySubscriptionEndedEvent::class, RegistrySubscriptionRequestedEvent::class));
		$this->assertFalse(is_subclass_of(RegistrySubscriptionRequestedEvent::class, RegistrySubscriptionEndedEvent::class));
	}//end testNeitherEventIsTheOther()
}//end class
