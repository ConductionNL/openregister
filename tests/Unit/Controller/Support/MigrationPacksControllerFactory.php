<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\MigrationPacksController;

/**
 * Builds MigrationPacksController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class MigrationPacksControllerFactory {
	/**
	 * The constructor arguments of MigrationPacksController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param MigrationPackServiceInterface $service Constructor argument.
	 * @param ControllerSession $session Constructor argument.
	 * @param MigrationPackRequestReader $requestReader Constructor argument.
	 * @param MigrationPackErrorResponder $errors Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\MigrationPackServiceInterface $service,
		\OCA\OpenRegister\Controller\Support\ControllerSession $session,
		\OCA\OpenRegister\Service\MigrationPack\MigrationPackRequestReader $requestReader,
		\OCA\OpenRegister\Service\MigrationPack\MigrationPackErrorResponder $errors,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\MigrationPacksControllerServices(
				service: $service,
				session: $session,
				requestReader: $requestReader,
				errors: $errors,
			),
		];
	}//end args()

	/**
	 * Build MigrationPacksController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return MigrationPacksController
	 */
	public static function create(mixed ...$args): MigrationPacksController {
		return new MigrationPacksController(...self::args(...$args));
	}//end create()
}//end class
