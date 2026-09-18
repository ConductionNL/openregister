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
	 * @param RelatedRowFilter $filter          The parsed filter.
	 * @param string           $engine          The database engine.
	 * @param string           $table           The objects table.
	 * @param string           $outerAlias      The alias of the outer object row.
	 * @param string           $innerAlias      The alias to give the related row.
	 * @param string           $accessPredicate SQL restricting the related rows to ones the caller may read.
	 * @param string           $parameterPrefix A prefix making this clause's placeholders unique.
	 * @param string           $storage         Whether the related rows are JSON in the objects table or columns in a magic table.
	 *
	 * @return array{sql: string, parameters: array<string, mixed>} The clause and its bindings.
	 *
	 * @throws InvalidArgumentException When the engine is unknown or no access predicate was given.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function render(
		RelatedRowFilter $filter,
		string $engine,
		string $table,
		string $outerAlias,
		string $innerAlias,
		string $accessPredicate,
		string $parameterPrefix,
		string $storage = self::STORAGE_JSON,
	): array {
		if (trim($accessPredicate) === '') {
			throw new InvalidArgumentException(
				'A related-row subquery needs the access predicate for the related schema. '
				. 'Without it the existence of rows the caller may not read decides which objects they see.'
			);
		}

		$parameters = [];
		$where      = [];

		if ($storage === self::STORAGE_JSON) {
			// The objects table holds every schema, so the schema must be named.
			// A magic table IS one schema, so naming it there would imply the
			// table holds more than one.
			$parameters[$parameterPrefix . '_schema'] = $filter->schema;
			$where[] = sprintf('%s."schema" = :%s_schema', $innerAlias, $parameterPrefix);
		}

		$where[] = sprintf(
			'%s = %s.%s',
			$this->fieldExpression(engine: $engine, storage: $storage, alias: $innerAlias, field: $filter->foreignKey),
			$outerAlias,
			$this->metadataColumn(storage: $storage, name: 'uuid')
		);

		// Soft-deleted related rows are not rows. Without this a case keeps
		// matching on a property somebody removed, which reads as the removal
		// not having worked.
		$where[] = sprintf(
			'%s.%s IS NULL',
			$innerAlias,
			$this->metadataColumn(storage: $storage, name: 'deleted')
		);

		$where[] = '(' . $accessPredicate . ')';

		foreach ($filter->conditions as $index => $condition) {
			$name = sprintf('%s_c%d', $parameterPrefix, $index);
			$left = $this->fieldExpression(engine: $engine, storage: $storage, alias: $innerAlias, field: $condition['field']);

			if ($condition['operator'] === 'in') {
				$values = array_values((array)$condition['value']);
				$placeholders = [];
				foreach ($values as $position => $value) {
					$placeholder = sprintf('%s_%d', $name, $position);
					$placeholders[] = ':' . $placeholder;
					$parameters[$placeholder] = (string)$value;
				}

				// An empty `in` matches nothing, and says so in SQL rather than
				// being dropped. A dropped condition widens the filter.
				if ($placeholders === []) {
					$where[] = '1 = 0';
				} else {
					$where[] = sprintf('%s IN (%s)', $left, implode(', ', $placeholders));
				}

				continue;
			}

			$operator = (self::SQL_OPERATORS[$condition['operator']] ?? null);
			if ($operator === null) {
				throw new InvalidArgumentException(
					sprintf('No SQL for operator \'%s\'.', (string)$condition['operator'])
				);
			}

			$where[] = $this->comparison(
				engine: $engine,
				storage: $storage,
				left: $left,
				operator: $operator,
				placeholder: $name,
				value: $condition['value']
			);
			$parameters[$name] = $condition['value'];
		}

		return [
			'sql' => sprintf(
				'EXISTS (SELECT 1 FROM %s %s WHERE %s)',
				$table,
				$innerAlias,
				implode(' AND ', $where)
			),
			'parameters' => $parameters,
		];
	}//end render()

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
	 * @param array<int, RelatedRowFilter> $filters         The parsed filters.
	 * @param string                       $engine          The database engine.
	 * @param string                       $table           The objects table.
	 * @param string                       $outerAlias      The alias of the outer object row.
	 * @param callable                     $accessPredicateFor Given the inner alias and the filter, the access predicate for rows under it.
	 * @param string                       $parameterPrefix A prefix for this query's placeholders.
	 * @param string                       $storage         The storage shape of the related rows.
	 *
	 * @return array{sql: array<int, string>, parameters: array<string, mixed>} The clauses and their bindings.
	 *
	 * @spec openspec/changes/query-related-schema-rows/specs/zoeken-filteren/spec.md
	 */
	public function renderAll(
		array $filters,
		string $engine,
		string $table,
		string $outerAlias,
		callable $accessPredicateFor,
		string $parameterPrefix = 'rel',
		string $storage = self::STORAGE_JSON,
	): array {
		$sql        = [];
		$parameters = [];

		foreach (array_values($filters) as $position => $filter) {
			$alias  = sprintf('%s%d', $parameterPrefix, $position);
			$clause = $this->render(
				filter: $filter,
				engine: $engine,
				table: $table,
				outerAlias: $outerAlias,
				innerAlias: $alias,
				accessPredicate: (string)$accessPredicateFor($alias, $filter),
				parameterPrefix: $alias,
				storage: $storage
			);

			$sql[]      = $clause['sql'];
			$parameters = array_merge($parameters, $clause['parameters']);
		}

		return [
			'sql'        => $sql,
			'parameters' => $parameters,
		];
	}//end renderAll()

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
	 * @param string $engine      The database engine.
	 * @param string $storage     Whether the field is JSON text or a typed column.
	 * @param string $left        The field expression.
	 * @param string $operator    The SQL operator.
	 * @param string $placeholder The bound parameter's name.
	 * @param mixed  $value       The bound value, read to choose the ordering.
	 *
	 * @return string The comparison.
	 */
	private function comparison(
		string $engine,
		string $storage,
		string $left,
		string $operator,
		string $placeholder,
		mixed $value,
	): string {
		$plain = sprintf('%s %s :%s', $left, $operator, $placeholder);

		// 🔴 A TYPED COLUMN NEEDS NONE OF WHAT FOLLOWS, AND IS HARMED BY IT.
		// A magic table stores `days_remaining` as `numeric` and `due_at` as a
		// timestamp, so the column's own type already orders them correctly.
		// Casting it, or guarding it with a regex that only a string can
		// satisfy, would break comparisons the database gets right unaided.
		// The machinery below exists solely because the JSON operator erases
		// the type and hands back text.
		if ($storage === self::STORAGE_COLUMNS) {
			return $plain;
		}

		if (in_array($operator, ['=', '!='], true) === true) {
			return $plain;
		}

		if (is_scalar($value) === false
			|| preg_match('/' . self::NUMERIC_PATTERN . '/', (string)$value) !== 1
		) {
			return $plain;
		}

		if ($engine === self::ENGINE_POSTGRES) {
			return sprintf(
				'(CASE WHEN %1$s ~ \'%3$s\' THEN (%1$s)::numeric %4$s :%2$s ELSE FALSE END)',
				$left,
				$placeholder,
				self::NUMERIC_PATTERN,
				$operator
			);
		}

		if ($engine === self::ENGINE_MARIADB) {
			return sprintf(
				'(CASE WHEN %1$s REGEXP \'%3$s\' THEN CAST(%1$s AS DECIMAL(65,30)) %4$s :%2$s ELSE FALSE END)',
				$left,
				$placeholder,
				self::NUMERIC_PATTERN,
				$operator
			);
		}

		throw new InvalidArgumentException(
			sprintf('No related-row SQL for engine \'%s\'.', $engine)
		);
	}//end comparison()

	/**
	 * The expression for one property, in whichever storage holds it.
	 *
	 * @param string $engine  The database engine.
	 * @param string $storage The storage shape.
	 * @param string $alias   The related row's alias.
	 * @param string $field   The property name.
	 *
	 * @return string The SQL expression.
	 *
	 * @throws InvalidArgumentException When the storage or engine is unknown.
	 */
	private function fieldExpression(string $engine, string $storage, string $alias, string $field): string {
		if ($storage === self::STORAGE_COLUMNS) {
			return sprintf('%s.%s', $alias, $this->quoteIdentifier(name: $field));
		}

		if ($storage === self::STORAGE_JSON) {
			return $this->jsonField(engine: $engine, alias: $alias, field: $field);
		}

		throw new InvalidArgumentException(
			sprintf('No related-row SQL for storage \'%s\'.', $storage)
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
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
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
	 * @param string $engine The database engine.
	 * @param string $alias  The related row's alias.
	 * @param string $field  The property name.
	 *
	 * @return string The SQL expression, yielding text.
	 *
	 * @throws InvalidArgumentException When the engine is unknown.
	 */
	private function jsonField(string $engine, string $alias, string $field): string {
		$safe = str_replace("'", "''", $field);

		if ($engine === self::ENGINE_POSTGRES) {
			return sprintf("%s.object ->> '%s'", $alias, $safe);
		}

		if ($engine === self::ENGINE_MARIADB) {
			return sprintf("JSON_UNQUOTE(JSON_EXTRACT(%s.object, '$.%s'))", $alias, $safe);
		}

		throw new InvalidArgumentException(
			sprintf('No related-row SQL for engine \'%s\'.', $engine)
		);
	}//end jsonField()
}//end class
