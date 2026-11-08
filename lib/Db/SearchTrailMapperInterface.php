<?php

/**
 * SearchTrailMapperInterface: what callers outside the Db component use of SearchTrailMapper.
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

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * The methods of SearchTrailMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to SearchTrailMapper.
 */
interface SearchTrailMapperInterface {

	/**
	 * Find a search trail by ID
	 *
	 * @param int $id The search trail ID
	 *
	 * @return SearchTrail The search trail entity
	 *
	 * @throws DoesNotExistException If the search trail is not found
	 * @throws MultipleObjectsReturnedException If multiple search trails are found
	 */
	public function find(int $id): SearchTrail;

	/**
	 * Find all search trails with optional filters
	 *
	 * @param int|null $limit Maximum number of results to return
	 * @param int|null $offset Number of results to skip
	 * @param array $filters Filter criteria
	 * @param array $sort Sort criteria
	 * @param string|null $search Search term
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return SearchTrail[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\SearchTrail>
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		array $filters = [],
		array $sort = [],
		?string $search = null,
		?DateTime $from = null,
		?DateTime $to = null,
	): array;

	/**
	 * Count search trails with optional filters
	 *
	 * @param array $filters Filter criteria
	 * @param string|null $search Search term
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return int Number of matching search trails
	 */
	public function count(
		array $filters = [],
		?string $search = null,
		?DateTime $from = null,
		?DateTime $to = null,
	): int;

	/**
	 * Create a new search trail entry
	 *
	 * @param array $searchQuery The search query parameters
	 * @param int $resultCount The number of results returned
	 * @param int $totalResults The total number of matching results
	 * @param float $responseTime The response time in milliseconds
	 * @param string $executionType The execution type ('sync' or 'async')
	 *
	 * @return SearchTrail The created search trail entity
	 */
	public function createSearchTrail(
		array $searchQuery,
		int $resultCount,
		int $totalResults,
		float $responseTime,
		string $executionType = 'sync',
	): SearchTrail;

	/**
	 * Get most popular search terms
	 *
	 * @param int $limit Maximum number of terms to return
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return (float|int|mixed)[][] Array of popular search terms with counts
	 *
	 * @psalm-return array<array{term: mixed, count: int,
	 *     avg_results: float, avg_response_time: float}>
	 */
	public function getPopularSearchTerms(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array;

	/**
	 * Get search activity by time period
	 *
	 * @param string $interval Time interval ('hour', 'day', 'week', 'month')
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return (float|int|mixed)[][] Array of search activity by time period
	 *
	 * @psalm-return array<array{period: mixed, count: int,
	 *     avg_results: float, avg_response_time: float}>
	 */
	public function getSearchActivityByTime(string $interval = 'day', ?DateTime $from = null, ?DateTime $to = null): array;

	/**
	 * Get search statistics by register and schema
	 *
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return (float|int|mixed)[][] Array of search statistics by register and schema
	 *
	 * @psalm-return array<array{register: mixed, schema: mixed,
	 *     register_uuid: mixed, schema_uuid: mixed, count: int,
	 *     avg_results: float, avg_response_time: float}>
	 */
	public function getSearchStatisticsByRegisterSchema(?DateTime $from = null, ?DateTime $to = null): array;

	/**
	 * Get user agent statistics
	 *
	 * @param int $limit Maximum number of user agents to return
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return (float|int|mixed)[][] Array of user agent statistics
	 *
	 * @psalm-return array<array{user_agent: mixed, count: int,
	 *     avg_results: float, avg_response_time: float}>
	 */
	public function getUserAgentStatistics(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array;

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

	/**
	 * Clear all search trail logs, expired or not.
	 *
	 * The admin clear-all endpoint deletes every row; {@see clearLogs()} only
	 * removes the expired ones.
	 *
	 * @return bool True if any logs were deleted, false otherwise
	 *
	 * @throws \Exception Database operation exceptions
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function clearAllLogs(): bool;

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
}//end interface
