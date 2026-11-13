<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Deletion;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\ArchivalImmutableException;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizer;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Acting on a deleted object refuses for an anonymous caller, an unresolvable
 * schema or a failing check, and an archival schema refuses the purge.
 *
 * @covers \OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizer
 * @uses   \OCA\OpenRegister\Service\Rbac\CallerContext
 * @uses   \OCA\OpenRegister\Exception\ArchivalImmutableException
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
final class DeletedObjectAuthorizerFailClosedTest extends TestCase {

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemas;

	/** @var PermissionCheckInterface&MockObject */
	private PermissionCheckInterface $permissions;

	protected function setUp(): void {
		$this->schemas     = $this->createMock(SchemaMapperInterface::class);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
	}

	private function authorizer(?string $uid = 'alice', bool $admin = false): DeletedObjectAuthorizer {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($admin);

		return new DeletedObjectAuthorizer($this->schemas, new CallerContext($session, $groups), $this->permissions);
	}

	private function object(?string $schema = '12'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		$object->setOwner('alice');

		return $object;
	}

	private function record(?string $actor): AuditTrail {
		$record = new AuditTrail();
		$record->setUser($actor);

		return $record;
	}

	public function testAnAnonymousCallerIsNoAdministrator(): void {
		$this->assertFalse($this->authorizer(uid: null, admin: true)->isCurrentUserAdmin());
	}

	public function testAnAnonymousCallerMayNotActOnADeletedObject(): void {
		$this->schemas->expects($this->never())->method('find');

		$this->assertFalse($this->authorizer(uid: null)->userMayActOnDeletedObject($this->object(), 'delete'));
	}

	public function testAnAdministratorMayActWithoutASchemaLookup(): void {
		$this->schemas->expects($this->never())->method('find');

		$this->assertTrue($this->authorizer(admin: true)->userMayActOnDeletedObject($this->object(), 'delete'));
	}

	public function testAnObjectWithoutSchemaRefuses(): void {
		$this->schemas->expects($this->never())->method('find');

		$this->assertFalse($this->authorizer()->userMayActOnDeletedObject($this->object(schema: ''), 'delete'));
	}

	public function testAnUnresolvableSchemaRefuses(): void {
		$this->schemas->method('find')->willThrowException(new RuntimeException('gone'));
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->authorizer()->userMayActOnDeletedObject($this->object(), 'delete'));
	}

	public function testAFailingPermissionCheckRefuses(): void {
		$this->schemas->method('find')->willReturn(new Schema());
		$this->permissions->method('hasPermission')->willThrowException(new RuntimeException('rbac'));

		$this->assertFalse($this->authorizer()->userMayActOnDeletedObject($this->object(), 'delete'));
	}

	public function testTheCheckIsAskedForTheActionOnTheSchemaAsTheCaller(): void {
		$schema = new Schema();
		$object = $this->object();
		$this->schemas->expects($this->once())->method('find')->with(12)->willReturn($schema);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($schema, 'restore', 'alice', 'alice', true, $object, null)
			->willReturn(true);

		$this->assertTrue($this->authorizer()->userMayActOnDeletedObject($object, 'restore'));
	}

	public function testAnAnonymousCallerReadsNoDestructionRecord(): void {
		$this->assertFalse($this->authorizer(uid: null)->userMayReadDestructionRecord($this->record('alice')));
	}

	public function testAnAdministratorReadsEveryDestructionRecord(): void {
		$this->assertTrue($this->authorizer(uid: 'root', admin: true)->userMayReadDestructionRecord($this->record('alice')));
	}

	public function testOnlyTheCallersOwnRecordsAreReadable(): void {
		$own     = $this->record('alice');
		$records = [$this->record('bob'), $own, $this->record(''), $this->record(null)];

		$this->assertSame([$own], $this->authorizer()->readableDestructionRecords($records));
	}

	public function testAnObjectWithoutSchemaResolvesNone(): void {
		$this->assertNull($this->authorizer()->resolveSchema($this->object(schema: null)));
	}

	public function testAnUnresolvableSchemaRefusesThePurge(): void {
		$this->schemas->method('find')->willThrowException(new RuntimeException('gone'));

		$refusal = $this->authorizer()->archivalRefusal($this->object(schema: '12'));

		$this->assertInstanceOf(ArchivalImmutableException::class, $refusal);
		$this->assertSame('12', $refusal->getSchemaIdentifier());
	}

	public function testAnObjectWithoutSchemaRefusesThePurgeAsUnknown(): void {
		$refusal = $this->authorizer()->archivalRefusal($this->object(schema: null));

		$this->assertSame('unknown', $refusal?->getSchemaIdentifier());
	}

	public function testASchemaWithoutArchivalAnnotationAllowsThePurge(): void {
		$this->schemas->method('find')->willReturn(new Schema());

		$this->assertNull($this->authorizer()->archivalRefusal($this->object()));
	}

	public function testAnArchivalSchemaRefusesThePurgeByItsSlug(): void {
		$schema = new Schema();
		$schema->setSlug('besluiten');
		$schema->setConfiguration(['x-openregister-archival' => ['retention' => 'P10Y']]);
		$this->schemas->method('find')->willReturn($schema);

		$refusal = $this->authorizer()->archivalRefusal($this->object());

		$this->assertSame('besluiten', $refusal?->getSchemaIdentifier());
		$this->assertSame(403, $refusal?->getCode());
	}
}
