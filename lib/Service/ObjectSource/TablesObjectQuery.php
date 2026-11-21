<?php

/**
 * The filters, search, ids and sort of a query on a Tables-backed schema.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
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
 * @spec openspec/specs/tables-virtual-register/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Applies what Tables cannot be asked for to virtual objects, in PHP.
 *
 * Filter values match a property exactly, as text; a list matches any of its
 * values. Search is a case-insensitive substring of any property value. Ids match
 * the object's uuid or its Tables row id.
 *
 * @spec openspec/specs/tables-virtual-register/spec.md
 */
final class TablesObjectQuery {

	/**
	 * Filter keys that scope the read and name no property.
	 *
	 * @var array<int, string>
	 */
	private const NOT_PROPERTIES = ['search', 'register', 'schema'];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $query The provider query.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly array $query,
	) {
	}//end __construct()

	/**
	 * Whether the query asks for more than a page of rows.
	 *
	 * @return bool True when the objects must be narrowed or ordered in PHP.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function isNarrowing(): bool {
		return $this->filters() !== [] || $this->search() !== '' || $this->ids() !== [] || $this->sortFields() !== [];
	}//end isNarrowing()

	/**
	 * The objects the query matches, in the order it asks for.
	 *
	 * @param array<int, ObjectEntity> $objects The virtual objects.
	 *
	 * @return array<int, ObjectEntity> The matching objects.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function matching(array $objects): array {
		$matching = array_values(array_filter($objects, fn (ObjectEntity $object): bool => $this->matches(object: $object)));
		$fields = $this->sortFields();
		if ($fields !== []) {
			usort($matching, static fn (ObjectEntity $a, ObjectEntity $b): int => self::compare(pair: [$a, $b], fields: $fields));
		}

		return $matching;
	}//end matching()

	/**
	 * The page of the matching objects the query asks for.
	 *
	 * @param array<int, ObjectEntity> $objects The matching objects.
	 * @param int $defaultLimit The page size when the query names none.
	 *
	 * @return array<int, ObjectEntity> The page.
	 *
	 * @spec openspec/specs/tables-virtual-register/spec.md
	 */
	public function page(array $objects, int $defaultLimit): array {
		return array_slice($objects, (int)($this->query['offset'] ?? 0), (int)($this->query['limit'] ?? $defaultLimit));
	}//end page()

	/**
	 * Whether one object meets the ids, the filters and the search.
	 *
	 * @param ObjectEntity $object The virtual object.
	 *
	 * @return bool True on a match.
	 */
	private function matches(ObjectEntity $object): bool {
		$data = $object->getObject();

		return $this->matchesIds(object: $object) && $this->matchesFilters(data: $data) && $this->matchesSearch(data: $data);
	}//end matches()

	/**
	 * Whether the object is one of the asked ids, when ids are asked.
	 *
	 * @param ObjectEntity $object The virtual object.
	 *
	 * @return bool True on a match.
	 */
	private function matchesIds(ObjectEntity $object): bool {
		$ids = $this->ids();
		if ($ids === []) {
			return true;
		}

		return array_intersect($ids, [(string)$object->getUuid(), (string)($object->getObject()['id'] ?? '')]) !== [];
	}//end matchesIds()

	/**
	 * Whether every filter holds.
	 *
	 * @param array<string, mixed> $data The object's properties.
	 *
	 * @return bool True on a match.
	 */
	private function matchesFilters(array $data): bool {
		foreach ($this->filters() as $field => $wanted) {
			$held = self::texts(value: ($data[$field] ?? null));
			if (array_intersect($held, self::texts(value: $wanted)) === []) {
				return false;
			}
		}

		return true;
	}//end matchesFilters()

	/**
	 * Whether any property value holds the search term.
	 *
	 * @param array<string, mixed> $data The object's properties.
	 *
	 * @return bool True on a match.
	 */
	private function matchesSearch(array $data): bool {
		$term = $this->search();
		if ($term === '') {
			return true;
		}

		foreach ($data as $value) {
			foreach (self::texts(value: $value) as $text) {
				if (mb_stripos($text, $term) !== false) {
					return true;
				}
			}
		}

		return false;
	}//end matchesSearch()

	/**
	 * The property filters of the query.
	 *
	 * @return array<string, mixed> Property => wanted value or values.
	 */
	private function filters(): array {
		$filters = ($this->query['filters'] ?? []);
		if (is_array($filters) === false) {
			return [];
		}

		return array_diff_key($filters, array_flip(self::NOT_PROPERTIES));
	}//end filters()

	/**
	 * The search term, under any of the keys callers use.
	 *
	 * @return string The term, '' for none.
	 */
	private function search(): string {
		$term = ($this->query['filters']['search'] ?? $this->query['search'] ?? $this->query['_search'] ?? '');

		return trim((string)$term);
	}//end search()

	/**
	 * The asked ids.
	 *
	 * @return array<int, string> The ids.
	 */
	private function ids(): array {
		$ids = ($this->query['ids'] ?? $this->query['_ids'] ?? []);

		return self::texts(value: $ids);
	}//end ids()

	/**
	 * The sort of the query as property => direction factor.
	 *
	 * @return array<string, int> The fields, 1 ascending and -1 descending.
	 */
	private function sortFields(): array {
		$sort = ($this->query['sort'] ?? []);
		if (is_string($sort) === true) {
			$sort = self::sortFromText(sort: $sort);
		}

		return self::fieldDirections(sort: (array)$sort);
	}//end sortFields()

	/**
	 * The sort entries as field => 1 (ascending) or -1 (descending), without an empty field.
	 *
	 * @param array $sort The sort entries.
	 *
	 * @return array<string, int>
	 */
	private static function fieldDirections(array $sort): array {
		$fields = [];
		foreach ($sort as $key => $value) {
			[$field, $direction] = self::sortEntry(key: $key, value: $value);
			$fields[$field] = 1;
			if (strtoupper($direction) === 'DESC') {
				$fields[$field] = -1;
			}
		}

		unset($fields['']);

		return $fields;
	}//end fieldDirections()

	/**
	 * Read a textual sort: JSON, a field, or a field with a leading `-` for descending.
	 *
	 * @param string $sort The textual sort.
	 *
	 * @return array<int|string, mixed> The sort as an array.
	 */
	private static function sortFromText(string $sort): array {
		$decoded = json_decode($sort, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		if (str_starts_with($sort, '-') === true) {
			return [substr($sort, 1) => 'DESC'];
		}

		return [$sort => 'ASC'];
	}//end sortFromText()

	/**
	 * One sort entry, from `{field: direction}` or `[{field, direction}]`.
	 *
	 * @param int|string $key The array key.
	 * @param mixed $value The array value.
	 *
	 * @return array{0: string, 1: string} The field and the direction.
	 */
	private static function sortEntry(int|string $key, mixed $value): array {
		if (is_array($value) === true) {
			return [(string)($value['field'] ?? ''), (string)($value['direction'] ?? $value['order'] ?? 'ASC')];
		}

		return [(string)$key, (string)$value];
	}//end sortEntry()

	/**
	 * Order two objects by the sort fields.
	 *
	 * @param array{0: ObjectEntity, 1: ObjectEntity} $pair The two objects.
	 * @param array<string, int> $fields The fields and direction factors.
	 *
	 * @return int Negative, zero or positive.
	 */
	private static function compare(array $pair, array $fields): int {
		[$left, $right] = [$pair[0]->getObject(), $pair[1]->getObject()];
		foreach ($fields as $field => $factor) {
			$order = (($left[$field] ?? null) <=> ($right[$field] ?? null));
			if ($order !== 0) {
				return ($order * $factor);
			}
		}

		return 0;
	}//end compare()

	/**
	 * A value as the list of texts it can match on.
	 *
	 * @param mixed $value A scalar, a list, or null.
	 *
	 * @return array<int, string> The texts; none for null.
	 */
	private static function texts(mixed $value): array {
		if (is_bool($value) === true) {
			return [var_export($value, true)];
		}

		if (is_scalar($value) === true) {
			return [(string)$value];
		}

		return array_merge([], ...array_map(self::texts(...), array_values((array)$value)));
	}//end texts()
}//end class
