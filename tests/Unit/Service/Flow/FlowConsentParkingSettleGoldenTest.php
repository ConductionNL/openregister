<?php

/**
 * Golden coverage for how FlowConsentParking settles one parked run.
 *
 * Pins, per branch, the outcome a sweep reports, the state and error written
 * onto the run, the request sent to the delegation store and what is logged —
 * allowed and refused outcomes alike.
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
use OCA\OpenRegister\Service\Delegation\DelegationRequest;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use OCA\OpenRegister\Service\Flow\FlowConsentParking;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Locks every branch of settling a parked run.
 */
class FlowConsentParkingSettleGoldenTest extends TestCase {

	/**
	 * The moment every test acts at.
	 *
	 * @var DateTime
	 */
	private DateTime $now;

	/**
	 * Requests the delegation double received.
	 *
	 * @var array<int, DelegationRequest>
	 */
	private array $requests = [];

	/**
	 * Log lines as [level, message].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $logged = [];

	/**
	 * Fresh clock and recorders.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->now = new DateTime('2026-08-26 12:00:00');
		$this->requests = [];
		$this->logged = [];
	}//end setUp()

	/**
	 * Sweep the given run once with a delegation that answers `$verdict`.
	 *
	 * @param FlowRun                $run     The parked run.
	 * @param DelegationVerdict|null $verdict The answer, or null to throw.
	 *
	 * @return array{released: int, failed: int} What the sweep did.
	 */
	private function sweep(FlowRun $run, ?DelegationVerdict $verdict): array {
		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('findAwaitingConsent')->willReturn([$run]);
		$mapper->method('update')->willReturnArgument(0);

		$delegation = $this->createMock(DelegationService::class);
		$delegation->method('verdictFor')->willReturnCallback(
			function (DelegationRequest $request) use ($verdict): DelegationVerdict {
				$this->requests[] = $request;
				if ($verdict === null) {
					throw new RuntimeException('store unreadable');
				}

				return $verdict;
			}
		);

		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int, array{0: string, 1: string}> $lines The sink.
			 */
			public function __construct(private array &$lines) {
			}

			/**
			 * Record one line.
			 *
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context Ignored.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = [(string)$level, (string)$message];
			}
		};

		return (new FlowConsentParking($mapper, $delegation, $logger))->sweep(now: $this->now, waitHours: 72);
	}//end sweep()

	/**
	 * A run parked with the given record.
	 *
	 * @param array<string, mixed>|string|null $record The parked record.
	 *
	 * @return FlowRun The run.
	 */
	private function run(array|string|null $record): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus(FlowRun::STATUS_AWAITING_CONSENT);
		$context = ['other' => 'kept'];
		if ($record !== null) {
			$context[FlowConsentParking::CONTEXT_KEY] = $record;
		}

		$run->setContext($context);

		return $run;
	}//end run()

	/**
	 * A complete record waiting since `$hoursAgo`.
	 *
	 * @param integer $hoursAgo How long it has waited.
	 *
	 * @return array<string, string> The record.
	 */
	private function record(int $hoursAgo): array {
		return [
			'principal' => '  alice ',
			'actingAs' => ' mayor',
			'reason' => DelegationVerdict::REASON_PENDING,
			'since' => (clone $this->now)->modify('-' . $hoursAgo . ' hours')->format('c'),
		];
	}//end record()

	/**
	 * A record that is not an array fails the run, keeping other context.
	 *
	 * @return void
	 */
	public function testARecordThatIsNotAnArrayFailsTheRun(): void {
		$run = $this->run('broken');

		$this->assertSame(['released' => 0, 'failed' => 1], $this->sweep($run, null));
		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
		$this->assertSame(
			'This run was parked awaiting consent but records no delegation, so nothing can release it.',
			$run->getError()
		);
		$this->assertSame(['other' => 'kept'], $run->getContext());
		$this->assertSame([], $this->requests, 'no delegation is consulted');
	}//end testARecordThatIsNotAnArrayFailsTheRun()

	/**
	 * A record naming a blank party fails the run without asking anyone.
	 *
	 * @return void
	 */
	public function testARecordWithABlankPartyFailsTheRun(): void {
		foreach ([['principal' => 'alice', 'actingAs' => '  '], ['actingAs' => 'mayor']] as $record) {
			$this->requests = [];
			$this->logged = [];
			$run = $this->run($record);

			$this->assertSame(['released' => 0, 'failed' => 1], $this->sweep($run, null));
			$this->assertSame(
				'This run was parked awaiting consent but names no parties, so nothing can release it.',
				$run->getError()
			);
			$this->assertSame([], $this->requests);
			$this->assertSame(
				[['warning', '[FlowConsentParking] Failed a parked run: ' . $run->getError()]],
				$this->logged
			);
		}
	}//end testARecordWithABlankPartyFailsTheRun()

	/**
	 * The delegation is asked with the trimmed parties, no scope and the sweep moment.
	 *
	 * @return void
	 */
	public function testTheDelegationIsAskedWithTheTrimmedParties(): void {
		$this->sweep($this->run($this->record(hoursAgo: 1)), DelegationVerdict::granted(new DelegationGrant()));

		$this->assertCount(1, $this->requests);
		$this->assertSame('alice', $this->requests[0]->principal);
		$this->assertSame('mayor', $this->requests[0]->actingAs);
		$this->assertSame([], $this->requests[0]->scope);
		$this->assertSame($this->now, $this->requests[0]->now);
	}//end testTheDelegationIsAskedWithTheTrimmedParties()

	/**
	 * An allowed delegation re-queues the run and logs the release.
	 *
	 * @return void
	 */
	public function testAnAllowedDelegationReleasesTheRun(): void {
		$run = $this->run($this->record(hoursAgo: 1));

		$this->assertSame(
			['released' => 1, 'failed' => 0],
			$this->sweep($run, DelegationVerdict::granted(new DelegationGrant()))
		);
		$this->assertSame(FlowRun::STATUS_QUEUED, $run->getStatus());
		$this->assertSame(['other' => 'kept'], $run->getContext());
		$this->assertSame(
			[['info', '[FlowConsentParking] Released a parked run — the delegation was allowed']],
			$this->logged
		);
	}//end testAnAllowedDelegationReleasesTheRun()

	/**
	 * An unreadable store logs a warning and leaves the run parked untouched.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreLeavesTheRunParkedAndWarns(): void {
		$run = $this->run($this->record(hoursAgo: 100));

		$this->assertSame(['released' => 0, 'failed' => 0], $this->sweep($run, null));
		$this->assertSame(FlowRun::STATUS_AWAITING_CONSENT, $run->getStatus());
		$this->assertSame(
			[['warning', '[FlowConsentParking] Could not resolve a parked run\'s delegation; leaving it parked: store unreadable']],
			$this->logged
		);
	}//end testAnUnreadableStoreLeavesTheRunParkedAndWarns()

	/**
	 * A pending answer waits inside the limit and fails closed past it.
	 *
	 * @return void
	 */
	public function testAPendingAnswerWaitsThenFailsClosed(): void {
		$pending = DelegationVerdict::refused(DelegationVerdict::REASON_PENDING, 'still waiting.');

		$young = $this->run($this->record(hoursAgo: 71));
		$this->assertSame(['released' => 0, 'failed' => 0], $this->sweep($young, $pending));
		$this->assertSame(FlowRun::STATUS_AWAITING_CONSENT, $young->getStatus());

		$old = $this->run($this->record(hoursAgo: 73));
		$this->assertSame(['released' => 0, 'failed' => 1], $this->sweep($old, $pending));
		$this->assertSame(
			'Nobody answered within 72 hours, so this run was not performed. An unanswered request is not consent.',
			$old->getError()
		);
	}//end testAPendingAnswerWaitsThenFailsClosed()

	/**
	 * Any other refusal fails the run naming both parties, the reason and the detail.
	 *
	 * @return void
	 */
	public function testAnyOtherRefusalFailsTheRunWithItsReason(): void {
		$run = $this->run($this->record(hoursAgo: 1));

		$outcome = $this->sweep(
			$run,
			DelegationVerdict::refused(DelegationVerdict::REASON_DENIED, 'the mayor said no.')
		);

		$this->assertSame(['released' => 0, 'failed' => 1], $outcome);
		$this->assertSame(FlowRun::STATUS_FAILED, $run->getStatus());
		$this->assertSame(
			'Refused: "alice" may not act as "mayor" (' . DelegationVerdict::REASON_DENIED . '). the mayor said no.',
			$run->getError()
		);
		$this->assertSame(['other' => 'kept'], $run->getContext());
	}//end testAnyOtherRefusalFailsTheRunWithItsReason()
}//end class
