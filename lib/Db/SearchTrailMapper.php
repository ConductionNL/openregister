<?php

/**
 * OpenRegister SearchTrailMapper
 *
 * This file contains the mapper class for SearchTrail entities,
 * providing database operations and statistical queries for search analytics.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Db\SearchTrail\SearchTrailFilterRun;
use OCA\OpenRegister\Db\SearchTrail\SearchTrailPage;
use OCA\OpenRegister\Db\SearchTrail\CreatedRange;
use OCA\OpenRegister\Db\SearchTrail\SearchTrailStatistics;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * SearchTrailMapper handles database operations for SearchTrail entities
 *
 * Provides comprehensive CRUD operations and statistical query methods
 * for search analytics and optimization.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method SearchTrail insert(Entity $entity)
 * @method SearchTrail update(Entity $entity)
 * @method SearchTrail insertOrUpdate(Entity $entity)
 * @method SearchTrail delete(Entity $entity)
 * @method SearchTrail findEntity(IQueryBuilder $query)
 * @method list<SearchTrail> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<SearchTrail>
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     A QBMapper for a table the
 *          search-trail reporting reads through roughly thirty distinct query
 *          shapes, each a method here. Adding clearAllLogs() for the admin
 *          endpoint (which called a method that never existed) took the class
 *          from just under phpmd's 1000-line threshold to just over it.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Search trail tracking requires integration with many components
 */
class SearchTrailMapper extends QBMapper implements SearchTrailMapperInterface, SearchTrailCleanupInterface {
	/**
	 * The search trail columns a filter may name.
	 *
	 * @var array<int, string>
	 */
	private const FILTER_COLUMNS = [
		'id',
		'uuid',
		'created',
		'expires',
		'search_term',
		'page',
		'limit',
		'offset',
		'facets_requested',
		'facetable_requested',
		'register',
		'register_uuid',
		'schema',
		'schema_uuid',
		'sort_parameters',
		'published_only',
		'filters',
		'query_parameters',
		'result_count',
		'total_results',
		'response_time',
		'execution_type',
		'ip_address',
		'user_agent',
		'request_uri',
		'http_method',
		'user',
		'user_name',
		'session',
		'size',
	];

	/**
	 * Constructor for SearchTrailMapper
	 *
	 * @param IDBConnection $db Database connection
	 * @param IRequest $request Request object for getting request data
	 * @param IUserSession $userSession User session for getting user information
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		IDBConnection $db,
		private readonly IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(db: $db, tableName: 'openregister_search_trails', entityClass: SearchTrail::class);
	}//end __construct()

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
	public function find(int $id): SearchTrail {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find all search trails with optional filters
	 *
	 * @param SearchTrailCriteria|null $criteria Filters, search term and created window; null for all.
	 * @param int|null $limit Maximum number of results to return
	 * @param int|null $offset Number of results to skip
	 * @param array $sort Sort criteria
	 *
	 * @return SearchTrail[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\SearchTrail>
	 */
	public function findAll(SearchTrailCriteria|null $criteria = null, int|null $limit = null, int|null $offset = null, array $sort = []): array {
		$page = new SearchTrailPage(limit: $limit, offset: $offset, sort: $sort);
		return $this->findMatching(criteria: ($criteria ?? new SearchTrailCriteria()), page: $page);
	}//end findAll()

	/**
	 * One ordered window of the trails matching the criteria.
	 *
	 * @param SearchTrailCriteria $criteria What the trails must match.
	 * @param SearchTrailPage     $page     The order and the window.
	 *
	 * @return SearchTrail[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\SearchTrail>
	 */
	private function findMatching(SearchTrailCriteria $criteria, SearchTrailPage $page): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName());

		$this->applyCriteria(qb: $qb, criteria: $criteria);

		$this->applySort(qb: $qb, sort: $page->sort);

		$this->applyPaging(qb: $qb, page: $page);

		return $this->findEntities(query: $qb);
	}//end findMatching()

	/**
	 * Order by the requested fields, or newest first when none are requested.
	 *
	 * @param IQueryBuilder $qb   The query.
	 * @param array         $sort Field to direction.
	 *
	 * @return void
	 */
	private function applySort(IQueryBuilder $qb, array $sort): void {
		if (empty($sort) === true) {
			$qb->orderBy('created', 'DESC');
			return;
		}

		foreach ($sort as $field => $direction) {
			// NC 35 types addOrderBy() as (string|..., string|SortDirection|null):
			// an int key (a list-shaped sort) or a non-string direction is a
			// TypeError there, so both are normalised here.
			$qb->addOrderBy((string)$field, $this->normaliseSortDirection(direction: $direction));
		}
	}//end applySort()

	/**
	 * Apply a limit and an offset, each only when given.
	 *
	 * @param IQueryBuilder   $qb   The query.
	 * @param SearchTrailPage $page The limit and the offset.
	 *
	 * @return void
	 */
	private function applyPaging(IQueryBuilder $qb, SearchTrailPage $page): void {
		if ($page->limit !== null) {
			$qb->setMaxResults($page->limit);
		}

		if ($page->offset !== null) {
			$qb->setFirstResult($page->offset);
		}
	}//end applyPaging()

	/**
	 * Apply the column filters, the search term and the created window.
	 *
	 * @param IQueryBuilder       $qb       The query.
	 * @param SearchTrailCriteria $criteria What the trails must match.
	 *
	 * @return void
	 */
	private function applyCriteria(IQueryBuilder $qb, SearchTrailCriteria $criteria): void {
		$this->applyFilters(qb: $qb, filters: $criteria->filters);

		$this->filterBySearchTerm(qb: $qb, search: $criteria->search);

		$this->filterByCreated(qb: $qb, range: [$criteria->from, $criteria->to]);
	}//end applyCriteria()

	/**
	 * Count search trails with optional filters
	 *
	 * @param SearchTrailCriteria|null $criteria Filters, search term and created window; null for all.
	 *
	 * @return int Number of matching search trails
	 */
	public function count(SearchTrailCriteria|null $criteria = null): int {
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->func()->count('*'))
			->from($this->getTableName());

		$this->applyCriteria(qb: $qb, criteria: ($criteria ?? new SearchTrailCriteria()));

		$result = $qb->executeQuery();
		$count = $result->fetchOne();
		$result->closeCursor();

		return (int)$count;
	}//end count()

	/**
	 * Create a new search trail entry
	 *
	 * @param array              $searchQuery The search query parameters.
	 * @param SearchTrailOutcome $outcome     The finished outcome values of the search.
	 *
	 * @return SearchTrail The created search trail entity
	 */
	public function createSearchTrail(array $searchQuery, SearchTrailOutcome $outcome): SearchTrail {
		$searchTrail = new SearchTrail();
		$searchTrail->setResultCount($outcome->resultCount);
		$searchTrail->setTotalResults($outcome->totalResults);
		$searchTrail->setExecutionType($outcome->executionType);
		$searchTrail->setResponseTime($outcome->responseTime);
		return $this->recordSearch(searchTrail: $this->stamped(searchTrail: $searchTrail), searchQuery: $searchQuery);
	}//end createSearchTrail()

	/**
	 * The trail with a fresh uuid and the current moment as its creation time.
	 *
	 * @param SearchTrail $searchTrail The unsaved trail.
	 *
	 * @return SearchTrail The same trail.
	 */
	private function stamped(SearchTrail $searchTrail): SearchTrail {
		$searchTrail->setUuid(Uuid::v4()->toRfc4122());
		$searchTrail->setCreated(new DateTime());
		return $searchTrail;
	}//end stamped()

	/**
	 * Complete a stamped trail with the search, the request and the user, size it and insert it.
	 *
	 * @param SearchTrail $searchTrail The trail with its uuid, timing and outcome set.
	 * @param array       $searchQuery The search query parameters.
	 *
	 * @return SearchTrail The created search trail entity
	 */
	private function recordSearch(SearchTrail $searchTrail, array $searchQuery): SearchTrail {
		// Extract and set search parameters.
		$this->extractSearchParameters(searchTrail: $searchTrail, query: $searchQuery);

		// Set request information.
		$this->setRequestInformation(searchTrail: $searchTrail);

		// Set user information.
		$this->setUserInformation(searchTrail: $searchTrail);

		// Calculate and set the size of the search trail entry, with a minimum default of 14 bytes.
		$serializedSize = strlen(serialize($searchTrail->jsonSerialize()));
		$searchTrail->setSize(max($serializedSize, 14));

		return $this->insert(entity: $searchTrail);
	}//end recordSearch()

	/**
	 * Get search statistics for the given time period
	 *
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return (float|int)[] Array of search statistics
	 *
	 * @psalm-return array{total_searches: int, total_results: int,
	 *     avg_results_per_search: float, avg_response_time: float,
	 *     non_empty_searches: int}
	 */
	public function getSearchStatistics(?DateTime $from = null, ?DateTime $to = null): array {
		return $this->statistics()->summary(range: new CreatedRange(from: $from, to: $to));
	}//end getSearchStatistics()

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
	public function getPopularSearchTerms(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array {
		return $this->statistics()->popularTerms(limit: $limit, range: new CreatedRange(from: $from, to: $to));
	}//end getPopularSearchTerms()

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
	public function getSearchActivityByTime(string $interval = 'day', ?DateTime $from = null, ?DateTime $to = null): array {
		return $this->statistics()->activityByTime(interval: $interval, range: new CreatedRange(from: $from, to: $to));
	}//end getSearchActivityByTime()

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
	public function getSearchStatisticsByRegisterSchema(?DateTime $from = null, ?DateTime $to = null): array {
		return $this->statistics()->byRegisterSchema(range: new CreatedRange(from: $from, to: $to));
	}//end getSearchStatisticsByRegisterSchema()

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
	public function getUserAgentStatistics(int $limit = 10, ?DateTime $from = null, ?DateTime $to = null): array {
		return $this->statistics()->userAgents(limit: $limit, range: new CreatedRange(from: $from, to: $to));
	}//end getUserAgentStatistics()

	/**
	 * Get count of unique search terms for the given time period
	 *
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return int Number of unique search terms
	 *
	 * @psalm-return int<0, max>
	 */
	public function getUniqueSearchTermsCount(?DateTime $from = null, ?DateTime $to = null): int {
		return $this->statistics()->distinctCount(column: 'search_term', range: new CreatedRange(from: $from, to: $to));
	}//end getUniqueSearchTermsCount()

	/**
	 * Get count of unique users for the given time period
	 *
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return int Number of unique users
	 *
	 * @psalm-return int<0, max>
	 */
	public function getUniqueUsersCount(?DateTime $from = null, ?DateTime $to = null): int {
		return $this->statistics()->distinctCount(column: 'user', range: new CreatedRange(from: $from, to: $to));
	}//end getUniqueUsersCount()

	/**
	 * Get average searches per session for the given time period
	 *
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return float Average searches per session
	 */
	public function getAverageSearchesPerSession(?DateTime $from = null, ?DateTime $to = null): float {
		return $this->statistics()->searchesPerSession(range: new CreatedRange(from: $from, to: $to));
	}//end getAverageSearchesPerSession()

	/**
	 * Get average object views per session for the given time period
	 *
	 * This method queries the audit trail table to count 'read' actions per session
	 *
	 * @param DateTime|null $from Start date filter
	 * @param DateTime|null $to End date filter
	 *
	 * @return float Average object views per session
	 */
	public function getAverageObjectViewsPerSession(?DateTime $from = null, ?DateTime $to = null): float {
		return $this->statistics()->objectViewsPerSession(range: new CreatedRange(from: $from, to: $to));
	}//end getAverageObjectViewsPerSession()

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
	public function clearLogs(): bool {
		try {
			// Get the query builder for database operations.
			$qb = $this->db->getQueryBuilder();

			// Build the delete query to remove expired search trail logs that have the 'expires' column set.
			$qb->delete($this->getTableName())
				->where($qb->expr()->isNotNull('expires'))
				->andWhere($qb->expr()->lt('expires', $qb->createFunction('NOW()')));

			// Execute the query and get the number of affected rows.
			$result = $qb->executeStatement();

			// Return true if any rows were affected (i.e., any logs were deleted).
			return $result > 0;
		} catch (\Exception $e) {
			// Log the error for debugging purposes.
			$this->logger->error(
				message: '[SearchTrailMapper] Failed to clear expired search trail logs: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
					'exception' => $e,
				]
			);

			// Re-throw the exception so the caller knows something went wrong.
			throw $e;
		}//end try
	}//end clearLogs()

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
	public function clearAllLogs(): bool {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName());

			$result = $qb->executeStatement();

			return $result > 0;
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[SearchTrailMapper] Failed to clear all search trail logs: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
					'exception' => $e,
				]
			);

			throw $e;
		}//end try
	}//end clearAllLogs()

	/**
	 * Match a search term against the search term, request uri and user agent.
	 *
	 * @param IQueryBuilder $qb     The query to narrow.
	 * @param string|null   $search The term, or null for no condition.
	 *
	 * @return void
	 */
	private function filterBySearchTerm(IQueryBuilder $qb, ?string $search): void {
		if ($search === null) {
			return;
		}

		$like = fn (string $column): string => $qb->expr()->like($column, $qb->createNamedParameter('%' . $search . '%'));
		$qb->andWhere($qb->expr()->orX($like('search_term'), $like('request_uri'), $like('user_agent')));
	}//end filterBySearchTerm()

	/**
	 * The aggregate reads over this mapper's table.
	 *
	 * @return SearchTrailStatistics
	 */
	private function statistics(): SearchTrailStatistics {
		return new SearchTrailStatistics(db: $this->db, table: $this->getTableName());
	}//end statistics()

	/**
	 * Narrow a query to rows created within a range; a null bound is open.
	 *
	 * @param IQueryBuilder                     $qb    The query to narrow.
	 * @param array{0: ?DateTime, 1: ?DateTime} $range The from and to moments.
	 *
	 * @return void
	 */
	private function filterByCreated(IQueryBuilder $qb, array $range): void {
		$this->statistics()->filterByCreated(qb: $qb, range: $range);
	}//end filterByCreated()

	/**
	 * Apply filters to the query builder
	 *
	 * Keys starting with an underscore and keys that are not a search trail
	 * column are skipped.
	 *
	 * @param IQueryBuilder $qb      The query builder
	 * @param array         $filters The filters to apply
	 *
	 * @return void
	 */
	private function applyFilters(IQueryBuilder $qb, array $filters): void {
		foreach ($filters as $field => $value) {
			if (str_starts_with($field, '_') === true || in_array($field, self::FILTER_COLUMNS) === false) {
				continue;
			}

			$this->applyFilter(run: new SearchTrailFilterRun(qb: $qb, field: $field), value: $value);
		}
	}//end applyFilters()

	/**
	 * Apply one column filter.
	 *
	 * An array matches any of its values. A string holding commas matches any
	 * of its trimmed parts. The markers `IS NULL` and `IS NOT NULL` add their
	 * null check and nothing else.
	 *
	 * @param SearchTrailFilterRun $run   The query and the column.
	 * @param mixed                $value The filter value.
	 *
	 * @return void
	 */
	private function applyFilter(SearchTrailFilterRun $run, mixed $value): void {
		if (is_array($value) === true) {
			$this->applyAnyOfFilter(run: $run, values: $value);
			return;
		}

		if ($value === 'IS NOT NULL' || $value === 'IS NULL') {
			$run->qb->andWhere($this->nullCondition(run: $run, marker: $value));
			return;
		}

		$run->qb->andWhere($this->valueCondition(run: $run, value: $value));
	}//end applyFilter()

	/**
	 * Equality on a value, or membership of its trimmed parts when it is a string holding commas.
	 *
	 * @param SearchTrailFilterRun $run   The query and the column.
	 * @param mixed                $value The filter value.
	 *
	 * @return string
	 */
	private function valueCondition(SearchTrailFilterRun $run, mixed $value): string {
		$qb = $run->qb;
		if (is_string($value) === true && strpos($value, ',') !== false) {
			$values = array_map('trim', explode(',', $value));
			return $qb->expr()->in($run->field, $qb->createNamedParameter($values, IQueryBuilder::PARAM_STR_ARRAY));
		}

		return $qb->expr()->eq($run->field, $qb->createNamedParameter($value));
	}//end valueCondition()

	/**
	 * Match any of the values (each may be a null marker); no values adds nothing.
	 *
	 * @param SearchTrailFilterRun $run    The query and the column.
	 * @param array                $values The values, like ['IS NULL', ''].
	 *
	 * @return void
	 */
	private function applyAnyOfFilter(SearchTrailFilterRun $run, array $values): void {
		$qb = $run->qb;
		$conditions = [];
		foreach ($values as $val) {
			if ($val === 'IS NULL' || $val === 'IS NOT NULL') {
				$conditions[] = $this->nullCondition(run: $run, marker: $val);
				continue;
			}

			$conditions[] = $qb->expr()->eq($run->field, $qb->createNamedParameter($val));
		}

		if (empty($conditions) === false) {
			$qb->andWhere($qb->expr()->orX(...$conditions));
		}
	}//end applyAnyOfFilter()

	/**
	 * The null check a marker stands for.
	 *
	 * @param SearchTrailFilterRun $run    The query and the column.
	 * @param string               $marker `IS NULL` or `IS NOT NULL`.
	 *
	 * @return string
	 */
	private function nullCondition(SearchTrailFilterRun $run, string $marker): string {
		if ($marker === 'IS NULL') {
			return $run->qb->expr()->isNull($run->field);
		}

		return $run->qb->expr()->isNotNull($run->field);
	}//end nullCondition()

	/**
	 * Extract search parameters from the query and set them on the search trail
	 *
	 * @param SearchTrail $searchTrail The search trail entity
	 * @param array $query The search query parameters
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Search parameter extraction requires many conditional checks
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function extractSearchParameters(SearchTrail $searchTrail, array $query): void {
		$searchTrail->setSearchTerm($query['_search'] ?? null);
		$searchTrail->setPage($query['_page'] ?? null);
		$searchTrail->setLimit($query['_limit'] ?? null);
		$searchTrail->setOffset($query['_offset'] ?? null);
		$searchTrail->setFacetsRequested(isset($query['_facets']));
		$searchTrail->setFacetableRequested(isset($query['_facetable']) && $query['_facetable'] === true);
		$this->recordRegisterAndSchema(searchTrail: $searchTrail, metadataFilters: ($query['@self'] ?? []));
		$searchTrail->setSortParameters($this->sortParameters(order: ($query['_order'] ?? null)));

		// Non-system parameters are the filters; the original query keeps @self.
		$searchTrail->setFilters(array_filter($query, fn ($key) => strpos($key, '_') !== 0 && $key !== '@self', ARRAY_FILTER_USE_KEY));
		$searchTrail->setQueryParameters(array_filter($query, fn ($key) => strpos($key, '_') !== 0, ARRAY_FILTER_USE_KEY));
	}//end extractSearchParameters()

	/**
	 * Record the register and schema an `@self` filter names, as an id when numeric and as a uuid when a string.
	 *
	 * A numeric string is recorded both ways. The trail is fresh, so a
	 * reference that is neither leaves its fields untouched.
	 *
	 * @param SearchTrail $searchTrail     The trail being built.
	 * @param mixed       $metadataFilters The `@self` filter.
	 *
	 * @return void
	 */
	private function recordRegisterAndSchema(SearchTrail $searchTrail, mixed $metadataFilters): void {
		$register = ($metadataFilters['register'] ?? null);
		if ($register !== null) {
			$searchTrail->setRegister($this->numericReference(reference: $register));
			$searchTrail->setRegisterUuid($this->stringReference(reference: $register));
		}

		$schema = ($metadataFilters['schema'] ?? null);
		if ($schema !== null) {
			$searchTrail->setSchema($this->numericReference(reference: $schema));
			$searchTrail->setSchemaUuid($this->stringReference(reference: $schema));
		}
	}//end recordRegisterAndSchema()

	/**
	 * A reference as an integer id, or null when it is not numeric.
	 *
	 * @param mixed $reference The reference.
	 *
	 * @return int|null
	 */
	private function numericReference(mixed $reference): ?int {
		if (is_numeric($reference) === true) {
			return (int)$reference;
		}

		return null;
	}//end numericReference()

	/**
	 * A reference as a uuid string, or null when it is not a string.
	 *
	 * @param mixed $reference The reference.
	 *
	 * @return string|null
	 */
	private function stringReference(mixed $reference): ?string {
		if (is_string($reference) === true) {
			return $reference;
		}

		return null;
	}//end stringReference()

	/**
	 * The sort parameters an `_order` value stands for: none, the array itself, or one value wrapped.
	 *
	 * @param mixed $order The `_order` value.
	 *
	 * @return array
	 */
	private function sortParameters(mixed $order): array {
		if ($order === null) {
			return [];
		}

		if (is_array($order) === true) {
			return $order;
		}

		return [$order];
	}//end sortParameters()

	/**
	 * Set request information on the search trail
	 *
	 * @param SearchTrail $searchTrail The search trail entity
	 *
	 * @return void
	 */
	private function setRequestInformation(SearchTrail $searchTrail): void {
		$searchTrail->setIpAddress($this->request->getRemoteAddress());
		$searchTrail->setUserAgent($this->request->getHeader('User-Agent'));
		$searchTrail->setRequestUri($this->request->getRequestUri());
		$searchTrail->setHttpMethod($this->request->getMethod());
	}//end setRequestInformation()

	/**
	 * Set user information on the search trail
	 *
	 * @param SearchTrail $searchTrail The search trail entity
	 *
	 * @return void
	 */
	private function setUserInformation(SearchTrail $searchTrail): void {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$searchTrail->setUser($user->getUID());
			$searchTrail->setUserName($user->getDisplayName());
		}

		$sessionId = $this->request->getHeader('X-Session-ID');
		if ($sessionId === '') {
			$sessionId = session_id();
		}

		$searchTrail->setSession($sessionId);
	}//end setUserInformation()

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
	public function setExpiryDate(int $retentionMs): int {
		try {
			// Convert milliseconds to seconds for DateTime calculation.
			$retentionSeconds = intval($retentionMs / 1000);

			// Get the query builder.
			$qb = $this->db->getQueryBuilder();

			// DATE_ADD is MySQL/MariaDB only. Now that the hourly LogCleanUpTask
			// calls this, a MySQL-only expression would fail every hour on a
			// PostgreSQL install, so the interval is spelled per platform.
			$expiresExpression = sprintf('DATE_ADD(created, INTERVAL %d SECOND)', $retentionSeconds);
			if ($this->db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
				$expiresExpression = sprintf("created + INTERVAL '%d seconds'", $retentionSeconds);
			}

			// Update search trails that don't have an expiry date set.
			$qb->update($this->getTableName())
				->set('expires', $qb->createFunction($expiresExpression))
				->where($qb->expr()->isNull('expires'));

			// Execute the update and return number of affected rows.
			return $qb->executeStatement();
		} catch (\Exception $e) {
			// Log the error for debugging purposes.
			$this->logger->error(
				message: '[SearchTrailMapper] Failed to set expiry dates for search trails: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'app' => 'openregister',
					'exception' => $e,
				]
			);

			// Re-throw the exception so the caller knows something went wrong.
			throw $e;
		}//end try
	}//end setExpiryDate()

	/**
	 * Normalise a caller-supplied sort direction to 'ASC' or 'DESC'.
	 *
	 * NC 35 types IQueryBuilder::addOrderBy()'s direction as
	 * string|SortDirection|null, so a non-string value is a TypeError there.
	 * Anything that is not a case-insensitive 'DESC' sorts ascending.
	 *
	 * @param mixed $direction The requested direction
	 *
	 * @return string 'ASC' or 'DESC'
	 */
	private function normaliseSortDirection(mixed $direction): string {
		if (is_string($direction) === true && strtoupper($direction) === 'DESC') {
			return 'DESC';
		}

		return 'ASC';
	}//end normaliseSortDirection()
}//end class
