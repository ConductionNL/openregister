<?php

/**
 * A revert is a write: it goes past the freeze, the schema and the audit trail
 * exactly as every other write does (#4105).
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

use OCA\OpenRegister\Db\AuditTrail\RevertOptions;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Event\ObjectRevertedEvent;
use OCA\OpenRegister\Service\Object\ObjectUpdateOptions;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\StoredObjectUpdate;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\EventDispatcher\IEventDispatcher;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\RevertHandler
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Event\ObjectRevertedEvent
 * @uses \OCA\OpenRegister\Exception\ObjectStateWriteException
 * @uses \OCA\OpenRegister\Service\Object\FileWriteGuard
 * @uses \OCA\OpenRegister\Service\Object\NotSuppliedHandler
 * @uses \OCA\OpenRegister\Exception\ValidationException
 * @uses \OCA\OpenRegister\Exception\LockedException
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Db\AuditTrail\RevertOptions
 * @uses \OCA\OpenRegister\Exception\ObjectStateMarker
 * @uses \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses \OCA\OpenRegister\Service\Object\RevertPoint
 * @uses \OCA\OpenRegister\Service\Object\RevertStores
 * @uses \OCA\OpenRegister\Service\Object\ObjectUpdateOptions
 * @uses \OCA\OpenRegister\Service\Object\StoredObjectUpdate
 */
final class RevertHandlerWriteGuardsTest extends TestCase {

	private const OBJ = 'obj-4105-0000-0000-0000-000000000001';

	private MagicMapper&MockObject $magic;

	private AuditTrailMapper&MockObject $audit;

	private ValidateObject&MockObject $validator;

	private SaveObject&MockObject $save;

	/**
	 * The update the save path was handed, and its options.
	 *
	 * @var list<array{0: StoredObjectUpdate, 1: ObjectUpdateOptions}>
	 */
	private array $saved = [];

	private IEventDispatcher&MockObject $events;

	private Register $register;

	private Schema $schema;

	private ObjectEntity $current;

	private ObjectEntity $reverted;

	private bool $mayUpdate = true;

	/**
	 * Wire a handler over mocks of the real sibling classes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->register = new Register();
		$this->register->setId(5);
		$this->register->setSlug('lp-register');
		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setSlug('lp-item');
		$this->schema->setHardValidation(true);

		$this->current = new ObjectEntity();
		$this->current->setId(11);
		$this->current->setUuid(self::OBJ);
		$this->current->setRegister('5');
		$this->current->setSchema('7');
		$this->current->setVersion('1.0.3');
		$this->current->setObject(['title' => 'now']);

		$this->reverted = clone $this->current;
		$this->reverted->setVersion('1.0.4');
		$this->reverted->setObject(['title' => 'then']);

		$this->magic = $this->createMock(MagicMapper::class);
		$this->magic->method('findAcrossAllSources')->willReturn(
			['object' => $this->current, 'register' => $this->register, 'schema' => $this->schema]
		);

		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->audit->method('revertObjectWith')->willReturn($this->reverted);

		$this->validator = $this->createMock(ValidateObject::class);
		$this->save = $this->createMock(SaveObject::class);
		$this->save->method('updateObject')->willReturnCallback(
			function (StoredObjectUpdate $update, ObjectUpdateOptions $options): ObjectEntity {
				$this->saved[] = [$update, $options];
				$written = clone $update->existingObject;
				$written->setObject($update->data);

				return $written;
			}
		);
		$this->events = $this->createMock(IEventDispatcher::class);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return RevertHandler
	 */
	private function handler(): RevertHandler {
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			fn (Schema $schema, string $action, ...$rest): bool => ($this->mayUpdate === true && $action === 'update' && $schema === $this->schema && in_array($this->current, $rest, true) === true)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				'userId' => 'alice',
				default => throw new \RuntimeException('unexpected service ' . $id),
			}
		);

		return new RevertHandler(
			stores: new \OCA\OpenRegister\Service\Object\RevertStores(
				auditTrailMapper: $this->audit,
				objectEntityMapper: $this->magic,
				validateHandler: $this->validator,
				saveObject: $this->save,
			),
			container: $container,
			eventDispatcher: $this->events,
			permissionHandler: $permissions,
		);
	}//end handler()

	/**
	 * A valid result from the real Opis result class.
	 *
	 * @return ValidationResult
	 */
	private function valid(): ValidationResult {
		return new ValidationResult(null);
	}//end valid()

	/**
	 * A revert is written as an update of the save path with origin `revert`,
	 * of the stored object with the restored data, never by the mapper directly;
	 * the reverted event follows the write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
	 */
	public function testRevertIsWrittenThroughTheSavePathAsARevert(): void {
		$this->validator->method('validateObject')->willReturn($this->valid());
		$this->magic->expects($this->never())->method('update');
		$this->audit->expects($this->never())->method('createAuditTrail');
		$this->events->expects($this->once())->method('dispatchTyped')->with(
			$this->callback(fn ($event): bool => $event instanceof ObjectRevertedEvent && $event->getRevertPoint() === '1.0.1' && count($this->saved) === 1)
		);

		$saved = $this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));

		$this->assertCount(1, $this->saved);
		[$update, $options] = $this->saved[0];
		$this->assertSame($this->current, $update->existingObject);
		$this->assertSame($this->register, $update->register);
		$this->assertSame($this->schema, $update->schema);
		$this->assertSame('then', $update->data['title']);
		$this->assertSame('revert', $options->origin);
		$this->assertSame(['revertedTo' => '1.0.1', 'revertedToVersion' => '1.0.1'], $options->marks);
		$this->assertNotSame($this->current, $options->oldObject);
		$this->assertSame('now', $options->oldObject->getObject()['title']);
		$this->assertFalse($options->silent);
		$this->assertSame('then', $saved->getObject()['title']);
	}//end testRevertIsWrittenThroughTheSavePathAsARevert()

	/**
	 * A revert to a point in time or an audit trail entry names that point, and no version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
	 */
	public function testARevertToATimeOrAnEntryNamesThatPoint(): void {
		$this->validator->method('validateObject')->willReturn($this->valid());

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: new \DateTime('2026-10-01T12:00:00+00:00')));
		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: 42));

		$this->assertSame(['revertedTo' => '2026-10-01T12:00:00+00:00'], $this->saved[0][1]->marks);
		$this->assertSame(['revertedTo' => '42'], $this->saved[1][1]->marks);
	}//end testARevertToATimeOrAnEntryNamesThatPoint()

	/**
	 * The audit trail is handed the object the revert checked, not the URL id to look up again.
	 *
	 * @return void
	 */
	public function testTheTrailIsHandedTheCheckedObject(): void {
		$this->validator->method('validateObject')->willReturn($this->valid());
		$this->audit->expects($this->once())->method('revertObjectWith')->with($this->identicalTo($this->current), $this->equalTo(new RevertOptions('1.0.1', false)));

		$this->handler()->revert(register: '5', schema: '7', id: '11', point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
	}//end testTheTrailIsHandedTheCheckedObject()

	/**
	 * A point that is not a DateTime, id, version or null is refused, as the strict trail mapper did.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function untypedPoints(): array {
		return ['true' => [true], 'false' => [false], 'one point zero' => [1.0], 'five point five' => [5.5]];
	}//end untypedPoints()

	/**
	 * A boolean or float point throws instead of being coerced to an audit trail id.
	 *
	 * @param mixed $until The point as the request handed it over.
	 *
	 * @return void
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('untypedPoints')]
	public function testAnUntypedRevertPointIsRefused(mixed $until): void {
		$this->audit->expects($this->never())->method('revertObjectWith');

		$this->expectException(\TypeError::class);

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: $until));
	}//end testAnUntypedRevertPointIsRefused()

	/**
	 * A frozen object refuses a revert as it refuses every other write, and nothing is written.
	 *
	 * @return void
	 */
	public function testFrozenObjectRefusesRevert(): void {
		$this->current->setFrozen(['by' => 'bob', 'at' => '2026-09-01T00:00:00+00:00']);

		$this->save->expects($this->never())->method('updateObject');
		$this->audit->expects($this->never())->method('revertObjectWith');

		$this->expectException(ObjectStateWriteException::class);

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
	}//end testFrozenObjectRefusesRevert()

	/**
	 * An archived object refuses a revert until it is restored, and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-an-archived-object-refuses-writes-to-its-data-req-oas-002
	 */
	public function testArchivedObjectRefusesRevert(): void {
		$this->current->setArchived(['by' => 'anna', 'at' => '2026-09-12T00:00:00+00:00', 'reason' => 'closed']);

		$this->save->expects($this->never())->method('updateObject');
		$this->audit->expects($this->never())->method('revertObjectWith');

		try {
			$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
			$this->fail('The revert went through');
		} catch (ObjectStateWriteException $e) {
			$this->assertSame('archived', $e->getState());
		}
	}//end testArchivedObjectRefusesRevert()

	/**
	 * Without update permission on the object the revert is refused before the lock is looked at, and nothing is written.
	 *
	 * @return void
	 */
	public function testNoUpdatePermissionRefusesRevertBeforeTheLock(): void {
		$this->mayUpdate = false;
		$this->current->setLocked(['user' => 'bob', 'expiration' => (new \DateTime('+1 hour'))->format('c')]);

		$this->save->expects($this->never())->method('updateObject');
		$this->audit->expects($this->never())->method('revertObjectWith');

		$this->expectException(\OCA\OpenRegister\Exception\NotAuthorizedException::class);
		$this->expectExceptionMessage('You do not have permission to revert this object');

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
	}//end testNoUpdatePermissionRefusesRevertBeforeTheLock()

	/**
	 * An object locked by someone else refuses a revert, names the holder, and is checked before the freeze.
	 *
	 * @return void
	 */
	public function testALockHeldBySomeoneElseRefusesRevertBeforeTheFreeze(): void {
		$this->current->setLocked(['user' => 'bob', 'expiration' => (new \DateTime('+1 hour'))->format('c')]);
		$this->current->setFrozen(['by' => 'bob', 'at' => '2026-09-01T00:00:00+00:00']);

		$this->save->expects($this->never())->method('updateObject');
		$this->audit->expects($this->never())->method('revertObjectWith');

		$this->expectException(\OCA\OpenRegister\Exception\LockedException::class);
		$this->expectExceptionMessage('Object is locked by bob');

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
	}//end testALockHeldBySomeoneElseRefusesRevertBeforeTheFreeze()

	/**
	 * The caller's own lock does not refuse the revert.
	 *
	 * @return void
	 */
	public function testTheCallersOwnLockAllowsRevert(): void {
		$this->current->setLocked(['user' => 'alice', 'expiration' => (new \DateTime('+1 hour'))->format('c')]);
		$this->validator->method('validateObject')->willReturn($this->valid());

		$saved = $this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));

		$this->assertCount(1, $this->saved);
		$this->assertSame('then', $saved->getObject()['title']);
	}//end testTheCallersOwnLockAllowsRevert()

	/**
	 * Restored data that the current schema no longer allows is refused before it is written.
	 *
	 * @return void
	 */
	public function testRevertedDataIsValidatedAgainstTheCurrentSchema(): void {
		$invalid = $this->createMock(ValidationResult::class);
		$invalid->method('isValid')->willReturn(false);
		$this->validator->expects($this->once())
			->method('validateObject')
			->with($this->callback(fn (array $data): bool => ($data['title'] ?? null) === 'then'), $this->schema)
			->willReturn($invalid);
		$this->validator->method('generateErrorMessage')->willReturn('title is no longer allowed');

		$this->save->expects($this->never())->method('updateObject');
		$this->magic->expects($this->never())->method('update');

		$this->expectException(ValidationException::class);

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
	}//end testRevertedDataIsValidatedAgainstTheCurrentSchema()

	/**
	 * A version from before the schema made a field required is not restored:
	 * the refusal names the field and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/history-revert-through-the-save-path/specs/content-versioning/spec.md
	 */
	public function testAVersionWithoutANowRequiredFieldIsNotRestored(): void {
		$this->schema->setRequired(['title', 'einddatum']);
		$this->reverted->setObject(['title' => 'then']);
		$invalid = $this->createMock(ValidationResult::class);
		$invalid->method('isValid')->willReturn(false);
		$this->validator->expects($this->once())->method('validateObject')
			->with($this->callback(static fn (array $data): bool => ($data['title'] ?? null) === 'then' && array_key_exists('einddatum', $data) === false), $this->schema)
			->willReturn($invalid);
		$this->validator->method('generateErrorMessage')->with($invalid)->willReturn("The required property 'einddatum' is missing");

		$this->save->expects($this->never())->method('updateObject');
		$this->magic->expects($this->never())->method('update');
		$this->events->expects($this->never())->method('dispatchTyped');

		try {
			$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
			$this->fail('A version the schema refuses was restored');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('einddatum', $e->getMessage());
		}

		$this->assertSame('now', $this->current->getObject()['title']);
	}//end testAVersionWithoutANowRequiredFieldIsNotRestored()

	/**
	 * A schema without hard validation is not validated on revert, as on save.
	 *
	 * @return void
	 */
	public function testSoftValidationSchemaSkipsValidation(): void {
		$this->schema->setHardValidation(false);
		$this->validator->expects($this->never())->method('validateObject');

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));

		$this->assertCount(1, $this->saved);
	}//end testSoftValidationSchemaSkipsValidation()

	/**
	 * The route may name the register and schema by slug, as every other object route allows (#4161).
	 *
	 * @return void
	 */
	public function testRevertAcceptsRegisterAndSchemaSlugs(): void {
		$this->validator->method('validateObject')->willReturn($this->valid());

		$saved = $this->handler()->revert(register: 'lp-register', schema: 'lp-item', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));

		$this->assertSame('then', $saved->getObject()['title']);
	}//end testRevertAcceptsRegisterAndSchemaSlugs()

	/**
	 * A slug of another register still answers not found.
	 *
	 * @return void
	 */
	public function testRevertRefusesAnotherRegistersSlug(): void {
		$this->save->expects($this->never())->method('updateObject');

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);

		$this->handler()->revert(register: 'other-register', schema: 'lp-item', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
	}//end testRevertRefusesAnotherRegistersSlug()
}//end class
