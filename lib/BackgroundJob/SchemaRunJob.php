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
use OCA\OpenRegister\Db\SchemaRunMapper;
use OCA\OpenRegister\Service\Schema\SchemaMigrationService;
use OCA\OpenRegister\Service\Schema\SchemaRevalidationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Background executor for schema runs.
 */
class SchemaRunJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param SchemaRunMapper $runMapper Run persistence.
	 * @param SchemaRevalidationService $revalidationService Revalidation engine.
	 * @param SchemaMigrationService $migrationService Migration engine.
	 * @param IJobList $jobList Job list for re-enqueue.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly SchemaRunMapper $runMapper,
		private readonly SchemaRevalidationService $revalidationService,
		private readonly SchemaMigrationService $migrationService,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
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
		$runId = ($argument['run_id'] ?? null);
		if ($runId === null) {
			$this->logger->error('[SchemaRunJob] Missing run_id argument', ['argument' => $argument]);
			return;
		}

		$batchSize = (int)($argument['batch_size'] ?? 100);

		try {
			$run = $this->runMapper->find((int)$runId);
		} catch (\Throwable $e) {
			$this->logger->error('[SchemaRunJob] Run not found', ['run_id' => $runId, 'error' => $e->getMessage()]);
			return;
		}

		if ($run->getState() !== SchemaRun::STATE_RUNNING) {
			// Nothing to do for a terminal/non-running run.
			return;
		}

		try {
			$more = $this->advance(run: $run, batchSize: $batchSize);
		} catch (\Throwable $e) {
			$this->logger->error(
				'[SchemaRunJob] Batch processing failed',
				['run_id' => $runId, 'error' => $e->getMessage()]
			);
			$run->setState(SchemaRun::STATE_FAILED);
			$report = ($run->getReport() ?? []);
			$report['fatal'] = $e->getMessage();
			$run->setReport($report);
			$this->runMapper->save($run);
			return;
		}

		if ($more === true) {
			$this->jobList->add(self::class, ['run_id' => $runId, 'batch_size' => $batchSize]);
		}

	}//end run()

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
