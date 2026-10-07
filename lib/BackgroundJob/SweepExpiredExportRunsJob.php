<?php

/**
 * Deletes the files of expired export runs, and keeps the rows.
 *
 * An expiry that no job reads is a label, not a rule. This is what makes a
 * copy of the register stop existing at the moment its run said it would.
 *
 * It calls `ExportRunRecorder::sweep()`, which selects on `expires_at`, the
 * column the recorder wrote. Neither side looks at a file timestamp: one
 * component writing a timestamp and another reading one is how every export
 * in this fleet once arrived already expired, with a green unit test on each
 * half of it.
 *
 * The row is kept on purpose. The fact that an export happened outlives the
 * copy it made, and that fact is what an administrator is asked about.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sweeps the files of expired export runs.
 */
class SweepExpiredExportRunsJob extends TimedJob {

	/**
	 * How often the sweep runs, in seconds.
	 *
	 * Hourly. A retention is measured in days, so an hour of slack between the
	 * deadline and the deletion is within what the promise means.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 3600;

	/**
	 * How many sweeps one tick makes.
	 *
	 * Each sweep takes at most ExportRunRecorder::SWEEP_BATCH runs, so a
	 * backlog on an instance that has been running without this job drains
	 * over a few ticks rather than in one long pass.
	 *
	 * @var int
	 */
	private const PASSES_PER_RUN = 5;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time   The clock the scheduler uses.
	 * @param ExportRunRecorder $runs   The export runs.
	 * @param LoggerInterface   $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ExportRunRecorder $runs,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Run one tick.
	 *
	 * @param mixed $argument Job argument, unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) `$argument` is TimedJob's contract, and this
	 *     sweep takes no argument: it asks the recorder which runs are due and the recorder asks
	 *     the clock.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	protected function run($argument): void {
		$total = 0;

		for ($pass = 0; $pass < self::PASSES_PER_RUN; $pass++) {
			try {
				$swept = $this->runs->sweep();
			} catch (Throwable $e) {
				$this->logger->error(
					message: '[SweepExpiredExportRunsJob] The sweep failed',
					context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
				);

				return;
			}

			$total += $swept;

			// A short pass means the backlog is drained; asking again would
			// only repeat an empty query.
			if ($swept < ExportRunRecorder::SWEEP_BATCH) {
				break;
			}
		}

		if ($total > 0) {
			$this->logger->info(
				message: '[SweepExpiredExportRunsJob] Expired export files removed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'swept' => $total]
			);
		}
	}//end run()
}//end class
