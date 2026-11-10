<?php

/**
 * Tests for GrantRuleFit.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\Rbac\GrantRuleFit;
use OCA\OpenRegister\Service\Rbac\HeldRule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * How a rule entry fits a record, and which principal type a rule names.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\GrantRuleFit
 */
class GrantRuleFitTest extends TestCase {

	/**
	 * @var ConditionMatcher&MockObject
	 */
	private ConditionMatcher $matcher;

	/**
	 * @var GrantRuleFit
	 */
	private GrantRuleFit $fit;

	/**
	 * Build the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->matcher = $this->createMock(ConditionMatcher::class);
		$this->fit     = new GrantRuleFit(matcher: $this->matcher);
	}//end setUp()

	/**
	 * An entry reached through a role is unconditional and the matcher is not asked.
	 *
	 * @return void
	 */
	public function testRoleEntryIsUnconditional(): void {
		$this->matcher->expects($this->never())->method('objectMatchesConditions');

		$this->assertSame(
			['matchedOn' => [], 'relative' => false],
			$this->fit->fitOf(rule: new HeldRule(entry: ['match' => ['status' => 'open']], role: 'editor'), envelope: [])
		);
	}//end testRoleEntryIsUnconditional()

	/**
	 * A plain principal string and an empty match are unconditional.
	 *
	 * @return void
	 */
	public function testEntryWithoutMatchIsUnconditional(): void {
		$this->matcher->expects($this->never())->method('objectMatchesConditions');

		$this->assertSame(['matchedOn' => [], 'relative' => false], $this->fit->fitOf(rule: new HeldRule(entry: 'group-a', role: null), envelope: []));
		$this->assertSame(['matchedOn' => [], 'relative' => false], $this->fit->fitOf(rule: new HeldRule(entry: ['match' => []], role: null), envelope: []));
		$this->assertSame(['matchedOn' => [], 'relative' => false], $this->fit->fitOf(rule: new HeldRule(entry: ['match' => 'x'], role: null), envelope: []));
	}//end testEntryWithoutMatchIsUnconditional()

	/**
	 * A static clause the record satisfies reports the properties matched on.
	 *
	 * @return void
	 */
	public function testStaticClauseThatMatchesReportsMatchedProperties(): void {
		$envelope = ['status' => 'open'];
		$this->matcher->expects($this->once())->method('objectMatchesConditions')
			->with($envelope, ['status' => 'open', 'level' => 3])
			->willReturn(true);

		$this->assertSame(
			['matchedOn' => ['status', 'level'], 'relative' => false],
			$this->fit->fitOf(rule: new HeldRule(entry: ['match' => ['status' => 'open', 'level' => 3]], role: null), envelope: $envelope)
		);
	}//end testStaticClauseThatMatchesReportsMatchedProperties()

	/**
	 * A static clause the record fails drops the entry.
	 *
	 * @return void
	 */
	public function testStaticClauseThatFailsDropsTheEntry(): void {
		$this->matcher->method('objectMatchesConditions')->willReturn(false);

		$this->assertNull($this->fit->fitOf(rule: new HeldRule(entry: ['match' => ['status' => 'open']], role: null), envelope: ['status' => 'closed']));
	}//end testStaticClauseThatFailsDropsTheEntry()

	/**
	 * Caller-reading values are kept out of the static evaluation and mark the fit relative.
	 *
	 * @return void
	 */
	public function testCallerReadingValuesAreRelativeAndNotEvaluated(): void {
		$this->matcher->expects($this->once())->method('objectMatchesConditions')
			->with(['k' => 1], ['kind' => 'case'])
			->willReturn(true);

		$result = $this->fit->fitOf(
			rule: new HeldRule(
				entry: [
					'match' => [
						'owner' => '$userId',
						'org'   => '$organisation.uuid',
						'group' => ['$memberOf' => 'x'],
						'any'   => ['$in' => ['literal', '$user']],
						'kind'  => 'case',
					],
				],
				role: null
			),
			envelope: ['k' => 1]
		);

		$this->assertSame(['matchedOn' => ['owner', 'org', 'group', 'any', 'kind'], 'relative' => true], $result);
	}//end testCallerReadingValuesAreRelativeAndNotEvaluated()

	/**
	 * A clause made only of caller-reading values is relative without asking the matcher.
	 *
	 * @return void
	 */
	public function testOnlyRelativeClauseSkipsTheMatcher(): void {
		$this->matcher->expects($this->never())->method('objectMatchesConditions');

		$this->assertSame(
			['matchedOn' => ['owner'], 'relative' => true],
			$this->fit->fitOf(rule: new HeldRule(entry: ['match' => ['owner' => '$activeOrganisation']], role: null), envelope: [])
		);
	}//end testOnlyRelativeClauseSkipsTheMatcher()

	/**
	 * Lookalike strings, nested literals and non-string scalars are static.
	 *
	 * @return void
	 */
	public function testLookalikesAreStatic(): void {
		$match = ['a' => '$userIdentity', 'b' => ['$in' => ['x', 'y']], 'c' => 5, 'd' => '$users.x'];
		$this->matcher->expects($this->once())->method('objectMatchesConditions')
			->with([], $match)
			->willReturn(true);

		$this->assertSame(
			['matchedOn' => ['a', 'b', 'c', 'd'], 'relative' => false],
			$this->fit->fitOf(rule: new HeldRule(entry: ['match' => $match], role: null), envelope: [])
		);
	}//end testLookalikesAreStatic()

	/**
	 * A `user:` prefixed principal is a user.
	 *
	 * @return void
	 */
	public function testUserPrefixedPrincipalIsAUser(): void {
		$this->assertSame(['user', 'alice'], $this->fit->principalOf(principal: 'user:alice', entry: null));
	}//end testUserPrefixedPrincipalIsAUser()

	/**
	 * A principal written as a `user` entry, alone or in a role's holder list, is a user.
	 *
	 * @return void
	 */
	public function testPrincipalNamedByAUserEntryIsAUser(): void {
		$this->assertSame(['user', 'bob'], $this->fit->principalOf(principal: 'bob', entry: ['user' => 'bob']));
		$this->assertSame(
			['user', 'bob'],
			$this->fit->principalOf(principal: 'bob', entry: ['staff', ['user' => 'carol'], ['user' => 'bob']])
		);
	}//end testPrincipalNamedByAUserEntryIsAUser()

	/**
	 * Pseudo principals keep their own type; anything else is a group.
	 *
	 * @return void
	 */
	public function testPseudoPrincipalsAndGroups(): void {
		$this->assertSame(['public', 'public'], $this->fit->principalOf(principal: 'public', entry: 'public'));
		$this->assertSame(['authenticated', 'authenticated'], $this->fit->principalOf(principal: 'authenticated', entry: null));
		$this->assertSame(['group', 'bob'], $this->fit->principalOf(principal: 'bob', entry: ['user' => 'carol']));
	}//end testPseudoPrincipalsAndGroups()
}//end class
