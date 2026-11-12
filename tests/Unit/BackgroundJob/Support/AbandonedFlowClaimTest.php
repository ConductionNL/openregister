<?php

/**
 * An abandoned flow claim carries the reap time and a readable error naming owner, stream and step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob\Support;

use DateTime;
use OCA\OpenRegister\BackgroundJob\Support\AbandonedFlowClaim;
use OCA\OpenRegister\BackgroundJob\Support\FlowReapRun;
use OCA\OpenRegister\Db\FlowClaim;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\Support\AbandonedFlowClaim
 * @covers \OCA\OpenRegister\BackgroundJob\Support\FlowReapRun
 * @uses \OCA\OpenRegister\Db\FlowClaim
 */
class AbandonedFlowClaimTest extends TestCase {

	public function testCutoffIsNowMinusTheWindowAndLeavesNowUntouched(): void {
		$now = new DateTime('2026-11-12 12:00:00');
		$run = new FlowReapRun(now: $now, minutes: 45);

		$this->assertSame('2026-11-12 11:15:00', $run->cutoff()->format('Y-m-d H:i:s'));
		$this->assertSame('2026-11-12 12:00:00', $now->format('Y-m-d H:i:s'));
	}//end testCutoffIsNowMinusTheWindowAndLeavesNowUntouched()

	public function testClaimFieldsAndErrorMessage(): void {
		$claim = new FlowClaim();
		$claim->setRunUuid('run-1');
		$claim->setOwner('worker-7');
		$claim->setStreamId('stream-a');
		$claim->setTransition('approve');
		$now = new DateTime('2026-11-12 12:00:00');

		$abandoned = new AbandonedFlowClaim(claim: $claim, reap: new FlowReapRun(now: $now, minutes: 30));

		$this->assertSame($claim, $abandoned->claim);
		$this->assertSame($now, $abandoned->now);
		$this->assertSame('run-1', $abandoned->runUuid);
		$this->assertSame('stream-a', $abandoned->branch);
		$this->assertSame('approve', $abandoned->transition);
		$this->assertSame(
			'Abandoned branch: worker pass "worker-7" took stream "stream-a" at step "approve" and never finished it '
			. '(no commit for over 30 minutes). The step may have performed its side effect; it was NOT re-run.',
			$abandoned->error
		);
	}//end testClaimFieldsAndErrorMessage()

	public function testMissingStreamAndTransitionBecomeEmptyStrings(): void {
		$claim = new FlowClaim();
		$claim->setRunUuid('run-2');

		$abandoned = new AbandonedFlowClaim(claim: $claim, reap: new FlowReapRun(now: new DateTime(), minutes: 5));

		$this->assertSame('', $abandoned->branch);
		$this->assertSame('', $abandoned->transition);
		$this->assertStringContainsString('worker pass "" took stream "" at step ""', $abandoned->error);
		$this->assertStringContainsString('over 5 minutes', $abandoned->error);
	}//end testMissingStreamAndTransitionBecomeEmptyStrings()
}//end class
