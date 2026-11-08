<?php

/**
 * NotificationsRegistrar: container registrations of the Notifications component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body and the binding map live in NotificationsWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Registers the services of the Notifications component that the container cannot
 * autowire, and binds the interfaces other components type against.
 */
final class NotificationsRegistrar {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = NotificationsWiring::BINDINGS;

	/**
	 * Register the Notifications component's services.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		NotificationsWiring::register(context: $context);
	}//end register()
}//end class
