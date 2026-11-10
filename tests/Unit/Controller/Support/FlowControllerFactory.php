<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\FlowController;

/**
 * Builds FlowController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class FlowControllerFactory {
	/**
	 * The constructor arguments of FlowController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param EventCatalogService $eventCatalog Constructor argument.
	 * @param FlowNodeRegistry $nodes Constructor argument.
	 * @param FlowStateMapper $flowState Constructor argument.
	 * @param FlowNodePreflight $preflight Constructor argument.
	 * @param FlowService $flows Constructor argument.
	 * @param FlowAccess $access Constructor argument.
	 * @param FlowVersionService $flowVersionService Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Flow\EventCatalogService $eventCatalog,
		\OCA\OpenRegister\Service\Flow\FlowNodeRegistry $nodes,
		\OCA\OpenRegister\Db\FlowStateMapper $flowState,
		\OCA\OpenRegister\Service\Flow\FlowNodePreflight $preflight,
		\OCA\OpenRegister\Service\Flow\FlowService $flows,
		\OCA\OpenRegister\Service\Flow\FlowAccess $access,
		\OCA\OpenRegister\Service\Flow\FlowVersionService $flowVersionService,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\FlowControllerServices(
				catalogue: new \OCA\OpenRegister\Controller\Support\FlowCatalogue(
					flowState: $flowState,
					eventCatalog: $eventCatalog,
					nodeTypes: new \OCA\OpenRegister\Controller\Support\FlowNodeTypes(
						nodes: $nodes,
						preflight: $preflight,
					),
					bpmn: new \OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnInterchange(),
				),
				definitions: new \OCA\OpenRegister\Controller\Support\FlowDefinitions(
					flows: $flows,
					access: $access,
					flowVersionService: $flowVersionService,
				),
			),
		];
	}

	/**
	 * Build FlowController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FlowController
	 */
	public static function create(mixed ...$args): FlowController {
		return new FlowController(...self::args(...$args));
	}
}
