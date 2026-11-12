<?php

/**
 * The delete guards' question, asked through the stoppable ObjectDeletingEvent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-a-frozen-record-cannot-be-deleted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnSql
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicPgTrgmProbe
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableCreator
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicTableIndexer
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses   \OCA\OpenRegister\Event\ObjectDeletingEvent
 * @uses   \OCA\OpenRegister\Exception\HookStoppedException
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Db\MagicMapper\MagicObjectDelete
 * @uses \OCA\OpenRegister\Db\RegisterSchema
 */
class MagicMapperAssertDeletableTest extends TestCase {

	/**
	 * A mapper whose dispatcher hands each event to the given listener.
	 *
	 * @param callable $listener Receives the dispatched event.
	 *
	 * @return MagicMapper The mapper.
	 */
	private function mapperWith(callable $listener): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback($listener);
		$property = new ReflectionProperty(MagicMapper::class, 'eventDispatcher');
		$property->setValue($mapper, $dispatcher);

		return $mapper;
	}//end mapperWith()

	/**
	 * The guards receive the object itself.
	 *
	 * @return void
	 */
	public function testTheGuardsSeeTheObject(): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-seen');
		$seen = [];
		$mapper = $this->mapperWith(
			static function (object $event) use (&$seen): void {
				$seen[] = $event;
			}
		);

		$mapper->assertDeletable(entity: $object);

		$this->assertCount(1, $seen);
		$this->assertInstanceOf(ObjectDeletingEvent::class, $seen[0]);
		$this->assertSame($object, $seen[0]->getObject());
	}//end testTheGuardsSeeTheObject()

	/**
	 * A guard that stops the event refuses the delete, with its own message and errors.
	 *
	 * @return void
	 */
	public function testAStoppedEventRefusesWithTheGuardsErrors(): void {
		$mapper = $this->mapperWith(
			static function (ObjectDeletingEvent $event): void {
				$event->setErrors(['message' => 'This record is locked by Ada', 'status' => 409]);
				$event->stopPropagation();
			}
		);

		try {
			$mapper->assertDeletable(entity: new ObjectEntity());
			$this->fail('a stopped deleting event must refuse');
		} catch (HookStoppedException $exception) {
			$this->assertSame('This record is locked by Ada', $exception->getMessage());
			$this->assertSame(409, $exception->getErrors()['status']);
		}
	}//end testAStoppedEventRefusesWithTheGuardsErrors()

	/**
	 * A permanent delete asks the same guards before it touches the table.
	 *
	 * @return void
	 */
	public function testAPermanentDeleteAsksTheGuardsBeforeItWrites(): void {
		$mapper = $this->mapperWith(
			static function (ObjectDeletingEvent $event): void {
				$event->setErrors(['message' => 'refused']);
				$event->stopPropagation();
			}
		);

		$this->expectException(HookStoppedException::class);
		$this->expectExceptionMessage('refused');

		$mapper->deleteObjectEntity(
			entity: new ObjectEntity(),
			register: new \OCA\OpenRegister\Db\Register(),
			schema: new \OCA\OpenRegister\Db\Schema(),
			hardDelete: true
		);
	}//end testAPermanentDeleteAsksTheGuardsBeforeItWrites()
}//end class
