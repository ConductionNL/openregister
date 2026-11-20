<?php

/**
 * ListenerBootstrap binds the presence push and registers the ordered listener list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\DecisionTableValidationListener;
use OCA\OpenRegister\Listener\DecisionTableValidationListenerInterface;
use OCA\OpenRegister\Listener\ListenerBootstrap;
use OCA\OpenRegister\Listener\ListenerRegistrar;
use OCA\OpenRegister\Listener\NotifyPushListener;
use OCA\OpenRegister\Listener\PresencePushInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Listener\ListenerBootstrap
 */
final class ListenerBootstrapTest extends TestCase {

	/**
	 * The bootstrap binds only the presence push; the Processes listener interface is the registrar's.
	 *
	 * @return void
	 */
	public function testItBindsOnlyThePresencePush(): void {
		$this->assertSame([PresencePushInterface::class => NotifyPushListener::class], ListenerBootstrap::BINDINGS);
		$this->assertSame(DecisionTableValidationListener::class, ListenerRegistrar::BINDINGS[DecisionTableValidationListenerInterface::class]);
	}//end testItBindsOnlyThePresencePush()

	/**
	 * Registering aliases both interfaces, the presence push before any listener.
	 *
	 * @return void
	 */
	public function testRegisterAliasesBothInterfaces(): void {
		$context = new RecordingRegistrationContext();
		ListenerBootstrap::register(context: $context);

		$this->assertSame(['alias' => PresencePushInterface::class, 'target' => NotifyPushListener::class], $context->aliases[0]);
		$this->assertContains(['alias' => DecisionTableValidationListenerInterface::class, 'target' => DecisionTableValidationListener::class], $context->aliases);
	}//end testRegisterAliasesBothInterfaces()

	/**
	 * The listeners are the registrar's, in the registrar's order.
	 *
	 * @return void
	 */
	public function testItRegistersTheRegistrarsListenersInOrder(): void {
		$viaBootstrap = new RecordingRegistrationContext();
		ListenerBootstrap::register(context: $viaBootstrap);
		$direct = new RecordingRegistrationContext();
		ListenerRegistrar::register(context: $direct);

		$this->assertNotSame([], $direct->listeners);
		$this->assertSame($direct->listeners, $viaBootstrap->listeners);
	}//end testItRegistersTheRegistrarsListenersInOrder()
}//end class
