<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ScopesController;

/**
 * Builds ScopesController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ScopesControllerFactory {
	/**
	 * The constructor arguments of ScopesController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ControllerSession $session Constructor argument.
	 * @param RegisterMapperInterface $registerMapper Constructor argument.
	 * @param SchemaMapperInterface $schemaMapper Constructor argument.
	 * @param PermissionHandlerInterface $permissionHandler Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Controller\Support\ControllerSession $session,
		\OCA\OpenRegister\Db\RegisterMapperInterface $registerMapper,
		\OCA\OpenRegister\Db\SchemaMapperInterface $schemaMapper,
		\OCA\OpenRegister\Service\Object\PermissionHandlerInterface $permissionHandler,
	): array {
		return [
			$appName,
			$request,
			$session,
			new \OCA\OpenRegister\Controller\Support\ScopesControllerServices(
				registerMapper: $registerMapper,
				schemaMapper: $schemaMapper,
				permissionHandler: $permissionHandler,
			),
		];
	}

	/**
	 * Build ScopesController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ScopesController
	 */
	public static function create(mixed ...$args): ScopesController {
		return new ScopesController(...self::args(...$args));
	}
}
