<?php

/**
 * The RBAC service registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body lives in RbacWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Registers the shared grant, hierarchy and reveal services that the permission path reads.
 *
 * Application::register() calls register() once.
 */
final class RbacRegistrar {

	/**
	 * Register the shared token-grant, hierarchy-grant and reveal services.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		RbacWiring::register(context: $context);
	}//end register()
}//end class
