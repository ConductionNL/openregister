<?php

/**
 * OpenRegister ArchivalParties
 *
 * Reads the parties a schema's archival block names off one row, for MDTO's
 * `betrokkene`.
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
 * @spec openspec/changes/archival-conformance/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The `parties` declaration of `x-openregister-archival`: one property name or
 * a list of them, each naming where the records of the schema keep a party
 * involved with them (`"parties": "aanvrager"`). Like `temporalCoverage`, the
 * schema says WHERE a record keeps the fact and the value comes off the row.
 *
 * A property value is a party when it is a non-empty string (the party's
 * name), or an object carrying a name under `name`, `naam`, `displayName`,
 * `title` or `fullName`, with its id under `id` or `uuid` when it has one. A
 * list holds several parties. Anything else names no party, so the export
 * leaves `betrokkene` out rather than writing an empty one.
 *
 * @spec openspec/changes/archival-conformance/tasks.md#task-1.2
 */
final class ArchivalParties {

	/**
	 * The keys a party object may carry its name under, in order of preference.
	 *
	 * @var array<int, string>
	 */
	private const NAME_KEYS = ['name', 'naam', 'displayName', 'title', 'fullName'];

	/**
	 * The parties a row names through the declared properties.
	 *
	 * @param mixed                $declared The annotation's `parties` value.
	 * @param array<string, mixed> $row      Row data keyed by field name.
	 *
	 * @return list<array{role: string, name: string, id?: string}>|null The parties, or null when the row names none.
	 *
	 * @spec openspec/changes/archival-conformance/tasks.md#task-1.2
	 */
	public static function forRow(mixed $declared, array $row): ?array {
		$parties = [];
		foreach (self::properties(declared: $declared) as $property) {
			foreach (self::partiesIn(value: ($row[$property] ?? null), role: $property) as $party) {
				$parties[] = $party;
			}
		}

		if ($parties === []) {
			return null;
		}

		return $parties;
	}//end forRow()

	/**
	 * The declared property names.
	 *
	 * @param mixed $declared One name or a list of them.
	 *
	 * @return array<int, string>
	 */
	private static function properties(mixed $declared): array {
		if (is_string($declared) === true) {
			$declared = [$declared];
		}

		if (is_array($declared) === false) {
			return [];
		}

		return array_values(array_unique(array_filter($declared, static fn (mixed $name): bool => is_string($name) === true && trim($name) !== '')));
	}//end properties()

	/**
	 * The parties one property value holds.
	 *
	 * @param mixed  $value The value.
	 * @param string $role  The property it came from.
	 *
	 * @return list<array{role: string, name: string, id?: string}>
	 */
	private static function partiesIn(mixed $value, string $role): array {
		if (is_string($value) === true) {
			if (trim($value) === '') {
				return [];
			}

			return [['role' => $role, 'name' => trim($value)]];
		}

		if (is_array($value) === false) {
			return [];
		}

		if (array_is_list($value) === true) {
			$parties = [];
			foreach ($value as $item) {
				if (is_array($item) === true && array_is_list($item) === true) {
					continue;
				}

				$parties = array_merge($parties, self::partiesIn(value: $item, role: $role));
			}

			return $parties;
		}

		$party = self::partyObject(value: $value, role: $role);
		if ($party === null) {
			return [];
		}

		return [$party];
	}//end partiesIn()

	/**
	 * A party from an object value, or null when it carries no name.
	 *
	 * @param array<string, mixed> $value The object.
	 * @param string               $role  The property it came from.
	 *
	 * @return array{role: string, name: string, id?: string}|null
	 */
	private static function partyObject(array $value, string $role): ?array {
		foreach (self::NAME_KEYS as $key) {
			$name = ($value[$key] ?? null);
			if (is_string($name) === true && trim($name) !== '') {
				$party = ['role' => $role, 'name' => trim($name)];
				$id    = ($value['id'] ?? ($value['uuid'] ?? null));
				if ((is_string($id) === true && trim($id) !== '') || is_int($id) === true) {
					$party['id'] = trim((string) $id);
				}

				return $party;
			}
		}

		return null;
	}//end partyObject()
}//end class
