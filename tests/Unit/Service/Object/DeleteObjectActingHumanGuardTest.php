<?php

/**
 * DeleteObject asks the acting-human guard before anything is deleted (REQ-SAT-006).
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
 *
 * @spec openspec/changes/scoped-api-tokens-machine-callers/specs/auth-system/spec.md#requirement-a-machine-write-names-its-acting-human-when-the-instance-or-schema-requires-it-req-sat-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ActingHumanRefusedException;
use OCA\OpenRegister\Service\Authorization\ActingHumanGuardInterface;
use OCA\OpenRegister\Service\Object\AuditRecording;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\DeletionAttribution;
use OCA\OpenRegister\Service\Object\DeletionIntegrity;
use OCA\OpenRegister\Service\Object\DeletionRequest;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Through deleteObject(): a refused machine delete never reaches the object table.
 *
 * @covers \OCA\OpenRegister\Service\Object\DeleteObject
 */
class DeleteObjectActingHumanGuardTest extends TestCase {

	private MagicMapper&MockObject $objects;

	public function testARefusedMachineDeleteTouchesNothing(): void {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('publicatie');

		$guard = $this->createMock(ActingHumanGuardInterface::class);
		$guard->expects(self::once())->method('assertMayWrite')->with($schema)
			->willThrowException(ActingHumanRefusedException::notDelegated(actingUser: 'p.bakker'));

		$handler = $this->handler(guard: $guard);
		$this->objects->expects(self::never())->method('find');
		$this->objects->expects(self::never())->method('update');
		$this->objects->expects(self::never())->method('delete');

		try {
			$handler->deleteObject(request: new DeletionRequest(register: new Register(), schema: $schema, uuid: 'abc-123'));
			self::fail('a refused machine delete must not proceed');
		} catch (ActingHumanRefusedException $refusal) {
			self::assertSame(['error' => 'acting-human-not-delegated', 'actingUser' => 'p.bakker'], $refusal->toResponseBody());
		}
	}//end testARefusedMachineDeleteTouchesNothing()

	public function testAnUnresolvedSchemaIsAskedAsNone(): void {
		$guard = $this->createMock(ActingHumanGuardInterface::class);
		$guard->expects(self::once())->method('assertMayWrite')->with(null)
			->willThrowException(ActingHumanRefusedException::required());

		$this->expectException(ActingHumanRefusedException::class);
		$this->handler(guard: $guard)->deleteObject(request: new DeletionRequest(register: 4, schema: 'publicatie', uuid: 'abc-123'));
	}//end testAnUnresolvedSchemaIsAskedAsNone()

	/**
	 * The handler with the given guard.
	 *
	 * @param ActingHumanGuardInterface $guard The guard.
	 *
	 * @return DeleteObject
	 */
	private function handler(ActingHumanGuardInterface $guard): DeleteObject {
		$this->objects = $this->createMock(MagicMapper::class);
		$logger        = $this->createMock(LoggerInterface::class);

		return new DeleteObject(
			objectEntityMapper: $this->objects,
			cacheHandler: $this->createMock(CacheHandler::class),
			audit: new AuditRecording($this->createMock(AuditTrailMapper::class), $logger),
			settingsService: $this->createMock(SettingsService::class),
			integrity: new DeletionIntegrity($this->createMock(ReferentialIntegrityService::class), $this->createMock(IDBConnection::class)),
			attribution: new DeletionAttribution($this->createMock(IUserSession::class), $this->createMock(OrganisationMapper::class), $logger),
			actingHuman: $guard
		);
	}//end handler()
}//end class
