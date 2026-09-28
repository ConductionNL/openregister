<?php

/**
 * A revert passes the freeze, the schema and the audit trail (openregister#4105).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
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
 * Before openregister#4105 a revert wrote straight through the mapper: no
 * `revert` audit row, a frozen object could be reverted, and the restored
 * data was never checked against the current schema.
 */
class RevertHandlerGuardsTest extends TestCase {

	private AuditTrailMapper&MockObject $auditTrailMapper;

	private MagicMapper&MockObject $mapper;

	private ValidateObject&MockObject $validator;

	private ObjectEntity $current;

	private Schema $schema;

	protected function setUp(): void {
		parent::setUp();

		$this->current = new ObjectEntity();
		$this->current->setUuid('obj-1');
		$this->current->setRegister('5');
		$this->current->setSchema('7');
		$this->current->setObject(['title' => 'now']);

		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schema->setHardValidation(true);

		$this->mapper = $this->createMock(MagicMapper::class);
		$this->mapper->method('findAcrossAllSources')->willReturnCallback(
			fn (): array => ['object' => $this->current, 'register' => new Register(), 'schema' => $this->schema]
		);

		$reverted = new ObjectEntity();
		$reverted->setUuid('obj-1');
		$reverted->setObject(['title' => 'then']);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->auditTrailMapper->method('revertObject')->willReturn($reverted);

		$this->validator = $this->createMock(ValidateObject::class);
	}//end setUp()

	/**
	 * The handler under test, with the caller allowed to update.
	 *
	 * @return RevertHandler
	 */
	private function handler(): RevertHandler {
		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) {
				if ($id === 'userId') {
					return 'alice';
				}

				throw new \RuntimeException('Not available: '.$id);
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRetentionSettingsOnly')->willReturn(['auditTrailsEnabled' => true]);

		return new RevertHandler(
			$this->auditTrailMapper,
			$container,
			$this->createMock(IEventDispatcher::class),
			$this->mapper,
			$permissions,
			$this->validator,
			$settings
		);
	}//end handler()

	/**
	 * A validation result that says valid or not.
	 *
	 * @param bool $valid The verdict.
	 *
	 * @return ValidationResult
	 */
	private function verdict(bool $valid): ValidationResult {
		$result = $this->createMock(ValidationResult::class);
		$result->method('isValid')->willReturn($valid);

		return $result;
	}//end verdict()

	/**
	 * A revert lands a `revert` row in the audit trail.
	 *
	 * @return void
	 */
	public function testARevertWritesARevertAuditRow(): void {
		$this->validator->method('validateObject')->willReturn($this->verdict(true));
		$this->mapper->method('update')->willReturnArgument(0);

		$this->auditTrailMapper->expects($this->once())
			->method('createAuditTrail')
			->with($this->current, $this->isInstanceOf(ObjectEntity::class), 'revert')
			->willReturn(new AuditTrail());

		$this->handler()->revert(register: '5', schema: '7', id: 'obj-1', until: 3);
	}//end testARevertWritesARevertAuditRow()

	/**
	 * A frozen object is not reverted.
	 *
	 * @return void
	 */
	public function testAFrozenObjectIsNotReverted(): void {
		$this->current->setFrozen(['by' => 'admin', 'at' => '2026-09-01T00:00:00+00:00', 'state' => 'final']);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(ObjectStateWriteException::class);
		$this->handler()->revert(register: '5', schema: '7', id: 'obj-1', until: 3);
	}//end testAFrozenObjectIsNotReverted()

	/**
	 * Data the current schema refuses does not come back.
	 *
	 * @return void
	 */
	public function testRestoredDataTheSchemaRefusesIsNotWritten(): void {
		$this->validator->method('validateObject')->willReturn($this->verdict(false));
		$this->validator->method('generateErrorMessage')->willReturn('title must be longer');
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(ValidationException::class);
		$this->handler()->revert(register: '5', schema: '7', id: 'obj-1', until: 3);
	}//end testRestoredDataTheSchemaRefusesIsNotWritten()
}//end class
