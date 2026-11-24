<?php

/**
 * MagicMapper::appendRelation(): the inverse relation write still announces the object's update.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\SystemOperationContext;
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
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicUnaccentProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\SearchFolding
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumnHealer
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableHandler
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacSettings
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicRbacSupport
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableSql
 * @uses   \OCA\OpenRegister\Db\RegisterSchema
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses   \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses   \OCA\OpenRegister\Service\Query\PropertyReadGuard
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowAccess
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowLookups
 * @uses   \OCA\OpenRegister\Service\Query\RelatedRowQueryApplier
 * @uses   \OCA\OpenRegister\Service\Rbac\RuleColumns
 * @uses   \OCA\OpenRegister\Service\Rbac\StoredRuleBlocks
 * @uses   \OCA\OpenRegister\Service\Rbac\UnknownRuleKeyFindings
 * @uses   \OCA\OpenRegister\Service\Search\SearchGrammar
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class MagicMapperAppendRelationEventTest extends TestCase {

	private const UUID = '11111111-2222-3333-4444-555555555555';

	private const SAVED = '99999999-8888-7777-6666-555555555555';

	private const OTHER = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

	/**
	 * Events in the order they were dispatched.
	 *
	 * @var list<object>
	 */
	private array $dispatched = [];

	/**
	 * Arguments of every find() call.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $finds = [];

	/**
	 * The mapper with the column write and the read stubbed.
	 *
	 * @param int                     $rows The rows the column write reports.
	 * @param ObjectEntity|\Throwable $read What find() answers.
	 *
	 * @return MagicMapper&MockObject
	 */
	private function mapper(int $rows, ObjectEntity|\Throwable $read): MagicMapper&MockObject {
		$this->dispatched = [];
		$this->finds      = [];
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->dispatched[] = $event;
			}
		);

		$services = [
			DateTimeNormalizerInterface::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverterInterface::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): ?object => ($services[$id] ?? null));

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs(
				[
					$this->createMock(IDBConnection::class),
					new MagicMapperLookups($this->createMock(SchemaMapper::class), $this->createMock(RegisterMapper::class), $container),
					new MagicMapperIdentity(
						$this->createMock(IUserSession::class),
						$this->createMock(IGroupManager::class),
						$this->createMock(IUserManager::class)
					),
					new MagicMapperRuntime(
						$this->createMock(IConfig::class),
						$this->createMock(IAppConfig::class),
						$events,
						$this->createMock(LoggerInterface::class)
					),
				]
			)
			->onlyMethods(['getTableNameForRegisterSchema', 'appendToRelationsColumn', 'find'])
			->getMock();
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_3_7');
		$mapper->expects($this->once())->method('appendToRelationsColumn')
			->with('openregister_table_3_7', self::UUID, self::SAVED)
			->willReturn($rows);
		$mapper->method('find')->willReturnCallback(
			function (...$args) use ($read): ObjectEntity {
				$this->finds[] = $args;
				if ($read instanceof \Throwable) {
					throw $read;
				}

				return $read;
			}
		);

		return $mapper;
	}//end mapper()

	/**
	 * The register and schema of the related object.
	 *
	 * @return RegisterSchema
	 */
	private function table(): RegisterSchema {
		$register = new Register();
		$register->setId(3);
		$schema = new Schema();
		$schema->setId(7);
		return new RegisterSchema(register: $register, schema: $schema);
	}//end table()

	/**
	 * The related object as read back after the write.
	 *
	 * @return ObjectEntity
	 */
	private function stored(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::UUID);
		$entity->setObject(['title' => 'Related']);
		$entity->setRelations(['owner' => self::OTHER, 0 => self::SAVED]);
		return $entity;
	}//end stored()

	/**
	 * A changed column dispatches ObjectUpdatedEvent: new holds the UUID, old does not.
	 *
	 * @return void
	 */
	public function testAChangedColumnDispatchesTheUpdateEvent(): void {
		$mapper = $this->mapper(rows: 1, read: $this->stored());

		$this->assertSame(1, $mapper->appendRelation(table: $this->table(), uuid: self::UUID, relatedUuid: self::SAVED));

		$this->assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		$this->assertInstanceOf(ObjectUpdatedEvent::class, $event);
		$this->assertSame(['owner' => self::OTHER, 0 => self::SAVED], $event->getNewObject()->getRelations());
		$this->assertSame(['owner' => self::OTHER], $event->getOldObject()->getRelations());
		$this->assertSame('Related', $event->getOldObject()->getObject()['title']);
		$this->assertCount(1, $this->finds);
		$this->assertSame(self::UUID, $this->finds[0]['identifier'] ?? $this->finds[0][0]);
		$this->assertFalse($this->finds[0]['_rbac'] ?? $this->finds[0][4]);
		$this->assertFalse($this->finds[0]['_multitenancy'] ?? $this->finds[0][5]);
	}//end testAChangedColumnDispatchesTheUpdateEvent()

	/**
	 * Nothing written (already related, missing or deleted): no read and no event.
	 *
	 * @return void
	 */
	public function testAnUnchangedColumnDispatchesNothing(): void {
		$mapper = $this->mapper(rows: 0, read: $this->stored());

		$this->assertSame(0, $mapper->appendRelation(table: $this->table(), uuid: self::UUID, relatedUuid: self::SAVED));

		$this->assertSame([], $this->dispatched);
		$this->assertSame([], $this->finds);
	}//end testAnUnchangedColumnDispatchesNothing()

	/**
	 * Inside a system operation the event is withheld, as for every lifecycle event.
	 *
	 * @return void
	 */
	public function testASystemOperationWithholdsTheEvent(): void {
		$mapper = $this->mapper(rows: 1, read: $this->stored());

		$rows = SystemOperationContext::run(
			fn (): int => $mapper->appendRelation(table: $this->table(), uuid: self::UUID, relatedUuid: self::SAVED)
		);

		$this->assertSame(1, $rows);
		$this->assertSame([], $this->dispatched);
	}//end testASystemOperationWithholdsTheEvent()

	/**
	 * A failed read-back keeps the written row and dispatches nothing.
	 *
	 * @return void
	 */
	public function testAFailedReadBackKeepsTheWrite(): void {
		$mapper = $this->mapper(rows: 1, read: new RuntimeException('gone'));

		$this->assertSame(1, $mapper->appendRelation(table: $this->table(), uuid: self::UUID, relatedUuid: self::SAVED));

		$this->assertSame([], $this->dispatched);
	}//end testAFailedReadBackKeepsTheWrite()
}//end class
