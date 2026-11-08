<?php

/**
 * RbacMatchSql
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Db
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\RuleColumns;

/**
 * Compiles the `match` block of a conditional RBAC rule into a raw SQL predicate.
 *
 * Every condition of a match applies (AND), every operator of a condition
 * applies (AND), and anything that cannot be compiled — an unknown operator,
 * an operand of the wrong shape, a dynamic variable that resolves to nothing —
 * becomes the impossible predicate `1 = 0` rather than being dropped, which
 * would widen the rule (openregister#1953, openregister#4089). Mirrors the
 * QueryBuilder emitter {@see RbacQueryMatch} and ConditionMatcher on find.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RbacMatchSql {

	/**
	 * The predicate no row satisfies.
	 *
	 * @var string
	 */
	public const IMPOSSIBLE = '1 = 0';

	/**
	 * Comparison operators and their SQL spelling.
	 *
	 * @var array<string, string>
	 */
	private const COMPARISONS = [
		'$eq' => '=',
		'$ne' => '!=',
		'$gt' => '>',
		'$gte' => '>=',
		'$lt' => '<',
		'$lte' => '<=',
	];

	/**
	 * List operators and their SQL keyword.
	 *
	 * @var array<string, string>
	 */
	private const LISTS = [
		'$in' => 'IN',
		'$nin' => 'NOT IN',
	];

	/**
	 * Constructor.
	 *
	 * @param ConditionMatcher $matcher The matcher that resolves dynamic values and knows the metadata.
	 * @param RbacSqlDialect   $dialect The dialect values and columns are quoted in.
	 */
	public function __construct(
		public readonly ConditionMatcher $matcher,
		public readonly RbacSqlDialect $dialect,
	) {
	}//end __construct()

	/**
	 * Terms joined by a glue, in parentheses when there is more than one.
	 *
	 * @param array<int, string> $terms The terms.
	 * @param string             $glue  ' AND ' or ' OR '.
	 *
	 * @return string|null The joined terms, or null for none.
	 */
	public static function joined(array $terms, string $glue): ?string {
		if ($terms === []) {
			return null;
		}

		if (count($terms) === 1) {
			return $terms[0];
		}

		return '(' . implode($glue, $terms) . ')';
	}//end joined()

	/**
	 * Whether a resolved match value is compared for equality.
	 *
	 * @param mixed $value The resolved value.
	 *
	 * @return bool True for a string, a number or a boolean.
	 */
	public static function isEqualityValue(mixed $value): bool {
		return is_string($value) === true || is_numeric($value) === true || is_bool($value) === true;
	}//end isEqualityValue()

	/**
	 * Compile a match block.
	 *
	 * @param array<string, mixed> $block        The match conditions.
	 * @param string               $columnPrefix A table alias with its dot, or ''.
	 *
	 * @return string|null The predicate, or null when no condition could be built.
	 */
	public function matchConditions(array $block, string $columnPrefix = ''): ?string {
		if ($this->matcher->namesUnknownMetadata(match: $block) === true) {
			return self::IMPOSSIBLE;
		}

		return self::joined(terms: $this->propertyConditions(block: $block, columnPrefix: $columnPrefix), glue: ' AND ');
	}//end matchConditions()

	/**
	 * Compile an operator object: every operator applies, an unbuildable one denies.
	 *
	 * @param string               $column    The qualified, quoted column.
	 * @param array<string, mixed> $operators The operators and their operands.
	 *
	 * @return string|null The predicate, or null for an empty object.
	 */
	public function operatorConditions(string $column, array $operators): ?string {
		$conditions = [];
		foreach ($operators as $operator => $operand) {
			$term = new RbacOperatorTerm(operator: $operator, operand: $operand);
			$conditions[] = $this->orImpossible(result: $this->operatorCondition(column: $column, term: $term), term: $term);
		}

		return self::joined(terms: $conditions, glue: ' AND ');
	}//end operatorConditions()

	/**
	 * Compile `$contains`: the object's array must contain the resolved value.
	 *
	 * Mirrors OperatorEvaluator::operatorContains(). An array operand holds when
	 * the column contains any of its non-null values; a value that resolves to
	 * nothing denies, exactly as the comparison emitter does.
	 *
	 * @param string           $column The qualified, quoted column.
	 * @param RbacOperatorTerm $term   The operator and its operand.
	 *
	 * @return string|null The predicate, or null when the operator is not `$contains`.
	 */
	public function contains(string $column, RbacOperatorTerm $term): ?string {
		if ($term->operator !== '$contains') {
			return null;
		}

		$resolved = $this->matcher->resolveDynamicValue(value: $term->operand);
		$candidates = $this->containsCandidates(resolved: $resolved);
		if ($resolved === null || $candidates === []) {
			return self::IMPOSSIBLE;
		}

		$parts = array_map(fn ($candidate): string => $this->containsPredicate(column: $column, candidate: $candidate), $candidates);
		return self::joined(terms: $parts, glue: ' OR ');
	}//end contains()

	/**
	 * Log that a scalar comparison was given an array operand.
	 *
	 * @param string $operator The operator.
	 *
	 * @return void
	 */
	public function warnArrayOperand(string $operator): void {
		$this->dialect->logger->warning(
			message: '[MagicRbacHandler] Array operand for a scalar comparison operator — emitting an impossible predicate',
			context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $operator]
		);
	}//end warnArrayOperand()

	/**
	 * The condition of every property of a match block, in order.
	 *
	 * The prefix qualifies the column when this predicate goes inside a
	 * subquery over a SECOND table: unqualified, a column the related table
	 * lacks would resolve against the OUTER row and test the wrong record.
	 *
	 * @param array<string, mixed> $block        The match conditions.
	 * @param string               $columnPrefix A table alias with its dot, or ''.
	 *
	 * @return array<int, string> The conditions that could be built.
	 */
	private function propertyConditions(array $block, string $columnPrefix): array {
		$conditions = [];
		foreach ($block as $property => $value) {
			$column = $columnPrefix . $this->dialect->quotedColumn(columnName: (new RuleColumns())->columnOf(key: $property));
			$conditions[] = $this->valueCondition(column: $column, value: $value);
		}

		return array_values(array_filter($conditions, static fn (?string $condition): bool => $condition !== null));
	}//end propertyConditions()

	/**
	 * The condition one match value puts on a column.
	 *
	 * A dynamic variable that resolves to null is the impossible predicate,
	 * never a dropped condition, which would leak rows (#1953).
	 *
	 * @param string $column The qualified, quoted column.
	 * @param mixed  $value  The value as written in the rule.
	 *
	 * @return string|null The predicate, or null for a value that is no condition.
	 */
	private function valueCondition(string $column, mixed $value): ?string {
		$resolved = $this->matcher->resolveDynamicValue(value: $value);
		if ($value !== $resolved && $resolved === null) {
			return self::IMPOSSIBLE;
		}

		return $this->resolvedCondition(column: $column, resolved: $resolved);
	}//end valueCondition()

	/**
	 * The condition a resolved match value puts on a column.
	 *
	 * @param string $column   The qualified, quoted column.
	 * @param mixed  $resolved The resolved value.
	 *
	 * @return string|null The predicate, or null for a value that is no condition.
	 */
	private function resolvedCondition(string $column, mixed $resolved): ?string {
		if (self::isEqualityValue(value: $resolved) === true) {
			return "{$column} = " . $this->dialect->quote(value: $resolved);
		}

		if (is_array($resolved) === true) {
			return $this->operatorConditions(column: $column, operators: $resolved);
		}

		if ($resolved === null) {
			return "{$column} IS NULL";
		}

		return null;
	}//end resolvedCondition()

	/**
	 * Compile one operator.
	 *
	 * @param string           $column The qualified, quoted column.
	 * @param RbacOperatorTerm $term   The operator and its operand.
	 *
	 * @return string|null The predicate, or null for an operator this emitter does not know.
	 */
	private function operatorCondition(string $column, RbacOperatorTerm $term): ?string {
		if (is_string($term->operator) === false) {
			return null;
		}

		return $this->comparison(column: $column, term: $term)
			?? $this->listMembership(column: $column, term: $term)
			?? $this->contains(column: $column, term: $term)
			?? $this->exists(column: $column, term: $term);
	}//end operatorCondition()

	/**
	 * A compiled operator, or the impossible predicate for one that would not compile.
	 *
	 * @param string|null      $result The compiled operator, or null.
	 * @param RbacOperatorTerm $term   The operator and its operand.
	 *
	 * @return string The predicate.
	 */
	private function orImpossible(?string $result, RbacOperatorTerm $term): string {
		if ($result !== null) {
			return $result;
		}

		$this->dialect->logger->warning(
			message: '[MagicRbacHandler] Unknown operator or operand — emitting an impossible predicate',
			context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $term->operator]
		);
		return self::IMPOSSIBLE;
	}//end orImpossible()

	/**
	 * Compile a comparison operator.
	 *
	 * @param string           $column The qualified, quoted column.
	 * @param RbacOperatorTerm $term   The operator and its operand.
	 *
	 * @return string|null The predicate, or null when the operator is no comparison.
	 */
	private function comparison(string $column, RbacOperatorTerm $term): ?string {
		$symbol = (self::COMPARISONS[$term->operator] ?? null);
		if ($symbol === null) {
			return null;
		}

		return $this->resolvedComparison(left: "{$column} {$symbol}", term: $term);
	}//end comparison()

	/**
	 * A comparison against the resolved operand.
	 *
	 * A dynamic variable that resolves to null denies (#1953). A resolved
	 * `$user.groups` cannot be a scalar comparison, and quoting it would
	 * compare against the literal string "Array", so it denies too.
	 *
	 * @param string           $left The column and the comparison symbol.
	 * @param RbacOperatorTerm $term The operator and its operand.
	 *
	 * @return string The predicate.
	 */
	private function resolvedComparison(string $left, RbacOperatorTerm $term): string {
		$resolved = $this->matcher->resolveDynamicValue(value: $term->operand);
		if ($term->operand !== $resolved && $resolved === null) {
			return self::IMPOSSIBLE;
		}

		if (is_array($resolved) === true) {
			$this->warnArrayOperand(operator: (string)$term->operator);
			return self::IMPOSSIBLE;
		}

		return "{$left} " . $this->dialect->quote(value: $resolved);
	}//end resolvedComparison()

	/**
	 * Compile `$in` / `$nin`.
	 *
	 * A map or a scalar is no list: it denies, as the QueryBuilder path and
	 * find do (openregister#4089).
	 *
	 * @param string           $column The qualified, quoted column.
	 * @param RbacOperatorTerm $term   The operator and its operand.
	 *
	 * @return string|null The predicate, or null when the operator is no list operator.
	 */
	private function listMembership(string $column, RbacOperatorTerm $term): ?string {
		$keyword = (self::LISTS[$term->operator] ?? null);
		if ($keyword === null) {
			return null;
		}

		if ($term->operandIsList() === false) {
			return self::IMPOSSIBLE;
		}

		if ($term->operand === []) {
			return $this->emptyList(column: $column, term: $term);
		}

		$quoted = array_map(fn ($val) => $this->dialect->quote(value: $val), $term->operand);
		return "{$column} {$keyword} (" . implode(', ', $quoted) . ')';
	}//end listMembership()

	/**
	 * An empty list: nothing is in it, and every present value is not.
	 *
	 * @param string           $column The qualified, quoted column.
	 * @param RbacOperatorTerm $term   `$in` or `$nin` with an empty list.
	 *
	 * @return string The predicate.
	 */
	private function emptyList(string $column, RbacOperatorTerm $term): string {
		if ($term->operator === '$in') {
			return self::IMPOSSIBLE;
		}

		return "{$column} IS NOT NULL";
	}//end emptyList()

	/**
	 * Compile `$exists`.
	 *
	 * @param string           $column The qualified, quoted column.
	 * @param RbacOperatorTerm $term   The operator and its operand (true for "is set").
	 *
	 * @return string|null The predicate, or null when the operator is not `$exists`.
	 */
	private function exists(string $column, RbacOperatorTerm $term): ?string {
		if ($term->operator !== '$exists') {
			return null;
		}

		if ($term->operand === true) {
			return "{$column} IS NOT NULL";
		}

		return "{$column} IS NULL";
	}//end exists()

	/**
	 * The values a `$contains` checks for: the value, or the non-null values of a list.
	 *
	 * @param mixed $resolved The resolved operand.
	 *
	 * @return array<int, mixed> The candidates.
	 */
	private function containsCandidates(mixed $resolved): array {
		if (is_array($resolved) === false) {
			return [$resolved];
		}

		return array_values(array_filter($resolved, static fn ($val) => $val !== null));
	}//end containsCandidates()

	/**
	 * Whether a JSON array column contains one value.
	 *
	 * @param string $column    The qualified, quoted column.
	 * @param mixed  $candidate The value.
	 *
	 * @return string The predicate.
	 */
	private function containsPredicate(string $column, mixed $candidate): string {
		$quoted = $this->dialect->quote(value: (string)$candidate);

		if ($this->dialect->isPostgres() === true) {
			return "COALESCE({$column}, '[]')::jsonb @> to_jsonb({$quoted}::text)";
		}

		return "JSON_CONTAINS(COALESCE({$column}, '[]'), JSON_QUOTE({$quoted}))";
	}//end containsPredicate()
}//end class
