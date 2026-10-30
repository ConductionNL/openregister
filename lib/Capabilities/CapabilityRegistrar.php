<?php

/**
 * The capability registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Capabilities
 * @package  OCA\OpenRegister\Capabilities
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Capabilities;

use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the OCS capabilities and the integrations capability service.
 *
 * Application::register() calls register() once.
 */
final class CapabilityRegistrar {

	/**
	 * Register the integrations capability service and both capabilities.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		// IntegrationsAdminSettings is declared in info.xml <admin> and
		// resolved by Nextcloud's container — IntegrationRegistry +
		// ExternalIntegrationRouter are already registered above and the
		// remaining constructor deps (IAppManager / IURLGenerator /
		// IL10N) are framework services NC autowires. No explicit
		// registerService needed, mirroring OpenRegisterAdmin.
		// IntegrationsCapability — surfaces the registry through the
		// Nextcloud OCS capabilities endpoint, role-redacted per AD-17.
		$context->registerService(
			IntegrationsCapability::class,
			function (ContainerInterface $container) {
				return new IntegrationsCapability(
					registry: $container->get(\OCA\OpenRegister\Service\Integration\IntegrationRegistry::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					appManager: $container->get('OCP\App\IAppManager'),
					leafRegistry: $container->get(\OCA\OpenRegister\Service\Integration\LeafRegistry::class)
				);
			}
		);

		$context->registerCapability(UrnCapability::class);
		$context->registerCapability(IntegrationsCapability::class);
	}//end register()
}//end class
