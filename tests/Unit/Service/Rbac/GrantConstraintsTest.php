<?php

/**
 * A grant that ends, and a grant that belongs to one area.
 *
 * Both constraints are subtractive, and a subtractive rule that silently does
 * nothing is the worst kind: the instance reads as configured and the right
 * never goes away. So every case here has a control that proves the entry was
 * reachable before the constraint removed it.
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

use DateTimeImmutable;
use OCA\OpenRegister\Service\Rbac\GrantConstraints;
use PHPUnit\Framework\TestCase;

/**
 * Tasks 8.2 and 8.4: the end on a grant, and the area it is confined to.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\GrantConstraints
 */
class GrantConstraintsTest extends TestCase {

	/**
	 * The constraints reader under test.
	 *
	 * @var GrantConstraints
	 */
	private GrantConstraints $constraints;

	/**
	 * The moment every case is evaluated at.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Build the reader and pin the clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->constraints = new GrantConstraints();
		$this->now = new DateTimeImmutable('2026-09-14T12:00:00+02:00');
	}//end setUp()

	/**
	 * The area every case asks about unless it says otherwise.
	 *
	 * @return array<string, string> The area.
	 */
	private function area(): array {
		return ['register' => 'zaken', 'schema' => 'zaak'];
	}//end area()

	/**
	 * 🔴 A block that names neither constraint comes back untouched.
	 *
	 * The backwards-compatibility promise, made as an assertion rather than as a
	 * sentence in a docblock: an instance that writes no ending and no area
	 * resolves exactly as it did before this existed.
	 *
	 * @return void
	 */
	public function testABlockWithoutConstraintsIsUnchanged(): void {
		$block = [
			'read' => ['behandelaars', ['group' => 'directie', 'match' => ['status' => 'open']]],
			'roles' => ['behandelaar' => ['behandelaars']],
			'deny' => ['update' => ['stagiairs']],
			'scope' => 'organisation',
			'public' => true,
		];

		$this->assertSame(
			$block,
			$this->constraints->apply(authorization: $block, area: $this->area(), now: $this->now)
		);
		$this->assertFalse($this->constraints->declaresAnyConstraint(authorization: $block));
	}//end testABlockWithoutConstraintsIsUnchanged()

	/**
	 * 🔴 A grant whose end has passed stops answering, with no sweep.
	 *
	 * @return void
	 */
	public function testAGrantWhoseEndHasPassedIsGone(): void {
		$block = [
			'read' => [
				['group' => 'waarnemers', 'until' => '2026-09-13T17:00:00+02:00'],
				'behandelaars',
			],
		];

		$filtered = $this->constraints->apply(authorization: $block, area: $this->area(), now: $this->now);

		$this->assertSame(['behandelaars'], $filtered['read'], 'the expired entry survived, or the control went with it');
	}//end testAGrantWhoseEndHasPassedIsGone()

	/**
	 * A grant whose end is still ahead keeps answering.
	 *
	 * The control for the case above: without it, a filter that dropped every
	 * entry carrying an end would pass.
	 *
	 * @return void
	 */
	public function testAGrantWhoseEndIsAheadStillAnswers(): void {
		$block = ['read' => [['group' => 'waarnemers', 'until' => '2026-09-15T17:00:00+02:00']]];

		$filtered = $this->constraints->apply(authorization: $block, area: $this->area(), now: $this->now);

		$this->assertCount(1, $filtered['read']);
		$this->assertSame('waarnemers', $filtered['read'][0]['group']);
	}//end testAGrantWhoseEndIsAheadStillAnswers()

	/**
	 * An end nobody can read is treated as passed.
	 *
	 * Fail closed. The other reading turns a typo into a grant that never ends,
	 * and the whole point of an end is that somebody meant it to arrive.
	 *
	 * @return void
	 */
	public function testAnUnreadableEndIsTreatedAsPassed(): void {
		$block = ['read' => [['group' => 'waarnemers', 'until' => 'volgende week dinsdag']]];

		$this->assertSame(
			[],
			$this->constraints->apply(authorization: $block, area: $this->area(), now: $this->now)['read']
		);
	}//end testAnUnreadableEndIsTreatedAsPassed()

	/**
	 * A deny may end too, and the same reader takes it away.
	 *
	 * @return void
	 */
	public function testADenyCanEndAsWell(): void {
		$block = [
			'read' => ['behandelaars'],
			'deny' => ['read' => [['group' => 'behandelaars', 'until' => '2026-09-01T00:00:00+02:00']]],
		];

		$filtered = $this->constraints->apply(authorization: $block, area: $this->area(), now: $this->now);

		$this->assertSame([], $filtered['deny']['read'], 'an expired deny still removes a verb');
		$this->assertSame(['behandelaars'], $filtered['read']);
	}//end testADenyCanEndAsWell()

	/**
	 * 🔴 `manage` scoped to one register does not administer another.
	 *
	 * The difference between delegating administration and handing it over.
	 *
	 * @return void
	 */
	public function testScopedManageDoesNotReachAnotherRegister(): void {
		$block = [
			'manage' => [['group' => 'beheerders', 'scopedTo' => ['registers' => ['zaken']]]],
		];

		$here = $this->constraints->apply(authorization: $block, area: $this->area(), now: $this->now);
		$this->assertCount(1, $here['manage'], 'the scoped grant does not reach the register it names');

		$elsewhere = $this->constraints->apply(
			authorization: $block,
			area: ['register' => 'besluiten', 'schema' => 'besluit'],
			now: $this->now
		);
		$this->assertSame([], $elsewhere['manage'], 'the scoped grant administers a register it does not name');
	}//end testScopedManageDoesNotReachAnotherRegister()

	/**
	 * An area naming both a register and a schema has to match both.
	 *
	 * @return void
	 */
	public function testAnAreaNamingBothMustMatchBoth(): void {
		$block = [
			'update' => [
				['group' => 'behandelaars', 'scopedTo' => ['registers' => ['zaken'], 'schemas' => ['besluit']]],
			],
		];

		$this->assertSame(
			[],
			$this->constraints->apply(authorization: $block, area: $this->area(), now: $this->now)['update'],
			'the right register was enough, so the schema half of the area does nothing'
		);
	}//end testAnAreaNamingBothMustMatchBoth()

	/**
	 * An area that names nothing covers nothing.
	 *
	 * Fail closed again, and for the same reason as the unreadable end: an empty
	 * area is a rule somebody started writing.
	 *
	 * @return void
	 */
	public function testAnEmptyAreaCoversNothing(): void {
		$block = ['read' => [['group' => 'behandelaars', 'scopedTo' => []]]];

		$this->assertSame(
			[],
			$this->constraints->apply(authorization: $block, area: $this->area(), now: $this->now)['read']
		);
	}//end testAnEmptyAreaCoversNothing()

	/**
	 * An area may be named by id as well as by slug.
	 *
	 * Both spellings are what an author has to hand: a slug when they write the
	 * rule, an id when a screen generated it.
	 *
	 * @return void
	 */
	public function testAnAreaMayBeNamedById(): void {
		$block = ['read' => [['group' => 'behandelaars', 'scopedTo' => ['registers' => [7]]]]];

		$filtered = $this->constraints->apply(
			authorization: $block,
			area: ['register' => '7', 'schema' => 'zaak'],
			now: $this->now
		);

		$this->assertCount(1, $filtered['read']);
	}//end testAnAreaMayBeNamedById()

	/**
	 * A role held until a date stops being held after it.
	 *
	 * A role assignment carries the constraint on the holder, because that is
	 * where the sentence lives: this group holds this role until Friday.
	 *
	 * @return void
	 */
	public function testARoleHeldUntilADateIsDroppedAfterIt(): void {
		$block = [
			'roles' => [
				'waarnemer' => [['group' => 'waarnemers', 'until' => '2026-09-01T00:00:00+02:00']],
				'behandelaar' => ['behandelaars'],
			],
		];

		$filtered = $this->constraints->apply(authorization: $block, area: $this->area(), now: $this->now);

		$this->assertArrayNotHasKey('waarnemer', $filtered['roles'], 'the expired role assignment survived');
		$this->assertSame(['behandelaars'], $filtered['roles']['behandelaar'], 'the control role went with it');
	}//end testARoleHeldUntilADateIsDroppedAfterIt()
}//end class
