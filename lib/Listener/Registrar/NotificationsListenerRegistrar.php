<?php

/**
 * NotificationsListenerRegistrar: the event listeners of the Notifications component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Registrar;

use OCA\OpenRegister\Event\AgentCreatedEvent;
use OCA\OpenRegister\Event\AgentUpdatedEvent;
use OCA\OpenRegister\Event\ConfigurationCreatedEvent;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Listener\SystemEntityNotificationListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the listeners of the Notifications component on events no other component listens to.
 *
 * An event with listeners from more than one component stays in
 * ListenerRegistrar, in one ordered list, because listeners of one event run
 * in registration order.
 */
final class NotificationsListenerRegistrar {

	/**
	 * Register the listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; the listeners carry their own specs.
	 */
	public static function register(IRegistrationContext $context): void {
		// System-entity notification bridge — routes create/update signals from
		// OpenRegister's own system entities through the same annotation-notification
		// pipeline used for stored register objects (spec: openregister-system-notifications).
		$context->registerEventListener(ConfigurationCreatedEvent::class, SystemEntityNotificationListener::class);
		$context->registerEventListener(ConfigurationUpdatedEvent::class, SystemEntityNotificationListener::class);
		$context->registerEventListener(SourceCreatedEvent::class, SystemEntityNotificationListener::class);
		$context->registerEventListener(SourceUpdatedEvent::class, SystemEntityNotificationListener::class);
		$context->registerEventListener(AgentCreatedEvent::class, SystemEntityNotificationListener::class);
		$context->registerEventListener(AgentUpdatedEvent::class, SystemEntityNotificationListener::class);
	}//end register()
}//end class
