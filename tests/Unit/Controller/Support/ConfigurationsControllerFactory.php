<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ConfigurationsController;

/**
 * Builds ConfigurationsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ConfigurationsControllerFactory {
	/**
	 * The constructor arguments of ConfigurationsController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param ConfigurationMapper $configurationMapper Constructor argument.
	 * @param ConfigurationService $configurationService Constructor argument.
	 * @param UploadService $uploadService Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\ConfigurationMapper $configurationMapper,
		\OCA\OpenRegister\Service\ConfigurationService $configurationService,
		\OCA\OpenRegister\Files\Service\UploadService $uploadService,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ConfigurationsControllerServices(
				records: new \OCA\OpenRegister\Service\Config\ConfigurationRecords(
					configurationMapper: $configurationMapper,
					configurationService: $configurationService,
				),
				uploadService: $uploadService,
				session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
					userSession: $userSession,
					groupManager: $groupManager,
					logger: new \Psr\Log\NullLogger(),
				),
			),
		];
	}

	/**
	 * Build ConfigurationsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ConfigurationsController
	 */
	public static function create(mixed ...$args): ConfigurationsController {
		return new ConfigurationsController(...self::args(...$args));
	}
}
