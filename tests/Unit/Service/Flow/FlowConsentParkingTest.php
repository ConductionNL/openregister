<?php

/**
 * Unit coverage for FlowConsentParking — the `awaiting_consent` run state.
 *
 * THE TWO TESTS THAT MATTER
 *
 * A parked run must be RELEASED when the answer is yes and FAILED when the
 * answer is anything else — including "nobody answered". The second is the one
 * with teeth: the tempting behaviour after a timeout is to run the work anyway,
 * which converts an unanswered prompt into an approval at whatever hour the
 * timer happened to elapse. That is precisely the substitution this whole
 * subsystem exists to prevent.
 *
 * The third is quieter and just as important: an UNREADABLE store must leave the
 * run parked rather than fail it. Here the trade-off inverts from the fire-time
 * check — nothing runs either way, so waiting costs nothing and failing destroys
 * work over an infrastructure blip.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use OCA\OpenRegister\Service\Flow\FlowConsentParking;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Locks what releases a parked run, and what does not.
 */
class FlowConsentParkingTest extends TestCase {

	/**
	 * The moment every test acts at.
	 *
	 * @var DateTime
	 */
	private DateTime $now;

	/**
	 * Runs the mapper double holds.
	 *
	 * @var array<int, FlowRun>
	 */
	private array $stored = [];

	protected function setUp(): void {
		parent::setUp();

		$this->now = new DateTime('2026-08-26 12:00:00');
		$this->stored = [];
	}//end setUp()

	/**
	 * A parking service whose resolver answers the given verdict.
	 *
	 * @param DelegationVerdict|null $verdict What the delegation answers, or null to throw.
	 *
	 * @return FlowConsentParking The service under test.
	 */
	private function parking(?DelegationVerdict $verdict): FlowConsentParking {
		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('findAwaitingConsent')->willReturnCallback(
			fn (): array => array_values(
				array_filter(
					$this->stored,
					static fn (FlowRun $run): bool => $run->getStatus() === FlowRun::STATUS_AWAITING_CONSENT
				)
			)
		);
		$mapper->method('update')->willReturnArgument(0);

		$delegation = $this->createMock(DelegationService::class);
		if ($verdict === null) {
			$delegation->method('verdictFor')->willThrowException(new RuntimeException('store unreadable'));
		} else {
			$delegation->method('verdictFor')->willReturn($verdict);
		}

		return new FlowConsentParking($mapper, $delegation, new NullLogger());
	}

	/**
	 * A run already parked, waiting since `$hoursAgo`.
	 *
	 * @param float $hoursAgo How long it has waited.
	 *
	 * @return FlowRun The parked run.
	 */
	private function parkedRun(float $hoursAgo = 1.0): FlowRun {
		$since = (clone $this->now)->modify('-' . (int)round($hoursAgo * 60) . ' minutes');

		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus(FlowRun::STATUS_AWAITING_CONSENT);
		$run->setContext(
			[
				FlowConsentParking::CONTEXT_KEY => [
					'principal' => 'alice',
					'actingAs' => 'mayor',
					'reason' => DelegationVerdict::REASON_PENDING,
					'since' => $since->format('c'),
				],
			]
		);

		$this->stored = [$run];

		return $run;
	}

	/**
	 * A live grant.
	 *
	 * @return DelegationGrant The grant.
	 */
	private function grant(): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setPrincipal('alice');
		$grant->setActingAs('mayor');

		return $grant;
	}

	/**
	 * Parking writes the state, the parties and NO resume time.
	 *
	 * The absent `resume_at` is load-bearing: with one, the timed-resume sweep
	 * would start the run before anybody had answered.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testParkingRecordsWhoItWaitsOnAndSetsNoTimer(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus(FlowRun::STATUS_QUEUED);
		$run->setContext([]);

		$parked = $this->parking(DelegationVerdict::granted($this->grant()))
			->park($run, 'alice', 'mayor', DelegationVerdict::REASON_PENDING);

		$this->assertSame(FlowRun::STATUS_AWAITING_CONSENT, $parked->getStatus());
		$this->assertNull($parked->getResumeAt(), 'a consent does not arrive on a clock');

		$record = ($parked->getContext() ?? [])[FlowConsentParking::CONTEXT_KEY];
		$this->assertSame('alice', $record['principal']);
		$this->assertSame('mayor', $record['actingAs']);

		// "Why is this stuck" must be answerable FROM THE RUN. An operator who
		// has to join the run against a grant table to find out is an operator
		// who will not find out.
		$this->assertStringContainsString('mayor', (string)$parked->getError());
		$this->assertStringContainsString('alice', (string)$parked->getError());
	}

	/**
	 * POSITIVE CONTROL: an allowed delegation releases the run to the queue.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAGrantedDelegationReleasesTheRun(): void {
		$run = $this->parkedRun();

		$outcome = $this->parking(DelegationVerdict::granted($this->grant()))->sweep(now: $this->now);

		$this->assertSame(['released' => 1, 'failed' => 0], $outcome);
		$this->assertSame(FlowRun::STATUS_QUEUED, $run->getStatus());
		$this->assertNull($run->getError());
		$this->assertArrayNotHasKey(
			FlowConsentParking::CONTEXT_KEY,
			($run->getContext() ?? []),
			'a released run must not still claim to be waiting'
		);
	}

	/**
	 * A DENIED delegation fails the run, naming the denial.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testADenialFailsTheParkedRunWithItsReason(): void {
		$run = $this->parkedRun();

		$outcome = $this->parking(
			DelegationVerdict::refused(DelegationVerdict::REASON_DENIED, 'the mayor said no.')
		)->sweep(now: $this->now);

		$this->assertSame(['released' => 0, 'failed' => 1], $outcome);
		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
		$this->assertStringContainsString('denied', (string)$run->getError());
	}

	/**
	 * A still-unanswered request leaves the run WAITING.
	 *
	 * The control for the timeout test below — without it, a sweep that failed
	 * every parked run would pass that one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAnUnansweredRequestKeepsWaiting(): void {
		$run = $this->parkedRun(hoursAgo: 1.0);

		$outcome = $this->parking(
			DelegationVerdict::refused(DelegationVerdict::REASON_PENDING, 'still waiting.')
		)->sweep(now: $this->now);

		$this->assertSame(['released' => 0, 'failed' => 0], $outcome);
		$this->assertSame(FlowRun::STATUS_AWAITING_CONSENT, $run->getStatus());
	}

	/**
	 * 🔴 An unanswered request eventually FAILS — it never becomes a yes.
	 *
	 * The tempting behaviour after a timeout is to run the work anyway. That
	 * converts an unread prompt into an approval at whatever hour the timer
	 * elapsed, which is exactly the substitution this subsystem exists to stop.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAnUnansweredRequestEventuallyFailsClosed(): void {
		$run = $this->parkedRun(hoursAgo: 100.0);

		$outcome = $this->parking(
			DelegationVerdict::refused(DelegationVerdict::REASON_PENDING, 'still waiting.')
		)->sweep(now: $this->now, waitHours: 72);

		$this->assertSame(['released' => 0, 'failed' => 1], $outcome);
		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
		$this->assertStringContainsString(
			'not consent',
			(string)$run->getError(),
			'the record must say the work was NOT performed, not merely that time passed'
		);
	}

	/**
	 * 🔴 An unreadable store leaves the run PARKED, not failed.
	 *
	 * The trade-off inverts from the fire-time check. There, refusing costs one
	 * run and permitting costs an unauthorized execution. Here nothing runs
	 * either way, so waiting is free and failing destroys work over a blip.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAnUnreadableStoreLeavesTheRunParked(): void {
		$run = $this->parkedRun();

		$outcome = $this->parking(null)->sweep(now: $this->now);

		$this->assertSame(['released' => 0, 'failed' => 0], $outcome);
		$this->assertSame(FlowRun::STATUS_AWAITING_CONSENT, $run->getStatus());
	}

	/**
	 * A parked run recording nothing is failed rather than left unreachable.
	 *
	 * Nobody can release it, and leaving it would make the state itself
	 * untrustworthy — "awaiting consent" would sometimes mean "stuck forever".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAParkedRunWithNoRecordIsFailed(): void {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus(FlowRun::STATUS_AWAITING_CONSENT);
		$run->setContext([]);
		$this->stored = [$run];

		$outcome = $this->parking(DelegationVerdict::granted($this->grant()))->sweep(now: $this->now);

		$this->assertSame(['released' => 0, 'failed' => 1], $outcome);
		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
		$this->assertStringContainsString('records no delegation', (string)$run->getError());
	}

	/**
	 * `awaiting_consent` counts as ACTIVE.
	 *
	 * Omitting it would hide a parked run from every "currently running" surface
	 * — which is where somebody would go to find out why their work has not run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function testAwaitingConsentIsActiveAndNotTerminal(): void {
		$this->assertContains(FlowRun::STATUS_AWAITING_CONSENT, FlowRun::ACTIVE);
		$this->assertNotContains(FlowRun::STATUS_AWAITING_CONSENT, FlowRun::TERMINAL);
	}
}
