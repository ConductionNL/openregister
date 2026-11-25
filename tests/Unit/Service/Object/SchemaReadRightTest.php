<?php

/**
 * SchemaReadRight: the Kernel read-right port answers with the object permission check's read decision.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Object\SchemaReadRight;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-an-owned-webhook-is-limited-to-object-events-in-one-scope
 */
#[CoversClass(SchemaReadRight::class)]
#[UsesClass(Schema::class)]
class SchemaReadRightTest extends TestCase {

	public function testTheReadDecisionIsThePermissionChecks(): void {
		$schema      = new Schema();
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->expects($this->exactly(2))->method('hasPermission')
			->with(
				$this->callback(
					fn (PermissionAsk $ask): bool => $ask->schema === $schema
						&& $ask->action === 'read'
						&& $ask->userId === 'owner'
						&& $ask->objectOwner === null
						&& $ask->object === null
						&& $ask->register === '4'
				),
				true
			)
			->willReturnOnConsecutiveCalls(true, false);

		$right = new SchemaReadRight(permissions: $permissions);

		$this->assertTrue($right->mayRead(schema: $schema, userId: 'owner', register: '4'));
		$this->assertFalse($right->mayRead(schema: $schema, userId: 'owner', register: '4'));
	}//end testTheReadDecisionIsThePermissionChecks()

	public function testASchemaThatIsNotStoredIsRefused(): void {
		$permissions = $this->createMock(PermissionCheckInterface::class);
		$permissions->expects($this->never())->method('hasPermission');

		$this->assertFalse((new SchemaReadRight(permissions: $permissions))->mayRead(schema: $this->createMock(SchemaInterface::class), userId: 'owner', register: null));
	}//end testASchemaThatIsNotStoredIsRefused()
}//end class
