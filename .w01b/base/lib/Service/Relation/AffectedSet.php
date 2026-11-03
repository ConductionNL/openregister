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

use InvalidArgumentException;

/**
 * Turns a bounded relation graph into the set of affected objects and parties.
 *
 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
 */
class AffectedSet {

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
	 * 🔴 `null` AND `[]` ARE DIFFERENT for both lists, and the difference is the
	 * one that turns a filter into an unconditional pass. `types: null` means
	 * "not filtered by type"; `types: []` is refused, because a caller who
	 * named no types either meant everything or meant nothing and those are
	 * opposite answers. `prune: []` is simply nothing pruned, which is
	 * unambiguous, so it is allowed.
	 *
	 * @param array<string, mixed>    $graph        The answer from the bounded walk.
	 * @param array<int, string>|null $types        Relation types to keep, or null for all.
	 * @param array<int, string>      $prune        Relation types to cut.
	 * @param array<int, string>      $partySchemas Which schemas are parties.
	 *
	 * @return array<string, mixed> The affected set.
	 *
	 * @throws InvalidArgumentException When `types` is present but empty.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	public function derive(array $graph, ?array $types = null, array $prune = [], array $partySchemas = []): array {
		if ($types !== null && $types === []) {
			throw new InvalidArgumentException(
				'An empty relation-type list is refused: leave it out to mean "every type", '
				. 'because an empty list reads as both "none" and "all".'
			);
		}

		$root = (string)($graph['root'] ?? '');
		$edges = (array)($graph['edges'] ?? []);
		$nodes = (array)($graph['nodes'] ?? []);

		['kept' => $kept, 'pruned' => $pruned] = $this->partitionEdges(
			edges: $edges,
			types: $types,
			prune: $prune
		);

		$reachable = $this->reachableFrom(root: $root, edges: $kept);

		['objects' => $objects, 'parties' => $parties] = $this->classifyNodes(
			nodes: $nodes,
			root: $root,
			reachable: $reachable,
			partySchemas: $partySchemas
		);

		return [
			'root' => $root,
			'objects' => $objects,
			'parties' => $parties,
			'pruned' => $pruned,
			// Passed through rather than recomputed: the walk is the only thing
			// that knows whether it stopped early, and an affected set that
			// reported "not truncated" over a truncated walk would be a
			// complete-looking answer to an incomplete question.
			'truncated' => (bool)($graph['truncated'] ?? false),
			'truncatedBy' => ($graph['truncatedBy'] ?? null),
		];
	}//end derive()

	/**
	 * Split the walk's edges into the ones that survive and the ones cut.
	 *
	 * Pruning is checked BEFORE the type filter, as it always has been: a type
	 * that is both pruned and kept is pruned, and it is reported as pruned
	 * rather than silently dropped by the filter.
	 *
	 * @param array<int, mixed>       $edges The walk's edges.
	 * @param array<int, string>|null $types Relation types to keep, or null for all.
	 * @param array<int, string>      $prune Relation types to cut.
	 *
	 * @return array{kept: array<int, mixed>, pruned: array<int, array{type: string, at: string, to: string}>} The split.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	private function partitionEdges(array $edges, ?array $types, array $prune): array {
		$kept = [];
		$pruned = [];

		foreach ($edges as $edge) {
			if (is_array($edge) === false) {
				continue;
			}

			$type = (string)($edge['type'] ?? '');

			if (in_array($type, $prune, true) === true) {
				$pruned[] = [
					'type' => $type,
					'at' => (string)($edge['from'] ?? ''),
					'to' => (string)($edge['to'] ?? ''),
				];
				continue;
			}

			if ($types !== null && in_array($type, $types, true) === false) {
				continue;
			}

			$kept[] = $edge;
		}//end foreach

		return [
			'kept' => $kept,
			'pruned' => $pruned,
		];
	}//end partitionEdges()

	/**
	 * Split the reachable nodes into plain objects and parties.
	 *
	 * The root itself is never in either list: it is what the question was
	 * about, not something the question affected.
	 *
	 * @param array<int, mixed>     $nodes        The walk's nodes.
	 * @param string                $root         The object the walk started from.
	 * @param array<string, mixed>  $reachable    Path by uuid, from the surviving edges.
	 * @param array<int, string>    $partySchemas Which schemas are parties.
	 *
	 * @return array{objects: array<int, array<string, mixed>>, parties: array<int, array<string, mixed>>} The split.
	 *
	 * @spec openspec/changes/relations-that-travel-and-what-they-expose/specs/referential-integrity/spec.md
	 */
	private function classifyNodes(array $nodes, string $root, array $reachable, array $partySchemas): array {
		$objects = [];
		$parties = [];

		foreach ($nodes as $node) {
			if (is_array($node) === false) {
				continue;
			}

			$uuid = (string)($node['uuid'] ?? '');
			if ($uuid === '' || $uuid === $root || array_key_exists($uuid, $reachable) === false) {
				continue;
			}

			$entry = $node;
			$entry['path'] = $reachable[$uuid];
			$entry['kind'] = self::KIND_OBJECT;

			if (in_array((string)($node['schema'] ?? ''), $partySchemas, true) === true) {
				$entry['kind'] = self::KIND_PARTY;
				$parties[] = $entry;
				continue;
			}

			$objects[] = $entry;
		}//end foreach

		return [
			'objects' => $objects,
			'parties' => $parties,
		];
	}//end classifyNodes()

	/**
	 * Which nodes remain reachable, and by which path.
	 *
	 * A breadth-first walk over the SURVIVING edges, so a node reachable only
	 * through a pruned or filtered edge does not appear at all. The path is the
	 * shortest one found, which is the one a reader wants when asked why
	 * somebody is on the list.
	 *
	 * @param string            $root  The root uuid.
	 * @param array<int, mixed> $edges The surviving edges.
	 *
	 * @return array<string, array<int, array<string, string>>> Uuid to the path that reached it.
	 */
	private function reachableFrom(string $root, array $edges): array {
		$outgoing = [];
		foreach ($edges as $edge) {
			$from = (string)($edge['from'] ?? '');
			if ($from === '') {
				continue;
			}

			if (isset($outgoing[$from]) === false) {
				$outgoing[$from] = [];
			}

			$outgoing[$from][] = $edge;
		}

		$paths = [];
		$frontier = [$root];
		$seen = [$root => true];

		while ($frontier !== []) {
			$next = [];
			foreach ($frontier as $uuid) {
				foreach (($outgoing[$uuid] ?? []) as $edge) {
					$to = (string)($edge['to'] ?? '');
					if ($to === '' || array_key_exists($to, $seen) === true) {
						continue;
					}

					$seen[$to] = true;
					$paths[$to] = array_merge(
						($paths[$uuid] ?? []),
						[['from' => $uuid, 'to' => $to, 'type' => (string)($edge['type'] ?? '')]]
					);
					$next[] = $to;
				}
			}

			$frontier = $next;
		}//end while

		return $paths;
	}//end reachableFrom()
}//end class
