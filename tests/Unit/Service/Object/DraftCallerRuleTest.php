<?php

/**
 * Who a draft is kept from: one rule for every explicit guard.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\DraftCallerRule;
use PHPUnit\Framework\TestCase;

/**
 * Owner, administrator, system caller, anonymous caller.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftCallerRule
 * @uses \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses \OCA\OpenRegister\Service\SystemOperationContext
 */
class DraftCallerRuleTest extends TestCase {

	private DraftCallerRule $rule;

	protected function setUp(): void {
		$this->rule = new DraftCallerRule();
	}//end setUp()

	/**
	 * An object in the given status.
	 *
	 * @param string $status The status.
	 *
	 * @return ObjectEntity The object.
	 */
	private function entity(string $status): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-1');
		$entity->setId(7);
		$entity->setStatus($status);

		return $entity;
	}//end entity()

	/**
	 * The one caller rule: owner and admin pass, a system caller passes, an anonymous or foreign web caller is kept.
	 *
	 * @return void
	 */
	public function testKeptFromCallerIsOneRuleForEveryCaller(): void {
		$draft = $this->entity(status: 'draft');
		$draft->setOwner('alice');
		$schema = new Schema();

		// A foreign signed-in caller is kept; so is an anonymous web caller.
		$this->assertTrue($this->rule->keptFromCaller(object: $draft, schema: $schema, userId: 'bob', isAdmin: false, commandLine: true));
		$this->assertTrue($this->rule->keptFromCaller(object: $draft, schema: $schema, userId: null, isAdmin: false, commandLine: false));
		// The owner, an administrator and the command line without a user are not.
		$this->assertFalse($this->rule->keptFromCaller(object: $draft, schema: $schema, userId: 'alice', isAdmin: false));
		$this->assertFalse($this->rule->keptFromCaller(object: $draft, schema: $schema, userId: 'bob', isAdmin: true));
		$this->assertFalse($this->rule->keptFromCaller(object: $draft, schema: $schema, userId: null, isAdmin: false, commandLine: true));
		// A system operation context passes a web request without a user, and a forced-anonymous evaluation does not.
		\OCA\OpenRegister\Service\SystemOperationContext::run(function () use ($draft, $schema): void {
			$this->assertFalse($this->rule->keptFromCaller(object: $draft, schema: $schema, userId: null, isAdmin: false, commandLine: false));
			\OCA\OpenRegister\Service\AnonymousEvaluationContext::run(function () use ($draft, $schema): void {
				$this->assertTrue($this->rule->keptFromCaller(object: $draft, schema: $schema, userId: null, isAdmin: false, commandLine: true));
			});
		});
		// A schema that shows its drafts, and an object that is no draft, keep nothing from anybody.
		$visible = new Schema();
		$visible->setConfiguration(['draftsVisible' => true]);
		$this->assertFalse($this->rule->keptFromCaller(object: $draft, schema: $visible, userId: 'bob', isAdmin: false));
		$this->assertFalse($this->rule->keptFromCaller(object: $this->entity(status: 'active'), schema: $schema, userId: 'bob', isAdmin: false));
	}//end testKeptFromCallerIsOneRuleForEveryCaller()
}//end class
