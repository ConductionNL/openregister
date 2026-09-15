<?php

/**
 * Access derived from a claim, and every way a rule declines to fire.
 *
 * A derivation that fires too easily is a privilege bug with a friendly name, so
 * the cases here are weighted towards the refusals: a rule that names no value,
 * a claim the provider did not assert, a rule that grants nothing.
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

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\DerivedGrantResolver;
use OCA\OpenRegister\Service\Rbac\GrantConstraints;
use PHPUnit\Framework\TestCase;

/**
 * Task 8.1: the claim becomes a role, a group and an area.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\DerivedGrantResolver
 */
class DerivedGrantResolverTest extends TestCase {

	/**
	 * The resolver under test.
	 *
	 * @var DerivedGrantResolver
	 */
	private DerivedGrantResolver $resolver;

	/**
	 * Build the resolver.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new DerivedGrantResolver();
	}//end setUp()

	/**
	 * The rule the spec's scenario is written against.
	 *
	 * @return array<int, array<string, mixed>> The rules.
	 */
	private function departmentRule(): array {
		return [
			[
				'claim' => 'department',
				'equals' => 'vergunningen',
				'role' => 'behandelaar',
				'groups' => ['behandelaars-vergunningen'],
				'scopedTo' => ['registers' => ['vergunningen']],
			],
		];
	}//end departmentRule()

	/**
	 * 🔴 A new employee is authorised by what the provider asserts.
	 *
	 * @return void
	 */
	public function testTheClaimProducesTheRoleAndTheGroup(): void {
		$derived = $this->resolver->derive(
			claims: ['department' => 'vergunningen'],
			rules: $this->departmentRule()
		);

		$this->assertCount(1, $derived['grants']);
		$this->assertSame('behandelaar', $derived['grants'][0]['role']);
		$this->assertSame(['behandelaars-vergunningen'], $derived['grants'][0]['groups']);
		$this->assertSame(['registers' => ['vergunningen']], $derived['grants'][0]['scopedTo']);
		$this->assertSame([], $derived['skipped']);
	}//end testTheClaimProducesTheRoleAndTheGroup()

	/**
	 * 🔴 And holds it in that department and no other.
	 *
	 * The area is read at resolution, not at derivation, because the same grant
	 * is asked about once per register a person opens.
	 *
	 * @return void
	 */
	public function testTheDerivedGroupIsHeldInThatAreaAndNoOther(): void {
		$grants = $this->resolver->derive(
			claims: ['department' => 'vergunningen'],
			rules: $this->departmentRule()
		)['grants'];

		$constraints = new GrantConstraints();

		$this->assertSame(
			['behandelaars-vergunningen'],
			$this->resolver->groupsFor(
				grants: $grants,
				area: ['register' => 'vergunningen', 'schema' => 'aanvraag'],
				constraints: $constraints
			)
		);

		$this->assertSame(
			[],
			$this->resolver->groupsFor(
				grants: $grants,
				area: ['register' => 'handhaving', 'schema' => 'zaak'],
				constraints: $constraints
			),
			'the derived group reached a register the rule does not name'
		);
	}//end testTheDerivedGroupIsHeldInThatAreaAndNoOther()

	/**
	 * A claim the provider did not assert derives nothing.
	 *
	 * @return void
	 */
	public function testAnAbsentClaimDerivesNothing(): void {
		$derived = $this->resolver->derive(claims: ['email' => 'ana@example.org'], rules: $this->departmentRule());

		$this->assertSame([], $derived['grants']);
	}//end testAnAbsentClaimDerivesNothing()

	/**
	 * A claim with the wrong value derives nothing.
	 *
	 * @return void
	 */
	public function testAClaimWithAnotherValueDerivesNothing(): void {
		$derived = $this->resolver->derive(claims: ['department' => 'handhaving'], rules: $this->departmentRule());

		$this->assertSame([], $derived['grants']);
	}//end testAClaimWithAnotherValueDerivesNothing()

	/**
	 * 🔴 A rule that names no value it wants fires on nothing.
	 *
	 * The privilege bug this closes: the other reading of a half-written rule is
	 * "any value at all", which grants to everybody the provider knows about.
	 *
	 * @return void
	 */
	public function testARuleThatNamesNoValueFiresOnNothing(): void {
		$derived = $this->resolver->derive(
			claims: ['department' => 'vergunningen'],
			rules: [['claim' => 'department', 'groups' => ['iedereen']]]
		);

		$this->assertSame([], $derived['grants']);
	}//end testARuleThatNamesNoValueFiresOnNothing()

	/**
	 * A rule granting neither a group nor a role is skipped and reported.
	 *
	 * @return void
	 */
	public function testARuleThatGrantsNothingIsReported(): void {
		$derived = $this->resolver->derive(
			claims: ['department' => 'vergunningen'],
			rules: [['claim' => 'department', 'equals' => 'vergunningen']]
		);

		$this->assertSame([], $derived['grants']);
		$this->assertCount(1, $derived['skipped']);
		$this->assertStringContainsString('neither a group nor a role', $derived['skipped'][0]);
	}//end testARuleThatGrantsNothingIsReported()

	/**
	 * A claim asserted as a list fires on any of its values.
	 *
	 * `groups` and `roles` claims arrive that way, so a rule written against one
	 * of them is the ordinary case rather than the exotic one.
	 *
	 * @return void
	 */
	public function testAListClaimFiresOnAnyOfItsValues(): void {
		$derived = $this->resolver->derive(
			claims: ['groups' => ['juristen', 'vergunningen']],
			rules: [
				[
					'claim' => 'groups',
					'oneOf' => ['vergunningen', 'handhaving'],
					'groups' => ['behandelaars'],
				],
			]
		);

		$this->assertCount(1, $derived['grants']);
		$this->assertSame(['behandelaars'], $derived['grants'][0]['groups']);
	}//end testAListClaimFiresOnAnyOfItsValues()

	/**
	 * A derived grant may carry an end, and stops being held after it.
	 *
	 * @return void
	 */
	public function testADerivedGrantMayEnd(): void {
		$grants = $this->resolver->derive(
			claims: ['department' => 'vergunningen'],
			rules: [
				[
					'claim' => 'department',
					'equals' => 'vergunningen',
					'groups' => ['waarnemers'],
					'until' => '2020-01-01T00:00:00+01:00',
				],
			]
		)['grants'];

		$this->assertCount(1, $grants, 'the rule did not fire, so the expiry below proves nothing');
		$this->assertSame(
			[],
			$this->resolver->groupsFor(
				grants: $grants,
				area: ['register' => 'vergunningen', 'schema' => 'aanvraag'],
				constraints: new GrantConstraints()
			)
		);
	}//end testADerivedGrantMayEnd()

	/**
	 * No rules at all derives nothing, quietly.
	 *
	 * @return void
	 */
	public function testNoRulesDeriveNothing(): void {
		$derived = $this->resolver->derive(claims: ['department' => 'vergunningen'], rules: null);

		$this->assertSame([], $derived['grants']);
		$this->assertSame([], $derived['skipped']);
	}//end testNoRulesDeriveNothing()
}//end class
