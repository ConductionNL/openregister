<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Settings\ApiTokenSettingsController;

/**
 * Builds ApiTokenSettingsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ApiTokenSettingsControllerFactory {
	/**
	 * The constructor arguments of ApiTokenSettingsController.
	 *
	 * @param mixed $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param IAppConfig $config Constructor argument.
	 * @param AppSettingsInterface $settingsService Constructor argument.
	 * @param IClientService $clientService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ConnectionReporter $connectionReporter Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		mixed $appName,
		\OCP\IRequest $request,
		\OCP\IAppConfig $config,
		\OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface $settingsService,
		\OCP\Http\Client\IClientService $clientService,
		\Psr\Log\LoggerInterface $logger,
		\OCA\OpenRegister\Service\Connection\ConnectionReporter $connectionReporter,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ApiTokenSettingsControllerServices(
				reporting: new \OCA\OpenRegister\Controller\Support\ConnectionReporting(
					connectionReporter: $connectionReporter,
					appConfig: $config,
				),
				settingsService: $settingsService,
				clientService: $clientService,
				logger: $logger,
			),
		];
	}

	/**
	 * Build ApiTokenSettingsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ApiTokenSettingsController
	 */
	public static function create(mixed ...$args): ApiTokenSettingsController {
		return new ApiTokenSettingsController(...self::args(...$args));
	}
}
