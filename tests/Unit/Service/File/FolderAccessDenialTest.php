<?php

/**
 * FolderAccessDenial: the audit entry written when a folder bind is refused.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FolderAccessDenial;
use OCA\OpenRegister\Service\File\FolderBindRun;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\File\FolderAccessDenial
 * @uses   \OCA\OpenRegister\Service\File\FolderBindRun
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class FolderAccessDenialTest extends TestCase {

	public function testRecordsTheDeniedFolderUnderTheActingUserAndTheObjectScope(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('3');
		$object->setSchema('7');

		$denial = new FolderAccessDenial(new FolderBindRun('42', $user, $object), 'not shared');
		$entry  = $denial->auditEntry();

		$this->assertInstanceOf(AuditTrail::class, $entry);
		$this->assertSame('alice', $denial->actorUid());
		$this->assertSame('folder_access_denied', $entry->getAction());
		$this->assertSame(['alice', 'alice', '', 0], [$entry->getUser(), $entry->getUserName(), $entry->getSession(), $entry->getObject()]);
		$this->assertSame(['folder' => '42', 'reason' => 'not shared'], $entry->getChanged());
		$this->assertSame(['obj-1', 3, 7], [$entry->getObjectUuid(), $entry->getRegister(), $entry->getSchema()]);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-/', (string)$entry->getUuid());
		$this->assertNotNull($entry->getCreated());
	}

	public function testWithoutAUserOrObjectTheSystemIsRecordedWithoutScope(): void {
		$entry = (new FolderAccessDenial(new FolderBindRun('9', null, 'obj-uuid-only'), 'no object'))->auditEntry();

		$this->assertSame('system', $entry->getUser());
		$this->assertSame(['folder' => '9', 'reason' => 'no object'], $entry->getChanged());
		$this->assertNull($entry->getObjectUuid());
		$this->assertNull($entry->getRegister());
		$this->assertNull($entry->getSchema());
	}

	public function testAnObjectWithoutIdsLeavesTheScopeEmpty(): void {
		$entry = (new FolderAccessDenial(new FolderBindRun('9', null, new ObjectEntity()), 'r'))->auditEntry();

		$this->assertNull($entry->getRegister());
		$this->assertNull($entry->getSchema());
	}
}
