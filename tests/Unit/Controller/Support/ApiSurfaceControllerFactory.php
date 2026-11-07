<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ApiSurfaceController;

/**
 * Builds ApiSurfaceController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class ApiSurfaceControllerFactory {
	/**
	 * The constructor arguments of ApiSurfaceController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ApiCapabilitiesService $capabilities Constructor argument.
	 * @param ApiVersionCatalogue $catalogue Constructor argument.
	 * @param ApiContractService $contracts Constructor argument.
	 * @param ControllerSession $session Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ApiVersion\ApiCapabilitiesService $capabilities,
		\OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue $catalogue,
		\OCA\OpenRegister\Service\ApiVersion\ApiContractService $contracts,
		\OCA\OpenRegister\Controller\Support\ControllerSession $session,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\ApiSurfaceControllerServices(
				capabilities: $capabilities,
				catalogue: $catalogue,
				contracts: $contracts,
				session: $session,
			),
		];
	}//end args()

	/**
	 * Build ApiSurfaceController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ApiSurfaceController
	 */
	public static function create(mixed ...$args): ApiSurfaceController {
		return new ApiSurfaceController(...self::args(...$args));
	}//end create()
}//end class
