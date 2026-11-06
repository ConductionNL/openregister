<?php

/**
 * OpenRegister RelationMap
 *
 * The uuids and paths an object's stored relation map holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * An object's stored relation map: property path to reference value, a uuid, a URL ending in
 * one, or a list of those, e.g. `['blocks.0' => 'uuid-b', 'owner' => 'https://…/uuid-o']`.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class RelationMap {

	/**
	 * Constructor.
	 *
	 * @param array<mixed, mixed> $relations The stored relation map.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly array $relations,
	) {
	}//end __construct()

	/**
	 * The distinct uuids the map names, without the given uuid.
	 *
	 * Empty strings, '0' and non-string values are skipped. Keys are kept as array_unique() leaves them.
	 *
	 * @param string|null $ownUuid The object's own uuid.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function uuidsBesides(?string $ownUuid): array {
		$uniqueIds = array_unique($this->referencedStrings());
		if ($ownUuid !== null) {
			$uniqueIds = array_filter($uniqueIds, fn ($id) => $id !== $ownUuid);
		}

		return $uniqueIds;
	}//end uuidsBesides()

	/**
	 * The stored relation path that holds a given uuid, if any.
	 *
	 * The map records which property a reference came in through, so neither
	 * direction has to walk the schema's `$ref` properties guessing which one
	 * holds the value. A list entry answers `path.index`.
	 *
	 * @param string $uuid The uuid to find; an empty uuid is never found.
	 *
	 * @return string|null The path, or null when the map does not name it.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function pathHolding(string $uuid): ?string {
		foreach (array_filter($this->relations, 'is_string', ARRAY_FILTER_USE_KEY) as $path => $value) {
			if (self::holds(value: $value, uuid: $uuid) === true) {
				return $path;
			}

			$index = self::indexHolding(value: $value, uuid: $uuid);
			if ($index !== null) {
				return $path.'.'.$index;
			}
		}

		return null;
	}//end pathHolding()

	/**
	 * Every non-empty string the map holds, in order, as a list.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private function referencedStrings(): array {
		$ids = [];
		foreach ($this->relations as $value) {
			$candidates = array_filter(
				self::listOf(value: $value),
				fn ($candidate) => is_string($candidate) === true && empty($candidate) === false
			);
			$ids = array_merge($ids, array_values($candidates));
		}

		return $ids;
	}//end referencedStrings()

	/**
	 * The value itself when it is an array, otherwise a list holding only the value.
	 *
	 * @param mixed $value The stored relation value.
	 *
	 * @return array<mixed, mixed>
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	private static function listOf(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [$value];
	}//end listOf()

	/**
	 * Whether a stored reference value names the uuid (as itself or inside a URL); an empty uuid names nothing.
	 *
	 * @param mixed  $value The stored value.
	 * @param string $uuid  The uuid.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private static function holds(mixed $value, string $uuid): bool {
		return $uuid !== '' && is_string($value) === true && str_contains($value, $uuid) === true;
	}//end holds()

	/**
	 * The index of the first list entry that names the uuid, or null.
	 *
	 * @param mixed  $value The stored value; anything but an array gives null.
	 * @param string $uuid  The uuid.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	private static function indexHolding(mixed $value, string $uuid): ?string {
		if (is_array($value) === false) {
			return null;
		}

		foreach ($value as $index => $sub) {
			if (self::holds(value: $sub, uuid: $uuid) === true) {
				return (string)$index;
			}
		}

		return null;
	}//end indexHolding()
}//end class
