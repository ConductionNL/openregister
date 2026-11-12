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

		$blocks = [];
		foreach (self::declared(query: $query) as $root => $block) {
			array_push($blocks, ...self::propertyBlocks(root: (string)$root, block: $block));
		}

		return $blocks;
	}//end blocks()

	/**
	 * The EXISTS condition of one block, or null when a field name is not a plain name.
	 *
	 * @param NestedPathTarget    $column The quoted, qualified array column, its binder (value to SQL) and the platform.
	 * @param array<string,mixed> $fields The block's fields.
	 *
	 * @return string|null The condition.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-match-one-element-of-an-array-property
	 */
	public function condition(NestedPathTarget $column, array $fields): ?string {
		$element = new NestedPathTarget(expression: self::ALIAS.'.e', bind: $column->bind(...), isPostgres: $column->isPostgres);
		$where   = self::where(fields: $fields, element: $element);
		if ($where === null) {
			return null;
		}

		if ($column->isPostgres === true) {
			return self::postgresExists(column: $column->expression, where: $where);
		}

		return self::mysqlExists(column: $column->expression, where: $where);
	}//end condition()

	/**
	 * The `_element` map of a query that carries one.
	 *
	 * @param array<string,mixed> $query The request query, with `_element` set.
	 *
	 * @return array<mixed> The map of property to block.
	 *
	 * @throws InvalidArgumentException When `_element` is not a non-empty map.
	 */
	private static function declared(array $query): array {
		$declared = $query[self::KEY];
		if (is_array($declared) === false || $declared === []) {
			throw new InvalidArgumentException("'_element' must name an array property and the fields one of its elements carries.");
		}

		return $declared;
	}//end declared()

	/**
	 * The blocks of one property, each tagged with the property.
	 *
	 * @param string $root  The array property.
	 * @param mixed  $block The value under `_element[<property>]`.
	 *
	 * @return list<array{root: string, fields: array<string,mixed>}> The blocks.
	 *
	 * @throws InvalidArgumentException When the block is not a non-empty map or list of maps.
	 */
	private static function propertyBlocks(string $root, mixed $block): array {
		if (is_array($block) === false || $block === []) {
			throw new InvalidArgumentException("'_element[{$root}]' must name the fields one of its elements carries.");
		}

		return array_map(
			static fn (array $fields): array => ['root' => $root, 'fields' => $fields],
			self::numbered(block: $block)
		);
	}//end propertyBlocks()

	/**
	 * The conditions of a block's fields, joined, or null when a field name is not a plain name.
	 *
	 * @param array<string,mixed> $fields  The block's fields.
	 * @param NestedPathTarget    $element The element the fields are read below, and its binder.
	 *
	 * @return string|null The WHERE clause of the EXISTS subquery.
	 */
	private static function where(array $fields, NestedPathTarget $element): ?string {
		$paths      = new NestedPathFilter();
		$conditions = [];
		foreach ($fields as $field => $value) {
			$expression = $paths->expression(column: $element, path: [(string)$field]);
			if ($expression === null) {
				return null;
			}

			$target = new NestedPathTarget(expression: $expression, bind: $element->bind(...), isPostgres: $element->isPostgres);
			array_push($conditions, ...$paths->conditions(target: $target, value: $value));
		}

		return implode(' AND ', $conditions);
	}//end where()

	/**
	 * The EXISTS over the elements of an array column on PostgreSQL.
	 *
	 * @param string $column The quoted, qualified array column.
	 * @param string $where  The conditions one element must meet.
	 *
	 * @return string The condition.
	 */
	private static function postgresExists(string $column, string $where): string {
		$elements = "json_array_elements(CASE WHEN json_typeof({$column}::json) = 'array' THEN {$column}::json ELSE '[]'::json END)";
		return 'EXISTS (SELECT 1 FROM '.$elements.' AS '.self::ALIAS."(e) WHERE {$where})";
	}//end postgresExists()

	/**
	 * The EXISTS over the elements of an array column on MariaDB/MySQL.
	 *
	 * @param string $column The quoted, qualified array column.
	 * @param string $where  The conditions one element must meet.
	 *
	 * @return string The condition.
	 */
	private static function mysqlExists(string $column, string $where): string {
		$elements = "JSON_TABLE(CASE WHEN JSON_TYPE({$column}) = 'ARRAY' THEN {$column} ELSE '[]' END, '\$[*]' COLUMNS (e JSON PATH '\$'))";
		return 'EXISTS (SELECT 1 FROM '.$elements.' AS '.self::ALIAS." WHERE {$where})";
	}//end mysqlExists()

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

		foreach ($block as $index => $fields) {
			if (self::isFieldMap(fields: $fields) === false) {
				throw new InvalidArgumentException("'_element' block {$index} must name the fields one element carries.");
			}
		}

		return $block;
	}//end numbered()

	/**
	 * Whether a value is a non-empty map of field names.
	 *
	 * @param mixed $fields The value.
	 *
	 * @return bool True for a map of fields.
	 */
	private static function isFieldMap(mixed $fields): bool {
		return is_array($fields) === true && $fields !== [] && array_is_list($fields) === false;
	}//end isFieldMap()
}//end class
