<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ObjectOwnershipController;

/**
 * Builds ObjectOwnershipController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ObjectOwnershipControllerFactory {
	/**
	 * The constructor arguments of ObjectOwnershipController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param RegisterMapper $registerMapper Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param ObjectOwnershipService $ownership Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Db\RegisterMapper $registerMapper,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCA\OpenRegister\Service\Rbac\ObjectOwnershipService $ownership,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ObjectOwnershipControllerServices(
				objectService: $objectService,
				catalog: new MapperBackedSchemaCatalog(registers: $registerMapper, schemas: $schemaMapper),
				ownership: $ownership,
				logger: $logger,
			),
		];
	}

	/**
	 * Build ObjectOwnershipController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ObjectOwnershipController
	 */
	public static function create(mixed ...$args): ObjectOwnershipController {
		return new ObjectOwnershipController(...self::args(...$args));
	}
}
