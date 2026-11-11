<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ObjectRelationsController;

/**
 * Builds ObjectRelationsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ObjectRelationsControllerFactory {
	/**
	 * The constructor arguments of ObjectRelationsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectRelationService $relations Constructor argument.
	 * @param RelationGraphService $graphs Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param ExportGate $exportGate Constructor argument.
	 * @param ObjectReadAccess $access Constructor argument.
	 * @param ObjectTreeService $trees Constructor argument.
	 * @param PurposeGuard $purposeGuard Constructor argument.
	 * @param RegisterMapper $registerMapper Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Relation\ObjectRelationService $relations,
		\OCA\OpenRegister\Service\Relation\RelationGraphService $graphs,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Service\Export\ExportGate $exportGate,
		\OCA\OpenRegister\Service\Object\ObjectReadAccess $access,
		\OCA\OpenRegister\Service\Relation\ObjectTreeService $trees,
		\OCA\OpenRegister\Service\Audit\PurposeGuard $purposeGuard,
		\OCA\OpenRegister\Db\RegisterMapper $registerMapper,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ObjectRelationsControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\ObjectRelationsMappers(
					registerMapper: $registerMapper,
				),
				graph: new \OCA\OpenRegister\Controller\Support\ObjectRelationsGraph(
					relations: $relations,
					graphs: $graphs,
					objectService: $objectService,
					exportGate: $exportGate,
				),
				guards: new \OCA\OpenRegister\Controller\Support\ObjectRelationsGuards(
					access: $access,
					trees: $trees,
					purposeGuard: $purposeGuard,
				),
			),
		];
	}

	/**
	 * Build ObjectRelationsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ObjectRelationsController
	 */
	public static function create(mixed ...$args): ObjectRelationsController {
		return new ObjectRelationsController(...self::args(...$args));
	}
}
