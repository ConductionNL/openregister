<?php

/**
 * The `inOrEmpty` list operator: one of the values, or no value, on every filter path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/search-value-or-empty-filter/specs/zoeken-filteren/spec.md#requirement-a-list-filter-can-match-a-value-or-no-value-in-one-query
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\PostgreSQL120Platform;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use OCA\OpenRegister\Db\MagicMapper\ConditionSqlRun;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\TableFilterRun;

/**
 * Locks `orNull` on every list-search builder, and that `ne` keeps its meaning.
 */
/**
 * A value or no value in one condition, on the QueryBuilder, union, metadata and nested paths,
 * with existing operators unchanged and a real database proving the rows.
 */
class MagicSearchHandlerInOrEmptyTest extends TestCase {

	/**
	 * A case schema with a flag that recompute jobs may not have written yet.
	 *
	 * @var array<string,mixed>
	 */
	private const PROPERTIES = [
		'setting'  => ['type' => 'string'],
		'settings' => ['type' => 'array', 'items' => ['type' => 'string']],
		'status'   => ['type' => 'string'],
		'world'    => [
			'type'       => 'object',
			'properties' => ['name' => ['type' => 'string']],
		],
	];

	/**
	 * WHERE fragments captured from the QueryBuilder path.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	/**
	 * The handler under test, on a PostgreSQL connection double.
	 *
	 * @var MagicSearchHandler
	 */
	private MagicSearchHandler $handler;

	protected function setUp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQL120Platform::class));
		$related = $this->createMock(RelatedRowQueryApplier::class);
		$related->method('mayReadProperty')->willReturn(true);

		$this->handler = new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $this->createMock(MagicRbacHandler::class),
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $related
		);
		$this->captured = [];
	}//end setUp()

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
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value) {
				if (is_array($value) === true) {
					return $value;
				}

				return (string)$value;
			}
		);
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
	 * A connection double whose quote() wraps values in single quotes.
	 *
	 * @return IDBConnection The connection double.
	 */
	private function makeConnection(): IDBConnection {
		$conn = $this->createMock(IDBConnection::class);
		$conn->method('quote')->willReturnCallback(static fn ($v) => "'{$v}'");
		return $conn;
	}//end makeConnection()

	/**
	 * A schema double carrying PROPERTIES.
	 *
	 * @return Schema The schema double.
	 */
	private function makeSchema(): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(self::PROPERTIES);
		return $schema;
	}//end makeSchema()

	/**
	 * Run applyObjectFilters() and return the WHERE fragments it produced.
	 *
	 * @param array<string,mixed> $filters Object-field filters.
	 *
	 * @return string[] Captured WHERE fragments.
	 */
	private function qbObject(array $filters): array {
		$this->captured = [];
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters');
		$method->invoke($this->handler, new TableFilterRun(qb: $this->makeQueryBuilder(), query: [], table: new MagicReadTable(schema: $this->makeSchema())), $filters);
		return $this->captured;
	}//end qbObject()

	/**
	 * Run applyMetadataFilters() and return the WHERE fragments it produced.
	 *
	 * @param array<string,mixed> $filters The `@self` bag.
	 *
	 * @return string[] Captured WHERE fragments.
	 */
	private function qbMetadata(array $filters): array {
		$this->captured = [];
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyMetadataFilters');
		$method->invoke($this->handler, $this->makeQueryBuilder(), $filters);
		return $this->captured;
	}//end qbMetadata()

	/**
	 * Run the raw UNION object builder and return its conditions.
	 *
	 * @param array<string,mixed> $query      Search query.
	 * @param IDBConnection|null  $connection Connection that quotes values.
	 * @param bool                $isPostgres Whether to write PostgreSQL SQL.
	 *
	 * @return string[] Generated SQL conditions.
	 */
	private function unionObject(array $query, ?IDBConnection $connection = null, bool $isPostgres = true): array {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildObjectFilterConditionsSql');
		return $method->invoke($this->handler, new ConditionSqlRun(query: $query, table: new MagicReadTable(schema: $this->makeSchema()), connection: $connection ?? $this->makeConnection(), isPostgres: $isPostgres));
	}//end unionObject()

	/**
	 * Run the raw UNION metadata builder and return its conditions.
	 *
	 * @param array<string,mixed> $filters The `@self` bag.
	 *
	 * @return string[] Generated SQL conditions.
	 */
	private function unionMetadata(array $filters): array {
		$method = new ReflectionMethod(MagicSearchHandler::class, 'buildMetadataFilterConditionsSql');
		return $method->invoke($this->handler, new ConditionSqlRun(query: ['@self' => $filters], table: new MagicReadTable(schema: new Schema()), connection: $this->makeConnection(), isPostgres: true));
	}//end unionMetadata()

	public function testQueryBuilderPathMatchesAValueOrNoValue(): void {
		$this->assertSame(
			["((in(t.setting,aldoria)) OR t.setting IS NULL OR CAST(t.setting AS TEXT) IN ('', '[]'))"],
			$this->qbObject(['setting' => ['inOrEmpty' => ['aldoria']]])
		);
	}

	public function testUnionPathMatchesAValueOrNoValue(): void {
		$this->assertSame(
			["((\"setting\" IN ('aldoria')) OR \"setting\" IS NULL OR CAST(\"setting\" AS TEXT) IN ('', '[]'))"],
			$this->unionObject(['setting' => ['inOrEmpty' => ['aldoria']]])
		);
	}

	public function testOtherOperatorsOfTheBagStillAnd(): void {
		$conditions = $this->qbObject(['setting' => ['inOrEmpty' => ['aldoria'], 'ne' => 'norheim']]);

		$this->assertCount(2, $conditions);
		$this->assertSame('neq(t.setting,norheim)', $conditions[0]);
		$this->assertStringStartsWith('((in(t.setting,aldoria)) OR t.setting IS NULL', $conditions[1]);
	}

	public function testAnEmptyListMatchesOnlyTheEmptyRows(): void {
		$this->assertSame(
			["(t.setting IS NULL OR CAST(t.setting AS TEXT) IN ('', '[]'))"],
			$this->qbObject(['setting' => ['inOrEmpty' => []]])
		);
		$this->assertSame(
			["(t.setting IS NULL OR CAST(t.setting AS TEXT) IN ('', '[]'))"],
			$this->qbObject(['setting' => ['inOrEmpty' => ['', null]]]),
			'blank entries in the list are not values'
		);
	}

	public function testAnArrayColumnUsesItsOwnAnyOfMatchInsideTheGroup(): void {
		$conditions = $this->qbObject(['settings' => ['inOrEmpty' => ['aldoria']]]);

		$this->assertCount(1, $conditions);
		$this->assertStringContainsString('aldoria', $conditions[0]);
		$this->assertStringEndsWith("OR t.settings IS NULL OR CAST(t.settings AS TEXT) IN ('', '[]'))", $conditions[0]);
	}

	public function testMetadataColumnsAcceptTheOperator(): void {
		$this->assertSame(
			["((in(t._organisation,org-1)) OR t._organisation IS NULL OR CAST(t._organisation AS TEXT) IN ('', '[]'))"],
			$this->qbMetadata(['organisation' => ['inOrEmpty' => ['org-1']]])
		);
		$union = $this->unionMetadata(['organisation' => ['inOrEmpty' => ['org-1']]]);
		$this->assertCount(1, $union);
		$this->assertStringEndsWith("OR \"_organisation\" IS NULL OR CAST(\"_organisation\" AS TEXT) IN ('', '[]'))", $union[0]);
	}

	public function testANestedPathAcceptsTheOperator(): void {
		$qb = $this->qbObject(['world.name' => ['inOrEmpty' => ['Aldoria']]]);

		$this->assertCount(1, $qb);
		$this->assertStringEndsWith("OR (t.world #>> '{name}') IS NULL OR CAST((t.world #>> '{name}') AS TEXT) IN ('', '[]'))", $qb[0]);
	}

	public function testExistingOperatorsAreUnchanged(): void {
		$this->assertSame(['in(t.setting,aldoria)'], $this->qbObject(['setting' => ['in' => ['aldoria']]]));
		$this->assertSame(["\"setting\" IN ('aldoria')"], $this->unionObject(['setting' => ['in' => ['aldoria']]]));
		$this->assertSame(['((in(t.setting,aldoria)) OR t.setting IS NULL)'], $this->qbObject(['setting' => ['in' => ['aldoria'], 'orNull' => true]]));
	}

	public function testOnARealDatabaseTheWorldAndTheSharedRowsComeBack(): void {
		if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
			$this->markTestSkipped('pdo_sqlite is not available');
		}

		$pdo = new PDO('sqlite::memory:');
		$pdo->exec('CREATE TABLE characters (id INTEGER, setting TEXT)');
		$pdo->exec("INSERT INTO characters VALUES (1, 'aldoria'), (2, 'norheim'), (3, NULL), (4, ''), (5, '[]'), (6, 'aldoria')");

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($v) => $pdo->quote((string)$v));

		$select = function (array $filter) use ($pdo, $connection): array {
			$where = implode(' AND ', $this->unionObject(['setting' => $filter], $connection, false));
			return array_map('intval', $pdo->query("SELECT id FROM characters WHERE {$where} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
		};
		$count = function (array $filter) use ($pdo, $connection): int {
			$where = implode(' AND ', $this->unionObject(['setting' => $filter], $connection, false));
			return (int)$pdo->query("SELECT COUNT(*) FROM characters WHERE {$where}")->fetchColumn();
		};

		$this->assertSame([1, 3, 4, 5, 6], $select(['inOrEmpty' => ['aldoria']]), 'the world and every row with no world, none of Norheim');
		$this->assertSame(5, $count(['inOrEmpty' => ['aldoria']]), 'the total is computed on the same condition');
		$this->assertSame([3, 4, 5], $select(['inOrEmpty' => []]));
		$this->assertSame([1, 6], $select(['in' => ['aldoria']]), '`in` keeps its meaning');
	}
}//end class
