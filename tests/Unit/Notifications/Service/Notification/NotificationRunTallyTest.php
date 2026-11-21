<?php

/**
 * Unit tests for NotificationRunTally: one evaluation's outcome, reason and counts.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Notifications\Service\Notification\NotificationRunTally;
use PHPUnit\Framework\TestCase;

/**
 * Sent, partial, failed and skipped per D-2.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NotificationRunTally
 */
final class NotificationRunTallyTest extends TestCase {

	public function testEveryDeliveryOutIsSent(): void {
		$tally = new NotificationRunTally();
		$tally->delivery(channel: 'email', delivered: true);
		$tally->delivery(channel: 'nc-notification', delivered: true);

		$this->assertSame('sent', $tally->outcome());
		$this->assertNull($tally->reason());
		$this->assertSame(['email' => ['sent' => 1, 'failed' => 0], 'nc-notification' => ['sent' => 1, 'failed' => 0]], $tally->counts());
	}

	public function testTwoOfThreeByMailIsPartial(): void {
		$tally = new NotificationRunTally();
		$tally->delivery(channel: 'email', delivered: true);
		$tally->delivery(channel: 'email', delivered: false);
		$tally->delivery(channel: 'email', delivered: true);

		$this->assertSame('partial', $tally->outcome());
		$this->assertSame(['email' => ['sent' => 2, 'failed' => 1]], $tally->counts());
	}

	public function testAHeldRecipientNextToADeliveryIsPartial(): void {
		$tally = new NotificationRunTally();
		$tally->delivery(channel: 'email', delivered: true);
		$tally->held(gate: NotificationRunTally::REASON_RATE_LIMIT);

		$this->assertSame('partial', $tally->outcome());
		$this->assertSame(['rate-limit' => 1], $tally->counts()['held']);
	}

	public function testNothingDeliveredAndSomethingFailedIsFailed(): void {
		$tally = new NotificationRunTally();
		$tally->delivery(channel: 'email', delivered: false);

		$this->assertSame('failed', $tally->outcome());
		$this->assertNull($tally->reason());
	}

	public function testADuplicateIsSkippedAsDuplicate(): void {
		$tally = new NotificationRunTally();
		$tally->skip(reason: NotificationRunTally::REASON_DUPLICATE);

		$this->assertSame('skipped', $tally->outcome());
		$this->assertSame('duplicate', $tally->reason());
		$this->assertSame([], $tally->counts());
	}

	public function testOnlyHeldRecipientsAreSkippedByTheGateThatHeldMost(): void {
		$tally = new NotificationRunTally();
		$tally->held(gate: NotificationRunTally::REASON_RATE_LIMIT);
		$tally->held(gate: NotificationRunTally::REASON_QUIET_HOURS);
		$tally->held(gate: NotificationRunTally::REASON_QUIET_HOURS);

		$this->assertSame('skipped', $tally->outcome());
		$this->assertSame('quiet-hours', $tally->reason());
	}

	public function testAnEmptyRunReachedNobody(): void {
		$tally = new NotificationRunTally();

		$this->assertSame('skipped', $tally->outcome());
		$this->assertSame('no-recipients', $tally->reason());
	}
}
