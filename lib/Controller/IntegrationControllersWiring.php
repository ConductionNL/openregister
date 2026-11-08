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

use OCA\OpenRegister\Controller\Support\IntegrationsControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsAccess;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsControllerServices;
use OCA\OpenRegister\Controller\Support\ObjectIntegrationsDomain;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use OCA\OpenRegister\Service\Integration\IntegrationCatalogueInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * The wiring behind IntegrationControllersRegistrar.
 *
 * IntegrationControllersRegistrar keeps the public entry points and delegates each of them here.
 *
 * Registers the IntegrationsController and the ObjectIntegrationsController.
 *
 * The integration registrar calls this at the point it used to register the
 * two controllers itself, so the registration order is unchanged and the
 * controllers' construction stays inside the Controller component.
 *
 * @spec openspec/specs/integration-registry/spec.md
 */
final class IntegrationControllersWiring {

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
		self::registerIntegrationsController(context: $context);
		self::registerObjectIntegrationsController(context: $context);
	}//end register()

	/**
	 * Register the IntegrationsController, the read-only API over the registry.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerIntegrationsController(IRegistrationContext $context): void {
		$context->registerService(
			IntegrationsController::class,
			static fn (ContainerInterface $container) => new IntegrationsController(
				appName: 'openregister',
				request: $container->get('OCP\IRequest'),
				services: new IntegrationsControllerServices(
					registry: $container->get(IntegrationCatalogueInterface::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					logger: $container->get('Psr\Log\LoggerInterface')
				)
			)
		);
	}//end registerIntegrationsController()

	/**
	 * Register the ObjectIntegrationsController, the object-scoped
	 * sub-resource dispatch through the registry.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	private static function registerObjectIntegrationsController(IRegistrationContext $context): void {
		$context->registerService(
			ObjectIntegrationsController::class,
			static fn (ContainerInterface $container) => new ObjectIntegrationsController(
				appName: 'openregister',
				request: $container->get('OCP\IRequest'),
				services: self::buildObjectIntegrationsServices(container: $container)
			)
		);
	}//end registerObjectIntegrationsController()

	/**
	 * Build the service bundle of the ObjectIntegrationsController.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return ObjectIntegrationsControllerServices
	 */
	private static function buildObjectIntegrationsServices(ContainerInterface $container): ObjectIntegrationsControllerServices {
		return new ObjectIntegrationsControllerServices(
			domain: new ObjectIntegrationsDomain(
				registry: $container->get(IntegrationCatalogueInterface::class),
				objectService: $container->get(ObjectOperationsInterface::class),
				schemaMapper: $container->get(SchemaMapperInterface::class)
			),
			access: new ObjectIntegrationsAccess(
				visibility: $container->get(TimelineVisibilityServiceInterface::class),
				permissions: $container->get(PermissionHandlerInterface::class)
			),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end buildObjectIntegrationsServices()
}//end class
