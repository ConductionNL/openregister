<?php

/**
 * The sweep's rules: one sender, no send after cancel, and a stopping point.
 *
 * 🔴 TWO WORKERS SENDING ONE MESSAGE IS THE FAILURE THAT REACHES A CITIZEN. It
 * arrives as two letters carrying one reference number, and nothing in the
 * system looks wrong afterwards. The claim compares the attempt count as well
 * as the state, so two sweeps that read the same pending row cannot both
 * write; that comparison is asserted rather than assumed.
 *
 * 🔴 CANCELLATION WINS OVER BEING DUE. The window between somebody pressing
 * cancel and the sweep reading the row is exactly when it matters, and a
 * cancelled row that is also due is the case a naive "select where due" gets
 * wrong.
 *
 * 🔴 AN UNPARSEABLE `sendAt` MUST NOT MEAN NOW. Reading a typo as "send
 * immediately" is the one outcome nobody asked for, and it is what a
 * `strtotime() ?: time()` would do.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Service\Notification\ScheduledMessagePolicy;
use PHPUnit\Framework\TestCase;

/**
 * The scheduled-message sweep.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md
 */
class ScheduledMessagePolicyTest extends TestCase {

	private ScheduledMessagePolicy $policy;
	private DateTimeImmutable $now;

	protected function setUp(): void {
		parent::setUp();
		$this->policy = new ScheduledMessagePolicy();
		$this->now = new DateTimeImmutable('2026-09-18T12:00:00+02:00');
	}//end setUp()

	/**
	 * One stored row.
	 *
	 * @param array<string,mixed> $overrides What to change.
	 *
	 * @return array<string,mixed> The row.
	 */
	private function message(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'msg-1',
				'state' => ScheduledMessagePolicy::PENDING,
				'attempts' => 0,
				'sendAt' => '2026-09-18T09:00:00+02:00',
				'claimedAt' => '',
			],
			$overrides
		);
	}//end message()

	public function testADueMessageIsClaimable(): void {
		$this->assertTrue($this->policy->isClaimable($this->message(), $this->now));
	}//end testADueMessageIsClaimable()

	public function testAMessageWhoseMomentHasNotComeIsNotTouched(): void {
		$later = $this->message(['sendAt' => '2026-09-18T18:00:00+02:00']);

		$this->assertFalse($this->policy->isClaimable($later, $this->now));
	}//end testAMessageWhoseMomentHasNotComeIsNotTouched()

	public function testAMissedWindowStillSends(): void {
		// The server was down at nine. A message silently abandoned for being
		// late is the worst of both outcomes.
		$late = $this->message(['sendAt' => '2026-09-01T09:00:00+02:00']);

		$this->assertTrue($this->policy->isClaimable($late, $this->now));
	}//end testAMissedWindowStillSends()

	public function testACancelledMessageIsNeverSentEvenWhenDue(): void {
		$cancelled = $this->message(['state' => ScheduledMessagePolicy::CANCELLED]);

		// The case a naive "select where due" gets wrong.
		$this->assertFalse($this->policy->isClaimable($cancelled, $this->now));
	}//end testACancelledMessageIsNeverSentEvenWhenDue()

	public function testACancelledMessageThatWasAlreadyClaimedIsStillNeverSent(): void {
		$cancelled = $this->message([
			'state' => ScheduledMessagePolicy::CANCELLED,
			'claimedAt' => $this->now->format('c'),
			'attempts' => 1,
		]);

		$this->assertFalse($this->policy->isClaimable($cancelled, $this->now));
	}//end testACancelledMessageThatWasAlreadyClaimedIsStillNeverSent()

	public function testASentMessageIsNotSentAgain(): void {
		$this->assertFalse(
			$this->policy->isClaimable($this->message(['state' => ScheduledMessagePolicy::SENT]), $this->now)
		);
	}//end testASentMessageIsNotSentAgain()

	public function testAFreshClaimKeepsOtherWorkersOff(): void {
		$held = $this->message([
			'state' => ScheduledMessagePolicy::CLAIMED,
			'claimedAt' => $this->now->modify('-10 seconds')->format('c'),
			'attempts' => 1,
		]);

		$this->assertFalse($this->policy->isClaimable($held, $this->now));
	}//end testAFreshClaimKeepsOtherWorkersOff()

	public function testAStaleClaimIsTakenOverRatherThanStuckForEver(): void {
		// A worker that died mid-send leaves its claim behind, and without an
		// expiry the row sits in a state that looks like progress.
		$abandoned = $this->message([
			'state' => ScheduledMessagePolicy::CLAIMED,
			'claimedAt' => $this->now->modify('-' . (ScheduledMessagePolicy::CLAIM_SECONDS + 60) . ' seconds')->format('c'),
			'attempts' => 1,
		]);

		$this->assertTrue($this->policy->isClaimable($abandoned, $this->now));
	}//end testAStaleClaimIsTakenOverRatherThanStuckForEver()

	public function testAClaimWithNoMomentIsTreatedAsStaleRatherThanEternal(): void {
		$odd = $this->message(['state' => ScheduledMessagePolicy::CLAIMED, 'claimedAt' => '', 'attempts' => 1]);

		$this->assertTrue($this->policy->isClaimable($odd, $this->now));
	}//end testAClaimWithNoMomentIsTreatedAsStaleRatherThanEternal()

	public function testTheClaimComparesTheStateAndTheAttemptCount(): void {
		$claim = $this->policy->claim($this->message(['attempts' => 2]), 'worker-a', $this->now);

		// Both, so two sweeps that read the same row cannot both write: the
		// second finds the attempt count moved and backs off. The failure this
		// prevents reaches a citizen as two letters with one reference number.
		$this->assertSame(ScheduledMessagePolicy::PENDING, $claim['expect']['state']);
		$this->assertSame(2, $claim['expect']['attempts']);
		$this->assertSame(ScheduledMessagePolicy::CLAIMED, $claim['set']['state']);
		$this->assertSame(3, $claim['set']['attempts'], 'the attempt is burned at claim time, not at send time');
		$this->assertSame('worker-a', $claim['set']['claimedBy']);
	}//end testTheClaimComparesTheStateAndTheAttemptCount()

	public function testAnUnparseableMomentDoesNotMeanNow(): void {
		$typo = $this->message(['sendAt' => 'morgenochtend']);

		// `strtotime() ?: time()` would send immediately, which is the one
		// outcome nobody asked for.
		$this->assertFalse($this->policy->isDue($typo, $this->now));
		$this->assertFalse($this->policy->isClaimable($typo, $this->now));
	}//end testAnUnparseableMomentDoesNotMeanNow()

	public function testNoMomentAtAllMeansSendAtTheFirstOpportunity(): void {
		$this->assertTrue($this->policy->isDue($this->message(['sendAt' => '']), $this->now));
	}//end testNoMomentAtAllMeansSendAtTheFirstOpportunity()

	public function testAFailureGoesBackToPendingUntilTheAttemptsAreSpent(): void {
		$after = $this->policy->afterFailure($this->message(['attempts' => 2]), 'connection refused');

		$this->assertSame(ScheduledMessagePolicy::PENDING, $after['state']);
		$this->assertSame('connection refused', $after['lastError']);
	}//end testAFailureGoesBackToPendingUntilTheAttemptsAreSpent()

	public function testASpentMessageIsParkedWithItsErrorRatherThanRetriedForEver(): void {
		$after = $this->policy->afterFailure(
			$this->message(['attempts' => ScheduledMessagePolicy::MAX_ATTEMPTS]),
			'550 mailbox unavailable'
		);

		// Not dropped: a row that vanished is a message somebody believes was
		// sent. Not retried: a mail server hammered about an address that will
		// never accept it.
		$this->assertSame(ScheduledMessagePolicy::PARKED, $after['state']);
		$this->assertStringContainsString('550', $after['lastError']);
	}//end testASpentMessageIsParkedWithItsErrorRatherThanRetriedForEver()

	public function testAParkedMessageIsNotPickedUpAgain(): void {
		$parked = $this->message(['state' => ScheduledMessagePolicy::PARKED, 'attempts' => 5]);

		$this->assertFalse($this->policy->isClaimable($parked, $this->now));
	}//end testAParkedMessageIsNotPickedUpAgain()

	public function testASuccessRecordsTheMessageIdAndClearsTheError(): void {
		$after = $this->policy->afterSuccess('<abc@example.org>');

		$this->assertSame(ScheduledMessagePolicy::SENT, $after['state']);
		$this->assertSame('<abc@example.org>', $after['messageId']);
		$this->assertSame('', $after['lastError']);
	}//end testASuccessRecordsTheMessageIdAndClearsTheError()
}//end class
