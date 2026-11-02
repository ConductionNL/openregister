<?php

/**
 * OpenRegister ArrayElementFilter.
 *
 * Filters on the elements of an array-of-objects property:
 * `_element[properties][propertyDefinition]=pd-7&_element[properties][value][gte]=100`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use InvalidArgumentException;

/**
 * Reads `_element` blocks out of a query and writes their SQL.
 *
 * A block names an array property and the fields one element of it must
 * carry: the object matches when ONE element satisfies every field of the
 * block. Several blocks on one property are numbered
 * (`_element[properties][0][…]`, `_element[properties][1][…]`), each its own
 * element. A field takes what a nested-path filter takes: a value, a list
 * (any of) or an operator bag (`gte`, `lte`, `gt`, `lt`, `in`, `notIn`, `ne`,
 * `isnull`, `like`).
 *
 * The SQL is one EXISTS per block over the array's elements:
 * `json_array_elements` on PostgreSQL, `JSON_TABLE` on MariaDB/MySQL.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-match-one-element-of-an-array-property
 */
class ArrayElementFilter {

	/**
	 * The query key.
	 */
	public const KEY = '_element';

	/**
	 * The element alias inside the EXISTS subquery.
	 */
	private const ALIAS = 'cn_el';

	/**
	 * The blocks a query carries.
	 *
	 * @param array<string,mixed> $query The request query.
	 *
	 * @return list<array{root: string, fields: array<string,mixed>}> One entry per block.
	 *
	 * @throws InvalidArgumentException When `_element` is not a map of property to block.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-match-one-element-of-an-array-property
	 */
	public function blocks(array $query): array {
		if (array_key_exists(self::KEY, $query) === false) {
			return [];
		}

		$declared = $query[self::KEY];
		if (is_array($declared) === false || $declared === []) {
			throw new InvalidArgumentException("'_element' must name an array property and the fields one of its elements carries.");
		}

		$blocks = [];
		foreach ($declared as $root => $block) {
			if (is_array($block) === false || $block === []) {
				throw new InvalidArgumentException("'_element[{$root}]' must name the fields one of its elements carries.");
			}

			foreach (self::numbered(block: $block) as $fields) {
				$blocks[] = ['root' => (string)$root, 'fields' => $fields];
			}
		}

		return $blocks;
	}//end blocks()

	/**
	 * The EXISTS condition of one block, or null when a field name is not a plain name.
	 *
	 * @param string              $column     The quoted, qualified array column.
	 * @param array<string,mixed> $fields     The block's fields.
	 * @param callable            $bind       Value to SQL: fn(string $value): string.
	 * @param bool                $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return string|null The condition.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-match-one-element-of-an-array-property
	 */
	public function condition(string $column, array $fields, callable $bind, bool $isPostgres): ?string {
		$paths      = new NestedPathFilter();
		$conditions = [];
		foreach ($fields as $field => $value) {
			$expression = $paths->expression(column: self::ALIAS.'.e', path: [(string)$field], isPostgres: $isPostgres);
			if ($expression === null) {
				return null;
			}

			array_push($conditions, ...$paths->conditions(expression: $expression, value: $value, bind: $bind, isPostgres: $isPostgres));
		}

		$where = implode(' AND ', $conditions);
		if ($isPostgres === true) {
			$elements = "json_array_elements(CASE WHEN json_typeof({$column}::json) = 'array' THEN {$column}::json ELSE '[]'::json END)";
			return 'EXISTS (SELECT 1 FROM '.$elements.' AS '.self::ALIAS."(e) WHERE {$where})";
		}

		$elements = "JSON_TABLE(CASE WHEN JSON_TYPE({$column}) = 'ARRAY' THEN {$column} ELSE '[]' END, '\$[*]' COLUMNS (e JSON PATH '\$'))";
		return 'EXISTS (SELECT 1 FROM '.$elements.' AS '.self::ALIAS." WHERE {$where})";
	}//end condition()

	/**
	 * The element blocks of one property: one unnumbered block, or the numbered ones.
	 *
	 * @param array<mixed> $block The value under `_element[<property>]`.
	 *
	 * @return list<array<string,mixed>> The blocks.
	 *
	 * @throws InvalidArgumentException When a numbered block is not a map of fields.
	 */
	private static function numbered(array $block): array {
		if (array_is_list($block) === false) {
			return [$block];
		}

		$blocks = [];
		foreach ($block as $index => $fields) {
			if (is_array($fields) === false || $fields === [] || array_is_list($fields) === true) {
				throw new InvalidArgumentException("'_element' block {$index} must name the fields one element carries.");
			}

			$blocks[] = $fields;
		}

		return $blocks;
	}//end numbered()
}//end class
