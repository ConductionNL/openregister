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
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ObjectStateWriteException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Tests\Support\AuditChangeCalls;
use OCP\EventDispatcher\IEventDispatcher;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\RevertHandler
 * @uses \OCA\OpenRegister\Db\AuditTrail
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
 */
final class RevertHandlerWriteGuardsTest extends TestCase {

	private const OBJ = 'obj-4105-0000-0000-0000-000000000001';

	private MagicMapper&MockObject $magic;

	private AuditTrailMapper&MockObject $audit;

	private ValidateObject&MockObject $validator;

	private SettingsService&MockObject $settings;

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
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getRetentionSettingsOnly')->willReturn(['auditTrailsEnabled' => true]);
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
				SettingsServiceInterface::class => $this->settings,
				default => throw new \RuntimeException('unexpected service ' . $id),
			}
		);

		return new RevertHandler(
			stores: new \OCA\OpenRegister\Service\Object\RevertStores(
				auditTrailMapper: $this->audit,
				objectEntityMapper: $this->magic,
				validateHandler: $this->validator,
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
	 * A successful revert leaves a `revert` row that names the old and the new state.
	 *
	 * @return void
	 */
	public function testRevertWritesARevertAuditRow(): void {
		$this->validator->method('validateObject')->willReturn($this->valid());
		$this->magic->method('update')->willReturnArgument(0);

		$this->audit->expects($this->once())
			->method('recordChange')
			->with(AuditChangeCalls::matching([$this->current, $this->reverted, 'revert']))
			->willReturn(new AuditTrail());

		$saved = $this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));

		$this->assertSame($this->reverted, $saved);
	}//end testRevertWritesARevertAuditRow()

	/**
	 * The audit trail is handed the object the revert checked, not the URL id to look up again.
	 *
	 * @return void
	 */
	public function testTheTrailIsHandedTheCheckedObject(): void {
		$this->validator->method('validateObject')->willReturn($this->valid());
		$this->magic->method('update')->willReturnArgument(0);
		$this->audit->expects($this->once())->method('revertObjectWith')->with($this->identicalTo($this->current), $this->equalTo(new RevertOptions('1.0.1', false)));

		$this->handler()->revert(register: '5', schema: '7', id: '11', point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
	}//end testTheTrailIsHandedTheCheckedObject()

	/**
	 * With audit trails switched off, the revert writes no row, as a save does not.
	 *
	 * @return void
	 */
	public function testRevertHonoursAuditTrailsDisabled(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getRetentionSettingsOnly')->willReturn(['auditTrailsEnabled' => false]);
		$this->validator->method('validateObject')->willReturn($this->valid());
		$this->magic->method('update')->willReturnArgument(0);

		$this->audit->expects($this->never())->method('recordChange');

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
	}//end testRevertHonoursAuditTrailsDisabled()

	/**
	 * A frozen object refuses a revert as it refuses every other write, and nothing is written.
	 *
	 * @return void
	 */
	public function testFrozenObjectRefusesRevert(): void {
		$this->current->setFrozen(['by' => 'bob', 'at' => '2026-09-01T00:00:00+00:00']);

		$this->magic->expects($this->never())->method('update');
<<<<<<< HEAD
		$this->audit->expects($this->never())->method('revertObjectWith');
		$this->audit->expects($this->never())->method('createAuditTrail');
=======
		$this->audit->expects($this->never())->method('revertObject');
		$this->audit->expects($this->never())->method('recordChange');
>>>>>>> origin/cloud/v01-audit-change-1

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

		$this->magic->expects($this->never())->method('update');
<<<<<<< HEAD
		$this->audit->expects($this->never())->method('revertObjectWith');
		$this->audit->expects($this->never())->method('createAuditTrail');
=======
		$this->audit->expects($this->never())->method('revertObject');
		$this->audit->expects($this->never())->method('recordChange');
>>>>>>> origin/cloud/v01-audit-change-1

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

		$this->magic->expects($this->never())->method('update');
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

		$this->magic->expects($this->never())->method('update');
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
		$this->magic->expects($this->once())->method('update')
			->with($this->reverted, $this->register, $this->schema)->willReturnArgument(0);

		$this->assertSame($this->reverted, $this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1')));
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

		$this->magic->expects($this->never())->method('update');
		$this->audit->expects($this->never())->method('recordChange');

		$this->expectException(ValidationException::class);

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
	}//end testRevertedDataIsValidatedAgainstTheCurrentSchema()

	/**
	 * A schema without hard validation is not validated on revert, as on save.
	 *
	 * @return void
	 */
	public function testSoftValidationSchemaSkipsValidation(): void {
		$this->schema->setHardValidation(false);
		$this->validator->expects($this->never())->method('validateObject');
		$this->magic->expects($this->once())->method('update')->willReturnArgument(0);
		$this->audit->method('recordChange')->willReturn(new AuditTrail());

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
	}//end testSoftValidationSchemaSkipsValidation()

	/**
	 * The route may name the register and schema by slug, as every other object route allows (#4161).
	 *
	 * @return void
	 */
	public function testRevertAcceptsRegisterAndSchemaSlugs(): void {
		$this->validator->method('validateObject')->willReturn($this->valid());
		$this->magic->expects($this->once())->method('update')->willReturn($this->reverted);
		$this->audit->method('recordChange')->willReturn(new AuditTrail());

		$saved = $this->handler()->revert(register: 'lp-register', schema: 'lp-item', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));

		$this->assertSame($this->reverted, $saved);
	}//end testRevertAcceptsRegisterAndSchemaSlugs()

	/**
	 * A slug of another register still answers not found.
	 *
	 * @return void
	 */
	public function testRevertRefusesAnotherRegistersSlug(): void {
		$this->magic->expects($this->never())->method('update');

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);

		$this->handler()->revert(register: 'other-register', schema: 'lp-item', id: self::OBJ, point: new \OCA\OpenRegister\Service\Object\RevertPoint(until: '1.0.1'));
	}//end testRevertRefusesAnotherRegistersSlug()
}//end class
