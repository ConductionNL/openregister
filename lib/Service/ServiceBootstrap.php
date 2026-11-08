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

use OCA\OpenRegister\Service\Gdpr\GdprRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the hand-wired services of the Service component.
 *
 * Application::register() calls register() and registerGdpr() once each, at
 * the points in its sequence where it called ServiceRegistrar and
 * GdprRegistrar before. The registrars themselves stay inside the component.
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

	/**
	 * Register the DSAR signer and the shared DSAR registries with their fail-closed defaults.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function registerGdpr(IRegistrationContext $context): void {
		GdprRegistrar::register(context: $context);
	}//end registerGdpr()
}//end class
