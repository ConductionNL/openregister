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
use OCP\EventDispatcher\IEventDispatcher;
use Opis\JsonSchema\ValidationResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\RevertHandler
 */
final class RevertHandlerWriteGuardsTest extends TestCase {

	private const OBJ = 'obj-4105-0000-0000-0000-000000000001';

	private MagicMapper&MockObject $magic;

	private AuditTrailMapper&MockObject $audit;

	private ValidateObject&MockObject $validator;

	private SettingsService&MockObject $settings;

	private IEventDispatcher&MockObject $events;

	private Schema $schema;

	private ObjectEntity $current;

	private ObjectEntity $reverted;

	/**
	 * Wire a handler over mocks of the real sibling classes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$register = new Register();
		$register->setId(5);
		$this->schema = new Schema();
		$this->schema->setId(7);
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
			['object' => $this->current, 'register' => $register, 'schema' => $this->schema]
		);

		$this->audit = $this->createMock(AuditTrailMapper::class);
		$this->audit->method('revertObject')->willReturn($this->reverted);

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
		$permissions->method('hasPermission')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				'userId' => 'alice',
				SettingsService::class => $this->settings,
				default => throw new \RuntimeException('unexpected service ' . $id),
			}
		);

		return new RevertHandler(
			auditTrailMapper: $this->audit,
			container: $container,
			eventDispatcher: $this->events,
			objectEntityMapper: $this->magic,
			permissionHandler: $permissions,
			validateHandler: $this->validator,
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
			->method('createAuditTrail')
			->with($this->current, $this->reverted, 'revert')
			->willReturn(new AuditTrail());

		$saved = $this->handler()->revert(register: '5', schema: '7', id: self::OBJ, until: '1.0.1');

		$this->assertSame($this->reverted, $saved);
	}//end testRevertWritesARevertAuditRow()

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

		$this->audit->expects($this->never())->method('createAuditTrail');

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, until: '1.0.1');
	}//end testRevertHonoursAuditTrailsDisabled()

	/**
	 * A frozen object refuses a revert as it refuses every other write, and nothing is written.
	 *
	 * @return void
	 */
	public function testFrozenObjectRefusesRevert(): void {
		$this->current->setFrozen(['by' => 'bob', 'at' => '2026-09-01T00:00:00+00:00']);

		$this->magic->expects($this->never())->method('update');
		$this->audit->expects($this->never())->method('revertObject');
		$this->audit->expects($this->never())->method('createAuditTrail');

		$this->expectException(ObjectStateWriteException::class);

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, until: '1.0.1');
	}//end testFrozenObjectRefusesRevert()

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
		$this->audit->expects($this->never())->method('createAuditTrail');

		$this->expectException(ValidationException::class);

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, until: '1.0.1');
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
		$this->audit->method('createAuditTrail')->willReturn(new AuditTrail());

		$this->handler()->revert(register: '5', schema: '7', id: self::OBJ, until: '1.0.1');
	}//end testSoftValidationSchemaSkipsValidation()
}//end class
