<?php

/**
 * A `@self` filter can name a field inside the archival metadata.
 *
 * `@self[retention.archiefactiedatum][lt]=2026-11-24` asks for every object
 * whose archive action date has passed. Before, the dotted key was refused as
 * an unknown metadata field, and the nested request spelling was read as an
 * IN list on the whole column, so a list or a dashboard counter could not ask it.
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
use OCA\OpenRegister\Db\MagicMapper\ConditionSqlRun;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicReadTable;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\UnknownMetadataFieldException;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Locks path filters into `retention` and `tmlo` on both query paths and both platforms.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 */
class MagicSearchHandlerMetadataJsonPathTest extends TestCase {

	/**
	 * The connection the handler asks for its platform.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * The handler under test.
	 *
	 * @var MagicSearchHandler
	 */
	private MagicSearchHandler $handler;

	/**
	 * WHERE fragments captured from the QueryBuilder path.
	 *
	 * @var string[]
	 */
	private array $captured = [];

	/**
	 * Build the handler over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(originalClassName: IDBConnection::class);
		$logger   = $this->createMock(originalClassName: LoggerInterface::class);

		$this->handler = new MagicSearchHandler(
			db: $this->db,
			logger: $logger,
			rbacHandler: $this->createMock(originalClassName: MagicRbacHandler::class),
			organizationHandler: $this->createMock(originalClassName: MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer(logger: $logger),
			relatedRows: $this->createMock(originalClassName: RelatedRowQueryApplier::class)
		);

		$this->captured = [];
	}//end setUp()

	/**
	 * Make the connection report a platform.
	 *
	 * @param string $platform The platform class.
	 *
	 * @return void
	 */
	private function on(string $platform): void {
		$this->db->method('getDatabasePlatform')->willReturn($this->createMock(originalClassName: $platform));
	}//end on()

	/**
	 * Run applyMetadataFilters() against a QueryBuilder double and return its WHERE fragments.
	 *
	 * @param array<string,mixed> $filters The `@self` bag.
	 *
	 * @return string[] Captured WHERE fragments.
	 */
	private function invokeApply(array $filters): array {
		$expr = $this->createMock(originalClassName: IExpressionBuilder::class);
		foreach (['eq', 'lte', 'gte'] as $operator) {
			$expr->method($operator)->willReturnCallback(
				static fn (string $column, $value): string => "{$operator}({$column},{$value})"
			);
		}

		$expr->method('isNotNull')->willReturnCallback(static fn (string $column): string => "isNotNull({$column})");

		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value) => $value);
		$qb->method('andWhere')->willReturnCallback(
			function (...$predicates) use ($qb) {
				foreach ($predicates as $predicate) {
					$this->captured[] = (string) $predicate;
				}

				return $qb;
			}
		);

		$method = new ReflectionMethod(objectOrMethod: MagicSearchHandler::class, method: 'applyMetadataFilters');
		$method->invoke($this->handler, $qb, $filters);

		return $this->captured;
	}//end invokeApply()

	/**
	 * Run the raw-SQL UNION metadata builder and return its conditions.
	 *
	 * @param array<string,mixed> $query Full query array (carries `@self`).
	 *
	 * @return string[] Generated SQL conditions.
	 */
	private function invokeUnion(array $query): array {
		$connection = $this->createMock(originalClassName: IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($value) => "'{$value}'");
		$table  = new MagicReadTable(schema: new Schema());
		$run    = new ConditionSqlRun(query: $query, table: $table, connection: $connection, isPostgres: true);
		$method = new ReflectionMethod(objectOrMethod: MagicSearchHandler::class, method: 'buildMetadataFilterConditionsSql');

		return $method->invoke($this->handler, $run);
	}//end invokeUnion()

	/**
	 * On PostgreSQL an `lt` on the archive action date compares the text at the path.
	 *
	 * @return void
	 */
	public function testAnLtOnTheArchiveActionDateComparesTheTextAtThePathOnPostgres(): void {
		$this->on(platform: PostgreSQLPlatform::class);
		$captured = $this->invokeApply(filters: ['retention.archiefactiedatum' => ['lt' => '2026-11-24']]);

		$this->assertSame(expected: ["(t._retention #>> '{archiefactiedatum}') < 2026-11-24"], actual: $captured);
	}//end testAnLtOnTheArchiveActionDateComparesTheTextAtThePathOnPostgres()

	/**
	 * The nested spelling a request arrives as (`@self_retention_archiefactiedatum[lt]`) reads the same path.
	 *
	 * @return void
	 */
	public function testTheNestedRequestSpellingReadsTheSamePath(): void {
		$this->on(platform: PostgreSQLPlatform::class);
		$captured = $this->invokeApply(filters: ['retention' => ['archiefactiedatum' => ['lt' => '2026-11-24']]]);

		$this->assertSame(expected: ["(t._retention #>> '{archiefactiedatum}') < 2026-11-24"], actual: $captured);
	}//end testTheNestedRequestSpellingReadsTheSamePath()

	/**
	 * On MySQL and MariaDB the same filter reads the path with JSON_EXTRACT.
	 *
	 * @return void
	 */
	public function testTheSameFilterReadsThePathWithJsonExtractElsewhere(): void {
		$this->on(platform: MySQLPlatform::class);
		$captured = $this->invokeApply(filters: ['retention.archiefnominatie' => 'vernietigen']);

		$this->assertSame(expected: ["JSON_UNQUOTE(JSON_EXTRACT(t._retention, '$.archiefnominatie')) = vernietigen"], actual: $captured);
	}//end testTheSameFilterReadsThePathWithJsonExtractElsewhere()

	/**
	 * A missing value is asked with isnull, so "retention not resolved" is expressible.
	 *
	 * @return void
	 */
	public function testIsNullAsksForAnUnresolvedValue(): void {
		$this->on(platform: PostgreSQLPlatform::class);
		$captured = $this->invokeApply(filters: ['retention.archiefnominatie' => ['isnull' => 'true']]);

		$this->assertSame(expected: ["(t._retention #>> '{archiefnominatie}') IS NULL"], actual: $captured);
	}//end testIsNullAsksForAnUnresolvedValue()

	/**
	 * A path into tmlo reads the tmlo column.
	 *
	 * @return void
	 */
	public function testAPathIntoTmloReadsItsColumn(): void {
		$this->on(platform: PostgreSQLPlatform::class);
		$captured = $this->invokeApply(filters: ['tmlo.archiefstatus' => 'overgebracht']);

		$this->assertSame(expected: ["(t._tmlo #>> '{archiefstatus}') = overgebracht"], actual: $captured);
	}//end testAPathIntoTmloReadsItsColumn()

	/**
	 * The whole column keeps its meaning: `retention` IS NOT NULL is still a column test.
	 *
	 * @return void
	 */
	public function testTheWholeColumnIsStillAddressable(): void {
		$this->on(platform: MySQLPlatform::class);
		$captured = $this->invokeApply(filters: ['retention' => 'IS NOT NULL']);

		$this->assertSame(expected: ['isNotNull(t._retention)'], actual: $captured);
	}//end testTheWholeColumnIsStillAddressable()

	/**
	 * A path segment that is not a plain name matches nothing instead of reaching SQL.
	 *
	 * @return void
	 */
	public function testASegmentThatIsNotAPlainNameMatchesNothing(): void {
		$this->on(platform: MySQLPlatform::class);
		$captured = $this->invokeApply(filters: ["retention.a'b" => '1']);

		$this->assertSame(expected: ['1 = 0'], actual: $captured);
	}//end testASegmentThatIsNotAPlainNameMatchesNothing()

	/**
	 * Plain metadata filters next to a path filter keep their meaning.
	 *
	 * @return void
	 */
	public function testPlainMetadataFiltersAreUnchanged(): void {
		$this->on(platform: PostgreSQLPlatform::class);
		$captured = $this->invokeApply(
			filters: [
				'created'                     => ['lte' => '2026-01-01'],
				'retention.archiefactiedatum' => ['gte' => '2026-01-01'],
			]
		);

		$this->assertSame(
			expected: [
				"(t._retention #>> '{archiefactiedatum}') >= 2026-01-01",
				'lte(t._created,2026-01-01)',
			],
			actual: $captured
		);
	}//end testPlainMetadataFiltersAreUnchanged()

	/**
	 * A dotted key outside the archival blocks is still named back as unknown.
	 *
	 * @return void
	 */
	public function testAPathIntoAnAccessColumnIsStillRefused(): void {
		$this->on(platform: MySQLPlatform::class);
		$this->expectException(exception: UnknownMetadataFieldException::class);

		$this->invokeApply(filters: ['authorization.read' => 'x']);
	}//end testAPathIntoAnAccessColumnIsStillRefused()

	/**
	 * The raw UNION path writes the same condition with a quoted column and literal.
	 *
	 * @return void
	 */
	public function testTheUnionPathWritesTheSameCondition(): void {
		$this->on(platform: PostgreSQLPlatform::class);
		$conditions = $this->invokeUnion(query: ['@self' => ['retention.archiefactiedatum' => ['lt' => '2026-11-24']]]);

		$this->assertSame(expected: ['("_retention" #>> \'{archiefactiedatum}\') < \'2026-11-24\''], actual: $conditions);
	}//end testTheUnionPathWritesTheSameCondition()
}//end class
