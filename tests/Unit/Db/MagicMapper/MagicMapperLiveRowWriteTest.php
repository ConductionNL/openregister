<?php

/**
 * MagicMapper: a write that may only land on a live row updates only a live row and never inserts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses \OCA\OpenRegister\Exception\HookStoppedException
 *
 * @spec openspec/changes/create-over-taken-identifier-runs-update-path/specs/object-lifecycle/spec.md
 */
class MagicMapperLiveRowWriteTest extends TestCase {

	private const UUID = '11111111-2222-3333-4444-555555555555';

	/**
	 * The conditions the update was given, as [kind, column].
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $conditions = [];

	/**
	 * How often a statement was executed.
	 *
	 * @var int
	 */
	private int $executed = 0;

	/**
	 * A mapper on a database whose statements change $affected rows.
	 *
	 * @param int $affected Rows an UPDATE changes.
	 *
	 * @return MagicMapper
	 */
	private function mapper(int $affected): MagicMapper {
		$this->conditions = [];
		$this->executed   = 0;

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(fn ($column): string => 'eq:' . $column);
		$expr->method('isNull')->willReturnCallback(fn ($column): string => 'isNull:' . $column);

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['update', 'insert', 'set', 'setValue'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('createNamedParameter')->willReturnCallback(static fn ($value): string => ':p');
		$qb->method('expr')->willReturn($expr);
		$qb->method('where')->willReturnCallback(function (string $condition) use ($qb): IQueryBuilder {
			$this->conditions[] = $condition;
			return $qb;
		});
		$qb->method('andWhere')->willReturnCallback(function (string $condition) use ($qb): IQueryBuilder {
			$this->conditions[] = $condition;
			return $qb;
		});
		$qb->method('executeStatement')->willReturnCallback(function () use ($affected): int {
			$this->executed++;
			return $affected;
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('lastInsertId')->willReturn(9);

		$services = [
			DateTimeNormalizerInterface::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverterInterface::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): ?object => ($services[$id] ?? null));

		return new MagicMapper(
			$db,
			new MagicMapperLookups(
				$this->createMock(SchemaMapper::class),
				$this->createMock(RegisterMapper::class),
				$container
			),
			new MagicMapperIdentity(
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(IUserManager::class)
			),
			new MagicMapperRuntime(
				$this->createMock(IConfig::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(IEventDispatcher::class),
				$this->createMock(LoggerInterface::class)
			)
		);
	}//end mapper()

	/**
	 * Mark the uuid's write as one that may only land on a live row.
	 *
	 * @param MagicMapper          $mapper  The mapper.
	 * @param HookStoppedException $refusal The refusal to throw when it cannot.
	 *
	 * @return void
	 */
	private function liveOnly(MagicMapper $mapper, HookStoppedException $refusal): void {
		(new ReflectionProperty(MagicMapper::class, 'liveRowOnlyWrites'))->setValue($mapper, [self::UUID => $refusal]);
	}//end liveOnly()

	/**
	 * Call a private table write.
	 *
	 * @param MagicMapper $mapper    The mapper.
	 * @param string      $method    The method.
	 * @param array       $arguments Its arguments.
	 *
	 * @return mixed
	 */
	private function call(MagicMapper $mapper, string $method, array $arguments): mixed {
		return (new ReflectionMethod(MagicMapper::class, $method))->invokeArgs($mapper, $arguments);
	}//end call()

	public function testALiveOnlyUpdateOfADeletedRowThrowsTheRefusal(): void {
		$mapper  = $this->mapper(affected: 0);
		$refusal = new HookStoppedException(message: 'not initial', errors: []);
		$this->liveOnly(mapper: $mapper, refusal: $refusal);

		try {
			$this->call(mapper: $mapper, method: 'updateObjectInRegisterSchemaTable', arguments: [self::rowWrite(self::UUID, ['_status' => 'x'], 'table')]);
			$this->fail('A live-only update that changed nothing must throw the refusal.');
		} catch (HookStoppedException $e) {
			$this->assertSame($refusal, $e);
		}

		$this->assertContains('isNull:_deleted', $this->conditions);
	}//end testALiveOnlyUpdateOfADeletedRowThrowsTheRefusal()

	public function testALiveOnlyUpdateOfALiveRowIsWritten(): void {
		$mapper = $this->mapper(affected: 1);
		$this->liveOnly(mapper: $mapper, refusal: new HookStoppedException(message: 'not initial', errors: []));

		$this->assertSame(1, $this->call(mapper: $mapper, method: 'updateObjectInRegisterSchemaTable', arguments: [self::rowWrite(self::UUID, ['_status' => 'x'], 'table')]));
		$this->assertContains('isNull:_deleted', $this->conditions);
	}//end testALiveOnlyUpdateOfALiveRowIsWritten()

	public function testAnOrdinaryUpdateIsUnconditional(): void {
		$mapper = $this->mapper(affected: 0);

		$this->assertSame(0, $this->call(mapper: $mapper, method: 'updateObjectInRegisterSchemaTable', arguments: [self::rowWrite(self::UUID, ['_status' => 'x'], 'table')]));
		$this->assertNotContains('isNull:_deleted', $this->conditions);
	}//end testAnOrdinaryUpdateIsUnconditional()

	public function testALiveOnlyWriteNeverInserts(): void {
		$mapper  = $this->mapper(affected: 1);
		$refusal = new HookStoppedException(message: 'not initial', errors: []);
		$this->liveOnly(mapper: $mapper, refusal: $refusal);

		try {
			$this->call(mapper: $mapper, method: 'insertObjectInRegisterSchemaTable', arguments: [['_uuid' => self::UUID], 'table']);
			$this->fail('A live-only write must not insert.');
		} catch (HookStoppedException $e) {
			$this->assertSame($refusal, $e);
		}

		$this->assertSame(0, $this->executed);
	}//end testALiveOnlyWriteNeverInserts()

	public function testAnOrdinaryInsertIsWritten(): void {
		$mapper = $this->mapper(affected: 1);

		$this->assertSame(9, $this->call(mapper: $mapper, method: 'insertObjectInRegisterSchemaTable', arguments: [['_uuid' => self::UUID], 'table']));
		$this->assertSame(1, $this->executed);
	}//end testAnOrdinaryInsertIsWritten()

	/**
	 * A row write of the given uuid, column values and table.
	 *
	 * @param string               $uuid  The object uuid.
	 * @param array<string, mixed> $row   The prepared column values.
	 * @param string               $table The table name.
	 *
	 * @return \OCA\OpenRegister\Db\MagicMapper\MagicRowWrite The write.
	 */
	private static function rowWrite(string $uuid, array $row, string $table): \OCA\OpenRegister\Db\MagicMapper\MagicRowWrite {
		$write = new \OCA\OpenRegister\Db\MagicMapper\MagicRowWrite(objectData: [], register: new \OCA\OpenRegister\Db\Register(), schema: new \OCA\OpenRegister\Db\Schema(), tableName: $table);
		$write->uuid = $uuid;
		$write->row  = $row;

		return $write;
	}//end rowWrite()
}//end class
