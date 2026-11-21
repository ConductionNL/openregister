<?php

/**
 * A bulk caller hides another owner's draft unless it is an admin or a command-line caller without a user.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\BulkCaller;
use OCA\OpenRegister\Service\SystemOperationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\BulkCaller
 * @uses \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Service\Object\DraftSubject
 * @uses \OCA\OpenRegister\Service\Object\DraftCaller
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 */
class BulkCallerTest extends TestCase {

	/**
	 * A stored object.
	 *
	 * @param string $status Its status.
	 * @param string $owner  Its owner.
	 */
	private function object(string $status, string $owner='alice'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setOwner($owner);
		$object->setStatus($status);
		return $object;
	}//end object()

	public function testDefaultsAreUnprivileged(): void {
		$caller = new BulkCaller(userId: 'bob', commandLine: false);

		$this->assertSame('bob', $caller->userId);
		$this->assertFalse($caller->commandLine);
		$this->assertFalse($caller->isAdmin);
		$this->assertFalse($caller->checksRbac);
		$this->assertFalse($caller->checksOrganisation);
		$this->assertNull($caller->organisation);
	}//end testDefaultsAreUnprivileged()

	public function testAnotherUsersDraftIsKeptFromANonAdmin(): void {
		$caller = new BulkCaller(userId: 'bob', commandLine: false);

		$this->assertTrue($caller->keptFromDraft($this->object(ObjectEntity::STATUS_DRAFT), new Schema()));
	}//end testAnotherUsersDraftIsKeptFromANonAdmin()

	public function testOwnDraftAdminAndNonDraftAreOpen(): void {
		$owner = new BulkCaller(userId: 'alice', commandLine: false);
		$admin = new BulkCaller(userId: 'bob', commandLine: false);
		$admin->isAdmin = true;
		$other = new BulkCaller(userId: 'bob', commandLine: false);

		$this->assertFalse($owner->keptFromDraft($this->object(ObjectEntity::STATUS_DRAFT), new Schema()));
		$this->assertFalse($admin->keptFromDraft($this->object(ObjectEntity::STATUS_DRAFT), new Schema()));
		$this->assertFalse($other->keptFromDraft($this->object('published'), new Schema()));
	}//end testOwnDraftAdminAndNonDraftAreOpen()

	public function testDraftsVisibleSchemaOpensTheDraft(): void {
		$schema = new Schema();
		$schema->setConfiguration(['draftsVisible' => true]);
		$caller = new BulkCaller(userId: 'bob', commandLine: false);

		$this->assertFalse($caller->keptFromDraft($this->object(ObjectEntity::STATUS_DRAFT), $schema));
	}//end testDraftsVisibleSchemaOpensTheDraft()

	public function testUserlessCallerIsOpenOnlyOnTheCommandLineOrInASystemOperation(): void {
		$draft = $this->object(ObjectEntity::STATUS_DRAFT);

		$this->assertFalse((new BulkCaller(userId: null, commandLine: true))->keptFromDraft($draft, new Schema()));
		$this->assertTrue((new BulkCaller(userId: null, commandLine: false))->keptFromDraft($draft, new Schema()));
		$this->assertFalse(
			SystemOperationContext::run(
				fn () => (new BulkCaller(userId: null, commandLine: false))->keptFromDraft($draft, new Schema())
			)
		);
	}//end testUserlessCallerIsOpenOnlyOnTheCommandLineOrInASystemOperation()
}//end class
