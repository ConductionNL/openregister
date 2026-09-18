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

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\MoveObject;
use OCA\OpenRegister\Service\Object\ValidateObject;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Object\MoveObject
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md
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
		$this->audit->method('createAuditTrailEntry')->willReturn(new AuditTrail());

		$this->objects->method('updateObjectEntity')->willReturnCallback(
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
		return new MoveObject($this->objects, $this->validator, $this->audit, new NullLogger());
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
	 * 🔴 The uuid and the minted number come along, untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testTheUuidAndTheNumberComeAlong(): void {
		$object = $this->object();

		$outcome = $this->service()->move(
			object: $object,
			sourceRegister: $this->register(1),
			sourceSchema: $this->schema(10),
			targetRegister: $this->register(2),
			targetSchema: $this->schema(20),
			actor: 'anna',
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
	 * 🔴 The row is written at the target BEFORE it is removed from the source.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-an-object-can-move-between-registers-and-schemas-without-changing-identity
	 */
	public function testTheWriteHappensBeforeTheRemoval(): void {
		$this->service()->move(
			object: $this->object(),
			sourceRegister: $this->register(1),
			sourceSchema: $this->schema(10),
			targetRegister: $this->register(2),
			targetSchema: $this->schema(20),
			actor: 'anna',
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
		$service = new MoveObject($this->objects, $validator, $this->audit, new NullLogger());

		$outcome = $service->move(
			object: $object,
			sourceRegister: $this->register(1),
			sourceSchema: $this->schema(10),
			targetRegister: $this->register(2),
			targetSchema: $this->schema(20),
			actor: 'anna',
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
		$objects->method('updateObjectEntity')->willThrowException(new RuntimeException('target table is gone'));
		$objects->expects(self::never())->method('deleteObjectEntity');

		$object = $this->object();
		$service = new MoveObject($objects, $this->validator, $this->audit, new NullLogger());

		$outcome = $service->move(
			object: $object,
			sourceRegister: $this->register(1),
			sourceSchema: $this->schema(10),
			targetRegister: $this->register(2),
			targetSchema: $this->schema(20),
			actor: 'anna',
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
		$objects->method('updateObjectEntity')->willReturnArgument(0);
		$objects->method('deleteObjectEntity')->willThrowException(new RuntimeException('source table is locked'));

		$service = new MoveObject($objects, $this->validator, $this->audit, new NullLogger());

		$outcome = $service->move(
			object: $this->object(),
			sourceRegister: $this->register(1),
			sourceSchema: $this->schema(10),
			targetRegister: $this->register(2),
			targetSchema: $this->schema(20),
			actor: 'anna',
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
		$objects->method('updateObjectEntity')->willReturnArgument(0);
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

		(new MoveObject($objects, $this->validator, $this->audit, new NullLogger()))->move(
			object: $this->object(),
			sourceRegister: $this->register(1),
			sourceSchema: $this->schema(10),
			targetRegister: $this->register(2),
			targetSchema: $this->schema(20),
			actor: 'anna',
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
		$audit->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = [], ?string $actorId = null) use (&$recorded): AuditTrail {
				$recorded[] = ['action' => $action, 'context' => $context, 'actor' => $actorId];
				return new AuditTrail();
			}
		);

		$service = new MoveObject($this->objects, $this->validator, $audit, new NullLogger());
		$service->move(
			object: $this->object(),
			sourceRegister: $this->register(1),
			sourceSchema: $this->schema(10),
			targetRegister: $this->register(2),
			targetSchema: $this->schema(20),
			actor: 'anna',
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
		$outcome = $this->service()->move(
			object: $this->object(),
			sourceRegister: $this->register(1),
			sourceSchema: $this->schema(10),
			targetRegister: $this->register(1),
			targetSchema: $this->schema(10),
			actor: 'anna',
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

		$verdict = (new MoveObject($this->objects, $validator, $this->audit, new NullLogger()))
			->fits(object: $this->object(), target: $this->schema(20));

		self::assertFalse($verdict['fits']);
		self::assertStringContainsString('could not be checked', $verdict['errors'][0]);
	}
}//end class
