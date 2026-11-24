<?php

/**
 * FilesMapperRegistrar: the entry that builds and binds the Files area's mappers in lib/Db.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\EntityEventsInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

/**
 * Builds and binds the Files area's mappers that live in lib/Db: the entity
 * relations found in files and objects.
 *
 * Application names this class only; the map lives in FilesMapperBindings,
 * so DbRegistrar (Core) names no Files interface and MapperWiring (Core)
 * no longer builds the entity relation mapper.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class FilesMapperRegistrar {

	/**
	 * The interfaces callers type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = FilesMapperBindings::BINDINGS;

	/**
	 * Register the entity relation mapper and every binding with the app container.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		$context->registerService(EntityRelationMapper::class, self::entityRelationMapper(...));
		DbRegistrar::bind(context: $context, bindings: FilesMapperBindings::BINDINGS);
	}//end register()

	/**
	 * Build the EntityRelationMapper.
	 *
	 * EntityRelationMapper is registered explicitly, as it was in MapperWiring:
	 * the event-dispatching mappers are wired by hand rather than left to
	 * Nextcloud's autowirer, whose resolution of framework-interface keys has
	 * historically been version-sensitive.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return EntityRelationMapper The mapper.
	 */
	private static function entityRelationMapper(ContainerInterface $container): EntityRelationMapper {
		return new EntityRelationMapper(
			db: $container->get('OCP\IDBConnection'),
			auditTrailMapper: $container->get(AuditTrailMapperInterface::class),
			userSession: $container->get('OCP\IUserSession'),
			entityEvents: $container->get(EntityEventsInterface::class),
			logger: $container->get('Psr\Log\LoggerInterface')
		);
	}//end entityRelationMapper()
}//end class
