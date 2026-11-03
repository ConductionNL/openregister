<?php

/**
 * When a scheduled message may be sent, by whom, and when it stops trying.
 *
 * 🔑 THE SWEEP IS THE DANGEROUS PART OF SCHEDULING, not the scheduling. A row
 * that says "send this at nine" is harmless; a job that reads it is where a
 * message gets sent twice, sent after it was cancelled, or retried for ever.
 * The rules live here rather than inside the job so they can be driven without
 * a database and cannot be re-invented the next time somebody writes a worker.
 *
 * 🔴 TWO WORKERS MUST NOT BOTH SEND. The claim is compare-and-set on the
 * STATE, never "select the due rows and then send them": between the select
 * and the send another sweep reads the same row, and the recipient gets two
 * copies of a letter with one reference number. This class says what a claim
 * must compare and what it must set; the mapper performs it.
 *
 * 🔴 A CANCELLED MESSAGE IS NEVER SENT, even when it is due and even when a
 * worker already claimed it. Cancellation is the one state that wins over
 * being due, because somebody pressed cancel for a reason and the window
 * between their press and the sweep is exactly when it matters.
 *
 * 🔴 A MESSAGE THAT RAN OUT OF ATTEMPTS IS PARKED WITH ITS LAST ERROR, not
 * retried for ever and not silently dropped. A row that vanishes is a message
 * somebody believes was sent; a row that retries for ever is a mail server
 * being hammered about an address that will never accept it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
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

namespace OCA\OpenRegister\Service\Notification;

use DateTimeImmutable;

/**
 * The rules a scheduled-message sweep obeys.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md
 */
class ScheduledMessagePolicy {

	/**
	 * Waiting for its moment.
	 *
	 * @var string
	 */
	public const PENDING = 'pending';

	/**
	 * A worker holds it and is sending.
	 *
	 * @var string
	 */
	public const CLAIMED = 'claimed';

	/**
	 * It went out.
	 *
	 * @var string
	 */
	public const SENT = 'sent';

	/**
	 * Somebody cancelled it before it went.
	 *
	 * @var string
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * It ran out of attempts and is waiting for a person.
	 *
	 * @var string
	 */
	public const PARKED = 'parked';

	/**
	 * How many times a message is tried before it is parked.
	 *
	 * @var int
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * How long a claim is honoured before another worker may take the row.
	 *
	 * A worker that dies mid-send leaves its claim behind, and without an
	 * expiry the message is stuck for ever in a state that looks like
	 * progress. Long enough that a slow SMTP server is not overtaken.
	 *
	 * @var int
	 */
	public const CLAIM_SECONDS = 300;

	/**
	 * How many messages one sweep takes.
	 *
	 * @var int
	 */
	public const SWEEP_LIMIT = 50;

	/**
	 * Whether this row may be claimed by a sweep running now.
	 *
	 * @param array<string,mixed> $message The stored row.
	 * @param DateTimeImmutable   $now     The moment the sweep is running.
	 *
	 * @return bool True when the sweep may take it.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md
	 */
	public function isClaimable(array $message, DateTimeImmutable $now): bool {
		$state = (string)($message['state'] ?? self::PENDING);

		// Cancellation wins over being due, over being claimed, over
		// everything. The window between somebody pressing cancel and the
		// sweep reading the row is exactly when this matters.
		if ($state === self::CANCELLED || $state === self::SENT || $state === self::PARKED) {
			return false;
		}

		if ($state === self::CLAIMED && $this->claimIsFresh(message: $message, now: $now) === true) {
			// Another worker holds it and is still within its window.
			return false;
		}

		if ((int)($message['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
			return false;
		}

		return ($this->isDue(message: $message, now: $now) === true);
	}//end isClaimable()

	/**
	 * Whether a message's moment has come.
	 *
	 * A `sendAt` in the PAST sends now rather than being skipped: a sweep that
	 * missed its window because the server was down must still deliver, and a
	 * message silently abandoned for being late is the worst of both.
	 *
	 * @param array<string,mixed> $message The row.
	 * @param DateTimeImmutable   $now     Now.
	 *
	 * @return bool True when it is due.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md
	 */
	public function isDue(array $message, DateTimeImmutable $now): bool {
		$sendAt = trim((string)($message['sendAt'] ?? ''));
		if ($sendAt === '') {
			// No moment named means send at the first opportunity, which is
			// what an immediate send through the same table looks like.
			return true;
		}

		$moment = strtotime($sendAt);
		if ($moment === false) {
			// An unparseable moment is NOT treated as "now": a typo would then
			// send immediately, which is the one outcome nobody asked for.
			return false;
		}

		return ($moment <= $now->getTimestamp());
	}//end isDue()

	/**
	 * The compare-and-set a claim performs.
	 *
	 * Returned as data rather than executed, so the one place that decides
	 * what a claim means is not also the place that talks to the database, and
	 * so a test can assert the comparison without one.
	 *
	 * @param array<string,mixed> $message The row.
	 * @param string              $worker  Who is claiming.
	 * @param DateTimeImmutable   $now     Now.
	 *
	 * @return array{expect:array<string,mixed>,set:array<string,mixed>}
	 *         What must still be true, and what to write.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md
	 */
	public function claim(array $message, string $worker, DateTimeImmutable $now): array {
		return [
			// The STATE and the attempt count are both compared: two sweeps
			// that read the same pending row write different attempt counts,
			// so whichever lands second finds the row changed and backs off.
			'expect' => [
				'id' => (string)($message['id'] ?? ''),
				'state' => (string)($message['state'] ?? self::PENDING),
				'attempts' => (int)($message['attempts'] ?? 0),
			],
			'set' => [
				'state' => self::CLAIMED,
				'attempts' => ((int)($message['attempts'] ?? 0) + 1),
				'claimedBy' => $worker,
				'claimedAt' => $now->format('c'),
			],
		];
	}//end claim()

	/**
	 * What to write when a send failed.
	 *
	 * @param array<string,mixed> $message The row, after its claim.
	 * @param string              $error   What went wrong.
	 *
	 * @return array<string,mixed> The fields to write.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md
	 */
	public function afterFailure(array $message, string $error): array {
		$attempts = (int)($message['attempts'] ?? 0);

		if ($attempts >= self::MAX_ATTEMPTS) {
			// Parked, and the last error is kept. A row that vanished would be
			// a message somebody believes was sent.
			return ['state' => self::PARKED, 'lastError' => $error];
		}

		// Back to pending so the next sweep picks it up; the attempt count
		// already moved when it was claimed, so a worker that dies after
		// claiming still burns one attempt rather than looping for ever.
		return ['state' => self::PENDING, 'lastError' => $error];
	}//end afterFailure()

	/**
	 * What to write when a send succeeded.
	 *
	 * @param string $messageId The Message-ID the channel minted.
	 *
	 * @return array<string,mixed> The fields to write.
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md
	 */
	public function afterSuccess(string $messageId): array {
		return ['state' => self::SENT, 'messageId' => $messageId, 'lastError' => ''];
	}//end afterSuccess()

	/**
	 * Whether a claim is still within its window.
	 *
	 * @param array<string,mixed> $message The row.
	 * @param DateTimeImmutable   $now     Now.
	 *
	 * @return bool True when another worker still holds it.
	 */
	private function claimIsFresh(array $message, DateTimeImmutable $now): bool {
		$claimedAt = trim((string)($message['claimedAt'] ?? ''));
		if ($claimedAt === '') {
			// Claimed with no moment recorded: treat the claim as stale rather
			// than as eternal, or the row is stuck for ever.
			return false;
		}

		$moment = strtotime($claimedAt);
		if ($moment === false) {
			return false;
		}

		return (($moment + self::CLAIM_SECONDS) > $now->getTimestamp());
	}//end claimIsFresh()
}//end class
