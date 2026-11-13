<?php

/**
 * An object moves, and stays the same object.
 *
 * 🔴 THE PROPERTY THAT MATTERS IS THAT NOTHING IS MINTED. Every side table —
 * the audit trail, the versions, the files, the notes, the watchers, the
 * favourites, the presence, the timers — is keyed on the uuid. A "move" that
 * wrote a new object would orphan all of them silently, and the object would
 * look fine: it would simply have no history, which is exactly what closing and
 * refiling does today and exactly what this change exists to stop.
 *
 * 🔴 THE ORDER OF WRITES IS THE SAFETY, AND IT IS ASSERTED AS AN ORDER. Write
 * to the target, then remove from the source. Removing first and failing to
 * write loses the object; writing first and failing to remove leaves it
 * readable at both addresses, which is visible, reversible and reported. A test
 * that only checked "both calls happened" passes on the dangerous order.
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

use OCA\OpenRegister\Service\Object\ObjectMove;
use OCA\OpenRegister\Service\Object\MoveAddress;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use OCA\OpenRegister\Service\Object\MoveObject;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Tests\Support\AuditTrailEvents;
use OCP\EventDispatcher\IEventDispatcher;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Object\MoveObject
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Object\MoveAddress
 * @uses \OCA\OpenRegister\Service\Object\ObjectMove
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
 * @uses \OCA\OpenRegister\Db\AuditTrail\NamedActor
 * @uses \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent
 * @uses \OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions
 * @uses \OCA\OpenRegister\Db\RegisterSchema
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses \OCA\OpenRegister\Exception\ObjectStateMarker
 * @uses \OCA\OpenRegister\Exception\ObjectStateWriteException
 * @uses \OCA\OpenRegister\Service\Object\FileWriteGuard
 * @uses \OCA\OpenRegister\Service\Object\ObjectChangeRecording
 */
final class MoveObjectTest extends TestCase {

	private const UUID = 'obj-2026-0042';

	private MagicMapper&MockObject $objects;

	private ValidateObject&MockObject $validator;

	private AuditTrailMapper&MockObject $audit;

	/**
	 * What the mapper was asked to do, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A mapper that records the order it was called in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->calls = [];
		$this->objects = $this->createMock(MagicMapper::class);
		$this->validator = $this->createMock(ValidateObject::class);
		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->audit->method('recordObjectEvent')->willReturn(new AuditTrail());

		$this->objects->method('update')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->calls[] = 'write';
				return $entity;
			}
		);
		$this->objects->method('deleteObjectEntity')->willReturnCallback(
			function (ObjectEntity $entity): ObjectEntity {
				$this->calls[] = 'remove';
				return $entity;
			}
		);

		$this->validator->method('validateObject')->willReturn(new ValidationResult(null));
	}

	/**
	 * The service under test.
	 *
	 * @return MoveObject The service.
	 */
	private function service(): MoveObject {
		return new MoveObject(new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($this->objects, $this->audit, new NullLogger(), $this->createStub(IEventDispatcher::class)), $this->validator);
	}

	/**
	 * A register with an id.
	 *
	 * @param int $id The id.
	 *
	 * @return Register The register.
	 */
	private function register(int $id): Register {
		$register = new Register();
		$register->setId($id);

		return $register;
	}

	/**
	 * A schema with an id and its properties.
	 *
	 * @param int                  $id         The id.
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, array $properties = []): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setProperties($properties);

		return $schema;
	}

	/**
	 * The object being moved, carrying a minted number and a title.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid(self::UUID);
		$object->setRegister(1);
		$object->setSchema(10);
		$object->setObject(['identifier' => '2026-0042', 'title' => 'Dakkapel Kerkstraat 12']);

		return $object;
	}

	/**
	 * States that keep an object where it is, whoever asks to move it.
	 *
	 * @return array<string, array{0: string, 1: array}>
	 */
	public static function keptStates(): array {
		return [
			'locked by someone else' => ['setLocked', ['user' => 'bert']],
			'locked by the mover' => ['setLocked', ['user' => 'anna']],
			'locked by a run' => ['setLocked', ['kind' => 'run', 'runUuid' => 'run-1']],
			'archived' => ['setArchived', ['date' => '2026-01-01']],
			'frozen' => ['setFrozen', ['date' => '2026-01-01']],
		];
	}

	/**
	 * A kept object is refused before anything is written or removed.
	 *
	 * @param string $setter The state's setter.
	 * @param array  $state  The state.
	 *
	 * @return void
	 */
	#[DataProvider('keptStates')]
	public function testAKeptObjectStaysWhereItIs(string $setter, array $state): void {
		$object = $this->object();
		$object->$setter($state);

		$outcome = $this->service()->perform(
			move: new ObjectMove(
				object: $object,
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);

		self::assertFalse($outcome['moved']);
		self::assertSame([], $this->calls);
	}

	/**
	 * A guard's refusal keeps the object and is what the caller reads.
	 *
	 * @return void
	 */
	public function testAValueOthersHoldStaysWhereItIs(): void {
		$heard = [];
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$heard): void {
				$heard[] = $event;
				$event->setErrors(['message' => 'Records hold this value.']);
				$event->stopPropagation();
			}
		);
		$object = $this->object();

		$outcome = (new MoveObject(new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($this->objects, $this->audit, new NullLogger(), $events), $this->validator))->perform(
			move: new ObjectMove(
				object: $object,
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);

		self::assertFalse($outcome['moved']);
		self::assertSame(['Records hold this value.'], $outcome['errors']);
		self::assertSame([], $this->calls);
		self::assertCount(1, $heard);
		self::assertSame(ObjectLeavingEvent::class, $heard[0]::class);
		self::assertSame($object, $heard[0]->getObject());
		self::assertSame('10', $heard[0]->getObject()->getSchema());
	}

	/**
	 * A guard that gives no words still refuses.
	 *
	 * @return void
	 */
	public function testARefusalWithoutWordsStillKeepsTheObject(): void {
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			static function (ObjectLeavingEvent $event): void {
				$event->stopPropagation();
			}
		);

		$reason = (new MoveObject(new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($this->objects, $this->audit, new NullLogger(), $events), $this->validator))
			->keptReason(object: $this->object());

		self::assertSame('Other records still need this object where it is, so it was not moved.', $reason);
	}

	/**
	 * A locked object is not moved, also not by the lock holder, and the move path and the migration path say why.
	 *
	 * @return void
	 */
	public function testALockedObjectIsNotMovedByItsHolderEither(): void {
		$object = $this->object();
		$object->setLocked(['user' => 'anna']);
		$service = $this->service();

		$outcome = $service->perform(
			move: new ObjectMove(
				object: $object,
				source: new MoveAddress(register: $this->register(id: 1), schema: $this->schema(id: 10)),
				target: new MoveAddress(register: $this->register(id: 2), schema: $this->schema(id: 20)),
				actor: 'anna'
			)
		);

		self::assertFalse(condition: $outcome['moved']);
		self::assertSame(expected: ['This object is locked, so it was not moved.'], actual: $outcome['errors']);
		self::assertSame(expected: 'This object is locked, so it was not moved.', actual: $service->keptReason(object: $object));
	}

	/**
	 * The state markers that keep an object, as the migration path asks them.
	 *
	 * @param string $setter The state's setter.
	 * @param array  $state  The state.
	 *
	 * @return void
	 */
	#[DataProvider('keptStates')]
	public function testAMigrationKeepsAKeptObject(string $setter, array $state): void {
		$object = $this->object();
		$object->$setter($state);

		$reason = $this->service()->keptReason(object: $object);

		self::assertNotNull(actual: $reason);
		// The migration path gives the same reason a move refuses with.
		$outcome = $this->service()->perform(
			move: new ObjectMove(
				object: $object,
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);
		self::assertSame(expected: [$reason], actual: $outcome['errors']);
	}

	/**
	 * The write hands the mapper the object as it was, source address included.
	 *
	 * The target table has no row yet, so a write left to read the old entity
	 * from the target fails with "Object not found in magic table".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testTheWriteCarriesTheOldEntityFromTheSource(): void {
		$seen = null;
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('update')->willReturnCallback(
			function (ObjectEntity $entity, Register $register, Schema $schema, ?ObjectEntity $old) use (&$seen): ObjectEntity {
				$seen = $old;
				return $entity;
			}
		);

		$outcome = (new MoveObject(new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($objects, $this->audit, new NullLogger(), $this->createStub(IEventDispatcher::class)), $this->validator))->perform(
			move: new ObjectMove(
				object: $this->object(),
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);

		self::assertTrue($outcome['moved']);
		self::assertNotNull($seen);
		self::assertSame(1, (int)$seen->getRegister());
		self::assertSame(10, (int)$seen->getSchema());
	}

	/**
	 * An object that lives elsewhere is refused: its row would stay and a second one appear.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testAnObjectThatDoesNotLiveAtTheSourceIsRefused(): void {
		foreach ([[3, 10], [1, 30]] as [$register, $schema]) {
			$object = $this->object();
			$object->setRegister($register);
			$object->setSchema($schema);

			$outcome = $this->service()->perform(
				move: new ObjectMove(
					object: $object,
					source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
					target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
					actor: 'anna'
				)
			);

			self::assertFalse($outcome['moved']);
			self::assertSame([], $this->calls, 'nothing is written or removed');
		}
	}

	/**
	 * 🔴 The uuid and the minted number come along, untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testTheUuidAndTheNumberComeAlong(): void {
		$object = $this->object();

		$outcome = $this->service()->perform(
			move: new ObjectMove(
				object: $object,
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);

		self::assertTrue($outcome['moved']);
		self::assertSame(self::UUID, $outcome['uuid']);
		self::assertSame(self::UUID, $object->getUuid(), 'the identity every side table is keyed on');
		self::assertSame('2026-0042', $object->getObject()['identifier'], 'a number is minted once');
		// CAST, because the entity types both columns as strings: the move
		// writes ints and reads back '2'. Asserting the int would be asserting
		// the entity's casting rather than the move's behaviour.
		self::assertSame(2, (int)$object->getRegister());
		self::assertSame(20, (int)$object->getSchema());
	}

	/**
	 * Review finding (Robert, 30-10): the slug is re-checked in the target before the
	 * write, and a failed write puts the source slug back.
	 *
	 * @return void
	 */
	public function testTheSlugIsDecidedForTheTargetBeforeTheWrite(): void {
		$object = $this->object();
		$object->setSlug('hello-world');
		$seen = 'unset';
		$objects = $this->createMock(MagicMapper::class);
		$objects->expects(self::once())->method('slugForMove')
			->with($object, self::callback(fn (RegisterSchema $t) => (int)$t->register->getId() === 2 && (int)$t->schema->getId() === 20))
			->willReturn(null);
		$objects->method('update')->willReturnCallback(
			function (ObjectEntity $entity) use (&$seen): ObjectEntity {
				$seen = $entity->getSlug();
				throw new \RuntimeException('disk full');
			}
		);

		$outcome = (new MoveObject(new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($objects, $this->audit, new NullLogger(), $this->createStub(IEventDispatcher::class)), $this->validator))->perform(
			move: new ObjectMove(
				object: $object,
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);

		self::assertNull($seen, 'the target write carries the decided slug');
		self::assertFalse($outcome['moved']);
		self::assertSame('hello-world', $object->getSlug(), 'a failed write puts the source slug back');
	}

	/**
	 * 🔴 The row is written at the target BEFORE it is removed from the source.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testTheWriteHappensBeforeTheRemoval(): void {
		$this->service()->perform(
			move: new ObjectMove(
				object: $this->object(),
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);

		self::assertSame(
			['write', 'remove'],
			$this->calls,
			'removing first and failing to write loses the object'
		);
	}

	/**
	 * 🔴 A target the object does not fit refuses, and NOTHING is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testATargetThatDoesNotFitRefusesAndWritesNothing(): void {
		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateObject')->willThrowException(new RuntimeException('bouwjaar is required'));

		$object = $this->object();
		$service = new MoveObject(new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($this->objects, $this->audit, new NullLogger(), $this->createStub(IEventDispatcher::class)), $validator);

		$outcome = $service->perform(
			move: new ObjectMove(
				object: $object,
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);

		self::assertFalse($outcome['moved']);
		self::assertStringContainsString('bouwjaar', $outcome['errors'][0]);
		self::assertSame([], $this->calls, 'the object is unchanged');
		self::assertSame(1, (int)$object->getRegister(), 'and still lives where it did');
	}

	/**
	 * 🔴 A failed WRITE leaves the entity pointing where it really is.
	 *
	 * A caller that keeps using the object must not be holding one that claims
	 * to live somewhere it does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testAFailedWriteRollsTheEntityBack(): void {
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('update')->willThrowException(new RuntimeException('target table is gone'));
		$objects->expects(self::never())->method('deleteObjectEntity');

		$object = $this->object();
		$service = new MoveObject(new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($objects, $this->audit, new NullLogger(), $this->createStub(IEventDispatcher::class)), $this->validator);

		$outcome = $service->perform(
			move: new ObjectMove(
				object: $object,
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);

		self::assertFalse($outcome['moved']);
		self::assertSame(1, (int)$object->getRegister());
		self::assertSame(10, (int)$object->getSchema());
	}

	/**
	 * 🔴 A failed REMOVAL still reports the move, and says the old row survived.
	 *
	 * The object is readable at its new address; the old row is a duplicate of
	 * the same object, and somebody has to be told it is there. Reporting the
	 * move as a failure would be worse: it moved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testAFailedRemovalStillReportsTheMoveAndNamesTheStrandedRow(): void {
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('update')->willReturnArgument(0);
		$objects->method('deleteObjectEntity')->willThrowException(new RuntimeException('source table is locked'));

		$service = new MoveObject(new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($objects, $this->audit, new NullLogger(), $this->createStub(IEventDispatcher::class)), $this->validator);

		$outcome = $service->perform(
			move: new ObjectMove(
				object: $this->object(),
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);

		self::assertTrue($outcome['moved'], 'it moved');
		self::assertStringContainsString('both addresses', $outcome['errors'][0]);
	}

	/**
	 * 🔴 The removal is HARD and silent.
	 *
	 * A soft delete leaves a tombstone the trash offers to restore into a table
	 * the object no longer belongs in, and a delete event tells eight listening
	 * apps that an object they can still read was deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testTheSourceRowIsHardDeletedWithNoEvents(): void {
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('update')->willReturnArgument(0);
		$objects->expects(self::once())
			->method('deleteObjectEntity')
			->with(
				self::anything(),
				self::anything(),
				self::anything(),
				self::isTrue(),
				self::isFalse()
			)
			->willReturnArgument(0);

		(new MoveObject(new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($objects, $this->audit, new NullLogger(), $this->createStub(IEventDispatcher::class)), $this->validator))->perform(
			move: new ObjectMove(
				object: $this->object(),
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);
	}

	/**
	 * 🔴 One `moved` entry, naming BOTH addresses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testOneMovedEntryNamesBothAddresses(): void {
		$recorded = [];
		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			function (ObjectEntity $object, string $action, array $context = [], ?string $actorId = null) use (&$recorded): AuditTrail {
				$recorded[] = ['action' => $action, 'context' => $context, 'actor' => $actorId];
				return new AuditTrail();
			}
		));

		$service = new MoveObject(new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($this->objects, $audit, new NullLogger(), $this->createStub(IEventDispatcher::class)), $this->validator);
		$service->perform(
			move: new ObjectMove(
				object: $this->object(),
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(2), schema: $this->schema(20)),
				actor: 'anna'
			)
		);

		self::assertCount(1, $recorded);
		self::assertSame(MoveObject::ACTION, $recorded[0]['action']);
		self::assertSame(['register' => 1, 'schema' => 10], $recorded[0]['context']['from']);
		self::assertSame(['register' => 2, 'schema' => 20], $recorded[0]['context']['to']);
		self::assertSame('anna', $recorded[0]['actor']);
	}

	/**
	 * Moving an object to where it already is writes nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testAMoveToWhereItAlreadyIsIsRefused(): void {
		$outcome = $this->service()->perform(
			move: new ObjectMove(
				object: $this->object(),
				source: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				target: new MoveAddress(register: $this->register(1), schema: $this->schema(10)),
				actor: 'anna'
			)
		);

		self::assertFalse($outcome['moved']);
		self::assertSame([], $this->calls);
	}

	/**
	 * 🔴 The generated properties of the target are the ones excluded.
	 *
	 * They are excluded from "must be absent on create" and NOT from validation:
	 * the value still has to be the right shape, it simply does not have to be
	 * missing. Dropping them from validation entirely would let a move carry a
	 * number into a property the target declares as a date.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testTheGeneratedPropertiesOfTheTargetAreNamed(): void {
		$target = $this->schema(
			20,
			[
				'identifier' => ['type' => 'string', MoveObject::GENERATED => ['strategy' => 'sequence']],
				'title' => ['type' => 'string'],
			]
		);

		// KEYED BY NAME, because `NotSuppliedHandler::excuse()` reads
		// `array_keys()`: a list excuses the properties called `0` and `1`.
		self::assertSame(
			['identifier' => MoveObject::GENERATED],
			$this->service()->generatedProperties(schema: $target)
		);
	}

	/**
	 * A validation that cannot run is not a pass.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testAnUnrunnableValidationRefusesRatherThanPassing(): void {
		$validator = $this->createMock(ValidateObject::class);
		$validator->method('validateObject')->willThrowException(new RuntimeException('schema is unreadable'));

		$verdict = (new MoveObject(new \OCA\OpenRegister\Service\Object\ObjectChangeRecording($this->objects, $this->audit, new NullLogger(), $this->createStub(IEventDispatcher::class)), $validator))
			->fits(object: $this->object(), target: $this->schema(20));

		self::assertFalse($verdict['fits']);
		self::assertStringContainsString('could not be checked', $verdict['errors'][0]);
	}
}//end class
