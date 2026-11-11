<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\TablesController;

/**
 * Builds TablesController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class TablesControllerFactory {
	/**
	 * The constructor arguments of TablesController.
	 *
	 * @param mixed $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param MagicMapperInterface $magicMapper Constructor argument.
	 * @param RegisterMapperInterface $registerMapper Constructor argument.
	 * @param SchemaMapperInterface $schemaMapper Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		mixed $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\MagicMapperInterface $magicMapper,
		\OCA\OpenRegister\Db\RegisterMapperInterface $registerMapper,
		\OCA\OpenRegister\Db\SchemaMapperInterface $schemaMapper,
		\Psr\Log\LoggerInterface $logger,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\TablesControllerServices(
				magicMapper: $magicMapper,
				registerMapper: $registerMapper,
				schemaMapper: $schemaMapper,
			),
			$logger,
			$userSession,
			$groupManager,
		];
	}

	/**
	 * Build TablesController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return TablesController
	 */
	public static function create(mixed ...$args): TablesController {
		return new TablesController(...self::args(...$args));
	}
}
