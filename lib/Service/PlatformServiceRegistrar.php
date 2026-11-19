<?php

/**
 * PlatformServiceRegistrar: the entry that binds the Platform area's service interfaces.
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
 * Binds the interfaces of the Platform area's services: the action-rights
 * catalogue, the API caller recorder, policy and rate limiter, the chat
 * proxy, and the language and translation services.
 *
 * Application names this class only; the map lives in
 * PlatformServiceBindings, inside the area. An interface of a lower area
 * that a Platform class implements is bound here as well, so no lower
 * registrar names it.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class PlatformServiceRegistrar {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = PlatformServiceBindings::BINDINGS;

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
		DbRegistrar::bind(context: $context, bindings: PlatformServiceBindings::BINDINGS);
	}//end register()
}//end class
