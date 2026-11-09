<?php

/**
 * ParentReference: the schema an allOf, oneOf or anyOf entry names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

/**
 * Reads the parent schema identifier out of a composition entry.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class ParentReference {

	/**
	 * The parent schema identifier an `allOf` entry names, or null if it names none.
	 *
	 * Three shapes are accepted, and the third is the one that matters:
	 *
	 *   1. a scalar: `"person"`, `42`, a uuid: OpenRegister's own shorthand for
	 *      "extend this schema", which is what the whole allOf-delta mechanism
	 *      was built around;
	 *   2. `{"$ref": "..."}`: standard JSON Schema. The last path segment is
	 *      taken, so `#/components/schemas/Person` resolves to `Person`, which
	 *      is what `loadSchema` matches against id / uuid / slug;
	 *   3. anything else: `{"if": …, "then": …, "else": …}`, an inline
	 *      `{"properties": …}`, a `$ref` that is not a string. These are valid
	 *      JSON Schema and name NO parent, so they contribute nothing to a
	 *      parent delta. Returning null skips them.
	 *
	 * @param mixed $entry One element of a schema's `allOf` array.
	 *
	 * @return string|int|null The parent identifier, or null when the entry names no parent schema.
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function fromEntry(mixed $entry): string|int|null {
		if (is_string($entry) === true || is_int($entry) === true) {
			return $this->scalar(entry: $entry);
		}

		if (is_array($entry) === true && is_string(($entry['$ref'] ?? null)) === true) {
			return $this->lastSegment(reference: $entry['$ref']);
		}

		return null;
	}//end fromEntry()

	/**
	 * A scalar entry, unless it is empty.
	 *
	 * @param string|int $entry The entry.
	 *
	 * @return string|int|null
	 */
	private function scalar(string|int $entry): string|int|null {
		if ($entry === '' || $entry === 0) {
			return null;
		}

		return $entry;
	}//end scalar()

	/**
	 * The last path segment of a reference, or null when there is none.
	 *
	 * @param string $reference The `$ref` value.
	 *
	 * @return string|null
	 */
	private function lastSegment(string $reference): ?string {
		$lastSlash = strrpos($reference, '/');
		$segment = $reference;
		if ($lastSlash !== false) {
			$segment = substr($reference, ($lastSlash + 1));
		}

		if ($segment === '') {
			return null;
		}

		return $segment;
	}//end lastSegment()
}//end class
