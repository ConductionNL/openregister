<?php

/**
 * OpenRegister ConceptHierarchy
 *
 * `broader` and `narrower` already round-trip through the SKOS importer. What
 * was missing is anything that USES them: a property saying "my values are the
 * narrower concepts of this branch", and a filter that matches an object
 * holding any of them.
 *
 * The tree is read live and walked here, bounded by depth. It is deliberately
 * not denormalised into a stored path: a gemeente moves a concept under a new
 * broader term and every property pointing at the branch has to follow, with
 * no schema save anywhere (design.md D-3). A stored path would go stale the
 * moment it was written, and a stale path is a filter that silently returns
 * the wrong set.
 *
 * Pure: it walks an in-memory map of concepts keyed by uri. The reading of
 * that map from the register is {@see ConceptRepository}'s job.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vocabulary
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vocabulary;

use DateTimeInterface;

/**
 * Walks a concept scheme's broader/narrower relations.
 */
class ConceptHierarchy {

	/**
	 * How deep a branch walk goes when the declaration names no bound.
	 *
	 * A vocabulary hierarchy that is deeper than this is not a hierarchy, it
	 * is a cycle someone has not noticed yet. The bound is named in the spec
	 * (REQ-CLH-002) precisely so a malformed scheme cannot turn one filter
	 * into an unbounded walk.
	 *
	 * @var integer
	 */
	public const DEFAULT_MAX_DEPTH = 5;

	/**
	 * Constructor.
	 *
	 * @param ConceptLifecycle $lifecycle Answers whether a value may be offered.
	 */
	public function __construct(
		private readonly ConceptLifecycle $lifecycle,
	) {

	}//end __construct()

	/**
	 * Every concept uri at or below `$rootUri`, bounded by depth.
	 *
	 * The root itself is included: filtering a list on the branch `vergunning`
	 * must also return the objects that hold `vergunning` directly, not only
	 * the ones holding `kapvergunning`.
	 *
	 * Cycles are survivable by construction: a uri already visited is never
	 * expanded twice, so a scheme whose `narrower` relations loop terminates
	 * with the set it reached rather than with a hung request.
	 *
	 * @param string $rootUri The branch root's uri.
	 * @param array<string,array<string,mixed>> $conceptsByUri Every concept of the scheme, keyed by uri.
	 * @param integer|null $maxDepth How many levels below the root to walk.
	 *
	 * @return array<int,string> The branch's uris, root first.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function branchUris(string $rootUri, array $conceptsByUri, ?int $maxDepth = null): array {
		$limit = ($maxDepth ?? self::DEFAULT_MAX_DEPTH);
		if ($limit < 0) {
			$limit = 0;
		}

		$seen = [$rootUri => true];
		$ordered = [$rootUri];
		$frontier = [$rootUri];

		for ($depth = 0; $depth < $limit; $depth++) {
			$next = [];
			foreach ($frontier as $uri) {
				foreach ($this->narrowerUrisOf(uri: $uri, conceptsByUri: $conceptsByUri) as $child) {
					if (isset($seen[$child]) === true) {
						continue;
					}

					$seen[$child] = true;
					$ordered[] = $child;
					$next[] = $child;
				}
			}

			if ($next === []) {
				break;
			}

			$frontier = $next;
		}//end for

		return $ordered;
	}//end branchUris()

	/**
	 * Whether the concept has no narrower concepts in this scheme.
	 *
	 * Read from the scheme rather than from the concept's own `narrower` list,
	 * because a one-sided import leaves `narrower` empty on a term that other
	 * terms nonetheless point at through `broader`.
	 *
	 * @param string $uri The concept's uri.
	 * @param array<string,array<string,mixed>> $conceptsByUri Every concept of the scheme, keyed by uri.
	 *
	 * @return boolean True when nothing sits under it.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function isLeaf(string $uri, array $conceptsByUri): bool {
		return $this->narrowerUrisOf(uri: $uri, conceptsByUri: $conceptsByUri) === [];
	}//end isLeaf()

	/**
	 * Build the option tree a form renders, from a branch root.
	 *
	 * Each node is `{value, label, notation, offerable, children}`. An
	 * unofferable node is kept with `offerable: false` rather than dropped,
	 * so a picker can still show the path to a value that is offerable while
	 * its parent is not. A caller that wants only the offerable values filters
	 * the flattened list instead.
	 *
	 * @param string $rootUri The branch root's uri.
	 * @param array<string,array<string,mixed>> $conceptsByUri Every concept of the scheme, keyed by uri.
	 * @param string $language The BCP-47 tag to read labels in.
	 * @param DateTimeInterface $at The instant to judge each window at.
	 * @param integer|null $maxDepth How many levels below the root to walk.
	 *
	 * @return array<string,mixed> The tree node for the root.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function tree(
		string $rootUri,
		array $conceptsByUri,
		string $language,
		DateTimeInterface $at,
		?int $maxDepth = null,
	): array {
		return $this->node(
			uri: $rootUri,
			conceptsByUri: $conceptsByUri,
			language: $language,
			at: $at,
			remaining: ($maxDepth ?? self::DEFAULT_MAX_DEPTH),
			visited: []
		);
	}//end tree()

	/**
	 * The preferred label of a concept in the asked-for language.
	 *
	 * Falls back to Dutch (which the concept schema requires), then to the
	 * first label present, then to the notation, then to the uri. A picker
	 * showing a uri is ugly; a picker showing nothing is broken.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 * @param string $language The BCP-47 tag asked for.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function labelOf(array $concept, string $language): string {
		$labels = ($concept['prefLabel'] ?? null);
		if (is_array($labels) === true) {
			foreach ([$language, substr($language, 0, 2), 'nl'] as $tag) {
				$candidate = ($labels[$tag] ?? null);
				if (is_string($candidate) === true && trim($candidate) !== '') {
					return $candidate;
				}
			}

			foreach ($labels as $candidate) {
				if (is_string($candidate) === true && trim($candidate) !== '') {
					return $candidate;
				}
			}
		}

		$notation = ($concept['notation'] ?? null);
		if (is_string($notation) === true && trim($notation) !== '') {
			return $notation;
		}

		return (string)($concept['uri'] ?? '');
	}//end labelOf()

	/**
	 * One tree node, recursing into its narrower concepts.
	 *
	 * @param string $uri The concept's uri.
	 * @param array<string,array<string,mixed>> $conceptsByUri Every concept of the scheme, keyed by uri.
	 * @param string $language The BCP-47 tag to read labels in.
	 * @param DateTimeInterface $at The instant to judge the window at.
	 * @param integer $remaining How many more levels may be walked.
	 * @param array<string,true> $visited The uris already on this path.
	 *
	 * @return array<string,mixed> The node.
	 */
	private function node(
		string $uri,
		array $conceptsByUri,
		string $language,
		DateTimeInterface $at,
		int $remaining,
		array $visited,
	): array {
		$concept = ($conceptsByUri[$uri] ?? []);
		$visited[$uri] = true;

		$children = [];
		if ($remaining > 0) {
			foreach ($this->narrowerUrisOf(uri: $uri, conceptsByUri: $conceptsByUri) as $child) {
				if (isset($visited[$child]) === true) {
					continue;
				}

				$children[] = $this->node(
					uri: $child,
					conceptsByUri: $conceptsByUri,
					language: $language,
					at: $at,
					remaining: ($remaining - 1),
					visited: $visited
				);
			}
		}

		return [
			'value' => $uri,
			'label' => $this->labelOf(concept: $concept, language: $language),
			'notation' => ($concept['notation'] ?? null),
			'offerable' => $this->lifecycle->isOfferable(concept: $concept, at: $at),
			'leaf' => ($this->narrowerUrisOf(uri: $uri, conceptsByUri: $conceptsByUri) === []),
			'children' => $children,
		];
	}//end node()

	/**
	 * The uris of every concept directly under `$uri`.
	 *
	 * Both directions of the relation are read and merged: the concept's own
	 * `narrower`, plus every concept whose `broader` names it.
	 *
	 * @param string $uri The parent concept's uri.
	 * @param array<string,array<string,mixed>> $conceptsByUri Every concept of the scheme, keyed by uri.
	 *
	 * @return array<int,string> The child uris.
	 */
	private function narrowerUrisOf(string $uri, array $conceptsByUri): array {
		$children = [];

		$parent = ($conceptsByUri[$uri] ?? []);
		foreach ($this->relationUris(value: ($parent['narrower'] ?? null)) as $child) {
			if (isset($conceptsByUri[$child]) === true) {
				$children[$child] = true;
			}
		}

		foreach ($conceptsByUri as $candidateUri => $candidate) {
			if ((string)$candidateUri === $uri) {
				continue;
			}

			foreach ($this->relationUris(value: ($candidate['broader'] ?? null)) as $broader) {
				if ($broader === $uri) {
					$children[(string)$candidateUri] = true;
				}
			}
		}

		return array_keys($children);
	}//end narrowerUrisOf()

	/**
	 * Normalise a SKOS relation value to a list of uris.
	 *
	 * The relation dialect stores either a bare string, a list of strings, or
	 * a list of `{uri: ...}`/`{id: ...}` maps depending on whether the read
	 * extended the relation. All three are read here so a caller never has to
	 * know which one it got.
	 *
	 * @param mixed $value The raw relation value.
	 *
	 * @return array<int,string> The referenced uris.
	 */
	private function relationUris(mixed $value): array {
		if (is_string($value) === true) {
			return ($value === '' ? [] : [$value]);
		}

		if (is_array($value) === false) {
			return [];
		}

		$uris = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true && $entry !== '') {
				$uris[] = $entry;
				continue;
			}

			if (is_array($entry) === false) {
				continue;
			}

			foreach (['uri', 'id', 'uuid', '@id'] as $key) {
				$candidate = ($entry[$key] ?? null);
				if (is_string($candidate) === true && $candidate !== '') {
					$uris[] = $candidate;
					break;
				}
			}
		}//end foreach

		return $uris;
	}//end relationUris()
}//end class
