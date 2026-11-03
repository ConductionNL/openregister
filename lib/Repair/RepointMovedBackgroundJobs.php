<?php

/**
 * Repair step: point job-list rows of moved job classes at their new names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\AppInfo\ClassAliasMap;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites `oc_jobs` rows that still name the old class of a moved job.
 *
 * Nextcloud keys a job-list row by class name. A job whose class moved keeps
 * its old row, and ReconcileDeclaredBackgroundJobs adds a row under the new
 * name, so the job would run twice. Every queued row is re-added under the new
 * name with its own argument (a no-op when that row exists) and the old row is
 * removed, so no queued work is lost.
 */
class RepointMovedBackgroundJobs implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param IJobList        $jobList The Nextcloud job list.
	 * @param ClassAliasMap   $map     The old => new class map.
	 * @param LoggerInterface $logger  Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IJobList $jobList,
		private readonly ClassAliasMap $map,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Step name shown by occ and the admin UI.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Point OpenRegister background jobs whose class moved at the new class name';
	}//end getName()

	/**
	 * Repoint the rows of every moved job class.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude Upgrade plumbing for moved job classes; no capability spec covers the job list.
	 */
	public function run(IOutput $output): void {
		foreach ($this->map->entries() as $old => $entry) {
			if (is_subclass_of($entry['class'], IJob::class) === false) {
				continue;
			}

			try {
				$this->repoint(old: $old, new: $entry['class']);
				$output->info('Repointed background job '.$old.' to '.$entry['class']);
			} catch (Throwable $e) {
				$this->logger->warning(
					'[RepointMovedBackgroundJobs] Could not repoint '.$old.': '.$e->getMessage(),
					['app' => 'openregister', 'exception' => $e]
				);
				$output->warning('Could not repoint '.$old.': '.$e->getMessage());
			}
		}
	}//end run()

	/**
	 * Re-add every row of one old class under the new class, then drop the old rows.
	 *
	 * @param string $old The old class name, as stored in the job list.
	 * @param string $new The class the job now lives under.
	 *
	 * @return void
	 */
	private function repoint(string $old, string $new): void {
		// The old name is the stored column value; no class carries it any more.
		/* @phpstan-ignore argument.type */
		foreach ($this->jobList->getJobsIterator($old, null, 0) as $job) {
			$this->jobList->add($new, $job->getArgument());
		}

		/* @phpstan-ignore argument.type */
		$this->jobList->remove($old);
	}//end repoint()
}//end class
