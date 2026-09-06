<?php

/**
 * Regression: the `_isnull` filter operator was documented but not implemented.
 *
 * `openspec/specs/zoeken-filteren` has advertised `?afgehandeld_op_isnull=true`
 * since 2026-03. Nothing on the live path ever honoured it. The only code that
 * mentioned the operator was `SearchQueryHandler::cleanQuery()`, which has zero
 * production callers, so the request layer never saw its output.
 *
 * What actually reaches the mapper is `buildSearchQuery()`'s underscore-to-nested
 * reconstruction: `?status_in[]=new` arrives as `status => ['in' => [...]]`, and
 * `MagicSearchHandler::COMPARISON_OPERATORS` happens to understand `in`. That is
 * why `_in`, `_notIn`, `_ne`, `_gte` and friends work. `isnull` was absent from
 * that set, so `?assignee_isnull=true` became a nested `['isnull' => 'true']` bag
 * that no builder inspected, the filter contributed no condition, and the caller
 * silently got an unrelated result set.
 *
 * Measured on a live instance before the fix, over 13 tickets of which 11 are
 * unassigned: `?assignee_isnull=true` -> 0 rows, `?assignee_isnull=false` -> 0
 * rows, `?assignee=IS NULL` (the literal sentinel) -> 11.
 *
 * The value coercion matters as much as the operator. A query string can only
 * deliver strings, so a strict `=== true` can never match, and raw truthiness
 * would make the string "false" mean true.
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
use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Db\Schema;
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
 * Locks `isnull` support on both the QueryBuilder path and the raw-SQL UNION
 * path, for object fields and for `@self` metadata.
 */
class MagicSearchHandlerIsNullOperatorTest extends TestCase {

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

		// `applyObjectFilters()` resolves the platform itself (unlike the raw-SQL
		// builder, which takes an $isPostgres flag), so the connection double has
		// to answer getDatabasePlatform(). The mock's class name carries
		// "PostgreSQL", which is what isPostgresPlatform() greps for.
		$this->db->method('getDatabasePlatform')
			->willReturn($this->createMock(PostgreSQL120Platform::class));

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
	 * A connection double whose quote() wraps values in single quotes.
	 *
	 * @return object The connection double.
	 */
	private function makeConnection(): object {
		$conn = $this->createMock(IDBConnection::class);
		$conn->method('quote')->willReturnCallback(static fn ($v) => "'{$v}'");
		return $conn;
	}//end makeConnection()

	/**
	 * A QueryBuilder double that renders each predicate as readable text.
	 *
	 * @return IQueryBuilder The query-builder double.
	 */
	private function makeQueryBuilder(): IQueryBuilder {
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
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string)$predicate;
				}

				return $qb;
			}
		);

		return $qb;
	}//end makeQueryBuilder()

	/**
	 * Run applyObjectFilters() and return the WHERE fragments it produced.
	 *
	 * @param array<string,mixed> $filters    Object-field filters.
	 * @param array<string,mixed> $properties Schema properties.
	 *
	 * @return string[] Captured WHERE fragments.
	 */
	private function invokeApplyObject(array $filters, array $properties): array {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters');
		$method->setAccessible(true);
		$method->invoke($this->handler, $this->makeQueryBuilder(), $filters, $schema);

		return $this->captured;
	}//end invokeApplyObject()

	/**
	 * Run the raw-SQL object builder and return its conditions.
	 *
	 * @param array<string,mixed> $query      Search query.
	 * @param array<string,mixed> $properties Schema properties.
	 *
	 * @return string[] Generated SQL conditions.
	 */
	private function invokeUnionObject(array $query, array $properties): array {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);

		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql');
		$method->setAccessible(true);
		return $method->invoke($this->handler, $query, $schema, $this->makeConnection(), true);
	}//end invokeUnionObject()

	/**
	 * Run applyMetadataFilters() and return the WHERE fragments it produced.
	 *
	 * @param array<string,mixed> $filters The `@self` bag.
	 *
	 * @return string[] Captured WHERE fragments.
	 */
	private function invokeApplyMetadata(array $filters): array {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyMetadataFilters');
		$method->setAccessible(true);
		$method->invoke($this->handler, $this->makeQueryBuilder(), $filters);

		return $this->captured;
	}//end invokeApplyMetadata()

	/**
	 * The nested `isnull` bag is what `?assignee_isnull=true` becomes after
	 * `buildSearchQuery()`'s underscore reconstruction, and it must reach SQL as
	 * a null check on the QueryBuilder path.
	 *
	 * @return void
	 */
	public function testObjectFieldIsNullEmitsANullCheck(): void {
		$captured = $this->invokeApplyObject(
			['assignee' => ['isnull' => 'true']],
			['assignee' => ['type' => 'string']]
		);

		$this->assertContains('isNull(t.assignee)', $captured);
	}//end testObjectFieldIsNullEmitsANullCheck()

	/**
	 * The negative half must ask for the rows that DO have a value — not be
	 * dropped, which is what "no operator matched" looked like before.
	 *
	 * @return void
	 */
	public function testObjectFieldIsNullFalseEmitsANotNullCheck(): void {
		$captured = $this->invokeApplyObject(
			['assignee' => ['isnull' => 'false']],
			['assignee' => ['type' => 'string']]
		);

		$this->assertContains('isNotNull(t.assignee)', $captured);
	}//end testObjectFieldIsNullFalseEmitsANotNullCheck()

	/**
	 * Every spelling the request layer can deliver, on the QueryBuilder path.
	 *
	 * `"false"` is the case a naive truthiness check gets wrong: a non-empty
	 * string is truthy in PHP, so `?x_isnull=false` would have asked for NULL.
	 *
	 * @dataProvider provideIsNullSpellings
	 *
	 * @param mixed  $value    Raw operator value.
	 * @param string $expected The fragment it must produce.
	 *
	 * @return void
	 */
	public function testEverySpellingResolvesToTheRightPredicate(mixed $value, string $expected): void {
		$captured = $this->invokeApplyObject(
			['assignee' => ['isnull' => $value]],
			['assignee' => ['type' => 'string']]
		);

		$this->assertContains($expected, $captured, sprintf(
			'?assignee_isnull=%s must emit %s',
			var_export($value, true),
			$expected
		));
	}//end testEverySpellingResolvesToTheRightPredicate()

	/**
	 * Spellings and the predicate each must produce.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function provideIsNullSpellings(): array {
		return [
			'the string "true", what a query string sends' => ['true', 'isNull(t.assignee)'],
			'the string "1"' => ['1', 'isNull(t.assignee)'],
			'a real boolean true' => [true, 'isNull(t.assignee)'],
			'the string "false"' => ['false', 'isNotNull(t.assignee)'],
			'the string "0"' => ['0', 'isNotNull(t.assignee)'],
			'a real boolean false' => [false, 'isNotNull(t.assignee)'],
			'an empty value' => ['', 'isNotNull(t.assignee)'],
		];
	}//end provideIsNullSpellings()

	/**
	 * The raw-SQL UNION path must agree with the QueryBuilder path. Two
	 * implementations of one filter language is exactly where a fix lands on one
	 * side only and the behaviour depends on which query shape the caller hit.
	 *
	 * @return void
	 */
	public function testRawSqlObjectPathEmitsTheSameNullCheck(): void {
		$conditions = $this->invokeUnionObject(
			['assignee' => ['isnull' => 'true']],
			['assignee' => ['type' => 'string']]
		);

		$this->assertNotEmpty($conditions, 'the isnull filter must contribute a condition, not be dropped');
		$this->assertStringContainsString('IS NULL', implode(' AND ', $conditions));
		$this->assertStringNotContainsString('IS NOT NULL', implode(' AND ', $conditions));
	}//end testRawSqlObjectPathEmitsTheSameNullCheck()

	/**
	 * And its negative half.
	 *
	 * @return void
	 */
	public function testRawSqlObjectPathEmitsNotNullForFalse(): void {
		$conditions = $this->invokeUnionObject(
			['assignee' => ['isnull' => 'false']],
			['assignee' => ['type' => 'string']]
		);

		$this->assertStringContainsString('IS NOT NULL', implode(' AND ', $conditions));
	}//end testRawSqlObjectPathEmitsNotNullForFalse()

	/**
	 * `@self` metadata fields carry the same operator vocabulary, so the null
	 * check must work there too.
	 *
	 * @return void
	 */
	public function testMetadataFieldIsNullEmitsANullCheck(): void {
		$captured = $this->invokeApplyMetadata(['deleted' => ['isnull' => 'true']]);

		$this->assertContains('isNull(t._deleted)', $captured);
	}//end testMetadataFieldIsNullEmitsANullCheck()

	/**
	 * A filter carrying only `isnull` must still be recognised as an OPERATOR
	 * bag. If `isnull` were missing from COMPARISON_OPERATORS the bag would fall
	 * through to the historical bare-list branch and become `IN ('true')`, which
	 * matches nothing and looks like a correct empty result.
	 *
	 * @return void
	 */
	public function testIsNullAloneIsNotMistakenForABareInList(): void {
		$captured = $this->invokeApplyObject(
			['assignee' => ['isnull' => 'true']],
			['assignee' => ['type' => 'string']]
		);

		foreach ($captured as $fragment) {
			$this->assertStringNotContainsString('in(t.assignee', $fragment);
		}
	}//end testIsNullAloneIsNotMistakenForABareInList()
}//end class
