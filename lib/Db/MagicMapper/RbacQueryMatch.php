<?php

/**
 * RbacQueryMatch
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

use OCA\OpenRegister\Service\Rbac\RuleColumns;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Compiles the `match` block of a conditional RBAC rule into QueryBuilder
 * expressions on the magic table aliased `t`, for one query.
 *
 * The QueryBuilder twin of {@see RbacMatchSql}: the same operators, the same
 * fail-closed impossible predicate for anything that cannot be compiled, and
 * `$contains` emitted through the raw-SQL compiler so the two list paths
 * cannot drift apart.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RbacQueryMatch {

	/**
	 * Comparison operators and their expression-builder method.
	 *
	 * @var array<string, string>
	 */
	private const COMPARISONS = [
		'$eq' => 'eq',
		'$ne' => 'neq',
		'$gt' => 'gt',
		'$gte' => 'gte',
		'$lt' => 'lt',
		'$lte' => 'lte',
	];

	/**
	 * List operators and their expression-builder method.
	 *
	 * @var array<string, string>
	 */
	private const LISTS = [
		'$in' => 'in',
		'$nin' => 'notIn',
	];

	/**
	 * Constructor.
	 *
	 * @param IQueryBuilder $qb  The query the expressions are built for.
	 * @param RbacMatchSql  $sql The raw-SQL compiler, its matcher and its dialect.
	 */
	public function __construct(
		private readonly IQueryBuilder $qb,
		private readonly RbacMatchSql $sql,
	) {
	}//end __construct()

	/**
	 * The expression no row satisfies.
	 *
	 * @return mixed `1 = 0` as bound parameters.
	 */
	public function impossible(): mixed {
		return $this->qb->expr()->eq($this->qb->createNamedParameter(1), $this->qb->createNamedParameter(0));
	}//end impossible()

	/**
	 * One cross-organisation branch: the impossible predicate, replaced by the
	 * compiled match when the table has every column the match names.
	 *
	 * @param RuleColumns          $columns The columns of the schema's table.
	 * @param array<string, mixed> $block   The rule's match block.
	 *
	 * @return mixed The expression, or null when nothing compiles.
	 */
	public function crossOrganisationBranch(RuleColumns $columns, array $block): mixed {
		$condition = $this->impossible();
		if ($columns->admits(match: $block) === true) {
			$condition = $this->matchConditions(block: $block);
		}

		return $condition;
	}//end crossOrganisationBranch()

	/**
	 * Compile a match block; every condition applies.
	 *
	 * @param array<string, mixed> $block The match conditions.
	 *
	 * @return mixed The expression, or null when no condition could be built.
	 */
	public function matchConditions(array $block): mixed {
		if ($this->sql->matcher->namesUnknownMetadata(match: $block) === true) {
			return $this->impossible();
		}

		$conditions = $this->propertyConditions(block: $block);
		if ($conditions === []) {
			$this->sql->dialect->logger->debug(
				message: '[MagicRbacHandler] No valid match conditions built',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		return $this->all(conditions: $conditions);
	}//end matchConditions()

	/**
	 * The condition one match value puts on its property's column.
	 *
	 * A dynamic variable that resolves to null yields the impossible predicate
	 * rather than null, which would drop it from the AND and leak rows the find
	 * path denies (#1953).
	 *
	 * @param string $property The property (camelCase or a metadata key).
	 * @param mixed  $value    The value as written in the rule.
	 *
	 * @return mixed The expression, or null for a value that is no condition.
	 */
	public function propertyCondition(string $property, mixed $value): mixed {
		$columnName = (new RuleColumns())->columnOf(key: $property);
		$resolved = $this->sql->matcher->resolveDynamicValue(value: $value);
		if ($value !== $resolved && $resolved === null) {
			return $this->impossible();
		}

		return $this->resolvedCondition(columnName: $columnName, resolved: $resolved);
	}//end propertyCondition()

	/**
	 * Compile an operator object: every operator applies, an unbuildable one
	 * denies (openregister#4089).
	 *
	 * @param string               $columnName The bare column name.
	 * @param array<string, mixed> $operators  The operators and their operands.
	 *
	 * @return mixed The expression, or null for an empty object.
	 */
	public function operatorConditions(string $columnName, array $operators): mixed {
		$conditions = [];
		foreach ($operators as $operator => $operand) {
			$term = new RbacOperatorTerm(operator: $operator, operand: $operand);
			$conditions[] = ($this->operatorCondition(columnName: $columnName, term: $term) ?? $this->impossible());
		}

		if ($conditions === []) {
			return null;
		}

		return $this->all(conditions: $conditions);
	}//end operatorConditions()

	/**
	 * The condition of every property of a match block, in order.
	 *
	 * @param array<string, mixed> $block The match conditions.
	 *
	 * @return array<int, mixed> The conditions that could be built.
	 */
	private function propertyConditions(array $block): array {
		$conditions = [];
		foreach ($block as $property => $value) {
			$condition = $this->propertyCondition(property: $property, value: $value);
			if ($condition !== null) {
				$conditions[] = $condition;
			}
		}

		return $conditions;
	}//end propertyConditions()

	/**
	 * The condition a resolved match value puts on a column.
	 *
	 * @param string $columnName The bare column name.
	 * @param mixed  $resolved   The resolved value.
	 *
	 * @return mixed The expression, or null for a value that is no condition.
	 */
	private function resolvedCondition(string $columnName, mixed $resolved): mixed {
		if (RbacMatchSql::isEqualityValue(value: $resolved) === true) {
			return $this->qb->expr()->eq("t.{$columnName}", $this->bindScalar(value: $resolved));
		}

		if (is_array($resolved) === true) {
			return $this->operatorConditions(columnName: $columnName, operators: $resolved);
		}

		if ($resolved === null) {
			return $this->qb->expr()->isNull("t.{$columnName}");
		}

		return null;
	}//end resolvedCondition()

	/**
	 * Compile one operator.
	 *
	 * @param string           $columnName The bare column name.
	 * @param RbacOperatorTerm $term       The operator and its operand.
	 *
	 * @return mixed The expression, or null for an operator this emitter does not know.
	 */
	private function operatorCondition(string $columnName, RbacOperatorTerm $term): mixed {
		if (is_string($term->operator) === false) {
			return null;
		}

		return $this->comparison(columnName: $columnName, term: $term)
			?? $this->listMembership(columnName: $columnName, term: $term)
			?? $this->contains(columnName: $columnName, term: $term)
			?? $this->exists(columnName: $columnName, term: $term)
			?? $this->unknownOperator(term: $term);
	}//end operatorCondition()

	/**
	 * One expression, or all of them ANDed.
	 *
	 * @param array<int, mixed> $conditions At least one expression.
	 *
	 * @return mixed The expression.
	 */
	private function all(array $conditions): mixed {
		if (count($conditions) === 1) {
			return $conditions[0];
		}

		return $this->qb->expr()->andX(...$conditions);
	}//end all()

	/**
	 * Compile a comparison operator.
	 *
	 * A resolved array operand (a token like `$user.groups`) cannot be a scalar
	 * comparison; comparing against the stringified "Array" would be a silent,
	 * wrong verdict, so it denies.
	 *
	 * @param string           $columnName The bare column name.
	 * @param RbacOperatorTerm $term       The operator and its operand.
	 *
	 * @return mixed The expression, or null when the operator is no comparison.
	 */
	private function comparison(string $columnName, RbacOperatorTerm $term): mixed {
		$method = (self::COMPARISONS[$term->operator] ?? null);
		if ($method === null) {
			return null;
		}

		$resolved = $this->sql->matcher->resolveDynamicValue(value: $term->operand);
		if (is_array($resolved) === true) {
			$this->sql->warnArrayOperand(operator: (string)$term->operator);
			return $this->impossible();
		}

		return $this->qb->expr()->{$method}("t.{$columnName}", $this->bindScalar(value: $resolved));
	}//end comparison()

	/**
	 * Compile `$in` / `$nin`.
	 *
	 * A map or a scalar is not a list of values: `in('Array')` matched nothing
	 * by accident and `$nin` over it matched everything (openregister#4089).
	 *
	 * @param string           $columnName The bare column name.
	 * @param RbacOperatorTerm $term       The operator and its operand.
	 *
	 * @return mixed The expression, or null when the operator is no list operator.
	 */
	private function listMembership(string $columnName, RbacOperatorTerm $term): mixed {
		$method = (self::LISTS[$term->operator] ?? null);
		if ($method === null) {
			return null;
		}

		if ($term->operandIsList() === false) {
			return $this->impossible();
		}

		if ($term->operand === []) {
			return $this->emptyList(columnName: $columnName, term: $term);
		}

		$values = $this->qb->createNamedParameter($term->operand, IQueryBuilder::PARAM_STR_ARRAY);
		return $this->qb->expr()->{$method}("t.{$columnName}", $values);
	}//end listMembership()

	/**
	 * An empty list: nothing is in it, and every present value is not.
	 *
	 * @param string           $columnName The bare column name.
	 * @param RbacOperatorTerm $term       `$in` or `$nin` with an empty list.
	 *
	 * @return mixed The expression.
	 */
	private function emptyList(string $columnName, RbacOperatorTerm $term): mixed {
		if ($term->operator === '$in') {
			return $this->impossible();
		}

		return $this->qb->expr()->isNotNull("t.{$columnName}");
	}//end emptyList()

	/**
	 * Compile `$contains` as a raw fragment from the raw-SQL compiler.
	 *
	 * The QueryBuilder cannot express JSON-array containment on either
	 * platform; the fragment comes from the SAME method the raw-SQL emitter
	 * uses, so the two list paths cannot drift apart.
	 *
	 * @param string           $columnName The bare column name.
	 * @param RbacOperatorTerm $term       The operator and its operand.
	 *
	 * @return mixed The expression, or null when the operator is not `$contains`.
	 */
	private function contains(string $columnName, RbacOperatorTerm $term): mixed {
		if ($term->operator !== '$contains') {
			return null;
		}

		$fragment = $this->sql->contains(
			column: 't.'.$this->sql->dialect->quotedColumn(columnName: $columnName),
			term: $term
		);
		if ($fragment === null) {
			return null;
		}

		return $this->qb->createFunction($fragment);
	}//end contains()

	/**
	 * Compile `$exists`.
	 *
	 * @param string           $columnName The bare column name.
	 * @param RbacOperatorTerm $term       The operator and its operand (true for "is set").
	 *
	 * @return mixed The expression, or null when the operator is not `$exists`.
	 */
	private function exists(string $columnName, RbacOperatorTerm $term): mixed {
		if ($term->operator !== '$exists') {
			return null;
		}

		if ($term->operand === true) {
			return $this->qb->expr()->isNotNull("t.{$columnName}");
		}

		return $this->qb->expr()->isNull("t.{$columnName}");
	}//end exists()

	/**
	 * Log an operator no emitter knows.
	 *
	 * @param RbacOperatorTerm $term The operator and its operand.
	 *
	 * @return mixed Always null: the caller turns it into the impossible predicate.
	 */
	private function unknownOperator(RbacOperatorTerm $term): mixed {
		$this->sql->dialect->logger->warning(
			message: '[MagicRbacHandler] Unknown operator',
			context: ['file' => __FILE__, 'line' => __LINE__, 'operator' => $term->operator]
		);

		return null;
	}//end unknownOperator()

	/**
	 * Bind a scalar, a boolean as a real boolean.
	 *
	 * PostgreSQL refuses `''` (how PHP stringifies false) for a boolean column.
	 *
	 * @param mixed $value The scalar.
	 *
	 * @return IParameter The bound parameter.
	 */
	private function bindScalar(mixed $value): IParameter {
		if (is_bool($value) === true) {
			return $this->qb->createNamedParameter($value, IQueryBuilder::PARAM_BOOL);
		}

		return $this->qb->createNamedParameter($value);
	}//end bindScalar()
}//end class
