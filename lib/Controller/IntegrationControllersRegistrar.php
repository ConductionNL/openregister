<?php

/**
 * The container registrations of the integration controllers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-registry/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The body lives in IntegrationControllersWiring, in this component;
 * this class keeps the entry points its callers use.
 *
 * Registers the IntegrationsController and the ObjectIntegrationsController.
 *
 * The integration registrar calls this at the point it used to register the
 * two controllers itself, so the registration order is unchanged and the
 * controllers' construction stays inside the Controller component.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
final class IntegrationControllersRegistrar {

	/**
	 * Register both integration controllers.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-registry/spec.md
	 */
	public static function register(IRegistrationContext $context): void {
		IntegrationControllersWiring::register(context: $context);
	}//end register()
}//end class
