<?php

/**
 * Regression: `@self` metadata filters must support comparison operators.
 *
 * Before this fix no query could express "created before X". On the single-table
 * path `applyMetadataFilters()` had no operator inspection at all, so
 * `{"@self":{"created":{"lte":"…"}}}` became `_created IN ('…')` — a disguised
 * equality against a timestamp column that matched nothing. On the raw-SQL UNION
 * path the metadata step was missing entirely, so the same filter was dropped and
 * the query returned rows the caller had explicitly excluded.
 *
 * The positive control that distinguishes the two failure modes: a cutoff far in
 * the FUTURE must return everything. Under the old single-table code it returned
 * nothing (mistranslated), and under the old UNION code an impossible cutoff
 * returned everything (dropped). A test that only ever asserts "zero rows" cannot
 * tell a working filter from a broken one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
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

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Exception\UnknownMetadataFieldException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Locks comparison-operator support on `@self` metadata filters, on both the
 * QueryBuilder path and the raw-SQL UNION path.
 */
class MagicSearchHandlerMetadataOperatorTest extends TestCase {

	private IDBConnection&MockObject $db;

	private LoggerInterface&MockObject $logger;

	private MagicSearchHandler $handler;

	/**
	 * WHERE fragments captured from the QueryBuilder path.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->handler = new MagicSearchHandler(
			db: $this->db,
			logger: $this->logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($this->logger)
		);

		$this->captured = [];
	}//end setUp()

	/**
	 * Build a connection mock whose quote() wraps values in single quotes.
	 *
	 * @return object The connection double.
	 */
	private function makeConnection(): object {
		$conn = $this->createMock(IDBConnection::class);
		$conn->method('quote')->willReturnCallback(static fn ($v) => "'{$v}'");
		return $conn;
	}//end makeConnection()

	/**
	 * Run applyMetadataFilters() against a QueryBuilder double and return the
	 * WHERE fragments it produced, rendered as readable `op(column,value)` text.
	 *
	 * @param array<string,mixed> $filters The `@self` bag.
	 *
	 * @return string[] Captured WHERE fragments.
	 */
	private function invokeApply(array $filters): array {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'neq', 'lt', 'lte', 'gt', 'gte', 'in', 'notIn'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				static function (string $column, $value) use ($operator): string {
					if (is_array($value) === true) {
						$value = implode('|', $value);
					}

					return "{$operator}({$column},{$value})";
				}
			);
		}

		$expr->method('isNull')->willReturnCallback(static fn (string $c): string => "isNull({$c})");
		$expr->method('isNotNull')->willReturnCallback(static fn (string $c): string => "isNotNull({$c})");

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		// Return the bound value itself so the rendered fragment shows what was bound.
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string)$predicate;
				}

				return $qb;
			}
		);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyMetadataFilters');
		$method->setAccessible(true);
		$method->invoke($this->handler, $qb, $filters);

		return $this->captured;
	}//end invokeApply()

	/**
	 * Run the raw-SQL UNION metadata builder and return its conditions.
	 *
	 * @param array<string,mixed> $query Full query array (must carry `@self`).
	 *
	 * @return string[] Generated SQL conditions.
	 */
	private function invokeUnion(array $query): array {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildMetadataFilterConditionsSql');
		$method->setAccessible(true);
		return $method->invoke($this->handler, $query, $this->makeConnection(), true);
	}//end invokeUnion()

	/**
	 * `created` with an `lte` operator must emit a `<=` comparison.
	 *
	 * This is the exact query that returned zero rows for every cutoff before the
	 * fix, including cutoffs far in the future.
	 *
	 * @return void
	 */
	public function testCreatedLteEmitsALessThanOrEqualComparison(): void {
		$captured = $this->invokeApply(['created' => ['lte' => '2026-01-01T00:00:00Z']]);

		$this->assertContains('lte(t._created,2026-01-01T00:00:00Z)', $captured);

		// The mistranslation this replaces: an IN() list built from the operator
		// bag's VALUES, which discarded the `lte` key entirely.
		foreach ($captured as $fragment) {
			$this->assertStringNotContainsString('in(t._created', $fragment);
		}
	}//end testCreatedLteEmitsALessThanOrEqualComparison()

	/**
	 * A cutoff far in the future is still a `<=` comparison, not a no-op.
	 *
	 * The positive control in test form: the generated predicate must be the same
	 * SHAPE regardless of the value, so a future cutoff selects everything rather
	 * than matching a literal timestamp string.
	 *
	 * @return void
	 */
	public function testAFutureCutoffProducesTheSameComparisonShape(): void {
		$captured = $this->invokeApply(['created' => ['lte' => '2030-01-01T00:00:00Z']]);

		$this->assertContains('lte(t._created,2030-01-01T00:00:00Z)', $captured);
	}//end testAFutureCutoffProducesTheSameComparisonShape()

	/**
	 * Every comparison operator in the shared vocabulary reaches SQL.
	 *
	 * @return void
	 */
	public function testEveryComparisonOperatorIsTranslated(): void {
		$captured = $this->invokeApply(
			[
				'created' => [
					'gte' => 'a',
					'lte' => 'b',
					'gt' => 'c',
					'lt' => 'd',
					'ne' => 'e',
				],
			]
		);

		$this->assertContains('gte(t._created,a)', $captured);
		$this->assertContains('lte(t._created,b)', $captured);
		$this->assertContains('gt(t._created,c)', $captured);
		$this->assertContains('lt(t._created,d)', $captured);
		$this->assertContains('neq(t._created,e)', $captured);
	}//end testEveryComparisonOperatorIsTranslated()

	/**
	 * `gte` plus `lte` AND together into a bounded range.
	 *
	 * @return void
	 */
	public function testGteAndLteCombineIntoARange(): void {
		$captured = $this->invokeApply(['updated' => ['gte' => '2026-01-01', 'lte' => '2026-02-01']]);

		$this->assertContains('gte(t._updated,2026-01-01)', $captured);
		$this->assertContains('lte(t._updated,2026-02-01)', $captured);
	}//end testGteAndLteCombineIntoARange()

	/**
	 * A bare list with no operator key keeps its historical IN() meaning.
	 *
	 * Guards against the fix breaking `{"@self":{"register":[1,2]}}`.
	 *
	 * @return void
	 */
	public function testABareListStillMeansIn(): void {
		$captured = $this->invokeApply(['register' => [1, 2]]);

		$this->assertContains('in(t._register,1|2)', $captured);
	}//end testABareListStillMeansIn()

	/**
	 * A plain scalar still means equality.
	 *
	 * @return void
	 */
	public function testAScalarStillMeansEquality(): void {
		$captured = $this->invokeApply(['uuid' => 'abc']);

		$this->assertContains('eq(t._uuid,abc)', $captured);
	}//end testAScalarStillMeansEquality()

	/**
	 * A null check must not ALSO emit an equality against the sentinel string.
	 *
	 * The branch had no `continue`, so `IS NOT NULL` produced
	 * `_owner IS NOT NULL AND _owner = 'IS NOT NULL'` — zero rows on a text column
	 * and a cast error on a timestamp one.
	 *
	 * @return void
	 */
	public function testANullCheckDoesNotAlsoCompareAgainstTheSentinel(): void {
		$captured = $this->invokeApply(['owner' => 'IS NOT NULL']);

		$this->assertSame(['isNotNull(t._owner)'], $captured);
	}//end testANullCheckDoesNotAlsoCompareAgainstTheSentinel()

	/**
	 * The IS NULL sentinel gets the same treatment.
	 *
	 * @return void
	 */
	public function testIsNullDoesNotAlsoCompareAgainstTheSentinel(): void {
		$captured = $this->invokeApply(['deleted' => 'IS NULL']);

		$this->assertSame(['isNull(t._deleted)'], $captured);
	}//end testIsNullDoesNotAlsoCompareAgainstTheSentinel()

	/**
	 * A camelCase metadata field resolves to its snake_case column.
	 *
	 * `schemaVersion` addressed a `_schemaVersion` column that does not exist and
	 * died as an opaque HTTP 500; the real column is `_schema_version`.
	 *
	 * @return void
	 */
	public function testACamelCaseFieldResolvesToItsSnakeCaseColumn(): void {
		$captured = $this->invokeApply(['schemaVersion' => '1.0']);

		$this->assertContains('eq(t._schema_version,1.0)', $captured);
	}//end testACamelCaseFieldResolvesToItsSnakeCaseColumn()

	/**
	 * An unknown metadata field fails loud instead of reaching SQL.
	 *
	 * @return void
	 */
	public function testAnUnknownMetadataFieldIsRefusedByName(): void {
		$this->expectException(UnknownMetadataFieldException::class);
		$this->expectExceptionMessage('nonsense');

		$this->invokeApply(['nonsense' => 'x']);
	}//end testAnUnknownMetadataFieldIsRefusedByName()

	/**
	 * The rejection message lists what IS filterable, so it is correctable.
	 *
	 * @return void
	 */
	public function testTheRejectionNamesTheFilterableFields(): void {
		try {
			$this->invokeApply(['creatd' => 'x']);
			$this->fail('Expected UnknownMetadataFieldException.');
		} catch (UnknownMetadataFieldException $e) {
			$this->assertSame('creatd', $e->getField());
			$this->assertStringContainsString('created', $e->getMessage());
			$this->assertStringContainsString('uuid', $e->getMessage());
		}
	}//end testTheRejectionNamesTheFilterableFields()

	// ---------------------------------------------------------------------
	// UNION path — the metadata step that did not exist at all.
	// ---------------------------------------------------------------------

	/**
	 * The UNION path emits a metadata condition rather than dropping the filter.
	 *
	 * Dropping returned too MANY rows, which is the dangerous direction: the
	 * caller's explicit exclusion silently did nothing.
	 *
	 * @return void
	 */
	public function testTheUnionPathNoLongerDropsMetadataFilters(): void {
		$conditions = $this->invokeUnion(['@self' => ['created' => ['lte' => '2026-01-01']]]);

		$this->assertNotEmpty($conditions, 'A @self filter must produce at least one UNION condition.');
		$this->assertContains('"_created" <= \'2026-01-01\'', $conditions);
	}//end testTheUnionPathNoLongerDropsMetadataFilters()

	/**
	 * The UNION path speaks the same operator vocabulary as the QueryBuilder path.
	 *
	 * @return void
	 */
	public function testTheUnionPathTranslatesEveryComparisonOperator(): void {
		$conditions = $this->invokeUnion(
			[
				'@self' => [
					'created' => [
						'gte' => 'a',
						'lte' => 'b',
						'gt' => 'c',
						'lt' => 'd',
						'ne' => 'e',
					],
				],
			]
		);

		$this->assertContains('"_created" >= \'a\'', $conditions);
		$this->assertContains('"_created" <= \'b\'', $conditions);
		$this->assertContains('"_created" > \'c\'', $conditions);
		$this->assertContains('"_created" < \'d\'', $conditions);
		$this->assertContains('"_created" <> \'e\'', $conditions);
	}//end testTheUnionPathTranslatesEveryComparisonOperator()

	/**
	 * The UNION path handles the null sentinels and bare IN lists too.
	 *
	 * @return void
	 */
	public function testTheUnionPathHandlesSentinelsAndBareLists(): void {
		$this->assertContains('"_owner" IS NOT NULL', $this->invokeUnion(['@self' => ['owner' => 'IS NOT NULL']]));
		$this->assertContains('"_deleted" IS NULL', $this->invokeUnion(['@self' => ['deleted' => 'IS NULL']]));
		$this->assertContains(
			'"_register" IN (\'1\', \'2\')',
			$this->invokeUnion(['@self' => ['register' => [1, 2]]])
		);
	}//end testTheUnionPathHandlesSentinelsAndBareLists()

	/**
	 * An empty `notIn` excludes nothing rather than emitting invalid SQL.
	 *
	 * @return void
	 */
	public function testAnEmptyNotInExcludesNothing(): void {
		$conditions = $this->invokeUnion(['@self' => ['owner' => ['notIn' => []]]]);

		$this->assertSame([], $conditions);
	}//end testAnEmptyNotInExcludesNothing()

	/**
	 * A query with no `@self` bag produces no metadata conditions.
	 *
	 * @return void
	 */
	public function testAQueryWithoutSelfProducesNoConditions(): void {
		$this->assertSame([], $this->invokeUnion(['name' => 'x']));
	}//end testAQueryWithoutSelfProducesNoConditions()

	/**
	 * The UNION path refuses an unknown metadata field just as loudly.
	 *
	 * @return void
	 */
	public function testTheUnionPathAlsoRefusesUnknownFields(): void {
		$this->expectException(UnknownMetadataFieldException::class);

		$this->invokeUnion(['@self' => ['nonsense' => 'x']]);
	}//end testTheUnionPathAlsoRefusesUnknownFields()
}//end class
