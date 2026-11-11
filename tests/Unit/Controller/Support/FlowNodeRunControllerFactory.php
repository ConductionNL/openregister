<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\FlowNodeRunController;

/**
 * Builds FlowNodeRunController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class FlowNodeRunControllerFactory {
	/**
	 * The constructor arguments of FlowNodeRunController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param FlowService $flows Constructor argument.
	 * @param FlowPublishedGraph $publishedGraphs Constructor argument.
	 * @param FlowLocator $subjects Constructor argument.
	 * @param FlowNodeRegistry $nodes Constructor argument.
	 * @param FlowRunService $runner Constructor argument.
	 * @param ObjectService $objects Constructor argument.
	 * @param SchemaMapper $schemas Constructor argument.
	 * @param PermissionHandler $permissions Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCP\IUserSession $userSession,
		\OCA\OpenRegister\Service\Flow\FlowService $flows,
		\OCA\OpenRegister\Service\Flow\FlowPublishedGraph $publishedGraphs,
		\OCA\OpenRegister\Service\Flow\FlowLocator $subjects,
		\OCA\OpenRegister\Service\Flow\FlowNodeRegistry $nodes,
		\OCA\OpenRegister\Service\Flow\FlowRunService $runner,
		\OCA\OpenRegister\Service\ObjectService $objects,
		\OCA\OpenRegister\Db\SchemaMapper $schemas,
		\OCA\OpenRegister\Service\Object\PermissionHandler $permissions,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\FlowNodeRunControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\FlowNodeRunMappers(
					schemas: $schemas,
				),
				graph: new \OCA\OpenRegister\Controller\Support\FlowNodeRunGraph(
					flows: $flows,
					publishedGraphs: $publishedGraphs,
					subjects: $subjects,
					nodes: $nodes,
				),
				execution: new \OCA\OpenRegister\Controller\Support\FlowNodeRunExecution(
					runner: $runner,
					objects: $objects,
					permissions: $permissions,
				),
				platform: new \OCA\OpenRegister\Controller\Support\DsarCasePlatform(
					userSession: $userSession,
				),
			),
		];
	}

	/**
	 * Build FlowNodeRunController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FlowNodeRunController
	 */
	public static function create(mixed ...$args): FlowNodeRunController {
		return new FlowNodeRunController(...self::args(...$args));
	}
}
