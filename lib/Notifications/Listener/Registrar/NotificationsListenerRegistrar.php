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

namespace OCA\OpenRegister\Notifications\Listener\Registrar;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body lives in NotificationsListenerWiring, in this component;
 * this class keeps the entry points its callers use.
 *
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
		NotificationsListenerWiring::register(context: $context);
	}//end register()
}//end class
