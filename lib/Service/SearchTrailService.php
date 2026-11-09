<?php

/**
 * OpenRegister SearchTrailService
 *
 * Service class for managing search trail operations in the OpenRegister application.
 * This service acts as a business logic layer between controllers and the SearchTrailMapper,
 * providing comprehensive search analytics and logging functionality.
 *
 * This service also supports self-clearing (automatic cleanup) of old search trails.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Db\SearchTrailCriteria;
use OCA\OpenRegister\Db\SearchTrailMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Service class for managing search trail operations
 *
 * This service provides business logic for search trail logging, analytics,
 * management operations, and supports self-clearing (automatic cleanup) of old search trails.
 * Query processing, name enrichment and the analytics calculations live in small
 * SearchTrail* collaborators.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */
class SearchTrailService implements SearchTrailServiceInterface {

	/**
	 * Whether self-clearing (automatic cleanup) is enabled.
	 * Disabled by default - cleanup should be handled by cron jobs.
	 *
	 * @var boolean
	 */
	private bool $selfClearingEnabled = false;

	/**
	 * Retention period in days for search trails.
	 *
	 * @var integer
	 */
	private int $retentionDays = 365;

	/**
	 * Turns request parameters into mapper arguments.
	 *
	 * @var SearchTrailQueryConfig
	 */
	private readonly SearchTrailQueryConfig $queryConfig;

	/**
	 * Adds register and schema names to trails.
	 *
	 * @var SearchTrailNameEnricher
	 */
	private readonly SearchTrailNameEnricher $nameEnricher;

	/**
	 * Calculates the derived statistics.
	 *
	 * @var SearchTrailStatistics
	 */
	private readonly SearchTrailStatistics $statistics;

	/**
	 * Calculates the activity insights.
	 *
	 * @var SearchTrailActivityInsights
	 */
	private readonly SearchTrailActivityInsights $activityInsights;

	/**
	 * Parses user agent strings.
	 *
	 * @var SearchTrailUserAgentParser
	 */
	private readonly SearchTrailUserAgentParser $userAgentParser;

	/**
	 * Constructor for SearchTrailService
	 *
	 * Initializes service with required mappers and optional configuration.
	 * Sets retention period and self-clearing flag if provided.
	 *
	 * @param SearchTrailMapperInterface $searchTrailMapper Mapper for search trail database operations
	 * @param RegisterSchemaMappers $mappers The register and schema mappers.
	 * @param int|null $retentionDays Optional retention period in days (default: 365)
	 * @param bool|null $selfClearing Optional flag to enable/disable self-clearing
	 *                                (default: false, use cron jobs instead)
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SearchTrailMapperInterface $searchTrailMapper,
		RegisterSchemaMappers $mappers,
		?int $retentionDays = null,
		?bool $selfClearing = null,
	) {
		$this->retentionDays = $retentionDays ?? $this->retentionDays;
		$this->selfClearingEnabled = $selfClearing ?? $this->selfClearingEnabled;
		$this->queryConfig = new SearchTrailQueryConfig();
		$this->nameEnricher = new SearchTrailNameEnricher(registerMapper: $mappers->registerMapper, schemaMapper: $mappers->schemaMapper);
		$this->statistics = new SearchTrailStatistics();
		$this->activityInsights = new SearchTrailActivityInsights();
		$this->userAgentParser = new SearchTrailUserAgentParser();
	}//end __construct()

	/**
	 * Create a search trail log entry
	 *
	 * Processes search query parameters and creates a comprehensive search trail entry
	 * for analytics and monitoring purposes. System parameters (starting with _) are
	 * automatically excluded from tracking by the mapper.
	 *
	 * If self-clearing is enabled, automatically triggers cleanup of old search trails
	 * after creating the new entry.
	 *
	 * @param array<string, mixed> $query The search query parameters (system params excluded)
	 * @param int $resultCount The number of results returned in this page
	 * @param int $totalResults The total number of matching results
	 * @param float $responseTime The response time in milliseconds (default: 0.0)
	 * @param string $executionType The execution type: 'sync' or 'async' (default: 'sync')
	 *
	 * @return SearchTrail The created search trail entity
	 *
	 * @throws Exception If search trail creation fails (database error, validation error, etc.)
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
	 */
	public function createSearchTrail(
		array $query,
		int $resultCount,
		int $totalResults,
		float $responseTime = 0.0,
		string $executionType = 'sync',
	): SearchTrail {
		try {
			// Mapper handles filtering of system parameters (starting with _).
			$outcome = $this->outcome(resultCount: $resultCount, totalResults: $totalResults);
			return $this->selfCleared(trail: $this->searchTrailMapper->createSearchTrail($query, $outcome, $responseTime, $executionType));
		} catch (Exception $e) {
			// Wrap exception with more context for debugging.
			throw new Exception('Search trail creation failed: ' . $e->getMessage(), 0, $e);
		}
	}//end createSearchTrail()

	/**
	 * The unsaved trail carrying what a search returned.
	 *
	 * @param int $resultCount  The number of results returned
	 * @param int $totalResults The total number of matching results
	 *
	 * @return SearchTrail
	 */
	private function outcome(int $resultCount, int $totalResults): SearchTrail {
		$outcome = new SearchTrail();
		$outcome->setResultCount($resultCount);
		$outcome->setTotalResults($totalResults);
		return $outcome;
	}//end outcome()

	/**
	 * Run the self-clearing a new trail triggers, and hand the trail back.
	 *
	 * @param SearchTrail $trail The trail just created.
	 *
	 * @return SearchTrail The same trail.
	 */
	private function selfCleared(SearchTrail $trail): SearchTrail {
		$this->selfClearIfEnabled();
		return $trail;
	}//end selfCleared()

	/**
	 * Clean up expired search trails
	 *
	 * This method deletes search trails that have expired based on their expires column.
	 * Intended to be called by cron jobs or manual cleanup operations.
	 *
	 * @return (bool|int|string)[] Cleanup results
	 *
	 * @psalm-return array{
	 *     success: bool,
	 *     deleted: 0|1,
	 *     error?: string,
	 *     message: 'Self-clearing operation failed'|
	 *              'Self-clearing: deleted expired search trail entries'|
	 *              'Self-clearing: no expired entries to delete',
	 *     cleanup_date?: string
	 * }
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
	 */
	public function clearExpiredSearchTrails(): array {
		return $this->clearExpiredLogs(
			messages: [
				'deleted' => 'Self-clearing: deleted expired search trail entries',
				'none' => 'Self-clearing: no expired entries to delete',
				'failed' => 'Self-clearing operation failed',
			]
		);
	}//end clearExpiredSearchTrails()

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
	public function getSearchTrails(array $config = []): array {
		$query = $this->queryConfig->process(config: $config);
		$enrichedTrails = $this->nameEnricher->enrich(trails: $this->findTrails(query: $query));
		$total = $this->countTrails(query: $query);

		return [
			'results' => $enrichedTrails,
			'total' => $total,
			'page' => $query['page'],
			'pages' => $this->calculatePages(total: $total, limit: $query['limit']),
			'limit' => $query['limit'],
			'offset' => $query['offset'],
		];
	}//end getSearchTrails()

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
	public function getSearchTrail(int $id): SearchTrail {
		$trail = $this->searchTrailMapper->find($id);

		// Enrich single trail with register and schema names.
		$enrichedTrails = $this->nameEnricher->enrich(trails: [$trail]);

		return $enrichedTrails[0];
	}//end getSearchTrail()

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
	public function getSearchStatistics(?DateTime $from = null, ?DateTime $to = null): array {
		$mapper = $this->searchTrailMapper;
		$stats = $this->statistics->withOutcomeRates(stats: $mapper->getSearchStatistics(from: $from, to: $to));
		$stats['unique_search_terms'] = $mapper->getUniqueSearchTermsCount(from: $from, to: $to);
		$stats['unique_users'] = $mapper->getUniqueUsersCount(from: $from, to: $to);
		$stats['avg_searches_per_session'] = $mapper->getAverageSearchesPerSession(from: $from, to: $to);
		$stats['avg_object_views_per_session'] = $mapper->getAverageObjectViewsPerSession(from: $from, to: $to);

		return $this->statistics->withDerivedMetrics(stats: $stats, from: $from, to: $to);
	}//end getSearchStatistics()

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
	public function getPopularSearchTerms(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array {
		$terms = $this->searchTrailMapper->getPopularSearchTerms(limit: $limit, from: $from, to: $to);

		return $this->statistics->popularTerms(terms: $terms, from: $from, to: $to);
	}//end getPopularSearchTerms()

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
	public function getSearchActivity(string $interval = 'day', ?DateTime $from = null, ?DateTime $to = null): array {
		$activity = $this->searchTrailMapper->getSearchActivityByTime(interval: $interval, from: $from, to: $to);

		return [
			'activity' => $activity,
			'insights' => $this->activityInsights->insights(activity: $activity),
			'interval' => $interval,
			'period' => $this->statistics->period(from: $from, to: $to),
		];
	}//end getSearchActivity()

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
	public function getRegisterSchemaStatistics(?DateTime $from = null, ?DateTime $to = null): array {
		$stats = $this->searchTrailMapper->getSearchStatisticsByRegisterSchema(from: $from, to: $to);

		return $this->statistics->registerSchemaUsage(stats: $stats, from: $from, to: $to);
	}//end getRegisterSchemaStatistics()

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
	public function getUserAgentStatistics(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array {
		$stats = $this->searchTrailMapper->getUserAgentStatistics(limit: $limit, from: $from, to: $to);
		$enhancedStats = $this->userAgentParser->withBrowserInfo(stats: $stats);

		return $this->statistics->userAgentUsage(userAgentStats: $enhancedStats, from: $from, to: $to);
	}//end getUserAgentStatistics()

	/**
	 * Clean up old search trail logs
	 *
	 * Note: clearLogs() only removes expired entries, ignoring the $before parameter.
	 * This maintains consistency with the audit trail cleanup approach.
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
	public function cleanupSearchTrails(?DateTime $_before = null): array {
		return $this->clearExpiredLogs(
			messages: [
				'deleted' => 'Successfully deleted expired search trail entries',
				'none' => 'No expired entries to delete',
				'failed' => 'Cleanup operation failed',
			]
		);
	}//end cleanupSearchTrails()

	/**
	 * Self-clearing: clean up old search trails when enabled.
	 *
	 * This prevents database growth but may impact performance on high-traffic systems.
	 *
	 * @return void
	 */
	private function selfClearIfEnabled(): void {
		if ($this->selfClearingEnabled === true) {
			$this->clearExpiredSearchTrails();
		}
	}//end selfClearIfEnabled()

	/**
	 * Remove the expired search trails and report the outcome.
	 *
	 * The mapper's clearLogs() returns whether anything was deleted, not a count.
	 *
	 * @param array{deleted: TMessage, none: TMessage, failed: TMessage} $messages Outcome messages
	 *
	 * @return (bool|int|string)[] Cleanup results
	 *
	 * @template TMessage of string
	 *
	 * @psalm-return array{success: bool, deleted: 0|1, error?: string, message: TMessage, cleanup_date?: string}
	 */
	private function clearExpiredLogs(array $messages): array {
		try {
			$deleted = 0;
			$message = $messages['none'];
			if ($this->searchTrailMapper->clearLogs() === true) {
				$deleted = 1;
				$message = $messages['deleted'];
			}

			$cleanupDate = (new DateTime())->format('Y-m-d H:i:s');
			return ['success' => true, 'deleted' => $deleted, 'cleanup_date' => $cleanupDate, 'message' => $message];
		} catch (Exception $e) {
			return ['success' => false, 'deleted' => 0, 'error' => $e->getMessage(), 'message' => $messages['failed']];
		}
	}//end clearExpiredLogs()

	/**
	 * Fetch one page of trails for processed query parameters.
	 *
	 * @param array $query Processed configuration
	 *
	 * @return SearchTrail[]
	 */
	private function findTrails(array $query): array {
		return $this->searchTrailMapper->findAll(
			criteria: $this->criteria(query: $query),
			limit: $query['limit'],
			offset: $query['offset'],
			sort: $query['sort']
		);
	}//end findTrails()

	/**
	 * The filters, search term and created window of processed query parameters.
	 *
	 * @param array $query Processed configuration
	 *
	 * @return SearchTrailCriteria
	 */
	private function criteria(array $query): SearchTrailCriteria {
		return new SearchTrailCriteria(
			filters: $query['filters'],
			search: $query['search'],
			from: $query['from'],
			to: $query['to']
		);
	}//end criteria()

	/**
	 * Count all trails matching processed query parameters.
	 *
	 * @param array $query Processed configuration
	 *
	 * @return int
	 */
	private function countTrails(array $query): int {
		return $this->searchTrailMapper->count(criteria: $this->criteria(query: $query));
	}//end countTrails()

	/**
	 * Calculate total number of pages
	 *
	 * @param int $total Total number of items
	 * @param int $limit Items per page
	 *
	 * @return int Total number of pages
	 */
	private function calculatePages(int $total, int $limit): int {
		if ($limit <= 0) {
			return 0;
		}

		return (int)ceil($total / $limit);
	}//end calculatePages()
}//end class
