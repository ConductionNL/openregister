<?php

/**
 * OpenRegister audit aggregation.
 *
 * The administered window within which consecutive edits by one actor on one
 * object are recorded as a single entry, and the fold that merges them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use DateTimeInterface;
use OCP\IAppConfig;

/**
 * Decides whether two consecutive edits are one entry, and folds them if so.
 *
 * WHY THE DEFAULT IS OFF.
 *
 * OpenProject merges consecutive edits by default. It is convenient and it
 * loses the order, and an audit trail that merges away the order of two edits
 * is answering a different question than the auditor asked (D-5). So nothing
 * merges unless an administrator sets a window, and a merged entry says how
 * many edits it covers rather than quietly looking like one.
 *
 * WHY THE WINDOW IS CAPPED AT FIVE MINUTES, AND WHY THAT IS NOT ARBITRARY.
 *
 * Merging means amending an entry that is already written. `AuditTrailMapper`
 * deliberately does not seal a row on insert: sealing is left to
 * `AuditSealJob`, which runs every 300 seconds. An unsealed row is not yet
 * part of the hash chain, so amending it is ordinary writing. A SEALED row is
 * part of the chain and amending one is tampering, whoever does it. The cap is
 * that interval, and the merge additionally refuses any row that is already
 * sealed, so the rule holds even when the job runs early.
 *
 * A window longer than the seal interval would therefore merge only the edits
 * that happened to arrive before the sweep, which is a rule nobody could
 * predict the behaviour of. Refusing to store one is better than storing one
 * that means something different every five minutes.
 */
class AuditAggregationService {

	/**
	 * The app-config key holding the window, in seconds.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'audit_aggregation_window_seconds';

	/**
	 * The longest window an administrator may set, in seconds.
	 *
	 * Equal to `AuditSealJob::INTERVAL_SECONDS`. See the class docblock for
	 * why that is the ceiling rather than a round number.
	 *
	 * @var integer
	 */
	public const MAXIMUM_WINDOW_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The instance settings store.
	 */
	public function __construct(private readonly IAppConfig $appConfig) {
	}//end __construct()

	/**
	 * The administered window, in seconds. Zero means no merging.
	 *
	 * @return integer The window, clamped to the seal interval.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	public function windowSeconds(): int {
		$stored = $this->appConfig->getValueInt('openregister', self::CONFIG_KEY, 0);

		return $this->clamp(seconds: $stored);
	}//end windowSeconds()

	/**
	 * Store the window, refusing anything outside the range.
	 *
	 * @param integer $seconds The window an administrator asked for.
	 *
	 * @return integer The window that was stored.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	public function setWindowSeconds(int $seconds): int {
		$clamped = $this->clamp(seconds: $seconds);
		$this->appConfig->setValueInt('openregister', self::CONFIG_KEY, $clamped);

		return $clamped;
	}//end setWindowSeconds()

	/**
	 * Clamp a window to the range the seal interval allows.
	 *
	 * @param integer $seconds The window to clamp.
	 *
	 * @return integer A window between zero and the seal interval.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	public function clamp(int $seconds): int {
		if ($seconds <= 0) {
			return 0;
		}

		return min($seconds, self::MAXIMUM_WINDOW_SECONDS);
	}//end clamp()

	/**
	 * Whether two entries are close enough in time to be one.
	 *
	 * Takes both instants rather than reading the clock, so the behaviour is
	 * testable without waiting five minutes for the answer.
	 *
	 * @param DateTimeInterface|null $previous When the entry already written was written.
	 * @param DateTimeInterface $now When the edit being recorded happened.
	 * @param integer $window The administered window, in seconds.
	 *
	 * @return boolean Whether the two merge.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	public function isWithinWindow(?DateTimeInterface $previous, DateTimeInterface $now, int $window): bool {
		if ($window <= 0 || $previous === null) {
			return false;
		}

		$elapsed = ($now->getTimestamp() - $previous->getTimestamp());
		if ($elapsed < 0) {
			return false;
		}

		return $elapsed <= $window;
	}//end isWithinWindow()

	/**
	 * Fold an edit into the entry already written.
	 *
	 * The oldest `old` and the newest `new` survive per property, which is what
	 * makes the merged entry a true statement about the span it covers. The
	 * count is the point of the whole thing: a merged entry that does not say
	 * it merged is a lie of omission, and a reader has no way to tell three
	 * edits from one.
	 *
	 * @param array $previous The `changed` column of the entry already written.
	 * @param array $incoming The `changed` column of the edit being folded in.
	 *
	 * @return array The merged `changed` column.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	public function fold(array $previous, array $incoming): array {
		$aggregation = ($previous['aggregation'] ?? null);
		$edits = 1;
		if (is_array($aggregation) === true && is_numeric($aggregation['edits'] ?? null) === true) {
			$edits = (int)$aggregation['edits'];
		}

		unset($previous['aggregation'], $incoming['aggregation']);

		$merged = $previous;
		foreach ($incoming as $property => $change) {
			$existing = ($merged[$property] ?? null);

			// A property this entry has not seen before joins as it arrived.
			if (is_array($existing) === false || array_key_exists('old', $existing) === false) {
				$merged[$property] = $change;
				continue;
			}

			if (is_array($change) === false || array_key_exists('new', $change) === false) {
				continue;
			}

			// The first `old` is what the value was when the span began, and
			// the last `new` is what it is now. Keeping the later `old` would
			// describe a change nobody made.
			$merged[$property] = [
				'old' => $existing['old'],
				'new' => $change['new'],
			];
		}//end foreach

		$merged['aggregation'] = ['edits' => ($edits + 1)];

		return $merged;
	}//end fold()
}//end class
