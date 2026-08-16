<?php

/**
 * Phase-0 regression: MagicMapper soft-deleted listing + restore.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\IResult;
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

/**
 * Locks two Phase-0 fixes:
 *
 *  - findDeletedAcrossAllMagicTables() scans EVERY magic table for `_deleted`
 *    rows and merges them newest-first by the RAW `_updated` value, then
 *    paginates — replacing the broken register/schema-less search path that
 *    always returned an empty list.
 *  - restoreObject() refuses to restore (throws) when the owning register/
 *    schema context cannot be resolved, instead of silently issuing an UPDATE
 *    against an unknown table.
 */
class MagicMapperDeletedRestoreTest extends TestCase {

	private IDBConnection&MockObject $db;

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a MagicMapper with the shared mock dependencies.
	 *
	 * @return MagicMapper
	 */
	private function makeMapper(): MagicMapper {
		$container = $this->makeContainer();

		return new MagicMapper(
			$this->db,
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(IConfig::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->logger,
			$this->createMock(SettingsService::class),
			$container
		);
	}//end makeMapper()

	/**
	 * Build a DI container that resolves the lazily-fetched collaborators
	 * MagicMapper pulls during construction (DateTimeNormalizer, ConditionMatcher,
	 * SchemaTypeConverter), matching the production container contract.
	 *
	 * @return ContainerInterface&MockObject
	 */
	private function makeContainer(): ContainerInterface {
		$dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);
		$conditionMatcher = $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class);
		$schemaTypeConverter = $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($dateTimeNormalizer, $conditionMatcher, $schemaTypeConverter) {
				if ($id === DateTimeNormalizer::class) {
					return $dateTimeNormalizer;
				}
				if ($id === \OCA\OpenRegister\Service\ConditionMatcher::class) {
					return $conditionMatcher;
				}
				if ($id === \OCA\OpenRegister\Service\Object\SchemaTypeConverter::class) {
					return $schemaTypeConverter;
				}
				return null;
			}
		);
		return $container;
	}//end makeContainer()

	/**
	 * Build an IResult mock returning the given fetchAll() payload.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows to return.
	 *
	 * @return IResult&MockObject
	 */
	private function resultReturning(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		return $result;
	}//end resultReturning()

	// -------------------------------------------------------------------------
	// findDeletedAcrossAllMagicTables()
	// -------------------------------------------------------------------------

	public function testFindDeletedMergesAllTablesNewestFirst(): void {
		$mapper = $this->makeMapper();

		// discoverMagicTables() uses db->prepare(...)->execute(...)->fetchAll().
		$discoverStmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$discoverResult = $this->resultReturning(
			[
				['table_name' => 'oc_openregister_table_1_1'],
				['table_name' => 'oc_openregister_table_1_2'],
			]
		);
		$discoverStmt->method('execute')->willReturn($discoverResult);
		$this->db->method('prepare')->willReturn($discoverStmt);

		// Per-table deleted-row queries go through getQueryBuilder().
		// Table 1_1 holds the OLDER deleted row, 1_2 the NEWER one.
		$qb1 = $this->makeSelectQbReturning(
			[
				['_uuid' => 'older', '_updated' => '2024-01-01T00:00:00Z', '_deleted' => '2024-01-02T00:00:00Z'],
			]
		);
		$qb2 = $this->makeSelectQbReturning(
			[
				['_uuid' => 'newer', '_updated' => '2024-06-01T00:00:00Z', '_deleted' => '2024-06-02T00:00:00Z'],
			]
		);
		$this->db->method('getQueryBuilder')->willReturnOnConsecutiveCalls($qb1, $qb2);

		// rowToObjectEntity calls schemaMapper->find(); let it throw (caught).
		$this->schemaMapper->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('no schema'));

		$found = $mapper->findDeletedAcrossAllMagicTables();

		$this->assertCount(2, $found);
		$this->assertContainsOnlyInstancesOf(ObjectEntity::class, $found);
		// Newest-first by the RAW _updated value: 'newer' before 'older'.
		$this->assertSame('newer', $found[0]->getUuid());
		$this->assertSame('older', $found[1]->getUuid());
	}//end testFindDeletedMergesAllTablesNewestFirst()

	public function testFindDeletedAppliesPaginationAfterMerge(): void {
		$mapper = $this->makeMapper();

		$discoverStmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$discoverResult = $this->resultReturning([['table_name' => 'oc_openregister_table_1_1']]);
		$discoverStmt->method('execute')->willReturn($discoverResult);
		$this->db->method('prepare')->willReturn($discoverStmt);

		$qb = $this->makeSelectQbReturning(
			[
				['_uuid' => 'a', '_updated' => '2024-03-01T00:00:00Z', '_deleted' => '2024-03-02T00:00:00Z'],
				['_uuid' => 'b', '_updated' => '2024-02-01T00:00:00Z', '_deleted' => '2024-02-02T00:00:00Z'],
				['_uuid' => 'c', '_updated' => '2024-01-01T00:00:00Z', '_deleted' => '2024-01-02T00:00:00Z'],
			]
		);
		$this->db->method('getQueryBuilder')->willReturn($qb);

		$this->schemaMapper->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('no schema'));

		// offset 1, limit 1 over the newest-first set [a, b, c] -> [b].
		$found = $mapper->findDeletedAcrossAllMagicTables(limit: 1, offset: 1);

		$this->assertCount(1, $found);
		$this->assertSame('b', $found[0]->getUuid());
	}//end testFindDeletedAppliesPaginationAfterMerge()

	/**
	 * Build a SELECT QueryBuilder mock whose executeQuery()->fetchAll()
	 * returns the given rows; chainable methods return $this.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows to return.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function makeSelectQbReturning(array $rows): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('isNotNull')->willReturn('cond');
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'where', 'orderBy'] as $chain) {
			$qb->method($chain)->willReturnSelf();
		}

		$qb->method('executeQuery')->willReturn($this->resultReturning($rows));
		return $qb;
	}//end makeSelectQbReturning()

	// -------------------------------------------------------------------------
	// restoreObject()
	// -------------------------------------------------------------------------

	public function testRestoreObjectThrowsWhenRegisterOrSchemaUnresolvable(): void {
		// Partial mock: stub the public findAcrossAllSources to return an object
		// with NO resolvable register/schema context.
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAcrossAllSources'])
			->getMock();

		$entity = new ObjectEntity();
		$entity->setUuid('orphan-uuid');

		$mapper->method('findAcrossAllSources')->willReturn(
			[
				'object' => $entity,
				'register' => null,
				'schema' => null,
			]
		);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Cannot restore object without resolvable register and schema context');
		$mapper->restoreObject('orphan-uuid');
	}//end testRestoreObjectThrowsWhenRegisterOrSchemaUnresolvable()

	public function testRestoreObjectClearsDeleteMarkerWhenContextResolvable(): void {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs($this->constructorArgs())
			->onlyMethods(['findAcrossAllSources', 'getTableNameForRegisterSchema'])
			->getMock();

		$entity = new ObjectEntity();
		$entity->setUuid('live-uuid');
		$entity->setDeleted('2024-01-01T00:00:00Z');

		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);

		$mapper->method('findAcrossAllSources')->willReturn(
			[
				'object' => $entity,
				'register' => $register,
				'schema' => $schema,
			]
		);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_2');

		// The restore must issue exactly one UPDATE clearing the _deleted marker.
		$updateQb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('cond');
		$updateQb->method('expr')->willReturn($expr);
		$updateQb->method('createNamedParameter')->willReturn('param');
		foreach (['update', 'set', 'where'] as $chain) {
			$updateQb->method($chain)->willReturnSelf();
		}

		$updateQb->expects($this->once())->method('executeStatement')->willReturn(1);
		$this->db->method('getQueryBuilder')->willReturn($updateQb);

		$restored = $mapper->restoreObject('live-uuid');

		$this->assertSame('live-uuid', $restored->getUuid());
		// The in-memory entity's delete marker is cleared (the `deleted` field is
		// a json-typed Entity column, so a cleared marker normalises to empty —
		// null or []; the key invariant is that it no longer carries a timestamp).
		$this->assertEmpty($restored->getDeleted());
	}//end testRestoreObjectClearsDeleteMarkerWhenContextResolvable()

	/**
	 * Constructor argument list for a real MagicMapper instance whose DB and
	 * schemaMapper are the shared mocks.
	 *
	 * @return array<int,mixed>
	 */
	private function constructorArgs(): array {
		$container = $this->makeContainer();

		return [
			$this->db,
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(IConfig::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(IAppConfig::class),
			$this->logger,
			$this->createMock(SettingsService::class),
			$container,
		];
	}//end constructorArgs()
}//end class
