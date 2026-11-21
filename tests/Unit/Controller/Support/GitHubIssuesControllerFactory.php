<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\GitHubIssuesController;

/**
 * Builds GitHubIssuesController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class GitHubIssuesControllerFactory {
	/**
	 * The constructor arguments of GitHubIssuesController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param GitHubClientInterface $githubHandler Constructor argument.
	 * @param GitHubGuards $guards Constructor argument.
	 * @param GitHubRequestValidator $validator Constructor argument.
	 * @param RateLimiterService $rateLimiter Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param ICacheFactory $cacheFactory Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface $githubHandler,
		\OCA\OpenRegister\Service\Configuration\GitHubGuards $guards,
		\OCA\OpenRegister\Service\Configuration\GitHubRequestValidator $validator,
		\OCA\OpenRegister\Service\Configuration\RateLimiterService $rateLimiter,
		\OCP\IUserSession $userSession,
		\OCP\ICacheFactory $cacheFactory,
		\Psr\Log\LoggerInterface $logger,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\GitHubIssuesControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\GitHubIssuesDomain(
					githubHandler: $githubHandler,
					guards: $guards,
					validator: $validator,
					rateLimiter: $rateLimiter,
				),
				platform: new \OCA\OpenRegister\Controller\Support\GitHubIssuesPlatform(
					session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
						userSession: $userSession,
						groupManager: $groupManager,
						logger: $logger,
					),
					cacheFactory: $cacheFactory,
				),
			),
		];
	}

	/**
	 * Build GitHubIssuesController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return GitHubIssuesController
	 */
	public static function create(mixed ...$args): GitHubIssuesController {
		return new GitHubIssuesController(...self::args(...$args));
	}
}
