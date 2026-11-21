<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\DsarController;

/**
 * Builds DsarController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class DsarControllerFactory {
	/**
	 * The constructor arguments of DsarController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param DsarService $dsarService Constructor argument.
	 * @param AvgComplianceService $complianceService Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\DsarService $dsarService,
		\OCA\OpenRegister\Service\AvgComplianceService $complianceService,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\DsarControllerServices(
				dsarService: $dsarService,
				complianceService: $complianceService,
				userSession: $userSession,
				groupManager: $groupManager,
			),
		];
	}//end args()

	/**
	 * Build DsarController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return DsarController
	 */
	public static function create(mixed ...$args): DsarController {
		return new DsarController(...self::args(...$args));
	}//end create()
}//end class
