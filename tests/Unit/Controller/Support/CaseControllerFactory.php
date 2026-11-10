<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\CaseController;

/**
 * Builds CaseController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class CaseControllerFactory {
	/**
	 * The constructor arguments of CaseController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param CasePlanService $plans Constructor argument.
	 * @param ZaaktypeCaseSkeletonMapper $zaaktypes Constructor argument.
	 * @param CasePlanAuthorizationService $authorization Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param ?LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Case\CasePlanService $plans,
		\OCA\OpenRegister\Service\Case\ZaaktypeCaseSkeletonMapper $zaaktypes,
		\OCA\OpenRegister\Service\Case\CasePlanAuthorizationService $authorization,
		\OCP\IUserSession $userSession,
		?\Psr\Log\LoggerInterface $logger = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\CaseControllerServices(
				plans: $plans,
				zaaktypes: $zaaktypes,
				authorization: $authorization,
				userSession: $userSession,
			),
			$logger,
		];
	}

	/**
	 * Build CaseController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return CaseController
	 */
	public static function create(mixed ...$args): CaseController {
		return new CaseController(...self::args(...$args));
	}
}
