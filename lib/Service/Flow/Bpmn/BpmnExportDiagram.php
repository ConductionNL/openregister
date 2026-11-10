<?php

/**
 * The diagram of an exported flow: one box per node, one line per edge.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

/**
 * The boxes and routes the exporter writes as the diagram.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
final class BpmnExportDiagram {
	/**
	 * Bundle the diagram.
	 *
	 * @param array<int, array{id: string, x: int, y: int}>                 $boxes  One diagram box per node.
	 * @param array<int, array{id: string, from: array<int>, to: array<int>}> $routes One diagram line per edge.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function __construct(
		public readonly array $boxes,
		public readonly array $routes,
	) {
	}//end __construct()
}//end class
