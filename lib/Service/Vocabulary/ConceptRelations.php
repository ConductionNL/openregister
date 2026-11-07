<?php

/**
 * OpenRegister ConceptRelations
 *
 * The broader/narrower relations of one concept scheme, read from an
 * in-memory map of concepts keyed by uri. Split out of
 * {@see ConceptHierarchy} so the walk over the tree and the reading of the
 * relation dialect each stay small.
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

/**
 * Reads the narrower concepts of a scheme from both sides of the relation.
 */
final class ConceptRelations {

	/**
	 * The keys a relation-list map may carry its uri under, in priority order.
	 *
	 * @var array<int,string>
	 */
	private const URI_KEYS = ['uri', 'id', 'uuid', '@id'];

	/**
	 * Constructor.
	 *
	 * @param array<string,array<string,mixed>> $conceptsByUri Every concept of the scheme, keyed by uri.
	 */
	public function __construct(
		private readonly array $conceptsByUri,
	) {

	}//end __construct()

	/**
	 * The decoded data of one concept, or an empty map when it is unknown.
	 *
	 * @param string $uri The concept's uri.
	 *
	 * @return array<string,mixed> The concept.
	 */
	public function concept(string $uri): array {
		return ($this->conceptsByUri[$uri] ?? []);
	}//end concept()

	/**
	 * The uris of every concept directly under `$uri`.
	 *
	 * Both directions of the relation are read and merged: the concept's own
	 * `narrower`, plus every concept whose `broader` names it.
	 *
	 * @param string $uri The parent concept's uri.
	 *
	 * @return array<int,string> The child uris.
	 */
	public function narrowerOf(string $uri): array {
		$children = array_fill_keys($this->declaredNarrowerOf(uri: $uri), true);
		foreach (array_keys($this->conceptsByUri) as $candidateUri) {
			if ($this->namesAsBroader(candidateUri: (string) $candidateUri, uri: $uri) === true) {
				$children[(string) $candidateUri] = true;
			}
		}

		return array_keys($children);
	}//end narrowerOf()

	/**
	 * The children of a frontier that are not yet known, each once, in order.
	 *
	 * @param array<int,string> $frontier The uris whose children to read.
	 * @param array<int,string> $known    The uris already reached.
	 *
	 * @return array<int,string> The newly reached uris.
	 */
	public function unseenChildren(array $frontier, array $known): array {
		$found = [];
		foreach ($frontier as $uri) {
			array_push($found, ...$this->narrowerOf(uri: $uri));
		}

		$seen = array_fill_keys($known, true);
		$fresh = array_filter(
			array_unique($found),
			static fn ($child): bool => isset($seen[$child]) === false
		);

		return array_values($fresh);
	}//end unseenChildren()

	/**
	 * The concept's own `narrower` uris that exist in the scheme.
	 *
	 * @param string $uri The parent concept's uri.
	 *
	 * @return array<int,string> The declared child uris.
	 */
	private function declaredNarrowerOf(string $uri): array {
		$declared = $this->relationUris(value: ($this->concept(uri: $uri)['narrower'] ?? null));

		return array_values(
			array_filter(
				$declared,
				fn (string $child): bool => isset($this->conceptsByUri[$child]) === true
			)
		);
	}//end declaredNarrowerOf()

	/**
	 * Whether another concept names `$uri` in its `broader` relation.
	 *
	 * @param string $candidateUri The concept that may point upward.
	 * @param string $uri          The concept it may point at.
	 *
	 * @return boolean True when the candidate sits directly under `$uri`.
	 */
	private function namesAsBroader(string $candidateUri, string $uri): bool {
		if ($candidateUri === $uri) {
			return false;
		}

		$broader = $this->relationUris(value: ($this->conceptsByUri[$candidateUri]['broader'] ?? null));

		return in_array($uri, $broader, true);
	}//end namesAsBroader()

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
			$value = [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		$uris = array_map(fn (mixed $entry): ?string => $this->uriFromRelationEntry(entry: $entry), $value);

		return array_values(array_filter($uris, static fn (?string $uri): bool => $uri !== null));
	}//end relationUris()

	/**
	 * Read a single uri out of one relation-list entry.
	 *
	 * An entry is either a bare uri string or a `{uri: ...}`/`{id: ...}` map;
	 * for a map the first non-empty of `uri`, `id`, `uuid`, `@id` wins. An empty
	 * string, or a map holding none of those keys, yields null so the caller
	 * drops it.
	 *
	 * @param mixed $entry The raw relation-list entry.
	 *
	 * @return string|null The referenced uri, or null when the entry names none.
	 */
	private function uriFromRelationEntry(mixed $entry): ?string {
		foreach ($this->uriCandidates(entry: $entry) as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}//end uriFromRelationEntry()

	/**
	 * The values one relation-list entry may name its uri by, in priority order.
	 *
	 * @param mixed $entry The raw relation-list entry.
	 *
	 * @return array<int,mixed> The entry itself, or its uri-bearing fields when it is a map.
	 */
	private function uriCandidates(mixed $entry): array {
		if (is_array($entry) === false) {
			return [$entry];
		}

		$candidates = [];
		foreach (self::URI_KEYS as $key) {
			$candidates[] = ($entry[$key] ?? null);
		}

		return $candidates;
	}//end uriCandidates()
}//end class
