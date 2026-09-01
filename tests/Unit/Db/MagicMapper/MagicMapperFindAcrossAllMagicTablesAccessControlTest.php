<?php

/**
 * Regression tests for the cross-org IDOR fix in
 * MagicMapper::findAcrossAllMagicTables() (openregister#2137).
 *
 * `findAcrossAllMagicTables()` — the cross-schema UUID fallback used when a
 * caller doesn't know an object's register/schema up front (e.g.
 * `ObjectService::find()` retrying after a correctly org-filtered scoped
 * lookup misses) — accepted `_rbac`/`_multitenancy` parameters but never
 * applied them. A non-admin authenticated caller could therefore read ANY
 * object by UUID across ANY organisation, bypassing the multitenancy
 * boundary the scoped lookup (`findInRegisterSchemaTable`) already enforces
 * via `MagicSearchHandler::applyAccessControlToQuery()`.
 *
 * The fix re-runs that SAME access-control check against the one candidate
 * row the scan matched. These tests pin the resulting contract:
 *   - a row that fails the access-control check is treated exactly like "not
 *     found in this table" — same exception, same message, no distinct
 *     status/behaviour an attacker could use to confirm existence;
 *   - a row that passes the check is returned exactly as before;
 *   - the admin/system bypass (`_rbac=false` AND `_multitenancy=false`) skips
 *     the check entirely, so it never even queries the access-control seam —
 *     legitimate internal callers (e.g. LockHandler's `_rbacBypass`) keep
 *     working unchanged;
 *   - if the row's register/schema cannot be resolved, the read fails CLOSED
 *     (denied) rather than trusting an unverifiable row.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
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

class MagicMapperFindAcrossAllMagicTablesAccessControlTest extends TestCase {

	private IDBConnection&MockObject $db;

	private RegisterMapper&MockObject $registerMapper;

	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Unprefixed magic table name => rows.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $tableRows = [];

	/** @var string[] Prefixed table names reported by information_schema. */
	private array $existingTables = [];

	/** @var int[] */
	private array $liveRegisterIds = [];

	/** @var int[] */
	private array $liveSchemaIds = [];

	/**
	 * Unprefixed table names for which the access-control verification
	 * query must come back EMPTY (i.e. the row is denied to this caller).
	 *
	 * @var string[]
	 */
	private array $deniedTables = [];

	/**
	 * When true, `RegisterMapper::find()` / `SchemaMapper::find()` throw for
	 * the given table's register/schema ids, simulating "could not resolve
	 * register/schema" (the pre-existing race the fallback already handles).
	 */
	private bool $registerSchemaUnresolvable = false;

	/**
	 * Every `applyAccessControlToQuery()` call recorded as
	 * [tableName, schemaId, rbac, multitenancy].
	 *
	 * @var array<int, array{0: string, 1: int, 2: bool, 3: bool}>
	 */
	private array $accessControlCalls = [];

	/** Number of full-row (`SELECT *`) phase-2 fetches issued. */
	private int $magicTableSelects = 0;

	/** Number of narrow access-control verification queries issued. */
	private int $accessControlQueries = 0;

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->tableRows = [];
		$this->existingTables = [];
		$this->liveRegisterIds = [];
		$this->liveSchemaIds = [];
		$this->deniedTables = [];
		$this->registerSchemaUnresolvable = false;
		$this->accessControlCalls = [];
		$this->magicTableSelects = 0;
		$this->accessControlQueries = 0;

		$this->db->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
		$this->db->method('prepare')->willReturnCallback(
			fn (string $sql): IPreparedStatement => $this->makeStatement($sql)
		);
		$this->db->method('getQueryBuilder')->willReturnCallback(
			fn (): IQueryBuilder => $this->makeQueryBuilder()
		);

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
	 * Build a magic-table row with the shared metadata columns.
	 *
	 * @param int $id The `_id` bigint primary key.
	 * @param string $uuid The `_uuid` value.
	 *
	 * @return array<string, mixed>
	 */
	private function row(int $id, string $uuid): array {
		return [
			'_id' => $id,
			'_uuid' => $uuid,
			'_slug' => 'slug-' . $id,
			'_uri' => 'uri-' . $id,
			'_deleted' => null,
			'name' => 'row-' . $id,
		];
	}//end row()

	/*
	 * ------------------------------------------------------------- fake driver
	 */

	private function makeStatement(string $sql): IPreparedStatement {
		$statement = $this->createMock(IPreparedStatement::class);

		$bound = [];
		$statement->method('bindValue')->willReturnCallback(
			function ($param, $value, $type = null) use (&$bound): bool {
				$bound[(int)$param] = $value;
				return true;
			}
		);

		$statement->method('execute')->willReturnCallback(
			function ($params = null) use ($sql, &$bound): IResult {
				if (str_contains($sql, 'information_schema') === true) {
					return $this->makeResult(
						rows: array_map(fn (string $t): array => ['table_name' => $t], $this->existingTables)
					);
				}

				return $this->makeResult(rows: $this->evaluateUnion(sql: $sql, bound: $bound));
			}
		);

		return $statement;
	}//end makeStatement()

	/**
	 * Evaluate the chunked UNION ALL locate query against the fixture.
	 *
	 * @param string $sql The union SQL.
	 * @param array<int, mixed> $bound Bound values, 1-indexed, repeating
	 *                                 (_id, _uuid, _slug, _uri) per branch.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function evaluateUnion(string $sql, array $bound): array {
		$idParam = ($bound[1] ?? null);
		$identifier = ($bound[2] ?? null);

		foreach ($this->tableRows as $tableName => $rows) {
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

				return [
					[
						'src_table' => $tableName,
						'hit_id' => $row['_id'],
					],
				];
			}
		}

		return [];
	}//end evaluateUnion()

	/**
	 * Build a QueryBuilder double. Distinguishes three shapes:
	 *  - `select('id')->from('openregister_registers'|'openregister_schemas')`
	 *    (getExistingIds — orphan-table filtering);
	 *  - `select('*')->from($magicTable)` (phase-2 full-row fetch);
	 *  - `select('t._id')->from($magicTable, 't')` (the NEW access-control
	 *    verification query added by the fix) — comes back EMPTY for any
	 *    table listed in `$this->deniedTables`.
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

				$selectedFullRow = in_array('*', ($state['select'] ?? []), true);

				if ($selectedFullRow === true) {
					// Phase-2 fetch: the real row (access control not yet applied).
					$this->magicTableSelects++;

					$rows = ($this->tableRows[$state['table']] ?? []);
					foreach ($rows as $row) {
						if ($row['_id'] === $state['id']) {
							return $this->makeResult(rows: [$row]);
						}
					}

					return $this->makeResult(rows: []);
				}

				// The narrow access-control verification query added by the fix.
				$this->accessControlQueries++;

				if (in_array($state['table'], $this->deniedTables, true) === true) {
					return $this->makeResult(rows: []);
				}

				return $this->makeResult(rows: [['_id' => $state['id']]]);
			}
		);

		return $queryBuilder;
	}//end makeQueryBuilder()

	/**
	 * @param array $rows Associative rows for fetch().
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
	 * Build the MagicMapper under test with `statisticsHandler` (row
	 * conversion) and `searchHandler` (access control) both stubbed via
	 * reflection, mirroring the seam pattern used by the sibling
	 * `MagicMapperFindAcrossAllMagicTablesTest`.
	 *
	 * `applyAccessControlToQuery()` is a `void` mutator on the real class —
	 * this double does not need to actually mutate the query builder,
	 * because the fake DB driver already decides ALLOW/DENY per table via
	 * `$this->deniedTables` (see `makeQueryBuilder()`). This test's job is to
	 * prove `findAcrossAllMagicTables()` calls the seam with the right
	 * arguments and correctly honours an empty result as "not found", not to
	 * re-verify `MagicSearchHandler`'s own filtering logic.
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
			$this->createMock(\OCA\OpenRegister\Service\SettingsService::class),
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

		$statisticsProperty = new \ReflectionProperty(MagicMapper::class, 'statisticsHandler');
		$statisticsProperty->setAccessible(true);
		$statisticsProperty->setValue($mapper, $statisticsHandler);

		$searchHandler = $this->createMock(MagicSearchHandler::class);
		$searchHandler->method('applyAccessControlToQuery')->willReturnCallback(
			function (IQueryBuilder $qb, Schema $schema, bool $_rbac = true, bool $_multitenancy = true): void {
				// Recorded for assertion; the fake DB driver (not this seam)
				// decides allow/deny for this test — see makeQueryBuilder().
				$this->accessControlCalls[] = [
					'schemaId' => $schema->getId(),
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
				];
			}
		);

		$searchProperty = new \ReflectionProperty(MagicMapper::class, 'searchHandler');
		$searchProperty->setAccessible(true);
		$searchProperty->setValue($mapper, $searchHandler);

		$this->registerMapper->method('find')->willReturnCallback(
			function (int $id) {
				if ($this->registerSchemaUnresolvable === true) {
					throw new DoesNotExistException('register gone');
				}

				$register = new Register();
				$register->setId($id);
				return $register;
			}
		);
		$this->schemaMapper->method('find')->willReturnCallback(
			function (int $id) {
				if ($this->registerSchemaUnresolvable === true) {
					throw new DoesNotExistException('schema gone');
				}

				$schema = new Schema();
				$schema->setId($id);
				return $schema;
			}
		);

		return $mapper;
	}//end makeMapper()

	/*
	 * ------------------------------------------------------------------- tests
	 */

	/**
	 * THE FIX: a row the caller is authorized to read (in-org / owner /
	 * public / RBAC-granted) is returned exactly as before, and the
	 * access-control seam was consulted with this row's actual schema.
	 *
	 * @return void
	 */
	public function testAuthorizedHitIsReturned(): void {
		$table = $this->givenLiveTable(
			registerId: 1,
			schemaId: 2,
			rows: [$this->row(id: 42, uuid: 'uuid-authorized')]
		);

		$result = $this->makeMapper()->findAcrossAllMagicTables(identifier: 'uuid-authorized');

		$this->assertSame('uuid-authorized', $result['object']->getUuid());
		$this->assertSame(1, $this->accessControlQueries, 'the access-control seam must be consulted once');
		$this->assertCount(1, $this->accessControlCalls);
		$this->assertSame(2, $this->accessControlCalls[0]['schemaId']);
		$this->assertTrue($this->accessControlCalls[0]['_rbac']);
		$this->assertTrue($this->accessControlCalls[0]['_multitenancy']);
	}//end testAuthorizedHitIsReturned()

	/**
	 * THE FIX (the actual IDOR): a row that fails the access-control check —
	 * e.g. a cross-organisation object — is treated exactly like "not found
	 * in this table". Before the fix, `findAcrossAllMagicTables()` returned
	 * the row unconditionally regardless of `_rbac`/`_multitenancy`.
	 *
	 * @return void
	 */
	public function testCrossOrgHitIsDeniedNotFoundNoLeak(): void {
		$table = $this->givenLiveTable(
			registerId: 5,
			schemaId: 6,
			rows: [$this->row(id: 99, uuid: 'uuid-cross-org')]
		);
		$this->deniedTables = [$table];

		$this->expectException(DoesNotExistException::class);
		// Same generic message as a genuine miss — no existence leak via a
		// distinguishable error.
		$this->expectExceptionMessage("Object with identifier 'uuid-cross-org' not found in any magic table");

		$this->makeMapper()->findAcrossAllMagicTables(identifier: 'uuid-cross-org');
	}//end testCrossOrgHitIsDeniedNotFoundNoLeak()

	/**
	 * The denial does not silently short-circuit into a DIFFERENT observable
	 * outcome (e.g. throwing a different exception type, or returning a
	 * partially-populated object) — it must be indistinguishable from a
	 * genuine miss to the caller.
	 *
	 * @return void
	 */
	public function testDeniedHitStopsShortOfObjectConversion(): void {
		$table = $this->givenLiveTable(
			registerId: 7,
			schemaId: 8,
			rows: [$this->row(id: 1, uuid: 'uuid-denied')]
		);
		$this->deniedTables = [$table];

		try {
			$this->makeMapper()->findAcrossAllMagicTables(identifier: 'uuid-denied');
			$this->fail('Expected DoesNotExistException for a denied cross-table hit');
		} catch (DoesNotExistException $e) {
			$this->assertStringContainsString('not found in any magic table', $e->getMessage());
		}
	}//end testDeniedHitStopsShortOfObjectConversion()

	/**
	 * Admin/system bypass: when the caller explicitly passes
	 * `_rbac=false` AND `_multitenancy=false` (e.g. LockHandler's
	 * `$_rbacBypass` path, or an internal system operation), the
	 * access-control seam is never even consulted — a row that WOULD be
	 * denied under normal RBAC is still returned, because the caller has
	 * already taken responsibility for authorization.
	 *
	 * @return void
	 */
	public function testExplicitBypassSkipsAccessControlEntirely(): void {
		$table = $this->givenLiveTable(
			registerId: 9,
			schemaId: 10,
			rows: [$this->row(id: 3, uuid: 'uuid-bypass')]
		);
		// Would be denied under normal RBAC — proves the bypass is a genuine
		// skip, not an accidental "always allow".
		$this->deniedTables = [$table];

		$result = $this->makeMapper()->findAcrossAllMagicTables(
			identifier: 'uuid-bypass',
			_rbac: false,
			_multitenancy: false
		);

		$this->assertSame('uuid-bypass', $result['object']->getUuid());
		$this->assertSame(0, $this->accessControlQueries, 'bypass must skip the access-control query entirely');
		$this->assertCount(0, $this->accessControlCalls);
	}//end testExplicitBypassSkipsAccessControlEntirely()

	/**
	 * Fail CLOSED: if the row's register/schema cannot be resolved (the
	 * pre-existing race this method already logs and tolerates for
	 * register/schema hydration), the read must be denied rather than
	 * trusting a row nobody can verify the authorization context for.
	 *
	 * @return void
	 */
	public function testFailsClosedWhenSchemaCannotBeResolved(): void {
		$this->givenLiveTable(
			registerId: 11,
			schemaId: 12,
			rows: [$this->row(id: 4, uuid: 'uuid-unresolvable')]
		);
		$this->registerSchemaUnresolvable = true;

		$this->expectException(DoesNotExistException::class);
		$this->expectExceptionMessage("Object with identifier 'uuid-unresolvable' not found in any magic table");

		$this->makeMapper()->findAcrossAllMagicTables(identifier: 'uuid-unresolvable');
	}//end testFailsClosedWhenSchemaCannotBeResolved()
}//end class
