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
 * @spec openspec/specs/skos-concept-registers/spec.md
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
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function branchUris(string $rootUri, array $conceptsByUri, ?int $maxDepth = null): array {
		$relations = new ConceptRelations(conceptsByUri: $conceptsByUri);
		$limit = ($maxDepth ?? self::DEFAULT_MAX_DEPTH);
		$ordered = [$rootUri];
		$frontier = [$rootUri];

		for ($depth = 0; $depth < $limit && $frontier !== []; $depth++) {
			$frontier = $relations->unseenChildren(frontier: $frontier, known: $ordered);
			$ordered = array_merge($ordered, $frontier);
		}

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
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function isLeaf(string $uri, array $conceptsByUri): bool {
		return (new ConceptRelations(conceptsByUri: $conceptsByUri))->narrowerOf(uri: $uri) === [];
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
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function tree(string $rootUri, array $conceptsByUri, string $language, DateTimeInterface $at, ?int $maxDepth = null): array {
		$walk = new ConceptTreeWalk(
			relations: new ConceptRelations(conceptsByUri: $conceptsByUri),
			language: $language,
			instant: $at,
			remaining: ($maxDepth ?? self::DEFAULT_MAX_DEPTH)
		);

		return $this->node(uri: $rootUri, walk: $walk);
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
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function labelOf(array $concept, string $language): string {
		$label = null;
		$labels = ($concept['prefLabel'] ?? null);
		if (is_array($labels) === true) {
			$label = $this->preferredLabelFromMap(labels: $labels, language: $language);
		}

		return ($label ?? $this->fallbackLabel(concept: $concept));
	}//end labelOf()

	/**
	 * The label of a concept that has no usable `prefLabel`.
	 *
	 * The notation when it is filled, else the uri, else an empty string.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return string The label.
	 */
	private function fallbackLabel(array $concept): string {
		$notation = ($concept['notation'] ?? null);
		if ($this->isFilled(value: $notation) === true) {
			return $notation;
		}

		return (string) ($concept['uri'] ?? '');
	}//end fallbackLabel()

	/**
	 * Pick the best label from a decoded `prefLabel` map.
	 *
	 * The asked-for tag wins, then its two-letter base, then Dutch (which the
	 * concept schema requires), then the first non-empty label present. A blank
	 * or non-string value is skipped rather than returned, so a half-filled map
	 * never yields an empty label.
	 *
	 * @param array<string,mixed> $labels The decoded `prefLabel` map.
	 * @param string $language The BCP-47 tag asked for.
	 *
	 * @return string|null The chosen label, or null when the map holds none.
	 */
	private function preferredLabelFromMap(array $labels, string $language): ?string {
		$preferred = [];
		foreach ([$language, substr($language, 0, 2), 'nl'] as $tag) {
			$preferred[] = ($labels[$tag] ?? null);
		}

		foreach (array_merge($preferred, array_values($labels)) as $candidate) {
			if ($this->isFilled(value: $candidate) === true) {
				return $candidate;
			}
		}

		return null;
	}//end preferredLabelFromMap()

	/**
	 * Whether a label candidate is a non-blank string.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return boolean True when it can be shown as a label.
	 */
	private function isFilled(mixed $value): bool {
		return is_string($value) === true && trim($value) !== '';
	}//end isFilled()

	/**
	 * One tree node, recursing into its narrower concepts.
	 *
	 * @param string $uri The concept's uri.
	 * @param ConceptTreeWalk $walk The walk's position: relations, language, instant, depth and path.
	 *
	 * @return array<string,mixed> The node.
	 */
	private function node(string $uri, ConceptTreeWalk $walk): array {
		$children = $this->childNodes(uri: $uri, walk: $walk);
		$concept = $walk->relations->concept(uri: $uri);

		return [
			'value' => $uri,
			'label' => $this->labelOf(concept: $concept, language: $walk->language),
			'notation' => ($concept['notation'] ?? null),
			'offerable' => $this->lifecycle->isOfferable(concept: $concept, at: $walk->instant),
			'leaf' => ($walk->relations->narrowerOf(uri: $uri) === []),
			'children' => $children,
		];
	}//end node()

	/**
	 * The nodes of the concepts directly under `$uri` that are not on the path.
	 *
	 * @param string $uri The parent concept's uri.
	 * @param ConceptTreeWalk $walk The walk's position at the parent.
	 *
	 * @return array<int,array<string,mixed>> The child nodes.
	 */
	private function childNodes(string $uri, ConceptTreeWalk $walk): array {
		if ($walk->canDescend() === false) {
			return [];
		}

		$below = $walk->below(uri: $uri);
		$children = [];
		foreach ($walk->relations->narrowerOf(uri: $uri) as $child) {
			if ($below->hasVisited(uri: $child) === false) {
				$children[] = $this->node(uri: $child, walk: $below);
			}
		}

		return $children;
	}//end childNodes()
}//end class
