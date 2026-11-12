<?php

/**
 * OpenRegister trail retention queries
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Exception;

/**
 * The retention writes the audit-trail and search-trail mappers share: clear
 * every row, stamp an expiry on rows without one, and log-then-rethrow a
 * failed write.
 *
 * The using mapper is a QBMapper with a `$logger` and declares
 * `TRAIL_NOUN` ('audit trail', 'search trail') for its log messages.
 */
trait TrailRetentionTrait {
	/**
	 * Delete every row of the trail, expired or not.
	 *
	 * @return bool True if any rows were deleted, false otherwise.
	 *
	 * @throws Exception Database operation exceptions.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function clearAllLogs(): bool {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName());

			return $qb->executeStatement() > 0;
		} catch (Exception $e) {
			$this->rethrowLogged(failure: 'clear all ' . self::TRAIL_NOUN . ' logs', exception: $e);
		}
	}//end clearAllLogs()

	/**
	 * Set the expiry of every row without one to its creation date plus the retention period.
	 *
	 * @param int $retentionMs Retention period in milliseconds.
	 *
	 * @return int Number of rows updated.
	 *
	 * @throws Exception Database operation exceptions.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-must-support-minimum-10-year-retention
	 */
	public function setExpiryDate(int $retentionMs): int {
		try {
			$retentionSeconds = intval($retentionMs / 1000);

			$qb = $this->db->getQueryBuilder();

			// DATE_ADD is MySQL/MariaDB only, so the interval is spelled per platform.
			$expiresExpression = sprintf('DATE_ADD(created, INTERVAL %d SECOND)', $retentionSeconds);
			if ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
				$expiresExpression = sprintf("created + INTERVAL '%d seconds'", $retentionSeconds);
			}

			$qb->update($this->getTableName())
				->set('expires', $qb->createFunction($expiresExpression))
				->where($qb->expr()->isNull('expires'));

			return $qb->executeStatement();
		} catch (Exception $e) {
			$this->rethrowLogged(failure: 'set expiry dates for ' . self::TRAIL_NOUN . 's', exception: $e);
		}
	}//end setExpiryDate()

	/**
	 * Log a failed retention write as an error and throw it on.
	 *
	 * @param string    $failure   What failed, after "Failed to ".
	 * @param Exception $exception The failure.
	 *
	 * @return never
	 *
	 * @throws Exception Always, the given exception.
	 */
	private function rethrowLogged(string $failure, Exception $exception): never {
		$mapper = substr((string) strrchr(self::class, '\\'), 1);
		$this->logger->error(
			message: '[' . $mapper . '] Failed to ' . $failure . ': ' . $exception->getMessage(),
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'app' => 'openregister',
				'exception' => $exception,
			]
		);

		throw $exception;
	}//end rethrowLogged()
}//end trait
