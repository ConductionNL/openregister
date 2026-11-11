<?php

/**
 * JobRunRecorder: the wrapper that writes a run row around job execution.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Operations
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use DateTime;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes one run row per job execution: start, end, duration, outcome, failure.
 *
 * D-1: the console reads one table, written here, rather than asking each job
 * to report itself. Two properties are load-bearing and easy to lose:
 *
 * 1. **The row is written even when the work throws.** The outcome and the
 *    message are the whole point of the log, and a recorder that only writes
 *    on success produces a log in which nothing ever fails.
 * 2. **The throwable is re-thrown.** Nextcloud's `Job::start()` catches and
 *    logs it; swallowing it here would change what the cron worker sees, and
 *    a recorder must observe an execution without altering it.
 *
 * Re-entrancy: run-now wraps the execution from the outside so it can record
 * the cause and the actor, and a job that records itself would then produce
 * two rows for one run. The nesting guard makes the inner call a pass-through,
 * so the outer row, which is the one carrying the actor, is the row that
 * survives.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
class JobRunRecorder implements JobRunRecorderInterface {

	/**
	 * How much of a failure message the row keeps.
	 *
	 * A stack-heavy message from a third-party library can run to kilobytes,
	 * and a run log is read as a list, not as a log file.
	 *
	 * @var integer
	 */
	public const MAX_MESSAGE = 2000;

	/**
	 * How deep the recorder currently is, per job class.
	 *
	 * @var array<string, int>
	 */
	private array $depth = [];

	/**
	 * Constructor.
	 *
	 * @param JobRunMapperInterface     $runs   The run log.
	 * @param LoggerInterface  $logger Where a failure to record is reported.
	 * @param JobAlertService  $alerts Raises the threshold alert on a failure.
	 */
	public function __construct(
		private readonly JobRunMapperInterface $runs,
		private readonly LoggerInterface $logger,
		private readonly JobAlertService $alerts,
	) {
	}//end __construct()

	/**
	 * Run the work, recording it.
	 *
	 * @param JobRunStart $start The job class the run belongs to, with its cause, actor, argument and details.
	 * @param callable    $work  The execution to observe.
	 *
	 * @return mixed Whatever the work returned.
	 *
	 * @throws Throwable Whatever the work threw, after the failure is recorded.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function around(JobRunStart $start, callable $work): mixed {
		if (($this->depth[$start->jobClass] ?? 0) > 0) {
			// An outer recorder already holds this run and already carries the
			// cause and the actor. One execution is one row.
			return $work();
		}

		return $this->observe(run: $this->start(jobClass: $start->jobClass, context: $start->context), work: $work);

	}//end around()

	/**
	 * Record an act that has already happened, as one completed run.
	 *
	 * Entering maintenance mode and applying a repair are instants, not
	 * durations, and they belong on the same log as the runs because the
	 * question the log answers is "what has been done to this instance".
	 *
	 * @param OperatorAct $act The act (as a class-shaped name), the uid that performed it, what it concerned and a sentence about it.
	 *
	 * @return JobRun|null The row, or null when the log could not be written.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 */
	public function recordAct(OperatorAct $act): ?JobRun {
		$row = $this->actRow(jobClass: $act->jobClass, context: new JobRunContext(cause: JobRun::CAUSE_MANUAL, actor: $act->actor, details: $act->details));
		$row->setMessage($act->message);

		return $this->insertAct(row: $row);

	}//end recordAct()

	/**
	 * Mark the job class as recording and open its row.
	 *
	 * @param string        $jobClass The job class.
	 * @param JobRunContext $context  The cause, actor, argument and details.
	 *
	 * @return RecordingRun The run being recorded.
	 */
	private function start(string $jobClass, JobRunContext $context): RecordingRun {
		$this->depth[$jobClass] = (($this->depth[$jobClass] ?? 0) + 1);
		$row = $this->open(jobClass: $jobClass, context: $context);

		return new RecordingRun(jobClass: $jobClass, row: $row, startedAt: microtime(true));

	}//end start()

	/**
	 * Run the work and close the row with its outcome, re-throwing a failure.
	 *
	 * @param RecordingRun $run  The run being recorded.
	 * @param callable     $work The execution to observe.
	 *
	 * @return mixed Whatever the work returned.
	 *
	 * @throws Throwable Whatever the work threw, after the failure is recorded.
	 */
	private function observe(RecordingRun $run, callable $work): mixed {
		try {
			$result = $work();
		} catch (Throwable $failure) {
			$this->close(run: $run, failure: $this->describe(failure: $failure));
			$this->alerts->observeFailure(jobClass: $run->jobClass);
			unset($this->depth[$run->jobClass]);

			throw $failure;
		}

		$this->close(run: $run, failure: null);
		unset($this->depth[$run->jobClass]);

		return $result;

	}//end observe()

	/**
	 * The row of an act that has already happened: an instant, completed.
	 *
	 * @param string        $jobClass The act, as a class-shaped name.
	 * @param JobRunContext $context  The manual cause, the actor and the details.
	 *
	 * @return JobRun The unsaved row.
	 */
	private function actRow(string $jobClass, JobRunContext $context): JobRun {
		$now = new DateTime();
		$run = new JobRun();
		$run->setJobClass($jobClass);
		$run->setStarted($now);
		$run->setEnded($now);
		$run->setDurationMs(0);
		$run->setOutcome(JobRun::OUTCOME_COMPLETED);
		$run->setCause($context->cause);
		$run->setActor($context->actor);
		$run->setDetails($this->encodedDetails(details: ($context->details ?? [])));

		return $run;

	}//end actRow()

	/**
	 * Store the row of an act, or log that it could not be stored.
	 *
	 * @param JobRun $row The row to store.
	 *
	 * @return JobRun|null The row, or null when the log could not be written.
	 */
	private function insertAct(JobRun $row): ?JobRun {
		try {
			return $this->runs->insert($row);
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[JobRunRecorder] Could not record the act',
				context: ['job' => $row->getJobClass(), 'exception' => $exception]
			);

			return null;
		}

	}//end insertAct()

	/**
	 * Open the row, before the work runs.
	 *
	 * A row that is written only at the end cannot answer "what is running
	 * now", which is the read that refuses a double start (D-2).
	 *
	 * @param string        $jobClass The job class.
	 * @param JobRunContext $context  The cause, actor, argument and details.
	 *
	 * @return JobRun|null The open row, or null when the log is unwritable.
	 */
	private function open(string $jobClass, JobRunContext $context): ?JobRun {
		$run = $this->runningRow(jobClass: $jobClass, context: $context);

		try {
			return $this->runs->insert($run);
		} catch (Throwable $exception) {
			// The log is an observation. A job whose work is fine must not
			// fail because the observation could not be stored.
			$this->logger->warning(
				message: '[JobRunRecorder] Could not open a run row',
				context: ['job' => $jobClass, 'exception' => $exception]
			);

			return null;
		}

	}//end open()

	/**
	 * A row for a run that starts now, with its cause, actor, argument digest and details.
	 *
	 * @param string        $jobClass The job class.
	 * @param JobRunContext $context  The cause, actor, argument and details.
	 *
	 * @return JobRun The unsaved row.
	 */
	private function runningRow(string $jobClass, JobRunContext $context): JobRun {
		$run = new JobRun();
		$run->setJobClass($jobClass);
		$run->setStarted(new DateTime());
		$run->setOutcome(JobRun::OUTCOME_RUNNING);
		$run->setCause($context->cause);
		$run->setActor($context->actor);
		$run->setArgumentDigest($this->digest(argument: $context->argument));

		if ($context->details !== null) {
			$run->setDetails($this->encodedDetails(details: $context->details));
		}

		return $run;
	}//end runningRow()

	/**
	 * Close the row with its outcome and duration.
	 *
	 * @param RecordingRun $run     The run; its row is null when opening failed.
	 * @param string|null  $failure The failure message when it failed, null when it completed.
	 *
	 * @return void
	 */
	private function close(RecordingRun $run, ?string $failure): void {
		$row = $run->row;

		if ($row === null) {
			return;
		}

		$this->markEnded(run: $run, failure: $failure);

		try {
			$this->runs->update($row);
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[JobRunRecorder] Could not close a run row',
				context: ['job' => $row->getJobClass(), 'exception' => $exception]
			);
		}

	}//end close()

	/**
	 * Stamp the run's row with its end, duration, outcome and failure message.
	 *
	 * @param RecordingRun $run     The run, its row open.
	 * @param string|null  $failure The failure message when it failed, null when it completed.
	 *
	 * @return void
	 */
	private function markEnded(RecordingRun $run, ?string $failure): void {
		$row = $run->row;
		$row->setEnded(new DateTime());
		$row->setDurationMs((int)round(((microtime(true) - $run->startedAt) * 1000)));
		$row->setOutcome(JobRun::OUTCOME_FAILED);

		if ($failure === null) {
			$row->setOutcome(JobRun::OUTCOME_COMPLETED);
		}

		$row->setMessage($failure);
	}//end markEnded()

	/**
	 * The failure, as the row keeps it.
	 *
	 * @param Throwable $failure What the work threw.
	 *
	 * @return string The class and message, bounded.
	 */
	private function describe(Throwable $failure): string {
		return substr(($failure::class.': '.$failure->getMessage()), 0, self::MAX_MESSAGE);

	}//end describe()

	/**
	 * A short digest of the job argument.
	 *
	 * The argument itself is not stored: it can hold a payload, and a run log
	 * is not a place to accumulate one. The digest only has to distinguish two
	 * queued rows of the same class.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return string|null The digest, or null when there was no argument.
	 */
	private function digest(mixed $argument): ?string {
		if ($argument === null) {
			return null;
		}

		$encoded = json_encode($argument);

		if ($encoded === false) {
			return null;
		}

		// NOSEMGREP: php.lang.security.weak-crypto.weak-crypto -- The hash only fingerprints content to detect a change, not a security value.
		return substr(sha1($encoded), 0, 16);

	}//end digest()

	/**
	 * The details column's value, or null when they do not encode.
	 *
	 * A run row that cannot be written is worse than one written without its
	 * details, so an unencodable payload becomes null rather than an exception
	 * on a path whose whole job is to record what already happened.
	 *
	 * @param array<string, mixed> $details The details to store.
	 *
	 * @return string|null The encoded details, or null.
	 */
	private function encodedDetails(array $details): ?string {
		$encoded = json_encode($details);

		if ($encoded === false) {
			return null;
		}

		return $encoded;

	}//end encodedDetails()
}//end class
