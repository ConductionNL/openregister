<?php

/**
 * The `orNull` modifier: a filter that also admits rows whose field is empty.
 *
 * SQL comparisons are unknown for NULL, so `isFinalStatus[ne]=true` leaves out
 * every case whose flag was never written. `orNull` asks for those rows back,
 * on every builder the list search has: the QueryBuilder path, the raw UNION
 * path, `@self` metadata on both, and nested paths inside object properties.
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

/**
 * Locks `orNull` on every list-search builder, and that `ne` keeps its meaning.
 */
class MagicSearchHandlerOrNullModifierTest extends TestCase {

	/**
	 * A case schema with a flag that recompute jobs may not have written yet.
	 *
	 * @var array<string,mixed>
	 */
	private const PROPERTIES = [
		'isFinalStatus' => ['type' => 'boolean'],
		'title'         => ['type' => 'string'],
		'dwell'         => [
			'type'       => 'object',
			'properties' => ['breached' => ['type' => 'boolean']],
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
		$method = new ReflectionMethod(MagicSearchHandler::class, 'applyObjectFilters');
		$method->invoke($this->handler, $this->makeQueryBuilder(), $filters, $this->makeSchema());
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
		return $method->invoke($this->handler, $query, $this->makeSchema(), $connection ?? $this->makeConnection(), $isPostgres);
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
		return $method->invoke($this->handler, ['@self' => $filters], $this->makeConnection(), true);
	}//end unionMetadata()

	public function testQueryBuilderPathAdmitsNullBesideNe(): void {
		$captured = $this->qbObject(['isFinalStatus' => ['ne' => true, 'orNull' => true]]);

		$this->assertSame(['((neq(t.is_final_status,1)) OR t.is_final_status IS NULL)'], $captured);
	}//end testQueryBuilderPathAdmitsNullBesideNe()

	public function testQueryBuilderPathKeepsTheBagsOperatorsAndedInsideTheGroup(): void {
		$captured = $this->qbObject(['title' => ['like' => 'aan', 'ne' => 'x', 'orNull' => 'true']]);

		$this->assertCount(1, $captured);
		$this->assertStringStartsWith('((', $captured[0]);
		$this->assertStringContainsString(') AND (neq(t.title,x))', $captured[0]);
		$this->assertStringEndsWith(') OR t.title IS NULL)', $captured[0]);
	}//end testQueryBuilderPathKeepsTheBagsOperatorsAndedInsideTheGroup()

	public function testNeWithoutOrNullKeepsItsMeaning(): void {
		$this->assertSame(['neq(t.is_final_status,1)'], $this->qbObject(['isFinalStatus' => ['ne' => true]]));
	}//end testNeWithoutOrNullKeepsItsMeaning()

	public function testOrNullFalseFromAQueryStringIsNoModifier(): void {
		$this->assertSame(
			['neq(t.is_final_status,1)'],
			$this->qbObject(['isFinalStatus' => ['ne' => 'true', 'orNull' => 'false']])
		);
	}//end testOrNullFalseFromAQueryStringIsNoModifier()

	public function testOrNullAloneAddsNoCondition(): void {
		$this->assertSame([], $this->qbObject(['isFinalStatus' => ['orNull' => 'true']]));
		$this->assertSame([], $this->unionObject(['isFinalStatus' => ['orNull' => 'true']]));
	}//end testOrNullAloneAddsNoCondition()

	public function testQueryBuilderPathAdmitsNullBesideIn(): void {
		$this->assertSame(
			['((in(t.is_final_status,0)) OR t.is_final_status IS NULL)'],
			$this->qbObject(['isFinalStatus' => ['in' => [false], 'orNull' => true]])
		);
	}//end testQueryBuilderPathAdmitsNullBesideIn()

	public function testQueryBuilderMetadataPathAdmitsNull(): void {
		$this->assertSame(
			['((lt(t._expires,2026-10-01)) OR t._expires IS NULL)'],
			$this->qbMetadata(['expires' => ['lt' => '2026-10-01', 'orNull' => 'true']])
		);
	}//end testQueryBuilderMetadataPathAdmitsNull()

	public function testUnionPathAdmitsNullBesideNe(): void {
		$this->assertSame(
			['(("is_final_status" <> \'1\') OR "is_final_status" IS NULL)'],
			$this->unionObject(['isFinalStatus' => ['ne' => true, 'orNull' => true]])
		);
	}//end testUnionPathAdmitsNullBesideNe()

	public function testUnionPathNeWithoutOrNullKeepsItsMeaning(): void {
		$this->assertSame(
			['"is_final_status" <> \'1\''],
			$this->unionObject(['isFinalStatus' => ['ne' => true]])
		);
	}//end testUnionPathNeWithoutOrNullKeepsItsMeaning()

	public function testUnionPathKeepsLikeBesideAnOperatorBag(): void {
		$conditions = $this->unionObject(['title' => ['like' => 'aan', 'ne' => 'x']]);

		$this->assertCount(2, $conditions);
		$this->assertStringContainsString('LIKE', $conditions[0]);
		$this->assertSame('"title" <> \'x\'', $conditions[1]);
	}//end testUnionPathKeepsLikeBesideAnOperatorBag()

	public function testUnionMetadataPathAdmitsNull(): void {
		$this->assertSame(
			['(("_expires" < \'2026-10-01\') OR "_expires" IS NULL)'],
			$this->unionMetadata(['expires' => ['lt' => '2026-10-01', 'orNull' => true]])
		);
	}//end testUnionMetadataPathAdmitsNull()

	public function testNestedPathAdmitsNullOnBothPaths(): void {
		$qb = $this->qbObject(['dwell.breached' => ['ne' => 'true', 'orNull' => 'true']]);
		$this->assertCount(1, $qb);
		$this->assertStringEndsWith("OR (t.dwell #>> '{breached}') IS NULL)", $qb[0]);

		$union = $this->unionObject(['dwell' => ['breached' => ['ne' => 'true', 'orNull' => 'true']]]);
		$this->assertSame(
			["((" . "(\"dwell\" #>> '{breached}') <> 'true') OR (\"dwell\" #>> '{breached}') IS NULL)"],
			$union
		);
	}//end testNestedPathAdmitsNullOnBothPaths()

	/**
	 * The condition run against a real database: the row whose flag was never
	 * written is the one `ne` alone loses and `orNull` brings back.
	 *
	 * @return void
	 */
	public function testOnARealDatabaseOrNullBringsBackTheUnwrittenRow(): void {
		if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
			$this->markTestSkipped('pdo_sqlite is not available');
		}

		$pdo = new PDO('sqlite::memory:');
		$pdo->exec('CREATE TABLE cases (id INTEGER, is_final_status BOOLEAN)');
		$pdo->exec('INSERT INTO cases VALUES (1, 1), (2, 0), (3, NULL)');

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($v) => $pdo->quote((string)$v));

		$select = function (array $filter) use ($pdo, $connection): array {
			$where = implode(' AND ', $this->unionObject(['isFinalStatus' => $filter], $connection, false));
			return array_map('intval', $pdo->query("SELECT id FROM cases WHERE {$where} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
		};

		$this->assertSame([2], $select(['ne' => true]), '`ne` alone drops the unwritten row');
		$this->assertSame([2, 3], $select(['ne' => true, 'orNull' => true]));
		$this->assertSame([2, 3], $select(['in' => [false], 'orNull' => 'true']));
	}//end testOnARealDatabaseOrNullBringsBackTheUnwrittenRow()
}//end class
