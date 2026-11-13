<?php

/**
 * A flow's graph as the exporter reads it: cleaned, with ids and layout resolved.
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
 * @spec openspec/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

/**
 * The process, its elements and its diagram, ready to serialise.
 *
 * @spec openspec/specs/flow-bpmn-interchange/spec.md
 */
final class BpmnExportGraph {

	/**
	 * The nodes.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public readonly array $nodes;

	/**
	 * The attached edges, each with an `id`.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public readonly array $edges;

	/**
	 * One diagram box per node.
	 *
	 * @var array<int, array{id: string, x: int, y: int}>
	 */
	public readonly array $boxes;

	/**
	 * One diagram line per edge.
	 *
	 * @var array<int, array{id: string, from: array<int>, to: array<int>}>
	 */
	public readonly array $routes;

	/**
	 * Constructor.
	 *
	 * @param string             $id       The flow's uuid as an NCName.
	 * @param string             $name     The flow's name.
	 * @param BpmnExportElements $elements The nodes and attached edges.
	 * @param BpmnExportDiagram  $diagram  The boxes and routes.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		BpmnExportElements $elements,
		BpmnExportDiagram $diagram,
	) {
		$this->nodes  = $elements->nodes;
		$this->edges  = $elements->edges;
		$this->boxes  = $diagram->boxes;
		$this->routes = $diagram->routes;
	}//end __construct()

	/**
	 * The process element's id.
	 *
	 * @return string The id.
	 */
	public function processId(): string {
		return 'Process_' . $this->id;
	}//end processId()
}//end class
