<?php

/**
 * MagicMapperDeleteObjectEntityGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
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

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Exception\HookStoppedException;
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
 * Pins what MagicMapper::deleteObjectEntity() does on the soft and the hard path,
 * and that the delete guards are asked before either touches the table.
 *
 * The mapper is built without its constructor, so the test does not depend on
 * how the constructor is wired.
 */
class MagicMapperDeleteObjectEntityGoldenTest extends TestCase {

	/**
	 * What happened, in order.
	 *
	 * @var list<string>
	 */
	private array $trace = [];

	/**
	 * A hard delete removes the row by uuid and leaves the entity's deleted state alone.
	 *
	 * @return void
	 */
	public function testAHardDeleteRemovesTheRowByUuid(): void {
		$entity = $this->entity(uuid: 'u-1');

		$result = $this->mapper(refuse: false)->deleteObjectEntity(entity: $entity, register: new Register(), schema: new Schema(), hardDelete: true);

		$this->assertSame($entity, $result);
		$this->assertSame(['guard u-1', 'table', 'delete openregister_table_x', 'param u-1', 'where _uuid = :u-1', 'execute'], $this->trace);
		$this->assertSame([], $entity->getDeleted());
	}//end testAHardDeleteRemovesTheRowByUuid()

	/**
	 * A soft delete marks the entity deleted by the current user and writes it back.
	 *
	 * @return void
	 */
	public function testASoftDeleteMarksAndWritesTheEntity(): void {
		$entity = $this->entity(uuid: 'u-2');

		$result = $this->mapper(refuse: false)->deleteObjectEntity(entity: $entity, register: new Register(), schema: new Schema());

		$this->assertSame($entity, $result);
		$this->assertSame(['guard u-2', 'table', 'update u-2 deletedBy=alice'], $this->trace);
		$deleted = $entity->getDeleted();
		$this->assertSame(['alice', 'Soft deleted via MagicMapper', 30], [$deleted['deletedBy'], $deleted['deletedReason'], $deleted['retentionPeriod']]);
	}//end testASoftDeleteMarksAndWritesTheEntity()

	/**
	 * A soft delete of an entity already marked deleted keeps its mark and still writes it.
	 *
	 * @return void
	 */
	public function testASoftDeleteKeepsAnExistingMark(): void {
		$entity = $this->entity(uuid: 'u-3');
		$entity->setDeleted(['deletedBy' => 'bob', 'deletedReason' => 'earlier']);

		$this->mapper(refuse: false)->deleteObjectEntity(entity: $entity, register: new Register(), schema: new Schema(), hardDelete: false);

		$this->assertSame(['guard u-3', 'table', 'update u-3 deletedBy=bob'], $this->trace);
		$this->assertSame(['deletedBy' => 'bob', 'deletedReason' => 'earlier'], $entity->getDeleted());
	}//end testASoftDeleteKeepsAnExistingMark()

	/**
	 * Without events the guards are not asked.
	 *
	 * @return void
	 */
	public function testWithoutEventsTheGuardsAreNotAsked(): void {
		$this->mapper(refuse: true)->deleteObjectEntity(entity: $this->entity(uuid: 'u-4'), register: new Register(), schema: new Schema(), hardDelete: true, dispatchEvents: false);

		$this->assertSame(['table', 'delete openregister_table_x', 'param u-4', 'where _uuid = :u-4', 'execute'], $this->trace);
	}//end testWithoutEventsTheGuardsAreNotAsked()

	/**
	 * A refusing guard stops a soft and a hard delete before the table is touched.
	 *
	 * @return void
	 */
	public function testARefusingGuardStopsBothPaths(): void {
		foreach ([true, false] as $hard) {
			$this->trace = [];
			try {
				$this->mapper(refuse: true)->deleteObjectEntity(entity: $this->entity(uuid: 'u-5'), register: new Register(), schema: new Schema(), hardDelete: $hard);
				$this->fail('a refusing guard must stop the delete');
			} catch (HookStoppedException $e) {
				$this->assertSame('refused', $e->getMessage());
			}

			$this->assertSame(['guard u-5'], $this->trace);
		}
	}//end testARefusingGuardStopsBothPaths()

	/**
	 * An entity without a uuid is refused after the guards, before the table is touched.
	 *
	 * @return void
	 */
	public function testAnEntityWithoutUuidIsRefused(): void {
		try {
			$this->mapper(refuse: false)->deleteObjectEntity(entity: new ObjectEntity(), register: new Register(), schema: new Schema(), hardDelete: true);
			$this->fail('an entity without uuid must be refused');
		} catch (Exception $e) {
			$this->assertSame('Cannot delete object entity without UUID', $e->getMessage());
		}

		$this->assertSame(['guard ', 'table'], $this->trace);
	}//end testAnEntityWithoutUuidIsRefused()

	/**
	 * An entity with a uuid.
	 *
	 * @param string $uuid The uuid
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		return $entity;
	}//end entity()

	/**
	 * A mapper that records the guard, the table lookup, the write-back and the delete query.
	 *
	 * @param bool $refuse Whether the delete guard refuses
	 *
	 * @return MagicMapper
	 */
	private function mapper(bool $refuse): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['getTableNameForRegisterSchema', 'updateObjectEntity'])
			->getMock();
		$mapper->method('getTableNameForRegisterSchema')->willReturnCallback(
			function (): string {
				$this->trace[] = 'table';
				return 'openregister_table_x';
			}
		);
		$mapper->method('updateObjectEntity')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->trace[] = 'update '.$entity->getUuid().' deletedBy='.($entity->getDeleted()['deletedBy'] ?? '');
				return $entity;
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event) use ($refuse): void {
				if ($event instanceof ObjectDeletingEvent) {
					$this->trace[] = 'guard '.$event->getObject()->getUuid();
					if ($refuse === true) {
						$event->setErrors(['message' => 'refused']);
						$event->stopPropagation();
					}
				}
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($column, $value): string => $column.' = '.$value);
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('delete')->willReturnCallback(
			function (string $table) use ($qb): IQueryBuilder {
				$this->trace[] = 'delete '.$table;
				return $qb;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value): string {
				$this->trace[] = 'param '.$value;
				return ':'.$value;
			}
		);
		$qb->method('where')->willReturnCallback(
			function ($predicate) use ($qb): IQueryBuilder {
				$this->trace[] = 'where '.$predicate;
				return $qb;
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function (): int {
				$this->trace[] = 'execute';
				return 1;
			}
		);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		foreach (['eventDispatcher' => $dispatcher, 'userSession' => $session, 'db' => $db, 'logger' => new NullLogger()] as $name => $value) {
			(new ReflectionProperty(MagicMapper::class, $name))->setValue($mapper, $value);
		}

		return $mapper;
	}//end mapper()
}//end class
