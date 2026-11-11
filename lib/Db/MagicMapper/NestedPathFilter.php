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
		$plain  = [];
		$nested = [];
		foreach ($filters as $key => $value) {
			$key      = (string)$key;
			$segments = explode('.', $key);
			if (self::isObjectProperty(properties: $properties, name: $segments[0]) === true && self::descends(segments: $segments, value: $value) === true) {
				array_push($nested, ...self::collect(path: $segments, value: $value));
				continue;
			}

			$plain[$key] = $value;
		}

		return [$plain, $nested];
	}//end split()

	/**
	 * The SQL expression reading the text at a path inside an object column, or null when a segment is not a plain name.
	 *
	 * @param NestedPathTarget $column The quoted, qualified column and its platform.
	 * @param list<string>     $path   The segments below the column.
	 *
	 * @return string|null The expression.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
	 */
	public function expression(NestedPathTarget $column, array $path): ?string {
		if (self::isPlainPath(path: $path) === false) {
			return null;
		}

		if ($column->isPostgres === true) {
			return "({$column->expression} #>> '{" . implode(',', $path) . "}')";
		}

		return "JSON_UNQUOTE(JSON_EXTRACT({$column->expression}, '$." . implode('.', $path) . "'))";
	}//end expression()

	/**
	 * The SQL conditions of one path filter on its target.
	 *
	 * The target's binder turns a value into its SQL form: a named parameter
	 * on the QueryBuilder path, a quoted literal on the raw UNION path.
	 *
	 * @param NestedPathTarget $target The expression from expression(), its binder and the platform.
	 * @param mixed            $value  The filter value: a scalar, a list, or an operator bag.
	 *
	 * @return list<string> The conditions, all of which must hold.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-a-filter-can-name-a-field-inside-an-object-property
	 */
	public function conditions(NestedPathTarget $target, mixed $value): array {
		if (is_array($value) === false) {
			return [self::scalarCondition(target: $target, value: $value)];
		}

		if (self::isOperatorBag(value: $value) === false) {
			return [self::inList(target: $target, values: $value)];
		}

		return [
			...self::rangeConditions(target: $target, value: $value),
			...self::setConditions(target: $target, value: $value),
			...self::presenceConditions(target: $target, value: $value),
		];

	}//end conditions()

	/**
	 * The condition of a value that is not a list or an operator bag.
	 *
	 * @param NestedPathTarget $target The expression and its binder.
	 * @param mixed            $value  The value: null, `IS NULL`, `IS NOT NULL` or a scalar.
	 *
	 * @return string The condition.
	 */
	private static function scalarCondition(NestedPathTarget $target, mixed $value): string {
		if ($value === 'IS NULL' || $value === null) {
			return "{$target->expression} IS NULL";
		}

		if ($value === 'IS NOT NULL') {
			return "{$target->expression} IS NOT NULL";
		}

		return "{$target->expression} = " . $target->bind(self::text(value: $value));

	}//end scalarCondition()

	/**
	 * The comparisons of an operator bag: gt, gte, lt and lte.
	 *
	 * @param NestedPathTarget $target The expression and its binder.
	 * @param array<mixed>     $value  The operator bag.
	 *
	 * @return list<string> The conditions.
	 */
	private static function rangeConditions(NestedPathTarget $target, array $value): array {
		$conditions = [];
		foreach (['gte' => '>=', 'lte' => '<=', 'gt' => '>', 'lt' => '<'] as $operator => $sql) {
			if (isset($value[$operator]) === true) {
				[$left, $right] = self::sides(target: $target, operand: $value[$operator]);
				$conditions[]    = "{$left} {$sql} {$right}";
			}
		}

		return $conditions;
	}//end rangeConditions()

	/**
	 * The set operators of an operator bag: ne, in and notIn.
	 *
	 * @param NestedPathTarget $target The expression and its binder.
	 * @param array<mixed>     $value  The operator bag.
	 *
	 * @return list<string> The conditions.
	 */
	private static function setConditions(NestedPathTarget $target, array $value): array {
		$conditions = [];
		if (isset($value['ne']) === true) {
			$conditions[] = "{$target->expression} <> " . $target->bind(self::text(value: $value['ne']));
		}

		if (isset($value['in']) === true) {
			$conditions[] = self::inList(target: $target, values: (array)$value['in']);
		}

		$notIn = (array)($value['notIn'] ?? []);
		if ($notIn !== []) {
			$conditions[] = "{$target->expression} NOT IN (" . self::boundList(target: $target, values: $notIn) . ')';
		}

		return $conditions;
	}//end setConditions()

	/**
	 * The presence and text operators of an operator bag: isnull and like.
	 *
	 * @param NestedPathTarget $target The expression and its binder.
	 * @param array<mixed>     $value  The operator bag.
	 *
	 * @return list<string> The conditions.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) NullFilter is the one reader of the isnull value, shared with the list and aggregation paths.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private static function presenceConditions(NestedPathTarget $target, array $value): array {
		$conditions = [];
		if (array_key_exists('isnull', $value) === true) {
			$predicate = 'IS NOT NULL';
			if (NullFilter::isNullOperatorAsksForNull(value: $value['isnull']) === true) {
				$predicate = 'IS NULL';
			}
			$conditions[] = "{$target->expression} {$predicate}";
		}

		$term = self::text(value: ($value[LikeOperator::KEY] ?? ''));
		if ($term !== '') {
			$conditions[] = "LOWER({$target->expression}) LIKE " . $target->bind('%' . addcslashes(strtolower($term), '\\%_') . '%');
		}

		return $conditions;
	}//end presenceConditions()

	/**
	 * The path filters below one path, descending into a nested map that is not an operator bag.
	 *
	 * @param list<string> $path  The root property, then the segments so far.
	 * @param mixed        $value The value at that path.
	 *
	 * @return list<array{root: string, path: list<string>, value: mixed}> The path filters, in order.
	 */
	private static function collect(array $path, mixed $value): array {
		if (self::isPathMap(value: $value) === false) {
			return [['root' => $path[0], 'path' => array_slice($path, 1), 'value' => $value]];
		}

		$collected = [];
		foreach ($value as $segment => $inner) {
			array_push($collected, ...self::collect(path: [...$path, (string)$segment], value: $inner));
		}

		return $collected;
	}//end collect()

	/**
	 * Whether a path is non-empty and every segment is a plain name.
	 *
	 * @param list<string> $path The segments below the column.
	 *
	 * @return bool True when the path can be written into a JSON path literal.
	 */
	private static function isPlainPath(array $path): bool {
		if ($path === []) {
			return false;
		}

		foreach ($path as $segment) {
			if (preg_match(self::SEGMENT, $segment) !== 1) {
				return false;
			}
		}

		return true;
	}//end isPlainPath()

	/**
	 * Whether a filter on an object property reads inside it: a dotted key, or a map of field names.
	 *
	 * @param list<string> $segments The key split on dots.
	 * @param mixed        $value    The filter value.
	 *
	 * @return bool True when the filter names a path below the root.
	 */
	private static function descends(array $segments, mixed $value): bool {
		return count($segments) > 1 || self::isPathMap(value: $value) === true;
	}//end descends()

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
	 * The two sides of a comparison, numeric when the operand is a number.
	 *
	 * @param NestedPathTarget $target  The expression and its binder.
	 * @param mixed            $operand The operand.
	 *
	 * @return array{0: string, 1: string} The left side and the bound operand.
	 */
	private static function sides(NestedPathTarget $target, mixed $operand): array {
		if (is_numeric($operand) === false) {
			return [$target->expression, $target->bind(self::text(value: $operand))];
		}

		return [$target->numeric(), $target->bind((string)$operand)];
	}//end sides()

	/**
	 * An IN list over text forms; an empty list matches nothing.
	 *
	 * @param NestedPathTarget $target The expression and its binder.
	 * @param array<mixed>     $values The values.
	 *
	 * @return string The condition.
	 */
	private static function inList(NestedPathTarget $target, array $values): string {
		if ($values === []) {
			return '1=0';
		}

		return "{$target->expression} IN (" . self::boundList(target: $target, values: $values) . ')';
	}//end inList()

	/**
	 * The values' text forms, bound and comma-separated, in order.
	 *
	 * @param NestedPathTarget $target The expression and its binder.
	 * @param array<mixed>     $values The values.
	 *
	 * @return string The list.
	 */
	private static function boundList(NestedPathTarget $target, array $values): string {
		$bound = array_map(static fn (mixed $item): string => $target->bind(self::text(value: $item)), array_values($values));

		return implode(', ', $bound);
	}//end boundList()

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
