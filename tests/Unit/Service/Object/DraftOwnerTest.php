<?php

/**
 * Who owns a draft: a Nextcloud user or a scope value such as `portal:<subjectRef>`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-owned-by-a-scope-value
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\DraftOwner;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * Decision 195: a draft may be owned by a scope value; only that owner finds it.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftOwner
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class DraftOwnerTest extends TestCase {

	/**
	 * A user with a uid.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * A stored object with an owner and a status.
	 */
	private function object(?string $owner, ?string $status): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-1');
		$entity->setOwner($owner);
		$entity->setStatus($status);

		return $entity;
	}//end object()

	/**
	 * A user owner is the uid, carries the user, and is not scoped.
	 */
	public function testAUserOwnerIsTheUid(): void {
		$alice = $this->user(uid: 'alice');
		$owner = DraftOwner::ofUser(user: $alice);

		$this->assertSame('alice', $owner->value());
		$this->assertTrue($owner->user() === $alice);
		$this->assertFalse($owner->isScoped());
	}//end testAUserOwnerIsTheUid()

	/**
	 * The owner is the asserted one, else the signed-in subject, else nobody.
	 */
	public function testTheOwnerIsTheAssertedOneElseTheSubjectElseNobody(): void {
		$alice = $this->user(uid: 'alice');
		$portal = DraftOwner::portalSubject(subjectRef: 'subject-1');

		$this->assertSame($portal, DraftOwner::resolve(subject: $alice, owner: $portal));
		$this->assertSame('alice', DraftOwner::resolve(subject: $alice, owner: null)?->value());
		$this->assertSame($portal, DraftOwner::resolve(subject: null, owner: $portal));
		$this->assertNull(DraftOwner::resolve(subject: null, owner: null));
	}//end testTheOwnerIsTheAssertedOneElseTheSubjectElseNobody()

	/**
	 * A portal subject owner is `portal:<subjectRef>`, scoped, without a user.
	 */
	public function testAPortalSubjectIsAScopeValue(): void {
		$owner = DraftOwner::portalSubject(subjectRef: ' subject-1 ');

		$this->assertSame('portal:subject-1', $owner->value());
		$this->assertNull($owner->user());
		$this->assertTrue($owner->isScoped());
		$this->assertSame('ledger:x', DraftOwner::scoped(scope: 'ledger', reference: 'x')->value());
	}//end testAPortalSubjectIsAScopeValue()

	/**
	 * Only the same owner owns a draft; a non-draft is nobody's draft.
	 */
	public function testOnlyTheSameOwnerOwnsTheDraft(): void {
		$owner = DraftOwner::portalSubject(subjectRef: 'subject-1');

		$this->assertTrue($owner->owns(object: $this->object(owner: 'portal:subject-1', status: 'draft')));
		$this->assertFalse($owner->owns(object: $this->object(owner: 'portal:subject-2', status: 'draft')));
		$this->assertFalse($owner->owns(object: $this->object(owner: 'subject-1', status: 'draft')));
		$this->assertFalse($owner->owns(object: $this->object(owner: 'portal:subject-1', status: 'active')));
		$this->assertFalse($owner->owns(object: $this->object(owner: 'portal:subject-1', status: null)));
		$this->assertFalse(DraftOwner::ofUser(user: $this->user(uid: 'portal:subject-1'))->isScoped());
	}//end testOnlyTheSameOwnerOwnsTheDraft()

	/**
	 * Malformed scope values are refused before anything is read or written.
	 *
	 * @dataProvider malformed
	 */
	public function testAMalformedScopeValueIsRefused(string $scope, string $reference): void {
		$this->expectException(InvalidArgumentException::class);
		DraftOwner::scoped(scope: $scope, reference: $reference);
	}//end testAMalformedScopeValueIsRefused()

	/**
	 * Scope and reference pairs that are not an owner.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function malformed(): array {
		return [
			'empty reference' => ['portal', '  '],
			'empty scope' => ['', 'subject-1'],
			'upper case scope' => ['Portal', 'subject-1'],
			'colon in scope' => ['por:tal', 'subject-1'],
			'control character' => ['portal', "sub\nject"],
			'longer than the owner column' => ['portal', str_repeat('a', 58)],
		];
	}//end malformed()

	/**
	 * The longest reference that still fits the 64-character owner column.
	 */
	public function testTheLongestFittingReferenceIsAccepted(): void {
		$this->assertSame(64, strlen(DraftOwner::portalSubject(subjectRef: str_repeat('a', 57))->value()));
	}//end testTheLongestFittingReferenceIsAccepted()
}//end class
