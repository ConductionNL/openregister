<?php

/**
 * SchemaRunJob — batched executor for schema revalidation and migration runs.
 *
 * A queued job that advances one {@see \OCA\OpenRegister\Db\SchemaRun} by a
 * single batch and re-enqueues itself while work remains, so very large
 * populations are processed incrementally with a resumable cursor that
 * survives a worker restart. Revalidation runs delegate to
 * {@see \OCA\OpenRegister\Service\Schema\SchemaRevalidationService};
 * migration runs to {@see \OCA\OpenRegister\Service\Schema\SchemaMigrationService}.
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

use OCA\OpenRegister\Db\SchemaRun;
use OCA\OpenRegister\Db\SchemaRunStoreInterface;
use OCA\OpenRegister\Service\Schema\SchemaBatchMigratorInterface;
use OCA\OpenRegister\Service\Schema\SchemaRevalidationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\BackgroundJob\Support\SchemaRunServices;

/**
 * Background executor for schema runs.
 */
class SchemaRunJob extends QueuedJob {
	/**
	 * Run persistence.
	 *
	 * @var SchemaRunStoreInterface
	 */
	private readonly SchemaRunStoreInterface $runMapper;

	/**
	 * Revalidation engine.
	 *
	 * @var SchemaRevalidationService
	 */
	private readonly SchemaRevalidationService $revalidationService;

	/**
	 * Migration engine.
	 *
	 * @var SchemaBatchMigratorInterface
	 */
	private readonly SchemaBatchMigratorInterface $migrationService;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param SchemaRunServices $services Run store, revalidation and migration passes.
	 * @param IJobList $jobList Job list for re-enqueue.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		SchemaRunServices $services,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
		$this->runMapper = $services->runMapper;
		$this->revalidationService = $services->revalidationService;
		$this->migrationService = $services->migrationService;
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Run one batch of a schema run.
	 *
	 * @param array<string, mixed> $argument Job arguments: run_id (required),
	 *                                       batch_size (optional).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	protected function run($argument): void {
		$run = $this->runningRun(argument: $argument);
		if ($run === null || $this->advanceOrFail(run: $run, argument: $argument) === false) {
			return;
		}

		$this->jobList->add(self::class, ['run_id' => $argument['run_id'], 'batch_size' => $this->batchSize(argument: $argument)]);

	}//end run()

	/**
	 * The run named by the job argument, or null when there is nothing to do.
	 *
	 * A terminal or otherwise non-running run is left alone.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return SchemaRun|null The run when it exists and is running.
	 */
	private function runningRun(mixed $argument): ?SchemaRun {
		$runId = ($argument['run_id'] ?? null);
		if ($runId === null) {
			$this->logger->error('[SchemaRunJob] Missing run_id argument', ['argument' => $argument]);
			return null;
		}

		$run = $this->findRun(runId: $runId);
		if ($run === null || $run->getState() !== SchemaRun::STATE_RUNNING) {
			return null;
		}

		return $run;
	}//end runningRun()

	/**
	 * Load a run, logging and returning null when it cannot be found.
	 *
	 * @param mixed $runId The run id from the job argument.
	 *
	 * @return SchemaRun|null
	 */
	private function findRun(mixed $runId): ?SchemaRun {
		try {
			return $this->runMapper->find((int)$runId);
		} catch (\Throwable $e) {
			$this->logger->error('[SchemaRunJob] Run not found', ['run_id' => $runId, 'error' => $e->getMessage()]);
			return null;
		}
	}//end findRun()

	/**
	 * The batch size from the job argument; 100 by default.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return int
	 */
	private function batchSize(mixed $argument): int {
		return (int)($argument['batch_size'] ?? 100);
	}//end batchSize()

	/**
	 * Advance the run by one batch; on failure mark it failed.
	 *
	 * @param SchemaRun $run      The run.
	 * @param mixed     $argument The job argument.
	 *
	 * @return bool True when more work remains; false when done or failed.
	 */
	private function advanceOrFail(SchemaRun $run, mixed $argument): bool {
		try {
			return $this->advance(run: $run, batchSize: $this->batchSize(argument: $argument));
		} catch (\Throwable $e) {
			$this->markFailed(run: $run, context: ['run_id' => $argument['run_id'], 'error' => $e->getMessage()]);
			return false;
		}
	}//end advanceOrFail()

	/**
	 * Log a failed batch and persist the run as failed, keeping its report.
	 *
	 * @param SchemaRun                              $run     The run.
	 * @param array{run_id: mixed, error: string}    $context The run id and the error message.
	 *
	 * @return void
	 */
	private function markFailed(SchemaRun $run, array $context): void {
		$this->logger->error('[SchemaRunJob] Batch processing failed', $context);
		$run->setState(SchemaRun::STATE_FAILED);
		$report          = ($run->getReport() ?? []);
		$report['fatal'] = $context['error'];
		$run->setReport($report);
		$this->runMapper->save($run);
	}//end markFailed()

	/**
	 * Advance the run by one batch, dispatching by run type.
	 *
	 * @param SchemaRun $run The run.
	 * @param int $batchSize The batch size.
	 *
	 * @return bool True when more work remains.
	 */
	private function advance(SchemaRun $run, int $batchSize): bool {
		if ($run->getType() === SchemaRun::TYPE_MIGRATION) {
			return $this->migrationService->processBatch($run, $batchSize);
		}

		return $this->revalidationService->processBatch($run, $batchSize);
	}//end advance()
}//end class
