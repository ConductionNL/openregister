<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\GdprEntitiesController;

/**
 * Builds GdprEntitiesController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class GdprEntitiesControllerFactory {
	/**
	 * The constructor arguments of GdprEntitiesController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param GdprEntityMapper $entityMapper Constructor argument.
	 * @param EntityRelationMapper $entityRelationMapper Constructor argument.
	 * @param IDBConnection $db Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param OrganisationService $organisationService Constructor argument.
	 * @param DecisionVisibility $decisionVisibility Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\GdprEntityMapper $entityMapper,
		\OCA\OpenRegister\Db\EntityRelationMapper $entityRelationMapper,
		\OCP\IDBConnection $db,
		\Psr\Log\LoggerInterface $logger,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		\OCA\OpenRegister\Service\Decision\DecisionVisibility $decisionVisibility,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\GdprEntitiesControllerServices(
				session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
					userSession: $userSession,
					groupManager: $groupManager,
					logger: $logger ?? new \Psr\Log\NullLogger(),
				),
				mappers: new \OCA\OpenRegister\Controller\Support\GdprEntitiesMappers(
					entityMapper: $entityMapper,
					entityRelationMapper: $entityRelationMapper,
				),
				domain: new \OCA\OpenRegister\Controller\Support\GdprEntitiesDomain(
					organisationService: $organisationService,
					decisionVisibility: $decisionVisibility,
				),
				platform: new \OCA\OpenRegister\Controller\Support\GdprEntitiesPlatform(
					db: $db,
				),
			),
		];
	}

	/**
	 * Build GdprEntitiesController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return GdprEntitiesController
	 */
	public static function create(mixed ...$args): GdprEntitiesController {
		return new GdprEntitiesController(...self::args(...$args));
	}
}
