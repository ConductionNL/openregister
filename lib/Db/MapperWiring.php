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

use OCA\OpenRegister\Service\ObjectAction\ObjectActionValidatorInterface;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandlerInterface;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * The wiring behind MapperRegistrar.
 *
 * MapperRegistrar keeps the public entry points and delegates each of them here.
 *
 * Registers the mappers that are built by hand rather than autowired.
 *
 * Application::register() calls register() once.
 */
final class MapperWiring {

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
		$context->registerService(SchemaMapper::class, self::schemaMapper(...));
		$context->registerService(RegisterMapper::class, self::registerMapper(...));
		$context->registerService(WebhookMapper::class, self::webhookMapper(...));
		$context->registerService(MagicMapper::class, self::magicMapper(...));
		$context->registerService(EntityRelationMapper::class, self::entityRelationMapper(...));
	}//end register()

	/**
	 * Build the SchemaMapper.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return SchemaMapper The mapper.
	 */
	private static function schemaMapper(ContainerInterface $container): SchemaMapper {
		return new SchemaMapper(
			db: $container->get('OCP\IDBConnection'),
			eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
			validator: $container->get(PropertyValidatorHandlerInterface::class),
			organisationMapper: $container->get(OrganisationMapperInterface::class),
			userSession: $container->get('OCP\IUserSession'),
			groupManager: $container->get('OCP\IGroupManager'),
			appConfig: $container->get('OCP\IAppConfig'),
			logger: $container->get('Psr\Log\LoggerInterface'),
			objectActions: static fn (): ObjectActionValidatorInterface => $container->get(ObjectActionValidatorInterface::class)
		);
	}//end schemaMapper()

	/**
	 * Build the RegisterMapper.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return RegisterMapper The mapper.
	 */
	private static function registerMapper(ContainerInterface $container): RegisterMapper {
		return new RegisterMapper(
			db: $container->get('OCP\IDBConnection'),
			schemaMapper: $container->get(SchemaMapperInterface::class),
			eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
			container: $container,
			organisationMapper: $container->get(OrganisationMapperInterface::class),
			userSession: $container->get('OCP\IUserSession'),
			groupManager: $container->get('OCP\IGroupManager'),
			appConfig: $container->get('OCP\IAppConfig'),
			// Explicitly wired: this factory overrides autowiring, so an
			// argument omitted here is never supplied at all. That is how
			// RegisterMapper ran with four dead logging branches
			// (openregister#2820).
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end registerMapper()

	/**
	 * Build the WebhookMapper.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return WebhookMapper The mapper.
	 */
	private static function webhookMapper(ContainerInterface $container): WebhookMapper {
		return new WebhookMapper(
			db: $container->get('OCP\IDBConnection'),
			organisationMapper: $container->get(OrganisationMapperInterface::class),
			userSession: $container->get('OCP\IUserSession'),
			groupManager: $container->get('OCP\IGroupManager'),
			appConfig: $container->get('OCP\IAppConfig')
		);
	}//end webhookMapper()

	/**
	 * Build the MagicMapper.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return MagicMapper The mapper.
	 */
	private static function magicMapper(ContainerInterface $container): MagicMapper {
		return new MagicMapper(
			db: $container->get('OCP\IDBConnection'),
			schemaMapper: $container->get(SchemaMapperInterface::class),
			registerMapper: $container->get(RegisterMapperInterface::class),
			config: $container->get('OCP\IConfig'),
			eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
			userSession: $container->get('OCP\IUserSession'),
			groupManager: $container->get('OCP\IGroupManager'),
			userManager: $container->get('OCP\IUserManager'),
			appConfig: $container->get('OCP\IAppConfig'),
			logger: $container->get('Psr\Log\LoggerInterface'),
			settingsService: $container->get(AppSettingsInterface::class),
			container: $container
		);
	}//end magicMapper()

	/**
	 * Build the EntityRelationMapper.
	 *
	 * EntityRelationMapper is registered explicitly because it constructor-injects
	 * `IEventDispatcher` to dispatch `EntityRelationDecisionUpdatedEvent`. Every
	 * other event-dispatcher-dependent mapper in this class (SchemaMapper,
	 * RegisterMapper, MagicMapper, WebhookMapper) is wired explicitly to avoid
	 * depending on NC's autowirer resolution of framework-interface keys, which
	 * has historically been version-sensitive.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return EntityRelationMapper The mapper.
	 */
	private static function entityRelationMapper(ContainerInterface $container): EntityRelationMapper {
		return new EntityRelationMapper(
			db: $container->get('OCP\IDBConnection'),
			auditTrailMapper: $container->get(\OCA\OpenRegister\Db\AuditTrailMapperInterface::class),
			userSession: $container->get('OCP\IUserSession'),
			eventDispatcher: $container->get('OCP\EventDispatcher\IEventDispatcher'),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end entityRelationMapper()
}//end class
