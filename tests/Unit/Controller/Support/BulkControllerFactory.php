<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\BulkController;

/**
 * Builds BulkController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class BulkControllerFactory {
	/**
	 * The constructor arguments of BulkController.
	 *
	 * @param string $appName Unused; kept so existing calls still fit.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param RegisterMapper $registerMapper Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param IDBConnection $db Constructor argument.
	 * @param ?SchemaManageGate $schemaManageGate Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Db\RegisterMapper $registerMapper,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\OCP\IDBConnection $db,
		?\OCA\OpenRegister\Service\Rbac\SchemaManageGate $schemaManageGate = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\BulkControllerServices(
				store: new \OCA\OpenRegister\Controller\Support\BulkStore(
					registerMapper: $registerMapper,
					schemaMapper: $schemaMapper,
					objectService: $objectService,
					schemaManageGate: $schemaManageGate,
				),
				platform: new \OCA\OpenRegister\Controller\Support\BulkPlatform(
					principal: new \OCA\OpenRegister\Controller\Support\SessionPrincipal(userSession: $userSession, groupManager: $groupManager),
					db: $db,
				),
			),
		];
	}

	/**
	 * Build BulkController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return BulkController
	 */
	public static function create(mixed ...$args): BulkController {
		return new BulkController(...self::args(...$args));
	}
}
