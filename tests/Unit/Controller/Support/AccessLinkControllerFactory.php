<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\AccessLinkController;

/**
 * Builds AccessLinkController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class AccessLinkControllerFactory {
	/**
	 * The constructor arguments of AccessLinkController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param AccessLinkService $links Constructor argument.
	 * @param AccessLinkReader $reader Constructor argument.
	 * @param AccessLinkMintGuard $mintGuard Constructor argument.
	 * @param AccessLinkActs $acts Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IThrottler $throttler Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Sharing\AccessLinkService $links,
		\OCA\OpenRegister\Files\Service\Sharing\AccessLinkReader $reader,
		\OCA\OpenRegister\Service\Sharing\AccessLinkMintGuard $mintGuard,
		\OCA\OpenRegister\Service\Sharing\AccessLinkActs $acts,
		\OCP\IUserSession $userSession,
		\OCP\Security\Bruteforce\IThrottler $throttler,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\AccessLinkControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\AccessLinkDomain(
					links: $links,
					reader: $reader,
					mintGuard: $mintGuard,
					acts: $acts,
				),
				platform: new \OCA\OpenRegister\Controller\Support\AccessLinkPlatform(
					userSession: $userSession,
					throttler: $throttler,
					logger: $logger,
				),
			),
		];
	}

	/**
	 * Build AccessLinkController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return AccessLinkController
	 */
	public static function create(mixed ...$args): AccessLinkController {
		return new AccessLinkController(...self::args(...$args));
	}
}
