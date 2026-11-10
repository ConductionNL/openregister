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
 * @uses \OCA\OpenRegister\Exception\DraftAlreadyLeftException
 * @uses \OCA\OpenRegister\Service\Form\FormDestinationValidator
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
	 * What the server fills is not the caller's to supply: a default, a computation, the serverSet marker.
	 */
	public function testServerFilledPropertiesAreExcusedWhenAbsent(): void {
		$schema = new Schema();
		$schema->setProperties(
			[
				'title' => ['type' => 'string'],
				'priority' => ['type' => 'string', 'default' => 'normal'],
				'total' => ['type' => 'number', 'computed' => ['expression' => 'a + b']],
				'number' => ['type' => 'string', 'x-openregister' => ['serverSet' => true]],
				'reference' => ['type' => 'string', 'x-openregister' => ['serverSet' => false]],
				'caseNumber' => ['type' => 'string', 'x-openregister-generated' => ['sequence' => 'case', 'format' => 'Z-{year}-{seq:4}']],
			]
		);

		$this->assertSame(
			['priority' => 'server-set', 'total' => 'server-set', 'number' => 'server-set', 'caseNumber' => 'server-set'],
			$this->policy->serverFilledExcusals(schema: $schema, object: ['title' => 'x'])
		);
		// A value the caller did supply is judged like any other.
		$this->assertSame(['total' => 'server-set', 'caseNumber' => 'server-set'], $this->policy->serverFilledExcusals(schema: $schema, object: ['priority' => 'high', 'number' => 'T-1']));
	}//end testServerFilledPropertiesAreExcusedWhenAbsent()

	/**
	 * A draft lifetime is 30 days unless the schema sets another number of days or opts out with false (decision 228).
	 */
	public function testTheDraftLifetimeIsReadFromTheSchema(): void {
		$schema = new Schema();
		$this->assertSame(30, DraftStatusPolicy::DEFAULT_LIFETIME_DAYS);
		$this->assertSame(30, $this->policy->draftLifetimeDays(schema: $schema));

		$schema->setConfiguration(['draftLifetimeDays' => 7]);
		$this->assertSame(7, $this->policy->draftLifetimeDays(schema: $schema));
		$this->assertSame('draftLifetimeDays', DraftStatusPolicy::LIFETIME_KEY);

		// Another draft setting does not change the lifetime.
		$schema->setConfiguration(['draftsVisible' => true]);
		$this->assertSame(30, $this->policy->draftLifetimeDays(schema: $schema));

		// The explicit opt-out: this schema keeps its drafts.
		$schema->setConfiguration(['draftLifetimeDays' => false]);
		$this->assertNull($this->policy->draftLifetimeDays(schema: $schema));

		// A value the schema refused is stored as the opt-out, so a typing error keeps drafts
		// and never reads as "remove every draft now" or "remove after 30 days".
		$schema->setConfiguration(['draftLifetimeDays' => 0]);
		$this->assertNull($this->policy->draftLifetimeDays(schema: $schema));
	}//end testTheDraftLifetimeIsReadFromTheSchema()

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
	 * A draft is kept from everybody but its owner, on a schema that keeps drafts to their owner; nothing else is.
	 */
	public function testADraftIsKeptFromEverybodyButItsOwner(): void {
		$schema = new Schema();
		$draft = new ObjectEntity();
		$draft->setOwner('agent');
		$draft->setStatus('draft');

		$this->assertTrue($this->policy->keptFrom(object: $draft, schema: $schema, userId: 'colleague'));
		$this->assertTrue($this->policy->keptFrom(object: $draft, schema: $schema, userId: null));
		$this->assertFalse($this->policy->keptFrom(object: $draft, schema: $schema, userId: 'agent'));

		// Control: an object that is no draft is nobody's to keep.
		$record = new ObjectEntity();
		$record->setOwner('agent');
		$record->setStatus('active');
		$this->assertFalse($this->policy->keptFrom(object: $record, schema: $schema, userId: 'colleague'));

		// Control: a schema that shows its drafts to others keeps none.
		$schema->setConfiguration(['draftsVisible' => true]);
		$this->assertFalse($this->policy->keptFrom(object: $draft, schema: $schema, userId: 'colleague'));
	}//end testADraftIsKeptFromEverybodyButItsOwner()

	/**
	 * A schema's lists show the caller their own drafts unless it says `draftsListed: false`.
	 */
	public function testOwnDraftsAreListedUnlessTheSchemaSaysOtherwise(): void {
		$schema = new Schema();
		$this->assertTrue($this->policy->ownDraftsListed(schema: $schema));

		$schema->setConfiguration(['draftsListed' => true]);
		$this->assertTrue($this->policy->ownDraftsListed(schema: $schema));

		$schema->setConfiguration(['draftsListed' => false]);
		$this->assertFalse($this->policy->ownDraftsListed(schema: $schema));
	}//end testOwnDraftsAreListedUnlessTheSchemaSaysOtherwise()

	/**
	 * The SQL clause that hides other people's drafts, for both emitters.
	 */
	public function testTheHiddenDraftClause(): void {
		$this->assertSame("(t._status IS NULL OR t._status <> 'draft')", $this->policy->notADraftSql(columnPrefix: 't.'));
		$this->assertSame("(_status IS NULL OR _status <> 'draft')", $this->policy->notADraftSql(columnPrefix: ''));
	}//end testTheHiddenDraftClause()

	/**
	 * An allowance for an object that is no longer a draft is refused, so a parallel request that lost the race writes nothing.
	 *
	 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-creation-side-effects-must-be-held-for-a-draft-and-run-once-when-it-leaves-draft
	 */
	public function testAnAllowanceForAnObjectThatAlreadyLeftDraftIsRefused(): void {
		DraftStatusPolicy::allowPromotion(uuid: 'uuid-1');

		$this->expectException(\OCA\OpenRegister\Exception\DraftAlreadyLeftException::class);
		$this->policy->resolveStatus(entity: $this->entity(status: 'active'), selfData: ['status' => 'active']);
	}//end testAnAllowanceForAnObjectThatAlreadyLeftDraftIsRefused()

	/**
	 * Control: without an allowance, and for a new object, an unchanged status is no refusal.
	 *
	 * @return void
	 */
	public function testNoAllowanceNoRefusal(): void {
		$this->assertSame('active', $this->policy->resolveStatus(entity: $this->entity(status: 'active'), selfData: ['status' => 'active']));

		DraftStatusPolicy::allowPromotion(uuid: 'uuid-1');
		$this->assertNull($this->policy->resolveStatus(entity: $this->entity(status: null, stored: false), selfData: []));
	}//end testNoAllowanceNoRefusal()

	/**
	 * The allowance is used by the save that leaves draft, and only then.
	 *
	 * @return void
	 */
	public function testThePromotionIsUsedBySavingTheDraft(): void {
		DraftStatusPolicy::allowPromotion(uuid: 'uuid-1');
		$this->assertFalse(DraftStatusPolicy::promotionWasUsed(uuid: 'uuid-1'));

		$this->assertSame('active', $this->policy->resolveStatus(entity: $this->entity(status: 'draft'), selfData: ['status' => 'active']));
		$this->assertTrue(DraftStatusPolicy::promotionWasUsed(uuid: 'uuid-1'));
	}//end testThePromotionIsUsedBySavingTheDraft()
}//end class
