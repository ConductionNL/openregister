<?php

/**
 * OpenRegister Log Cleanup Task
 *
 * This file contains the background job that enforces the configured retention
 * on the audit trail and the search trail in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Cron
 * @package   OCA\OpenRegister\BackgroundJob
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job that enforces log retention
 *
 * Runs hourly and performs two INDEPENDENT sweeps:
 *
 *   1. Audit trail: expired rows are tombstoned (payload destroyed, row and
 *      hash-chain links kept) through AuditTrailMapper::clearLogs().
 *   2. Search trail: rows past the configured `searchTrailRetention` are
 *      deleted. Search trail rows are inserted WITHOUT an expiry, so the job
 *      stamps `expires` first and sweeps second; before this job did that, the
 *      retention setting was echoed to the admin UI and enforced by nothing,
 *      the only caller of the sweep being a manual admin endpoint.
 *
 * A failure in one sweep is logged and never skips the other.
 *
 * @package OCA\OpenRegister\BackgroundJob
 *
 * @psalm-suppress UnusedClass
 */
class LogCleanUpTask extends TimedJob {

	/**
	 * Fallback search trail retention when the setting is absent: 30 days in milliseconds.
	 *
	 * Mirrors the default ObjectRetentionHandler::getRetentionSettingsOnly() serves.
	 *
	 * @var int
	 */
	private const DEFAULT_SEARCH_TRAIL_RETENTION_MS = 2592000000;

	/**
	 * The audit trail mapper for database operations
	 *
	 * @var AuditTrailMapper
	 */
	private readonly AuditTrailMapper $auditTrailMapper;

	/**
	 * The search trail mapper for database operations
	 *
	 * @var SearchTrailMapper
	 */
	private readonly SearchTrailMapper $searchTrailMapper;

	/**
	 * The retention settings handler that carries `searchTrailRetention`
	 *
	 * @var ObjectRetentionHandler
	 */
	private readonly ObjectRetentionHandler $retentionHandler;

	/**
	 * The logger for logging operations
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor for the LogCleanUpTask
	 *
	 * @param ITimeFactory           $time              The time factory for time operations
	 * @param AuditTrailMapper       $auditTrailMapper  The audit trail mapper for database operations
	 * @param SearchTrailMapper      $searchTrailMapper The search trail mapper for database operations
	 * @param ObjectRetentionHandler $retentionHandler  The retention settings handler
	 * @param LoggerInterface        $logger            The logger for logging operations
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-hourly-log-cleanup-job-must-enforce-the-configured-search-trail-retention
	 */
	public function __construct(
		ITimeFactory $time,
		AuditTrailMapper $auditTrailMapper,
		SearchTrailMapper $searchTrailMapper,
		ObjectRetentionHandler $retentionHandler,
		LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->auditTrailMapper = $auditTrailMapper;
		$this->searchTrailMapper = $searchTrailMapper;
		$this->retentionHandler = $retentionHandler;
		$this->logger = $logger;

		// Run every hour (3600 seconds).
		$this->setInterval(seconds: 3600);

		// Delay until low-load time.
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);

		// Only run one instance of this job at a time.
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Execute the log cleanup task
	 *
	 * Each sweep contains its own failures, so the order below is not a
	 * dependency: the search trail sweep runs whether or not the audit sweep
	 * succeeded, and vice versa.
	 *
	 * @param mixed $argument The job argument (not used in this implementation).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-hourly-log-cleanup-job-must-enforce-the-configured-search-trail-retention
	 */
	protected function run($argument): void {
		$this->clearAuditTrails();
		$this->clearSearchTrails();
	}//end run()

	/**
	 * Tombstone expired audit trail rows
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-hourly-log-cleanup-job-must-enforce-the-configured-search-trail-retention
	 */
	private function clearAuditTrails(): void {
		try {
			// Tombstone expired audit rows. Since or#2265 this destroys the
			// payload and stamps `purged_at` rather than deleting the row, so
			// the hash chain stays verifiable across a lawful purge, and
			// `expires` now follows the RETENTION OF THE OBJECT the row
			// describes rather than a flat 30 days.
			$logsCleared = $this->auditTrailMapper->clearLogs();

			// Log the result for monitoring purposes.
			if ($logsCleared === true) {
				$this->logger->info(
					message: '[LogCleanUpTask] Tombstoned expired audit trail rows (payload purged, chain preserved)',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'app' => 'openregister',
					]
				);
				return;
			}

			$this->logger->debug(
				message: '[LogCleanUpTask] No expired audit trail logs found to clear',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
				]
			);
		} catch (\Throwable $e) {
			// Log any errors that occur during cleanup.
			$this->logger->error(
				message: '[LogCleanUpTask] Failed to clear expired audit trail logs: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
					'exception' => $e,
				]
			);
		}//end try
	}//end clearAuditTrails()

	/**
	 * Delete search trail rows past the configured retention
	 *
	 * Two statements, in this order: stamp `expires` on rows that have none
	 * (SearchTrailMapper::createSearchTrail() never sets it), then delete rows
	 * whose `expires` has passed. A row stamped under an earlier retention
	 * keeps that expiry, the same rule the audit trail follows. A non-positive
	 * retention means "keep": nothing is stamped and nothing is deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-hourly-log-cleanup-job-must-enforce-the-configured-search-trail-retention
	 */
	private function clearSearchTrails(): void {
		try {
			$settings = $this->retentionHandler->getRetentionSettingsOnly();
			$retentionMs = (int) ($settings['searchTrailRetention'] ?? self::DEFAULT_SEARCH_TRAIL_RETENTION_MS);

			if ($retentionMs <= 0) {
				$this->logger->debug(
					message: '[LogCleanUpTask] Search trail retention is not positive, search trails are kept',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'app' => 'openregister',
						'searchTrailRetention' => $retentionMs,
					]
				);
				return;
			}

			$stamped = $this->searchTrailMapper->setExpiryDate(retentionMs: $retentionMs);
			$logsCleared = $this->searchTrailMapper->clearLogs();

			if ($logsCleared === true) {
				$this->logger->info(
					message: '[LogCleanUpTask] Deleted search trail rows past the configured retention',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'app' => 'openregister',
						'searchTrailRetention' => $retentionMs,
						'newlyStamped' => $stamped,
					]
				);
				return;
			}

			$this->logger->debug(
				message: '[LogCleanUpTask] No expired search trail logs found to clear',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
					'searchTrailRetention' => $retentionMs,
					'newlyStamped' => $stamped,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[LogCleanUpTask] Failed to clear expired search trail logs: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
					'exception' => $e,
				]
			);
		}//end try
	}//end clearSearchTrails()
}//end class
