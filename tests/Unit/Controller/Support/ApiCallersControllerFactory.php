<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ApiCallersController;

/**
 * Builds ApiCallersController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class ApiCallersControllerFactory {
	/**
	 * The constructor arguments of ApiCallersController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param ApiCallRecordMapper $records Constructor argument.
	 * @param ApiVersionCatalogue $catalogue Constructor argument.
	 * @param IAppConfig $appConfig Constructor argument.
	 * @param ControllerSession $session Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\ApiCallRecordMapper $records,
		\OCA\OpenRegister\Service\ApiVersion\ApiVersionCatalogue $catalogue,
		\OCP\IAppConfig $appConfig,
		\OCA\OpenRegister\Controller\Support\ControllerSession $session,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ApiCallersControllerServices(
				records: $records,
				catalogue: $catalogue,
				appConfig: $appConfig,
				session: $session,
			),
		];
	}//end args()

	/**
	 * Build ApiCallersController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ApiCallersController
	 */
	public static function create(mixed ...$args): ApiCallersController {
		return new ApiCallersController(...self::args(...$args));
	}//end create()
}//end class
