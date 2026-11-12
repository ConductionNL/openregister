<?php

/**
 * OpenRegister object relations reachability
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * Cuts refused nodes out of a relation graph, with their edges and what hangs only on them.
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */
final class ObjectRelationsReachability {
	/**
	 * The graph without the refused nodes, their edges and what hangs only on them.
	 *
	 * @param array<string, mixed> $graph   The graph.
	 * @param array<string, true>  $refused The uuids of the refused nodes.
	 *
	 * @return array<string, mixed> The graph.
	 */
	public function reachableFromRoot(array $graph, array $refused): array {
		$edges = $this->edgesAvoiding(edges: ($graph['edges'] ?? null), uuids: $refused);

		$reached = $this->reached(root: (string)($graph['root'] ?? ''), edges: $edges);

		$graph['nodes'] = $this->nodesAmong(nodes: ($graph['nodes'] ?? null), uuids: array_diff_key($reached, $refused));
		$graph['edges'] = array_values(
			array_filter($edges, static fn (array $edge): bool => self::joins(edge: $edge, uuids: $reached) === true)
		);

		return $graph;
	}//end reachableFromRoot()

	/**
	 * The well-formed edges that touch none of some objects.
	 *
	 * @param mixed               $edges The graph's edges.
	 * @param array<string, true> $uuids The objects, as a set.
	 *
	 * @return list<array<string, mixed>> The edges.
	 */
	private function edgesAvoiding(mixed $edges, array $uuids): array {
		return array_values(
			array_filter(
				$this->rowsIn(value: $edges),
				static fn (array $edge): bool => self::touches(edge: $edge, uuids: $uuids) === false
			)
		);
	}//end edgesAvoiding()

	/**
	 * The well-formed nodes that are among some objects.
	 *
	 * @param mixed               $nodes The graph's nodes.
	 * @param array<string, true> $uuids The objects, as a set.
	 *
	 * @return list<array<string, mixed>> The nodes.
	 */
	private function nodesAmong(mixed $nodes, array $uuids): array {
		return array_values(
			array_filter(
				$this->rowsIn(value: $nodes),
				static fn (array $node): bool => isset($uuids[(string)($node['uuid'] ?? '')]) === true
			)
		);
	}//end nodesAmong()

	/**
	 * Whether either end of an edge is in a set of uuids.
	 *
	 * @param array<string, mixed> $edge  The edge.
	 * @param array<string, true>  $uuids The set.
	 *
	 * @return bool True when its `from` or its `to` is in the set.
	 */
	private static function touches(array $edge, array $uuids): bool {
		return isset($uuids[(string)($edge['from'] ?? '')]) === true
			|| isset($uuids[(string)($edge['to'] ?? '')]) === true;
	}//end touches()

	/**
	 * Whether both ends of an edge are in a set of uuids.
	 *
	 * @param array<string, mixed> $edge  The edge.
	 * @param array<string, true>  $uuids The set.
	 *
	 * @return bool True when its `from` and its `to` are in the set.
	 */
	private static function joins(array $edge, array $uuids): bool {
		return isset($uuids[(string)($edge['from'] ?? '')]) === true
			&& isset($uuids[(string)($edge['to'] ?? '')]) === true;
	}//end joins()

	/**
	 * A graph list (its nodes or edges) as a list of rows; anything else is no rows.
	 *
	 * @param mixed $value The list as the graph holds it.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsIn(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn (mixed $row): bool => is_array($row) === true));
	}//end rowsIn()

	/**
	 * The uuids connected to the root through the given edges, either direction.
	 *
	 * @param string                           $root  The root uuid.
	 * @param array<int, array<string, mixed>> $edges The edges that remain.
	 *
	 * @return array<string, true> The reached uuids.
	 */
	private function reached(string $root, array $edges): array {
		$reached = [$root => true];
		do {
			$before = count($reached);
			$reached = $this->spread(reached: $reached, edges: $edges);
		} while (count($reached) > $before);

		return $reached;
	}//end reached()

	/**
	 * One pass over the edges: every edge with exactly one end reached reaches the other.
	 *
	 * @param array<string, true>              $reached The uuids reached so far.
	 * @param array<int, array<string, mixed>> $edges   The edges that remain.
	 *
	 * @return array<string, true> The uuids reached after the pass.
	 */
	private function spread(array $reached, array $edges): array {
		foreach ($edges as $edge) {
			$from = (string)($edge['from'] ?? '');
			$to = (string)($edge['to'] ?? '');
			if (isset($reached[$from]) !== isset($reached[$to])) {
				$reached[$from] = true;
				$reached[$to] = true;
			}
		}

		return $reached;
	}//end spread()
}//end class
