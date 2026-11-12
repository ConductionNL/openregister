<?php

/**
 * Golden test for the lock, unlock and uuid batch-delete entry points of MagicMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Tests
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * Pins allowed and refused outcomes of lockObjectEntity(), unlockObjectEntity() and deleteObjectsByUuids().
 */
class MagicMapperLockAndBatchDeleteGoldenTest extends TestCase {

	/**
	 * What happened, in order.
	 *
	 * @var list<string>
	 */
	private array $trace = [];

	/**
	 * A mapper with recorded updates, events and statements, for the given user (null for none).
	 *
	 * @param string|null $user        The logged-in user id.
	 * @param bool        $tableExists Whether the magic table exists.
	 *
	 * @return MagicMapper
	 */
	private function mapper(?string $user, bool $tableExists=true): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['updateObjectEntity', 'getTableNameForRegisterSchema', 'tableExistsForRegisterSchema'])
			->getMock();
		$mapper->method('updateObjectEntity')->willReturnCallback(
			function (ObjectEntity $entity, Register $register, Schema $schema): ObjectEntity {
				$this->trace[] = 'update '.$register->getId().'/'.$schema->getId().' locked='.json_encode($entity->getLocked() !== null);
				return $entity;
			}
		);
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_1_2');
		$mapper->method('tableExistsForRegisterSchema')->willReturn($tableExists);

		$session = $this->createMock(IUserSession::class);
		$account = null;
		if ($user !== null) {
			$account = $this->createMock(IUser::class);
			$account->method('getUID')->willReturn($user);
		}

		$session->method('getUser')->willReturn($account);
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->trace[] = 'event '.(new \ReflectionClass($event))->getShortName();
			}
		);

		foreach (['userSession' => $session, 'eventDispatcher' => $events, 'logger' => new NullLogger(), 'db' => $this->db()] as $name => $value) {
			(new ReflectionProperty(MagicMapper::class, $name))->setValue($mapper, $value);
		}

		return $mapper;
	}//end mapper()

	/**
	 * A connection whose query builder records the statement shape.
	 *
	 * @return IDBConnection
	 */
	private function db(): IDBConnection {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('in')->willReturnCallback(static fn ($col): string => 'in:'.$col);
		$expr->method('isNull')->willReturnCallback(static fn ($col): string => 'isNull:'.$col);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value): string {
				if (is_array($value) === true) {
					return ':'.implode(',', $value);
				}

				return ':value';
			}
		);
		foreach (['delete', 'update'] as $verb) {
			$qb->method($verb)->willReturnCallback(
				function (string $table) use ($qb, $verb): IQueryBuilder {
					$this->trace[] = $verb.' '.$table;
					return $qb;
				}
			);
		}

		foreach (['where', 'andWhere'] as $verb) {
			$qb->method($verb)->willReturnCallback(
				function ($condition) use ($qb, $verb): IQueryBuilder {
					$this->trace[] = $verb.' '.$condition;
					return $qb;
				}
			);
		}

		$qb->method('set')->willReturnCallback(
			function (string $column) use ($qb): IQueryBuilder {
				$this->trace[] = 'set '.$column;
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturn(2);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end db()

	/**
	 * The register and schema.
	 *
	 * @return array{0: Register, 1: Schema}
	 */
	private function table(): array {
		$register = new Register();
		$register->setId(1);
		$schema = new Schema();
		$schema->setId(2);

		return [$register, $schema];
	}//end table()

	public function testALockIsWrittenAndAnnounced(): void {
		[$register, $schema] = $this->table();
		$entity = new ObjectEntity();
		$entity->setUuid('u-1');

		$locked = $this->mapper(user: 'anna')->lockObjectEntity($entity, $register, $schema, 60, 'p-1', null);

		$this->assertSame('anna', $locked->getLocked()['user']);
		$this->assertSame('p-1', $locked->getLocked()['process']);
		$this->assertSame(['update 1/2 locked=true', 'event ObjectLockedEvent'], $this->trace);
	}//end testALockIsWrittenAndAnnounced()

	public function testALockWithoutAProcessIsTaggedByTheMapper(): void {
		[$register, $schema] = $this->table();
		$locked = $this->mapper(user: 'anna')->lockObjectEntity(new ObjectEntity(), $register, $schema);

		$this->assertSame('MagicMapper lock', $locked->getLocked()['process']);
	}//end testALockWithoutAProcessIsTaggedByTheMapper()

	public function testALockWithoutAUserIsRefusedBeforeAnyWrite(): void {
		[$register, $schema] = $this->table();
		try {
			$this->mapper(user: null)->lockObjectEntity(new ObjectEntity(), $register, $schema);
			$this->fail('A lock without a user must be refused.');
		} catch (Exception $e) {
			$this->assertSame('No user logged in', $e->getMessage());
		}

		$this->assertSame([], $this->trace);
	}//end testALockWithoutAUserIsRefusedBeforeAnyWrite()

	public function testALockHeldBySomeoneElseIsRefusedBeforeAnyWrite(): void {
		[$register, $schema] = $this->table();
		$entity = new ObjectEntity();
		$this->mapper(user: 'anna')->lockObjectEntity($entity, $register, $schema);
		$this->trace = [];

		$this->expectExceptionMessageMatches('/Object is locked by/');
		try {
			$this->mapper(user: 'bob')->lockObjectEntity($entity, $register, $schema);
		} finally {
			$this->assertSame([], $this->trace);
		}
	}//end testALockHeldBySomeoneElseIsRefusedBeforeAnyWrite()

	public function testAnUnlockByTheHolderIsWrittenAndAnnounced(): void {
		[$register, $schema] = $this->table();
		$entity = new ObjectEntity();
		$this->mapper(user: 'anna')->lockObjectEntity($entity, $register, $schema);
		$this->trace = [];

		$this->mapper(user: 'anna')->unlockObjectEntity($entity, $register, $schema);

		$this->assertNull($entity->getLocked());
		$this->assertSame(['update 1/2 locked=false', 'event ObjectUnlockedEvent'], $this->trace);
	}//end testAnUnlockByTheHolderIsWrittenAndAnnounced()

	public function testAnUnlockByAnotherUserIsRefusedUnlessBroken(): void {
		[$register, $schema] = $this->table();
		$entity = new ObjectEntity();
		$this->mapper(user: 'anna')->lockObjectEntity($entity, $register, $schema);
		$this->trace = [];

		try {
			$this->mapper(user: 'bob')->unlockObjectEntity($entity, $register, $schema);
			$this->fail('An unlock by another user must be refused.');
		} catch (Exception $e) {
			$this->assertStringStartsWith('Object is locked by', $e->getMessage());
		}

		$this->assertSame([], $this->trace);

		$this->mapper(user: 'bob')->unlockObjectEntity($entity, $register, $schema, null, true);
		$this->assertSame(['update 1/2 locked=false', 'event ObjectUnlockedEvent'], $this->trace);
	}//end testAnUnlockByAnotherUserIsRefusedUnlessBroken()

	public function testAnUnlockOfAnUnlockedObjectIsStillWrittenAndAnnounced(): void {
		[$register, $schema] = $this->table();

		$this->mapper(user: null)->unlockObjectEntity(new ObjectEntity(), $register, $schema);
		$this->assertSame(['update 1/2 locked=false', 'event ObjectUnlockedEvent'], $this->trace);
	}//end testAnUnlockOfAnUnlockedObjectIsStillWrittenAndAnnounced()

	public function testABatchHardDeleteRemovesTheRows(): void {
		[$register, $schema] = $this->table();

		$this->assertSame(2, $this->mapper(user: 'anna')->deleteObjectsByUuids($register, $schema, ['a', 'b'], true));
		$this->assertSame(['delete openregister_table_1_2', 'where in:_uuid'], $this->trace);
	}//end testABatchHardDeleteRemovesTheRows()

	public function testABatchSoftDeleteMarksOnlyLiveRows(): void {
		[$register, $schema] = $this->table();

		$this->assertSame(2, $this->mapper(user: 'anna')->deleteObjectsByUuids($register, $schema, ['a', 'b']));
		$this->assertSame(['update openregister_table_1_2', 'set _deleted', 'where in:_uuid', 'andWhere isNull:_deleted'], $this->trace);
	}//end testABatchSoftDeleteMarksOnlyLiveRows()

	public function testABatchDeleteOfNothingOrAMissingTableDeletesNothing(): void {
		[$register, $schema] = $this->table();

		$this->assertSame(0, $this->mapper(user: 'anna')->deleteObjectsByUuids($register, $schema, [], true));
		$this->assertSame(0, $this->mapper(user: 'anna', tableExists: false)->deleteObjectsByUuids($register, $schema, ['a'], true));
		$this->assertSame([], $this->trace);
	}//end testABatchDeleteOfNothingOrAMissingTableDeletesNothing()
}//end class
