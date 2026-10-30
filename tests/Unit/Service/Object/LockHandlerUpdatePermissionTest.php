<?php

/**
 * A user lock needs update permission on the object.
 *
 * A lock refuses every other writer, and one taken without a duration holds
 * until it is unlocked, so a reader who may only read the object must not be
 * able to take it.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\AdvisoryLockStore;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\LockHandler
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
final class LockHandlerUpdatePermissionTest extends TestCase {

	private const OBJ = 'obj-11111111-2222-3333-4444-555555555555';

	private MagicMapper $magic;

	private PermissionHandler $permissions;

	private Schema $schema;

	private ObjectEntity $object;

	/**
	 * Wire a handler over mocks that resolves one object.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->magic = $this->createMock(originalClassName: MagicMapper::class);
		$this->permissions = $this->createMock(originalClassName: PermissionHandler::class);
		$this->schema = $this->createMock(originalClassName: Schema::class);

		$this->object = new ObjectEntity();
		$this->object->setUuid(self::OBJ);
		$this->object->setOwner('owner-uid');

		$this->magic->method('findAcrossAllSources')->willReturn(
			[
				'object' => $this->object,
				'register' => $this->createMock(Register::class),
				'schema' => $this->schema,
			]
		);
	}//end setUp()

	/**
	 * The handler under test.
	 *
	 * @return LockHandler The handler.
	 */
	private function handler(): LockHandler {
		return new LockHandler(
			$this->magic,
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AdvisoryLockStore::class),
			null,
			$this->permissions
		);
	}//end handler()

	/**
	 * A reader without update permission is refused, and nothing is locked.
	 *
	 * @return void
	 */
	public function testAReaderWithoutUpdatePermissionCannotLock(): void {
		$this->permissions->expects($this->once())
			->method('checkPermission')
			->with(
				$this->schema,
				'update',
				null,
				'owner-uid',
				true,
				$this->object
			)
			->willThrowException(new NotAuthorizedException('No update permission'));
		$this->magic->expects($this->never())->method('lockObjectEntity');

		$this->expectException(NotAuthorizedException::class);
		$this->handler()->lock(identifier: self::OBJ);
	}//end testAReaderWithoutUpdatePermissionCannotLock()

	/**
	 * A user who may update the object takes the lock.
	 *
	 * @return void
	 */
	public function testAWriterTakesTheLock(): void {
		$this->permissions->expects($this->once())->method('checkPermission');
		$this->magic->expects($this->once())
			->method('lockObjectEntity')
			->willReturn($this->object);

		$result = $this->handler()->lock(identifier: self::OBJ);

		$this->assertSame(self::OBJ, $result['uuid']);
	}//end testAWriterTakesTheLock()

	/**
	 * A flow run authorizes at its own call site, so the handler does not ask.
	 *
	 * @return void
	 */
	public function testARunLockIsNotAskedAgain(): void {
		$this->permissions->expects($this->never())->method('checkPermission');
		$this->magic->method('lockObjectEntity')->willReturn($this->object);

		$this->handler()->lock(identifier: self::OBJ, runUuid: 'run-1');
	}//end testARunLockIsNotAskedAgain()
}//end class
