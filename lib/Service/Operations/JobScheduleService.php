<?php

/**
 * JobScheduleService: interval, window and enabled per recurring job.
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
use OCP\IConfig;

/**
 * The administered schedule of one recurring job.
 *
 * Nextcloud's own job list carries an interval baked into the job class and a
 * last run. What an administrator wants is to change the interval, to say the
 * job may only run at night, and to switch a job off without uninstalling the
 * app. That administration lives here, keyed by job class, and the row on the
 * console carries the last run and the next due time from it.
 *
 * A disabled job has no next due time. That is the whole of the disable: a job
 * that reported a next due time while disabled would be a console lying about
 * an instance it is the only window onto.
 *
 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
 */
class JobScheduleService implements JobScheduleServiceInterface {

	/**
	 * The app the administered schedules belong to.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * Prefix of the per-job setting.
	 *
	 * @var string
	 */
	public const SETTING_PREFIX = 'operations_schedule_';

	/**
	 * The interval a job falls back on when nothing is administered.
	 *
	 * @var integer
	 */
	public const DEFAULT_INTERVAL_SECONDS = 3600;

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Where the administered schedules live.
	 */
	public function __construct(private readonly IConfig $config) {
	}//end __construct()

	/**
	 * The schedule of one job, with its last run and its next due time.
	 *
	 * @param string $jobClass The job class.
	 * @param int    $lastRun  The unix time of its last run, 0 when it never ran.
	 *
	 * @return array<string, mixed> The schedule as the console row carries it.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function describe(string $jobClass, int $lastRun = 0): array {
		$schedule = $this->read(jobClass: $jobClass);
		$nextDue = $this->nextDue(schedule: $schedule, lastRun: $lastRun);

		$lastRunAt = null;
		if ($lastRun > 0) {
			$lastRunAt = (new DateTime())->setTimestamp($lastRun)->format(DateTime::ATOM);
		}

		return [
			'job' => $jobClass,
			'enabled' => $schedule['enabled'],
			'intervalSeconds' => $schedule['intervalSeconds'],
			'windowStartHour' => $schedule['windowStartHour'],
			'windowEndHour' => $schedule['windowEndHour'],
			'lastRun' => $lastRunAt,
			'nextDue' => $nextDue?->format(DateTime::ATOM),
		];

	}//end describe()

	/**
	 * Administer one job's schedule.
	 *
	 * @param string            $jobClass The job class.
	 * @param JobScheduleChange $change   What to change; a null part is left as it is.
	 *
	 * @return array<string, mixed> The schedule now in force.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function administer(string $jobClass, JobScheduleChange $change): array {
		$enabled         = $change->enabled;
		$intervalSeconds = $change->intervalSeconds;
		$windowStartHour = $change->windowStartHour;
		$windowEndHour   = $change->windowEndHour;

		$schedule = $this->read(jobClass: $jobClass);

		if ($enabled !== null) {
			$schedule['enabled'] = $enabled;
		}

		if ($intervalSeconds !== null) {
			$schedule['intervalSeconds'] = max(60, $intervalSeconds);
		}

		if ($windowStartHour !== null) {
			$schedule['windowStartHour'] = max(0, min(23, $windowStartHour));
		}

		if ($windowEndHour !== null) {
			$schedule['windowEndHour'] = max(0, min(23, $windowEndHour));
		}

		$encoded = json_encode($schedule);
		if ($encoded === false) {
			$encoded = '{}';
		}

		$this->config->setAppValue(
			self::APP_ID,
			$this->key(jobClass: $jobClass),
			$encoded
		);

		return $this->describe(jobClass: $jobClass);

	}//end administer()

	/**
	 * May this job run at this moment.
	 *
	 * @param string   $jobClass The job class.
	 * @param DateTime $moment   The moment being asked about.
	 *
	 * @return bool True when the schedule allows it.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function mayRun(string $jobClass, DateTime $moment): bool {
		$schedule = $this->read(jobClass: $jobClass);

		if ($schedule['enabled'] === false) {
			return false;
		}

		return $this->insideWindow(schedule: $schedule, hour: (int)$moment->format('G'));

	}//end mayRun()

	/**
	 * The stored schedule, or the default one.
	 *
	 * @param string $jobClass The job class.
	 *
	 * @return array{enabled: bool, intervalSeconds: int, windowStartHour: int|null, windowEndHour: int|null} The schedule.
	 */
	private function read(string $jobClass): array {
		$default = [
			'enabled' => true,
			'intervalSeconds' => self::DEFAULT_INTERVAL_SECONDS,
			'windowStartHour' => null,
			'windowEndHour' => null,
		];

		$stored = $this->config->getAppValue(self::APP_ID, $this->key(jobClass: $jobClass), '');

		if ($stored === '') {
			return $default;
		}

		$decoded = json_decode($stored, true);

		if (is_array($decoded) === false) {
			return $default;
		}

		$windowStartHour = null;
		if (isset($decoded['windowStartHour']) === true) {
			$windowStartHour = (int)$decoded['windowStartHour'];
		}

		$windowEndHour = null;
		if (isset($decoded['windowEndHour']) === true) {
			$windowEndHour = (int)$decoded['windowEndHour'];
		}

		return [
			'enabled' => (bool)($decoded['enabled'] ?? true),
			'intervalSeconds' => max(60, (int)($decoded['intervalSeconds'] ?? self::DEFAULT_INTERVAL_SECONDS)),
			'windowStartHour' => $windowStartHour,
			'windowEndHour' => $windowEndHour,
		];

	}//end read()

	/**
	 * When the job is next due, or null when it is disabled.
	 *
	 * @param array<string, mixed> $schedule The schedule in force.
	 * @param int                  $lastRun  The unix time of the last run.
	 *
	 * @return DateTime|null The next due moment.
	 */
	private function nextDue(array $schedule, int $lastRun): ?DateTime {
		if ($schedule['enabled'] === false) {
			return null;
		}

		if ($lastRun <= 0) {
			// Never run and enabled: due now, not at some computed future
			// moment a reader would have to wait out to learn it was wrong.
			return new DateTime();
		}

		$due = (new DateTime())->setTimestamp(($lastRun + (int)$schedule['intervalSeconds']));

		if ($this->insideWindow(schedule: $schedule, hour: (int)$due->format('G')) === true) {
			return $due;
		}

		// Outside the window: the next moment the window opens.
		$due->setTime((int)$schedule['windowStartHour'], 0);

		if ($due->getTimestamp() < ($lastRun + (int)$schedule['intervalSeconds'])) {
			$due->modify('+1 day');
		}

		return $due;

	}//end nextDue()

	/**
	 * Is an hour inside the administered window.
	 *
	 * A window that wraps midnight (22 to 6) is the common one for a nightly
	 * job, so the wrap is handled rather than treated as an empty window.
	 *
	 * @param array<string, mixed> $schedule The schedule in force.
	 * @param int                  $hour     The hour, 0-23.
	 *
	 * @return bool True when the hour is allowed.
	 */
	private function insideWindow(array $schedule, int $hour): bool {
		$start = $schedule['windowStartHour'];
		$end = $schedule['windowEndHour'];

		if ($start === null || $end === null) {
			return true;
		}

		if ($start <= $end) {
			return ($hour >= $start && $hour <= $end);
		}

		return ($hour >= $start || $hour <= $end);

	}//end insideWindow()

	/**
	 * Carry a job's administered schedule from its old class name to its new one.
	 *
	 * A job whose class moved would otherwise fall back to the default schedule,
	 * because the setting is keyed by class name. A schedule already stored under
	 * the new name wins; the old setting is removed either way.
	 *
	 * @param string $from The class name the schedule is stored under.
	 * @param string $to   The class name the job now has.
	 *
	 * @return void
	 *
	 * @spec exclude Upgrade plumbing for moved job classes; the schedule itself is unchanged.
	 */
	public function moveSchedule(string $from, string $to): void {
		$stored = $this->config->getAppValue(self::APP_ID, $this->key(jobClass: $from), '');
		if ($stored === '') {
			return;
		}

		if ($this->config->getAppValue(self::APP_ID, $this->key(jobClass: $to), '') === '') {
			$this->config->setAppValue(self::APP_ID, $this->key(jobClass: $to), $stored);
		}

		$this->config->deleteAppValue(self::APP_ID, $this->key(jobClass: $from));

	}//end moveSchedule()

	/**
	 * The setting key one job's schedule is stored under.
	 *
	 * @param string $jobClass The job class.
	 *
	 * @return string The key, inside Nextcloud's 64-character ceiling.
	 */
	private function key(string $jobClass): string {
		return (self::SETTING_PREFIX.md5($jobClass));

	}//end key()
}//end class
