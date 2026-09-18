<?php

/**
 * The deny vocabulary, its precedence and its SQL predicate.
 *
 * A deny is the first rule in this layer that subtracts, and every enforcement
 * path now depends on the same reading of it, so the reading is pinned here
 * once rather than re-asserted per path.
 *
 * The fail-closed cases matter most, and they point the opposite way from the
 * grant side. On a grant, "could not read this rule" must mean no access. On a
 * deny it must mean no access too — which is the SAME direction and the
 * opposite code: dropping a deny is what leaks.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pins the deny grammar every enforcement path reads.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\DenyResolver
 */
class DenyResolverTest extends TestCase {

	/**
	 * The resolver under test.
	 *
	 * @var DenyResolver
	 */
	private DenyResolver $resolver;

	/**
	 * Build the resolver. It is a stateless value object.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new DenyResolver(new DenyEntryMatcher());
	}//end setUp()

	/**
	 * A block with no `deny` key declares no denial.
	 *
	 * This is the backwards-compatibility promise: every instance in the fleet
	 * is this case, and the whole deny pass is skipped on it.
	 *
	 * @return void
	 */
	public function testABlockWithoutADenyKeyDeclaresNothing(): void {
		$this->assertFalse($this->resolver->declaresAnyDeny(null));
		$this->assertFalse($this->resolver->declaresAnyDeny([]));
		$this->assertFalse($this->resolver->declaresAnyDeny(['read' => ['behandelaars']]));
		$this->assertSame([], $this->resolver->denyBlock(['read' => ['behandelaars']]));
	}//end testABlockWithoutADenyKeyDeclaresNothing()

	/**
	 * An empty deny list is not a denial either.
	 *
	 * @return void
	 */
	public function testAnEmptyDenyListDeclaresNothing(): void {
		$this->assertFalse($this->resolver->declaresAnyDeny(['deny' => ['read' => []]]));
	}//end testAnEmptyDenyListDeclaresNothing()

	/**
	 * A group named in a deny list denies the caller in that group.
	 *
	 * @return void
	 */
	public function testAGroupDenyReachesAMemberOfThatGroup(): void {
		$block = ['read' => ['behandelaars'], 'deny' => ['read' => ['waarnemers']]];
		$principals = $this->resolver->principalsFor('ana', ['behandelaars', 'waarnemers']);

		$denial = $this->resolver->unconditionalDenial($block, 'read', $principals);

		$this->assertIsArray($denial);
		$this->assertSame('waarnemers', $denial['principal']);
		$this->assertSame('read', $denial['action']);
		$this->assertFalse($denial['conditional']);
	}//end testAGroupDenyReachesAMemberOfThatGroup()

	/**
	 * A deny written for another group does not reach this caller.
	 *
	 * The control for the test above: without it, a resolver that denied
	 * everybody would pass that one.
	 *
	 * @return void
	 */
	public function testADenyForAnotherGroupDoesNotReachThisCaller(): void {
		$block = ['deny' => ['read' => ['waarnemers']]];
		$principals = $this->resolver->principalsFor('ana', ['behandelaars']);

		$this->assertNull($this->resolver->unconditionalDenial($block, 'read', $principals));
	}//end testADenyForAnotherGroupDoesNotReachThisCaller()

	/**
	 * A deny is scoped to the verb it names, and to no other.
	 *
	 * @return void
	 */
	public function testADenyIsScopedToOneVerb(): void {
		$block = ['deny' => ['update' => ['behandelaars']]];
		$principals = $this->resolver->principalsFor('ana', ['behandelaars']);

		$this->assertNotNull($this->resolver->unconditionalDenial($block, 'update', $principals));
		$this->assertNull($this->resolver->unconditionalDenial($block, 'read', $principals));
	}//end testADenyIsScopedToOneVerb()

	/**
	 * A `user:<uid>` deny reaches one named person and nobody else.
	 *
	 * @return void
	 */
	public function testAUserDenyReachesOnlyThatUser(): void {
		$block = ['deny' => ['delete' => ['user:bob']]];

		$this->assertNotNull(
			$this->resolver->unconditionalDenial($block, 'delete', $this->resolver->principalsFor('bob', []))
		);
		$this->assertNull(
			$this->resolver->unconditionalDenial($block, 'delete', $this->resolver->principalsFor('ana', []))
		);
	}//end testAUserDenyReachesOnlyThatUser()

	/**
	 * The object entry form names the same principals as the string form.
	 *
	 * @return void
	 */
	public function testTheObjectEntryFormNamesTheSamePrincipals(): void {
		$principals = $this->resolver->principalsFor('bob', ['waarnemers']);

		$this->assertSame(
			'waarnemers',
			$this->resolver->entryNames(['group' => 'waarnemers'], $principals)
		);
		$this->assertSame(
			'user:bob',
			$this->resolver->entryNames(['user' => 'bob'], $principals)
		);
		$this->assertNull($this->resolver->entryNames(['group' => 'anderen'], $principals));
	}//end testTheObjectEntryFormNamesTheSamePrincipals()

	/**
	 * The `mcp` token never denies, because it never grants.
	 *
	 * A token that could subtract but not add would give `mcp` a meaning
	 * nobody declared, and an administrator who created a real group by that
	 * name would silently lose rights across every annotated schema.
	 *
	 * @return void
	 */
	public function testTheMcpTokenNeverDenies(): void {
		$block = ['deny' => ['read' => ['mcp', ['group' => 'mcp']]]];
		$principals = $this->resolver->principalsFor('ana', ['mcp']);

		$this->assertNull($this->resolver->unconditionalDenial($block, 'read', $principals));
	}//end testTheMcpTokenNeverDenies()

	/**
	 * An entry carrying a `match` clause is conditional, never unconditional.
	 *
	 * @return void
	 */
	public function testAMatchClauseMakesTheDenialConditional(): void {
		$block = ['deny' => ['update' => [['group' => 'behandelaars', 'match' => ['status' => 'gesloten']]]]];
		$principals = $this->resolver->principalsFor('ana', ['behandelaars']);

		$this->assertNull($this->resolver->unconditionalDenial($block, 'update', $principals));
		$this->assertCount(1, $this->resolver->conditionalDenials($block, 'update', $principals));
	}//end testAMatchClauseMakesTheDenialConditional()

	/**
	 * Every caller carries `public`, and a signed-in one also carries `authenticated`.
	 *
	 * @return void
	 */
	public function testThePseudoPrincipalsEveryCallerCarries(): void {
		$anonymous = $this->resolver->principalsFor(null, []);
		$this->assertSame(['public'], $anonymous);

		$signedIn = $this->resolver->principalsFor('ana', ['hr']);
		$this->assertContains('public', $signedIn);
		$this->assertContains('authenticated', $signedIn);
		$this->assertContains('user:ana', $signedIn);
		$this->assertContains('hr', $signedIn);
	}//end testThePseudoPrincipalsEveryCallerCarries()

	/**
	 * 🔴 The merge UNIONS, so a narrower level can only add denials.
	 *
	 * This is the test that stops the precedence rule holding everywhere except
	 * the level an author edits. If the object's block replaced the schema's,
	 * writing a deny on an object would REMOVE the one written above it.
	 *
	 * @return void
	 */
	public function testTheMergeUnionsSoAnObjectCannotUndoASchemaDeny(): void {
		$merged = $this->resolver->mergeDeny(
			['read' => ['waarnemers']],
			['update' => ['stagiairs']]
		);

		$this->assertSame(['waarnemers'], $merged['read']);
		$this->assertSame(['stagiairs'], $merged['update']);
	}//end testTheMergeUnionsSoAnObjectCannotUndoASchemaDeny()

	/**
	 * The same action at two levels keeps both lists.
	 *
	 * @return void
	 */
	public function testTheMergeKeepsBothListsForOneAction(): void {
		$merged = $this->resolver->mergeDeny(
			['read' => ['waarnemers']],
			['read' => ['stagiairs', 'waarnemers']]
		);

		$this->assertSame(['waarnemers', 'stagiairs'], $merged['read']);
	}//end testTheMergeKeepsBothListsForOneAction()

	/**
	 * The row predicate names the caller's principals and the action's path.
	 *
	 * This is the "assert the SQL carries the filter" half: the point of the
	 * capability is that the engine does the filtering, so what has to be
	 * asserted is the predicate, not a filtered result.
	 *
	 * @return void
	 */
	public function testTheRowPredicateNamesThePrincipalAndThePath(): void {
		$sql = $this->resolver->notDeniedRowSql('t._authorization', 'read', false, ['waarnemers']);

		$this->assertStringContainsString('$.deny.read', $sql);
		$this->assertStringContainsString('waarnemers', $sql);
		$this->assertStringContainsString('JSON_CONTAINS', $sql);
		$this->assertStringContainsString('NOT LIKE', $sql);
		$this->assertStringStartsWith('(', $sql);
	}//end testTheRowPredicateNamesThePrincipalAndThePath()

	/**
	 * PostgreSQL gets containment rather than `JSON_CONTAINS`.
	 *
	 * @return void
	 */
	public function testThePostgresRowPredicateUsesContainment(): void {
		$sql = $this->resolver->notDeniedRowSql('t._authorization', 'read', true, ['waarnemers']);

		$this->assertStringContainsString('::jsonb', $sql);
		$this->assertStringContainsString('@>', $sql);
		$this->assertStringNotContainsString('JSON_CONTAINS', $sql);
	}//end testThePostgresRowPredicateUsesContainment()

	/**
	 * Both entry shapes are looked for, and the object one is array-wrapped.
	 *
	 * PostgreSQL's containment operator does not match a bare object against an
	 * array, so a candidate that worked on MariaDB and not on PostgreSQL would
	 * be a deny that holds on one platform and not the other.
	 *
	 * @return void
	 */
	public function testBothEntryShapesAreLookedForAndWrapped(): void {
		$sql = $this->resolver->notDeniedRowSql('t._authorization', 'read', true, ['user:bob']);

		$this->assertStringContainsString('["user:bob"]', $sql);
		$this->assertStringContainsString('[{"user":"bob"}]', $sql);
	}//end testBothEntryShapesAreLookedForAndWrapped()

	/**
	 * 🔴 A verb that cannot be written as a JSON path denies everything.
	 *
	 * The path is built from the action name, so the name is checked rather
	 * than trusted. Refusing every row is recoverable and visible; emitting a
	 * path built from an unchecked string is neither.
	 *
	 * @return void
	 */
	public function testAnUnsafeVerbDeniesEverythingRatherThanEmittingItsName(): void {
		$sql = $this->resolver->notDeniedRowSql('t._authorization', "read') OR 1=1 --", false, ['hr']);

		$this->assertSame('1 = 0', $sql);
	}//end testAnUnsafeVerbDeniesEverythingRatherThanEmittingItsName()

	/**
	 * A quote inside a principal name cannot close the literal.
	 *
	 * @return void
	 */
	public function testAQuoteInAPrincipalNameIsEscaped(): void {
		$sql = $this->resolver->notDeniedRowSql('t._authorization', 'read', false, ["o'brien"]);

		$this->assertStringContainsString("o''brien", $sql);
		$this->assertStringNotContainsString("'o'brien'", $sql);
	}//end testAQuoteInAPrincipalNameIsEscaped()

	/**
	 * The cheap guard comes first, so a row that mentions no denial is decided
	 * without a JSON parse.
	 *
	 * @return void
	 */
	public function testTheCheapGuardComesBeforeTheJsonWork(): void {
		$sql = $this->resolver->notDeniedRowSql('t._authorization', 'read', false, ['hr']);

		$guardAt = strpos($sql, 'NOT LIKE');
		$jsonAt = strpos($sql, 'JSON_CONTAINS');

		$this->assertIsInt($guardAt);
		$this->assertIsInt($jsonAt);
		$this->assertLessThan($jsonAt, $guardAt);
	}//end testTheCheapGuardComesBeforeTheJsonWork()
}//end class
