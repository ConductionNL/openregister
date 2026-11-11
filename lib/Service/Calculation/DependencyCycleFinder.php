<?php

/**
 * OpenRegister DependencyCycleFinder
 *
 * Finds the first cycle in a dependency graph by depth-first search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

/**
 * Depth-first cycle search over a name-to-names dependency graph.
 *
 * Nodes are visited in the order the graph lists them; the first cycle
 * found is returned as the path that closes it (`a -> b -> a`). Edges to
 * names that are not nodes of the graph are ignored.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class DependencyCycleFinder {

	/**
	 * The graph being searched.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $deps = [];

	/**
	 * Node state: 1 on the current path, 2 finished.
	 *
	 * @var array<string, int>
	 */
	private array $colour = [];

	/**
	 * The current depth-first path.
	 *
	 * @var array<int, string>
	 */
	private array $stack = [];

	/**
	 * The cycle found, or null.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $path = null;


	/**
	 * The first cycle in the graph, or null when it has none.
	 *
	 * @param array<string, array<int, string>> $deps Name to the names it depends on.
	 *
	 * @return array<int, string>|null The cycle, its first node repeated at the end.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-compute-profile-org/tasks.md#task-2
	 */
	public function find(array $deps): ?array {
		$this->deps = $deps;
		$this->colour = [];
		$this->stack = [];
		$this->path = null;
		foreach (array_keys($deps) as $name) {
			$this->visit(node: $name);
			if ($this->path !== null) {
				return $this->path;
			}
		}

		return null;
	}//end find()


	/**
	 * Visit a node and the nodes it depends on.
	 *
	 * @param string $node The node.
	 *
	 * @return void
	 */
	private function visit(string $node): void {
		if ($this->path !== null || ($this->colour[$node] ?? 0) === 2) {
			return;
		}

		if (($this->colour[$node] ?? 0) === 1) {
			$this->closeCycle(node: $node);
			return;
		}

		$this->colour[$node] = 1;
		$this->stack[] = $node;
		$this->visitDependencies(node: $node);
		array_pop($this->stack);
		$this->colour[$node] = 2;
	}//end visit()


	/**
	 * Visit the nodes a node depends on that are nodes of the graph.
	 *
	 * @param string $node The node.
	 *
	 * @return void
	 */
	private function visitDependencies(string $node): void {
		foreach (($this->deps[$node] ?? []) as $next) {
			if (isset($this->deps[$next]) === true) {
				$this->visit(node: $next);
			}
		}
	}//end visitDependencies()


	/**
	 * Record the cycle that a revisit of a node on the current path closes.
	 *
	 * @param string $node The revisited node.
	 *
	 * @return void
	 */
	private function closeCycle(string $node): void {
		$idx = array_search($node, $this->stack, true);
		if ($idx === false) {
			return;
		}

		$this->path = array_slice($this->stack, $idx);
		$this->path[] = $node;
	}//end closeCycle()
}//end class
