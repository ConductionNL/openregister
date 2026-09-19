<?php

/**
 * BulkJobRunner — the batched executor behind a committed bulk action.
 *
 * A queued job that advances one {@see \OCA\OpenRegister\Db\BulkJob} by a
 * single batch and re-enqueues itself while members remain, so four hundred
 * objects are walked incrementally with a resumable cursor that survives a
 * worker restart. Cancellation is cooperative: the executor reads the job's
 * state between every two objects and stops at the boundary.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Background executor for bulk action jobs.
 *
 * @psalm-suppress UnusedClass Enqueued by BulkJobService::commit().
 */
class BulkJobRunner extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param BulkJobMapper $jobMapper Job persistence.
	 * @param BulkJobService $bulkJobService The lifecycle service.
	 * @param IJobList $jobList Job list for re-enqueue.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly BulkJobMapper $jobMapper,
		private readonly BulkJobService $bulkJobService,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Run one batch of a bulk job.
	 *
	 * @param array<string, mixed> $argument Job arguments: job_id (required),
	 *                                       batch_size (optional).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	protected function run($argument): void {
		$jobId = ($argument['job_id'] ?? null);

		if ($jobId === null) {
			$this->logger->error(
				message: '[BulkJobRunner] Missing job_id argument',
				context: ['argument' => $argument]
			);

			return;
		}

		try {
			$job = $this->jobMapper->find((int)$jobId);
		} catch (\Throwable $exception) {
			$this->logger->error(
				message: '[BulkJobRunner] Job not found',
				context: ['job_id' => $jobId, 'error' => $exception->getMessage()]
			);

			return;
		}

		if ($job->getState() !== BulkJob::STATE_RUNNING) {
			// A cancelled, completed or failed job has nothing left to do,
			// and a cancelling one is finished off by the batch that saw the
			// cancel. Either way, do not re-enqueue.
			return;
		}

		$batchSize = (int)($argument['batch_size'] ?? $this->bulkJobService->getBatchSize());

		try {
			$more = $this->bulkJobService->processBatch(job: $job, batchSize: $batchSize);
		} catch (\Throwable $exception) {
			$this->logger->error(
				message: '[BulkJobRunner] Batch failed',
				context: ['job_id' => $jobId, 'error' => $exception->getMessage()]
			);

			$job->setState(BulkJob::STATE_FAILED);
			$report = ($job->getReport() ?? []);
			$report['fatal'] = $exception->getMessage();
			$job->setReport($report);
			$this->jobMapper->save($job);

			return;
		}//end try

		if ($more === true) {
			$this->jobList->add(self::class, ['job_id' => $jobId, 'batch_size' => $batchSize]);
		}
	}//end run()
}//end class
