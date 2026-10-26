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
 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefresh;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refreshes the marked example sets once a day.
 *
 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
 */
class ExampleSetDailyRefreshJob extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory           $time   The clock.
	 * @param ExampleSetDailyRefresh $daily  The marked sets and their refresh.
	 * @param LoggerInterface        $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ExampleSetDailyRefresh $daily,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
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
	 *
	 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	protected function run(mixed $argument): void {
		try {
			$report = $this->daily->run();
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
