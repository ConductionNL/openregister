<?php

/**
 * PresentationAnnotations: the lens, list and geographic-inheritance declarations of a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;

use OCA\OpenRegister\Db\Schema;

/**
 * Reads the presentation annotations of a schema configuration into their usable entries.
 *
 * An entry missing what it needs is dropped rather than half-applied; save-time
 * validators (HingeAnnotationValidator) report it.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class PresentationAnnotations {

	/**
	 * Get the lens declarations from the schema configuration.
	 *
	 * A lens is a property that shows a referenced record's own field, live: it
	 * holds the path, never the value. Each entry is keyed by the property name
	 * it renders as and declares `through` (a reference property on this schema)
	 * and `property` (the property to read through it, dot paths allowed). An
	 * entry missing either key is dropped rather than half-applied.
	 *
	 * @param array|null $configuration The schema's configuration.
	 *
	 * @return array<string, array{through: string, property: string, label?: string}>
	 *                                                                                The declared lenses, keyed by property name.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function lenses(?array $configuration): array {
		$lenses = ($configuration[Schema::LENS_ANNOTATION] ?? null);
		if (is_array($lenses) === false) {
			return [];
		}

		$declared = [];
		foreach ($lenses as $name => $spec) {
			$declared = $this->withLens(declared: $declared, name: (string)$name, spec: $spec);
		}

		return $declared;
	}//end lenses()

	/**
	 * The lenses so far with one declared lens added, unless it is nameless or half a lens.
	 *
	 * @param array  $declared The lenses so far.
	 * @param string $name     The property name the lens renders as.
	 * @param mixed  $spec     The declared lens.
	 *
	 * @return array
	 */
	private function withLens(array $declared, string $name, mixed $spec): array {
		$entry = $this->normaliseLens(spec: $spec);
		if ($name !== '' && $entry !== null) {
			$declared[$name] = $entry;
		}

		return $declared;
	}//end withLens()

	/**
	 * Get the list-surface declaration from the schema configuration.
	 *
	 * A schema may say which columns a list shows and which fields it searches
	 * on, so the generic surface renders any object type without a list page of
	 * its own. A schema declaring neither keeps whatever the surface defaults
	 * to today.
	 *
	 * @param array|null $configuration The schema's configuration.
	 *
	 * @return array The declared columns and search fields, each possibly empty.
	 *
	 * @psalm-return array{columns: array<int, array{property: string, label?: string}>, searchFields: array<int, string>}
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function listPresentation(?array $configuration): array {
		$list = ($configuration[Schema::LIST_ANNOTATION] ?? null);
		if (is_array($list) === false) {
			return [
				'columns' => [],
				'searchFields' => [],
			];
		}

		return [
			'columns' => $this->namedEntries(declared: ($list['columns'] ?? []), key: 'property'),
			'searchFields' => $this->searchFields(declared: ($list['searchFields'] ?? [])),
		];
	}//end listPresentation()

	/**
	 * Get the geographic-inheritance declaration from the schema configuration.
	 *
	 * A record may collect map features from the objects and parties it points
	 * at. Each entry names the reference property to follow; the collector
	 * stamps that name onto every feature it brings back, so a pin on a map can
	 * always say where it came from.
	 *
	 * @param array|null $configuration The schema's configuration.
	 *
	 * @return array<int, array{through: string, label?: string}> The reference properties to collect from.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function geoInheritance(?array $configuration): array {
		$geo = ($configuration[Schema::GEO_INHERITANCE_ANNOTATION] ?? null);
		if (is_array($geo) === false || is_array($geo['from'] ?? null) === false) {
			return [];
		}

		return $this->namedEntries(declared: $geo['from'], key: 'through');
	}//end geoInheritance()

	/**
	 * Normalise one declared lens, or drop it.
	 *
	 * A lens is only half a lens without both halves: a `through` naming the
	 * reference property and a `property` naming what to read there. Half of one
	 * would render as empty on every read, which is indistinguishable from a
	 * field nobody filled in, so it is dropped here and reported by
	 * HingeAnnotationValidator at save time.
	 *
	 * @param mixed $spec The declared lens.
	 *
	 * @return array|null The lens, or null when either half is missing.
	 *
	 * @psalm-return array{through: string, property: string, label?: string}|null
	 */
	private function normaliseLens(mixed $spec): ?array {
		if (is_array($spec) === false) {
			return null;
		}

		$through = ($spec['through'] ?? null);
		$property = ($spec['property'] ?? null);
		if ($this->isName(value: $through) === false || $this->isName(value: $property) === false) {
			return null;
		}

		return $this->withLabel(entry: ['through' => $through, 'property' => $property], spec: $spec);
	}//end normaliseLens()

	/**
	 * The usable entries of a list of names: list columns, or geographic sources.
	 *
	 * @param mixed  $declared The declared list.
	 * @param string $key      The key an entry names its property under: `property` or `through`.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function namedEntries(mixed $declared, string $key): array {
		$entries = [];
		foreach ($declared as $value) {
			$entry = $this->namedEntry(value: $value, key: $key);
			if ($entry !== null) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}//end namedEntries()

	/**
	 * Normalise one declared list column or geographic source, or drop it.
	 *
	 * A bare string is the property name; an array names it under the key and
	 * may add the label a list header or a map legend shows beside it.
	 *
	 * @param mixed  $value The declared entry.
	 * @param string $key   The key it names its property under: `property` or `through`.
	 *
	 * @return array<string, string>|null The entry, or null when it names no property.
	 */
	private function namedEntry(mixed $value, string $key): ?array {
		if (is_string($value) === true) {
			return $this->bareName(name: $value, key: $key);
		}

		if (is_array($value) === false || $this->isName(value: ($value[$key] ?? null)) === false) {
			return null;
		}

		return $this->withLabel(entry: [$key => $value[$key]], spec: $value);
	}//end namedEntry()

	/**
	 * A bare string entry, or null for the empty string.
	 *
	 * @param string $name The declared name.
	 * @param string $key  The key it is stored under.
	 *
	 * @return array<string, string>|null
	 */
	private function bareName(string $name, string $key): ?array {
		if ($name === '') {
			return null;
		}

		return [$key => $name];
	}//end bareName()

	/**
	 * The declared search fields: the non-empty strings, each once, in order.
	 *
	 * @param mixed $declared The declared search fields.
	 *
	 * @return array<int, string>
	 */
	private function searchFields(mixed $declared): array {
		$searchFields = [];
		foreach ($declared as $field) {
			if ($this->isName(value: $field) === true) {
				$searchFields[] = $field;
			}
		}

		return array_values(array_unique($searchFields));
	}//end searchFields()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isName(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isName()

	/**
	 * The entry with the declared label, when the declaration carries a string one.
	 *
	 * @param array<string, string> $entry The entry so far.
	 * @param array                 $spec  The declaration.
	 *
	 * @return array<string, string>
	 */
	private function withLabel(array $entry, array $spec): array {
		if (isset($spec['label']) === true && is_string($spec['label']) === true) {
			$entry['label'] = $spec['label'];
		}

		return $entry;
	}//end withLabel()
}//end class
