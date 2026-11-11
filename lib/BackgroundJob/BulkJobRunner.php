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
use OCA\OpenRegister\Db\BulkJobMapperInterface;
use OCA\OpenRegister\Service\BulkJob\BulkJobServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\BackgroundJob\Support\BulkJobServices;

/**
 * Background executor for bulk action jobs.
 *
 * @psalm-suppress UnusedClass Enqueued by BulkJobService::commit().
 */
class BulkJobRunner extends QueuedJob {
	/**
	 * Job persistence.
	 *
	 * @var BulkJobMapperInterface
	 */
	private readonly BulkJobMapperInterface $jobMapper;

	/**
	 * The lifecycle service.
	 *
	 * @var BulkJobServiceInterface
	 */
	private readonly BulkJobServiceInterface $bulkJobService;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory            $time           Time factory.
	 * @param BulkJobServices $services Bulk job store and slice runner.
	 * @param IJobList                $jobList        Job list for re-enqueue.
	 * @param LoggerInterface         $logger         Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		BulkJobServices $services,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
		$this->jobMapper = $services->jobMapper;
		$this->bulkJobService = $services->bulkJobService;
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
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	protected function run($argument): void {
		$job = $this->runningJob(argument: $argument);
		if ($job !== null) {
			$this->runBatch(job: $job, argument: $argument);
		}
	}//end run()

	/**
	 * The job a run is for, when it still has work to do.
	 *
	 * @param mixed $argument Job arguments.
	 *
	 * @return BulkJob|null The running job; null when the argument names none,
	 *                      it cannot be found or it is not running.
	 */
	private function runningJob($argument): ?BulkJob {
		$jobId = ($argument['job_id'] ?? null);

		if ($jobId === null) {
			$this->logger->error(
				message: '[BulkJobRunner] Missing job_id argument',
				context: ['argument' => $argument]
			);

			return null;
		}

		$job = $this->findJob(jobId: $jobId);
		if ($job === null || $job->getState() !== BulkJob::STATE_RUNNING) {
			// A cancelled, completed or failed job has nothing left to do,
			// and a cancelling one is finished off by the batch that saw the
			// cancel. Either way, do not re-enqueue.
			return null;
		}

		return $job;
	}//end runningJob()

	/**
	 * Load a bulk job, logging when it cannot be found.
	 *
	 * @param mixed $jobId The job id from the arguments.
	 *
	 * @return BulkJob|null
	 */
	private function findJob($jobId): ?BulkJob {
		try {
			return $this->jobMapper->find((int)$jobId);
		} catch (\Throwable $exception) {
			$this->logger->error(
				message: '[BulkJobRunner] Job not found',
				context: ['job_id' => $jobId, 'error' => $exception->getMessage()]
			);

			return null;
		}
	}//end findJob()

	/**
	 * Walk one batch of a running job and re-enqueue it while members remain.
	 *
	 * @param BulkJob $job      The running job.
	 * @param mixed   $argument Job arguments.
	 *
	 * @return void
	 */
	private function runBatch(BulkJob $job, $argument): void {
		$jobId     = $argument['job_id'];
		$batchSize = (int)($argument['batch_size'] ?? $this->bulkJobService->getBatchSize());

		try {
			$more = $this->bulkJobService->processBatch(job: $job, batchSize: $batchSize);
		} catch (\Throwable $exception) {
			$this->logger->error(
				message: '[BulkJobRunner] Batch failed',
				context: ['job_id' => $jobId, 'error' => $exception->getMessage()]
			);
			$this->markFailed(job: $job, exception: $exception);
			return;
		}

		if ($more === true) {
			$this->jobList->add(self::class, ['job_id' => $jobId, 'batch_size' => $batchSize]);
		}
	}//end runBatch()

	/**
	 * Fail a job whose batch threw, keeping its report and recording the fatal error.
	 *
	 * @param BulkJob    $job       The job.
	 * @param \Throwable $exception What the batch threw.
	 *
	 * @return void
	 */
	private function markFailed(BulkJob $job, \Throwable $exception): void {
		$job->setState(BulkJob::STATE_FAILED);
		$report          = ($job->getReport() ?? []);
		$report['fatal'] = $exception->getMessage();
		$job->setReport($report);
		$this->jobMapper->save($job);
	}//end markFailed()
}//end class
