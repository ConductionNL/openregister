<?php

/**
 * IdentifierSchemeMigrationJob — advances one identifier scheme migration a batch at a time.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\IdentifierSchemeRun;
use OCA\OpenRegister\Service\IdentifierSchemeMigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites one batch, then queues itself again until the run stops.
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
 */
class IdentifierSchemeMigrationJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                     $time       The clock.
	 * @param IdentifierSchemeMigrationService $migrations The migrations.
	 * @param IJobList                         $jobList    Queues the next batch.
	 * @param LoggerInterface                  $logger     Where a stopped run is reported.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly IdentifierSchemeMigrationService $migrations,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Rewrite the run's next batch.
	 *
	 * @param mixed $argument `['run_id' => int, 'batch_size' => int]`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
	 */
	protected function run($argument): void {
		$runId = (int)($argument['run_id'] ?? 0);
		$run = null;
		if ($runId > 0) {
			$run = $this->migrations->find(id: $runId);
		}

		if ($run === null || $run->getState() !== IdentifierSchemeRun::STATE_RUNNING) {
			return;
		}

		$batchSize = (int)($argument['batch_size'] ?? IdentifierSchemeMigrationService::DEFAULT_BATCH);
		try {
			$more = $this->migrations->processBatch(run: $run, batchSize: $batchSize);
		} catch (Throwable $e) {
			$this->logger->error('[IdentifierSchemeMigrationJob] run '.$runId.' stopped: '.$e->getMessage(), ['exception' => $e]);
			$this->migrations->fail(run: $run, error: $e->getMessage());
			return;
		}

		if ($more === true) {
			$this->jobList->add(self::class, ['run_id' => $runId, 'batch_size' => $batchSize]);
		}
	}//end run()
}//end class
