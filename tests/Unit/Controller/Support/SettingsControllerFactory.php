<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\SettingsController;

/**
 * Builds SettingsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class SettingsControllerFactory {
	/**
	 * The constructor arguments of SettingsController.
	 *
	 * @param mixed $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param IAppConfig $config Constructor argument.
	 * @param IDBConnection $db Constructor argument.
	 * @param ContainerInterface $container Constructor argument.
	 * @param AppSettingsInterface $settingsService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ?IL10N $l10n Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		mixed $appName,
		\OCP\IRequest $request,
		\OCP\IAppConfig $config,
		\OCP\IDBConnection $db,
		\Psr\Container\ContainerInterface $container,
		\OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface $settingsService,
		\Psr\Log\LoggerInterface $logger,
		?\OCP\IL10N $l10n = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\SettingsControllerServices(
				store: new \OCA\OpenRegister\Controller\Support\SettingsStore(
					settingsService: $settingsService,
					config: $config,
					db: $db,
					container: $container,
				),
				runtime: new \OCA\OpenRegister\Controller\Support\SettingsRuntime(
					logger: $logger,
					l10n: $l10n,
				),
			),
		];
	}

	/**
	 * Build SettingsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return SettingsController
	 */
	public static function create(mixed ...$args): SettingsController {
		return new SettingsController(...self::args(...$args));
	}
}
