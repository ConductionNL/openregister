<?php

/**
 * SearchTrailCleanupInterface: what callers outside the Db component ask of SearchTrailMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Expiring and clearing search trail rows, as the log clean-up job does. Only the methods that caller calls.
 */
interface SearchTrailCleanupInterface {

	/**
	 * Set expiry dates for search trails based on retention period in milliseconds
	 *
	 * Updates the expires column for search trails based on their creation date plus the retention period.
	 * Only affects search trails that don't already have an expiry date set.
	 *
	 * @param int $retentionMs Retention period in milliseconds
	 *
	 * @return int Number of search trails updated
	 *
	 * @throws \Exception Database operation exceptions
	 */
	public function setExpiryDate(int $retentionMs): int;

	/**
	 * Clear expired search trail logs from the database
	 *
	 * This method deletes all search trail logs that have expired (i.e., their 'expires' date is
	 * earlier than the current date and time) and have the 'expires' column set. This helps maintain
	 * database performance by removing old log entries that are no longer needed.
	 *
	 * @return bool True if any logs were deleted, false otherwise
	 *
	 * @throws \Exception Database operation exceptions
	 */
	public function clearLogs(): bool;
}//end interface
