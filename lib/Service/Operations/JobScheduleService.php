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
	 * The schedule of a job nobody administered.
	 *
	 * @var array{enabled: bool, intervalSeconds: int, windowStartHour: int|null, windowEndHour: int|null}
	 */
	private const DEFAULT_SCHEDULE = [
		'enabled' => true,
		'intervalSeconds' => self::DEFAULT_INTERVAL_SECONDS,
		'windowStartHour' => null,
		'windowEndHour' => null,
	];

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

		return [
			'job' => $jobClass,
			'enabled' => $schedule['enabled'],
			'intervalSeconds' => $schedule['intervalSeconds'],
			'windowStartHour' => $schedule['windowStartHour'],
			'windowEndHour' => $schedule['windowEndHour'],
			'lastRun' => $this->lastRunAt(lastRun: $lastRun),
			'nextDue' => $nextDue?->format(DateTime::ATOM),
		];

	}//end describe()

	/**
	 * The last run as an ATOM timestamp, or null when the job never ran.
	 *
	 * @param int $lastRun The unix time of its last run, 0 when it never ran.
	 *
	 * @return string|null The timestamp.
	 */
	private function lastRunAt(int $lastRun): ?string {
		if ($lastRun > 0) {
			return (new DateTime())->setTimestamp($lastRun)->format(DateTime::ATOM);
		}

		return null;
	}//end lastRunAt()

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
		$schedule = $this->changed(schedule: $this->read(jobClass: $jobClass), change: $change);
		$this->store(jobClass: $jobClass, schedule: $schedule);

		return $this->describe(jobClass: $jobClass);

	}//end administer()

	/**
	 * The schedule with the change applied, the interval and window hours clamped.
	 *
	 * @param array             $schedule The schedule in force.
	 * @param JobScheduleChange $change   What to change; a null part is left as it is.
	 *
	 * @return array The changed schedule.
	 */
	private function changed(array $schedule, JobScheduleChange $change): array {
		if ($change->enabled !== null) {
			$schedule['enabled'] = $change->enabled;
		}

		if ($change->intervalSeconds !== null) {
			$schedule['intervalSeconds'] = max(60, $change->intervalSeconds);
		}

		if ($change->windowStartHour !== null) {
			$schedule['windowStartHour'] = max(0, min(23, $change->windowStartHour));
		}

		if ($change->windowEndHour !== null) {
			$schedule['windowEndHour'] = max(0, min(23, $change->windowEndHour));
		}

		return $schedule;
	}//end changed()

	/**
	 * Store one job's schedule as JSON.
	 *
	 * @param string $jobClass The job class.
	 * @param array  $schedule The schedule to store.
	 *
	 * @return void
	 */
	private function store(string $jobClass, array $schedule): void {
		$encoded = json_encode($schedule);
		if ($encoded === false) {
			$encoded = '{}';
		}

		$this->config->setAppValue(
			self::APP_ID,
			$this->key(jobClass: $jobClass),
			$encoded
		);
	}//end store()

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
		$stored = $this->config->getAppValue(self::APP_ID, $this->key(jobClass: $jobClass), '');

		if ($stored === '') {
			return self::DEFAULT_SCHEDULE;
		}

		$decoded = json_decode($stored, true);

		if (is_array($decoded) === false) {
			return self::DEFAULT_SCHEDULE;
		}

		return $this->fromStored(decoded: $decoded);

	}//end read()

	/**
	 * A decoded stored schedule, cast, its interval at least a minute, missing parts defaulted.
	 *
	 * @param array $decoded The decoded stored schedule.
	 *
	 * @return array{enabled: bool, intervalSeconds: int, windowStartHour: int|null, windowEndHour: int|null} The schedule.
	 */
	private function fromStored(array $decoded): array {
		return [
			'enabled' => (bool)($decoded['enabled'] ?? true),
			'intervalSeconds' => max(60, (int)($decoded['intervalSeconds'] ?? self::DEFAULT_INTERVAL_SECONDS)),
			'windowStartHour' => $this->storedHour(decoded: $decoded, key: 'windowStartHour'),
			'windowEndHour' => $this->storedHour(decoded: $decoded, key: 'windowEndHour'),
		];
	}//end fromStored()

	/**
	 * One stored window hour as an integer, or null when it is not set.
	 *
	 * @param array  $decoded The decoded stored schedule.
	 * @param string $key     The hour's key.
	 *
	 * @return integer|null The hour.
	 */
	private function storedHour(array $decoded, string $key): ?int {
		if (isset($decoded[$key]) === true) {
			return (int)$decoded[$key];
		}

		return null;
	}//end storedHour()

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

		$earliest = ($lastRun + (int)$schedule['intervalSeconds']);
		$due = (new DateTime())->setTimestamp($earliest);

		if ($this->insideWindow(schedule: $schedule, hour: (int)$due->format('G')) === true) {
			return $due;
		}

		// Outside the window: the next moment the window opens.
		return $this->windowOpeningAfter(schedule: $schedule, earliest: $earliest);

	}//end nextDue()

	/**
	 * The first opening of the schedule's window at or after a moment.
	 *
	 * @param array $schedule The schedule in force.
	 * @param int   $earliest The unix time the job may run at the earliest.
	 *
	 * @return DateTime The window's opening that day, or the next day when that has already passed.
	 */
	private function windowOpeningAfter(array $schedule, int $earliest): DateTime {
		$due = (new DateTime())->setTimestamp($earliest);
		$due->setTime((int)$schedule['windowStartHour'], 0);

		if ($due->getTimestamp() < $earliest) {
			$due->modify('+1 day');
		}

		return $due;
	}//end windowOpeningAfter()

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
