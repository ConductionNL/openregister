<?php

/**
 * The middleware registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body lives in MiddlewareWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Registers OpenRegister's controller middlewares. Nextcloud runs them in
 * registration order, so the order below is part of their behaviour.
 *
 * Application::register() calls register() once.
 */
final class MiddlewareRegistrar {

	/**
	 * Register the middlewares, in the order Nextcloud runs them.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	public static function register(IRegistrationContext $context): void {
		MiddlewareWiring::register(context: $context);
	}//end register()
}//end class
