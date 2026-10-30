<?php

/**
 * The mapper registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Registers the mappers that are built by hand rather than autowired.
 *
 * Application::register() calls register() once.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every service it wires.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Factory closures list every constructor argument.
 * @SuppressWarnings(PHPMD.StaticAccess)          DbContractBindings is a static registrar, like this class.
 */
final class MapperRegistrar {

	/**
	 * Register the schema, register, webhook, magic and entity-relation mappers.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		$context->registerService(
			SchemaMapper::class,
			function (ContainerInterface $container) {
				return new SchemaMapper(
					db: $container->get('OCP\IDBConnection'),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					validator: $container->get(PropertyValidatorHandler::class),
					organisationMapper: $container->get(OrganisationMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					appConfig: $container->get('OCP\IAppConfig'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			RegisterMapper::class,
			function (ContainerInterface $container) {
				return new RegisterMapper(
					db: $container->get('OCP\IDBConnection'),
					schemaMapper: $container->get(SchemaMapper::class),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					container: $container,
					organisationMapper: $container->get(OrganisationMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					appConfig: $container->get('OCP\IAppConfig'),
					// Explicitly wired: this factory overrides autowiring, so an
					// argument omitted here is never supplied at all. That is how
					// RegisterMapper ran with four dead logging branches
					// (openregister#2820).
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		$context->registerService(
			WebhookMapper::class,
			function (ContainerInterface $container) {
				return new WebhookMapper(
					db: $container->get('OCP\IDBConnection'),
					organisationMapper: $container->get(OrganisationMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					appConfig: $container->get('OCP\IAppConfig')
				);
			}
		);

		$context->registerService(
			MagicMapper::class,
			function (ContainerInterface $container) {
				return new MagicMapper(
					db: $container->get('OCP\IDBConnection'),
					schemaMapper: $container->get(SchemaMapper::class),
					registerMapper: $container->get(RegisterMapper::class),
					config: $container->get('OCP\IConfig'),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					userSession: $container->get('OCP\IUserSession'),
					groupManager: $container->get('OCP\IGroupManager'),
					userManager: $container->get('OCP\IUserManager'),
					appConfig: $container->get('OCP\IAppConfig'),
					logger: $container->get('Psr\Log\LoggerInterface'),
					settingsService: $container->get(SettingsService::class),
					container: $container
				);
			}
		);

		// EntityRelationMapper is registered explicitly because it constructor-injects
		// `IEventDispatcher` to dispatch `EntityRelationDecisionUpdatedEvent`. Every
		// other event-dispatcher-dependent mapper in this method (SchemaMapper,
		// RegisterMapper, MagicMapper, WebhookMapper) is wired explicitly to avoid
		// depending on NC's autowirer resolution of framework-interface keys, which
		// has historically been version-sensitive.
		$context->registerService(
			EntityRelationMapper::class,
			function (ContainerInterface $container) {
				return new EntityRelationMapper(
					db: $container->get('OCP\IDBConnection'),
					auditTrailMapper: $container->get(\OCA\OpenRegister\Db\AuditTrailMapper::class),
					userSession: $container->get('OCP\IUserSession'),
					eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
					logger: $container->get('Psr\Log\LoggerInterface')
				);
			}
		);

		DbContractBindings::register(context: $context);
	}//end register()
}//end class
