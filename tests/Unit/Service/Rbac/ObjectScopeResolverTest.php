<?php

/**
 * The object-scope vocabulary, its precedence, and its SQL predicate.
 *
 * These are the rules every one of the four enforcement paths now depends on,
 * so they are pinned here once rather than re-asserted per path. The live-DB
 * parity matrix proves the paths AGREE; this proves what they agree ON.
 *
 * The fail-closed cases matter most. An unrecognised scope value must mean
 * private, not "ignore it" — a typo that opened an object up would be silent,
 * whereas one that hides it is visible the moment somebody looks for it.
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
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/private-object-scope/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use PHPUnit\Framework\TestCase;

/**
 * Pins the scope vocabulary the four enforcement paths share.
 */
class ObjectScopeResolverTest extends TestCase {

	private ObjectScopeResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new ObjectScopeResolver();
	}//end setUp()

	/**
	 * Nothing declared anywhere is the organisation default.
	 *
	 * This is the opt-in guarantee: an object that predates the capability is
	 * decided exactly as it was.
	 */
	public function testAbsentEverywhereIsOrganisation(): void {
		$this->assertSame(
			ObjectScopeResolver::SCOPE_ORGANISATION,
			$this->resolver->effectiveScope(null, null)
		);
		$this->assertFalse($this->resolver->isPrivate([], []));
		$this->assertFalse($this->resolver->isPrivate(['read' => ['admin']], ['read' => ['editors']]));
	}//end testAbsentEverywhereIsOrganisation()

	/**
	 * An object declaring `private` is private.
	 */
	public function testObjectDeclaresPrivate(): void {
		$this->assertTrue($this->resolver->isPrivate(['scope' => 'private'], null));
	}//end testObjectDeclaresPrivate()

	/**
	 * A schema default makes its objects private without them saying so.
	 */
	public function testSchemaDefaultMakesObjectsPrivate(): void {
		$this->assertTrue($this->resolver->isPrivate(null, ['scope' => 'private']));
		$this->assertTrue($this->resolver->isPrivate([], ['scope' => 'private']));
		$this->assertTrue($this->resolver->schemaDefaultIsPrivate(['scope' => 'private']));
	}//end testSchemaDefaultMakesObjectsPrivate()

	/**
	 * The object overrides the schema, in BOTH directions.
	 *
	 * The schema value is a default, not a ceiling. An owner putting their own
	 * object back to `organisation` is the Files model — a file starts private
	 * and its owner may share it.
	 */
	public function testObjectOverridesTheSchemaInBothDirections(): void {
		$this->assertFalse(
			$this->resolver->isPrivate(['scope' => 'organisation'], ['scope' => 'private'])
		);
		$this->assertTrue(
			$this->resolver->isPrivate(['scope' => 'private'], ['scope' => 'organisation'])
		);
	}//end testObjectOverridesTheSchemaInBothDirections()

	/**
	 * An unrecognised value fails CLOSED, at either level.
	 */
	public function testUnrecognisedValueIsPrivate(): void {
		$this->assertTrue($this->resolver->isPrivate(['scope' => 'privat'], null));
		$this->assertTrue($this->resolver->isPrivate(['scope' => 'PRIVATE'], null));
		$this->assertTrue($this->resolver->isPrivate(['scope' => 'Organisation'], null));
		$this->assertTrue($this->resolver->isPrivate(['scope' => true], null));
		$this->assertTrue($this->resolver->isPrivate(['scope' => 42], null));
		$this->assertTrue($this->resolver->isPrivate(['scope' => ['private']], null));
		$this->assertTrue($this->resolver->isPrivate(null, ['scope' => 'nonsense']));
	}//end testUnrecognisedValueIsPrivate()

	/**
	 * Absent is NOT the same as unrecognised — it falls through to the level below.
	 */
	public function testNullAndEmptyStringMeanUnset(): void {
		$this->assertNull($this->resolver->declaredScope(['scope' => null]));
		$this->assertNull($this->resolver->declaredScope(['scope' => '']));
		$this->assertNull($this->resolver->declaredScope([]));
		$this->assertNull($this->resolver->declaredScope(null));

		// An empty object-level value falls through to the schema, rather than
		// failing closed on the object.
		$this->assertFalse($this->resolver->isPrivate(['scope' => ''], ['scope' => 'organisation']));
		$this->assertTrue($this->resolver->isPrivate(['scope' => ''], ['scope' => 'private']));
	}//end testNullAndEmptyStringMeanUnset()

	/**
	 * The owner is admitted unconditionally.
	 */
	public function testOwnerIsAdmittedUnconditionally(): void {
		$this->assertTrue($this->resolver->admitsUnconditionally('alice', [], 'alice'));
		$this->assertTrue($this->resolver->admitsUnconditionally('alice', ['finance'], 'alice'));
	}//end testOwnerIsAdmittedUnconditionally()

	/**
	 * An administrator is admitted unconditionally, including for someone else's object.
	 */
	public function testAdminIsAdmittedUnconditionally(): void {
		$this->assertTrue($this->resolver->admitsUnconditionally('bob', ['admin'], 'alice'));
		$this->assertTrue($this->resolver->admitsUnconditionally(null, ['admin'], 'alice'));
		$this->assertTrue($this->resolver->admitsUnconditionally('bob', ['admin'], null));
	}//end testAdminIsAdmittedUnconditionally()

	/**
	 * Everybody else is refused: a colleague, an anonymous caller, and an
	 * object whose owner was never recorded.
	 */
	public function testEverybodyElseIsRefused(): void {
		$this->assertFalse($this->resolver->admitsUnconditionally('bob', ['finance'], 'alice'));
		$this->assertFalse($this->resolver->admitsUnconditionally(null, [], 'alice'));
		$this->assertFalse($this->resolver->admitsUnconditionally('alice', [], null));
		$this->assertFalse($this->resolver->admitsUnconditionally(null, [], null));
	}//end testEverybodyElseIsRefused()

	/**
	 * A group merely NAMED admin does not count — membership is matched strictly.
	 */
	public function testAdminMatchIsStrict(): void {
		$this->assertFalse($this->resolver->admitsUnconditionally('bob', ['admins'], 'alice'));
		$this->assertFalse($this->resolver->admitsUnconditionally('bob', ['Admin'], 'alice'));
	}//end testAdminMatchIsStrict()

	/**
	 * The Postgres predicate short-circuits on an unwritten column.
	 *
	 * The `IS NULL` disjunct is first on purpose: it is what keeps this
	 * predicate affordable on the list path of every open schema.
	 */
	public function testPostgresPredicateShortCircuitsOnNull(): void {
		$sql = $this->resolver->notPrivateSql('t._authorization', false, true);

		$this->assertStringStartsWith('(t._authorization IS NULL OR', $sql);
		$this->assertStringContainsString("->> 'scope'", $sql);
		$this->assertStringContainsString("= 'organisation'", $sql);
	}//end testPostgresPredicateShortCircuitsOnNull()

	/**
	 * The MariaDB predicate uses JSON_EXTRACT, not the Postgres arrow operator.
	 */
	public function testMariadbPredicateUsesJsonExtract(): void {
		$sql = $this->resolver->notPrivateSql('_authorization', false, false);

		$this->assertStringContainsString('JSON_UNQUOTE(JSON_EXTRACT(_authorization', $sql);
		$this->assertStringNotContainsString('::jsonb', $sql);
		$this->assertStringNotContainsString('->>', $sql);
	}//end testMariadbPredicateUsesJsonExtract()

	/**
	 * Under a private DEFAULT the null short-circuit must be absent — an
	 * unwritten column means private there, so admitting it would invert the
	 * default and expose every object of a private-by-default schema.
	 */
	public function testPrivateDefaultDoesNotAdmitAnUnwrittenColumn(): void {
		foreach ([true, false] as $isPostgres) {
			$sql = $this->resolver->notPrivateSql('_authorization', true, $isPostgres);

			$this->assertStringContainsString('_authorization IS NOT NULL', $sql);
			$this->assertStringNotContainsString('_authorization IS NULL', $sql);
			$this->assertStringContainsString("= 'organisation'", $sql);
		}
	}//end testPrivateDefaultDoesNotAdmitAnUnwrittenColumn()

	/**
	 * The private-default predicate is two-valued.
	 *
	 * Without the COALESCE a row whose block carries no `scope` key yields SQL
	 * NULL. A WHERE clause reads that as false, which is right — but a caller
	 * wrapping the fragment in NOT would get NULL again and drop the row from
	 * both sides of the decision.
	 */
	public function testPrivateDefaultPredicateIsTwoValued(): void {
		$this->assertStringContainsString(
			'COALESCE(',
			$this->resolver->notPrivateSql('_authorization', true, true)
		);
	}//end testPrivateDefaultPredicateIsTwoValued()

	/**
	 * The predicate honours the column name it is given.
	 *
	 * The two emitters reference the column differently — the QueryBuilder path
	 * has a `t` alias, the raw-SQL path feeds UNION members that have none — so
	 * a hard-coded column name here would break one of them.
	 */
	public function testPredicateHonoursTheCallersColumnName(): void {
		$aliased = $this->resolver->notPrivateSql('t._authorization', false, true);
		$bare = $this->resolver->notPrivateSql('_authorization', false, true);

		$this->assertStringContainsString('t._authorization', $aliased);
		$this->assertStringNotContainsString('t._authorization', $bare);
	}//end testPredicateHonoursTheCallersColumnName()

}//end class
