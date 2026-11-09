<?php

/**
 * OpenRegister RelationValueReader
 *
 * Reads the declared values of relation annotations: labels in a language, titles, keys; holds no state.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Relation;

/**
 * Reads the declared values of relation annotations: labels in a language, titles, keys; holds no state.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class RelationValueReader {
	/**
	 * Resolve a label to text in one language.
	 *
	 * A plain string is the label in every language, which is what an
	 * organisation with one language writes. A map is resolved by exact tag,
	 * then primary subtag, then Dutch, then English, then whatever is there:
	 * a label in the wrong language beats no label, which is the fallback and
	 * says nothing at all.
	 *
	 * @param mixed $value The declared label.
	 * @param string $language The BCP-47 tag asked for.
	 *
	 * @return string|null The resolved text.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function text(mixed $value, string $language): ?string {
		if (is_string($value) === true) {
			return $this->nonBlank(value: $value);
		}

		$value = $this->asArray(value: $value);
		if (is_array($value) === false) {
			return null;
		}

		$preferred = array_map(
			static fn (string $tag): mixed => ($value[$tag] ?? null),
			[$language, substr($language, 0, 2), 'nl', 'en']
		);

		return ($this->firstNonBlank(candidates: $preferred) ?? $this->firstNonBlank(candidates: $value));
	}//end text()

	/**
	 * A property's own title, when it has one.
	 *
	 * @param mixed $property The property definition.
	 *
	 * @return string|null The title.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function titleOf(mixed $property): ?string {
		$property = $this->asArray(value: $property);
		if (is_array($property) === false) {
			return null;
		}

		return $this->nonBlank(value: ($property['title'] ?? null));
	}//end titleOf()

	/**
	 * The first candidate that is a non-blank string, trimmed.
	 *
	 * @param array<mixed> $candidates The candidates, in order of preference.
	 *
	 * @return string|null The text.
	 */
	private function firstNonBlank(array $candidates): ?string {
		foreach ($candidates as $candidate) {
			$text = $this->nonBlank(value: $candidate);
			if ($text !== null) {
				return $text;
			}
		}

		return null;
	}//end firstNonBlank()

	/**
	 * A value as trimmed text, or null when it is not a string or is blank.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function nonBlank(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end nonBlank()

	/**
	 * A decoded JSON object as an array; any other value as it is.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function asArray(mixed $value): mixed {
		if (is_object($value) === true) {
			return (array)$value;
		}

		return $value;
	}//end asArray()
}//end class
