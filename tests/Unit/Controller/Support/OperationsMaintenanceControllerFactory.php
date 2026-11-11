<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\OperationsMaintenanceController;

/**
 * Builds OperationsMaintenanceController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class OperationsMaintenanceControllerFactory {
	/**
	 * The constructor arguments of OperationsMaintenanceController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param MaintenanceModeServiceInterface $maintenance Constructor argument.
	 * @param SupportBundleServiceInterface $bundle Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Operations\MaintenanceModeServiceInterface $maintenance,
		\OCA\OpenRegister\Service\Operations\SupportBundleServiceInterface $bundle,
		\OCP\IUserSession $userSession,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\OperationsMaintenanceControllerServices(
				maintenance: $maintenance,
				bundle: $bundle,
				userSession: $userSession,
			),
		];
	}

	/**
	 * Build OperationsMaintenanceController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return OperationsMaintenanceController
	 */
	public static function create(mixed ...$args): OperationsMaintenanceController {
		return new OperationsMaintenanceController(...self::args(...$args));
	}
}
