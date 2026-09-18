<?php

/**
 * RecordedQueuedJob: a one-off job whose every run is a row.
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

use OCA\OpenRegister\Service\Operations\JobRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Base class for queued jobs that report every run.
 *
 * The queued half of {@see RecordedTimedJob}. There is no schedule to honour:
 * a queued job was asked for once, by something that already decided it should
 * happen, so switching it off is the caller's decision, not the console's.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-actions-run-as-observable-jobs-req-aoc-004
 */
abstract class RecordedQueuedJob extends QueuedJob implements RecordsItsRuns {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory   $time     Time factory for the parent job class.
	 * @param JobRunRecorder $recorder The wrapper that writes the run row.
	 */
	public function __construct(
		ITimeFactory $time,
		protected readonly JobRunRecorder $recorder,
	) {
		parent::__construct(time: $time);

	}//end __construct()

	/**
	 * Record the run and do the work.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 */
	final protected function run($argument): void {
		$this->recorder->around(
			jobClass: static::class,
			work: function () use ($argument): void {
				$this->runRecorded($argument);
			},
			cause: $this->causeOf(argument: $argument),
			actor: $this->actorOf(argument: $argument),
			argument: $argument
		);

	}//end run()

	/**
	 * The cause the row carries.
	 *
	 * A maintenance job queued by an administrator from the console carries
	 * that administrator through its argument, so the run row can say who
	 * asked for it rather than reporting every maintenance act as the
	 * schedule's doing.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return string One of the JobRun CAUSE_ constants.
	 */
	private function causeOf(mixed $argument): string {
		if (is_array($argument) === true && ($argument['actor'] ?? null) !== null) {
			return \OCA\OpenRegister\Db\JobRun::CAUSE_MANUAL;
		}

		return \OCA\OpenRegister\Db\JobRun::CAUSE_SCHEDULE;

	}//end causeOf()

	/**
	 * The actor the row carries, when the argument names one.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return string|null The uid.
	 */
	private function actorOf(mixed $argument): ?string {
		if (is_array($argument) === true && is_string($argument['actor'] ?? null) === true) {
			return $argument['actor'];
		}

		return null;

	}//end actorOf()

	/**
	 * The work itself.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 */
	abstract protected function runRecorded(mixed $argument): void;
}//end class
