<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ObjectSharingController;

/**
 * Builds ObjectSharingController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ObjectSharingControllerFactory {
	/**
	 * The constructor arguments of ObjectSharingController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectOperationsInterface $objectService Constructor argument.
	 * @param RegisterMapperInterface $registerMapper Constructor argument.
	 * @param SchemaMapperInterface $schemaMapper Constructor argument.
	 * @param ObjectSharingService $sharing Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface $objectService,
		\OCA\OpenRegister\Db\RegisterMapperInterface $registerMapper,
		\OCA\OpenRegister\Db\SchemaMapperInterface $schemaMapper,
		\OCA\OpenRegister\Service\Rbac\ObjectSharingService $sharing,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ObjectSharingControllerServices(
				objectService: $objectService,
				catalog: new MapperBackedSchemaCatalog(registers: $registerMapper, schemas: $schemaMapper),
				sharing: $sharing,
				logger: $logger,
			),
		];
	}

	/**
	 * Build ObjectSharingController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ObjectSharingController
	 */
	public static function create(mixed ...$args): ObjectSharingController {
		return new ObjectSharingController(...self::args(...$args));
	}
}
