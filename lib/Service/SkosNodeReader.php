<?php

/**
 * SkosNodeReader — reads predicate values out of lightweight SKOS/JSON-LD nodes.
 *
 * Predicate keys may be bare, `prefix:local`, or a full IRI; values may be a
 * bare string, a `{"@value": ...}` literal node, a `{"@id": ...}` reference
 * node, or a list of those. Used by {@see VocabularyImportService}.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Pure reader over JSON-LD node values.
 */
class SkosNodeReader {
	/**
	 * The local name of an IRI or prefixed name — the substring after the
	 * rightmost `#`, `/` or `:`.
	 *
	 * @param string $iriOrPrefixed A full IRI, a `prefix:local` name, or a bare local name.
	 *
	 * @return string The local name.
	 */
	public function localName(string $iriOrPrefixed): string {
		$positions = array_filter(
			array_map(static fn (string $separator) => strrpos($iriOrPrefixed, $separator), ['#', '/', ':']),
			static fn (int|false $position): bool => $position !== false
		);
		if ($positions === []) {
			return $iriOrPrefixed;
		}

		return substr($iriOrPrefixed, max($positions) + 1);
	}//end localName()

	/**
	 * Find the first value of a node whose key's local name matches `$localName`.
	 *
	 * @param array<string,mixed> $node The JSON-LD node.
	 * @param string $localName The predicate local name to find (e.g. "prefLabel").
	 *
	 * @return mixed The matched value, or null when absent.
	 */
	public function findPredicate(array $node, string $localName): mixed {
		foreach ($node as $key => $value) {
			if (str_starts_with($key, '@') === true) {
				continue;
			}

			if ($this->localName(iriOrPrefixed: $key) === $localName) {
				return $value;
			}
		}

		return null;
	}//end findPredicate()

	/**
	 * Extract a single string literal: a bare string, a `{"@value": ...}` node,
	 * or the first non-empty match in a list of either.
	 *
	 * @param mixed $value The raw predicate value.
	 *
	 * @return string|null The extracted literal, or null when absent/empty.
	 */
	public function literal(mixed $value): ?string {
		if (is_string($value) === true) {
			return $this->trimmedOrNull(value: $value);
		}

		if (is_array($value) === false) {
			return null;
		}

		if (isset($value['@value']) === true) {
			return (string)$value['@value'];
		}

		return $this->firstLiteral(items: $value);
	}//end literal()

	/**
	 * Extract a BCP-47-keyed language map: a list of `{"@value", "@language"}`
	 * nodes, a single such node, a bare string (assumed `nl`), or an assoc
	 * shorthand `{"nl": "...", "en": "..."}`.
	 *
	 * @param mixed $value The raw predicate value.
	 *
	 * @return array<string,string> BCP-47 tag => label.
	 */
	public function langMap(mixed $value): array {
		if (is_string($value) === true) {
			return $this->stringLangMap(value: $value);
		}

		if (is_array($value) === false) {
			return [];
		}

		if (isset($value['@value']) === true) {
			return [$this->languageOf(literal: $value) => (string)$value['@value']];
		}

		if (array_is_list($value) === true) {
			return $this->listLangMap(items: $value);
		}

		return $this->assocLangMap(labels: $value);
	}//end langMap()

	/**
	 * Extract the referenced uris: a bare uri string, a `{"@id": ...}` node,
	 * or a list of either.
	 *
	 * @param mixed $value The raw predicate value.
	 *
	 * @return array<string> The referenced uris.
	 */
	public function idRefs(mixed $value): array {
		if (is_string($value) === true) {
			return $this->trimmedList(value: $value);
		}

		if (is_array($value) === false) {
			return [];
		}

		if (isset($value['@id']) === true) {
			return [(string)$value['@id']];
		}

		return $this->listIdRefs(items: $value);
	}//end idRefs()

	/**
	 * The first non-null literal among `$items`.
	 *
	 * @param array<mixed> $items Candidate values.
	 *
	 * @return string|null
	 */
	private function firstLiteral(array $items): ?string {
		foreach ($items as $item) {
			$literal = $this->literal(value: $item);
			if ($literal !== null) {
				return $literal;
			}
		}

		return null;
	}//end firstLiteral()

	/**
	 * Trim a string, null when nothing is left.
	 *
	 * @param string $value The raw string.
	 *
	 * @return string|null
	 */
	private function trimmedOrNull(string $value): ?string {
		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end trimmedOrNull()

	/**
	 * A bare string as a one-entry Dutch language map.
	 *
	 * @param string $value The raw string.
	 *
	 * @return array<string,string>
	 */
	private function stringLangMap(string $value): array {
		$trimmed = $this->trimmedOrNull(value: $value);
		if ($trimmed === null) {
			return [];
		}

		return ['nl' => $trimmed];
	}//end stringLangMap()

	/**
	 * A bare string as a one-entry uri list.
	 *
	 * @param string $value The raw string.
	 *
	 * @return array<string>
	 */
	private function trimmedList(string $value): array {
		$trimmed = $this->trimmedOrNull(value: $value);
		if ($trimmed === null) {
			return [];
		}

		return [$trimmed];
	}//end trimmedList()

	/**
	 * Language map from a list of literal nodes and bare (Dutch) strings.
	 *
	 * @param array<int,mixed> $items The list items.
	 *
	 * @return array<string,string>
	 */
	private function listLangMap(array $items): array {
		$map = [];
		foreach ($items as $item) {
			if ($this->hasKey(item: $item, key: '@value') === true) {
				$map[$this->languageOf(literal: $item)] = (string)$item['@value'];
				continue;
			}

			if ($this->isNonBlankString(value: $item) === true) {
				$map['nl'] = $item;
			}
		}

		return $map;
	}//end listLangMap()

	/**
	 * Language map from an assoc `tag => label` shorthand, skipping blank labels.
	 *
	 * @param array<mixed> $labels The shorthand map.
	 *
	 * @return array<string,string>
	 */
	private function assocLangMap(array $labels): array {
		$map = [];
		foreach ($labels as $long => $label) {
			if ($this->isNonBlankString(value: $label) === true) {
				$map[(string)$long] = $label;
			}
		}

		return $map;
	}//end assocLangMap()

	/**
	 * Uris from a list of reference nodes and bare uri strings.
	 *
	 * @param array<mixed> $items The list items.
	 *
	 * @return array<string>
	 */
	private function listIdRefs(array $items): array {
		$out = [];
		foreach ($items as $item) {
			if ($this->hasKey(item: $item, key: '@id') === true) {
				$out[] = (string)$item['@id'];
				continue;
			}

			if ($this->isNonBlankString(value: $item) === true) {
				$out[] = $item;
			}
		}

		return $out;
	}//end listIdRefs()

	/**
	 * The language tag of a literal node, `nl` when untagged.
	 *
	 * @param array<string,mixed> $literal The literal node.
	 *
	 * @return string
	 */
	private function languageOf(array $literal): string {
		return (string)($literal['@language'] ?? 'nl');
	}//end languageOf()

	/**
	 * Whether `$item` is an array with a set `$key`.
	 *
	 * @param mixed $item The candidate.
	 * @param string $key The key.
	 *
	 * @return bool
	 */
	private function hasKey(mixed $item, string $key): bool {
		return is_array($item) === true && isset($item[$key]) === true;
	}//end hasKey()

	/**
	 * Whether `$value` is a string with non-whitespace content.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return bool
	 */
	private function isNonBlankString(mixed $value): bool {
		return is_string($value) === true && trim($value) !== '';
	}//end isNonBlankString()
}//end class
