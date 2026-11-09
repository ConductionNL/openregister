<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Settings\LlmSettingsController;

/**
 * Builds LlmSettingsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class LlmSettingsControllerFactory {
	/**
	 * The constructor arguments of LlmSettingsController.
	 *
	 * @param mixed $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param IDBConnection $db Constructor argument.
	 * @param ContainerInterface $container Constructor argument.
	 * @param AppSettingsInterface $settingsService Constructor argument.
	 * @param VectorizationServiceInterface $vectorizationService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ConnectionReporter $connectionReporter Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		mixed $appName,
		\OCP\IRequest $request,
		\OCP\IDBConnection $db,
		\Psr\Container\ContainerInterface $container,
		\OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface $settingsService,
		\OCA\OpenRegister\Search\Service\VectorizationServiceInterface $vectorizationService,
		\Psr\Log\LoggerInterface $logger,
		\OCA\OpenRegister\Service\Connection\ConnectionReporter $connectionReporter,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\LlmSettingsControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\LlmSettingsDomain(
					settingsService: $settingsService,
					vectorizationService: $vectorizationService,
					connectionReporter: $connectionReporter,
				),
				platform: new \OCA\OpenRegister\Controller\Support\LlmSettingsPlatform(
					container: $container,
				),
			),
		];
	}

	/**
	 * Build LlmSettingsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return LlmSettingsController
	 */
	public static function create(mixed ...$args): LlmSettingsController {
		return new LlmSettingsController(...self::args(...$args));
	}
}
