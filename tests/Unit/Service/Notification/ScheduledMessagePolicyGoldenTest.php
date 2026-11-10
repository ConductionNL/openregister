<?php

/**
 * Golden characterization test for ScheduledMessagePolicy.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use DateTimeImmutable;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessagePolicy;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Notifications\Service\Notification\ClaimAttempt;

/**
 * Runs every public decision of the policy over a fixed table of rows at a
 * fixed moment (every timestamp carries an explicit offset, so neither
 * today's date nor the default timezone matters) and compares the answers.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessagePolicy
 */
final class ScheduledMessagePolicyGoldenTest extends TestCase {

	/**
	 * The sweep moment: 2026-05-01T12:00:00Z.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-05-01T12:00:00+00:00');
	}//end now()

	/**
	 * Rows and whether they are [claimable, due].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: bool, 2: bool}>
	 */
	public static function rows(): array {
		return [
			'empty-row' => [[], true, true],
			'pending-no-send-at' => [['state' => 'pending', 'sendAt' => '  '], true, true],
			'pending-past' => [['state' => 'pending', 'sendAt' => '2026-05-01T11:59:59+00:00'], true, true],
			'pending-exact' => [['state' => 'pending', 'sendAt' => '2026-05-01T14:00:00+02:00'], true, true],
			'pending-future' => [['state' => 'pending', 'sendAt' => '2026-05-01T12:00:01+00:00'], false, false],
			'pending-unparseable' => [['state' => 'pending', 'sendAt' => 'not a date'], false, false],
			'cancelled-due' => [['state' => 'cancelled'], false, true],
			'sent' => [['state' => 'sent'], false, true],
			'parked' => [['state' => 'parked'], false, true],
			'unknown-state' => [['state' => 'weird'], true, true],
			'claimed-fresh' => [['state' => 'claimed', 'claimedAt' => '2026-05-01T11:55:01+00:00'], false, true],
			'claimed-expired-exactly' => [['state' => 'claimed', 'claimedAt' => '2026-05-01T11:55:00+00:00'], true, true],
			'claimed-no-moment' => [['state' => 'claimed', 'claimedAt' => ''], true, true],
			'claimed-unparseable' => [['state' => 'claimed', 'claimedAt' => 'yesterday-ish'], true, true],
			'claimed-fresh-ignored-when-pending' => [['state' => 'pending', 'claimedAt' => '2026-05-01T11:59:00+00:00'], true, true],
			'attempts-below-max' => [['attempts' => '4'], true, true],
			'attempts-at-max' => [['attempts' => 5], false, true],
			'claimed-stale-but-out-of-attempts' => [['state' => 'claimed', 'claimedAt' => '', 'attempts' => 6], false, true],
		];
	}//end rows()

	/**
	 * Each row is claimable and due exactly as recorded.
	 *
	 * @param array<string, mixed> $message   The row.
	 * @param bool                 $claimable Recorded claimability.
	 * @param bool                 $due       Recorded dueness.
	 *
	 * @return void
	 *
	 * @dataProvider rows
	 */
	public function testClaimableAndDue(array $message, bool $claimable, bool $due): void {
		$policy = new ScheduledMessagePolicy();
		$this->assertSame($claimable, $policy->isClaimable($message, self::now()));
		$this->assertSame($due, $policy->isDue($message, self::now()));
	}//end testClaimableAndDue()

	/**
	 * The claim compare-and-set for a full row and an empty one.
	 *
	 * @return void
	 */
	public function testClaim(): void {
		$policy = new ScheduledMessagePolicy();
		$this->assertSame(
			[
				'expect' => ['id' => '42', 'state' => 'claimed', 'attempts' => 2],
				'set' => ['state' => 'claimed', 'attempts' => 3, 'claimedBy' => 'worker-a', 'claimedAt' => '2026-05-01T12:00:00+00:00'],
			],
			$policy->claim(['id' => 42, 'state' => 'claimed', 'attempts' => '2'], new ClaimAttempt('worker-a', self::now()))
		);
		$this->assertSame(
			[
				'expect' => ['id' => '', 'state' => 'pending', 'attempts' => 0],
				'set' => ['state' => 'claimed', 'attempts' => 1, 'claimedBy' => '', 'claimedAt' => '2026-05-01T12:00:00+00:00'],
			],
			$policy->claim([], new ClaimAttempt('', self::now()))
		);
	}//end testClaim()

	/**
	 * Failure parks at the attempt ceiling; success records the Message-ID.
	 *
	 * @return void
	 */
	public function testAfterFailureAndSuccess(): void {
		$policy = new ScheduledMessagePolicy();
		$this->assertSame(['state' => 'pending', 'lastError' => 'smtp'], $policy->afterFailure(['attempts' => 4], 'smtp'));
		$this->assertSame(['state' => 'parked', 'lastError' => 'smtp'], $policy->afterFailure(['attempts' => 5], 'smtp'));
		$this->assertSame(['state' => 'pending', 'lastError' => ''], $policy->afterFailure([], ''));
		$this->assertSame(['state' => 'sent', 'messageId' => '<m@x>', 'lastError' => ''], $policy->afterSuccess('<m@x>'));
	}//end testAfterFailureAndSuccess()
}//end class
