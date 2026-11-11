<?php

/**
 * Renders a related-row filter as an existence subquery.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Query
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Query;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One `EXISTS (...)` clause per related-row filter, per engine.
 *
 * An object matches when at least one row of the related schema, whose foreign
 * key points at it, satisfies every condition. `EXISTS` is the shape that says
 * that in one round trip and stops at the first matching row, which a join plus
 * `DISTINCT` does not: a join multiplies the outer row by every matching
 * related row and then throws the duplicates away, and the paging is computed
 * on the multiplied count.
 *
 * 🔴 THE ACCESS PREDICATE IS A REQUIRED ARGUMENT, NOT SOMETHING THIS CLASS
 * WRITES. A subquery over a second schema is a second place rows can leak, and
 * it is the easiest place to forget: the outer query is filtered by the
 * caller's access, the reader sees a filtered list, and the subquery quietly
 * consulted rows they may not read to decide which of them to show. What leaks
 * then is not the row, it is its EXISTENCE, which for a case property is the
 * fact that some case somewhere carries a value.
 *
 * This class refuses to render without one. It does not invent one either,
 * because a second evaluator of the access question disagrees with the first
 * within a week, and the one that ends up wider is the one that discloses.
 *
 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
 */
final class RelatedRowExistsClause {
	use MagicColumnNameTrait;

	/**
	 * PostgreSQL. Exercised against a live database.
	 */
	public const ENGINE_POSTGRES = 'pgsql';

	/**
	 * MariaDB and MySQL.
	 */
	public const ENGINE_MARIADB = 'mysql';

	/**
	 * The related rows live in `oc_openregister_objects`, in its JSON `object`
	 * column.
	 */
	public const STORAGE_JSON = 'json';

	/**
	 * 🔴 THE STORAGE THE LIVE SEARCH PATH ACTUALLY USES.
	 *
	 * The related rows live in a per-schema "magic" table,
	 * `oc_openregister_table_<register>_<schema>`, whose properties are REAL
	 * TYPED COLUMNS: `days_remaining numeric`, `due_at timestamp`,
	 * `is_overdue boolean`. Metadata columns are underscore-prefixed
	 * (`_uuid`, `_owner`, `_deleted`), which is why they need their own names
	 * here rather than the objects table\'s.
	 *
	 * Two consequences that are easy to get backwards:
	 *
	 * - The table IS the schema, so there is no `schema = :p` condition. Adding
	 *   one would compare against `_schema` and narrow correctly by accident,
	 *   while implying the table holds more than one schema.
	 * - The numeric-versus-text machinery the JSON shape needs is not just
	 *   unnecessary here, it is WRONG. A `numeric` column already compares
	 *   numerically; casting it, or guarding it with a string regex, would
	 *   break the comparison the column type already gets right.
	 */
	public const STORAGE_COLUMNS = 'columns';

	/**
	 * What counts as a number on both engines.
	 *
	 * Deliberately the same string for Postgres `~` and MariaDB `REGEXP`, so
	 * the two engines cannot disagree about which values are compared
	 * numerically. Anchored at both ends: `12abc` is not a number.
	 */
	private const NUMERIC_PATTERN = '^-?[0-9]+(\\.[0-9]+)?$';

	/**
	 * The guarded numeric comparison per engine: field expression (1), placeholder (2), number pattern (3), operator (4).
	 *
	 * @var array<string, string>
	 */
	private const NUMERIC_COMPARISONS = [
		self::ENGINE_POSTGRES => '(CASE WHEN %1$s ~ \'%3$s\' THEN (%1$s)::numeric %4$s :%2$s ELSE FALSE END)',
		self::ENGINE_MARIADB  => '(CASE WHEN %1$s REGEXP \'%3$s\' THEN CAST(%1$s AS DECIMAL(65,30)) %4$s :%2$s ELSE FALSE END)',
	];

	/**
	 * The SQL operator for each of the parser's operators.
	 *
	 * `in` is absent on purpose: it renders as `IN (...)` with one placeholder
	 * per value, not as a binary operator, and giving it a row here would let a
	 * caller write `x in 1` and get SQL that parses and means nothing.
	 *
	 * @var array<string, string>
	 */
	private const SQL_OPERATORS = [
		'eq' => '=',
		'ne' => '!=',
		'gt' => '>',
		'gte' => '>=',
		'lt' => '<',
		'lte' => '<=',
	];

	/**
	 * Render one filter as an `EXISTS` clause and its parameters.
	 *
	 * @param RelatedRowFilter   $filter   The parsed filter.
	 * @param RelatedRowSubquery $subquery The SQL target (engine, related table, outer alias, storage) and the
	 *                                     inner row (alias, access predicate, placeholder prefix).
	 *
	 * @return array{sql: string, parameters: array<string, mixed>} The clause and its bindings.
	 *
	 * @throws InvalidArgumentException When the engine is unknown or no access predicate was given.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function render(RelatedRowFilter $filter, RelatedRowSubquery $subquery): array {
		$inner = $subquery->inner;
		if (trim($inner->accessPredicate) === '') {
			throw new InvalidArgumentException(
				'A related-row subquery needs the access predicate for the related schema. '
				. 'Without it the existence of rows the caller may not read decides which objects they see.'
			);
		}

		$clause     = $this->rowConditions(filter: $filter, subquery: $subquery);
		$conditions = $this->filterConditions(filter: $filter, subquery: $subquery);

		return [
			'sql' => sprintf(
				'EXISTS (SELECT 1 FROM %s %s WHERE %s)',
				$subquery->target->table,
				$inner->alias,
				implode(' AND ', array_merge($clause['where'], $conditions['where']))
			),
			'parameters' => array_merge($clause['parameters'], $conditions['parameters']),
		];
	}//end render()

	/**
	 * Every condition of the filter, in order, with their bindings.
	 *
	 * @param RelatedRowFilter   $filter   The parsed filter.
	 * @param RelatedRowSubquery $subquery The SQL target and the inner row.
	 *
	 * @return array{where: array<int, string>, parameters: array<string, mixed>} The conditions and their bindings.
	 *
	 * @throws InvalidArgumentException When an operator has no SQL, or the engine or storage is unknown.
	 */
	private function filterConditions(RelatedRowFilter $filter, RelatedRowSubquery $subquery): array {
		$where      = [];
		$parameters = [];
		foreach ($filter->conditions as $index => $condition) {
			$rendered = $this->condition(
				target: $subquery->target,
				bound: new RelatedRowBoundCondition(
					left: $this->fieldExpression(target: $subquery->target, field: $subquery->innerField(name: $condition['field'])),
					placeholder: $subquery->placeholderFor(index: $index),
					operator: $condition['operator'],
					value: $condition['value']
				)
			);

			$where[]    = $rendered['where'];
			$parameters = array_merge($parameters, $rendered['parameters']);
		}

		return ['where' => $where, 'parameters' => $parameters];
	}//end filterConditions()

	/**
	 * The conditions every related row must meet before any filter condition: schema, join, not deleted, access.
	 *
	 * @param RelatedRowFilter   $filter   The parsed filter.
	 * @param RelatedRowSubquery $subquery The SQL target and the inner row.
	 *
	 * @return array{where: array<int, string>, parameters: array<string, mixed>} The conditions and their bindings.
	 *
	 * @throws InvalidArgumentException When the engine or storage is unknown.
	 */
	private function rowConditions(RelatedRowFilter $filter, RelatedRowSubquery $subquery): array {
		$target     = $subquery->target;
		$inner      = $subquery->inner;
		$innerAlias = $inner->alias;
		$parameters = [];
		$where      = [];

		if ($target->storage === self::STORAGE_JSON) {
			// The objects table holds every schema, so the schema must be named.
			// A magic table IS one schema, so naming it there would imply the
			// table holds more than one.
			$parameters[$inner->parameterPrefix . '_schema'] = $filter->schema;
			$where[] = sprintf('%s."schema" = :%s_schema', $innerAlias, $inner->parameterPrefix);
		}

		$where[] = $this->joinCondition(filter: $filter, subquery: $subquery);

		// Soft-deleted related rows are not rows. Without this a case keeps
		// matching on a property somebody removed, which reads as the removal
		// not having worked.
		$where[] = sprintf(
			'%s.%s IS NULL',
			$innerAlias,
			$this->metadataColumn(storage: $target->storage, name: 'deleted')
		);

		$where[] = '(' . $inner->accessPredicate . ')';

		return ['where' => $where, 'parameters' => $parameters];
	}//end rowConditions()

	/**
	 * One filter condition and its bindings.
	 *
	 * @param RelatedRowSqlTarget      $target The engine and storage shape.
	 * @param RelatedRowBoundCondition $bound  The condition, with its field expression and placeholder name.
	 *
	 * @return array{where: string, parameters: array<string, mixed>} The condition and its bindings.
	 *
	 * @throws InvalidArgumentException When the operator has no SQL, or the engine is unknown.
	 */
	private function condition(RelatedRowSqlTarget $target, RelatedRowBoundCondition $bound): array {
		if ($bound->operator === 'in') {
			return $this->inCondition(bound: $bound);
		}

		return [
			'where' => $this->comparison(target: $target, bound: $bound),
			'parameters' => [$bound->placeholder => $bound->value],
		];
	}//end condition()

	/**
	 * An `IN (...)` condition with one placeholder per value.
	 *
	 * @param RelatedRowBoundCondition $bound The condition: field expression, placeholder name, value or values.
	 *
	 * @return array{where: string, parameters: array<string, string>} The condition and its bindings.
	 */
	private function inCondition(RelatedRowBoundCondition $bound): array {
		$placeholders = [];
		$parameters   = [];
		foreach (array_values((array)$bound->value) as $position => $item) {
			$placeholder = sprintf('%s_%d', $bound->placeholder, $position);
			$placeholders[] = ':' . $placeholder;
			$parameters[$placeholder] = (string)$item;
		}

		// An empty `in` matches nothing, and says so in SQL rather than
		// being dropped. A dropped condition widens the filter.
		if ($placeholders === []) {
			return ['where' => '1 = 0', 'parameters' => []];
		}

		return ['where' => sprintf('%s IN (%s)', $bound->left, implode(', ', $placeholders)), 'parameters' => $parameters];
	}//end inCondition()

	/**
	 * Render every filter the parser returned, one clause each.
	 *
	 * 🔴 TWO BLOCKS ON ONE SCHEMA MUST STAY TWO CLAUSES. Asking for a case with
	 * a `pd-7` of at least 100 AND a `pd-9` of at most 5 is a question about two
	 * rows. Folded into one clause it asks for a single row that is both
	 * property definitions at once, which no row is, so the caller gets an empty
	 * list and no explanation of why. The parser already keeps numbered blocks
	 * apart; this keeps them apart in the SQL.
	 *
	 * Each clause gets its own parameter prefix from its POSITION, so two blocks
	 * over the same schema cannot bind the same placeholder name. Keying the
	 * prefix on the schema instead would have the second block silently
	 * overwrite the first block's bindings, and the query would run, and it
	 * would answer the wrong question without failing.
	 *
	 * @param RelatedRowClauseBatch $batch The filters, the target, the access predicate per inner row and the placeholder prefix.
	 *
	 * @return array{sql: array<int, string>, parameters: array<string, mixed>} The clauses and their bindings.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function renderAll(RelatedRowClauseBatch $batch): array {
		$sql        = [];
		$parameters = [];

		foreach (array_keys(array_values($batch->filters)) as $position) {
			$clause     = $this->clauseAt(batch: $batch, position: $position);
			$sql[]      = $clause['sql'];
			$parameters = array_merge($parameters, $clause['parameters']);
		}

		return [
			'sql'        => $sql,
			'parameters' => $parameters,
		];
	}//end renderAll()

	/**
	 * The clause for the block at one position, its alias and parameter prefix taken from that position.
	 *
	 * @param RelatedRowClauseBatch $batch    The filters, the target, the access predicate per inner row and the placeholder prefix.
	 * @param int                   $position The block's position in the batch.
	 *
	 * @return array{sql: string, parameters: array<string, mixed>} The clause and its bindings.
	 */
	private function clauseAt(RelatedRowClauseBatch $batch, int $position): array {
		$filter = array_values($batch->filters)[$position];
		$alias  = sprintf('%s%d', $batch->parameterPrefix, $position);

		return $this->render(
			filter: $filter,
			subquery: new RelatedRowSubquery(
				target: $batch->target,
				inner: new RelatedRowInnerRow(
					alias: $alias,
					accessPredicate: (string)($batch->accessPredicateFor)($alias, $filter),
					parameterPrefix: $alias
				)
			)
		);
	}//end clauseAt()

	/**
	 * One comparison: numeric when the caller asked for a number, text otherwise.
	 *
	 * 🔴 TWO DEFECTS HERE, BOTH FOUND BY RUNNING THE SQL AGAINST A LIVE
	 * POSTGRES AND NEITHER FINDABLE BY READING IT.
	 *
	 * The first: the original rendered `object ->> 'value' >= :p` and asked for
	 * cases with a property of at least 100. It returned a case whose value was
	 * **50**, because `->>` yields TEXT and `'50' >= '100'` is true in text
	 * ordering. Every ordering comparison on a number was quietly wrong, and
	 * wrong in the direction that returns MORE rows. A renderer test could not
	 * have caught it: the SQL was exactly what the test would have asserted.
	 *
	 * The second: the fix for the first put BOTH sides behind a
	 * `CASE WHEN ... ~ '<number>' THEN (...)::numeric ... END` guard, which
	 * looks safe and is not. Postgres folds constant expressions at PLAN time,
	 * before any `WHEN` is evaluated, so a date bound against a guarded cast
	 * raised `invalid input syntax for type numeric: "2026-06-01"` and the query
	 * failed outright. A guard does not protect a cast of something already
	 * known.
	 *
	 * So the bound side is decided HERE, in PHP, where its value is known, and
	 * never cast in SQL:
	 *
	 * - a non-numeric bound (an ISO date, a name) renders a plain text
	 *   comparison, which is the right answer for dates and the reason there is
	 *   a fallback at all;
	 * - a numeric bound renders a numeric comparison guarded on the COLUMN,
	 *   whose values genuinely are not known until the row is read.
	 *
	 * A stored value that is not a number cannot be greater than a number, so
	 * the guard's else arm is FALSE rather than a text comparison. Mixing the
	 * two orderings in one query is how `50 >= 100` got in.
	 *
	 * Equality is left alone on purpose: text equality is the right answer on
	 * both engines, and `'7' = '7'` needs no cast to be true.
	 *
	 * @param RelatedRowSqlTarget      $target The engine, and whether the field is JSON text or a typed column.
	 * @param RelatedRowBoundCondition $bound  The condition; its value is read to choose the ordering.
	 *
	 * @return string The comparison.
	 *
	 * @throws InvalidArgumentException When the operator has no SQL, or the engine is unknown.
	 */
	private function comparison(RelatedRowSqlTarget $target, RelatedRowBoundCondition $bound): string {
		$operator = (self::SQL_OPERATORS[$bound->operator] ?? null);
		if ($operator === null) {
			throw new InvalidArgumentException(
				sprintf('No SQL for operator \'%s\'.', $bound->operator)
			);
		}

		if ($this->isPlainComparison(target: $target, bound: $bound) === true) {
			return sprintf('%s %s :%s', $bound->left, $operator, $bound->placeholder);
		}

		return $this->numericComparison(engine: $target->engine, bound: $bound);
	}//end comparison()

	/**
	 * Whether a comparison is written as-is, without the numeric guard.
	 *
	 * 🔴 A TYPED COLUMN NEEDS NONE OF THE NUMERIC MACHINERY, AND IS HARMED BY
	 * IT. A magic table stores `days_remaining` as `numeric` and `due_at` as a
	 * timestamp, so the column's own type already orders them correctly.
	 * Casting it, or guarding it with a regex that only a string can satisfy,
	 * would break comparisons the database gets right unaided. The machinery
	 * exists solely because the JSON operator erases the type and hands back
	 * text. Equality, and a bound that is not a number, are plain too.
	 *
	 * Only called once `comparison()` has checked the operator has SQL, so
	 * `eq` and `ne` are exactly the operators that render as `=` and `!=`.
	 *
	 * @param RelatedRowSqlTarget      $target The storage shape.
	 * @param RelatedRowBoundCondition $bound  The condition: its operator and bound value.
	 *
	 * @return bool True when the comparison is plain.
	 */
	private function isPlainComparison(RelatedRowSqlTarget $target, RelatedRowBoundCondition $bound): bool {
		return $target->storage === self::STORAGE_COLUMNS
			|| in_array($bound->operator, ['eq', 'ne'], true) === true
			|| is_scalar($bound->value) === false
			|| preg_match('/' . self::NUMERIC_PATTERN . '/', (string)$bound->value) !== 1;
	}//end isPlainComparison()

	/**
	 * A numeric ordering comparison, guarded on the column, in the engine's spelling.
	 *
	 * Only called once `comparison()` has checked the operator has SQL.
	 *
	 * @param string                   $engine The database engine.
	 * @param RelatedRowBoundCondition $bound  The condition: field expression, placeholder name and operator.
	 *
	 * @return string The comparison.
	 *
	 * @throws InvalidArgumentException When the engine is unknown.
	 */
	private function numericComparison(string $engine, RelatedRowBoundCondition $bound): string {
		$operator = self::SQL_OPERATORS[$bound->operator];
		if (isset(self::NUMERIC_COMPARISONS[$engine]) === false) {
			throw new InvalidArgumentException(
				sprintf('No related-row SQL for engine \'%s\'.', $engine)
			);
		}

		return sprintf(self::NUMERIC_COMPARISONS[$engine], $bound->left, $bound->placeholder, self::NUMERIC_PATTERN, $operator);
	}//end numericComparison()

	/**
	 * The expression for one property, in whichever storage holds it.
	 *
	 * @param RelatedRowSqlTarget $target The engine and storage shape.
	 * @param RelatedRowField     $field  The row's alias and the property name.
	 *
	 * @return string The SQL expression.
	 *
	 * @throws InvalidArgumentException When the storage or engine is unknown.
	 */
	private function fieldExpression(RelatedRowSqlTarget $target, RelatedRowField $field): string {
		if ($target->storage === self::STORAGE_COLUMNS) {
			return sprintf('%s.%s', $field->alias, $this->quoteIdentifier(name: $this->sanitizeColumnName(name: $field->name)));
		}

		if ($target->storage === self::STORAGE_JSON) {
			return $this->jsonField(engine: $target->engine, field: $field);
		}

		throw new InvalidArgumentException(
			sprintf('No related-row SQL for storage \'%s\'.', $target->storage)
		);
	}//end fieldExpression()

	/**
	 * The name of one metadata column, which differs between the two storages.
	 *
	 * The objects table calls them `uuid` and `deleted`; a magic table prefixes
	 * every metadata column with an underscore to keep them clear of the
	 * schema's own properties, which is exactly why a schema may legitimately
	 * have a property called `deleted` (one on this instance does).
	 *
	 * @param string $storage The storage shape.
	 * @param string $name    The bare metadata name.
	 *
	 * @return string The column name.
	 *
	 * @throws InvalidArgumentException When the storage is unknown.
	 */
	private function metadataColumn(string $storage, string $name): string {
		if ($storage === self::STORAGE_COLUMNS) {
			return '_' . $name;
		}

		if ($storage === self::STORAGE_JSON) {
			return $name;
		}

		throw new InvalidArgumentException(
			sprintf('No related-row SQL for storage \'%s\'.', $storage)
		);
	}//end metadataColumn()

	/**
	 * How the related row joins the object.
	 *
	 * Inverse (`_related`): the related row's property holds the object's uuid.
	 * Forward (`_referenced`): the object's property holds the related row's uuid.
	 *
	 * @param RelatedRowFilter   $filter   The parsed filter.
	 * @param RelatedRowSubquery $subquery The engine, the storage shape, and the aliases of the object row and the related row.
	 *
	 * @return string The join condition.
	 *
	 * @spec openspec/changes/query-referenced-schema-rows/specs/zoeken-filteren/spec.md
	 */
	private function joinCondition(RelatedRowFilter $filter, RelatedRowSubquery $subquery): string {
		$target = $subquery->target;
		$uuid   = $this->metadataColumn(storage: $target->storage, name: 'uuid');

		if ($filter->direction === RelatedRowFilter::DIRECTION_FORWARD) {
			return sprintf(
				'%s.%s = %s',
				$subquery->inner->alias,
				$uuid,
				$this->fieldExpression(target: $target, field: $subquery->outerField(name: $filter->foreignKey))
			);
		}

		return sprintf(
			'%s = %s.%s',
			$this->fieldExpression(target: $target, field: $subquery->innerField(name: $filter->foreignKey)),
			$target->outerAlias,
			$uuid
		);
	}//end joinCondition()

	/**
	 * Quote a column name, refusing anything that is not one.
	 *
	 * A magic-table property becomes a bare identifier in the SQL, not a bound
	 * parameter, because no engine accepts a placeholder where a column goes.
	 * So the name is checked rather than escaped: the parser produced it, but
	 * "the parser produced it" is the reasoning behind most injection, and the
	 * check costs nothing.
	 *
	 * @param string $name The column name.
	 *
	 * @return string The quoted name.
	 *
	 * @throws InvalidArgumentException When the name is not a plain identifier.
	 */
	private function quoteIdentifier(string $name): string {
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1) {
			throw new InvalidArgumentException(
				sprintf('\'%s\' is not a column name a related-row filter may reference.', $name)
			);
		}

		return '"' . $name . '"';
	}//end quoteIdentifier()

	/**
	 * A JSON field of the object column, in the engine's own spelling.
	 *
	 * 🔴 BOTH SPELLINGS RETURN TEXT, and that is deliberate rather than
	 * incidental. Postgres `->` returns json and `->>` returns text; comparing
	 * json to a bound string raises "operator does not exist: json = unknown"
	 * on some casts and, worse, compares the QUOTED form on others, so `"7"`
	 * never equals `7`. MariaDB's `JSON_EXTRACT` keeps the quotes for the same
	 * reason, which is why it is wrapped in `JSON_UNQUOTE`.
	 *
	 * The field name is embedded rather than bound. It is a property name that
	 * came through `RelatedRowFilterParser`, and it is quoted here as a SQL
	 * string literal with the quotes doubled, because neither engine accepts a
	 * placeholder inside a JSON path expression.
	 *
	 * @param string          $engine The database engine.
	 * @param RelatedRowField $field  The row's alias and the property name.
	 *
	 * @return string The SQL expression, yielding text.
	 *
	 * @throws InvalidArgumentException When the engine is unknown.
	 */
	private function jsonField(string $engine, RelatedRowField $field): string {
		$alias = $field->alias;
		$safe  = str_replace("'", "''", $field->name);

		if ($engine === self::ENGINE_POSTGRES) {
			return sprintf("%s.object ->> '%s'", $alias, $safe);
		}

		if ($engine === self::ENGINE_MARIADB) {
			// MariaDB reads a backslash as an escape inside a string literal.
			return sprintf("JSON_UNQUOTE(JSON_EXTRACT(%s.object, '$.%s'))", $alias, str_replace('\\', '\\\\', $safe));
		}

		throw new InvalidArgumentException(
			sprintf('No related-row SQL for engine \'%s\'.', $engine)
		);
	}//end jsonField()

	/**
	 * Which engine the SQL must be written for.
	 *
	 * Read off the LIVE CONNECTION rather than configured separately, because a
	 * second source of truth for the engine is a second thing that can be wrong
	 * about it, and being wrong would surface as a syntax error in production
	 * and nowhere else.
	 *
	 * The detection matches `MagicRbacHandler::isPostgres()` deliberately,
	 * including its fallback: when the platform cannot be read, both default to
	 * MariaDB syntax. Two different guesses would put MariaDB JSON functions
	 * and Postgres operators in the same statement.
	 *
	 * @param IDBConnection   $db     The connection, read for its platform.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return string The engine.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function engineFor(IDBConnection $db, LoggerInterface $logger): string {
		try {
			$platform = $db->getDatabasePlatform();
			if (stripos(get_debug_type($platform), 'PostgreSQL') !== false) {
				return RelatedRowExistsClause::ENGINE_POSTGRES;
			}
		} catch (Throwable $e) {
			$logger->warning(
				message: '[RelatedRowExistsClause] Could not read the database platform; defaulting to MariaDB syntax',
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e->getMessage()]
			);
		}

		return RelatedRowExistsClause::ENGINE_MARIADB;
	}//end engineFor()
}//end class
