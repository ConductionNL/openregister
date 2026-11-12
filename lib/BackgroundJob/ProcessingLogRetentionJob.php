<?php

/**
 * Removes processing-log entries past their retention and records each purge.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/processing-activity-register/specs/avg-verwerkingsregister/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateInterval;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ProcessingLogMapperInterface;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use OCA\OpenRegister\BackgroundJob\Support\ProcessingLogRetentionStores;

/**
 * Daily prune of `openregister_processing_log` by the `created` index.
 *
 * @spec openspec/changes/processing-activity-register/specs/avg-verwerkingsregister/spec.md
 */
class ProcessingLogRetentionJob extends TimedJob {
	/**
	 * The app the setting lives under.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * App-config key holding the retention period, an ISO 8601 duration.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'processing_log_retention';

	/**
	 * Default retention period (VNG Logging Verwerkingen norm).
	 *
	 * @var string
	 */
	public const DEFAULT_RETENTION = 'P3Y';

	/**
	 * The audit action that records a purge.
	 *
	 * @var string
	 */
	public const ACTION_PRUNED = 'processing-log.pruned';

	/**
	 * Deletes expired entries.
	 *
	 * @var ProcessingLogMapperInterface
	 */
	private readonly ProcessingLogMapperInterface $entries;

	/**
	 * Holds the record of each purge.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrail;

	/**
	 * Config, taken from the ConfiguredJobRuntime.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $config;

	/**
	 * Logger, taken from the ConfiguredJobRuntime.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param ConfiguredJobRuntime $runtime The job runtime: scheduler clock, app settings and run log.
	 * @param ProcessingLogRetentionStores $stores Processing log and the audit trail of each purge.
	 */
	public function __construct(
		ConfiguredJobRuntime $runtime,
		ProcessingLogRetentionStores $stores,
	) {
		$this->logger = $runtime->logger;
		$this->config = $runtime->appConfig;
		$this->entries = $stores->entries;
		$this->auditTrail = $stores->auditTrail;
		parent::__construct(time: $runtime->time);

		$this->setInterval(seconds: 86400);

	}//end __construct()

	/**
	 * Prune the expired entries and record the purge.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/changes/processing-activity-register/specs/avg-verwerkingsregister/spec.md
	 */
	protected function run($argument): void {
		$retention = $this->retention();
		$cutoff = $this->cutoff(retention: $retention);

		$deleted = $this->prunedCount(cutoff: $cutoff);
		if ($deleted === null) {
			return;
		}

		$this->logger->info(
			message: '[ProcessingLogRetention] Pruned expired entries',
			context: ['retention' => $retention, 'cutoff' => $cutoff->format('c'), 'deleted' => $deleted]
		);

		if ($deleted > 0) {
			$this->recordPurge(prune: new ProcessingLogPrune(retention: $retention, cutoff: $cutoff, deleted: $deleted));
		}

	}//end run()

	/**
	 * Delete the entries created before the cutoff.
	 *
	 * @param DateTime $cutoff The retention cutoff.
	 *
	 * @return int|null How many were deleted; null, logged, when the prune failed.
	 */
	private function prunedCount(DateTime $cutoff): ?int {
		try {
			return $this->entries->deleteCreatedBefore(cutoff: $cutoff);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ProcessingLogRetention] Prune failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end prunedCount()

	/**
	 * The configured retention, or the default when it is not a positive duration.
	 *
	 * A value that cannot be read must not shorten retention, so it falls
	 * back rather than deleting by a mistyped setting.
	 *
	 * @return string The ISO 8601 duration.
	 */
	private function retention(): string {
		$configured = $this->config->getValueString(self::APP_ID, self::CONFIG_KEY, self::DEFAULT_RETENTION);

		try {
			$now = $this->time->getDateTime();
			if ((clone $now)->sub(new DateInterval($configured)) < $now) {
				return $configured;
			}
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[ProcessingLogRetention] Unreadable retention, using the default: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		return self::DEFAULT_RETENTION;
	}//end retention()

	/**
	 * The moment before which entries have expired.
	 *
	 * @param string $retention The ISO 8601 duration.
	 *
	 * @return DateTime The cutoff.
	 */
	private function cutoff(string $retention): DateTime {
		return $this->time->getDateTime()->sub(new DateInterval($retention));
	}//end cutoff()

	/**
	 * Put the purge (period and count) on the audit trail.
	 *
	 * @param ProcessingLogPrune $prune The retention and cutoff applied and how many entries went.
	 *
	 * @return void
	 */
	private function recordPurge(ProcessingLogPrune $prune): void {
		$row = $this->purgeRow(prune: $prune);

		try {
			$this->auditTrail->insertAuditTrails(entries: [$row]);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ProcessingLogRetention] Entries were pruned but the purge was not recorded: ' . $e->getMessage(),
				context: $prune->recorded()
			);
		}
	}//end recordPurge()

	/**
	 * The audit-trail row that records a prune.
	 *
	 * @param ProcessingLogPrune $prune The prune.
	 *
	 * @return AuditTrail
	 */
	private function purgeRow(ProcessingLogPrune $prune): AuditTrail {
		$row = new AuditTrail();
		$row->setUuid(Uuid::v4()->toRfc4122());
		$row->setAction(self::ACTION_PRUNED);
		$row->setUser('system');
		$row->setUserName('System');
		$row->setChanged($prune->recorded());
		$row->setCreated($this->time->getDateTime());
		return $row;
	}//end purgeRow()
}//end class
