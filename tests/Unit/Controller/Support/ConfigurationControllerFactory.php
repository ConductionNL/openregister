<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ConfigurationController;

/**
 * Builds ConfigurationController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ConfigurationControllerFactory {
	/**
	 * The constructor arguments of ConfigurationController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param ConfigurationMapper $configurationMapper Constructor argument.
	 * @param ConfigurationService $configurationService Constructor argument.
	 * @param NotificationService $notificationService Constructor argument.
	 * @param GitHubClientInterface $githubHandler Constructor argument.
	 * @param GitLabHandler $gitlabHandler Constructor argument.
	 * @param IAppManager $appManager Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\ConfigurationMapper $configurationMapper,
		\OCA\OpenRegister\Service\ConfigurationService $configurationService,
		\OCA\OpenRegister\Service\NotificationService $notificationService,
		\OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface $githubHandler,
		\OCA\OpenRegister\Service\Configuration\GitLabHandler $gitlabHandler,
		\OCP\App\IAppManager $appManager,
		\Psr\Log\LoggerInterface $logger,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ConfigurationControllerServices(
				session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
					userSession: $userSession,
					groupManager: $groupManager,
					logger: $logger ?? new \Psr\Log\NullLogger(),
				),
				mappers: new \OCA\OpenRegister\Controller\Support\ConfigurationMappers(
					configurationMapper: $configurationMapper,
				),
				domain: new \OCA\OpenRegister\Controller\Support\ConfigurationDomain(
					configurationService: $configurationService,
					notificationService: $notificationService,
					githubHandler: $githubHandler,
					gitlabHandler: $gitlabHandler,
				),
				platform: new \OCA\OpenRegister\Controller\Support\ConfigurationPlatform(
					appManager: $appManager,
				),
			),
		];
	}

	/**
	 * Build ConfigurationController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ConfigurationController
	 */
	public static function create(mixed ...$args): ConfigurationController {
		return new ConfigurationController(...self::args(...$args));
	}
}
