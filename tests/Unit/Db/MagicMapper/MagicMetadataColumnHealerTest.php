<?php

/**
 * A magic table without `_archived` heals on first use (14 October 2026).
 *
 * Measured on the development instance: 38 of 1,712 magic tables lacked
 * `_archived`, `_frozen` and `_status`, and every list of them failed with
 * `column t._archived does not exist`, because the columns arrived only
 * through a repair step that runs on an app upgrade. These tests build such a
 * table in a real SQLite database and drive it through the callers that run
 * on every list: MagicTableHandler::existsTableForRegisterSchema() and
 * MagicSearchHandler::buildWhereConditionsSql().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-magic-table-missing-metadata-columns-heals-itself-on-first-use-req-mth-001
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-table-that-cannot-be-healed-degrades-quietly-req-mth-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\IDBConnection;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Drives a drifted SQLite magic table through the request-path callers.
 */
class MagicMetadataColumnHealerTest extends TestCase {

	/**
	 * The bare table name the tests use.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_table_5_12';

	/**
	 * The columns the live drifted tables lacked.
	 *
	 * @var string[]
	 */
	public const DRIFTED = ['_archived', '_frozen', '_status'];

	private PDO $pdo;

	private MagicMapper&MockObject $magicMapper;

	private IDBConnection&MockObject $db;

	private LoggerInterface&MockObject $logger;

	/**
	 * Backing store of the distributed-cache double.
	 *
	 * @var array<string, mixed>
	 */
	private array $cacheStore = [];

	private ICache&MockObject $cache;

	/**
	 * How many times the table's columns were read from the database.
	 *
	 * @var int
	 */
	private int $columnReads = 0;

	/**
	 * When set, every ALTER throws this message instead of running.
	 *
	 * @var string|null
	 */
	private ?string $alterFailure = null;

	protected function setUp(): void {
		if (in_array('sqlite', PDO::getAvailableDrivers(), true) === false) {
			$this->markTestSkipped('pdo_sqlite is not available.');
		}

		MagicMapper::clearAllStaticCaches();
		MagicMetadataColumnHealer::reset();

		$this->pdo = self::createDriftedTable(omit: self::DRIFTED);
		$this->magicMapper = self::wireMagicMapper(
			testCase: $this,
			pdo: $this->pdo,
			columnReads: $this->columnReads
		);
		$this->magicMapper->method('getCacheKey')->willReturn('5_12');
		$this->magicMapper->method('checkTableExistsInDatabase')->willReturn(true);

		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('inTransaction')->willReturn(false);
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				if ($this->alterFailure !== null) {
					throw new RuntimeException($this->alterFailure);
				}

				return (int)$this->pdo->exec($sql);
			}
		);

		$this->logger = $this->createMock(LoggerInterface::class);

		$this->cacheStore = [];
		$this->cache = $this->createMock(ICache::class);
		$this->cache->method('get')->willReturnCallback(fn (string $key) => ($this->cacheStore[$key] ?? null));
		$this->cache->method('set')->willReturnCallback(
			function (string $key, $value): bool {
				$this->cacheStore[$key] = $value;
				return true;
			}
		);
		$this->cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->cacheStore[$key]);
				return true;
			}
		);
	}//end setUp()

	protected function tearDown(): void {
		MagicMapper::clearAllStaticCaches();
		MagicMetadataColumnHealer::reset();
		parent::tearDown();
	}//end tearDown()

	/**
	 * A SQLite database holding one magic table with every metadata column but the omitted ones, and two rows.
	 *
	 * @param string[] $omit Metadata columns to leave out.
	 *
	 * @return PDO The connection.
	 */
	public static function createDriftedTable(array $omit): PDO {
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		$columns = ['"_id" INTEGER PRIMARY KEY AUTOINCREMENT'];
		foreach (array_keys(self::metadataDefinitions()) as $name) {
			if ($name === '_id' || in_array($name, $omit, true) === true) {
				continue;
			}

			$columns[] = '"'.$name.'" TEXT';
		}

		$pdo->exec('CREATE TABLE "oc_'.self::TABLE.'" ('.implode(', ', $columns).')');
		$pdo->exec('INSERT INTO "oc_'.self::TABLE.'" ("_uuid") VALUES (\'a\'), (\'b\')');

		return $pdo;
	}//end createDriftedTable()

	/**
	 * The real metadata column list, read from MagicMapper without constructing it.
	 *
	 * @return array<string, array> Definitions keyed by column name.
	 */
	public static function metadataDefinitions(): array {
		$bare = (new ReflectionClass(MagicMapper::class))->newInstanceWithoutConstructor();
		return $bare->getMetadataColumnDefinitions();
	}//end metadataDefinitions()

	/**
	 * A MagicMapper double whose column introspection and DDL rendering run against SQLite.
	 *
	 * @param TestCase $testCase    The test creating the double.
	 * @param PDO      $pdo         The SQLite connection.
	 * @param int      $columnReads Counter of column reads, by reference.
	 *
	 * @return MagicMapper&MockObject The double.
	 */
	public static function wireMagicMapper(TestCase $testCase, PDO $pdo, int &$columnReads): MagicMapper&MockObject {
		$mock = (new \PHPUnit\Framework\MockObject\MockBuilder($testCase, MagicMapper::class))
			->disableOriginalConstructor()
			->onlyMethods(
				[
					'getMetadataColumnDefinitions',
					'getExistingTableColumns',
					'buildAddColumnSql',
					'getCacheKey',
					'checkTableExistsInDatabase',
					'listMagicTableNames',
					'inspectMetadataColumns',
					'healMetadataColumns',
				]
			)
			->getMock();

		$mock->method('getMetadataColumnDefinitions')->willReturn(self::metadataDefinitions());
		$mock->method('getExistingTableColumns')->willReturnCallback(
			static function (string $tableName) use ($pdo, &$columnReads): array {
				$columnReads++;
				$columns = [];
				foreach ($pdo->query('PRAGMA table_info("oc_'.$tableName.'")')->fetchAll(PDO::FETCH_ASSOC) as $row) {
					$columns[$row['name']] = ['name' => $row['name'], 'type' => $row['type']];
				}

				return $columns;
			}
		);
		$mock->method('buildAddColumnSql')->willReturnCallback(
			static fn (string $tableName, array $definition): string => 'ALTER TABLE "oc_'.$tableName.'" ADD COLUMN "'.$definition['name'].'" TEXT'
		);

		return $mock;
	}//end wireMagicMapper()

	/**
	 * The healer under test, on the shared doubles.
	 *
	 * @return MagicMetadataColumnHealer The healer.
	 */
	private function healer(): MagicMetadataColumnHealer {
		return new MagicMetadataColumnHealer(
			db: $this->db,
			logger: $this->logger,
			magicMapper: $this->magicMapper,
			cache: $this->cache
		);
	}//end healer()

	/**
	 * The table handler every list asks before it queries a table.
	 *
	 * @param MagicMetadataColumnHealer|null $healer The healer, or null for the handler as it was.
	 *
	 * @return MagicTableHandler The handler.
	 */
	private function tableHandler(?MagicMetadataColumnHealer $healer): MagicTableHandler {
		return new MagicTableHandler(
			db: $this->db,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->logger,
			magicMapper: $this->magicMapper,
			metadataColumns: $healer
		);
	}//end tableHandler()

	/**
	 * Run the default list's lifecycle predicate against the table, as the QueryBuilder path renders it.
	 *
	 * @return string[] The uuids returned.
	 */
	private function listWorkingSet(): array {
		return $this->pdo
			->query('SELECT t._uuid FROM "oc_'.self::TABLE.'" AS t WHERE t._deleted IS NULL AND t._archived IS NULL ORDER BY t._uuid')
			->fetchAll(PDO::FETCH_COLUMN);
	}//end listWorkingSet()

	/**
	 * The register and schema the table belongs to.
	 *
	 * @return array{0: Register, 1: Schema} The pair.
	 */
	private function pair(): array {
		$register = new Register();
		$register->setId(5);
		$schema = new Schema();
		$schema->setId(12);

		return [$register, $schema];
	}//end pair()

	/**
	 * Control: without the healer, the default list of a drifted table fails, as it did live.
	 *
	 * Keeps the test below honest: it passes because the healer ran, not
	 * because SQLite tolerates the missing column.
	 */
	public function testWithoutTheHealerTheDefaultListFails(): void {
		[$register, $schema] = $this->pair();
		$this->assertTrue($this->tableHandler(healer: null)->existsTableForRegisterSchema(register: $register, schema: $schema));

		$this->expectException(\PDOException::class);
		$this->expectExceptionMessage('_archived');
		$this->listWorkingSet();
	}//end testWithoutTheHealerTheDefaultListFails()

	/**
	 * The first use of a drifted table adds the missing columns, and the default list then answers.
	 */
	public function testFirstUseHealsTheTableAndTheDefaultListAnswers(): void {
		[$register, $schema] = $this->pair();

		$this->assertTrue($this->tableHandler(healer: $this->healer())->existsTableForRegisterSchema(register: $register, schema: $schema));

		$columns = array_column($this->pdo->query('PRAGMA table_info("oc_'.self::TABLE.'")')->fetchAll(PDO::FETCH_ASSOC), 'name');
		foreach (self::DRIFTED as $name) {
			$this->assertContains($name, $columns);
		}

		$this->assertSame(['a', 'b'], $this->listWorkingSet());
	}//end testFirstUseHealsTheTableAndTheDefaultListAnswers()

	/**
	 * A complete table is inspected once: the memo answers in-process, the distributed cache across requests.
	 */
	public function testACompleteTableIsNotInspectedAgain(): void {
		[$register, $schema] = $this->pair();
		$handler = $this->tableHandler(healer: $this->healer());

		$handler->existsTableForRegisterSchema(register: $register, schema: $schema);
		$readsAfterFirstUse = $this->columnReads;
		$this->assertGreaterThan(0, $readsAfterFirstUse);

		// Same process: the existence memo and the healer memo answer.
		$handler->existsTableForRegisterSchema(register: $register, schema: $schema);
		$handler->existsTableForRegisterSchema(register: $register, schema: $schema);
		$this->assertSame($readsAfterFirstUse, $this->columnReads);

		// A new request: the process memos are gone, the distributed cache is not.
		MagicMapper::clearAllStaticCaches();
		$this->tableHandler(healer: $this->healer())->existsTableForRegisterSchema(register: $register, schema: $schema);
		$this->assertSame($readsAfterFirstUse, $this->columnReads);

		// The answer is keyed by the metadata column list's version.
		$this->assertNotEmpty(
			array_filter(array_keys($this->cacheStore), fn (string $key): bool => str_contains($key, $this->healer()->version()))
		);
	}//end testACompleteTableIsNotInspectedAgain()

	/**
	 * Another request adds `_archived` between our read and our ALTER: that is not a failure.
	 */
	public function testAConcurrentAddOfTheSameColumnIsTolerated(): void {
		$healer = $this->healer();
		$stale = $healer->inspect(tableName: self::TABLE);
		$this->assertArrayHasKey('_archived', $stale);

		// The other request wins the race.
		$this->pdo->exec('ALTER TABLE "oc_'.self::TABLE.'" ADD COLUMN "_archived" TEXT');

		$this->logger->expects($this->never())->method('warning');

		$result = $healer->heal(tableName: self::TABLE, missing: $stale);

		$this->assertSame([], $result['failed']);
		$this->assertContains('_archived', $result['added']);
		$this->assertSame([], $healer->missingFor(tableName: self::TABLE));
		$this->assertSame(['a', 'b'], $this->listWorkingSet());
	}//end testAConcurrentAddOfTheSameColumnIsTolerated()

	/**
	 * Without ALTER permission: one warning over three requests, and the list leaves `_archived` out.
	 */
	public function testATableThatCannotBeHealedWarnsOnceAndStillLists(): void {
		[$register, $schema] = $this->pair();
		$this->alterFailure = 'permission denied for table oc_'.self::TABLE;

		$this->logger->expects($this->once())->method('warning');

		for ($request = 0; $request < 3; $request++) {
			MagicMapper::clearAllStaticCaches();
			$this->tableHandler(healer: $this->healer())->existsTableForRegisterSchema(register: $register, schema: $schema);
		}

		$healer = $this->healer();
		$this->assertSame(self::DRIFTED, $healer->missingFor(tableName: self::TABLE));

		$conditions = $this->searchHandler(healer: $healer)->buildWhereConditionsSql(
			query: ['_rbac' => false, '_multitenancy' => false],
			schema: new Schema(),
			registerId: 5,
			tableName: self::TABLE
		);

		$sql = implode(' ', $conditions);
		$this->assertStringNotContainsString('_archived', $sql);
		$this->assertStringNotContainsString('_status', $sql);

		$rows = $this->pdo
			->query('SELECT _uuid FROM "oc_'.self::TABLE.'" WHERE '.implode(' AND ', $conditions).' ORDER BY _uuid')
			->fetchAll(PDO::FETCH_COLUMN);
		$this->assertSame(['a', 'b'], $rows);

		// The archived-only lens of a table without the column is empty, not every row.
		$archivedOnly = $this->searchHandler(healer: $healer)->buildWhereConditionsSql(
			query: ['_rbac' => false, '_multitenancy' => false, '_archived' => 'true'],
			schema: new Schema(),
			registerId: 5,
			tableName: self::TABLE
		);
		$this->assertContains('1=0', $archivedOnly);
	}//end testATableThatCannotBeHealedWarnsOnceAndStillLists()

	/**
	 * A complete table keeps every lifecycle predicate.
	 */
	public function testACompleteTableKeepsTheArchivePredicate(): void {
		$healer = $this->healer();
		$healer->ensure(tableName: self::TABLE);

		$conditions = $this->searchHandler(healer: $healer)->buildWhereConditionsSql(
			query: ['_rbac' => false, '_multitenancy' => false],
			schema: new Schema(),
			registerId: 5,
			tableName: self::TABLE
		);

		$this->assertContains('_archived IS NULL', $conditions);
	}//end testACompleteTableKeepsTheArchivePredicate()

	/**
	 * The search handler on a connection that quotes like SQLite.
	 *
	 * @param MagicMetadataColumnHealer $healer The healer whose memo it consults.
	 *
	 * @return MagicSearchHandler The handler.
	 */
	private function searchHandler(MagicMetadataColumnHealer $healer): MagicSearchHandler {
		$connection = new class($this->pdo) {
			/**
			 * Constructor.
			 *
			 * @param PDO $pdo The SQLite connection.
			 */
			public function __construct(private PDO $pdo) {
			}

			/**
			 * Quote a value.
			 *
			 * @param mixed $value The value.
			 *
			 * @return string The quoted value.
			 */
			public function quote(mixed $value): string {
				return (string)$this->pdo->quote((string)$value);
			}
		};

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('getConnection')->willReturn($connection);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('getDatabasePlatform')->willReturn(new \Doctrine\DBAL\Platforms\SqlitePlatform());

		$rbac = $this->createMock(MagicRbacHandler::class);
		$rbac->method('getCurrentUserId')->willReturn(null);

		$logger = $this->createMock(LoggerInterface::class);

		return new MagicSearchHandler(
			db: $db,
			logger: $logger,
			rbacHandler: $rbac,
			organizationHandler: $this->createMock(MagicOrganizationHandler::class),
			schemaTypeConverter: new SchemaTypeConverter(),
			dateTimeNormalizer: new DateTimeNormalizer($logger),
			relatedRows: $this->createMock(RelatedRowQueryApplier::class),
			languageService: null,
			metadataColumns: $healer
		);
	}//end searchHandler()
}//end class
