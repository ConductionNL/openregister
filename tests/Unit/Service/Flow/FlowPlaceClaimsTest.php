<?php

/**
 * The claim protocol over a fake unique index.
 *
 * The fake mapper enforces `(run_uuid, place)` uniqueness the way the real
 * index does and records every insert, so ordering, release-on-refusal, caps
 * and clamping are asserted against what the protocol actually attempted.
 * This does NOT exercise a real database: two connections racing on one
 * index is a property only the real index gives, and SQLite would not show a
 * row-lock race either way — a green run here is not that evidence.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use LogicException;
use OCA\OpenRegister\BackgroundJob\FlowRunWorker;
use OCA\OpenRegister\Db\FlowClaim;
use OCA\OpenRegister\Db\FlowClaimMapper;
use OCA\OpenRegister\Service\Flow\FlowConcurrency;
use OCA\OpenRegister\Service\Flow\ClaimPass;
use OCA\OpenRegister\Service\Flow\FiringClaim;
use OCA\OpenRegister\Service\Flow\FlowPlaceClaims;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Claims.
 */
class FlowPlaceClaimsTest extends TestCase {

	/** @var array<string, FlowClaim> keyed by run|place */
	private array $index = [];

	/** @var array<int, string> every insert attempted, in order */
	private array $attempts = [];

	private bool $inTransaction = false;

	private FlowPlaceClaims $claims;

	protected function setUp(): void {
		parent::setUp();
		$mapper = $this->createMock(FlowClaimMapper::class);
		$mapper->method('insertOrRefuse')->willReturnCallback(function (FlowClaim $claim): bool {
			$key = $claim->getRunUuid() . '|' . $claim->getPlace();
			$this->attempts[] = (string)$claim->getPlace();
			if (isset($this->index[$key]) === true) {
				return false;
			}

			$this->index[$key] = $claim;
			return true;
		});
		$mapper->method('release')->willReturnCallback(function (string $runUuid, array $places): int {
			$n = 0;
			foreach ($places as $place) {
				if (isset($this->index[$runUuid . '|' . $place]) === true) {
					unset($this->index[$runUuid . '|' . $place]);
					$n++;
				}
			}

			return $n;
		});
		$mapper->method('countHeldForRun')->willReturnCallback(function (string $runUuid): int {
			return count(array_filter($this->index, static fn (FlowClaim $c): bool => $c->getRunUuid() === $runUuid));
		});
		$mapper->method('countHeldByOwner')->willReturnCallback(function (string $owner): int {
			return count(array_filter($this->index, static fn (FlowClaim $c): bool => $c->getOwner() === $owner));
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnCallback(fn (): bool => $this->inTransaction);

		$this->claims = new FlowPlaceClaims(claims: $mapper, db: $db, logger: new NullLogger());
	}//end setUp()

	public function testPlacesAreClaimedInOneFixedBytewiseOrder(): void {
		$taken = $this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's', transition: 'T', places: ['c', 'a', 'b']), pass: new ClaimPass(owner: 'A'));
		$this->assertSame(['a', 'b', 'c'], $taken);
		$this->assertSame(['a', 'b', 'c'], $this->attempts);
	}//end testPlacesAreClaimedInOneFixedBytewiseOrder()

	public function testTwoWorkersOnTheSamePlaceExactlyOneWins(): void {
		$first = $this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's', transition: 'T1', places: ['a', 'c']), pass: new ClaimPass(owner: 'A'));
		$second = $this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's', transition: 'T1', places: ['a', 'c']), pass: new ClaimPass(owner: 'B'));

		$this->assertSame(['a', 'c'], $first);
		$this->assertNull($second);
		// The loser holds nothing: no partial claim survives a refusal.
		$this->assertSame(['A', 'A'], array_map(static fn (FlowClaim $c): string => (string)$c->getOwner(), array_values($this->index)));
	}//end testTwoWorkersOnTheSamePlaceExactlyOneWins()

	public function testDisjointBranchesBothProceed(): void {
		$this->assertSame(['a', 'c'], $this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's1', transition: 'T1', places: ['a', 'c']), pass: new ClaimPass(owner: 'A')));
		$this->assertSame(['b', 'd'], $this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's2', transition: 'T2', places: ['b', 'd']), pass: new ClaimPass(owner: 'B')));
	}//end testDisjointBranchesBothProceed()

	public function testARefusalReleasesWhatTheAttemptTookAndTheFiringStaysClaimable(): void {
		// B holds c. A wants {a, c}: takes a, is refused c, releases a.
		$this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's2', transition: 'T2', places: ['c']), pass: new ClaimPass(owner: 'B'));
		$this->assertNull($this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's1', transition: 'T1', places: ['a', 'c']), pass: new ClaimPass(owner: 'A')));
		$this->assertArrayNotHasKey('r|a', $this->index);

		// Once B releases c, A's next attempt succeeds — the firing was skipped, never lost.
		$this->claims->release(runUuid: 'r', places: ['c']);
		$this->assertSame(['a', 'c'], $this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's1', transition: 'T1', places: ['a', 'c']), pass: new ClaimPass(owner: 'A')));
	}//end testARefusalReleasesWhatTheAttemptTookAndTheFiringStaysClaimable()

	public function testATwelveTokenMarkingHoldsAtMostFiveClaimsPerRun(): void {
		$held = 0;
		for ($i = 0; $i < 12; $i++) {
			$taken = $this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's' . $i, transition: 'T' . $i, places: ['p' . $i]), pass: new ClaimPass(owner: 'W'));
			if ($taken !== null) {
				$held++;
			}
		}

		$this->assertSame(FlowConcurrency::DEFAULT_LIMIT, $held);
		$this->assertSame(5, $held);
	}//end testATwelveTokenMarkingHoldsAtMostFiveClaimsPerRun()

	public function testTheCapReadsFlowConcurrencysNumbersAndClampsAboveTheCeiling(): void {
		$this->assertSame(FlowConcurrency::DEFAULT_LIMIT, FlowPlaceClaims::streamCap(configured: null));
		$this->assertSame(FlowConcurrency::MAX_LIMIT, FlowPlaceClaims::streamCap(configured: 500));
		$this->assertSame(1, FlowPlaceClaims::streamCap(configured: 0));
		$this->assertSame(1, FlowPlaceClaims::streamCap(configured: -3));
		$this->assertSame(FlowRunWorker::BATCH * FlowConcurrency::DEFAULT_LIMIT, FlowPlaceClaims::passCap());
	}//end testTheCapReadsFlowConcurrencysNumbersAndClampsAboveTheCeiling()

	public function testAPassIsBoundedAcrossRuns(): void {
		// One pass holding passCap() claims across many runs is refused the next.
		$cap = FlowPlaceClaims::passCap();
		for ($i = 0; $i < $cap; $i++) {
			$this->assertNotNull($this->claims->claim(firing: new FiringClaim(runUuid: 'run' . $i, streamId: 's', transition: 'T', places: ['p']), pass: new ClaimPass(owner: 'pass')));
		}

		$this->assertNull($this->claims->claim(firing: new FiringClaim(runUuid: 'run-extra', streamId: 's', transition: 'T', places: ['p']), pass: new ClaimPass(owner: 'pass')));
		// Another pass is unaffected.
		$this->assertNotNull($this->claims->claim(firing: new FiringClaim(runUuid: 'run-extra', streamId: 's', transition: 'T', places: ['p']), pass: new ClaimPass(owner: 'other-pass')));
	}//end testAPassIsBoundedAcrossRuns()

	public function testAcquiringInsideATransactionIsRefusedLoudly(): void {
		$this->inTransaction = true;
		$this->expectException(LogicException::class);
		$this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's', transition: 'T', places: ['a']), pass: new ClaimPass(owner: 'A'));
	}//end testAcquiringInsideATransactionIsRefusedLoudly()

	public function testOwnerTokensAreUniquePerPass(): void {
		$this->assertNotSame(FlowPlaceClaims::newOwner(), FlowPlaceClaims::newOwner());
		$this->assertLessThanOrEqual(128, strlen(FlowPlaceClaims::newOwner()));
	}//end testOwnerTokensAreUniquePerPass()

	public function testEachClaimRowNamesTheRunPlaceOwnerStreamTransitionAndOneClaimTime(): void {
		$this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's7', transition: 'T7', places: ['b', 'a']), pass: new ClaimPass(owner: 'A'));

		$this->assertSame(['r|a', 'r|b'], array_keys($this->index));
		$first = $this->index['r|a'];
		$second = $this->index['r|b'];
		$this->assertNotSame($first, $second, 'Every place gets its own row.');
		foreach ([$first, $second] as $claim) {
			$this->assertSame('r', $claim->getRunUuid());
			$this->assertSame('A', $claim->getOwner());
			$this->assertSame('s7', $claim->getStreamId());
			$this->assertSame('T7', $claim->getTransition());
			$this->assertNotNull($claim->getClaimedAt());
		}

		$this->assertSame('a', $first->getPlace());
		$this->assertSame('b', $second->getPlace());
		$this->assertEquals($first->getClaimedAt(), $second->getClaimedAt(), 'One firing is claimed at one moment.');
	}//end testEachClaimRowNamesTheRunPlaceOwnerStreamTransitionAndOneClaimTime()

	public function testDuplicatePlacesAreClaimedOnceAndNoPlacesClaimNothing(): void {
		$this->assertSame(['1', 'a'], $this->claims->claim(firing: new FiringClaim(runUuid: 'r', streamId: 's', transition: 'T', places: ['a', 1, 'a', '1']), pass: new ClaimPass(owner: 'A')));
		$this->assertSame(['1', 'a'], $this->attempts);

		$this->attempts = [];
		$this->assertSame([], $this->claims->claim(firing: new FiringClaim(runUuid: 'r2', streamId: 's', transition: 'T', places: []), pass: new ClaimPass(owner: 'A')));
		$this->assertSame([], $this->attempts);
	}//end testDuplicatePlacesAreClaimedOnceAndNoPlacesClaimNothing()

	public function testClaimTakesTheFiringAndThePassAsValues(): void {
		$taken = $this->claims->claim(
			firing: new FiringClaim(runUuid: 'r', streamId: 's', transition: 'T', places: ['b', 'a']),
			pass: new ClaimPass(owner: 'A', runCap: 1)
		);
		$this->assertSame(['a', 'b'], $taken);
		$this->assertSame('A', $this->index['r|a']->getOwner());

		// The run cap travels on the pass: a run at its cap is refused.
		$this->assertNull($this->claims->claim(
			firing: new FiringClaim(runUuid: 'r', streamId: 's2', transition: 'T2', places: ['c']),
			pass: new ClaimPass(owner: 'A', runCap: 1)
		));
	}//end testClaimTakesTheFiringAndThePassAsValues()
}//end class
