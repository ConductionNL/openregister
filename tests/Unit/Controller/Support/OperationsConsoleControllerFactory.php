<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\OperationsConsoleController;

/**
 * Builds OperationsConsoleController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class OperationsConsoleControllerFactory {
	/**
	 * The constructor arguments of OperationsConsoleController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param OperationsConsoleServiceInterface $console Constructor argument.
	 * @param OperationsJobsServiceInterface $jobsService Constructor argument.
	 * @param JobAlertServiceInterface $alerts Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\OperationsConsoleServiceInterface $console,
		\OCA\OpenRegister\Service\Operations\OperationsJobsServiceInterface $jobsService,
		\OCA\OpenRegister\Service\Operations\JobAlertServiceInterface $alerts,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\OperationsConsoleControllerServices(
				console: $console,
				jobsService: $jobsService,
				alerts: $alerts,
				session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
					userSession: $userSession,
					groupManager: $groupManager,
					logger: new \Psr\Log\NullLogger(),
				),
			),
		];
	}

	/**
	 * Build OperationsConsoleController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return OperationsConsoleController
	 */
	public static function create(mixed ...$args): OperationsConsoleController {
		return new OperationsConsoleController(...self::args(...$args));
	}
}
