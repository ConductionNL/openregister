<?php

/**
 * What changed between two flow graphs, and whether it breaks anybody.
 *
 * THE RULE IS ABOUT REMOVAL, and that is the whole design. A consumer of a
 * flow can depend on three things: that a step exists, that a path connects,
 * and that a step still reads the key it read. Every one of those is broken by
 * taking something away, and none of them by adding.
 *
 * Changing a VALUE can break a consumer too — an assignee that no longer
 * resolves, a threshold that moves — but that is not detectable as breaking
 * from the graph alone. Guessing would produce majors nobody believes, and a
 * version people ignore carries no information at all. That gap is what the
 * author's explicit override exists for, and why the override only goes
 * upward: the diff is evidence, and evidence can be added to but not argued
 * down.
 *
 * 🔴 A CONFIG KEY REMOVED FROM A NODE THAT ALSO DISAPPEARED COUNTS ONCE. It is
 * one change — the node went — and counting it twice inflates the list the
 * author reads before publishing. That list is the part that makes the verdict
 * credible, so padding it is not a cosmetic problem.
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
 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-taking-something-away-is-major-everything-else-is-minor
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Compares two graphs and reports what a publish would take away.
 */
class FlowGraphDiff {

	/**
	 * The verdict when something a consumer could depend on was removed.
	 *
	 * @var string
	 */
	public const MAJOR = 'major';

	/**
	 * The verdict for every other difference, including none at all.
	 *
	 * @var string
	 */
	public const MINOR = 'minor';

	/**
	 * What the candidate graph takes away from the published one.
	 *
	 * @param array<string, mixed> $published The graph currently published.
	 * @param array<string, mixed> $candidate The graph about to be published.
	 *
	 * @return array{verdict: string, removedNodes: array<int, string>, removedEdges: array<int, string>, removedKeys: array<int, string>}
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-taking-something-away-is-major-everything-else-is-minor
	 */
	public function compare(array $published, array $candidate): array {
		$before = $this->nodesById(graph: $published);
		$after = $this->nodesById(graph: $candidate);

		$removedNodes = array_values(array_diff(array_keys($before), array_keys($after)));
		sort($removedNodes);

		$removedEdges = array_values(
			array_diff($this->edgeKeys(graph: $published), $this->edgeKeys(graph: $candidate))
		);
		sort($removedEdges);

		$removedKeys = [];
		foreach ($before as $id => $node) {
			// 🔴 ONLY SURVIVING NODES. A key that went with its node is one
			// change, already reported as the node.
			if (array_key_exists($id, $after) === false) {
				continue;
			}

			$gone = array_diff(
				array_keys($this->configOf(node: $node)),
				array_keys($this->configOf(node: $after[$id]))
			);

			foreach ($gone as $key) {
				$removedKeys[] = $id . '.' . $key;
			}
		}

		sort($removedKeys);

		$breaks = ($removedNodes !== [] || $removedEdges !== [] || $removedKeys !== []);

		$verdict = self::MINOR;
		if ($breaks === true) {
			$verdict = self::MAJOR;
		}

		return [
			'verdict' => $verdict,
			'removedNodes' => $removedNodes,
			'removedEdges' => $removedEdges,
			'removedKeys' => $removedKeys,
		];
	}//end compare()

	/**
	 * A short sentence naming what was taken away, for the author to read
	 * before they publish.
	 *
	 * Empty when nothing was removed: a caller writing "this publish removes:"
	 * in front of an empty list is the shape this avoids.
	 *
	 * @param array{removedNodes: array<int, string>, removedEdges: array<int, string>, removedKeys: array<int, string>} $diff The comparison.
	 *
	 * @return string The summary, or '' when nothing was removed.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-the-author-is-told-what-it-will-be-before-publishing
	 */
	public function summarise(array $diff): string {
		$parts = [];

		if (($diff['removedNodes'] ?? []) !== []) {
			$parts[] = 'steps ' . implode(', ', $diff['removedNodes']);
		}

		if (($diff['removedEdges'] ?? []) !== []) {
			$parts[] = 'connections ' . implode(', ', $diff['removedEdges']);
		}

		if (($diff['removedKeys'] ?? []) !== []) {
			$parts[] = 'settings ' . implode(', ', $diff['removedKeys']);
		}

		if ($parts === []) {
			return '';
		}

		return 'This removes ' . implode('; ', $parts) . '.';
	}//end summarise()

	/**
	 * The graph's nodes, keyed by id, skipping anything without one.
	 *
	 * A node with no id cannot be compared with anything, and inventing a key
	 * for it would report it as removed on every publish.
	 *
	 * @param array<string, mixed> $graph The graph.
	 *
	 * @return array<string, array<string, mixed>> The nodes.
	 */
	private function nodesById(array $graph): array {
		$byId = [];

		foreach (($graph['nodes'] ?? []) as $node) {
			if (is_array($node) === false) {
				continue;
			}

			$id = trim((string)($node['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$byId[$id] = $node;
		}

		return $byId;
	}//end nodesById()

	/**
	 * Every edge as a comparable `from->to` string.
	 *
	 * Endpoints are normalised the way the engine reads them: `from` and `to`
	 * are each either a string or a list, and the same connection written both
	 * ways must not read as a removal plus an addition.
	 *
	 * @param array<string, mixed> $graph The graph.
	 *
	 * @return array<int, string> The edge keys.
	 */
	private function edgeKeys(array $graph): array {
		$keys = [];

		foreach (($graph['edges'] ?? []) as $edge) {
			if (is_array($edge) === false) {
				continue;
			}

			foreach ($this->endpoints(value: ($edge['from'] ?? null)) as $from) {
				foreach ($this->endpoints(value: ($edge['to'] ?? null)) as $to) {
					$keys[] = $from . '->' . $to;
				}
			}
		}

		return array_values(array_unique($keys));
	}//end edgeKeys()

	/**
	 * One endpoint value as a list of node ids.
	 *
	 * @param mixed $value The raw endpoint.
	 *
	 * @return array<int, string> The ids.
	 */
	private function endpoints(mixed $value): array {
		$list = [$value];
		if (is_array($value) === true) {
			$list = $value;
		}

		$ids = [];
		foreach ($list as $item) {
			$id = trim((string)$item);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end endpoints()

	/**
	 * A node's configuration, as an array whatever it was stored as.
	 *
	 * An empty config is persisted as `[]` by some writers and omitted by
	 * others; both mean "no keys", and neither is a removal.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function configOf(array $node): array {
		$config = ($node['config'] ?? []);
		if (is_array($config) === false) {
			return [];
		}

		return $config;
	}//end configOf()
}//end class
