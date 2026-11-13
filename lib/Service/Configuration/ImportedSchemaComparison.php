<?php

/**
 * Whether an incoming schema says anything different from the stored one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Schema;

/**
 * Compares an incoming schema with the stored one, ignoring order.
 *
 * The version field is an optimisation, not the source of truth: an app may
 * change a schema without bumping it.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportedSchemaComparison
{

	/**
	 * Plain configuration keys an import compares besides the annotations.
	 *
	 * @var array<int, string>
	 */
	private const COMPARED_CONFIGURATION_KEYS = ['partyKinds', 'linkRoles', 'orphanCleanup'];

	/**
	 * Whether an incoming schema says anything different from the stored one.
	 *
	 * @param array<string, mixed> $data The incoming schema definition.
	 * @param Schema $existing The schema already stored.
	 *
	 * @return bool True when the properties, the required list, the authorization or the annotations differ.
	 *
	 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md#requirement-catalogue-metadata-travels-with-the-register
	 */
	public function differs(array $data, Schema $existing): bool
	{
		$fields = [
			'properties'    => $existing->getProperties(),
			'required'      => $existing->getRequired(),
			'authorization' => $existing->getAuthorization(),
		];

		foreach ($fields as $key => $storedValue) {
			if ($this->normalised(value: ($data[$key] ?? null)) !== $this->normalised(value: $storedValue)) {
				return true;
			}
		}

		return $this->catalogueDiffers(data: $data, existing: $existing)
			|| $this->annotationsDiffer(data: $data, existing: $existing);
	}//end differs()

	/**
	 * Whether the incoming classification or catalogue block differs from the stored one.
	 *
	 * Compared only when the file carries the key: a file written before these
	 * fields existed says nothing about them, and must not count as a change
	 * on every import.
	 *
	 * @param array<string, mixed> $data     The incoming schema definition.
	 * @param Schema               $existing The schema already stored.
	 *
	 * @return bool True when a carried field differs.
	 *
	 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md#requirement-catalogue-metadata-travels-with-the-register
	 */
	private function catalogueDiffers(array $data, Schema $existing): bool
	{
		$stored = ['classification' => $existing->getClassification(), 'catalogue' => $existing->getCatalogue()];
		foreach ($stored as $key => $storedValue) {
			if (array_key_exists($key, $data) === true && $this->normalised(value: $data[$key]) !== $this->normalised(value: $storedValue)) {
				return true;
			}
		}

		return false;
	}//end catalogueDiffers()

	/**
	 * Whether the incoming schema declares an `x-openregister-*` annotation
	 * that the stored schema does not carry with the same value.
	 *
	 * WHY THIS EXISTS
	 * ---------------
	 * `differs()` compared `properties`, `required` and
	 * `authorization` and nothing else, so a change that ONLY adds or edits an
	 * annotation block was invisible to it. Combined with the version gate
	 * in the importer ("skip when incoming <= existing") that produced a silent,
	 * permanent no-op: the annotation sits declared in the app's register JSON,
	 * visible in the repo, and never reaches the running system.
	 *
	 * Measured on openbuild (2026-08-16): `exportJob` declares
	 * `x-openregister-lifecycle` at version 0.1.0 while the instance carried
	 * 1.0.0 (a schema edited once through the UI bumps to 1.0.0). The version
	 * said skip, the content check could not see the missing lifecycle, so
	 * `TransitionEngine::transition()` found no state machine and returned
	 * without doing anything — every export sat at `status: queued` forever,
	 * its background job consumed, with not one line in the log. The same trap
	 * applies to every key in the vocabulary: `mcp`, `calculations`,
	 * `notifications`, `widgets`, `relations`, `archival`.
	 *
	 * TWO DELIBERATE NARROWINGS, both to avoid re-importing on every load:
	 *
	 *  1. Only keys in `Schema::ANNOTATION_VOCABULARY` are compared. An
	 *     unknown key (openbuild really does declare
	 *     `x-openregister-lifecycle-exception`) is dropped on every save, so
	 *     comparing it would differ forever.
	 *  2. Only keys the INCOMING declares are compared. The stored
	 *     configuration also holds keys OpenRegister maintains itself
	 *     (`objectNameField`, `objectDescriptionField`, …) plus annotations an
	 *     operator may have added through the UI; treating those as a
	 *     difference would rewrite them away on the next import.
	 *
	 * Incoming annotations are read from BOTH the top level and
	 * `configuration`, because `Schema::hydrate()` folds sibling-of-properties
	 * `x-openregister-*` keys into `configuration` — app register JSON declares
	 * them at the top level, the stored schema keeps them nested.
	 *
	 * @param array<string,mixed> $data     Incoming schema definition.
	 * @param Schema              $existing The stored schema.
	 *
	 * @return bool True when a declared annotation is missing or different.
	 */
	private function annotationsDiffer(array $data, Schema $existing): bool
	{
		$stored = ($existing->getConfiguration() ?? []);

		foreach ($this->declaredKeys(data: $data) as $key => $value) {
			if ($this->normalised(value: $value) !== $this->normalised(value: ($stored[$key] ?? null))) {
				return true;
			}
		}

		return false;
	}//end annotationsDiffer()

	/**
	 * The compared keys an incoming schema declares.
	 *
	 * Top level first, then `configuration`: the nested form wins, the same
	 * precedence hydrate() applies when it folds.
	 *
	 * @param array<string,mixed> $data Incoming schema definition.
	 *
	 * @return array<string,mixed>
	 */
	private function declaredKeys(array $data): array
	{
		$nested = ($data['configuration'] ?? []);
		if (is_array($nested) === false) {
			$nested = [];
		}

		return array_filter(
			array_replace($data, $nested),
			static fn (int|string $key): bool => self::isComparedKey(key: $key),
			ARRAY_FILTER_USE_KEY
		);
	}//end declaredKeys()

	/**
	 * Whether an incoming configuration key is compared against the stored one.
	 *
	 * Annotations of the vocabulary, plus the party and role vocabularies: a
	 * party kind a schema starts accepting is a configuration change with no
	 * property or version change, and the party writer refuses that kind until
	 * it lands.
	 *
	 * @param int|string $key The configuration key.
	 *
	 * @return bool True when the key is compared.
	 */
	private static function isComparedKey(int|string $key): bool
	{
		if (is_string($key) === false) {
			return false;
		}

		if (in_array($key, self::COMPARED_CONFIGURATION_KEYS, true) === true) {
			return true;
		}

		return str_starts_with($key, 'x-openregister-') === true
			&& in_array($key, Schema::ANNOTATION_VOCABULARY, true) === true;
	}//end isComparedKey()

	/**
	 * Normalise a JSON-ish value into an order-insensitive canonical string.
	 *
	 * Associative arrays get their keys sorted recursively; lists of scalars
	 * are sorted by value so a reorder does not read as a change. Empty and
	 * null values collapse to the same canonical form, so "absent" and "empty
	 * array" compare equal.
	 *
	 * @param mixed $value The value to normalise.
	 *
	 * @return string A stable canonical representation.
	 */
	private function normalised(mixed $value): string
	{
		if ($value === null || $value === []) {
			return 'null';
		}

		if (is_array($value) === false) {
			return json_encode($value);
		}

		if (array_keys($value) === range(0, (count($value) - 1))) {
			return $this->normalisedList(list: $value);
		}

		return $this->normalisedMap(map: $value);
	}//end normalised()

	/**
	 * A list as a string, whatever the order of its items.
	 *
	 * @param array<int, mixed> $list The list.
	 *
	 * @return string
	 */
	private function normalisedList(array $list): string
	{
		$parts = array_map(fn ($item) => $this->normalised(value: $item), $list);
		sort($parts);

		return '['.implode(',', $parts).']';
	}//end normalisedList()

	/**
	 * A map as a string, whatever the order of its keys.
	 *
	 * @param array<int|string, mixed> $map The map.
	 *
	 * @return string
	 */
	private function normalisedMap(array $map): string
	{
		ksort($map);
		$parts = [];
		foreach ($map as $key => $item) {
			$parts[] = json_encode($key).':'.$this->normalised(value: $item);
		}

		return '{'.implode(',', $parts).'}';
	}//end normalisedMap()
}//end class
