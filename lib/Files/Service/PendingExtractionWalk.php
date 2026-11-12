<?php

/**
 * OpenRegister Pending Extraction Walk
 *
 * Counters of one walk over the files that still wait for text extraction.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

/**
 * Counters and position of one extractPendingFiles() walk: how many files were extracted,
 * failed and seen, which window is next, and how many failures the current window had.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class PendingExtractionWalk {

	/**
	 * Files extracted.
	 *
	 * @var integer
	 */
	public int $processed = 0;

	/**
	 * Files that failed to extract.
	 *
	 * @var integer
	 */
	public int $failed = 0;

	/**
	 * Files returned by all windows together.
	 *
	 * @var integer
	 */
	public int $seen = 0;

	/**
	 * Offset of the next window.
	 *
	 * @var integer
	 */
	public int $offset = 0;

	/**
	 * Windows fetched so far.
	 *
	 * @var integer
	 */
	public int $windows = 0;

	/**
	 * Failures in the current window.
	 *
	 * @var integer
	 */
	public int $failedInWindow = 0;

	/**
	 * Number of files in the last window fetched.
	 *
	 * @var integer
	 */
	public int $lastWindowSize = 0;

	/**
	 * Constructor.
	 *
	 * @param int $limit Maximum number of files to extract, also the window size.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly int $limit,
	) {
	}//end __construct()

	/**
	 * Whether fewer files than the limit have been extracted.
	 *
	 * @return bool True while the budget has room.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function hasBudget(): bool {
		return $this->processed < $this->limit;
	}//end hasBudget()

	/**
	 * The walk's statistics.
	 *
	 * The walk is capped at $maxWindows windows. Hitting that cap while the budget still had
	 * room means files may remain pending that this run never looked at, which the counters
	 * alone cannot tell apart from a finished run; `truncated` says so.
	 *
	 * @param int $maxWindows The window cap of the walk.
	 *
	 * @return array{processed: int, failed: int, total: int, truncated: bool}
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function result(int $maxWindows): array {
		return [
			'processed' => $this->processed,
			'failed' => $this->failed,
			'total' => $this->seen,
			'truncated' => ($this->windows >= $maxWindows && $this->hasBudget() === true),
		];
	}//end result()
}//end class
