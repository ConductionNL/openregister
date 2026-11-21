<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Only recipients who may read the object pass, and the check fails closed.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-a-watcher-who-lost-access-is-not-told
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Notifications\Service\Notification\ReadableRecipients;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(ReadableRecipients::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(Schema::class)]
class ReadableRecipientsTest extends TestCase {

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setOwner('owner');

		return $object;
	}

	public function testEachUserIsAskedReadOnTheObjectAndOnlyReadersPass(): void {
		$schema = new Schema();
		$object = $this->object();
		$asked = [];
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			static function (
				Schema $s,
				string $action,
				?string $userId = null,
				?string $objectOwner = null,
				bool $_rbac = true,
				?ObjectEntity $object = null
			) use (&$asked): bool {
				$asked[] = [$action, $userId, $objectOwner, $_rbac, $object?->getUuid()];
				return $userId !== 'lost';
			}
		);

		$kept = (new ReadableRecipients(logger: new NullLogger(), permissions: $permissions))->filter(['a', 'lost', 'b'], $object, $schema);

		$this->assertSame(['a', 'b'], $kept);
		$this->assertSame(['read', 'lost', 'owner', true, 'o-1'], $asked[1]);
		$this->assertCount(3, $asked);
	}

	public function testACheckThatThrowsDropsThatUser(): void {
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			static function (Schema $s, string $action, ?string $userId = null): bool {
				if ($userId === 'boom') {
					throw new RuntimeException('no schema');
				}

				return true;
			}
		);

		$kept = (new ReadableRecipients(logger: new NullLogger(), permissions: $permissions))->filter(['boom', 'ok'], $this->object(), new Schema());

		$this->assertSame(['ok'], $kept);
	}

	public function testWithoutAPermissionHandlerNobodyPasses(): void {
		$this->assertSame([], (new ReadableRecipients(logger: new NullLogger()))->filter(['a'], $this->object(), new Schema()));
	}
}//end class
