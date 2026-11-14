<?php

/**
 * OpenRegister NotificationRunTally
 *
 * Counts what one evaluation of a declared notification did, per channel,
 * and turns it into a run outcome. Recipients are counted, never named.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The aggregate of one notification evaluation (D-2): `sent` when every
 * delivery went out, `partial` when some did, `failed` when none did and some
 * failed, `skipped` with the gate that stopped it when nothing was attempted.
 *
 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
 */
final class NotificationRunTally {

	public const SENT = 'sent';

	public const PARTIAL = 'partial';

	public const FAILED = 'failed';

	public const SKIPPED = 'skipped';

	public const REASON_DUPLICATE = 'duplicate';

	public const REASON_NO_RECIPIENTS = 'no-recipients';

	public const REASON_RATE_LIMIT = 'rate-limit';

	public const REASON_QUIET_HOURS = 'quiet-hours';

	/**
	 * Counts per channel: `sent` and `failed`.
	 *
	 * @var array<string, array{sent: int, failed: int}>
	 */
	private array $channels = [];

	/**
	 * Recipients a gate held back, per gate.
	 *
	 * @var array<string, int>
	 */
	private array $held = [];

	/**
	 * Why the whole run was skipped, when it was.
	 *
	 * @var string
	 */
	private string $skipReason = '';

	/**
	 * Count one delivery over a channel.
	 *
	 * @param string $channel   The channel.
	 * @param bool   $delivered Whether it went out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	public function delivery(string $channel, bool $delivered): void {
		$counts = ($this->channels[$channel] ?? ['sent' => 0, 'failed' => 0]);
		if ($delivered === true) {
			$counts['sent']++;
		} else {
			$counts['failed']++;
		}

		$this->channels[$channel] = $counts;
	}//end delivery()

	/**
	 * Count one recipient a gate held back (rate limit, quiet hours, preference, coalescing).
	 *
	 * @param string $gate The gate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	public function held(string $gate): void {
		$this->held[$gate] = ($this->held[$gate] ?? 0) + 1;
	}//end held()

	/**
	 * Mark the whole run skipped before any delivery (duplicate, no recipients).
	 *
	 * @param string $reason The gate that stopped it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	public function skip(string $reason): void {
		$this->skipReason = $reason;
	}//end skip()

	/**
	 * The run outcome.
	 *
	 * @return string sent, partial, failed or skipped.
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	public function outcome(): string {
		$sent   = $this->total(field: 'sent');
		$failed = $this->total(field: 'failed');
		$held   = array_sum($this->held);

		if ($this->skipReason !== '' || ($sent === 0 && $failed === 0)) {
			return self::SKIPPED;
		}

		if ($failed === 0 && $held === 0) {
			return self::SENT;
		}

		if ($sent === 0 && $held === 0) {
			return self::FAILED;
		}

		return self::PARTIAL;
	}//end outcome()

	/**
	 * Why a skipped run was skipped: its own reason, else the gate that held most recipients.
	 *
	 * @return string|null The reason, null when the run was not skipped.
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	public function reason(): ?string {
		if ($this->outcome() !== self::SKIPPED) {
			return null;
		}

		if ($this->skipReason !== '') {
			return $this->skipReason;
		}

		if ($this->held === []) {
			return self::REASON_NO_RECIPIENTS;
		}

		$held = $this->held;
		arsort($held);

		return (string)array_key_first($held);
	}//end reason()

	/**
	 * The counts per channel, with the held recipients under `held`.
	 *
	 * @return array<string, array<string, int>> The counts.
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	public function counts(): array {
		$counts = $this->channels;
		if ($this->held !== []) {
			$counts['held'] = $this->held;
		}

		return $counts;
	}//end counts()

	/**
	 * The sum of one count over every channel.
	 *
	 * @param string $field sent or failed.
	 *
	 * @return int The sum.
	 */
	private function total(string $field): int {
		return array_sum(array_column($this->channels, $field));
	}//end total()
}//end class
