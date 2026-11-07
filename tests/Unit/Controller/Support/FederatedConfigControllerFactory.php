<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\FederatedConfigController;

/**
 * Builds FederatedConfigController from its services as separate arguments.
 */
final class FederatedConfigControllerFactory {
	/**
	 * The constructor arguments of FederatedConfigController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param FederatedConfigServiceInterface $service Constructor argument.
	 * @param FederatedConfigAccess $access Constructor argument.
	 * @param ControllerSession $session Constructor argument.
	 * @param IConfig $config Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Config\FederatedConfigServiceInterface $service,
		\OCA\OpenRegister\Service\Config\FederatedConfigAccess $access,
		\OCA\OpenRegister\Controller\Support\ControllerSession $session,
		\OCP\IConfig $config,
	): array {
		return [
			$appName,
			$request,
			$service,
			$access,
			$session,
			$config,
		];
	}//end args()

	/**
	 * Build FederatedConfigController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FederatedConfigController
	 */
	public static function create(mixed ...$args): FederatedConfigController {
		return new FederatedConfigController(...self::args(...$args));
	}//end create()
}//end class
