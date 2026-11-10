<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\HardeningController;

/**
 * Builds HardeningController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class HardeningControllerFactory {
	/**
	 * The constructor arguments of HardeningController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param HardeningReportService $reportService Constructor argument.
	 * @param HardeningSettingsService $settingsService Constructor argument.
	 * @param HardeningPolicy $policy Constructor argument.
	 * @param ElevationService $elevation Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Hardening\HardeningReportService $reportService,
		\OCA\OpenRegister\Service\Hardening\HardeningSettingsService $settingsService,
		\OCA\OpenRegister\Service\Hardening\HardeningPolicy $policy,
		\OCA\OpenRegister\Service\Hardening\ElevationService $elevation,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\HardeningControllerServices(
				reportService: $reportService,
				settingsService: $settingsService,
				policy: $policy,
				elevation: $elevation,
			),
		];
	}//end args()

	/**
	 * Build HardeningController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return HardeningController
	 */
	public static function create(mixed ...$args): HardeningController {
		return new HardeningController(...self::args(...$args));
	}//end create()
}//end class
