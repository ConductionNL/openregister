<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\UserController;

/**
 * Builds UserController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class UserControllerFactory {
	/**
	 * The constructor arguments of UserController.
	 *
	 * @param string $appName Unused; kept so existing calls still fit.
	 * @param IRequest $request Constructor argument.
	 * @param UserService $userService Constructor argument.
	 * @param SecurityService $securityService Constructor argument.
	 * @param IUserManager $userManager Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param IL10N $l10n Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\UserService $userService,
		\OCA\OpenRegister\Service\SecurityService $securityService,
		\OCP\IUserManager $userManager,
		\OCP\IUserSession $userSession,
		\Psr\Log\LoggerInterface $logger,
		\OCP\IL10N $l10n,
		?\OCP\Authentication\TwoFactorAuth\IRegistry $twoFactor = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\UserControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\UserDomain(
					userService: $userService,
					securityService: $securityService,
					userManager: $userManager,
					userSession: $userSession,
					twoFactor: ($twoFactor ?? self::noSecondFactor()),
				),
				platform: new \OCA\OpenRegister\Controller\Support\UserPlatform(
					logger: $logger,
					l10n: $l10n,
				),
			),
		];
	}

	/**
	 * A registry in which nobody has a second factor enabled.
	 *
	 * @return \OCP\Authentication\TwoFactorAuth\IRegistry
	 */
	public static function noSecondFactor(): \OCP\Authentication\TwoFactorAuth\IRegistry {
		return new class implements \OCP\Authentication\TwoFactorAuth\IRegistry {
			public function getProviderStates(\OCP\IUser $user): array {
				return [];
			}

			public function enableProviderFor(\OCP\Authentication\TwoFactorAuth\IProvider $provider, \OCP\IUser $user) {
			}

			public function disableProviderFor(\OCP\Authentication\TwoFactorAuth\IProvider $provider, \OCP\IUser $user) {
			}

			public function cleanUp(string $providerId) {
			}
		};
	}

	/**
	 * Build UserController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return UserController
	 */
	public static function create(mixed ...$args): UserController {
		return new UserController(...self::args(...$args));
	}
}
