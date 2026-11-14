<?php

/**
 * ImportRunRetention — prunes the per-row detail of old import runs, keeping the run.
 *
 * A million-row load cannot keep a million rows of detail forever (D-5). The
 * run header with its counts is kept; the per-row detail is pruned after an
 * administered period, and the run is marked so it reads as pruned rather
 * than as a run with no rows.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
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
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ImportPreviewMapperInterface;
use OCA\OpenRegister\Db\ImportPreviewRowMapperInterface;
use OCP\IAppConfig;

/**
 * Removes the rows of runs past the period and marks each run pruned.
 *
 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md
 */
class ImportRunRetention {

	/**
	 * The app config key holding the period, in days.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'import_run_row_retention_days';

	/**
	 * The period when none is administered.
	 *
	 * @var int
	 */
	public const DEFAULT_DAYS = 90;

	/**
	 * The runs handled per pass.
	 *
	 * @var int
	 */
	private const BATCH = 50;

	/**
	 * Constructor.
	 *
	 * @param ImportPreviewMapperInterface    $runMapper The run headers.
	 * @param ImportPreviewRowMapperInterface $rowMapper The per-row detail.
	 * @param IAppConfig                      $appConfig Where the period is administered.
	 */
	public function __construct(
		private readonly ImportPreviewMapperInterface $runMapper,
		private readonly ImportPreviewRowMapperInterface $rowMapper,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The administered period in days; a value below one falls back to the default.
	 *
	 * @return int The days.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md#requirement-import-detail-is-pruned-while-the-run-survives-req-rcn-007
	 */
	public function retentionDays(): int {
		$days = $this->appConfig->getValueInt(app: 'openregister', key: self::CONFIG_KEY, default: self::DEFAULT_DAYS);
		if ($days < 1) {
			return self::DEFAULT_DAYS;
		}

		return $days;
	}//end retentionDays()

	/**
	 * Prune the row detail of every run created more than the period ago.
	 *
	 * @param DateTimeImmutable $now     The moment of the pass.
	 * @param int               $batches At most this many batches of runs in one pass.
	 *
	 * @return int The number of runs pruned.
	 *
	 * @spec openspec/changes/runs-recorded-and-causes-named/specs/data-import-export/spec.md#requirement-import-detail-is-pruned-while-the-run-survives-req-rcn-007
	 */
	public function prune(DateTimeImmutable $now, int $batches = 20): int {
		$before = DateTime::createFromImmutable($now->modify('-'.$this->retentionDays().' days'));
		$pruned = 0;

		for ($pass = 0; $pass < max(1, $batches); $pass++) {
			$runs = $this->runMapper->findUnprunedBefore(before: $before, limit: self::BATCH);
			foreach ($runs as $run) {
				$this->rowMapper->deleteByPreview(previewId: (int)$run->getId());
				$run->setRowsPrunedAt(DateTime::createFromImmutable($now));
				$this->runMapper->persist(preview: $run);
				$pruned++;
			}

			if (count($runs) < self::BATCH) {
				break;
			}
		}

		return $pruned;
	}//end prune()
}//end class
