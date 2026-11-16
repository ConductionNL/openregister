<?php

/**
 * OpenRegister ArchivalContainment
 *
 * Reads the parts a schema's archival block says a record contains off one
 * row, for MDTO's `bevatOnderdeel` and `isOnderdeelVan`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-conformance/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The `containment` declaration of `x-openregister-archival`: the name of the
 * relation property through which a record of the schema contains other
 * records (`"containment": "onderdelen"`). Like `parties`, the schema says
 * WHERE a record keeps the fact and the value comes off the row.
 *
 * A part is a record reference: a non-empty string (the part's id), or an
 * object carrying its id under `id` or `uuid`, with its name under `name`,
 * `naam`, `title` or `displayName` when it has one. A list holds several
 * parts. A schema that declares no containment gets no fact, so its export
 * is exactly what it was.
 */
final class ArchivalContainment {

	/**
	 * The keys a part object may keep its name under, in order of preference.
	 */
	private const NAME_KEYS = ['name', 'naam', 'title', 'displayName'];

	/**
	 * The keys a part object may keep its id under, in order of preference.
	 */
	private const ID_KEYS = ['id', 'uuid'];

	/**
	 * The containment fact for one row: the declared relation property and the parts it holds.
	 *
	 * @param mixed                $declared The annotation's `containment` value.
	 * @param array<string, mixed> $row      The record's data.
	 *
	 * @return array{property: string, parts: list<array{id: string, name?: string}>}|null The fact, or null when the schema declares no containment.
	 *
	 * @spec openspec/changes/archival-conformance/tasks.md#task-1.3
	 */
	public static function forRow(mixed $declared, array $row): ?array {
		if (is_string($declared) === false || trim($declared) === '') {
			return null;
		}

		$property = trim($declared);
		$parts    = [];
		foreach (self::partsIn(value: ($row[$property] ?? null)) as $part) {
			$parts[$part['id']] = $part;
		}

		return ['property' => $property, 'parts' => array_values($parts)];
	}//end forRow()

	/**
	 * The parts a property value holds.
	 *
	 * @param mixed $value The row's value for the containment property.
	 *
	 * @return list<array{id: string, name?: string}> The parts.
	 */
	private static function partsIn(mixed $value): array {
		if (is_string($value) === true && trim($value) !== '') {
			return [['id' => trim($value)]];
		}

		if (is_array($value) === false) {
			return [];
		}

		if (array_is_list($value) === false) {
			return array_filter([self::partObject(value: $value)]);
		}

		$parts = [];
		foreach ($value as $item) {
			if (is_string($item) === true || (is_array($item) === true && array_is_list($item) === false)) {
				$parts = array_merge($parts, self::partsIn(value: $item));
			}
		}

		return $parts;
	}//end partsIn()

	/**
	 * A part from an object value, or null when it carries no id.
	 *
	 * @param array<array-key, mixed> $value The object.
	 *
	 * @return array{id: string, name?: string}|null The part.
	 */
	private static function partObject(array $value): ?array {
		$id = self::firstText(value: $value, keys: self::ID_KEYS);
		if ($id === null) {
			return null;
		}

		$part = ['id' => $id];
		$name = self::firstText(value: $value, keys: self::NAME_KEYS);
		if ($name !== null) {
			$part['name'] = $name;
		}

		return $part;
	}//end partObject()

	/**
	 * The first non-empty text (or integer) under one of the keys.
	 *
	 * @param array<array-key, mixed> $value The object.
	 * @param list<string>            $keys  The keys, in order.
	 *
	 * @return string|null The text.
	 */
	private static function firstText(array $value, array $keys): ?string {
		foreach ($keys as $key) {
			$found = ($value[$key] ?? null);
			if (is_int($found) === true || (is_string($found) === true && trim($found) !== '')) {
				return trim((string) $found);
			}
		}

		return null;
	}//end firstText()
}//end class
