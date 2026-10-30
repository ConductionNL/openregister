<?php

/**
 * OpenRegister NestedPathFilter.
 *
 * Filters on a field inside an object-typed property: `dunning.currentStage=2`.
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

use OCA\OpenRegister\Db\LikeOperator;
use OCA\OpenRegister\Support\NullFilter;

/**
 * Reads nested-path filters out of a filter map and writes their SQL.
 *
 * A filter reaches the magic table in one of two spellings:
 *
 *  - a dotted key, `['dunning.currentStage' => 2]`, as a PHP caller passes it;
 *  - a nested map, `['dunning' => ['currentStage' => 2]]`, which is what the
 *    request layer rebuilds from `?dunning.currentStage=2` (PHP mangles the dot
 *    to `dunning_currentStage`, and SearchQueryHandler splits it back).
 *
 * Both name a root property of type `object` and a path inside its JSON
 * column. The value is compared in its text form; a comparison operator with a
 * numeric operand compares numerically. An object property's column holds JSON
 * (plain `json` on PostgreSQL, JSON on MariaDB/MySQL), read with `#>>` and
 * `JSON_EXTRACT` respectively.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
 */
class NestedPathFilter {

	/**
	 * Operator keys of an operator bag.
	 */
	public const OPERATORS = ['gte', 'lte', 'gt', 'lt', 'in', 'notIn', 'ne', 'isnull', NullFilter::OR_NULL_MODIFIER, LikeOperator::KEY];

	/**
	 * A path segment: a property name, nothing that could leave the JSON path.
	 *
	 * Ends in `\z`, not `$`: `$` also matches before a trailing newline, so
	 * `a\n` passed and reached the JSON path literal, which MariaDB rejects (a
	 * 500 instead of the refusal this class intends).
	 */
	private const SEGMENT = '/^[A-Za-z0-9_\-]+\z/';

	/**
	 * Split a filter map into the filters on columns and the filters on a path inside an object property.
	 *
	 * @param array<string,mixed> $filters    The object filters.
	 * @param array<string,mixed> $properties The schema's properties.
	 *
	 * @return array{0: array<string,mixed>, 1: list<array{root: string, path: list<string>, value: mixed}>} The column filters and the path filters.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
	 */
	public function split(array $filters, array $properties): array {
		$plain = [];
		$nested = [];
		foreach ($filters as $key => $value) {
			$key = (string)$key;
			$segments = explode('.', $key);
			$root = array_shift($segments);

			if ($segments !== [] && self::isObjectProperty(properties: $properties, name: $root) === true) {
				self::collect(root: $root, path: $segments, value: $value, into: $nested);
				continue;
			}

			if ($segments === []
				&& self::isObjectProperty(properties: $properties, name: $root) === true
				&& self::isPathMap(value: $value) === true
			) {
				foreach ($value as $segment => $inner) {
					self::collect(root: $root, path: [(string)$segment], value: $inner, into: $nested);
				}

				continue;
			}

			$plain[$key] = $value;
		}//end foreach

		return [$plain, $nested];
	}//end split()

	/**
	 * The SQL expression reading the text at a path inside an object column, or null when a segment is not a plain name.
	 *
	 * @param string       $column     The quoted, qualified column.
	 * @param list<string> $path       The segments below the column.
	 * @param bool         $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return string|null The expression.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
	 */
	public function expression(string $column, array $path, bool $isPostgres): ?string {
		if ($path === []) {
			return null;
		}

		foreach ($path as $segment) {
			if (preg_match(self::SEGMENT, $segment) !== 1) {
				return null;
			}
		}

		if ($isPostgres === true) {
			return "({$column} #>> '{" . implode(',', $path) . "}')";
		}

		return "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$." . implode('.', $path) . "'))";
	}//end expression()

	/**
	 * The SQL conditions of one path filter.
	 *
	 * `$bind` turns a value into its SQL form: a named parameter on the
	 * QueryBuilder path, a quoted literal on the raw UNION path.
	 *
	 * @param string   $expression The expression from expression().
	 * @param mixed    $value      The filter value: a scalar, a list, or an operator bag.
	 * @param callable $bind       Value to SQL: fn(string $value): string.
	 * @param bool     $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return list<string> The conditions, all of which must hold.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
	 */
	public function conditions(string $expression, mixed $value, callable $bind, bool $isPostgres): array {
		if ($value === 'IS NULL' || $value === null) {
			return ["{$expression} IS NULL"];
		}

		if ($value === 'IS NOT NULL') {
			return ["{$expression} IS NOT NULL"];
		}

		if (is_array($value) === false) {
			return ["{$expression} = " . $bind(self::text(value: $value))];
		}

		if (self::isOperatorBag(value: $value) === false) {
			return [self::inList(expression: $expression, values: $value, bind: $bind, negate: false)];
		}

		return [
			...self::rangeConditions(expression: $expression, value: $value, bind: $bind, isPostgres: $isPostgres),
			...self::setConditions(expression: $expression, value: $value, bind: $bind),
			...self::presenceConditions(expression: $expression, value: $value, bind: $bind),
		];

	}//end conditions()

	/**
	 * The comparisons of an operator bag: gt, gte, lt and lte.
	 *
	 * @param string       $expression The expression.
	 * @param array<mixed> $value      The operator bag.
	 * @param callable     $bind       Value to SQL.
	 * @param bool         $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return list<string> The conditions.
	 */
	private static function rangeConditions(string $expression, array $value, callable $bind, bool $isPostgres): array {
		$conditions = [];
		foreach (['gte' => '>=', 'lte' => '<=', 'gt' => '>', 'lt' => '<'] as $operator => $sql) {
			if (isset($value[$operator]) === true) {
				$conditions[] = self::compare(expression: $expression, sql: $sql, operand: $value[$operator], bind: $bind, isPostgres: $isPostgres);
			}
		}

		return $conditions;
	}//end rangeConditions()

	/**
	 * The set operators of an operator bag: ne, in and notIn.
	 *
	 * @param string       $expression The expression.
	 * @param array<mixed> $value      The operator bag.
	 * @param callable     $bind       Value to SQL.
	 *
	 * @return list<string> The conditions.
	 */
	private static function setConditions(string $expression, array $value, callable $bind): array {
		$conditions = [];
		if (isset($value['ne']) === true) {
			$conditions[] = "{$expression} <> " . $bind(self::text(value: $value['ne']));
		}

		if (isset($value['in']) === true) {
			$conditions[] = self::inList(expression: $expression, values: (array)$value['in'], bind: $bind, negate: false);
		}

		$notIn = (array)($value['notIn'] ?? []);
		if ($notIn !== []) {
			$conditions[] = self::inList(expression: $expression, values: $notIn, bind: $bind, negate: true);
		}

		return $conditions;
	}//end setConditions()

	/**
	 * The presence and text operators of an operator bag: isnull and like.
	 *
	 * @param string       $expression The expression.
	 * @param array<mixed> $value      The operator bag.
	 * @param callable     $bind       Value to SQL.
	 *
	 * @return list<string> The conditions.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) NullFilter is the one reader of the isnull value, shared with the list and aggregation paths.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function presenceConditions(string $expression, array $value, callable $bind): array {
		$conditions = [];
		if (array_key_exists('isnull', $value) === true) {
			$predicate = 'IS NOT NULL';
			if (NullFilter::isNullOperatorAsksForNull(value: $value['isnull']) === true) {
				$predicate = 'IS NULL';
			}

			$conditions[] = "{$expression} {$predicate}";
		}

		$term = self::text(value: ($value[LikeOperator::KEY] ?? ''));
		if ($term !== '') {
			$conditions[] = "LOWER({$expression}) LIKE " . $bind('%' . addcslashes(strtolower($term), '\\%_') . '%');
		}

		return $conditions;
	}//end presenceConditions()

	/**
	 * Add one path filter, descending into a nested map that is not an operator bag.
	 *
	 * @param string                                                         $root  The object property.
	 * @param list<string>                                                   $path  The segments so far.
	 * @param mixed                                                          $value The value at that path.
	 * @param list<array{root: string, path: list<string>, value: mixed}> $into  The collected path filters.
	 *
	 * @return void
	 */
	private static function collect(string $root, array $path, mixed $value, array &$into): void {
		if (self::isPathMap(value: $value) === true) {
			foreach ($value as $segment => $inner) {
				self::collect(root: $root, path: [...$path, (string)$segment], value: $inner, into: $into);
			}

			return;
		}

		$into[] = ['root' => $root, 'path' => $path, 'value' => $value];
	}//end collect()

	/**
	 * Whether a property is declared with type object.
	 *
	 * @param array<string,mixed> $properties The schema's properties.
	 * @param string              $name       The property.
	 *
	 * @return bool True for an object property.
	 */
	private static function isObjectProperty(array $properties, string $name): bool {
		return is_array($properties[$name] ?? null) === true && ($properties[$name]['type'] ?? null) === 'object';
	}//end isObjectProperty()

	/**
	 * Whether a value is a map of field names: non-empty, string keys, and no operator key.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for a nested path map.
	 */
	private static function isPathMap(mixed $value): bool {
		if (is_array($value) === false || $value === [] || array_is_list($value) === true) {
			return false;
		}

		return self::isOperatorBag(value: $value) === false;
	}//end isPathMap()

	/**
	 * Whether a value is an operator bag.
	 *
	 * @param array<mixed> $value The value.
	 *
	 * @return bool True when one of its keys is an operator.
	 */
	private static function isOperatorBag(array $value): bool {
		return array_intersect(array_map('strval', array_keys($value)), self::OPERATORS) !== [];
	}//end isOperatorBag()

	/**
	 * A comparison, numeric when the operand is a number.
	 *
	 * @param string   $expression The expression.
	 * @param string   $sql        The SQL operator.
	 * @param mixed    $operand    The operand.
	 * @param callable $bind       Value to SQL.
	 * @param bool     $isPostgres Whether the platform is PostgreSQL.
	 *
	 * @return string The condition.
	 */
	private static function compare(string $expression, string $sql, mixed $operand, callable $bind, bool $isPostgres): string {
		if (is_numeric($operand) === false) {
			return "{$expression} {$sql} " . $bind(self::text(value: $operand));
		}

		$numeric = "CAST({$expression} AS DECIMAL(30,10))";
		if ($isPostgres === true) {
			// A non-numeric text would abort the cast, so only numeric text is compared.
			$numeric = "(CASE WHEN {$expression} ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST({$expression} AS NUMERIC) END)";
		}

		return "{$numeric} {$sql} " . $bind((string)$operand);
	}//end compare()

	/**
	 * An IN or NOT IN list over text forms.
	 *
	 * @param string       $expression The expression.
	 * @param array<mixed> $values     The values.
	 * @param callable     $bind       Value to SQL.
	 * @param bool         $negate     NOT IN when true.
	 *
	 * @return string The condition.
	 */
	private static function inList(string $expression, array $values, callable $bind, bool $negate): string {
		if ($values === []) {
			return '1=0';
		}

		$bound = array_map(static fn (mixed $item): string => $bind(self::text(value: $item)), array_values($values));
		if ($negate === true) {
			return "{$expression} NOT IN (" . implode(', ', $bound) . ')';
		}

		return "{$expression} IN (" . implode(', ', $bound) . ')';
	}//end inList()

	/**
	 * A value's text form as JSON text extraction yields it: a boolean as true or false.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private static function text(mixed $value): string {
		if (is_bool($value) === true) {
			return var_export($value, true);
		}

		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end text()
}//end class
