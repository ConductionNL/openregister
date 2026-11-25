<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The read check for a named user asks the permission handler for `read` with the owner and RBAC on.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-a-watcher-who-lost-access-is-not-told
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\ObjectReadRights;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObjectReadRights::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(PermissionAsk::class)]
#[UsesClass(Schema::class)]
class ObjectReadRightsTest extends TestCase {

	public function testAsksReadAsTheUserWithTheOwnerAndRbac(): void {
		$object = new ObjectEntity();
		$object->setOwner('owner');
		$schema = new Schema();

		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->expects($this->once())->method('hasPermission')
			->with(
				$this->callback(
					fn (PermissionAsk $ask): bool => $ask->schema === $schema
						&& $ask->action === 'read'
						&& $ask->userId === 'alice'
						&& $ask->objectOwner === 'owner'
						&& $ask->object === $object
				),
				true
			)
			->willReturn(true);

		$this->assertTrue((new ObjectReadRights($permissions))->mayRead('alice', $object, $schema));
	}

	public function testARefusalIsFalse(): void {
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturn(false);

		$this->assertFalse((new ObjectReadRights($permissions))->mayRead('bob', new ObjectEntity(), new Schema()));
	}

	public function testAnObjectCoreDoesNotStoreIsNotReadable(): void {
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->expects($this->never())->method('hasPermission');

		$foreign = $this->createMock(ObjectEntityInterface::class);

		$this->assertFalse((new ObjectReadRights($permissions))->mayRead('alice', $foreign, new Schema()));
	}
}
