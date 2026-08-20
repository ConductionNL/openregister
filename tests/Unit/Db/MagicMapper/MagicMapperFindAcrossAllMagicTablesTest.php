<?php

/**
 * Regression tests for MagicMapper::findAcrossAllMagicTables().
 *
 * The method used to issue ONE `SELECT *` query PER magic table. On a live
 * instance with ~1,000 live magic tables a single bare-identifier lookup
 * (e.g. an app resolving a file by UUID with no register/schema context) cost
 * ~1,010 SQL queries and saturated the database.
 *
 * It is now a two-phase scan:
 *   1. LOCATE — one narrow `UNION ALL` per chunk of tables, selecting ONLY the
 *      table name and `_id`, capped with `LIMIT 1`.
 *   2. FETCH  — a single `SELECT *` from the one table that matched.
 *
 * These tests pin both the behaviour (which must be identical to the old
 * per-table scan) and the query shape (which is the whole point of the fix):
 * the union must never select full rows, must stay chunked, must keep the
 * orphan-table filter, must bind `_id` as an integer so a UUID never reaches
 * the bigint column, and must never interpolate the identifier into SQL.
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class MagicMapperFindAcrossAllMagicTablesTest extends TestCase {

	private IDBConnection&MockObject $db;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Magic tables that exist in information_schema (prefixed names).
	 *
	 * @var string[]
	 */
	private array $existingTables = [];

	/**
	 * Register ids that still have a live row.
	 *
	 * @var int[]
	 */
	private array $liveRegisterIds = [];

	/**
	 * Schema ids that still have a live row.
	 *
	 * @var int[]
	 */
	private array $liveSchemaIds = [];

	/**
	 * Fixture rows per UNPREFIXED magic table name.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $tableRows = [];

	/**
	 * Every SQL string passed to IDBConnection::prepare().
	 *
	 * @var string[]
	 */
	private array $preparedSql = [];

	/**
	 * Values bound on the union statements, as [position => [value, type]].
	 *
	 * @var array<int, array<int, array{0: mixed, 1: int}>>
	 */
	private array $boundValues = [];

	/**
	 * Number of QueryBuilder-backed queries executed against a magic table.
	 */
	private int $magicTableSelects = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->existingTables = [];
		$this->liveRegisterIds = [];
		$this->liveSchemaIds = [];
		$this->tableRows = [];
		$this->preparedSql = [];
		$this->boundValues = [];
		$this->magicTableSelects = 0;

		$this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$this->db->method('prepare')->willReturnCallback(
			fn (string $sql): IPreparedStatement => $this->makeStatement($sql)
		);
		$this->db->method('getQueryBuilder')->willReturnCallback(
			fn (): IQueryBuilder => $this->makeQueryBuilder()
		);

		// findAcrossAllMagicTables resolves the mappers through the service container.
		\OC::$server->registerService(RegisterMapper::class, fn () => $this->registerMapper);
		\OC::$server->registerService(SchemaMapper::class, fn () => $this->schemaMapper);
	}//end setUp()

	/*
	 * ---------------------------------------------------------------- fixtures
	 */

	/**
	 * Register a live magic table (its register and schema rows both exist).
	 *
	 * @param int $registerId The register id.
	 * @param int $schemaId The schema id.
	 * @param array $rows Rows held by the table.
	 *
	 * @return string The unprefixed table name.
	 */
	private function givenLiveTable(int $registerId, int $schemaId, array $rows = []): string {
		$tableName = 'openregister_table_' . $registerId . '_' . $schemaId;

		$this->existingTables[] = 'oc_' . $tableName;
		$this->liveRegisterIds[] = $registerId;
		$this->liveSchemaIds[] = $schemaId;
		$this->tableRows[$tableName] = $rows;

		return $tableName;
	}//end givenLiveTable()

	/**
	 * Register an ORPHANED magic table: the table file still exists, but its
	 * register (and/or schema) row was deleted. It must never be queried.
	 *
	 * @param int $registerId The dead register id.
	 * @param int $schemaId The dead schema id.
	 * @param array $rows Rows the stale table still holds.
	 *
	 * @return string The unprefixed table name.
	 */
	private function givenOrphanedTable(int $registerId, int $schemaId, array $rows = []): string {
		$tableName = 'openregister_table_' . $registerId . '_' . $schemaId;

		$this->existingTables[] = 'oc_' . $tableName;
		$this->tableRows[$tableName] = $rows;

		return $tableName;
	}//end givenOrphanedTable()

	/**
	 * Build a magic-table row with the shared metadata columns.
	 *
	 * @param int $id The `_id` bigint primary key.
	 * @param string $uuid The `_uuid` value.
	 * @param string $slug The `_slug` value.
	 * @param string $uri The `_uri` value.
	 * @param string|null $deleted The `_deleted` payload (null = not deleted).
	 *
	 * @return array<string, mixed>
	 */
	private function row(int $id, string $uuid, string $slug, string $uri, ?string $deleted = null): array {
		return [
			'_id' => $id,
			'_uuid' => $uuid,
			'_slug' => $slug,
			'_uri' => $uri,
			'_deleted' => $deleted,
			'name' => 'row-' . $id,
		];
	}//end row()

	/*
	 * ------------------------------------------------------------- fake driver
	 */

	/**
	 * Build a prepared-statement double for the raw-SQL paths.
	 *
	 * Two SQL shapes reach prepare(): the information_schema table listing and
	 * the chunked UNION ALL locate query. The union is answered by evaluating
	 * the fixture against the tables actually named in the SQL, so a table the
	 * production code fails to include simply cannot be matched.
	 *
	 * @param string $sql The SQL being prepared.
	 *
	 * @return IPreparedStatement&MockObject
	 */
	private function makeStatement(string $sql): IPreparedStatement {
		$this->preparedSql[] = $sql;

		$statementIndex = (count($this->preparedSql) - 1);
		$this->boundValues[$statementIndex] = [];

		$statement = $this->createMock(IPreparedStatement::class);

		$statement->method('bindValue')->willReturnCallback(
			function ($param, $value, $type = null) use ($statementIndex): bool {
				$this->boundValues[$statementIndex][(int)$param] = [$value, (int)$type];
				return true;
			}
		);

		$statement->method('execute')->willReturnCallback(
			function ($params = null) use ($sql, $statementIndex): IResult {
				if (str_contains($sql, 'information_schema') === true) {
					return $this->makeResult(
						rows: array_map(fn (string $t): array => ['table_name' => $t], $this->existingTables)
					);
				}

				return $this->makeResult(rows: $this->evaluateUnion(sql: $sql, statementIndex: $statementIndex));
			}
		);

		return $statement;
	}//end makeStatement()

	/**
	 * Evaluate the chunked UNION ALL locate query against the fixture.
	 *
	 * Mirrors what the database would do: for every table whose name literal is
	 * present in the SQL, look for a row matching the bound identifier, honour
	 * the `_deleted IS NULL` predicate IF the SQL actually carries it, and stop
	 * at the first hit (the query is `LIMIT 1`).
	 *
	 * @param string $sql The union SQL.
	 * @param int $statementIndex Index into the recorded bound values.
	 *
	 * @return array<int, array<string, mixed>> At most one (src_table, hit_id) row.
	 */
	private function evaluateUnion(string $sql, int $statementIndex): array {
		// The bound parameters repeat per branch: (_id, _uuid, _slug, _uri).
		$bound = $this->boundValues[$statementIndex];
		$idParam = ($bound[1][0] ?? null);
		$identifier = ($bound[2][0] ?? null);

		// Only filter deleted rows when the generated SQL says so.
		$excludeDeleted = str_contains($sql, 'IS NULL');

		foreach ($this->tableRows as $tableName => $rows) {
			// The table can only be searched if the code actually emitted it.
			if (str_contains($sql, "'" . $tableName . "'") === false) {
				continue;
			}

			foreach ($rows as $row) {
				$matches = ($row['_id'] === $idParam
					|| $row['_uuid'] === $identifier
					|| $row['_slug'] === $identifier
					|| $row['_uri'] === $identifier);

				if ($matches === false) {
					continue;
				}

				if ($excludeDeleted === true && $row['_deleted'] !== null) {
					continue;
				}

				// LIMIT 1.
				return [
					[
						'src_table' => $tableName,
						'hit_id' => $row['_id'],
					],
				];
			}
		}//end foreach

		return [];
	}//end evaluateUnion()

	/**
	 * Build a QueryBuilder double covering the three QB-backed paths:
	 * the two `getExistingIds()` lookups and the phase-2 `SELECT *` fetch.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function makeQueryBuilder(): IQueryBuilder {
		$state = [
			'table' => null,
			'id' => null,
			'select' => null,
		];

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('orX')->willReturn($this->createMock(ICompositeExpression::class));

		$queryBuilder = $this->createMock(IQueryBuilder::class);
		$queryBuilder->method('expr')->willReturn($expr);
		$queryBuilder->method('where')->willReturnSelf();
		$queryBuilder->method('andWhere')->willReturnSelf();
		$queryBuilder->method('setMaxResults')->willReturnSelf();

		$queryBuilder->method('select')->willReturnCallback(
			function (...$cols) use (&$state, $queryBuilder): IQueryBuilder {
				$state['select'] = $cols;
				return $queryBuilder;
			}
		);

		$queryBuilder->method('from')->willReturnCallback(
			function (string $table) use (&$state, $queryBuilder): IQueryBuilder {
				$state['table'] = $table;
				return $queryBuilder;
			}
		);

		// The phase-2 fetch binds the located `_id`; capture it so the fake can
		// return the right row.
		$queryBuilder->method('createNamedParameter')->willReturnCallback(
			function ($value, $type = null) use (&$state): string {
				if (is_int($value) === true) {
					$state['id'] = $value;
				}

				return ':param';
			}
		);

		$queryBuilder->method('executeQuery')->willReturnCallback(
			function () use (&$state): IResult {
				if ($state['table'] === 'openregister_registers') {
					return $this->makeResult(rows: [], columns: $this->liveRegisterIds);
				}

				if ($state['table'] === 'openregister_schemas') {
					return $this->makeResult(rows: [], columns: $this->liveSchemaIds);
				}

				$rows = ($this->tableRows[$state['table']] ?? []);

				// The cross-org IDOR fix (openregister#2137) added a second,
				// narrow query against the matched magic table — the
				// access-control verification re-run via
				// MagicSearchHandler::applyAccessControlToQuery(), which
				// selects only the `_id` column (never `*`). Only a `SELECT
				// *` is the phase-2 full-row fetch these tests are pinning;
				// the searchHandler is stubbed to a pass-through allow below
				// (see makeMapper()), so the verification query always finds
				// the same row here — it must NOT double-count as a second
				// full-row select.
				$selectedFullRow = in_array('*', ($state['select'] ?? []), true);
				if ($selectedFullRow === true) {
					$this->magicTableSelects++;
				}

				foreach ($rows as $row) {
					if ($row['_id'] === $state['id']) {
						return $this->makeResult(rows: [$selectedFullRow === true ? $row : ['_id' => $row['_id']]]);
					}
				}

				return $this->makeResult(rows: []);
			}
		);

		return $queryBuilder;
	}//end makeQueryBuilder()

	/**
	 * Build an IResult double.
	 *
	 * @param array $rows Associative rows for fetch()/fetchAll().
	 * @param array $columns Scalar column values for fetchAll(FETCH_COLUMN).
	 *
	 * @return IResult&MockObject
	 */
	private function makeResult(array $rows, array $columns = []): IResult {
		$cursor = 0;

		$result = $this->createMock(IResult::class);
		$result->method('closeCursor')->willReturn(true);
		$result->method('fetch')->willReturnCallback(
			function () use ($rows, &$cursor) {
				if (isset($rows[$cursor]) === false) {
					return false;
				}

				$row = $rows[$cursor];
				$cursor++;
				return $row;
			}
		);
		$result->method('fetchAll')->willReturnCallback(
			function ($mode = null) use ($rows, $columns): array {
				if (empty($columns) === false) {
					return $columns;
				}

				return $rows;
			}
		);

		return $result;
	}//end makeResult()

	/*
	 * ----------------------------------------------------------- subject setup
	 */

	/**
	 * Build the MagicMapper under test with the row-conversion seam stubbed.
	 *
	 * `convertRowToObjectEntity()` delegates to the MagicStatisticsHandler; that
	 * collaborator is unchanged by this fix, so it is replaced with a double that
	 * echoes the row back as an ObjectEntity. Everything else — table discovery,
	 * orphan filtering, chunking, the union, the fetch — is the real code.
	 *
	 * @return MagicMapper
	 */
	private function makeMapper(): MagicMapper {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(
			fn (string $key, $default = null) => ($key === 'dbtableprefix' ? 'oc_' : $default)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				return match ($id) {
					DateTimeNormalizer::class => $this->createMock(DateTimeNormalizer::class),
					\OCA\OpenRegister\Service\ConditionMatcher::class => $this->createMock(
						\OCA\OpenRegister\Service\ConditionMatcher::class
					),
					\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class => $this->createMock(
						\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class
					),
					default => null,
				};
			}
		);

		$mapper = new MagicMapper(
			$this->db,
			$this->schemaMapper,
			$this->registerMapper,
			$config,
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(SettingsService::class),
			$container
		);

		$statisticsHandler = $this->createMock(MagicStatisticsHandler::class);
		$statisticsHandler->method('convertRowToObjectEntity')->willReturnCallback(
			function (array $row): ObjectEntity {
				$object = new ObjectEntity();
				$object->setId($row['_id']);
				$object->setUuid($row['_uuid']);
				return $object;
			}
		);

		$property = new \ReflectionProperty(MagicMapper::class, 'statisticsHandler');
		$property->setAccessible(true);
		$property->setValue($mapper, $statisticsHandler);

		// The cross-org IDOR fix (openregister#2137) re-verifies every hit
		// through MagicSearchHandler::applyAccessControlToQuery(). These
		// tests are about scan/locate mechanics (chunking, quoting, id
		// binding), not access control, so stub it as a pass-through allow —
		// the access-control semantics themselves are pinned separately in
		// MagicMapperFindAcrossAllMagicTablesAccessControlTest.
		$searchHandler = $this->createMock(\OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler::class);
		$searchHandler->method('applyAccessControlToQuery')->willReturnCallback(
			static function (IQueryBuilder $qb, Schema $schema, bool $_rbac = true, bool $_multitenancy = true): void {
				// No-op: the fake DB driver already answers the verification
				// query with the same row it located, so "no filtering
				// applied" reproduces the pre-fix pass-through behaviour
				// these tests expect.
			}
		);

		$searchHandlerProperty = new \ReflectionProperty(MagicMapper::class, 'searchHandler');
		$searchHandlerProperty->setAccessible(true);
		$searchHandlerProperty->setValue($mapper, $searchHandler);

		// Register/schema hydration on the hit path.
		$this->registerMapper->method('find')->willReturnCallback(
			function (int $id): Register {
				$register = new Register();
				$register->setId($id);
				return $register;
			}
		);
		$this->schemaMapper->method('find')->willReturnCallback(
			function (int $id): Schema {
				$schema = new Schema();
				$schema->setId($id);
				return $schema;
			}
		);

		return $mapper;
	}//end makeMapper()

	/**
	 * Return only the UNION ALL locate queries that were prepared.
	 *
	 * @return string[]
	 */
	private function unionQueries(): array {
		return array_values(
			array_filter(
				$this->preparedSql,
				fn (string $sql): bool => str_contains($sql, 'UNION ALL') === true
					|| str_contains($sql, 'src_table') === true
			)
		);
	}//end unionQueries()

	/*
	 * ------------------------------------------------------------------- tests
	 */

	/**
	 * An object is found by its UUID, and the context comes back with it.
	 *
	 * @return void
	 */
	public function testFindsObjectByUuid(): void {
		$this->givenLiveTable(registerId: 1, schemaId: 2, rows: []);
		$this->givenLiveTable(
			registerId: 3,
			schemaId: 4,
			rows: [$this->row(id: 42, uuid: 'uuid-abc', slug: 'my-slug', uri: 'https://example.org/42')]
		);

		$result = $this->makeMapper()->findAcrossAllMagicTables(identifier: 'uuid-abc');

		$this->assertInstanceOf(ObjectEntity::class, $result['object']);
		$this->assertSame('uuid-abc', $result['object']->getUuid());
		$this->assertInstanceOf(Register::class, $result['register']);
		$this->assertInstanceOf(Schema::class, $result['schema']);
		$this->assertSame(3, $result['register']->getId());
		$this->assertSame(4, $result['schema']->getId());

		// Exactly one full-row SELECT — against the ONE table that matched.
		$this->assertSame(1, $this->magicTableSelects);
	}//end testFindsObjectByUuid()

	/**
	 * An object is found by its slug.
	 *
	 * @return void
	 */
	public function testFindsObjectBySlug(): void {
		$this->givenLiveTable(
			registerId: 5,
			schemaId: 6,
			rows: [$this->row(id: 7, uuid: 'uuid-xyz', slug: 'cowboy', uri: 'https://example.org/7')]
		);

		$result = $this->makeMapper()->findAcrossAllMagicTables(identifier: 'cowboy');

		$this->assertSame('uuid-xyz', $result['object']->getUuid());
		$this->assertSame(5, $result['register']->getId());
	}//end testFindsObjectBySlug()

	/**
	 * An object is found by its numeric `_id`, which is bound as a real integer.
	 *
	 * `_id` is a bigint column: binding a UUID against it type-errors on
	 * PostgreSQL, so the code binds the -1 sentinel for non-numeric identifiers
	 * and the actual integer for numeric ones.
	 *
	 * @return void
	 */
	public function testFindsObjectByNumericId(): void {
		$this->givenLiveTable(
			registerId: 8,
			schemaId: 9,
			rows: [$this->row(id: 99, uuid: 'uuid-99', slug: 'slug-99', uri: 'https://example.org/99')]
		);

		$result = $this->makeMapper()->findAcrossAllMagicTables(identifier: 99);

		$this->assertSame('uuid-99', $result['object']->getUuid());

		// Every branch binds (_id INT, _uuid STR, _slug STR, _uri STR).
		$bound = $this->boundValues[array_key_last($this->boundValues)];
		$this->assertSame(99, $bound[1][0]);
		$this->assertSame(IQueryBuilder::PARAM_INT, $bound[1][1]);
		$this->assertSame('99', $bound[2][0]);
		$this->assertSame(IQueryBuilder::PARAM_STR, $bound[2][1]);
	}//end testFindsObjectByNumericId()

	/**
	 * A non-numeric identifier binds the -1 sentinel against the bigint column,
	 * never the UUID itself.
	 *
	 * @return void
	 */
	public function testNonNumericIdentifierBindsSentinelAgainstBigintColumn(): void {
		$this->givenLiveTable(
			registerId: 1,
			schemaId: 1,
			rows: [$this->row(id: 1, uuid: 'uuid-1', slug: 'slug-1', uri: 'uri-1')]
		);

		$this->makeMapper()->findAcrossAllMagicTables(identifier: 'uuid-1');

		$bound = $this->boundValues[array_key_last($this->boundValues)];
		$this->assertSame(-1, $bound[1][0], 'the bigint _id column must receive the -1 sentinel');
		$this->assertSame(IQueryBuilder::PARAM_INT, $bound[1][1]);
	}//end testNonNumericIdentifierBindsSentinelAgainstBigintColumn()

	/**
	 * Nothing found anywhere still throws DoesNotExistException.
	 *
	 * @return void
	 */
	public function testThrowsDoesNotExistWhenNotFoundInAnyTable(): void {
		$this->givenLiveTable(
			registerId: 1,
			schemaId: 2,
			rows: [$this->row(id: 1, uuid: 'uuid-1', slug: 'slug-1', uri: 'uri-1')]
		);

		$this->expectException(DoesNotExistException::class);
		$this->expectExceptionMessage("Object with identifier 'nope' not found in any magic table");

		$this->makeMapper()->findAcrossAllMagicTables(identifier: 'nope');
	}//end testThrowsDoesNotExistWhenNotFoundInAnyTable()

	/**
	 * A soft-deleted object is invisible by default and visible with
	 * $includeDeleted — and the `_deleted IS NULL` predicate is what does it.
	 *
	 * @return void
	 */
	public function testIncludeDeletedIsHonoured(): void {
		$this->givenLiveTable(
			registerId: 1,
			schemaId: 2,
			rows: [
				$this->row(
					id: 5,
					uuid: 'uuid-deleted',
					slug: 'gone',
					uri: 'uri-5',
					deleted: '{"deleted":"2026-01-01"}'
				),
			]
		);

		// Default: the deleted row is not found.
		try {
			$this->makeMapper()->findAcrossAllMagicTables(identifier: 'uuid-deleted');
			$this->fail('Expected DoesNotExistException for a soft-deleted object');
		} catch (DoesNotExistException $e) {
			$this->assertStringContainsString('not found in any magic table', $e->getMessage());
		}

		$this->assertStringContainsString(
			'IS NULL',
			$this->unionQueries()[0],
			'the locate query must exclude soft-deleted rows by default'
		);

		// includeDeleted: the row is returned, and the predicate is gone.
		$this->preparedSql = [];
		$this->boundValues = [];

		$result = $this->makeMapper()->findAcrossAllMagicTables(
			identifier: 'uuid-deleted',
			includeDeleted: true
		);

		$this->assertSame('uuid-deleted', $result['object']->getUuid());
		$this->assertStringNotContainsString(
			'IS NULL',
			$this->unionQueries()[0],
			'includeDeleted must drop the _deleted predicate'
		);
	}//end testIncludeDeletedIsHonoured()

	/**
	 * Orphaned magic tables — whose register or schema row was deleted — are
	 * never queried, even though the table still exists and still holds the row.
	 *
	 * @return void
	 */
	public function testOrphanedTablesAreSkipped(): void {
		// Stale table: its register/schema rows are gone, but it still holds the object.
		$orphan = $this->givenOrphanedTable(
			registerId: 77,
			schemaId: 88,
			rows: [$this->row(id: 1, uuid: 'uuid-orphan', slug: 'orphan', uri: 'uri-orphan')]
		);

		$this->givenLiveTable(registerId: 1, schemaId: 2, rows: []);

		try {
			$this->makeMapper()->findAcrossAllMagicTables(identifier: 'uuid-orphan');
			$this->fail('Expected DoesNotExistException — the orphaned table must not be searched');
		} catch (DoesNotExistException $e) {
			$this->assertStringContainsString('not found in any magic table', $e->getMessage());
		}

		$this->assertStringNotContainsString(
			$orphan,
			$this->unionQueries()[0],
			'an orphaned table must never appear in the locate query'
		);
		$this->assertSame(0, $this->magicTableSelects, 'no full-row fetch should have happened');
	}//end testOrphanedTablesAreSkipped()

	/**
	 * THE FIX. The scan no longer issues one query per magic table: it probes
	 * them in chunks of 100 with a narrow UNION, then fetches one row.
	 *
	 * With 250 live tables the old code ran 250 `SELECT *` queries. The new code
	 * runs ceil(250/100) = 3 locate queries at most, plus one fetch.
	 *
	 * @return void
	 */
	public function testScanIsChunkedInsteadOfOneQueryPerTable(): void {
		for ($i = 1; $i <= 249; $i++) {
			$this->givenLiveTable(registerId: $i, schemaId: 1, rows: []);
		}

		// The object lives in the last table, so every chunk must be probed.
		$this->givenLiveTable(
			registerId: 250,
			schemaId: 1,
			rows: [$this->row(id: 3, uuid: 'uuid-last', slug: 'last', uri: 'uri-last')]
		);

		$result = $this->makeMapper()->findAcrossAllMagicTables(identifier: 'uuid-last');

		$this->assertSame('uuid-last', $result['object']->getUuid());

		$unionQueries = $this->unionQueries();
		$this->assertCount(3, $unionQueries, '250 tables must be probed in 3 chunks of 100, not 250 queries');
		$this->assertSame(1, $this->magicTableSelects, 'exactly one full-row fetch');
	}//end testScanIsChunkedInsteadOfOneQueryPerTable()

	/**
	 * MEMORY SAFETY. A `SELECT *` UNION across every magic table has previously
	 * caused an out-of-memory event on this codebase. The locate query must
	 * select ONLY the table name and `_id`, and must be LIMIT-ed.
	 *
	 * @return void
	 */
	public function testLocateQueryIsNarrowAndLimited(): void {
		for ($i = 1; $i <= 5; $i++) {
			$this->givenLiveTable(registerId: $i, schemaId: 1, rows: []);
		}

		try {
			$this->makeMapper()->findAcrossAllMagicTables(identifier: 'absent');
		} catch (DoesNotExistException) {
			// Expected — we only care about the SQL shape here.
		}

		$union = $this->unionQueries()[0];

		$this->assertStringNotContainsString('SELECT *', $union, 'the union must never select full rows');
		$this->assertStringContainsString('src_table', $union);
		$this->assertStringContainsString('hit_id', $union);
		$this->assertStringContainsString('UNION ALL', $union);
		$this->assertStringContainsString('LIMIT 1', $union);
	}//end testLocateQueryIsNarrowAndLimited()

	/**
	 * SQL INJECTION. The identifier is always bound, never interpolated — even
	 * though the table names are embedded as literals.
	 *
	 * @return void
	 */
	public function testIdentifierIsNeverInterpolatedIntoSql(): void {
		$this->givenLiveTable(registerId: 1, schemaId: 2, rows: []);

		$evil = "' OR 1=1 --";

		try {
			$this->makeMapper()->findAcrossAllMagicTables(identifier: $evil);
		} catch (DoesNotExistException) {
			// Expected.
		}

		foreach ($this->preparedSql as $sql) {
			$this->assertStringNotContainsString($evil, $sql, 'the identifier must never reach the SQL text');
		}

		// It was bound instead.
		$bound = $this->boundValues[array_key_last($this->boundValues)];
		$this->assertSame($evil, $bound[2][0]);
	}//end testIdentifierIsNeverInterpolatedIntoSql()

	/**
	 * The identifier columns are quoted for the active platform. MySQL uses
	 * backticks where PostgreSQL uses double quotes; hand-rolled quoting would
	 * break one of them.
	 *
	 * @return void
	 */
	public function testLocateQueryQuotesIdentifiersPerPlatform(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
		$this->db->method('prepare')->willReturnCallback(
			fn (string $sql): IPreparedStatement => $this->makeStatement($sql)
		);
		$this->db->method('getQueryBuilder')->willReturnCallback(
			fn (): IQueryBuilder => $this->makeQueryBuilder()
		);

		$this->givenLiveTable(registerId: 1, schemaId: 2, rows: []);

		try {
			$this->makeMapper()->findAcrossAllMagicTables(identifier: 'absent');
		} catch (DoesNotExistException) {
			// Expected.
		}

		$union = $this->unionQueries()[0];

		$this->assertStringContainsString('`_uuid`', $union, 'MySQL identifiers must be backtick-quoted');
		$this->assertStringContainsString('`oc_openregister_table_1_2`', $union);
		$this->assertStringNotContainsString('"_uuid"', $union);
	}//end testLocateQueryQuotesIdentifiersPerPlatform()
}//end class
