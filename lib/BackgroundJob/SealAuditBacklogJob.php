<?php

/**
 * Drains the backlog of unsealed audit rows a bounded bite at a time.
 *
 * AuditSealJob seals new rows and re-chains a short tail; it stands down when
 * the oldest gap has more than a few thousand rows after it, so a large backlog
 * never drains there. This job drains it, through the same driver the
 * `occ openregister:audit:seal-backlog` command runs.
 *
 * It is hard-bounded on purpose. The incident this was written after was an
 * unbounded maintenance pass that had run for 74 minutes with ~12 hours to go.
 * Each run stops at a row cap or a time cap, whichever comes first, and the next
 * run carries on from the oldest row still unsealed. The seal lock is taken per
 * window and released between windows, and a window that finds the lock busy is
 * abandoned and the run ends, so the job never queues behind other sealers.
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
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Audit\AuditSealBacklogInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seals up to a capped number of backlog rows per run.
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-the-scheduled-seal-job-shall-be-hard-bounded-per-invocation
 */
class SealAuditBacklogJob extends TimedJob {

	/**
	 * How often the job runs, in seconds.
	 *
	 * @var integer
	 */
	private const INTERVAL_SECONDS = 600;

	/**
	 * Most rows one run seals.
	 *
	 * @var integer
	 */
	public const MAX_ROWS_PER_RUN = 20000;

	/**
	 * Most wall-clock seconds one run spends.
	 *
	 * @var integer
	 */
	public const MAX_SECONDS_PER_RUN = 120;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time    The scheduler clock.
	 * @param AuditSealBacklogInterface $backlog Drains the backlog.
	 * @param LoggerInterface           $logger  The run log.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly AuditSealBacklogInterface $backlog,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);

	}//end __construct()

	/**
	 * Drain one capped bite of the backlog.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-the-scheduled-seal-job-shall-be-hard-bounded-per-invocation
	 */
	protected function run($argument): void {
		try {
			$this->drainAndReport();
		} catch (Throwable $e) {
			// Never let a failing drain break cron for every other job.
			$this->logger->error(
				message: '[SealAuditBacklogJob] Seal backlog pass failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
			);
		}
	}//end run()

	/**
	 * Run the capped pass and log what it did, staying silent when there was nothing to do.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-the-scheduled-seal-job-shall-be-hard-bounded-per-invocation
	 */
	private function drainAndReport(): void {
		$result = $this->backlog->drain(
			maxRows: self::MAX_ROWS_PER_RUN,
			maxSeconds: self::MAX_SECONDS_PER_RUN
		);

		if ($result['rowsSealed'] === 0 && $result['windowsAbandoned'] === 0) {
			return;
		}

		$this->logger->info(
			message: sprintf(
				'[SealAuditBacklogJob] Sealed %d audit row(s) in %d window(s); stopped: %s; '
				. '%d unsealed row(s) lie before the newest sealed row.',
				$result['rowsSealed'],
				$result['windowsProcessed'],
				$result['stoppedBy'],
				$result['unsealedBehindChain']
			),
			context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister']
		);
	}//end drainAndReport()
}//end class
