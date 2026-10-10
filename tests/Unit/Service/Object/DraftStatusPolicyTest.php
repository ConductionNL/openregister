<?php

/**
 * The explicit lifecycle status `draft`: who may set it, who may leave it, and what it relaxes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-an-object-must-be-able-to-carry-the-explicit-lifecycle-status-draft
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\DraftStatusPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Decision 180: a draft is the destination object, stored with `@self.status: draft`.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftStatusPolicy
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Exception\ValidationException
 */
class DraftStatusPolicyTest extends TestCase {

	private DraftStatusPolicy $policy;

	protected function setUp(): void {
		$this->policy = new DraftStatusPolicy();
		DraftStatusPolicy::resetPromotions();
	}//end setUp()

	/**
	 * An object entity, new (no id) or stored, in a given status.
	 */
	private function entity(?string $status, bool $stored = true): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-1');
		if ($stored === true) {
			$entity->setId(7);
		}

		$entity->setStatus($status);

		return $entity;
	}//end entity()

	/**
	 * A new object may be created as a draft; a stored draft stays one.
	 */
	public function testANewObjectOrADraftMayBeADraft(): void {
		$this->assertSame('draft', $this->policy->resolveStatus(entity: $this->entity(status: null, stored: false), selfData: ['status' => 'draft']));
		$this->assertSame('draft', $this->policy->resolveStatus(entity: $this->entity(status: 'draft'), selfData: ['status' => 'draft']));
	}//end testANewObjectOrADraftMayBeADraft()

	/**
	 * A stored object that is not a draft cannot go back to draft.
	 */
	public function testAStoredObjectCannotBecomeADraft(): void {
		$this->expectException(ValidationException::class);
		$this->policy->resolveStatus(entity: $this->entity(status: null), selfData: ['status' => 'draft']);
	}//end testAStoredObjectCannotBecomeADraft()

	/**
	 * Leaving draft is refused unless the submit allowed it for this object.
	 */
	public function testLeavingDraftNeedsTheSubmit(): void {
		try {
			$this->policy->resolveStatus(entity: $this->entity(status: 'draft'), selfData: ['status' => 'active']);
			$this->fail('A draft left draft without the submit.');
		} catch (ValidationException $refused) {
			$this->assertStringContainsString('submit', $refused->getMessage());
		}

		DraftStatusPolicy::allowPromotion(uuid: 'uuid-1');
		$this->assertSame('active', $this->policy->resolveStatus(entity: $this->entity(status: 'draft'), selfData: ['status' => 'active']));

		// Used once: the allowance does not linger for a later write.
		$this->expectException(ValidationException::class);
		$this->policy->resolveStatus(entity: $this->entity(status: 'draft'), selfData: ['status' => 'active']);
	}//end testLeavingDraftNeedsTheSubmit()

	/**
	 * No status in the payload keeps what is stored; the same status is no change; an unknown one is refused.
	 */
	public function testAbsentSameAndUnknownStatus(): void {
		$this->assertSame('draft', $this->policy->resolveStatus(entity: $this->entity(status: 'draft'), selfData: []));
		$this->assertNull($this->policy->resolveStatus(entity: $this->entity(status: null), selfData: []));
		$this->assertSame('active', $this->policy->resolveStatus(entity: $this->entity(status: 'active'), selfData: ['status' => 'active']));

		$this->expectException(ValidationException::class);
		$this->policy->resolveStatus(entity: $this->entity(status: null, stored: false), selfData: ['status' => 'published']);
	}//end testAbsentSameAndUnknownStatus()

	/**
	 * A write is a draft write when the payload asks for draft, or the stored object is one and the payload does not leave it.
	 */
	public function testIsDraftWrite(): void {
		$this->assertTrue($this->policy->isDraftWrite(object: ['@self' => ['status' => 'draft']], existing: null));
		$this->assertTrue($this->policy->isDraftWrite(object: ['title' => 'x'], existing: $this->entity(status: 'draft')));
		$this->assertFalse($this->policy->isDraftWrite(object: ['@self' => ['status' => 'active']], existing: $this->entity(status: 'draft')));
		$this->assertFalse($this->policy->isDraftWrite(object: ['title' => 'x'], existing: $this->entity(status: null)));
		$this->assertFalse($this->policy->isDraftWrite(object: ['title' => 'x'], existing: null));
	}//end testIsDraftWrite()

	/**
	 * A draft is excused from every required property, list or property-level, and from nothing else.
	 */
	public function testADraftIsExcusedFromRequiredOnly(): void {
		$schema = new Schema();
		$schema->setRequired(['title']);
		$schema->setProperties(['title' => ['type' => 'string'], 'description' => ['type' => 'string', 'required' => true], 'note' => ['type' => 'string']]);

		$this->assertSame(['title' => 'draft', 'description' => 'draft'], $this->policy->requiredExcusals(schema: $schema));
	}//end testADraftIsExcusedFromRequiredOnly()

	/**
	 * Drafts are owner-only unless the schema says otherwise.
	 */
	public function testDraftsAreOwnerOnlyUnlessTheSchemaSaysOtherwise(): void {
		$schema = new Schema();
		$this->assertFalse($this->policy->draftsVisibleToOthers(schema: $schema));

		$schema->setConfiguration(['draftsVisible' => true]);
		$this->assertTrue($this->policy->draftsVisibleToOthers(schema: $schema));
	}//end testDraftsAreOwnerOnlyUnlessTheSchemaSaysOtherwise()

	/**
	 * The SQL clause that hides other people's drafts, for both emitters.
	 */
	public function testTheHiddenDraftClause(): void {
		$this->assertSame("(t._status IS NULL OR t._status <> 'draft')", $this->policy->notADraftSql(columnPrefix: 't.'));
		$this->assertSame("(_status IS NULL OR _status <> 'draft')", $this->policy->notADraftSql(columnPrefix: ''));
	}//end testTheHiddenDraftClause()
}//end class
