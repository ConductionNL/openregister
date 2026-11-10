<?php

/**
 * A move asks the update guards before it writes at the target, through the mapper's own update.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperIdentity;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperLookups;
use OCA\OpenRegister\Db\MagicMapper\MagicMapperRuntime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\DateTimeNormalizerInterface;
use OCA\OpenRegister\Service\Object\MoveAddress;
use OCA\OpenRegister\Service\Object\MoveObject;
use OCA\OpenRegister\Service\Object\ObjectMove;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\Object\SchemaTypeConverterInterface;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Object\MoveObject
 * @covers \OCA\OpenRegister\Service\Object\ObjectMove
 * @uses   \OCA\OpenRegister\Db\MagicMapper
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\ObjectDeletingEvent
 * @uses   \OCA\OpenRegister\Event\ObjectLeavingEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses   \OCA\OpenRegister\Exception\HookStoppedException
 * @uses   \OCA\OpenRegister\Service\Object\FileWriteGuard
 * @uses   \OCA\OpenRegister\Service\Object\MoveAddress
 * @uses   \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
 */
class MoveObjectUpdateGuardsTest extends TestCase {

	/**
	 * The events in the order they were dispatched.
	 *
	 * @var list<object>
	 */
	private array $heard = [];

	/**
	 * The refusal the update guard gives, or null when it lets the write through.
	 *
	 * @var string|null
	 */
	private ?string $refusal = null;

	/**
	 * A move asks the update guards once, with the old object at the source and the new one at the target.
	 *
	 * @return void
	 */
	public function testAMoveAsksTheUpdateGuardsOnceBeforeTheWrite(): void {
		$answer = $this->mover(writes: 1)->move($this->object(), ...$this->addresses());

		$this->assertTrue($answer['moved']);
		$this->assertSame(
			[ObjectLeavingEvent::class, ObjectUpdatingEvent::class, ObjectUpdatedEvent::class],
			array_map(static fn (object $event): string => $event::class, $this->heard)
		);
		$this->assertSame(['10', '20'], [$this->heard[1]->getOldObject()?->getSchema(), $this->heard[1]->getNewObject()->getSchema()]);
	}//end testAMoveAsksTheUpdateGuardsOnceBeforeTheWrite()

	/**
	 * An update guard that refuses keeps the object where it is.
	 *
	 * @return void
	 */
	public function testAnUpdateGuardThatRefusesKeepsTheObject(): void {
		$this->refusal = 'This record is final.';

		$object = $this->object();
		$answer = $this->mover(writes: 0)->move($object, ...$this->addresses());

		$this->assertFalse($answer['moved']);
		$this->assertStringContainsString('This record is final.', $answer['errors'][0]);
		$this->assertSame(['1', '10'], [$object->getRegister(), $object->getSchema()]);
	}//end testAnUpdateGuardThatRefusesKeepsTheObject()

	/**
	 * A move that names the stored row hands it to the update guards as the old object.
	 *
	 * @return void
	 */
	public function testTheUpdateGuardsJudgeTheChangeFromTheStoredRow(): void {
		$stored = $this->object();
		$object = $this->object();
		$object->setObject(['headline' => 'changed']);
		[$sourceRegister, $sourceSchema, $targetRegister, $targetSchema] = $this->addresses();
		$move = new ObjectMove(
			object: $object,
			source: new MoveAddress(register: $sourceRegister, schema: $sourceSchema),
			target: new MoveAddress(register: $targetRegister, schema: $targetSchema),
			actor: ''
		);

		$this->mover(writes: 1)->perform(move: $move->storedAs(stored: $stored));

		$this->assertSame('T', ($this->heard[1]->getOldObject()?->getObject()['title'] ?? null));
		$this->assertSame('changed', ($this->heard[1]->getNewObject()->getObject()['headline'] ?? null));
		$this->assertArrayNotHasKey('title', $this->heard[1]->getNewObject()->getObject());
	}//end testTheUpdateGuardsJudgeTheChangeFromTheStoredRow()

	/**
	 * The mover over the mapper's real update, with the table I/O stubbed.
	 *
	 * @param int $writes How many table writes the test expects.
	 *
	 * @return MoveObject
	 */
	private function mover(int $writes): MoveObject {
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				$this->heard[] = $event;
				if ($event instanceof ObjectUpdatingEvent && $this->refusal !== null) {
					$event->setErrors(['message' => $this->refusal]);
					$event->stopPropagation();
				}
			}
		);

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->setConstructorArgs($this->mapperArguments(events: $events))
			->onlyMethods(['ensureTableForRegisterSchema', 'getTableNameForRegisterSchema', 'saveObjectToRegisterSchemaTable', 'slugForMove', 'deleteObjectEntity'])
			->getMock();
		$mapper->method('getTableNameForRegisterSchema')->willReturn('openregister_table_2_20');
		$mapper->expects($this->exactly($writes))->method('saveObjectToRegisterSchemaTable');
		$mapper->expects($this->exactly($writes))->method('deleteObjectEntity');

		$valid = $this->createMock(ValidationResult::class);
		$valid->method('isValid')->willReturn(true);
		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateObject')->willReturn($valid);

		return new MoveObject(recording: new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($mapper, $this->createMock(AuditTrailMapper::class), new NullLogger(), $events), validator: $validator);
	}//end mover()

	/**
	 * The mapper's constructor arguments, sharing the given dispatcher.
	 *
	 * @param IEventDispatcher $events The dispatcher.
	 *
	 * @return list<object>
	 */
	private function mapperArguments(IEventDispatcher $events): array {
		$services = [
			DateTimeNormalizerInterface::class => $this->createMock(DateTimeNormalizer::class),
			ConditionMatcher::class => $this->createMock(ConditionMatcher::class),
			SchemaTypeConverterInterface::class => $this->createMock(SchemaTypeConverter::class),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): ?object => ($services[$id] ?? null));

		return [
			$this->createMock(IDBConnection::class),
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
				$events,
				new NullLogger()
			),
		];
	}//end mapperArguments()

	/**
	 * An object stored in register 1, schema 10.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-1111-1111-111111111111');
		$object->setRegister('1');
		$object->setSchema('10');
		$object->setObject(['title' => 'T']);
		return $object;
	}//end object()

	/**
	 * Source register 1 and schema 10, target register 2 and schema 20, the actor last.
	 *
	 * @return array{0: Register, 1: Schema, 2: Register, 3: Schema, 4?: string}
	 */
	private function addresses(): array {
		$ids = [[1, new Register()], [10, new Schema()], [2, new Register()], [20, new Schema()]];
		$entities = [];
		foreach ($ids as [$id, $entity]) {
			$entity->setId($id);
			$entities[] = $entity;
		}

		return [...$entities, 'anna'];
	}//end addresses()
}//end class
