<?php

/**
 * OpenRegister - a group can own an object
 *
 * The owning group admits on the same terms as the named owner, so colleagues
 * can edit a record without being handed it first. It is pinned on BOTH paths:
 * the single-object verdict and the list predicate. A principal honoured on one
 * path and not the other is a defect in both directions — over-filtering hides a
 * record its reader may edit, under-filtering leaks one.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/object-ownership-and-handover/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use PHPUnit\Framework\TestCase;

/**
 * The owning group, on both enforcement paths.
 */
class ObjectScopeResolverOwnerGroupTest extends TestCase {

	/**
	 * System under test.
	 *
	 * @var ObjectScopeResolver
	 */
	private ObjectScopeResolver $resolver;

	/**
	 * Build the resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->resolver = new ObjectScopeResolver();
	}//end setUp()

	/**
	 * A member of the owning group is admitted, although they own nothing.
	 *
	 * @return void
	 */
	public function testAMemberOfTheOwningGroupIsAdmitted(): void {
		$this->assertTrue(
			$this->resolver->admitsUnconditionally(
				userId: 'bob',
				userGroups: ['redactie'],
				objectOwner: 'alice',
				authorization: ['scope' => 'private', 'ownerGroup' => 'redactie']
			)
		);
	}//end testAMemberOfTheOwningGroupIsAdmitted()

	/**
	 * Somebody in a different group is not.
	 *
	 * @return void
	 */
	public function testSomebodyInAnotherGroupIsNotAdmitted(): void {
		$this->assertFalse(
			$this->resolver->admitsUnconditionally(
				userId: 'bob',
				userGroups: ['financien'],
				objectOwner: 'alice',
				authorization: ['scope' => 'private', 'ownerGroup' => 'redactie']
			)
		);
	}//end testSomebodyInAnotherGroupIsNotAdmitted()

	/**
	 * A block with no owning group decides exactly as it did before.
	 *
	 * @return void
	 */
	public function testABlockWithoutAnOwningGroupIsUnchanged(): void {
		$this->assertFalse(
			$this->resolver->admitsUnconditionally(
				userId: 'bob',
				userGroups: ['redactie'],
				objectOwner: 'alice',
				authorization: ['scope' => 'private']
			)
		);

		$this->assertTrue(
			$this->resolver->admitsUnconditionally(
				userId: 'alice',
				userGroups: ['redactie'],
				objectOwner: 'alice',
				authorization: ['scope' => 'private']
			)
		);
	}//end testABlockWithoutAnOwningGroupIsUnchanged()

	/**
	 * An unreadable owning group admits nobody.
	 *
	 * An array, a boolean or an empty string is not a group id, and a value that
	 * cannot be read must not let somebody in.
	 *
	 * @return void
	 */
	public function testAnUnreadableOwningGroupAdmitsNobody(): void {
		foreach ([['redactie'], true, '', 7] as $value) {
			$this->assertNull($this->resolver->ownerGroup(['ownerGroup' => $value]));
		}

		$this->assertNull($this->resolver->ownerGroup(null));
		$this->assertNull($this->resolver->ownerGroup([]));
	}//end testAnUnreadableOwningGroupAdmitsNobody()

	/**
	 * The list predicate keeps a row owned by one of the caller's groups.
	 *
	 * @return void
	 */
	public function testTheListPredicateKeepsARowOwnedByMyGroup(): void {
		$sql = $this->resolver->notPrivateOrGrantedSql(
			authColumn: '_authorization',
			defaultPrivate: true,
			isPostgres: false,
			uuidColumn: '_uuid',
			quotedUuids: [],
			quotedUserGroups: ["'redactie'"]
		);

		$this->assertStringContainsString('ownerGroup', $sql);
		$this->assertStringContainsString("'redactie'", $sql);
	}//end testTheListPredicateKeepsARowOwnedByMyGroup()

	/**
	 * An anonymous caller adds no group term at all.
	 *
	 * The predicate lands on the list query of every schema, so it must stay
	 * exactly what it was for a caller who is in no group.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerAddsNoGroupTerm(): void {
		$withoutGroups = $this->resolver->notPrivateOrGrantedSql(
			authColumn: '_authorization',
			defaultPrivate: false,
			isPostgres: false,
			uuidColumn: '_uuid',
			quotedUuids: [],
			quotedUserGroups: []
		);

		$this->assertSame(
			$this->resolver->notPrivateSql('_authorization', false, false),
			$withoutGroups
		);
	}//end testAnAnonymousCallerAddsNoGroupTerm()

	/**
	 * Grants and the owning group compose rather than replace each other.
	 *
	 * @return void
	 */
	public function testAGrantAndAnOwningGroupBothSurvive(): void {
		$sql = $this->resolver->notPrivateOrGrantedSql(
			authColumn: '_authorization',
			defaultPrivate: true,
			isPostgres: true,
			uuidColumn: '_uuid',
			quotedUuids: ["'uuid-1'"],
			quotedUserGroups: ["'redactie'"]
		);

		$this->assertStringContainsString("_uuid IN ('uuid-1')", $sql);
		$this->assertStringContainsString("->> 'ownerGroup'", $sql);
	}//end testAGrantAndAnOwningGroupBothSurvive()

}//end class
