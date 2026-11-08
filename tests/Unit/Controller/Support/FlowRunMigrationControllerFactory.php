<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\FlowRunMigrationController;

/**
 * Builds FlowRunMigrationController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class FlowRunMigrationControllerFactory {
	/**
	 * The constructor arguments of FlowRunMigrationController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param FlowRunMapperInterface $mapper Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param FlowRunnableGuard $guard Constructor argument.
	 * @param ?FlowRunMigrationServiceInterface $migrations Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\FlowRunMapperInterface $mapper,
		\OCP\IUserSession $userSession,
		\OCA\OpenRegister\Service\Flow\FlowRunnableGuard $guard,
		?\OCA\OpenRegister\Service\Flow\FlowRunMigrationServiceInterface $migrations = null,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\FlowRunMigrationControllerServices(
				mapper: $mapper,
				userSession: $userSession,
				guard: $guard,
				migrations: $migrations,
			),
		];
	}//end args()

	/**
	 * Build FlowRunMigrationController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FlowRunMigrationController
	 */
	public static function create(mixed ...$args): FlowRunMigrationController {
		return new FlowRunMigrationController(...self::args(...$args));
	}//end create()
}//end class
