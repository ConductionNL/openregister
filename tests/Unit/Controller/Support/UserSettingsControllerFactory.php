<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\UserSettingsController;

/**
 * Builds UserSettingsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class UserSettingsControllerFactory {
	/**
	 * The constructor arguments of UserSettingsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param GitHubClientInterface $gitHubService Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface $gitHubService,
		\OCP\IUserSession $userSession,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\UserSettingsControllerServices(
				gitHubService: $gitHubService,
				logger: $logger,
			),
			$userSession,
		];
	}

	/**
	 * Build UserSettingsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return UserSettingsController
	 */
	public static function create(mixed ...$args): UserSettingsController {
		return new UserSettingsController(...self::args(...$args));
	}
}
