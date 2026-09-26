<?php

/**
 * RecordedTimedJob: a recurring job whose every run is a row.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCA\OpenRegister\Service\Operations\JobScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Base class for recurring jobs that report every run.
 *
 * `start()` is final on `TimedJob`, so the wrapper cannot sit outside the job:
 * it sits at the top of `run()`, which is the one place every execution of
 * this job passes through. Subclasses implement `runRecorded()` and never
 * touch `run()`; that is what makes forgetting to report impossible rather
 * than merely discouraged (D-1).
 *
 * The administered schedule is honoured here too, for the same reason: a job
 * that an administrator disabled must not run, and putting that check in each
 * subclass is putting it in the place it can be left out.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
abstract class RecordedTimedJob extends TimedJob implements RecordsItsRuns {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory       $time     Time factory for the parent job class.
	 * @param JobRunRecorder     $recorder The wrapper that writes the run row.
	 * @param JobScheduleService $schedule The administered schedule.
	 */
	public function __construct(
		ITimeFactory $time,
		protected readonly JobRunRecorder $recorder,
		protected readonly JobScheduleService $schedule,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Record the run, honour the schedule, and do the work.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
	 */
	final protected function run($argument): void {
		if ($this->schedule->mayRun(jobClass: static::class, moment: new DateTime()) === false) {
			// Disabled, or outside its window. Not a run, so not a row: a
			// skipped tick recorded as a run would report a duration of
			// nothing and an outcome of completed, which reads as "it ran".
			return;
		}

		$this->recorder->around(
			jobClass: static::class,
			work: function () use ($argument): void {
				$this->runRecorded(argument: $argument);
			},
			argument: $argument
		);

	}//end run()

	/**
	 * The work itself.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
	 */
	abstract protected function runRecorded(mixed $argument): void;
}//end class
