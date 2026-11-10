<?php

/**
 * Mutation-killing tests for the authorizer of actions on soft-deleted objects.
 *
 * Written against the mutants Infection left alive in DeletedObjectAuthorizer:
 * no session is never an admin, the permission check runs with RBAC on, a
 * destruction record with an empty actor is nobody's, the readable records
 * come back re-indexed, an unresolvable schema refuses the purge and an
 * empty schema id never reaches the mapper.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Deletion;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizer;
use OCA\OpenRegister\Service\Rbac\CallerContext;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Tests\Unit\Service\Object\PermissionAskArgs;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeletedObjectAuthorizerMutationTest extends TestCase {
	/**
	 * Test double or fixture for schemas.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;

	/**
	 * Test double or fixture for groups.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * Test double or fixture for permissions.
	 *
	 * @var PermissionCheckInterface&MockObject
	 */
	private PermissionCheckInterface&MockObject $permissions;

	protected function setUp(): void {
		$this->schemas     = $this->createMock(SchemaMapperInterface::class);
		$this->groups      = $this->createMock(IGroupManager::class);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
	}//end setUp()

	private function authorizer(?string $uid): DeletedObjectAuthorizer {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new DeletedObjectAuthorizer($this->schemas, new CallerContext($session, $this->groups), $this->permissions);
	}//end authorizer()

	private function record(?string $actor): AuditTrail {
		$record = new AuditTrail();
		$record->setUser($actor);

		return $record;
	}//end record()

	public function testNoSessionIsNeverAdmin(): void {
		$this->groups->method('isAdmin')->willReturn(true);

		$this->assertFalse($this->authorizer(null)->isCurrentUserAdmin());
	}//end testNoSessionIsNeverAdmin()

	public function testThePermissionCheckRunsWithRbacOn(): void {
		$this->groups->method('isAdmin')->willReturn(false);
		$schema = new Schema();
		$this->schemas->method('find')->with(12)->willReturn($schema);
		$object = new ObjectEntity();
		$object->setSchema('12');
		$object->setOwner('eigenaar');
		$this->permissions->expects($this->once())
			->method('hasPermission')
			->with(...PermissionAskArgs::with($schema, 'restore', 'jan', 'eigenaar', true, $object))
			->willReturn(true);

		$this->assertTrue($this->authorizer('jan')->userMayActOnDeletedObject($object, 'restore'));
	}//end testThePermissionCheckRunsWithRbacOn()

	public function testARecordWithAnEmptyActorIsNobodys(): void {
		$this->groups->method('isAdmin')->willReturn(false);

		$this->assertFalse($this->authorizer('')->userMayReadDestructionRecord($this->record('')));
		$this->assertTrue($this->authorizer('jan')->userMayReadDestructionRecord($this->record('jan')));
	}//end testARecordWithAnEmptyActorIsNobodys()

	public function testReadableRecordsComeBackReindexed(): void {
		$this->groups->method('isAdmin')->willReturn(false);
		$own   = $this->record('jan');
		$other = $this->record('piet');
		$also  = $this->record('jan');

		$this->assertSame([$own, $also], $this->authorizer('jan')->readableDestructionRecords([$other, $own, $other, $also]));
	}//end testReadableRecordsComeBackReindexed()

	public function testAnEmptySchemaIdNeverReachesTheMapper(): void {
		$this->schemas->expects($this->never())->method('find');
		$object = new ObjectEntity();
		$object->setSchema('');

		$this->assertNull($this->authorizer('jan')->resolveSchema($object));
	}//end testAnEmptySchemaIdNeverReachesTheMapper()

	public function testAnUnresolvableSchemaRefusesThePurge(): void {
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('gone'));
		$object = new ObjectEntity();
		$object->setSchema('77');

		$refusal = $this->authorizer('jan')->archivalRefusal($object);

		$this->assertNotNull($refusal);
		$this->assertSame('77', $refusal->getSchemaIdentifier());
		$this->assertSame(403, $refusal->getCode());
	}//end testAnUnresolvableSchemaRefusesThePurge()

	public function testAnObjectWithoutASchemaRefusesThePurgeAsUnknown(): void {
		$refusal = $this->authorizer('jan')->archivalRefusal(new ObjectEntity());

		$this->assertNotNull($refusal);
		$this->assertSame('unknown', $refusal->getSchemaIdentifier());
	}//end testAnObjectWithoutASchemaRefusesThePurgeAsUnknown()
}//end class
