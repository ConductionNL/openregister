<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ObjectShareLinkController;

/**
 * Builds ObjectShareLinkController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ObjectShareLinkControllerFactory {
	/**
	 * The constructor arguments of ObjectShareLinkController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param IManager $shareManager Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param AccessLinkReader $reader Constructor argument.
	 * @param IThrottler $throttler Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCP\Share\IManager $shareManager,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Files\Service\Sharing\AccessLinkReader $reader,
		\OCP\Security\Bruteforce\IThrottler $throttler,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\ObjectShareLinkControllerServices(
				shareManager: $shareManager,
				objectService: $objectService,
				reader: $reader,
				throttler: $throttler,
			),
			$logger,
		];
	}

	/**
	 * Build ObjectShareLinkController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ObjectShareLinkController
	 */
	public static function create(mixed ...$args): ObjectShareLinkController {
		return new ObjectShareLinkController(...self::args(...$args));
	}
}
