<?php

/**
 * JobAlertService: a failure threshold over a period, and one alert per breach.
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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Raises one alert when a job fails more than the administered number of times
 * inside the administered period.
 *
 * D-3: a notification per failed run trains people to ignore notifications.
 * The threshold is a count over a period, both administered, and the alert
 * names the job and its FIRST failure in the period, because that is where the
 * reader starts looking.
 *
 * "One alert per breach" is the hard part, and it is held by a marker rather
 * than by counting: once an alert has been raised for a job, no second alert
 * follows until the job has had a period with no failures in it. Without that,
 * the fourth, fifth and sixth failure each re-cross the threshold and each
 * raise an alert, which is the noise the threshold existed to prevent.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
 */
class JobAlertService {

	/**
	 * The app the administered settings belong to.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * The setting holding how many failures breach the threshold.
	 *
	 * @var string
	 */
	public const SETTING_THRESHOLD = 'operations_alert_threshold';

	/**
	 * The setting holding the period, in minutes.
	 *
	 * @var string
	 */
	public const SETTING_PERIOD_MINUTES = 'operations_alert_period_minutes';

	/**
	 * Three failures in an hour, which is the example the spec names.
	 *
	 * @var integer
	 */
	public const DEFAULT_THRESHOLD = 3;

	/**
	 * One hour.
	 *
	 * @var integer
	 */
	public const DEFAULT_PERIOD_MINUTES = 60;

	/**
	 * The notification object type the alert is delivered as.
	 *
	 * @var string
	 */
	public const NOTIFICATION_OBJECT = 'operations_job_failure';

	/**
	 * Prefix of the per-job marker that makes the alert fire once per breach.
	 *
	 * @var string
	 */
	private const MARKER_PREFIX = 'operations_alert_raised_';

	/**
	 * Constructor.
	 *
	 * @param JobRunMapper          $runs          The run log the count comes from.
	 * @param IConfig               $config        Where the threshold and the marker live.
	 * @param INotificationManager  $notifications Where the alert is delivered.
	 * @param IGroupManager         $groups        Resolves who the administrators are.
	 * @param ITimeFactory          $time          The clock, so a test can hold it still.
	 * @param LoggerInterface       $logger        Where a failed delivery is reported.
	 */
	public function __construct(
		private readonly JobRunMapper $runs,
		private readonly IConfig $config,
		private readonly INotificationManager $notifications,
		private readonly IGroupManager $groups,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The administered threshold and period.
	 *
	 * @return array{threshold: int, periodMinutes: int} The settings in force.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function settings(): array {
		$threshold = (int)$this->config->getAppValue(
			self::APP_ID,
			self::SETTING_THRESHOLD,
			(string)self::DEFAULT_THRESHOLD
		);
		$period = (int)$this->config->getAppValue(
			self::APP_ID,
			self::SETTING_PERIOD_MINUTES,
			(string)self::DEFAULT_PERIOD_MINUTES
		);

		return [
			'threshold' => max(1, $threshold),
			'periodMinutes' => max(1, $period),
		];

	}//end settings()

	/**
	 * Administer the threshold and the period.
	 *
	 * @param int|null $threshold     How many failures breach it.
	 * @param int|null $periodMinutes Over how many minutes.
	 *
	 * @return array{threshold: int, periodMinutes: int} The settings now in force.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function administer(?int $threshold, ?int $periodMinutes): array {
		if ($threshold !== null) {
			$this->config->setAppValue(self::APP_ID, self::SETTING_THRESHOLD, (string)max(1, $threshold));
		}

		if ($periodMinutes !== null) {
			$this->config->setAppValue(
				self::APP_ID,
				self::SETTING_PERIOD_MINUTES,
				(string)max(1, $periodMinutes)
			);
		}

		return $this->settings();

	}//end administer()

	/**
	 * A job has just failed: raise the alert when this failure breaches.
	 *
	 * @param string $jobClass The job that failed.
	 *
	 * @return array<string, mixed>|null The alert raised, or null when nothing breached.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function observeFailure(string $jobClass): ?array {
		$breach = $this->breach(jobClass: $jobClass);

		if ($breach === null) {
			return null;
		}

		if ($this->alreadyRaised(jobClass: $jobClass, firstFailure: $breach['firstFailure']) === true) {
			return null;
		}

		$this->config->setAppValue(
			self::APP_ID,
			(self::MARKER_PREFIX.md5($jobClass)),
			(string)$breach['firstFailure']
		);

		$this->deliver(breach: $breach);

		return $breach;

	}//end observeFailure()

	/**
	 * Every job currently over the threshold, for the console to render.
	 *
	 * @param array<int, string> $jobClasses The jobs to look at.
	 *
	 * @return array<int, array<string, mixed>> The breaches, one per job.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-a-recurring-jobs-schedule-is-administered-and-failures-raise-an-alert-req-aoc-003
	 */
	public function alerts(array $jobClasses): array {
		$alerts = [];

		foreach ($jobClasses as $jobClass) {
			$breach = $this->breach(jobClass: $jobClass);

			if ($breach === null) {
				continue;
			}

			$alerts[] = $breach;
		}

		return $alerts;

	}//end alerts()

	/**
	 * The breach for one job, when there is one.
	 *
	 * @param string $jobClass The job.
	 *
	 * @return array<string, mixed>|null The breach, naming the first failure.
	 */
	private function breach(string $jobClass): ?array
	{
		$settings = $this->settings();
		$since = (new DateTime())->setTimestamp(
			($this->time->getTime() - ($settings['periodMinutes'] * 60))
		);

		$failures = $this->runs->failuresSince(jobClass: $jobClass, since: $since);

		if (count($failures) <= $settings['threshold']) {
			// "More than" the threshold, not "at least": three failures do not
			// breach a threshold of three.
			return null;
		}

		$first = $failures[0];

		return [
			'job' => $jobClass,
			'name' => $first->shortName(),
			'failures' => count($failures),
			'threshold' => $settings['threshold'],
			'periodMinutes' => $settings['periodMinutes'],
			'since' => $since->format(DateTime::ATOM),
			'firstFailure' => (int)($first->getStarted()?->getTimestamp() ?? 0),
			'firstFailureAt' => $first->getStarted()?->format(DateTime::ATOM),
			'firstFailureMessage' => $first->getMessage(),
		];

	}//end breach()

	/**
	 * Has an alert already gone out for this breach.
	 *
	 * The marker holds the first failure of the breach that raised it. A later
	 * failure inside the same run of bad luck reports the same first failure,
	 * so it is the same breach and stays quiet. Once the period rolls past
	 * that first failure, a new breach has a new first failure and alerts.
	 *
	 * @param string $jobClass     The job.
	 * @param int    $firstFailure The timestamp of the first failure in the period.
	 *
	 * @return bool True when this breach has already been announced.
	 */
	private function alreadyRaised(string $jobClass, int $firstFailure): bool {
		$raised = $this->config->getAppValue(self::APP_ID, (self::MARKER_PREFIX.md5($jobClass)), '');

		if ($raised === '') {
			return false;
		}

		return (int)$raised === $firstFailure;

	}//end alreadyRaised()

	/**
	 * Deliver the alert to every administrator.
	 *
	 * @param array<string, mixed> $breach The breach.
	 *
	 * @return void
	 */
	private function deliver(array $breach): void {
		try {
			foreach ($this->administrators() as $uid) {
				$notification = $this->notifications->createNotification();
				$notification->setApp(self::APP_ID)
					->setUser($uid)
					->setDateTime(new DateTime())
					->setObject(self::NOTIFICATION_OBJECT, (string)$breach['job'])
					->setSubject(
						'operations_job_failure',
						[
							'job' => $breach['name'],
							'failures' => $breach['failures'],
							'periodMinutes' => $breach['periodMinutes'],
						]
					)
					->setMessage(
						'operations_job_failure',
						[
							'firstFailureAt' => $breach['firstFailureAt'],
							'firstFailureMessage' => $breach['firstFailureMessage'],
						]
					);

				$this->notifications->notify($notification);
			}
		} catch (Throwable $exception) {
			// An alert that cannot be delivered must not take the failing job
			// down with it; the breach is still readable on the console.
			$this->logger->warning(
				message: '[JobAlertService] Could not deliver the alert',
				context: ['job' => $breach['job'], 'exception' => $exception]
			);
		}

	}//end deliver()

	/**
	 * The uids the alert goes to.
	 *
	 * @return array<int, string> The administrators.
	 */
	private function administrators(): array {
		$group = $this->groups->get('admin');

		if ($group === null) {
			return [];
		}

		$uids = [];

		foreach ($group->getUsers() as $user) {
			$uids[] = $user->getUID();
		}

		return $uids;

	}//end administrators()
}//end class
