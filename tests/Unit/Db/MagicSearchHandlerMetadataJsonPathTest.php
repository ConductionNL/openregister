<?php

/**
 * A `@self` filter can name a field inside the archival metadata.
 *
 * `@self[retention.archiefactiedatum][lt]=2026-11-24` asks for every object
 * whose archive action date has passed. Before, the dotted key was refused as
 * an unknown metadata field, so a list or a dashboard counter could not ask it.
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
 *
 * @spec openspec/changes/self-filter-inside-retention/specs/zoeken-filteren/spec.md#requirement-a-self-filter-can-name-a-field-inside-the-archival-metadata
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
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
use OCA\OpenRegister\Db\MagicMapper\ConditionSqlRun;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\Schema;

/**
 * Locks path filters into `retention` and `tmlo` on both query paths.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 */
class MagicSearchHandlerMetadataJsonPathTest extends TestCase {


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
			dateTimeNormalizer: new DateTimeNormalizer($this->logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class)
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
		return $method->invoke($this->handler, new ConditionSqlRun(query: $query, table: new MagicReadTable(schema: new Schema()), connection: $this->makeConnection(), isPostgres: true));
	}//end invokeUnion()

	/**
	 * Make the connection report PostgreSQL.
	 *
	 * @return void
	 */
	private function onPostgres(): void {
		$this->db->method('getDatabasePlatform')->willReturn($this->createMock(PostgreSQLPlatform::class));
	}//end onPostgres()

	/**
	 * Make the connection report MySQL.
	 *
	 * @return void
	 */
	private function onMysql(): void {
		$this->db->method('getDatabasePlatform')->willReturn($this->createMock(MySQLPlatform::class));
	}//end onMysql()

	/**
	 * The nested spelling a request arrives as (`@self_retention_archiefactiedatum[lt]`) reads the same path.
	 *
	 * @return void
	 */
	public function testTheNestedRequestSpellingReadsTheSamePath(): void {
		$this->onPostgres();
		$captured = $this->invokeApply(['retention' => ['archiefactiedatum' => ['lt' => '2026-11-24']]]);

		$this->assertSame(["(t._retention #>> '{archiefactiedatum}') < 2026-11-24"], $captured);
	}//end testTheNestedRequestSpellingReadsTheSamePath()

	/**
	 * The whole column keeps its meaning: `retention` IS NOT NULL is still a column test.
	 *
	 * @return void
	 */
	public function testTheWholeColumnIsStillAddressable(): void {
		$this->onMysql();
		$captured = $this->invokeApply(['retention' => 'IS NOT NULL']);

		$this->assertSame(['isNotNull(t._retention)'], $captured);
	}//end testTheWholeColumnIsStillAddressable()

	/**
	 * A path into tmlo reads the tmlo column.
	 *
	 * @return void
	 */
	public function testAPathIntoTmloReadsItsColumn(): void {
		$this->onPostgres();
		$captured = $this->invokeApply(['tmlo.archiefstatus' => 'overgebracht']);

		$this->assertSame(["(t._tmlo #>> '{archiefstatus}') = overgebracht"], $captured);
	}//end testAPathIntoTmloReadsItsColumn()

	/**
	 * On PostgreSQL an `lt` on the archive action date compares the text at the path.
	 *
	 * @return void
	 */
	public function testAnLtOnTheArchiveActionDateComparesTheTextAtThePathOnPostgres(): void {
		$this->onPostgres();
		$captured = $this->invokeApply(['retention.archiefactiedatum' => ['lt' => '2026-11-24']]);

		$this->assertSame(["(t._retention #>> '{archiefactiedatum}') < 2026-11-24"], $captured);
	}//end testAnLtOnTheArchiveActionDateComparesTheTextAtThePathOnPostgres()

	/**
	 * On MySQL and MariaDB the same filter reads the path with JSON_EXTRACT.
	 *
	 * @return void
	 */
	public function testTheSameFilterReadsThePathWithJsonExtractElsewhere(): void {
		$this->onMysql();
		$captured = $this->invokeApply(['retention.archiefnominatie' => 'vernietigen']);

		$this->assertSame(["JSON_UNQUOTE(JSON_EXTRACT(t._retention, '$.archiefnominatie')) = vernietigen"], $captured);
	}//end testTheSameFilterReadsThePathWithJsonExtractElsewhere()

	/**
	 * A missing value is asked with isnull, so "retention not resolved" is expressible.
	 *
	 * @return void
	 */
	public function testIsNullAsksForAnUnresolvedValue(): void {
		$this->onPostgres();
		$captured = $this->invokeApply(['retention.archiefnominatie' => ['isnull' => true]]);

		$this->assertSame(["(t._retention #>> '{archiefnominatie}') IS NULL"], $captured);
	}//end testIsNullAsksForAnUnresolvedValue()

	/**
	 * A path segment that is not a plain name matches nothing instead of reaching SQL.
	 *
	 * @return void
	 */
	public function testASegmentThatIsNotAPlainNameMatchesNothing(): void {
		$this->onMysql();
		$captured = $this->invokeApply(["retention.a'b" => '1']);

		$this->assertSame(['1 = 0'], $captured);
	}//end testASegmentThatIsNotAPlainNameMatchesNothing()

	/**
	 * Plain metadata filters next to a path filter keep their meaning.
	 *
	 * @return void
	 */
	public function testPlainMetadataFiltersAreUnchanged(): void {
		$this->onPostgres();
		$captured = $this->invokeApply(
			[
				'created'                     => ['lte' => '2026-01-01'],
				'retention.archiefactiedatum' => ['gte' => '2026-01-01'],
			]
		);

		$this->assertSame(
			[
				"(t._retention #>> '{archiefactiedatum}') >= 2026-01-01",
				'lte(t._created,2026-01-01)',
			],
			$captured
		);
	}//end testPlainMetadataFiltersAreUnchanged()

	/**
	 * A dotted key outside the archival blocks is still named back as unknown.
	 *
	 * @return void
	 */
	public function testAPathIntoAnAccessColumnIsStillRefused(): void {
		$this->onMysql();
		$this->expectException(UnknownMetadataFieldException::class);

		$this->invokeApply(['authorization.read' => 'x']);
	}//end testAPathIntoAnAccessColumnIsStillRefused()

	/**
	 * The raw UNION path writes the same condition with a quoted column and literal.
	 *
	 * @return void
	 */
	public function testTheUnionPathWritesTheSameCondition(): void {
		$this->onPostgres();
		$conditions = $this->invokeUnion(['@self' => ['retention.archiefactiedatum' => ['lt' => '2026-11-24']]]);

		$this->assertSame(['("_retention" #>> \'{archiefactiedatum}\') < \'2026-11-24\''], $conditions);
	}//end testTheUnionPathWritesTheSameCondition()
}//end class
