<?php

/**
 * Who else is affected, derived by walking the declared relations (row 2.48).
 *
 * The ledger note: "Row 2.26 declares typed links. Nothing walks them to answer
 * who else is affected, and nothing lets you prune a branch of that walk."
 *
 * 🔑 ONE TRAVERSAL, TWO ANSWERS (D-1). This is NOT a second walk.
 * `RelationGraphService::graph()` already returns a bounded, typed, directed
 * graph with its depth cap, its node cap and its truncation reason; this takes
 * that answer and filters it. Two walkers would drift, and the second one would
 * be the one nobody bounded.
 *
 * 🔴 THE PRUNE IS AN INPUT AND AN OUTPUT (D-2). A branch cut from the answer is
 * reported as cut, with the relation type that cut it and the node it was cut
 * at. A notification list that silently omits a branch is worse than one that
 * omits it loudly, because the omission is invisible precisely when it matters:
 * somebody was not told, and nobody can see that they were not.
 *
 * 🔴 AND PRUNING RECOMPUTES REACHABILITY. Dropping the pruned EDGES while
 * keeping the nodes would leave every node beyond the cut still in the answer,
 * reached by nothing — a prune that reports a cut and changes no result. A node
 * still reachable another way stays; a node reachable only through the cut goes.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
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
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * Turns a bounded relation graph into the set of affected objects and parties.
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */
class AffectedSet implements AffectedSetInterface {

	/**
	 * A reached node that is a record.
	 *
	 * @var string
	 */
	public const KIND_OBJECT = 'object';

	/**
	 * A reached node that is a party.
	 *
	 * @var string
	 */
	public const KIND_PARTY = 'party';

	/**
	 * Derive the affected set from a graph answer.
	 *
	 * 🔴 A NODE THE CALLER MAY NOT READ IS NEITHER IN THE ANSWER NOR WALKED
	 * THROUGH. The walk loads every node under the caller's RBAC, so an
	 * unreadable record arrives unresolved; its edges are dropped before the
	 * walk, so nothing reachable only through it appears either, and no path
	 * names its uuid. Only resolved and external nodes are walked.
	 *
	 * @param array<string, mixed> $graph The answer from the bounded walk.
	 * @param AffectedSetQuery     $query The types to follow and prune, and the party schemas.
	 *
	 * @return array<string, mixed> The affected set.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function derive(array $graph, AffectedSetQuery $query): array {
		$nodes = $this->visibleNodes(graph: $graph);
		$split = $this->partitionEdges(edges: $this->edgesBetween(graph: $graph, nodes: $nodes), query: $query);
		$reached = $this->reachableFrom(root: (string)($graph['root'] ?? ''), edges: $split['kept']);

		return array_merge(
			['root' => (string)($graph['root'] ?? '')],
			$this->classifyNodes(nodes: $this->withPaths(nodes: $nodes, reached: $reached), query: $query),
			[
				'pruned' => $split['pruned'],
				// Passed through rather than recomputed: the walk is the only
				// thing that knows whether it stopped early.
				'truncated' => (bool)($graph['truncated'] ?? false),
				'truncatedBy' => ($graph['truncatedBy'] ?? null),
			]
		);
	}//end derive()

	/**
	 * The nodes the caller may see, keyed by uuid: resolved records and external addresses.
	 *
	 * @param array<string, mixed> $graph The graph.
	 *
	 * @return array<string, array<string, mixed>> The visible nodes.
	 */
	private function visibleNodes(array $graph): array {
		$visible = [];
		foreach (array_filter((array)($graph['nodes'] ?? []), 'is_array') as $node) {
			$uuid = (string)($node['uuid'] ?? '');
			if ($uuid !== '' && (($node['resolved'] ?? false) === true || ($node['external'] ?? false) === true)) {
				$visible[$uuid] = $node;
			}
		}

		return $visible;
	}//end visibleNodes()

	/**
	 * The edges whose two ends are both visible.
	 *
	 * @param array<string, mixed>                $graph The graph.
	 * @param array<string, array<string, mixed>> $nodes The visible nodes.
	 *
	 * @return array<int, array<string, mixed>> The edges.
	 */
	private function edgesBetween(array $graph, array $nodes): array {
		return array_values(
			array_filter(
				(array)($graph['edges'] ?? []),
				static fn (mixed $edge): bool => is_array($edge) === true
					&& isset($nodes[(string)($edge['from'] ?? '')], $nodes[(string)($edge['to'] ?? '')]) === true
			)
		);
	}//end edgesBetween()

	/**
	 * Split the edges into the ones kept and the ones pruned, reporting each cut.
	 *
	 * An edge of a pruned type is reported even when a type filter would also
	 * have dropped it: the caller named it, so the caller is told where it cut.
	 *
	 * @param array<int, array<string, mixed>> $edges The edges.
	 * @param AffectedSetQuery                 $query The query.
	 *
	 * @return array{kept: array<int, array<string, mixed>>, pruned: array<int, array{type: string, at: string, to: string}>}
	 */
	private function partitionEdges(array $edges, AffectedSetQuery $query): array {
		$split = ['kept' => [], 'pruned' => []];
		foreach ($edges as $edge) {
			$type = (string)($edge['type'] ?? '');
			if ($query->prunes(type: $type) === true) {
				$split['pruned'][] = ['type' => $type, 'at' => (string)($edge['from'] ?? ''), 'to' => (string)($edge['to'] ?? '')];
				continue;
			}

			if ($query->follows(type: $type) === true) {
				$split['kept'][] = $edge;
			}
		}

		return $split;
	}//end partitionEdges()

	/**
	 * The reached nodes, each carrying the path that reached it, in walk order.
	 *
	 * @param array<string, array<string, mixed>>      $nodes   The visible nodes.
	 * @param array<string, array<int, array<string, string>>> $reached The path to each reached uuid.
	 *
	 * @return array<int, array<string, mixed>> The reached nodes.
	 */
	private function withPaths(array $nodes, array $reached): array {
		$entries = [];
		foreach ($nodes as $uuid => $node) {
			if (array_key_exists($uuid, $reached) === true) {
				$entries[] = array_merge($node, ['path' => $reached[$uuid]]);
			}
		}

		return $entries;
	}//end withPaths()

	/**
	 * Sort the reached nodes into objects and parties.
	 *
	 * @param array<int, array<string, mixed>> $nodes The reached nodes.
	 * @param AffectedSetQuery                 $query The query naming the party schemas.
	 *
	 * @return array{objects: array<int, array<string, mixed>>, parties: array<int, array<string, mixed>>}
	 */
	private function classifyNodes(array $nodes, AffectedSetQuery $query): array {
		$sorted = ['objects' => [], 'parties' => []];
		foreach ($nodes as $node) {
			if ($query->isParty(schema: (string)($node['schema'] ?? '')) === true) {
				$sorted['parties'][] = array_merge($node, ['kind' => self::KIND_PARTY]);
				continue;
			}

			$sorted['objects'][] = array_merge($node, ['kind' => self::KIND_OBJECT]);
		}

		return $sorted;
	}//end classifyNodes()

	/**
	 * The shortest path from the root to every node the kept edges reach.
	 *
	 * Breadth-first, so each node carries the first path that reached it. The
	 * root is not in the answer: it is not in its own affected set.
	 *
	 * @param string                           $root  The root uuid.
	 * @param array<int, array<string, mixed>> $edges The kept edges.
	 *
	 * @return array<string, array<int, array<string, string>>> The path to each reached uuid.
	 */
	private function reachableFrom(string $root, array $edges): array {
		$outgoing = $this->outgoing(edges: $edges);
		$paths = [$root => []];
		$queue = [$root];
		while ($queue !== []) {
			$from  = (string)array_shift($queue);
			$found = self::newlyReached(steps: ($outgoing[$from] ?? []), paths: $paths);
			$paths = $paths + $found;
			foreach (array_keys($found) as $to) {
				$queue[] = (string)$to;
			}
		}

		unset($paths[$root]);
		return $paths;
	}//end reachableFrom()


	/**
	 * The paths to the neighbours of one node that no earlier path reached.
	 *
	 * @param array<string, array<string, string>>             $steps The edges leaving the node, keyed by the uuid they reach.
	 * @param array<string, array<int, array<string, string>>> $paths The paths found so far.
	 *
	 * @return array<string, array<int, array<string, string>>> The path to each newly reached uuid, in edge order.
	 */
	private static function newlyReached(array $steps, array $paths): array {
		$found = [];
		foreach ($steps as $to => $step) {
			if (array_key_exists($to, $paths) === false) {
				$found[$to] = array_merge($paths[$step['from']], [$step]);
			}
		}

		return $found;
	}//end newlyReached()

	/**
	 * The kept edges grouped by the node they leave: from => [to => edge], first edge wins.
	 *
	 * @param array<int, array<string, mixed>> $edges The kept edges.
	 *
	 * @return array<string, array<string, array<string, string>>> The outgoing edges.
	 */
	private function outgoing(array $edges): array {
		$outgoing = [];
		foreach ($edges as $edge) {
			$from = (string)($edge['from'] ?? '');
			$to = (string)($edge['to'] ?? '');
			if ($from !== '' && $to !== '' && isset($outgoing[$from][$to]) === false) {
				$outgoing[$from][$to] = ['from' => $from, 'to' => $to, 'type' => (string)($edge['type'] ?? '')];
			}
		}

		return $outgoing;
	}//end outgoing()
}//end class
