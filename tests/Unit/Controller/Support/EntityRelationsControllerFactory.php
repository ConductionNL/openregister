<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\EntityRelationsController;

/**
 * Builds EntityRelationsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class EntityRelationsControllerFactory {
	/**
	 * The constructor arguments of EntityRelationsController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param EntityRelationMapper $entityRelationMapper Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IRootFolder $rootFolder Constructor argument.
	 * @param PermissionHandler $permissionHandler Constructor argument.
	 * @param MagicMapper $magicMapper Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param RegisterMapper $registerMapper Constructor argument.
	 * @param EmailLinkMapper $emailLinkMapper Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param RelationDecisionService $relationDecisions Constructor argument.
	 * @param DecisionVisibility $decisionVisibility Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\EntityRelationMapper $entityRelationMapper,
		\OCP\IUserSession $userSession,
		\OCP\Files\IRootFolder $rootFolder,
		\OCA\OpenRegister\Service\Object\PermissionHandler $permissionHandler,
		\OCA\OpenRegister\Db\MagicMapper $magicMapper,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCA\OpenRegister\Db\RegisterMapper $registerMapper,
		\OCA\OpenRegister\Db\EmailLinkMapper $emailLinkMapper,
		\Psr\Log\LoggerInterface $logger,
		\OCA\OpenRegister\Service\Decision\RelationDecisionService $relationDecisions,
		\OCA\OpenRegister\Service\Decision\DecisionVisibility $decisionVisibility,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\EntityRelationsControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\EntityRelationsMappers(
					entityRelationMapper: $entityRelationMapper,
					catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
						registers: $registerMapper,
						schemas: $schemaMapper,
						tables: $magicMapper,
					),
				),
				links: new \OCA\OpenRegister\Controller\Support\EntityRelationsLinks(
					emailLinkMapper: $emailLinkMapper,
				),
				domain: new \OCA\OpenRegister\Controller\Support\EntityRelationsDomain(
					permissionHandler: $permissionHandler,
					relationDecisions: $relationDecisions,
					decisionVisibility: $decisionVisibility,
				),
				platform: new \OCA\OpenRegister\Controller\Support\EntityRelationsPlatform(
					session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
						userSession: $userSession,
						groupManager: $groupManager,
						logger: $logger,
					),
					rootFolder: $rootFolder,
				),
			),
		];
	}

	/**
	 * Build EntityRelationsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return EntityRelationsController
	 */
	public static function create(mixed ...$args): EntityRelationsController {
		return new EntityRelationsController(...self::args(...$args));
	}
}
