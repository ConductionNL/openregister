<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ManifestController;

/**
 * Builds ManifestController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class ManifestControllerFactory {
	/**
	 * The constructor arguments of ManifestController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ManifestServiceInterface $manifestService Constructor argument.
	 * @param IAppManager $appManager Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ManifestServiceInterface $manifestService,
		\OCP\App\IAppManager $appManager,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\ManifestControllerServices(
				manifestService: $manifestService,
				appManager: $appManager,
				logger: $logger,
			),
		];
	}//end args()

	/**
	 * Build ManifestController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ManifestController
	 */
	public static function create(mixed ...$args): ManifestController {
		return new ManifestController(...self::args(...$args));
	}//end create()
}//end class
