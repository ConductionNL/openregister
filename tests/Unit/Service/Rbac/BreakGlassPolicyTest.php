<?php

/**
 * The period of a break-glass take and the limit on taking it again, with a clock fixture (REQ-GSR-004).
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
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Rbac\BreakGlassDeclaration;
use OCA\OpenRegister\Service\Rbac\BreakGlassPolicy;
use OCA\OpenRegister\Service\Rbac\BreakGlassTake;
use PHPUnit\Framework\TestCase;

/**
 * The policy, the clock handed in as a fixed timestamp.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\BreakGlassPolicy
 * @covers \OCA\OpenRegister\Service\Rbac\BreakGlassTake
 * @uses   \OCA\OpenRegister\Service\Rbac\BreakGlassDeclaration
 */
class BreakGlassPolicyTest extends TestCase {

	/**
	 * The clock fixture: 2026-03-02T09:00:00Z.
	 *
	 * @var integer
	 */
	private const NOW = 1772442000;

	/**
	 * One hour.
	 *
	 * @var integer
	 */
	private const HOUR = 3600;

	/**
	 * Read for four hours.
	 *
	 * @return BreakGlassDeclaration The declaration.
	 */
	private static function fourHoursRead(): BreakGlassDeclaration {
		return new BreakGlassDeclaration(verbs: ['read'], durationSeconds: 4 * self::HOUR, notifyUsers: ['teamlead']);
	}//end fourHoursRead()

	/**
	 * A first take grants exactly the declared verbs for exactly the declared period.
	 *
	 * @return void
	 */
	public function testAFirstTakeGrantsTheDeclaredPeriod(): void {
		$take = (new BreakGlassPolicy())->take(declaration: self::fourHoursRead(), grantee: 'ana', priorTakes: [], now: self::NOW);

		$this->assertSame('ana', $take->grantee);
		$this->assertSame(['read'], $take->verbs);
		$this->assertSame(self::NOW, $take->takenAt);
		$this->assertSame(self::NOW + 4 * self::HOUR, $take->until);
		$this->assertSame(self::NOW, $take->chainStart);
	}//end testAFirstTakeGrantsTheDeclaredPeriod()

	/**
	 * It ends by itself: held a minute before the end, not at the end, not four hours later.
	 *
	 * @return void
	 */
	public function testItEndsByItself(): void {
		$take = (new BreakGlassPolicy())->take(declaration: self::fourHoursRead(), grantee: 'ana', priorTakes: [], now: self::NOW);

		$this->assertTrue($take->grantsAt(verb: 'read', now: self::NOW));
		$this->assertTrue($take->grantsAt(verb: 'read', now: self::NOW + 4 * self::HOUR - 60));
		$this->assertFalse($take->grantsAt(verb: 'read', now: self::NOW + 4 * self::HOUR));
		$this->assertFalse($take->grantsAt(verb: 'read', now: self::NOW + 8 * self::HOUR));
		$this->assertFalse($take->grantsAt(verb: 'read', now: self::NOW - 1));
		$this->assertFalse($take->grantsAt(verb: 'update', now: self::NOW));
	}//end testItEndsByItself()

	/**
	 * With no administered limit, taking it again while it holds is refused.
	 *
	 * @return void
	 */
	public function testWithoutALimitItCannotBeExtended(): void {
		$policy = new BreakGlassPolicy();
		$first = $policy->take(declaration: self::fourHoursRead(), grantee: 'ana', priorTakes: [], now: self::NOW);

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('cannot be extended beyond the administered limit');
		$policy->take(declaration: self::fourHoursRead(), grantee: 'ana', priorTakes: [$first], now: self::NOW + 3 * self::HOUR);
	}//end testWithoutALimitItCannotBeExtended()

	/**
	 * An administered limit lets a run grow up to its first take plus the limit, and no further.
	 *
	 * @return void
	 */
	public function testALimitBoundsTheWholeRun(): void {
		$policy = new BreakGlassPolicy();
		$limit = 6 * self::HOUR;
		$first = $policy->take(declaration: self::fourHoursRead(), grantee: 'ana', priorTakes: [], now: self::NOW, limitSeconds: $limit);

		// Three hours in: four more would reach seven, the run stops at six.
		$second = $policy->take(
			declaration: self::fourHoursRead(),
			grantee: 'ana',
			priorTakes: [$first],
			now: self::NOW + 3 * self::HOUR,
			limitSeconds: $limit
		);
		$this->assertSame(self::NOW + 6 * self::HOUR, $second->until);
		$this->assertSame(self::NOW, $second->chainStart);

		// Five hours in: the run already ends at six, nothing is left to add.
		$this->expectException(NotAuthorizedException::class);
		$policy->take(
			declaration: self::fourHoursRead(),
			grantee: 'ana',
			priorTakes: [$second, $first],
			now: self::NOW + 5 * self::HOUR,
			limitSeconds: $limit
		);
	}//end testALimitBoundsTheWholeRun()

	/**
	 * A limit shorter than the declared period does not shorten a first take.
	 *
	 * @return void
	 */
	public function testAShortLimitDoesNotShortenAFirstTake(): void {
		$take = (new BreakGlassPolicy())->take(
			declaration: self::fourHoursRead(),
			grantee: 'ana',
			priorTakes: [],
			now: self::NOW,
			limitSeconds: self::HOUR
		);

		$this->assertSame(self::NOW + 4 * self::HOUR, $take->until);
	}//end testAShortLimitDoesNotShortenAFirstTake()

	/**
	 * After a run has ended a new take starts a new run.
	 *
	 * @return void
	 */
	public function testANewEmergencyAfterTheEndStartsANewRun(): void {
		$policy = new BreakGlassPolicy();
		$first = $policy->take(declaration: self::fourHoursRead(), grantee: 'ana', priorTakes: [], now: self::NOW);

		$later = self::NOW + 5 * self::HOUR;
		$next = $policy->take(declaration: self::fourHoursRead(), grantee: 'ana', priorTakes: [$first], now: $later);

		$this->assertSame($later, $next->chainStart);
		$this->assertSame($later + 4 * self::HOUR, $next->until);
	}//end testANewEmergencyAfterTheEndStartsANewRun()

	/**
	 * Somebody else's take is not this grantee's run.
	 *
	 * @return void
	 */
	public function testAnotherGranteesTakeIsNotThisRun(): void {
		$policy = new BreakGlassPolicy();
		$bob = $policy->take(declaration: self::fourHoursRead(), grantee: 'bob', priorTakes: [], now: self::NOW);

		$this->assertNull($policy->currentRun(priorTakes: [$bob], grantee: 'ana', now: self::NOW + 60));
		$this->assertSame($bob, $policy->currentRun(priorTakes: [$bob, 'not a take'], grantee: 'bob', now: self::NOW + 60));

		$ana = $policy->take(declaration: self::fourHoursRead(), grantee: 'ana', priorTakes: [$bob], now: self::NOW + 60);
		$this->assertSame(self::NOW + 60, $ana->chainStart);
	}//end testAnotherGranteesTakeIsNotThisRun()

	/**
	 * A take survives the trail round trip, and a malformed context is no take.
	 *
	 * @return void
	 */
	public function testATakeRoundTripsThroughTheTrailContext(): void {
		$take = new BreakGlassTake(grantee: 'ana', verbs: ['read'], takenAt: self::NOW, until: self::NOW + self::HOUR, chainStart: self::NOW - 60);
		$context = $take->toContext(reason: 'aan de balie');

		$this->assertSame('aan de balie', $context['reason']);
		$this->assertSame('2026-03-02T09:00:00+00:00', $context['takenAt']);
		$this->assertEquals($take, BreakGlassTake::fromContext(context: $context));

		$this->assertNull(BreakGlassTake::fromContext(context: null));
		$this->assertNull(BreakGlassTake::fromContext(context: ['grantee' => 'ana', 'verbs' => []] + $context));
		$this->assertNull(BreakGlassTake::fromContext(context: ['until' => 'never'] + $context));
		$this->assertNull(BreakGlassTake::fromContext(context: ['until' => $context['takenAt']] + $context));
	}//end testATakeRoundTripsThroughTheTrailContext()
}//end class
