<?php

/**
 * Shape questions about a flow document, asked while lowering it to a Petri net.
 *
 * Split out of `FlowDefinitionBuilder`, which had grown past the
 * class-complexity threshold. The seam is real rather than arithmetic: nothing
 * here decides anything about the flow, it only answers "what is this node
 * called as a place", "which edges touch it", "is it a join". The builder's own
 * job — refusing a document it cannot lower, and assembling places and
 * transitions — stays where it is.
 *
 * Every method is pure and stateless.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Names places and reads edges for the definition builder.
 *
 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
 */
class FlowGraph {

	/**
	 * Separates a join's node id from the edge whose token it waits on.
	 */
	public const PLACE_JOIN = '#';

	/**
	 * The input place of a node, which is the node id itself.
	 *
	 * There is deliberately NO prefix, and that is load-bearing in two places,
	 * both of which break silently if one is introduced:
	 *
	 *  1. **Per-item routing.** `FlowItemPlacement::itemsForOutput()` matches an
	 *     item's output tag against the output PLACE NAME. A routing step tags
	 *     items with the node it is routing to (`work`, `idle`), so a place
	 *     called `in:work` matches nothing and every routed item is dropped —
	 *     no error, just an empty branch.
	 *  2. **The marking is the user-visible answer to "where is this run?"**
	 *     Consumers render it directly (hermiq badges the node holding a
	 *     token). A prefixed marking reads as internal machinery leaking out.
	 *
	 * @param string $nodeId The node.
	 *
	 * @return string The place name.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function inPlace(string $nodeId): string {
		return $nodeId;
	}//end inPlace()

	/**
	 * The place an edge delivers its token to.
	 *
	 * An ordinary node has one input place. A declared join has one per
	 * incoming edge instead, which is what makes it wait for all of them.
	 *
	 * @param string $nodeId The target node.
	 * @param string $edgeId The edge arriving at it.
	 * @param array<string, array> $nodes The declared nodes.
	 *
	 * @return string The place name.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function targetPlace(string $nodeId, string $edgeId, array $nodes): string {
		if ($this->isJoin(node: ($nodes[$nodeId] ?? [])) === true) {
			return $this->joinPlace(nodeId: $nodeId, edgeId: $edgeId);
		}

		return $this->inPlace(nodeId: $nodeId);
	}//end targetPlace()

	/**
	 * The per-edge input place of a declared join.
	 *
	 * @param string $nodeId The join node.
	 * @param string $edgeId The incoming edge.
	 *
	 * @return string The place name.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function joinPlace(string $nodeId, string $edgeId): string {
		return $this->inPlace(nodeId: $nodeId) . self::PLACE_JOIN . $edgeId;
	}//end joinPlace()

	/**
	 * Whether a node synchronises its incoming edges.
	 *
	 * @param array $node The node.
	 *
	 * @return bool True when it is a join.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function isJoin(array $node): bool {
		return ($node['join'] ?? false) === true;
	}//end isJoin()

	/**
	 * Edges arriving at a node.
	 *
	 * @param string $nodeId The node.
	 * @param array $edges The normalised edges.
	 *
	 * @return array<array> The edges.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function incoming(string $nodeId, array $edges): array {
		return array_values(
			array_filter(
				$edges,
				static fn (array $edge): bool => in_array($nodeId, $edge['to'], true)
			)
		);

	}//end incoming()

	/**
	 * Edges leaving a node.
	 *
	 * @param string $nodeId The node.
	 * @param array $edges The normalised edges.
	 *
	 * @return array<array> The edges.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function outgoing(string $nodeId, array $edges): array {
		return array_values(
			array_filter(
				$edges,
				static fn (array $edge): bool => in_array($nodeId, $edge['from'], true)
			)
		);

	}//end outgoing()

	/**
	 * Normalise an edge endpoint to a list of node ids.
	 *
	 * A scalar endpoint is an ordinary edge; an array endpoint fans out to
	 * several nodes at once.
	 *
	 * @param mixed $value The raw endpoint value.
	 *
	 * @return array<string> The endpoint node ids.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	public function normaliseEndpoints(mixed $value): array {
		if ($value === null) {
			return [];
		}

		$list = [$value];
		if (is_array($value) === true) {
			$list = $value;
		}

		$names = [];
		foreach ($list as $item) {
			$name = trim((string)$item);
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end normaliseEndpoints()
}//end class
