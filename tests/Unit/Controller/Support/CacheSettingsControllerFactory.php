<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Settings\CacheSettingsController;

/**
 * Builds CacheSettingsController from its services as separate arguments.
 */
final class CacheSettingsControllerFactory {
	/**
	 * The constructor arguments of CacheSettingsController.
	 *
	 * @param mixed $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param AppSettingsInterface $settingsService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param Factory $appDataFactory Constructor argument.
	 * @param IAppConfig $appConfig Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		mixed $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface $settingsService,
		\Psr\Log\LoggerInterface $logger,
		\OC\Files\AppData\Factory $appDataFactory,
		\OCP\IAppConfig $appConfig,
	): array {
		return [
			$appName,
			$request,
			$settingsService,
			$logger,
			$appDataFactory,
			$appConfig,
		];
	}//end args()

	/**
	 * Build CacheSettingsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return CacheSettingsController
	 */
	public static function create(mixed ...$args): CacheSettingsController {
		return new CacheSettingsController(...self::args(...$args));
	}//end create()
}//end class
