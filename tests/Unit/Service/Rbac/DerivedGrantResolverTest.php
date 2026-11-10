<?php

/**
 * Tests for DerivedGrantResolver.
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

use OCA\OpenRegister\Service\Rbac\DerivedGrantResolver;
use OCA\OpenRegister\Service\Rbac\GrantConstraints;
use PHPUnit\Framework\TestCase;

/**
 * Which claim rules fire, which are skipped and why, and which groups a set of grants yields.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\DerivedGrantResolver
 * @uses \OCA\OpenRegister\Service\Rbac\GrantConstraints
 */
class DerivedGrantResolverTest extends TestCase {

	/**
	 * No rules derive nothing.
	 *
	 * @return void
	 */
	public function testNoRulesDeriveNothing(): void {
		$resolver = new DerivedGrantResolver();

		$this->assertSame(['grants' => [], 'skipped' => []], $resolver->derive(claims: ['dept' => 'hr'], rules: null));
		$this->assertSame(['grants' => [], 'skipped' => []], $resolver->derive(claims: ['dept' => 'hr'], rules: []));
	}//end testNoRulesDeriveNothing()

	/**
	 * Unreadable rules are skipped with their reason; a rule with no wanted value, a
	 * missing claim or a different value fires on nothing.
	 *
	 * @return void
	 */
	public function testSkipReasonsAndNonMatches(): void {
		$result = (new DerivedGrantResolver())->derive(
			claims: ['dept' => 'hr', 'level' => ['senior', ['x']]],
			rules: [
				'a' => 'not-an-object',
				'b' => ['claim' => '', 'groups' => ['g']],
				'c' => ['claim' => 'dept', 'groups' => ['', 5], 'role' => ''],
				'd' => ['claim' => 'dept', 'groups' => 'hr-staff'],
				'e' => ['claim' => 'missing', 'equals' => 'x', 'groups' => ['g']],
				'f' => ['claim' => 'dept', 'equals' => 'finance', 'groups' => ['g']],
				'g' => ['claim' => 'dept', 'equals' => ['hr'], 'groups' => ['g']],
			]
		);

		$this->assertSame([], $result['grants']);
		$this->assertSame(
			[
				'Rule a is not an object and was skipped.',
				'Rule b names no claim and was skipped.',
				'Rule c grants neither a group nor a role and was skipped.',
			],
			$result['skipped']
		);
	}//end testSkipReasonsAndNonMatches()

	/**
	 * `equals` and `oneOf` both match, against a single or a multi-valued claim, and the
	 * grant carries its groups, role and constraints.
	 *
	 * @return void
	 */
	public function testMatchingRulesGrant(): void {
		$result = (new DerivedGrantResolver())->derive(
			claims: ['dept' => 'hr', 'level' => ['junior', 3]],
			rules: [
				['claim' => 'dept', 'equals' => 'hr', 'groups' => 'hr-staff', 'until' => '2030-01-01T00:00:00+00:00'],
				['claim' => 'level', 'oneOf' => ['3', 'senior'], 'role' => 'reviewer', 'scopedTo' => ['schemas' => ['zaak']]],
			]
		);

		$this->assertSame([], $result['skipped']);
		$this->assertSame(
			[
				['rule' => '0', 'claim' => 'dept', 'groups' => ['hr-staff'], 'role' => null, 'scopedTo' => null, 'until' => '2030-01-01T00:00:00+00:00'],
				['rule' => '1', 'claim' => 'level', 'groups' => [], 'role' => 'reviewer', 'scopedTo' => ['schemas' => ['zaak']], 'until' => null],
			],
			$result['grants']
		);
	}//end testMatchingRulesGrant()

	/**
	 * Groups are collected once each, from grants that still reach the area.
	 *
	 * @return void
	 */
	public function testGroupsForCollectsReachingGroupsOnce(): void {
		$groups = (new DerivedGrantResolver())->groupsFor(
			grants: [
				'junk',
				['groups' => ['hr', 'staff'], 'until' => '2999-01-01T00:00:00+00:00'],
				['groups' => ['staff', 'hr', 'audit']],
				['groups' => ['lapsed'], 'until' => '2000-01-01T00:00:00+00:00'],
				['groups' => ['elsewhere'], 'scopedTo' => ['registers' => ['other']]],
			],
			area: ['register' => 'zaken'],
			constraints: new GrantConstraints()
		);

		$this->assertSame(['hr', 'staff', 'audit'], $groups);
	}//end testGroupsForCollectsReachingGroupsOnce()
}//end class
