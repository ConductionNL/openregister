<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\DelegationController;

/**
 * Builds DelegationController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class DelegationControllerFactory {
	/**
	 * The constructor arguments of DelegationController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param DelegationGrantMapper $grants Constructor argument.
	 * @param DelegationConsentService $consent Constructor argument.
	 * @param DelegationNotifier $notifier Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IUserManager $userManager Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param OrganisationService $organisations Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\DelegationGrantMapper $grants,
		\OCA\OpenRegister\Service\Delegation\DelegationConsentService $consent,
		\OCA\OpenRegister\Service\Delegation\DelegationNotifier $notifier,
		\OCP\IUserSession $userSession,
		\OCP\IUserManager $userManager,
		\OCP\IGroupManager $groupManager,
		\OCA\OpenRegister\Service\OrganisationService $organisations,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\DelegationControllerServices(
				session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
					userSession: $userSession,
					groupManager: $groupManager,
					logger: $logger ?? new \Psr\Log\NullLogger(),
				),
				mappers: new \OCA\OpenRegister\Controller\Support\DelegationMappers(
					grants: $grants,
				),
				domain: new \OCA\OpenRegister\Controller\Support\DelegationDomain(
					consent: $consent,
					notifier: $notifier,
					organisations: $organisations,
				),
				platform: new \OCA\OpenRegister\Controller\Support\DelegationPlatform(
					userManager: $userManager,
				),
			),
		];
	}

	/**
	 * Build DelegationController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return DelegationController
	 */
	public static function create(mixed ...$args): DelegationController {
		return new DelegationController(...self::args(...$args));
	}
}
