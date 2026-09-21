<?php

/**
 * The shape of the `EXISTS` clause a related-row filter becomes.
 *
 * 🔴 THE TWO DEFECTS THIS SUITE PINS WERE BOTH FOUND BY RUNNING THE SQL, NOT BY
 * READING IT, AND NEITHER WAS REACHABLE FROM A RENDERER TEST WRITTEN FIRST.
 *
 * The first was `object ->> 'value' >= '100'` matching a stored `50`, because
 * `->>` yields text and `'50' >= '100'` is true in text ordering. A renderer
 * test written before running it would have asserted exactly that SQL and gone
 * green. The second was the fix for the first: guarding both sides with
 * `CASE WHEN ... ~ '<number>'` still failed, because Postgres folds constant
 * expressions at plan time and the cast of a date literal raised before any
 * `WHEN` ran.
 *
 * So the tests below assert the CONSEQUENCE of those findings, not the SQL
 * string: a numeric bound is never compared as text, a non-numeric bound is
 * never cast, and the access predicate is inside the subquery. The live-database
 * evidence is recorded in the PR body, because this suite cannot reach a
 * database.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Query
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Query;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Query\RelatedRowExistsClause;
use OCA\OpenRegister\Service\Query\RelatedRowFilter;
use PHPUnit\Framework\TestCase;

/**
 * One clause per filter, on each engine.
 *
 * @covers \OCA\OpenRegister\Service\Query\RelatedRowExistsClause
 */
class RelatedRowExistsClauseTest extends TestCase {

	/**
	 * The clause under test.
	 *
	 * @var RelatedRowExistsClause
	 */
	private RelatedRowExistsClause $clause;

	/**
	 * Build the clause.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clause = new RelatedRowExistsClause();
	}//end setUp()

	/**
	 * A filter with the given conditions.
	 *
	 * @param array<int, array<string, mixed>> $conditions The conditions.
	 *
	 * @return RelatedRowFilter The filter.
	 */
	private function filter(array $conditions): RelatedRowFilter {
		return new RelatedRowFilter('caseProperty', 'case', $conditions);
	}//end filter()

	/**
	 * Render one filter with a stock access predicate.
	 *
	 * @param array<int, array<string, mixed>> $conditions The conditions.
	 * @param string                           $engine     The engine.
	 *
	 * @return array{sql: string, parameters: array<string, mixed>} The clause.
	 */
	private function render(array $conditions, string $engine = RelatedRowExistsClause::ENGINE_POSTGRES): array {
		return $this->clause->render(
			filter: $this->filter($conditions),
			engine: $engine,
			table: 'oc_openregister_objects',
			outerAlias: 'o',
			innerAlias: 'r0',
			accessPredicate: 'r0.owner = :me',
			parameterPrefix: 'rel0'
		);
	}//end render()

	/**
	 * 🔴 THE DEFECT: a numeric bound must never be compared as text.
	 *
	 * Pinned as "the bound value does not appear in a bare text comparison with
	 * an ordering operator", because that is the thing that let `50` answer
	 * `>= 100`. Verified against a live Postgres: before this, the query for
	 * `value gte 100` returned a case whose only matching row held `50`.
	 *
	 * @return void
	 */
	public function testAnOrderingComparisonOnANumberIsNumericNotText(): void {
		$sql = $this->render([['field' => 'value', 'operator' => 'gte', 'value' => '100']])['sql'];

		$this->assertStringContainsString('::numeric >= :rel0_c0', $sql);
		$this->assertStringNotContainsString("object ->> 'value' >= :rel0_c0", $sql);
	}//end testAnOrderingComparisonOnANumberIsNumericNotText()

	/**
	 * 🔴 THE SECOND DEFECT: a non-numeric bound must never be cast.
	 *
	 * An ISO date compares correctly as text and raises
	 * `invalid input syntax for type numeric` if cast, and Postgres folds that
	 * cast at plan time so no `CASE` guard saves it. The clause therefore
	 * decides in PHP and emits no cast at all here.
	 *
	 * @return void
	 */
	public function testAnOrderingComparisonOnADateStaysText(): void {
		$sql = $this->render([['field' => 'value', 'operator' => 'gte', 'value' => '2026-06-01']])['sql'];

		$this->assertStringContainsString("r0.object ->> 'value' >= :rel0_c0", $sql);
		$this->assertStringNotContainsString('numeric', $sql);
		$this->assertStringNotContainsString('CASE', $sql);
	}//end testAnOrderingComparisonOnADateStaysText()

	/**
	 * Equality needs no cast on either side, so it gets none.
	 *
	 * @return void
	 */
	public function testEqualityIsComparedAsText(): void {
		$sql = $this->render([['field' => 'value', 'operator' => 'eq', 'value' => '100']])['sql'];

		$this->assertStringContainsString("r0.object ->> 'value' = :rel0_c0", $sql);
		$this->assertStringNotContainsString('numeric', $sql);
	}//end testEqualityIsComparedAsText()

	/**
	 * A stored value that is not a number is not greater than one.
	 *
	 * The guard's else arm is FALSE rather than a text comparison, because
	 * mixing the two orderings in one query is exactly how `50 >= 100` got in.
	 *
	 * @return void
	 */
	public function testANonNumericStoredValueCannotSatisfyANumericOrdering(): void {
		$sql = $this->render([['field' => 'value', 'operator' => 'gt', 'value' => '5']])['sql'];

		$this->assertStringContainsString('ELSE FALSE END', $sql);
	}//end testANonNumericStoredValueCannotSatisfyANumericOrdering()

	/**
	 * 🔴 THE ACCESS PREDICATE IS INSIDE THE SUBQUERY, not beside it.
	 *
	 * Outside it, the subquery decides which objects a reader sees by consulting
	 * rows they may not read, and what leaks is the EXISTENCE of a related row.
	 * Verified live: the same query with `owner = 'alice'` returned nothing and
	 * with `owner = 'bob'` returned the case, the predicate being the only
	 * difference.
	 *
	 * @return void
	 */
	public function testTheAccessPredicateIsInsideTheSubquery(): void {
		$sql = $this->render([['field' => 'value', 'operator' => 'eq', 'value' => 'x']])['sql'];

		$open  = strpos($sql, 'EXISTS (');
		$owner = strpos($sql, 'r0.owner = :me');

		$this->assertIsInt($open);
		$this->assertIsInt($owner);
		$this->assertGreaterThan($open, $owner, 'The access predicate must sit inside the EXISTS body.');
		$this->assertStringEndsWith(')', $sql);
	}//end testTheAccessPredicateIsInsideTheSubquery()

	/**
	 * Rendering without an access predicate is refused, not defaulted.
	 *
	 * @return void
	 */
	public function testRenderingWithoutAnAccessPredicateIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->clause->render(
			filter: $this->filter([]),
			engine: RelatedRowExistsClause::ENGINE_POSTGRES,
			table: 'oc_openregister_objects',
			outerAlias: 'o',
			innerAlias: 'r0',
			accessPredicate: '   ',
			parameterPrefix: 'rel0'
		);
	}//end testRenderingWithoutAnAccessPredicateIsRefused()

	/**
	 * A soft-deleted related row is not a row.
	 *
	 * Without this a case keeps matching on a property somebody removed, which
	 * reads to the user as the removal not having worked.
	 *
	 * @return void
	 */
	public function testSoftDeletedRelatedRowsAreExcluded(): void {
		$sql = $this->render([])['sql'];

		$this->assertStringContainsString('r0.deleted IS NULL', $sql);
	}//end testSoftDeletedRelatedRowsAreExcluded()

	/**
	 * An empty `in` matches nothing, and says so.
	 *
	 * 🔑 "No options" must never become "every option". A dropped condition
	 * widens the filter, and wider is the direction that discloses.
	 *
	 * @return void
	 */
	public function testAnEmptyInMatchesNothingRatherThanBeingDropped(): void {
		$sql = $this->render([['field' => 'propertyDefinition', 'operator' => 'in', 'value' => []]])['sql'];

		$this->assertStringContainsString('1 = 0', $sql);
	}//end testAnEmptyInMatchesNothingRatherThanBeingDropped()

	/**
	 * `in` binds one placeholder per value, so no value is lost.
	 *
	 * @return void
	 */
	public function testInBindsOnePlaceholderPerValue(): void {
		$clause = $this->render([['field' => 'propertyDefinition', 'operator' => 'in', 'value' => ['a', 'b', 'c']]]);

		$this->assertStringContainsString('IN (:rel0_c0_0, :rel0_c0_1, :rel0_c0_2)', $clause['sql']);
		$this->assertSame('a', $clause['parameters']['rel0_c0_0']);
		$this->assertSame('c', $clause['parameters']['rel0_c0_2']);
	}//end testInBindsOnePlaceholderPerValue()

	/**
	 * MariaDB gets its own JSON spelling, with the quotes stripped.
	 *
	 * `JSON_EXTRACT` keeps the quotes, so `"7"` would never equal `7`. This is
	 * written for MariaDB and, as the PR body says plainly, was NOT exercised
	 * against a MariaDB server: this machine has none.
	 *
	 * @return void
	 */
	public function testMariaDbUsesJsonUnquoteAndDecimalCasts(): void {
		$sql = $this->render(
			[['field' => 'value', 'operator' => 'gte', 'value' => '100']],
			RelatedRowExistsClause::ENGINE_MARIADB
		)['sql'];

		$this->assertStringContainsString("JSON_UNQUOTE(JSON_EXTRACT(r0.object, '$.value'))", $sql);
		$this->assertStringContainsString('CAST(', $sql);
		$this->assertStringContainsString('AS DECIMAL(65,30)) >= :rel0_c0', $sql);
		$this->assertStringNotContainsString('->>', $sql);
	}//end testMariaDbUsesJsonUnquoteAndDecimalCasts()

	/**
	 * An unknown engine is refused rather than rendered as Postgres.
	 *
	 * @return void
	 */
	public function testAnUnknownEngineIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->render([['field' => 'value', 'operator' => 'eq', 'value' => 'x']], 'sqlite');
	}//end testAnUnknownEngineIsRefused()

	/**
	 * 🔑 TWO BLOCKS ON ONE SCHEMA STAY TWO CLAUSES WITH SEPARATE BINDINGS.
	 *
	 * Folded into one they ask for a row that is two property definitions at
	 * once, which no row is. Sharing a parameter prefix is the quieter failure:
	 * the second block overwrites the first's bindings, the query runs, and it
	 * answers a question nobody asked without failing.
	 *
	 * @return void
	 */
	public function testTwoBlocksProduceTwoClausesWithDistinctBindings(): void {
		$rendered = $this->clause->renderAll(
			filters: [
				$this->filter([['field' => 'propertyDefinition', 'operator' => 'eq', 'value' => 'pd-7']]),
				$this->filter([['field' => 'propertyDefinition', 'operator' => 'eq', 'value' => 'pd-9']]),
			],
			engine: RelatedRowExistsClause::ENGINE_POSTGRES,
			table: 'oc_openregister_objects',
			outerAlias: 'o',
			accessPredicateFor: static fn(string $alias): string => $alias . '.owner = :me'
		);

		$this->assertCount(2, $rendered['sql']);
		$this->assertSame('pd-7', $rendered['parameters']['rel0_c0']);
		$this->assertSame('pd-9', $rendered['parameters']['rel1_c0']);
		$this->assertStringContainsString('rel0.owner = :me', $rendered['sql'][0]);
		$this->assertStringContainsString('rel1.owner = :me', $rendered['sql'][1]);
	}//end testTwoBlocksProduceTwoClausesWithDistinctBindings()

	/**
	 * Every operator the parser accepts renders to SQL, or throws.
	 *
	 * A silently unhandled operator would drop its condition and widen the
	 * filter, which is the failure the parser exists to prevent.
	 *
	 * @return void
	 */
	public function testEveryParserOperatorRenders(): void {
		foreach (['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in'] as $operator) {
			$value  = ($operator === 'in' ? ['1'] : '1');
			$clause = $this->render([['field' => 'value', 'operator' => $operator, 'value' => $value]]);

			$this->assertStringContainsString('rel0_c0', $clause['sql'], $operator . ' rendered no binding');
		}
	}//end testEveryParserOperatorRenders()

	/**
	 * Render one filter against a magic table.
	 *
	 * @param array<int, array<string, mixed>> $conditions The conditions.
	 *
	 * @return array{sql: string, parameters: array<string, mixed>} The clause.
	 */
	private function renderColumns(array $conditions): array {
		return $this->clause->render(
			filter: new RelatedRowFilter('syncLog', 'synchronization_id', $conditions),
			engine: RelatedRowExistsClause::ENGINE_POSTGRES,
			table: 'oc_openregister_table_29_1108',
			outerAlias: 'o',
			innerAlias: 'r0',
			accessPredicate: 'r0._owner = :me',
			parameterPrefix: 'rel0',
			storage: RelatedRowExistsClause::STORAGE_COLUMNS
		);
	}//end renderColumns()

	/**
	 * 🔴 A MAGIC TABLE'S PROPERTIES ARE REAL COLUMNS, NOT JSON.
	 *
	 * This is the storage the live search path uses: `MagicMapper` resolves
	 * `oc_openregister_table_<register>_<schema>` for every read, and there are
	 * 1,340 such tables on the development instance while
	 * `oc_openregister_objects` holds zero rows. A clause that only spoke JSON
	 * could never filter anything a user can actually see.
	 *
	 * @return void
	 */
	public function testAMagicTablePropertyIsAColumnNotAJsonExpression(): void {
		$sql = $this->renderColumns([['field' => 'found', 'operator' => 'gte', 'value' => '6']])['sql'];

		$this->assertStringContainsString('r0."found" >= :rel0_c0', $sql);
		$this->assertStringNotContainsString('->>', $sql);
		$this->assertStringNotContainsString('object', $sql);
	}//end testAMagicTablePropertyIsAColumnNotAJsonExpression()

	/**
	 * 🔴 A TYPED COLUMN MUST NOT GET THE NUMERIC-VERSUS-TEXT MACHINERY.
	 *
	 * `found` is an `integer` column, so `>=` already compares numerically.
	 * Casting it, or guarding it with a regex only a string can satisfy, breaks
	 * a comparison the database gets right unaided. Measured on the live
	 * instance with the discriminating value 6: the column comparison answers
	 * 4 parents and the text comparison answers 0.
	 *
	 * @return void
	 */
	public function testATypedColumnIsComparedWithoutCastsOrGuards(): void {
		$sql = $this->renderColumns([['field' => 'found', 'operator' => 'gte', 'value' => '6']])['sql'];

		$this->assertStringNotContainsString('CASE', $sql);
		$this->assertStringNotContainsString('numeric', $sql);
		$this->assertStringNotContainsString('~', $sql);
	}//end testATypedColumnIsComparedWithoutCastsOrGuards()

	/**
	 * A magic table IS one schema, so the clause must not name the schema.
	 *
	 * Naming it would narrow correctly by accident, against `_schema`, while
	 * implying the table holds more than one schema.
	 *
	 * @return void
	 */
	public function testAMagicTableClauseDoesNotNameTheSchema(): void {
		$clause = $this->renderColumns([['field' => 'found', 'operator' => 'gte', 'value' => '6']]);

		$this->assertArrayNotHasKey('rel0_schema', $clause['parameters']);
		$this->assertStringNotContainsString('"schema"', $clause['sql']);
	}//end testAMagicTableClauseDoesNotNameTheSchema()

	/**
	 * Magic tables prefix every metadata column with an underscore.
	 *
	 * That prefix is why a schema may legitimately carry its own property
	 * called `deleted`, and why reading the objects table's names here would
	 * silently filter on the wrong column.
	 *
	 * @return void
	 */
	public function testAMagicTableUsesTheUnderscoredMetadataColumns(): void {
		$sql = $this->renderColumns([])['sql'];

		$this->assertStringContainsString('r0._deleted IS NULL', $sql);
		$this->assertStringContainsString('= o._uuid', $sql);
	}//end testAMagicTableUsesTheUnderscoredMetadataColumns()

	/**
	 * A property name that is not an identifier is refused, not quoted.
	 *
	 * A column cannot be a bound parameter on any engine, so the name is
	 * checked instead. The parser produced it, but "the parser produced it" is
	 * the reasoning behind most injection.
	 *
	 * @return void
	 */
	public function testANonIdentifierColumnNameIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->renderColumns([['field' => 'found"; DROP TABLE x --', 'operator' => 'eq', 'value' => '1']]);
	}//end testANonIdentifierColumnNameIsRefused()

	/**
	 * An unknown storage is refused rather than guessed.
	 *
	 * @return void
	 */
	public function testAnUnknownStorageIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->clause->render(
			filter: $this->filter([['field' => 'value', 'operator' => 'eq', 'value' => 'x']]),
			engine: RelatedRowExistsClause::ENGINE_POSTGRES,
			table: 'whatever',
			outerAlias: 'o',
			innerAlias: 'r0',
			accessPredicate: 'TRUE',
			parameterPrefix: 'rel0',
			storage: 'mongo'
		);
	}//end testAnUnknownStorageIsRefused()

	/**
	 * The access predicate is required on a magic table too.
	 *
	 * @return void
	 */
	public function testAMagicTableClauseAlsoRequiresTheAccessPredicate(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->clause->render(
			filter: new RelatedRowFilter('syncLog', 'synchronization_id', []),
			engine: RelatedRowExistsClause::ENGINE_POSTGRES,
			table: 'oc_openregister_table_29_1108',
			outerAlias: 'o',
			innerAlias: 'r0',
			accessPredicate: '',
			parameterPrefix: 'rel0',
			storage: RelatedRowExistsClause::STORAGE_COLUMNS
		);
	}//end testAMagicTableClauseAlsoRequiresTheAccessPredicate()
}//end class
