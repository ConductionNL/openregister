<?php

/**
 * OpenRegister AutomationRecordRetentionJob
 *
 * Prunes automation run records older than the retention period.
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
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\AutomationRecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily: delete records older than `automation_record_retention_days` (default 90) (D-5).
 *
 * The notification dedup log keeps its own, much shorter window.
 *
 * @spec openspec/changes/automation-run-records/design.md
 */
class AutomationRecordRetentionJob extends TimedJob {

	public const APP_ID = 'openregister';

	public const CONFIG_KEY = 'automation_record_retention_days';

	public const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory           $time    The clock.
	 * @param AutomationRecordMapper $records The record store.
	 * @param IAppConfig             $config  Holds the retention period.
	 * @param LoggerInterface        $logger  Logs the sweep.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly AutomationRecordMapper $records,
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * The configured retention period in days; the default when unset or not positive.
	 *
	 * @return int The days.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function retentionDays(): int {
		$days = (int)$this->config->getValueString(self::APP_ID, self::CONFIG_KEY, (string)self::DEFAULT_RETENTION_DAYS);
		if ($days <= 0) {
			return self::DEFAULT_RETENTION_DAYS;
		}

		return $days;
	}//end retentionDays()

	/**
	 * Delete the expired records.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	protected function run($argument): void {
		$days   = $this->retentionDays();
		$cutoff = new DateTime('@' . ($this->time->getTime() - ($days * 86400)));
		$cutoff->setTimezone(new DateTimeZone('UTC'));

		try {
			$deleted = $this->records->deleteOlderThan(cutoff: $cutoff);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[AutomationRecordRetention] Sweep failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		if ($deleted > 0) {
			$this->logger->info(
				message: '[AutomationRecordRetention] Deleted ' . $deleted . ' expired automation record(s).',
				context: ['retentionDays' => $days]
			);
		}
	}//end run()
}//end class
