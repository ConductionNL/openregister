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
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Operations;

use DateTime;
use OCA\OpenRegister\Db\JobRun;
use OCA\OpenRegister\Db\JobRunMapper;
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
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
 */
class JobRunRecorder {

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
	 * @param JobRunMapper     $runs   The run log.
	 * @param LoggerInterface  $logger Where a failure to record is reported.
	 * @param JobAlertService  $alerts Raises the threshold alert on a failure.
	 */
	public function __construct(
		private readonly JobRunMapper $runs,
		private readonly LoggerInterface $logger,
		private readonly JobAlertService $alerts,
	) {
	}//end __construct()

	/**
	 * Run the work, recording it.
	 *
	 * @param string        $jobClass The job class the run belongs to.
	 * @param callable      $work     The execution to observe.
	 * @param string        $cause    One of the JobRun CAUSE_ constants.
	 * @param string|null   $actor    The uid that caused the run, when a person did.
	 * @param mixed         $argument The job argument, digested into the row.
	 * @param array<string, mixed>|null $details What the act concerned.
	 *
	 * @return mixed Whatever the work returned.
	 *
	 * @throws Throwable Whatever the work threw, after the failure is recorded.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-every-background-run-is-listed-with-its-outcome-req-aoc-001
	 */
	public function around(
		string $jobClass,
		callable $work,
		string $cause = JobRun::CAUSE_SCHEDULE,
		?string $actor = null,
		mixed $argument = null,
		?array $details = null,
	): mixed {
		if (($this->depth[$jobClass] ?? 0) > 0) {
			// An outer recorder already holds this run and already carries the
			// cause and the actor. One execution is one row.
			return $work();
		}

		$this->depth[$jobClass] = (($this->depth[$jobClass] ?? 0) + 1);
		$run = $this->open(jobClass: $jobClass, cause: $cause, actor: $actor, argument: $argument, details: $details);
		$startedAt = microtime(true);

		try {
			$result = $work();
		} catch (Throwable $failure) {
			$this->close(
				run: $run,
				startedAt: $startedAt,
				outcome: JobRun::OUTCOME_FAILED,
				message: $this->describe(failure: $failure)
			);
			$this->alerts->observeFailure(jobClass: $jobClass);
			unset($this->depth[$jobClass]);

			throw $failure;
		}

		$this->close(run: $run, startedAt: $startedAt, outcome: JobRun::OUTCOME_COMPLETED, message: null);
		unset($this->depth[$jobClass]);

		return $result;

	}//end around()

	/**
	 * Record an act that has already happened, as one completed run.
	 *
	 * Entering maintenance mode and applying a repair are instants, not
	 * durations, and they belong on the same log as the runs because the
	 * question the log answers is "what has been done to this instance".
	 *
	 * @param string               $jobClass The act, as a class-shaped name.
	 * @param string               $actor    The uid that performed it.
	 * @param array<string, mixed> $details  What the act concerned.
	 * @param string|null          $message  A sentence about the act.
	 *
	 * @return JobRun|null The row, or null when the log could not be written.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-the-instance-checks-its-own-data-and-repairs-it-as-a-separate-act-req-aoc-005
	 */
	public function recordAct(string $jobClass, string $actor, array $details, ?string $message = null): ?JobRun {
		$now = new DateTime();
		$run = new JobRun();
		$run->setJobClass($jobClass);
		$run->setStarted($now);
		$run->setEnded($now);
		$run->setDurationMs(0);
		$run->setOutcome(JobRun::OUTCOME_COMPLETED);
		$run->setCause(JobRun::CAUSE_MANUAL);
		$run->setActor($actor);
		$run->setMessage($message);
		$run->setDetails(json_encode($details) ?: null);

		try {
			return $this->runs->insert($run);
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[JobRunRecorder] Could not record the act',
				context: ['job' => $jobClass, 'exception' => $exception]
			);

			return null;
		}

	}//end recordAct()

	/**
	 * Open the row, before the work runs.
	 *
	 * A row that is written only at the end cannot answer "what is running
	 * now", which is the read that refuses a double start (D-2).
	 *
	 * @param string               $jobClass The job class.
	 * @param string               $cause    The cause constant.
	 * @param string|null          $actor    The uid, when a person caused it.
	 * @param mixed                $argument The job argument.
	 * @param array<string, mixed>|null $details What the act concerned.
	 *
	 * @return JobRun|null The open row, or null when the log is unwritable.
	 */
	private function open(
		string $jobClass,
		string $cause,
		?string $actor,
		mixed $argument,
		?array $details,
	): ?JobRun {
		$run = new JobRun();
		$run->setJobClass($jobClass);
		$run->setStarted(new DateTime());
		$run->setOutcome(JobRun::OUTCOME_RUNNING);
		$run->setCause($cause);
		$run->setActor($actor);
		$run->setArgumentDigest($this->digest(argument: $argument));

		if ($details !== null) {
			$run->setDetails(json_encode($details) ?: null);
		}

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
	 * Close the row with its outcome and duration.
	 *
	 * @param JobRun|null $run       The open row, or null when opening failed.
	 * @param float       $startedAt The microtime the work began.
	 * @param string      $outcome   The outcome constant.
	 * @param string|null $message   The failure message, when it failed.
	 *
	 * @return void
	 */
	private function close(?JobRun $run, float $startedAt, string $outcome, ?string $message): void {
		if ($run === null) {
			return;
		}

		$run->setEnded(new DateTime());
		$run->setDurationMs((int)round(((microtime(true) - $startedAt) * 1000)));
		$run->setOutcome($outcome);
		$run->setMessage($message);

		try {
			$this->runs->update($run);
		} catch (Throwable $exception) {
			$this->logger->warning(
				message: '[JobRunRecorder] Could not close a run row',
				context: ['job' => $run->getJobClass(), 'exception' => $exception]
			);
		}

	}//end close()

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

		return substr(sha1($encoded), 0, 16);

	}//end digest()
}//end class
