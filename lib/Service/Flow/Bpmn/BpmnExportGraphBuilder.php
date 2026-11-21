<?php

/**
 * Reads a flow into the graph the BPMN exporter serialises.
 *
 * Everything here is about WHICH nodes and edges go out, under which ids and
 * where they are drawn; nothing here writes XML. The exporter turns the
 * result into elements.
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

use OCA\OpenRegister\Db\Flow;

/**
 * Cleans a flow's graph and resolves its ids and layout.
 *
 * @spec openspec/specs/flow-bpmn-interchange/spec.md
 */
class BpmnExportGraphBuilder {

	/**
	 * The flow's graph, cleaned and with ids and layout resolved.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return BpmnExportGraph The graph.
	 *
	 * @spec openspec/specs/flow-bpmn-interchange/spec.md
	 */
	public function graphOf(Flow $flow): BpmnExportGraph {
		$nodes = $this->nodesOf(flow: $flow);
		$edges = $this->edgesOf(flow: $flow, nodes: $nodes);
		$boxes = $this->boxesOf(nodes: $nodes);

		return new BpmnExportGraph(
			id: $this->ncName(value: (string)$flow->getUuid()),
			name: (string)($flow->getName() ?? ''),
			elements: new BpmnExportElements(nodes: $nodes, edges: $edges),
			diagram: new BpmnExportDiagram(boxes: $boxes, routes: $this->routesOf(edges: $edges, boxes: $boxes))
		);
	}//end graphOf()

	/**
	 * One box per node, at its canvas position or a laid-out one.
	 *
	 * @param array<int, array<string, mixed>> $nodes The nodes.
	 *
	 * @return array<int, array{id: string, x: int, y: int}> The boxes.
	 */
	private function boxesOf(array $nodes): array {
		$boxes = [];
		foreach ($nodes as $index => $node) {
			[$left, $top] = $this->positionOf(node: $node, index: (int)$index);
			$boxes[] = ['id' => $this->ncName(value: (string)($node['id'] ?? '')), 'x' => $left, 'y' => $top];
		}

		return $boxes;
	}//end boxesOf()

	/**
	 * One route per edge, between the centres of the boxes it joins.
	 *
	 * @param array<int, array<string, mixed>>              $edges The edges, with their ids resolved.
	 * @param array<int, array{id: string, x: int, y: int}> $boxes The boxes.
	 *
	 * @return array<int, array{id: string, from: array<int>, to: array<int>}> The routes.
	 */
	private function routesOf(array $edges, array $boxes): array {
		$centres = $this->centresOf(boxes: $boxes);
		$routes = [];
		foreach ($edges as $edge) {
			$routes[] = [
				'id'   => $this->ncName(value: (string)$edge['id']),
				'from' => $this->centreOf(centres: $centres, ref: (string)($edge['from'] ?? '')),
				'to'   => $this->centreOf(centres: $centres, ref: (string)($edge['to'] ?? '')),
			];
		}

		return $routes;
	}//end routesOf()

	/**
	 * The centre of every box, keyed by node id; a later duplicate id wins.
	 *
	 * @param array<int, array{id: string, x: int, y: int}> $boxes The boxes.
	 *
	 * @return array<string, array{0: int, 1: int}> The centres.
	 */
	private function centresOf(array $boxes): array {
		$centres = [];
		foreach ($boxes as $box) {
			$centres[$box['id']] = [
				(int)($box['x'] + intdiv(FlowBpmnExporter::WIDTH, 2)),
				(int)($box['y'] + intdiv(FlowBpmnExporter::HEIGHT, 2)),
			];
		}

		return $centres;
	}//end centresOf()

	/**
	 * The centre of the box a node reference names, or the origin.
	 *
	 * @param array<string, array{0: int, 1: int}> $centres The centres.
	 * @param string                               $ref     The node reference.
	 *
	 * @return array{0: int, 1: int} The centre.
	 */
	private function centreOf(array $centres, string $ref): array {
		return ($centres[$this->ncName(value: $ref)] ?? [0, 0]);
	}//end centreOf()

	/**
	 * A node's canvas position, or a laid-out one.
	 *
	 * 🔑 BOTH SPELLINGS OF A POSITION ARE READ. PHP does not own the canvas
	 * shape — the editor writes it — and the stored graphs carry `position:
	 * {x, y}` and bare `x`/`y` alike. Reading only one would silently lay out a
	 * perfectly positioned flow as a diagonal line, which reads as "the export
	 * lost my layout".
	 *
	 * @param array<string, mixed> $node  The node.
	 * @param int                  $index Its index.
	 *
	 * @return array{0: int, 1: int} The x and y.
	 */
	private function positionOf(array $node, int $index): array {
		$stored = ($this->pointOf(source: ($node['position'] ?? null)) ?? $this->pointOf(source: $node));

		return ($stored ?? [($index * FlowBpmnExporter::SPACING), 100]);
	}//end positionOf()

	/**
	 * The x and y a value carries, when it carries both.
	 *
	 * @param mixed $source The value.
	 *
	 * @return array{0: int, 1: int}|null The point, or null.
	 */
	private function pointOf(mixed $source): ?array {
		if (is_array($source) === false || isset($source['x'], $source['y']) === false) {
			return null;
		}

		return [(int)$source['x'], (int)$source['y']];
	}//end pointOf()

	/**
	 * The flow's nodes as a list.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 */
	private function nodesOf(Flow $flow): array {
		$nodes = [];
		foreach ((array)($flow->getNodes() ?? []) as $node) {
			if (is_array($node) === true) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}//end nodesOf()

	/**
	 * The flow's attached edges as a list, each with its id resolved.
	 *
	 * @param Flow                             $flow  The flow.
	 * @param array<int, array<string, mixed>> $nodes Its nodes.
	 *
	 * @return array<int, array<string, mixed>> The edges.
	 */
	private function edgesOf(Flow $flow, array $nodes): array {
		$known = array_map(static fn (array $node): string => (string)($node['id'] ?? ''), $nodes);
		$edges = array_filter(
			(array)($flow->getEdges() ?? []),
			fn ($edge): bool => is_array($edge) === true && $this->isAttached(edge: $edge, known: $known) === true
		);

		return $this->withIds(edges: array_values($edges));
	}//end edgesOf()

	/**
	 * Whether both ends of an edge name a node of the process.
	 *
	 * 🔴 A DANGLING EDGE IS DROPPED, NOT EXPORTED. A `sequenceFlow` whose
	 * sourceRef or targetRef names nothing in the process is not a slightly
	 * wrong diagram: every modeller refuses the whole file, so one edge left
	 * behind by a deleted node turns the export into something nobody can
	 * open. The flow itself is not wrong — the engine refuses a dangling edge
	 * at build time — but a document assembled from a stored node list can
	 * still carry one, and the export is the surface where it becomes fatal.
	 *
	 * @param array<string, mixed> $edge  The edge.
	 * @param array<int, string>   $known The node ids.
	 *
	 * @return bool Whether it is attached.
	 */
	private function isAttached(array $edge, array $known): bool {
		$from = (string)($edge['from'] ?? '');
		$to = (string)($edge['to'] ?? '');

		return in_array($from, $known, true) === true && in_array($to, $known, true) === true;
	}//end isAttached()

	/**
	 * The edges with an id on each, `edge-<index>` for one that has none.
	 *
	 * @param array<int, array<string, mixed>> $edges The edges.
	 *
	 * @return array<int, array<string, mixed>> The edges.
	 */
	private function withIds(array $edges): array {
		foreach ($edges as $index => $edge) {
			$edges[$index]['id'] = (string)($edge['id'] ?? ('edge-' . $index));
		}

		return $edges;
	}//end withIds()

	/**
	 * A value as an XML NCName, which is what a BPMN id must be.
	 *
	 * 🔴 A UUID STARTS WITH A DIGIT ABOUT HALF THE TIME, and an NCName may not.
	 * An id that is invalid XML makes the whole document unparseable by the
	 * tool the export exists to reach, and the failure arrives as "Camunda
	 * cannot open your file" rather than as anything about ids.
	 *
	 * @param string $value The value.
	 *
	 * @return string The NCName.
	 *
	 * @spec openspec/specs/flow-bpmn-interchange/spec.md
	 */
	public function ncName(string $value): string {
		$clean = (string)preg_replace('/[^A-Za-z0-9_.-]/', '_', trim($value));
		if ($clean === '') {
			$clean = 'unnamed';
		}

		if (preg_match('/^[A-Za-z_]/', $clean) !== 1) {
			$clean = ('id_' . $clean);
		}

		return $clean;
	}//end ncName()
}//end class
