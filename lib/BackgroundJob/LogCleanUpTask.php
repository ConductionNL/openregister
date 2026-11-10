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

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\SearchTrailCleanupInterface;
use OCA\OpenRegister\Db\StateHistoryMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\MetricsRetentionInterface;
use OCA\OpenRegister\Service\Settings\RetentionSettingsReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\BackgroundJob\Support\LogCleanUpTrails;

/**
 * Background job that enforces log retention
 *
 * Runs hourly and performs INDEPENDENT sweeps:
 *
 *   1. Audit trail: expired rows are tombstoned (payload destroyed, row and
 *      hash-chain links kept) through AuditTrailMapper::clearLogs().
 *   2. Search trail: rows past the configured `searchTrailRetention` are
 *      deleted. Search trail rows are inserted WITHOUT an expiry, so the job
 *      stamps `expires` first and sweeps second; before this job did that, the
 *      retention setting was echoed to the admin UI and enforced by nothing,
 *      the only caller of the sweep being a manual admin endpoint.
 *   3. Metrics: rows past the 90-day default of
 *      MetricsRetentionInterface::cleanOldMetrics() are deleted. Audit rows are not touched.
 *
 * A failure in one sweep is logged and never skips the other.
 *
 * @package OCA\OpenRegister\BackgroundJob
 *
 * @psalm-suppress UnusedClass
 */
class LogCleanUpTask extends TimedJob {

	/**
	 * Objects whose purged trail is reconciled with the projection per sweep.
	 *
	 * The sweep runs hourly, so a bounded batch keeps up with a purge that is
	 * itself bounded by what expired in the last hour, without ever turning one
	 * cron tick into a table scan.
	 *
	 * @var int
	 */
	private const PRUNE_BATCH = 500;

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
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * The search trail mapper for database operations
	 *
	 * @var SearchTrailCleanupInterface
	 */
	private readonly SearchTrailCleanupInterface $searchTrailMapper;

	/**
	 * The derived state-history projection, pruned with the trail
	 *
	 * @var ?StateHistoryMapperInterface
	 */
	private readonly ?StateHistoryMapperInterface $stateHistory;

	/**
	 * The operational metrics, cut to their retention
	 *
	 * @var ?MetricsRetentionInterface
	 */
	private readonly ?MetricsRetentionInterface $metrics;

	/**
	 * Constructor for the LogCleanUpTask
	 *
	 * @param ITimeFactory           $time              The time factory for time operations
	 * @param LogCleanUpTrails $trails Audit trail, search trail, state history and metrics to cut.
	 * @param RetentionSettingsReaderInterface $retentionHandler  The retention settings handler
	 * @param LoggerInterface        $logger            The logger for logging operations
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-hourly-log-cleanup-job-must-enforce-the-configured-search-trail-retention
	 */
	public function __construct(
		ITimeFactory $time,
		LogCleanUpTrails $trails,
		private readonly RetentionSettingsReaderInterface $retentionHandler,
		private readonly LoggerInterface $logger,
	) {
		$this->auditTrailMapper = $trails->auditTrailMapper;
		$this->searchTrailMapper = $trails->searchTrailMapper;
		$this->stateHistory = $trails->stateHistory;
		$this->metrics = $trails->metrics;
		parent::__construct(time: $time);
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
		// AFTER the purge, not before: the rows this prunes are the ones the
		// purge just tombstoned, so running it first would prune last hour's
		// purge and leave this one's derivations standing for an hour.
		$this->pruneStateHistory();
		$this->clearOldMetrics();
	}//end run()

	/**
	 * Delete operational metrics past their retention.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	private function clearOldMetrics(): void {
		try {
			$deleted = $this->metrics?->cleanOldMetrics();
			if ($deleted !== null) {
				$this->logger->info(message: '[LogCleanUpTask] Deleted ' . $deleted . ' metric rows past their retention', context: $this->place());
			}
		} catch (\Throwable $e) {
			$this->logger->warning(message: '[LogCleanUpTask] Metrics cleanup failed: ' . $e->getMessage(), context: $this->place());
		}
	}//end clearOldMetrics()

	/**
	 * Drop the projected intervals whose source payload has been purged.
	 *
	 * The state-history projection is DERIVED from the audit trail's `changed`
	 * payload. A retention purge destroys that payload, so the derivation has
	 * to go with it, or a history filter keeps answering about a period nothing
	 * else in the instance can show. A projection that is one sweep behind is a
	 * smaller problem than a cleanup job Nextcloud disables, so a failure is only
	 * logged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	private function pruneStateHistory(): void {
		if ($this->stateHistory === null) {
			return;
		}

		try {
			$pruned = $this->prunePurgedIntervals(history: $this->stateHistory);
			if ($pruned > 0) {
				$this->logger->info(message: '[LogCleanUpTask] Pruned ' . $pruned . ' state-history intervals whose trail was purged', context: $this->place());
			}
		} catch (\Throwable $e) {
			$this->logger->warning(message: '[LogCleanUpTask] State-history prune failed: ' . $e->getMessage(), context: $this->place());
		}
	}//end pruneStateHistory()

	/**
	 * Prune one batch of purged objects and count the intervals that went.
	 *
	 * 🔴 ONLY CLOSED INTERVALS GO. The open one describes the state the object
	 * is in NOW, which the object itself still asserts; it is not derived from
	 * the purged payload, and dropping it would make a case sitting in bezwaar
	 * for ten years vanish from "was ever in bezwaar" the day its oldest audit
	 * row expired.
	 *
	 * @param StateHistoryMapperInterface $history The projection.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	private function prunePurgedIntervals(StateHistoryMapperInterface $history): int {
		$pruned = 0;
		foreach ($this->auditTrailMapper->findPurgedHorizons(limit: self::PRUNE_BATCH) as $uuid => $horizon) {
			if ($horizon !== '') {
				$pruned += $history->pruneClosedIntervalsBefore(objectUuid: $uuid, horizon: new DateTime($horizon));
			}
		}

		return $pruned;
	}//end prunePurgedIntervals()

	/**
	 * Tombstone expired audit trail rows
	 *
	 * Since or#2265 this destroys the payload and stamps `purged_at` rather than
	 * deleting the row, so the hash chain stays verifiable across a lawful purge, and
	 * `expires` follows the RETENTION OF THE OBJECT the row describes rather than a
	 * flat 30 days.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-hourly-log-cleanup-job-must-enforce-the-configured-search-trail-retention
	 */
	private function clearAuditTrails(): void {
		try {
			if ($this->auditTrailMapper->clearLogs() === true) {
				$message = '[LogCleanUpTask] Tombstoned expired audit trail rows (payload purged, chain preserved)';
				$this->logger->info(message: $message, context: $this->logContext());
				return;
			}

			$this->logger->debug(message: '[LogCleanUpTask] No expired audit trail logs found to clear', context: $this->logContext());
		} catch (\Throwable $e) {
			$this->reportFailure(message: 'Failed to clear expired audit trail logs', failure: $e);
		}
	}//end clearAuditTrails()

	/**
	 * Delete search trail rows past the configured retention
	 *
	 * A non-positive retention means "keep": nothing is stamped and nothing is deleted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-hourly-log-cleanup-job-must-enforce-the-configured-search-trail-retention
	 */
	private function clearSearchTrails(): void {
		try {
			$settings    = $this->retentionHandler->getRetentionSettingsOnly();
			$retentionMs = (int) ($settings['searchTrailRetention'] ?? self::DEFAULT_SEARCH_TRAIL_RETENTION_MS);
			if ($retentionMs > 0) {
				$this->sweepSearchTrails(retentionMs: $retentionMs);
				return;
			}

			$message = '[LogCleanUpTask] Search trail retention is not positive, search trails are kept';
			$this->logger->debug(message: $message, context: $this->logContext(extra: ['searchTrailRetention' => $retentionMs]));
		} catch (\Throwable $e) {
			$this->reportFailure(message: 'Failed to clear expired search trail logs', failure: $e);
		}
	}//end clearSearchTrails()

	/**
	 * Stamp and delete search trail rows.
	 *
	 * Two statements, in this order: stamp `expires` on rows that have none
	 * (SearchTrailMapper::createSearchTrail() never sets it), then delete rows
	 * whose `expires` has passed. A row stamped under an earlier retention
	 * keeps that expiry, the same rule the audit trail follows.
	 *
	 * @param int $retentionMs The configured retention.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-hourly-log-cleanup-job-must-enforce-the-configured-search-trail-retention
	 */
	private function sweepSearchTrails(int $retentionMs): void {
		$stamped = $this->searchTrailMapper->setExpiryDate(retentionMs: $retentionMs);
		$cleared = $this->searchTrailMapper->clearLogs();
		$context = $this->logContext(extra: ['searchTrailRetention' => $retentionMs, 'newlyStamped' => $stamped]);

		if ($cleared === true) {
			$this->logger->info(message: '[LogCleanUpTask] Deleted search trail rows past the configured retention', context: $context);
			return;
		}

		$this->logger->debug(message: '[LogCleanUpTask] No expired search trail logs found to clear', context: $context);
	}//end sweepSearchTrails()

	/**
	 * Log a trail sweep that failed.
	 *
	 * @param string     $message What failed.
	 * @param \Throwable $failure Why.
	 *
	 * @return void
	 */
	private function reportFailure(string $message, \Throwable $failure): void {
		$this->logger->error(
			message: '[LogCleanUpTask] ' . $message . ': ' . $failure->getMessage(),
			context: $this->logContext(extra: ['exception' => $failure])
		);
	}//end reportFailure()

	/**
	 * The context of a trail sweep's log line.
	 *
	 * @param array<string, mixed> $extra What the line adds.
	 *
	 * @return array<string, mixed>
	 */
	private function logContext(array $extra = []): array {
		return $this->place() + ['app' => 'openregister'] + $extra;
	}//end logContext()

	/**
	 * Where a log line comes from.
	 *
	 * @return array{file: string, line: int}
	 */
	private function place(): array {
		return ['file' => __FILE__, 'line' => __LINE__];
	}//end place()
}//end class
