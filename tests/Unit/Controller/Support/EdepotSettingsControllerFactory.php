<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Settings\EdepotSettingsController;

/**
 * Builds EdepotSettingsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class EdepotSettingsControllerFactory {
	/**
	 * The constructor arguments of EdepotSettingsController.
	 *
	 * @param mixed $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param IAppConfig $appConfig Constructor argument.
	 * @param EdepotTransferService $transferService Constructor argument.
	 * @param SftpTransport $sftpTransport Constructor argument.
	 * @param RestApiTransport $restTransport Constructor argument.
	 * @param OpenConnectorTransport $ocTransport Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ConnectionReporter $connectionReporter Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		mixed $appName,
		\OCP\IRequest $request,
		\OCP\IAppConfig $appConfig,
		\OCA\OpenRegister\Service\Edepot\EdepotTransferService $transferService,
		\OCA\OpenRegister\Service\Edepot\Transport\SftpTransport $sftpTransport,
		\OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport $restTransport,
		\OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport $ocTransport,
		\Psr\Log\LoggerInterface $logger,
		\OCA\OpenRegister\Service\Connection\ConnectionReporter $connectionReporter,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\EdepotSettingsControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\EdepotSettingsDomain(
					transferService: $transferService,
					sftpTransport: $sftpTransport,
					restTransport: $restTransport,
					ocTransport: $ocTransport,
				),
				platform: new \OCA\OpenRegister\Controller\Support\ConnectionReporting(
					connectionReporter: $connectionReporter,
					appConfig: $appConfig,
				),
			),
		];
	}

	/**
	 * Build EdepotSettingsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return EdepotSettingsController
	 */
	public static function create(mixed ...$args): EdepotSettingsController {
		return new EdepotSettingsController(...self::args(...$args));
	}
}
