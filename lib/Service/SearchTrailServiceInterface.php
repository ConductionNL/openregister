<?php

/**
 * SearchTrailServiceInterface: the part of SearchTrailService that the search trail controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use DateTime;
use OCA\OpenRegister\Db\SearchTrail;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The methods of SearchTrailService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SearchTrailServiceInterface {

	/**
	 * Get paginated search trail logs
	 *
	 * @param array $config Configuration array containing:
	 *                      - limit: Maximum number of results to return
	 *                      - offset: Number of results to skip
	 *                      - page: Page number (alternative to offset)
	 *                      - filters: Filter criteria
	 *                      - sort: Sort criteria
	 *                      - search: Search term
	 *                      - from: Start date filter
	 *                      - to: End date filter
	 *
	 * @return (array|float|int)[]
	 *
	 * @psalm-return array{results: array, total: int, page: float|int<1, max>, pages: int, limit: int<1, max>, offset: int}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
	 */
	public function getSearchTrails(array $config = []): array;

	/**
	 * Get a specific search trail by ID
	 *
	 * @param int $id The search trail ID
	 *
	 * @return SearchTrail The search trail entity
	 *
	 * @throws DoesNotExistException If the search trail is not found
	 *
	 * @spec exclude Thin find-by-id then name-enrich of a single trail; no analytics or orchestration.
	 */
	public function getSearchTrail(int $id): SearchTrail;

	/**
	 * Get comprehensive search statistics
	 *
	 * @param DateTime|null $from Start date for statistics
	 * @param DateTime|null $to End date for statistics
	 *
	 * @return array Search statistics data
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getSearchStatistics(?DateTime $from = null, ?DateTime $to = null): array;

	/**
	 * Get popular search terms with enhanced analytics
	 *
	 * @param int $limit Maximum number of terms to return
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return array Popular search terms data
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getPopularSearchTerms(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array;

	/**
	 * Get search activity patterns by time period
	 *
	 * @param string $interval Time interval ('hour', 'day', 'week', 'month')
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return array Search activity data with insights
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getSearchActivity(string $interval = 'day', ?DateTime $from = null, ?DateTime $to = null): array;

	/**
	 * Get search statistics by register and schema with insights
	 *
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return array Register/schema statistics data
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getRegisterSchemaStatistics(?DateTime $from = null, ?DateTime $to = null): array;

	/**
	 * Get user agent statistics with browser insights
	 *
	 * @param int $limit Maximum number of user agents to return
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return array User agent statistics data
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getUserAgentStatistics(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array;

	/**
	 * Clean up old search trail logs
	 *
	 * @param DateTime|null $_before Delete entries older than this date (unused, kept for API compatibility)
	 *
	 * @return (bool|int|string)[] Cleanup results
	 *
	 * @psalm-return array{
	 *     success: bool,
	 *     deleted: 0|1,
	 *     error?: string,
	 *     message: 'Cleanup operation failed'|'No expired entries to delete'
	 *             |'Successfully deleted expired search trail entries',
	 *     cleanup_date?: string
	 * }
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
	 */
	public function cleanupSearchTrails(?DateTime $_before = null): array;
}//end interface
