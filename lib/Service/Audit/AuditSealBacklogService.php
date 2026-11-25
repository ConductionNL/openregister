<?php

/**
 * AuditSealBacklogService: the one seal-backlog driver the occ command and the background job share.
 *
 * Sealing a whole backlog with one AuditHashService::sealRows() call would read
 * every row between the oldest and the newest unsealed id, which on the instance
 * this was written for was ~227,000 rows of ~5 KB each: an out-of-memory kill.
 * This driver cuts the backlog into windows bounded by both a row count and an
 * id span, seals them one at a time in ascending order (never concurrently: row
 * N's link is row N-1's hash), and lets every window commit on its own, so an
 * interrupted pass loses at most one window and the next pass resumes from the
 * oldest row still unsealed.
 *
 * It seals only rows AFTER the newest chain link (a sealed row that is no
 * retention tombstone). An unsealed row before that link cannot be sealed in
 * place: the link after it was chained over it, so giving it a hash makes that
 * link's stored hash disagree with its new predecessor and verifyChain() reports
 * the chain broken there. Those rows are counted as `unsealedBehindChain` and
 * left for `occ openregister:rechain-audit-trail`, the deliberate repair that may
 * rewrite sealed rows. Rows before the cutover marker among them are legacy and
 * do not fail verification.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Drains unsealed audit rows window by window, within a row cap and a time cap.
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
 */
class AuditSealBacklogService implements AuditSealBacklogInterface {

	/**
	 * Most unsealed rows sealed in one window (~10 MB of payload at ~5 KB a row).
	 *
	 * @var integer
	 */
	public const MAX_WINDOW_ROWS = 2000;

	/**
	 * Widest id range one window may cover.
	 *
	 * Unsealed rows can be scattered: 2,000 of them across 200,000 ids would make
	 * the window's `id, hash` skeleton read 200,000 rows. The span cap keeps that
	 * read bounded however sparse the region is.
	 *
	 * @var integer
	 */
	public const MAX_WINDOW_SPAN = 20000;

	/**
	 * Constructor.
	 *
	 * @param AuditSealBacklogCursorInterface $cursor Finds the unsealed rows by id.
	 * @param AuditSealWindowInterface        $sealer Seals one window under the seal lock.
	 * @param ITimeFactory                    $time   The clock the time cap is read from.
	 */
	public function __construct(
		private readonly AuditSealBacklogCursorInterface $cursor,
		private readonly AuditSealWindowInterface $sealer,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Seal unsealed rows in ascending id order until drained or a cap is reached.
	 *
	 * @param int           $maxRows    Most rows to seal in this pass (at least 1).
	 * @param int           $maxSeconds Most wall-clock seconds to spend, 0 for no limit.
	 * @param callable|null $onWindow   Called after each sealed window with the counters so far.
	 *
	 * @return array{rowsSealed: int, windowsProcessed: int, windowsAbandoned: int, stoppedBy: string, unsealedBehindChain: int}
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
	 */
	public function drain(int $maxRows, int $maxSeconds = 0, ?callable $onWindow = null): array {
		$lastLink = $this->cursor->lastLinkId();
		$counters = $this->sealWindowsAfter(
			afterId: $lastLink,
			maxRows: $maxRows,
			deadline: $this->deadline(maxSeconds: $maxSeconds),
			onWindow: $onWindow
		);

		$counters['unsealedBehindChain'] = 0;
		if ($lastLink > 0) {
			$counters['unsealedBehindChain'] = $this->cursor->countUnsealedBefore(beforeId: $lastLink);
		}

		return $counters;
	}//end drain()

	/**
	 * The time by which the pass must stop, or null for no time cap.
	 *
	 * @param int $maxSeconds Most seconds to spend, 0 for no limit.
	 *
	 * @return int|null The deadline as a unix time.
	 */
	private function deadline(int $maxSeconds): ?int {
		if ($maxSeconds < 1) {
			return null;
		}

		return ($this->time->getTime() + $maxSeconds);
	}//end deadline()

	/**
	 * Seal window after window from the given id on, until a stop condition holds.
	 *
	 * @param int           $afterId  The id the first window starts after.
	 * @param int           $maxRows  Most rows to seal.
	 * @param int|null      $deadline The unix time to stop at, or null.
	 * @param callable|null $onWindow Called after each sealed window.
	 *
	 * @return array{rowsSealed: int, windowsProcessed: int, windowsAbandoned: int, stoppedBy: string}
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-the-scheduled-seal-job-shall-be-hard-bounded-per-invocation
	 */
	private function sealWindowsAfter(int $afterId, int $maxRows, ?int $deadline, ?callable $onWindow): array {
		$counters = ['rowsSealed' => 0, 'windowsProcessed' => 0, 'windowsAbandoned' => 0, 'stoppedBy' => 'drained'];

		while (true) {
			$stop = $this->capReached(sealed: $counters['rowsSealed'], maxRows: $maxRows, deadline: $deadline);
			if ($stop !== null) {
				$counters['stoppedBy'] = $stop;
				return $counters;
			}

			$window = self::cutWindow(
				ids: $this->cursor->unsealedIdsAfter(
					afterId: $afterId,
					limit: min(self::MAX_WINDOW_ROWS, ($maxRows - $counters['rowsSealed']))
				)
			);
			if ($window === []) {
				return $counters;
			}

			$sealed = $this->sealer->sealRows(ids: $window);
			if ($sealed === 0) {
				// The window's rows were unsealed a moment ago, so nothing sealed
				// means the seal lock was busy. Abandon this window, not the
				// backlog: the next pass starts from the same oldest row.
				$counters['windowsAbandoned']++;
				$counters['stoppedBy'] = 'lock';
				return $counters;
			}

			$counters['rowsSealed'] += $sealed;
			$counters['windowsProcessed']++;
			$afterId = max($window);
			if ($onWindow !== null) {
				$onWindow($counters);
			}
		}//end while
	}//end sealWindowsAfter()

	/**
	 * Which cap stops the pass now, if any.
	 *
	 * @param int      $sealed   Rows sealed so far.
	 * @param int      $maxRows  The row cap.
	 * @param int|null $deadline The unix time to stop at, or null.
	 *
	 * @return string|null `row-cap`, `time-cap`, or null to carry on.
	 */
	private function capReached(int $sealed, int $maxRows, ?int $deadline): ?string {
		if ($sealed >= $maxRows) {
			return 'row-cap';
		}

		if ($deadline !== null && $this->time->getTime() >= $deadline) {
			return 'time-cap';
		}

		return null;
	}//end capReached()

	/**
	 * The leading ids of an ascending list that fit in one window's id span.
	 *
	 * @param list<int> $ids Unsealed ids, ascending, at most MAX_WINDOW_ROWS of them.
	 *
	 * @return list<int> The window, empty when the list is.
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-a-bounded-resumable-seal-pass-shall-drain-unsealed-audit-rows-in-chain-order
	 */
	public static function cutWindow(array $ids): array {
		if ($ids === []) {
			return [];
		}

		$last = ($ids[0] + self::MAX_WINDOW_SPAN - 1);
		$window = [];
		foreach ($ids as $id) {
			if ($id > $last) {
				break;
			}

			$window[] = $id;
		}

		return $window;
	}//end cutWindow()
}//end class
