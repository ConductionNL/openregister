<?php

/**
 * Handing a stored record to the system identity.
 *
 * Pins that only a system operation may hand a record to the system, that
 * the hand-over writes the system owner and one audit entry and notifies
 * nobody, that a record the system already owns is not written again, and
 * that a configured system identity is the one used.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter;
use OCA\OpenRegister\Service\Rbac\ObjectLookup;
use OCA\OpenRegister\Service\Rbac\ObjectOwnerWriter;
use OCA\OpenRegister\Service\Rbac\ObjectOwnershipService;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCA\OpenRegister\Service\Rbac\OwnedRecord;
use OCA\OpenRegister\Service\Rbac\OwnerAssignment;
use OCA\OpenRegister\Service\Rbac\OwnershipGuards;
use OCA\OpenRegister\Service\Rbac\OwnershipLog;
use OCA\OpenRegister\Service\Rbac\OwnershipSources;
use OCA\OpenRegister\Service\Rbac\OwnershipWriters;
use OCA\OpenRegister\Service\Rbac\PrincipalDirectory;
use OCA\OpenRegister\Service\SystemOperationContext;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ObjectOwnershipReleaseToSystemTest extends TestCase {

	private ObjectOwnerWriter $ownerWriter;

	private AuditTrailMapper $auditTrailMapper;

	private INotificationManager $notificationManager;

	private function service(string $configured = ''): ObjectOwnershipService {
		$this->ownerWriter = $this->createMock(ObjectOwnerWriter::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->auditTrailMapper->method('createAuditTrail')->willReturn($this->createMock(AuditTrail::class));
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($configured);

		return new ObjectOwnershipService(
			guards: new OwnershipGuards(
				userSession: $this->createMock(IUserSession::class),
				grantManage: new GrantManageGuard(new ObjectScopeResolver(), $this->createMock(OrganisationSpecialRights::class)),
				permissions: $this->createMock(PermissionHandler::class)
			),
			sources: new OwnershipSources(
				directory: new PrincipalDirectory(userManager: $this->createMock(IUserManager::class), groupManager: $this->createMock(IGroupManager::class)),
				lookup: new ObjectLookup(magicMapper: $this->createMock(MagicMapper::class), registerMapper: $this->createMock(RegisterMapper::class), schemaMapper: $this->createMock(SchemaMapper::class)),
				appConfig: $config
			),
			writers: new OwnershipWriters(
				ownerWriter: $this->ownerWriter,
				authorizationWriter: $this->createMock(ObjectAuthorizationWriter::class),
				auditTrailMapper: $this->auditTrailMapper,
				notificationManager: $this->notificationManager
			),
			log: new OwnershipLog(logger: $this->createMock(LoggerInterface::class))
		);
	}//end service()

	private static function record(?string $owner): OwnedRecord {
		$object = new ObjectEntity();
		$object->setUuid('9d8c7b6a-5f4e-4d3c-8b2a-1f0e9d8c7b6a');
		$object->setOwner($owner);

		return new OwnedRecord(register: new Register(), schema: new Schema(), object: $object);
	}//end record()

	public function testOutsideASystemOperationTheHandOverIsRefused(): void {
		$service = $this->service();
		$this->ownerWriter->expects($this->never())->method('writeOwner');

		$this->expectException(NotAuthorizedException::class);
		$service->releaseToSystem(record: self::record(owner: 'editor'));
	}//end testOutsideASystemOperationTheHandOverIsRefused()

	public function testInsideASystemOperationTheRecordGoesToTheSystemAndIsAudited(): void {
		$service = $this->service();
		$this->ownerWriter->expects($this->once())->method('writeOwner')->with(
			$this->anything(),
			$this->callback(static fn (OwnerAssignment $assignment): bool => $assignment->owner === '__system__')
		);
		$this->auditTrailMapper->expects($this->once())->method('createAuditTrail')->with(
			$this->callback(static fn (ObjectEntity $old): bool => $old->getOwner() === 'editor'),
			$this->callback(static fn (ObjectEntity $new): bool => $new->getOwner() === '__system__'),
			ObjectOwnershipService::RELEASE_AUDIT_ACTION
		);
		$this->notificationManager->expects($this->never())->method('notify');

		$outcome = SystemOperationContext::run(static fn (): array => $service->releaseToSystem(record: self::record(owner: 'editor')));

		$this->assertTrue($outcome['changed']);
		$this->assertSame('editor', $outcome['previousOwner']);
	}//end testInsideASystemOperationTheRecordGoesToTheSystemAndIsAudited()

	public function testARecordTheSystemOwnsIsNotWrittenAgain(): void {
		$service = $this->service(configured: 'svc-system');
		$this->ownerWriter->expects($this->never())->method('writeOwner');
		$this->auditTrailMapper->expects($this->never())->method('createAuditTrail');

		$outcome = SystemOperationContext::run(static fn (): array => $service->releaseToSystem(record: self::record(owner: 'svc-system')));

		$this->assertFalse($outcome['changed']);
		$this->assertSame('svc-system', $outcome['newOwner']);
	}//end testARecordTheSystemOwnsIsNotWrittenAgain()
}//end class
