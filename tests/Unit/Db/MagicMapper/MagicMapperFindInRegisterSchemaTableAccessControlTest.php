<?php

/**
 * Regression tests for MagicMapper::findInRegisterSchemaTable() RBAC wire-up.
 *
 * WOO-545 flagged a comment-only placeholder in this method where RBAC
 * filtering used to be skipped:
 *
 *     // Apply RBAC filtering if enabled.
 *     if ($_rbac === true) {
 *         // Add RBAC filtering logic here if needed.
 *         // Currently skipped as owner/authorization logic is complex.
 *     }
 *
 * Commit 71c6b7e47 (2026-06-11) "fix(rbac): close cross-org single-GET IDOR"
 * replaced that placeholder with a proper delegation to
 * `MagicSearchHandler::applyAccessControlToQuery()`, so the scoped
 * single-object read now enforces the same isolation as the list/search path.
 *
 * These unit tests pin the resulting contract at the class boundary — a
 * sibling to the existing `MagicMapperFindAcrossAllMagicTablesAccessControlTest`
 * (which covers the cross-schema fallback path) and complement the
 * `MagicMapperIntegrationTest` scenarios that exercise the same call against a
 * real database.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
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

class MagicMapperFindInRegisterSchemaTableAccessControlTest extends TestCase {

	private IDBConnection&MockObject $db;

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private MagicSearchHandler&MockObject $searchHandler;

	private MagicTableHandler&MockObject $tableHandler;

	/**
	 * @var array<int, array{schemaId: int|null, _rbac: bool, _multitenancy: bool}>
	 */
	private array $accessControlCalls = [];

	protected function setUp(): void {
		parent::setUp();

		$this->db = $this->createMock(IDBConnection::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->searchHandler = $this->createMock(MagicSearchHandler::class);
		$this->tableHandler = $this->createMock(MagicTableHandler::class);
		$this->accessControlCalls = [];

		// Record every applyAccessControlToQuery() call so tests can assert
		// the seam was consulted (or NOT consulted, in the bypass case) with
		// the expected _rbac / _multitenancy arguments.
		$this->searchHandler->method('applyAccessControlToQuery')->willReturnCallback(
			function (IQueryBuilder $qb, Schema $schema, bool $_rbac = true, bool $_multitenancy = true): void {
				$this->accessControlCalls[] = [
					'schemaId' => $schema->getId(),
					'_rbac' => $_rbac,
					'_multitenancy' => $_multitenancy,
				];
			}
		);
	}//end setUp()

	/**
	 * Build a MagicMapper wired with the mocked table + search handlers.
	 *
	 * `applyAccessControlToQuery` is a void mutator on the real class — the
	 * mock does not need to actually modify the query builder. It just
	 * records that the call was (or wasn't) made. The DB driver is stubbed
	 * to return an EMPTY result so the method throws DoesNotExistException,
	 * which the assertions below expect.
	 */
	private function makeMapper(): MagicMapper {
		$config = $this->createMock(IConfig::class);

		// The MagicMapper constructor eagerly boots MagicRbacHandler +
		// dependents via the container. Route the minimum handful of
		// container resolves to mocks so the constructor doesn't blow up
		// on missing typed args (ConditionMatcher, etc.).
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				return match ($id) {
					\OCA\OpenRegister\Service\DateTimeNormalizer::class => $this->createMock(
						\OCA\OpenRegister\Service\DateTimeNormalizer::class
					),
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

		// Route the two tableHandler probes findInRegisterSchemaTable calls
		// (existsTableForRegisterSchema + getTableNameForRegisterSchema) at a
		// stubbed handler so we don't need a real table setup.
		$this->tableHandler->method('existsTableForRegisterSchema')->willReturn(true);
		$this->tableHandler->method('getTableNameForRegisterSchema')->willReturn('oc_openregister_table_1_2');

		$tableHandlerProperty = new \ReflectionProperty(MagicMapper::class, 'tableHandler');
		$tableHandlerProperty->setAccessible(true);
		$tableHandlerProperty->setValue($mapper, $this->tableHandler);

		$searchProperty = new \ReflectionProperty(MagicMapper::class, 'searchHandler');
		$searchProperty->setAccessible(true);
		$searchProperty->setValue($mapper, $this->searchHandler);

		// Empty result → row === false → DoesNotExistException. This lets us
		// exercise the RBAC-wire branch without also stubbing the row
		// conversion pipeline.
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->makeEmptyQueryBuilder());

		return $mapper;
	}//end makeMapper()

	private function makeEmptyQueryBuilder(): IQueryBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('isNull')->willReturn('isNull');
		$expr->method('orX')->willReturn($this->createMock(ICompositeExpression::class));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('createNamedParameter')->willReturn(':param');

		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);

		return $qb;
	}//end makeEmptyQueryBuilder()

	private function makeSchema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		return $schema;
	}//end makeSchema()

	private function makeRegister(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}//end makeRegister()

	/*
	 * ------------------------------------------------------------------- tests
	 */

	/**
	 * THE FIX: `_rbac: true` (the default) must consult
	 * `applyAccessControlToQuery()` with the SAME `_rbac`/`_multitenancy` flags
	 * so the scoped single-object read enforces schema-level RBAC + org
	 * isolation. Prior to commit 71c6b7e47 this was a comment-only placeholder
	 * (WOO-545 was filed against that shape).
	 */
	public function testRbacTrueInvokesAccessControlSeam(): void {
		$mapper = $this->makeMapper();

		try {
			$mapper->findInRegisterSchemaTable(
				identifier: 'uuid-authorized',
				register: $this->makeRegister(id: 1),
				schema: $this->makeSchema(id: 2),
				_rbac: true,
				_multitenancy: true
			);
			$this->fail('empty DB result should have raised DoesNotExistException');
		} catch (DoesNotExistException $e) {
			// Expected — assertion is about the seam call, not the return.
		}

		$this->assertCount(1, $this->accessControlCalls, 'the access-control seam MUST be consulted when _rbac is true');
		$this->assertSame(2, $this->accessControlCalls[0]['schemaId']);
		$this->assertTrue($this->accessControlCalls[0]['_rbac']);
		$this->assertTrue($this->accessControlCalls[0]['_multitenancy']);
	}//end testRbacTrueInvokesAccessControlSeam()

	/**
	 * `_rbac: false` alone still consults the seam — multitenancy is a
	 * separate axis (org isolation) that must still be enforced for
	 * non-admin sessions.
	 */
	public function testRbacFalseButMultitenancyTrueStillInvokesSeam(): void {
		$mapper = $this->makeMapper();

		try {
			$mapper->findInRegisterSchemaTable(
				identifier: 'uuid-something',
				register: $this->makeRegister(id: 1),
				schema: $this->makeSchema(id: 3),
				_rbac: false,
				_multitenancy: true
			);
			$this->fail('empty DB result should have raised DoesNotExistException');
		} catch (DoesNotExistException $e) {
			// Expected.
		}

		$this->assertCount(1, $this->accessControlCalls);
		$this->assertFalse($this->accessControlCalls[0]['_rbac']);
		$this->assertTrue($this->accessControlCalls[0]['_multitenancy']);
	}//end testRbacFalseButMultitenancyTrueStillInvokesSeam()

	/**
	 * Both `_rbac: false` AND `_multitenancy: false` — the admin/system-context
	 * bypass — MUST skip the access-control seam entirely, matching the
	 * long-standing contract for internal callers (e.g. LockHandler,
	 * WOO536RepairReadRules::run).
	 */
	public function testFullBypassSkipsAccessControlSeam(): void {
		$mapper = $this->makeMapper();

		try {
			$mapper->findInRegisterSchemaTable(
				identifier: 'uuid-admin-context',
				register: $this->makeRegister(id: 1),
				schema: $this->makeSchema(id: 4),
				_rbac: false,
				_multitenancy: false
			);
			$this->fail('empty DB result should have raised DoesNotExistException');
		} catch (DoesNotExistException $e) {
			// Expected.
		}

		$this->assertSame([], $this->accessControlCalls, 'the access-control seam MUST NOT be consulted when both flags are false');
	}//end testFullBypassSkipsAccessControlSeam()

	/**
	 * The default parameter values (both true) must exercise the seam — a
	 * caller that omits the flags entirely must NOT accidentally get the
	 * admin bypass path.
	 */
	public function testDefaultFlagsInvokeAccessControlSeam(): void {
		$mapper = $this->makeMapper();

		try {
			$mapper->findInRegisterSchemaTable(
				identifier: 'uuid-defaults',
				register: $this->makeRegister(id: 1),
				schema: $this->makeSchema(id: 5)
			);
			$this->fail('empty DB result should have raised DoesNotExistException');
		} catch (DoesNotExistException $e) {
			// Expected.
		}

		$this->assertCount(1, $this->accessControlCalls);
		$this->assertTrue($this->accessControlCalls[0]['_rbac']);
		$this->assertTrue($this->accessControlCalls[0]['_multitenancy']);
	}//end testDefaultFlagsInvokeAccessControlSeam()

}//end class
