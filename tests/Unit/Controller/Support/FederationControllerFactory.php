<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\FederationController;

/**
 * Builds FederationController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class FederationControllerFactory {
	/**
	 * The constructor arguments of FederationController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param FederatedShareMapper $shareMapper Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param FederationShareService $shareService Constructor argument.
	 * @param IThrottler $throttler Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\FederatedShareMapper $shareMapper,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Service\FederationShareService $shareService,
		\OCP\Security\Bruteforce\IThrottler $throttler,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\FederationControllerServices(
				shareMapper: $shareMapper,
				objectService: $objectService,
				shareService: $shareService,
				throttler: $throttler,
			),
			$logger,
		];
	}

	/**
	 * Build FederationController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FederationController
	 */
	public static function create(mixed ...$args): FederationController {
		return new FederationController(...self::args(...$args));
	}
}
