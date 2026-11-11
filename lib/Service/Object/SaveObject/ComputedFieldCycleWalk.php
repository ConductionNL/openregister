<?php

/**
 * OpenRegister ComputedFieldCycleWalk
 *
 * A depth-first walk over the dependency graph of a schema's computed fields
 * that collects every distinct cycle.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * One walk over a computed-field dependency graph.
 *
 * The walk tracks the current traversal stack to detect back-edges. Distinct
 * cycles are canonicalised (rotated so the smallest node leads) so two paths
 * into the same cycle are not reported twice.
 *
 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-3
 */
final class ComputedFieldCycleWalk {

	/**
	 * Globally-visited nodes, so shared subtrees are not walked again.
	 *
	 * @var array<string, bool>
	 */
	private array $visited = [];

	/**
	 * The detected cycles, in the order found.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $cycles = [];

	/**
	 * The signatures of the cycles already reported.
	 *
	 * @var array<string, bool>
	 */
	private array $reported = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string>> $graph Adjacency list: computed property => computed properties it references.
	 */
	public function __construct(
		private readonly array $graph,
	) {
	}//end __construct()

	/**
	 * Walk from every node and return the cycles found.
	 *
	 * @return array<int, array<int, string>> Each cycle as the ordered node path, closing node repeated.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-3
	 */
	public function cycles(): array {
		foreach (array_keys($this->graph) as $start) {
			$this->visit(node: $start, stack: []);
		}

		return $this->cycles;
	}//end cycles()

	/**
	 * Visit a node: record a cycle on a back-edge, else walk its neighbours.
	 *
	 * @param string $node The node being visited.
	 * @param array<int, string> $stack The traversal stack on the way to the node.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-3
	 */
	private function visit(string $node, array $stack): void {
		if (in_array($node, $stack, true) === true) {
			$this->record(cycle: $this->cycleTo(node: $node, stack: $stack));
			return;
		}

		if (isset($this->visited[$node]) === true) {
			return;
		}

		$stack[] = $node;
		foreach ($this->graph[$node] ?? [] as $neighbour) {
			$this->visit(node: $neighbour, stack: $stack);
		}

		$this->visited[$node] = true;
	}//end visit()

	/**
	 * The cycle a back-edge to a node closes, sliced out of the stack.
	 *
	 * @param string $node The node already on the stack.
	 * @param array<int, string> $stack The traversal stack.
	 *
	 * @return array<int, string> The cycle path from the node back to itself.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-3
	 */
	private function cycleTo(string $node, array $stack): array {
		$cyclePath = array_slice($stack, array_search($node, $stack, true));
		$cyclePath[] = $node;

		return $cyclePath;
	}//end cycleTo()

	/**
	 * Report a cycle unless the same cycle was already reported.
	 *
	 * @param array<int, string> $cycle The cycle path, closing node repeated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-3
	 */
	private function record(array $cycle): void {
		$signature = $this->signature(cycle: $cycle);
		if (isset($this->reported[$signature]) === false) {
			$this->reported[$signature] = true;
			$this->cycles[] = $cycle;
		}
	}//end record()

	/**
	 * Canonicalise a cycle so duplicate starts produce one signature.
	 *
	 * Rotates the cycle so the lexicographically smallest node leads. A cycle
	 * path always starts and ends with the same node and has at least two
	 * entries, so dropping the closing node leaves the cycle's body.
	 *
	 * @param array<int, string> $cycle The cycle path, closing node repeated.
	 *
	 * @return string Canonical cycle signature.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-4
	 */
	private function signature(array $cycle): string {
		$body = array_slice($cycle, 0, -1);
		$minIndex = $this->smallestIndex(body: $body);

		return implode('->', array_merge(array_slice($body, $minIndex), array_slice($body, 0, $minIndex)));
	}//end signature()

	/**
	 * The index of the first lexicographically (strcmp) smallest node.
	 *
	 * @param array<int, string> $body The cycle's nodes.
	 *
	 * @return int The index.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-computed-fields/tasks.md#task-4
	 */
	private function smallestIndex(array $body): int {
		$minIndex = 0;
		foreach ($body as $idx => $name) {
			if (strcmp($name, $body[$minIndex]) < 0) {
				$minIndex = $idx;
			}
		}

		return $minIndex;
	}//end smallestIndex()
}//end class
