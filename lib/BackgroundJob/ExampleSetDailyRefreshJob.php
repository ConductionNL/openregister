<?php

/**
 * OpenRegister Example Set Daily Refresh Job
 *
 * Refreshes, once a day, the example sets an administrator marked with
 * `occ openregister:example-set:install <set> --daily`, so their "today"
 * follows the day the instance is viewed. Nothing is marked by default.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetBackgroundRun;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefreshInterface;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refreshes the marked example sets once a day.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
 */
class ExampleSetDailyRefreshJob extends TimedJob {
	/**
	 * Logger, taken from the JobRuntime.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param JobRuntime $runtime The job runtime: scheduler clock and run log.
	 * @param ExampleSetDailyRefreshInterface $daily  The marked sets and their refresh.
	 *
	 * @return void
	 */
	public function __construct(
		JobRuntime $runtime,
		private readonly ExampleSetDailyRefreshInterface $daily,
	) {
		$this->logger = $runtime->logger;
		parent::__construct(time: $runtime->time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Refresh the marked sets and log each outcome.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's job contract.
	 * @SuppressWarnings(PHPMD.StaticAccess) ExampleSetBackgroundRun::inBackgroundJob() is the process-wide frame a cron run opens.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	protected function run(mixed $argument): void {
		try {
			// A refresh may act as a cast account (a set's grants), also when cron runs inside a web request.
			$report = ExampleSetBackgroundRun::inBackgroundJob(run: fn (): array => $this->daily->run());
		} catch (Throwable $e) {
			$this->logger->error('[ExampleSetDailyRefreshJob] The daily refresh failed', ['exception' => $e->getMessage()]);
			return;
		}

		foreach ($report as $set => $outcome) {
			$level = 'info';
			if ($outcome['ok'] !== true) {
				$level = 'warning';
			}

			$this->logger->$level('[ExampleSetDailyRefreshJob] Example set refreshed for today', ['set' => $set] + $outcome);
		}
	}//end run()
}//end class
