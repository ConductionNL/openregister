<?php

/**
 * ReindexProgress: the counts of one reindex run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

/**
 * Total, processed and failed, with each failed id and its reason.
 *
 * Processed counts every object the run handled, failed ones included, so a
 * finished run reads processed equal to total and names what failed.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
 */
final class ReindexProgress {

	/**
	 * The most failures the run row lists by id; the count stays exact.
	 *
	 * @var integer
	 */
	public const MAX_LISTED_FAILURES = 500;

	/**
	 * The objects handled so far, failed ones included.
	 *
	 * @var integer
	 */
	private int $processed = 0;

	/**
	 * The objects that failed so far.
	 *
	 * @var integer
	 */
	private int $failed = 0;

	/**
	 * The failed ids with their reasons, up to MAX_LISTED_FAILURES.
	 *
	 * @var array<int, array{id: string, reason: string}>
	 */
	private array $failures = [];

	/**
	 * Start counting.
	 *
	 * @param integer $total The objects the selection resolved to.
	 */
	public function __construct(
		public readonly int $total,
	) {
	}//end __construct()

	/**
	 * Count one object reindexed.
	 *
	 * @return void
	 */
	public function succeeded(): void {
		$this->processed++;

	}//end succeeded()

	/**
	 * Count one object that failed, with why.
	 *
	 * @param string $id     The object id.
	 * @param string $reason What went wrong.
	 *
	 * @return void
	 */
	public function failedOn(string $id, string $reason): void {
		$this->processed++;
		$this->failed++;

		if (count($this->failures) < self::MAX_LISTED_FAILURES) {
			$this->failures[] = ['id' => $id, 'reason' => $reason];
		}

	}//end failedOn()

	/**
	 * The objects handled so far, failed ones included.
	 *
	 * @return integer The count.
	 */
	public function processed(): int {
		return $this->processed;

	}//end processed()

	/**
	 * The progress as the run row's details.
	 *
	 * @return array{total: int, processed: int, failed: int, failures: array<int, array{id: string, reason: string}>, failuresTruncated: bool} The details.
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md#requirement-an-administrator-reindexes-a-selection-and-watches-it-run-req-sqf-007
	 */
	public function toArray(): array {
		return [
			'total' => $this->total,
			'processed' => $this->processed,
			'failed' => $this->failed,
			'failures' => $this->failures,
			'failuresTruncated' => ($this->failed > count($this->failures)),
		];

	}//end toArray()
}//end class
