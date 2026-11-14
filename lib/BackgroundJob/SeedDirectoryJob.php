<?php

/**
 * SeedDirectoryJob: re-import the seed directory when its content changed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Configuration\SeedDirectoryImporter;
use OCA\OpenRegister\Service\Operations\JobScheduleServiceInterface;

/**
 * Every five minutes, imports the seed directory when the SHA-256 over its
 * file names and contents differs from the last run's, so a file changed on
 * the mounted volume arrives without a restart and an unchanged one costs a
 * directory read.
 *
 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md
 */
class SeedDirectoryJob extends RecordedTimedJob {

	/**
	 * Five minutes, in seconds.
	 */
	public const INTERVAL_SECONDS = 300;

	/**
	 * Build the job.
	 *
	 * @param RecordedJobRuntime          $runtime  The clock and the run recorder.
	 * @param JobScheduleServiceInterface $schedule Whether a tick may run.
	 * @param SeedDirectoryImporter       $importer The seed import.
	 */
	public function __construct(
		RecordedJobRuntime $runtime,
		JobScheduleServiceInterface $schedule,
		private readonly SeedDirectoryImporter $importer,
	) {
		parent::__construct(runtime: $runtime, schedule: $schedule);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Import when the digest changed.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/seed-from-a-mounted-directory/specs/data-import-export/spec.md#requirement-an-installation-is-seeded-from-an-operators-mounted-directory-without-the-admin-req-smd-001
	 */
	protected function runRecorded(mixed $argument): void {
		$this->importer->importIfChanged();
	}//end runRecorded()
}//end class
