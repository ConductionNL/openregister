<?php

/**
 * NotificationsServiceRegistrar: the entry that binds the Notifications area's service interfaces in lib/Service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\DbRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the interfaces of the Notifications area's services that live in
 * lib/Service: the referenced-address mailer.
 *
 * Application names this class only; the map lives in
 * NotificationsServiceBindings, inside the area.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class NotificationsServiceRegistrar {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = NotificationsServiceBindings::BINDINGS;

	/**
	 * Register every binding with the app container.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		DbRegistrar::bind(context: $context, bindings: NotificationsServiceBindings::BINDINGS);
	}//end register()
}//end class
