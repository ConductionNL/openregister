<?php

/**
 * ServiceBootstrap: the entries Application calls to register the hand-wired services of the Service component.
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

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the hand-wired services of the Service component.
 *
 * Application::register() calls register() once, at the point in its
 * sequence where it called ServiceRegistrar before. The registrar itself
 * stays inside the component. The Compliance GDPR registrar is reached
 * through ComplianceServiceRegistrar, so this Core class does not name it.
 *
 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
 */
final class ServiceBootstrap {

	/**
	 * Register the services that need a hand-written factory.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		ServiceRegistrar::register(context: $context);
	}//end register()
}//end class
