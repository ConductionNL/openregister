<?php

/**
 * Class SearchTrailController
 *
 * Controller for managing search trail operations and analytics in the OpenRegister app.
 * Provides functionality to retrieve search statistics, popular search terms, and search logs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
 */

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Controller\Support\ResultWindow;
use OCA\OpenRegister\Controller\Support\SearchTrailPageLinks;
use OCA\OpenRegister\Db\SearchTrail;
use OCA\OpenRegister\Db\SearchTrailMapperInterface;
use OCA\OpenRegister\Service\SearchTrailServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Controller\Support\SearchTrailControllerServices;

/**
 * Class SearchTrailController
 * Handles all search trail related operations and analytics
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class SearchTrailController extends Controller {
	/**
	 * Request keys that are pagination, search or sort, each in a plain and an underscore-prefixed form.
	 */
	private const PREFIXABLE_KEYS = ['limit', 'offset', 'page', 'search', 'sort', 'order'];

	/**
	 * Request keys that are dates or system fields.
	 */
	private const SYSTEM_KEYS = ['from', 'to', '_route', 'id'];

	/**
	 * Export columns and the getter of the search trail that fills each one.
	 */
	private const EXPORT_COLUMNS = [
		'id' => 'getId',
		'search_term' => 'getSearchTerm',
		'request_uri' => 'getRequestUri',
		'result_count' => 'getResultCount',
		'total_results' => 'getTotalResults',
		'response_time' => 'getResponseTime',
		'execution_type' => 'getExecutionType',
		'user_id' => 'getUserId',
		'user_agent' => 'getUserAgent',
		'ip_address' => 'getIpAddress',
		'session_id' => 'getSessionId',
		'created' => 'getCreated',
		'updated' => 'getUpdated',
	];

	/**
	 * Export columns added when metadata is requested.
	 */
	private const EXPORT_METADATA_COLUMNS = [
		'search_parameters' => 'getSearchParameters',
		'result_metadata' => 'getResultMetadata',
	];

	/**
	 * The search trail service
	 *
	 * @var SearchTrailServiceInterface
	 */
	private readonly SearchTrailServiceInterface $searchTrailService;

	/**
	 * Mapper the admin clear-all path deletes through
	 *
	 * @var SearchTrailMapperInterface
	 */
	private readonly SearchTrailMapperInterface $searchTrailMapper;

	/**
	 * Active user session for caller identity
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Group manager for admin / role checks
	 *
	 * @var IGroupManager
	 */
	private readonly IGroupManager $groupManager;

	/**
	 * Constructor for SearchTrailController
	 *
	 * @param string $appName The name of the app
	 * @param IRequest $request The request object
	 * @param SearchTrailControllerServices $services The services, bundled.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		SearchTrailControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->searchTrailService = $services->searchTrailService;
		$this->searchTrailMapper = $services->searchTrailMapper;
		$this->userSession = $services->userSession;
		$this->groupManager = $services->groupManager;
	}//end __construct()

	/**
	 * An error response.
	 *
	 * @param string               $message The error message.
	 * @param int                  $status  The HTTP status.
	 * @param array<string, mixed> $fields  Fields that precede the error in the body.
	 *
	 * @return JSONResponse The error response.
	 */
	private function failure(string $message, int $status = 500, array $fields = []): JSONResponse {
		$fields['error'] = $message;
		return new JSONResponse(data: $fields, statusCode: $status);
	}//end failure()

	/**
	 * Gate sensitive search-trail read operations on admin membership.
	 *
	 * SECURITY: search-trail rows record per-search IP address, user
	 * ID, user-agent and full query string for every search across
	 * every register/schema. Returning them to non-admin callers leaks
	 * PII (GDPR) and gives any authenticated user — including users
	 * restricted to a single app group — a recon view of what every
	 * other tenant is searching for (wave-3 C7). Surface stays
	 * admin-only at both the framework level (no `@NoAdminRequired`)
	 * and the body level (defence-in-depth).
	 *
	 * @return JSONResponse|null 401/403 response when blocked, null when allowed.
	 */
	private function requireAdmin(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->failure(message: 'Authentication required', status: 401);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return $this->failure(message: 'Forbidden: this search-trail operation is admin-only', status: 403);
		}

		return null;
	}//end requireAdmin()

	/**
	 * The response with the data a builder returns, or the error response when it throws.
	 *
	 * @param callable             $build    Builds the response data.
	 * @param string               $failure  The error message prefix, followed by the exception message.
	 * @param string|null          $notFound The 404 error message when the builder hits a missing row.
	 * @param array<string, mixed> $fields   Fields that precede the error in the error body.
	 *
	 * @return JSONResponse The response.
	 */
	private function respond(callable $build, string $failure, ?string $notFound = null, array $fields = []): JSONResponse {
		try {
			return new JSONResponse(data: $build());
		} catch (\Exception $e) {
			if ($notFound !== null && $e instanceof DoesNotExistException) {
				return $this->failure(message: $notFound, status: 404);
			}

			return $this->failure(message: $failure . $e->getMessage(), fields: $fields);
		}
	}//end respond()

	/**
	 * Extract pagination, filter, and search parameters from request
	 *
	 * @return array Request parameters including pagination and filters
	 *
	 * @spec exclude Private helper: parses pagination/filter/date params; the search-trail analytics API is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3.
	 */
	private function extractRequestParameters(): array {
		$params = $this->request->getParams();

		return $this->positionParams(params: $params) + [
			'filters' => $this->filterParams(params: $params),
			'sort' => $this->sortParams(params: $params),
			// Prioritize the underscore-prefixed search parameter.
			'search' => $params['_search'] ?? $params['search'] ?? null,
			// An invalid date is ignored.
			'from' => $this->dateParam(params: $params, name: 'from'),
			'to' => $this->dateParam(params: $params, name: 'to'),
		];
	}//end extractRequestParameters()

	/**
	 * The limit, offset and page of the request; a page without an offset sets the offset.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array{limit: int, offset: int|null, page: int|null} The position parameters.
	 */
	private function positionParams(array $params): array {
		// The plain name wins over the underscore-prefixed one.
		$limit = $this->intParam(params: $params, name: 'limit') ?? 20;
		$offset = $this->intParam(params: $params, name: 'offset');
		$page = $this->intParam(params: $params, name: 'page');
		if ($page !== null && $offset === null) {
			$offset = ($page - 1) * $limit;
		}

		return ['limit' => $limit, 'offset' => $offset, 'page' => $page];
	}//end positionParams()

	/**
	 * The sort of the request: newest first, plus the requested field (underscore-prefixed forms win).
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed> The sort, by field.
	 */
	private function sortParams(array $params): array {
		$sort = ['created' => 'DESC'];
		if (($params['_sort'] ?? null) !== null || (($params['sort'] ?? null) !== null) === true) {
			$sortField = $params['_sort'] ?? $params['sort'] ?? 'created';
			$sortOrder = $params['_order'] ?? $params['order'] ?? 'DESC';
			$sort[$sortField] = $sortOrder;
		}

		return $sort;
	}//end sortParams()

	/**
	 * The request parameters that are filters, without pagination, search, sort, date and system fields.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed> The filter parameters.
	 */
	private function filterParams(array $params): array {
		$reserved = self::SYSTEM_KEYS;
		foreach (self::PREFIXABLE_KEYS as $key) {
			$reserved[] = $key;
			$reserved[] = '_' . $key;
		}

		return array_filter(
			$params,
			static fn ($key): bool => !in_array($key, $reserved),
			ARRAY_FILTER_USE_KEY
		);
	}//end filterParams()

	/**
	 * Read an integer request parameter, preferring the plain name over its underscore-prefixed form.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string               $name   The parameter name without underscore.
	 *
	 * @return int|null The value, or null when neither form is set.
	 */
	private function intParam(array $params, string $name): ?int {
		if (($params[$name] ?? null) !== null) {
			return (int)$params[$name];
		}

		if (($params['_' . $name] ?? null) !== null) {
			return (int)$params['_' . $name];
		}

		return null;
	}//end intParam()

	/**
	 * Read a date request parameter.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 * @param string               $name   The parameter name.
	 *
	 * @return DateTime|null The date, or null when it is not set or not a valid date.
	 */
	private function dateParam(array $params, string $name): ?DateTime {
		try {
			return $this->optionalDate(value: $params[$name] ?? null);
		} catch (\Exception $e) {
			return null;
		}
	}//end dateParam()

	/**
	 * A date from a request value.
	 *
	 * @param mixed $value The request value.
	 *
	 * @return DateTime|null The date, or null when the value is null.
	 *
	 * @throws \Exception When the value is not a valid date.
	 */
	private function optionalDate(mixed $value): ?DateTime {
		if ($value === null) {
			return null;
		}

		return new DateTime($value);
	}//end optionalDate()

	/**
	 * The period of the request, as the from/to arguments of the search-trail service.
	 *
	 * @return array{from: DateTime|null, to: DateTime|null} The period.
	 */
	private function period(): array {
		$params = $this->extractRequestParameters();
		return ['from' => $params['from'], 'to' => $params['to']];
	}//end period()

	/**
	 * The request parameters of a statistics listing, with its page size under `statsLimit`.
	 *
	 * @param int $defaultLimit The page size when the request names none.
	 *
	 * @return array<string, mixed> The request parameters.
	 */
	private function statsQuery(int $defaultLimit): array {
		$query = $this->extractRequestParameters();
		// Prioritize underscore-prefixed limit parameter.
		$query['statsLimit'] = $this->request->getParam('_limit', $this->request->getParam('limit', $defaultLimit));
		return $query;
	}//end statsQuery()

	/**
	 * Private helper method to handle pagination of results.
	 *
	 * This method paginates the given results array based on the provided total, limit, offset, and page parameters.
	 * It calculates the number of pages, sets the appropriate offset and page values, and returns the paginated results
	 * along with metadata such as total items, current page, total pages, limit, and offset.
	 *
	 * @param array $results The array of objects to paginate.
	 * @param int|null $total The total number of items (before pagination). Defaults to 0.
	 * @param int|null $limit The number of items per page. Defaults to 20.
	 * @param int|null $offset The offset of items. Defaults to 0.
	 * @param int|null $page The current page number. Defaults to 1.
	 *
	 * @return (array|float|int|null|string)[]
	 *
	 * @phpstan-param array<int, mixed> $results
	 *
	 * @phpstan-return array<string, mixed>
	 *
	 * @psalm-param array<int, mixed> $results
	 *
	 * @psalm-return array{
	 *     results: array<int, mixed>,
	 *     total: int<0, max>,
	 *     page: float|int<1, max>,
	 *     pages: 1|float,
	 *     limit: int<1, max>,
	 *     offset: int<0, max>,
	 *     next?: null|string,
	 *     prev?: null|string
	 * }
	 *
	 * @spec exclude Private helper: shared pagination-envelope builder; the search-trail analytics API is owned by
	 *              retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3.
	 */
	private function paginate(array $results, ?int $total = 0, ?int $limit = 20, ?int $offset = 0, ?int $page = 1): array {
		$window = new ResultWindow(total: $total, limit: $limit, offset: $offset, page: $page);
		$envelope = $window->over(results: $results);
		$links = new SearchTrailPageLinks(currentUrl: $this->request->getRequestUri());

		return $envelope + $links->around(page: $envelope['page'], pages: $envelope['pages']);
	}//end paginate()

	/**
	 * A statistics list paged with the page size and position of its query.
	 *
	 * @param array<int, mixed>    $items The statistics list.
	 * @param mixed                $total The total number of entries.
	 * @param array<string, mixed> $query The statistics query.
	 *
	 * @return array<string, mixed> The paging envelope.
	 */
	private function statsPage(array $items, mixed $total, array $query): array {
		return $this->paginate(
			results: $items,
			total: $total,
			limit: $query['statsLimit'],
			offset: $query['offset'] ?? 0,
			page: $query['page'] ?? 1
		);
	}//end statsPage()

	/**
	 * A paged statistics list with the search count and period of the service result.
	 *
	 * @param array<string, mixed> $envelope      The paging envelope.
	 * @param mixed                $totalSearches The number of searches.
	 * @param mixed                $period        The period of the statistics.
	 *
	 * @return array<string, mixed> The envelope with total_searches and period.
	 */
	private function withSearchMeta(array $envelope, mixed $totalSearches, mixed $period): array {
		$envelope['total_searches'] = $totalSearches;
		$envelope['period'] = $period;
		return $envelope;
	}//end withSearchMeta()

	/**
	 * Get all search trail logs
	 *
	 * Admin-only at the framework level (no @NoAdminRequired). Body
	 * `requireAdmin()` stays as defence-in-depth — wave-3 C7.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with search trail logs
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function index(): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		return $this->respond(build: fn (): array => $this->indexPage(), failure: 'Failed to retrieve search trails: ');
	}//end index()

	/**
	 * The search trails the service pages for the raw request parameters, in the ObjectsController envelope.
	 *
	 * @return array<string, mixed> The paging envelope.
	 */
	private function indexPage(): array {
		// The raw request parameters are what the service expects, without the system parameters.
		$rawParams = $this->request->getParams();
		unset($rawParams['_route'], $rawParams['id']);
		$serviceResult = $this->searchTrailService->getSearchTrails($rawParams);

		return $this->paginate(
			results: $serviceResult['results'] ?? [],
			total: $serviceResult['total'] ?? 0,
			limit: $serviceResult['limit'] ?? 20,
			offset: $serviceResult['offset'] ?? 0,
			page: (int)($serviceResult['page'] ?? 1)
		);
	}//end indexPage()

	/**
	 * Get a specific search trail log by ID
	 *
	 * Admin-only at the framework level (no @NoAdminRequired). Body
	 * `requireAdmin()` stays as defence-in-depth — wave-3 C7. IDs
	 * are sequential so without the gate any authed caller could
	 * enumerate every tenant's recorded searches (IP, user ID,
	 * user-agent, query string).
	 *
	 * @param int $id The search trail ID
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with search trail data
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function show(int $id): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		return $this->respond(
			build: fn (): SearchTrail => $this->searchTrailService->getSearchTrail($id),
			failure: 'Failed to retrieve search trail: ',
			notFound: 'Search trail not found'
		);
	}//end show()

	/**
	 * Get search statistics for a given period
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with search statistics
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function statistics(): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		return $this->respond(
			build: fn (): array => $this->searchTrailService->getSearchStatistics(...$this->period()),
			failure: 'Failed to get search statistics: '
		);
	}//end statistics()

	/**
	 * Get popular search terms
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with popular search terms
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function popularTerms(): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		return $this->respond(
			build: fn (): array => $this->popularTermsPage(query: $this->statsQuery(defaultLimit: 10)),
			failure: 'Failed to get popular search terms: '
		);
	}//end popularTerms()

	/**
	 * The popular search terms, paged, with the search count and period.
	 *
	 * @param array<string, mixed> $query The statistics query.
	 *
	 * @return array<string, mixed> The paged terms.
	 */
	private function popularTermsPage(array $query): array {
		$serviceResult = $this->searchTrailService->getPopularSearchTerms(
			limit: (int)$query['statsLimit'],
			from: $query['from'],
			to: $query['to']
		);

		return $this->withSearchMeta(
			envelope: $this->statsPage(items: $serviceResult['terms'] ?? [], total: $serviceResult['total_unique_terms'] ?? 0, query: $query),
			totalSearches: $serviceResult['total_searches'] ?? 0,
			period: $serviceResult['period'] ?? null
		);
	}//end popularTermsPage()

	/**
	 * Get search activity by time period
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with search activity data
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function activity(): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		return $this->respond(build: fn (): array => $this->activityResult(), failure: 'Failed to get search activity: ');
	}//end activity()

	/**
	 * The search activity of the requested period, by the requested interval.
	 *
	 * @return array<string, mixed> The search activity.
	 */
	private function activityResult(): array {
		$period = $this->period();
		$interval = $this->request->getParam(key: 'interval', default: 'day');

		return $this->searchTrailService->getSearchActivity(interval: $interval, from: $period['from'], to: $period['to']);
	}//end activityResult()

	/**
	 * Get search statistics by register and schema
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with register schema statistics
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function registerSchemaStats(): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		return $this->respond(
			build: fn (): array => $this->registerSchemaPage(query: $this->statsQuery(defaultLimit: 20)),
			failure: 'Failed to get register/schema statistics: '
		);
	}//end registerSchemaStats()

	/**
	 * The register/schema statistics, paged, with the search count and period.
	 *
	 * @param array<string, mixed> $query The statistics query.
	 *
	 * @return array<string, mixed> The paged statistics.
	 */
	private function registerSchemaPage(array $query): array {
		$serviceResult = $this->searchTrailService->getRegisterSchemaStatistics(from: $query['from'], to: $query['to']);

		return $this->withSearchMeta(
			envelope: $this->statsPage(items: $serviceResult['statistics'] ?? [], total: $serviceResult['total_combinations'] ?? 0, query: $query),
			totalSearches: $serviceResult['total_searches'] ?? 0,
			period: $serviceResult['period'] ?? null
		);
	}//end registerSchemaPage()

	/**
	 * Get user agent statistics
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with user agent statistics
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function userAgentStats(): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		return $this->respond(
			build: fn (): array => $this->userAgentPage(query: $this->statsQuery(defaultLimit: 10)),
			failure: 'Failed to get user agent statistics: '
		);
	}//end userAgentStats()

	/**
	 * The user agents, paged; a structured service result also carries its period and browser breakdown.
	 *
	 * @param array<string, mixed> $query The statistics query.
	 *
	 * @return array<string, mixed> The paged user agents.
	 */
	private function userAgentPage(array $query): array {
		$serviceResult = $this->searchTrailService->getUserAgentStatistics(
			limit: (int)$query['statsLimit'],
			from: $query['from'],
			to: $query['to']
		);
		if (($serviceResult['user_agents'] ?? null) !== null) {
			return $this->structuredUserAgentPage(serviceResult: $serviceResult, query: $query);
		}

		// A simple array is the user agents list itself, possibly associative.
		$userAgents = array_values($serviceResult);
		return $this->statsPage(items: $userAgents, total: count($userAgents), query: $query);
	}//end userAgentPage()

	/**
	 * The user agents of a structured service result (user_agents, browser_distribution, total_user_agents, period).
	 *
	 * @param array<string, mixed> $serviceResult The service result.
	 * @param array<string, mixed> $query         The statistics query.
	 *
	 * @return array<string, mixed> The paged user agents with period and, when known, the browser breakdown.
	 */
	private function structuredUserAgentPage(array $serviceResult, array $query): array {
		$userAgents = [];
		if (is_array($serviceResult['user_agents']) === true) {
			$userAgents = array_values($serviceResult['user_agents']);
		}

		// The service does not return a search count.
		$envelope = $this->withSearchMeta(
			envelope: $this->statsPage(items: $userAgents, total: $serviceResult['total_user_agents'] ?? 0, query: $query),
			totalSearches: 0,
			period: $serviceResult['period'] ?? null
		);

		return $this->withBrowserBreakdown(envelope: $envelope, browserStats: $serviceResult['browser_distribution'] ?? null);
	}//end structuredUserAgentPage()

	/**
	 * The paged user agents with the browser breakdown, when the service returned a non-empty one.
	 *
	 * @param array<string, mixed> $envelope     The paged user agents.
	 * @param mixed                $browserStats The browser distribution of the service result.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function withBrowserBreakdown(array $envelope, mixed $browserStats): array {
		if ($browserStats !== null && empty($browserStats) === false) {
			$envelope['browser_breakdown'] = $browserStats;
		}

		return $envelope;
	}//end withBrowserBreakdown()

	/**
	 * Clean up old search trail logs
	 *
	 * @return JSONResponse JSON response containing cleanup operation results
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<
	 *     200|400|500,
	 *     array{
	 *         error?: string,
	 *         success?: bool,
	 *         deleted?: 0|1,
	 *         message?: 'Cleanup operation failed'|'No expired entries to delete'
	 *             |'Successfully deleted expired search trail entries',
	 *         cleanup_date?: string
	 *     },
	 *     array<never, never>
	 * >
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function cleanup(): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		try {
			$beforeDate = $this->optionalDate(value: $this->request->getParam(key: 'before'));
		} catch (\Exception $e) {
			return $this->failure(message: 'Invalid date format for before parameter', status: 400);
		}

		return $this->respond(build: fn (): array => $this->searchTrailService->cleanupSearchTrails($beforeDate), failure: 'Cleanup failed: ');
	}//end cleanup()

	/**
	 * Export search trail logs in specified format
	 *
	 * Admin-only at the framework level (no @NoAdminRequired): search-trail
	 * rows carry per-search IP, user id, user-agent and query string across
	 * every register/schema (cross-tenant PII, wave-3 C7), like the sibling
	 * analytics/destructive endpoints. Body `requireAdmin()` is defence-in-depth.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with export data
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function export(): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		return $this->respond(build: fn (): array => $this->exportPayload(), failure: 'Export failed: ');
	}//end export()

	/**
	 * The export of the filtered search trails in the requested format (CSV by default).
	 *
	 * @return array<string, mixed> The success flag and the export file.
	 */
	private function exportPayload(): array {
		$params = $this->extractRequestParameters();
		$format = $this->request->getParam(key: 'format', default: 'csv');
		$includeMetadata = $this->request->getParam(key: 'includeMetadata', default: false);
		$includeMetadata = filter_var($includeMetadata, FILTER_VALIDATE_BOOLEAN);

		$searchTrails = $this->searchTrailService->getSearchTrails(config: $this->exportQuery(params: $params));
		$exportData = $this->exportRows(trails: $searchTrails['results'], includeMetadata: $includeMetadata);

		return ['success' => true, 'data' => $this->exportFile(exportData: $exportData, format: $format)];
	}//end exportPayload()

	/**
	 * The search-trail query of an export: the filters, search and period of the request, unpaged.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return array<string, mixed> The service configuration.
	 */
	private function exportQuery(array $params): array {
		return [
			'filters' => $params['filters'],
			'search' => $params['search'],
			'from' => $params['from'],
			'to' => $params['to'],
			'limit' => null,
			'offset' => null,
		];
	}//end exportQuery()

	/**
	 * The export rows of the search trails.
	 *
	 * @param mixed $trails          The search trails.
	 * @param bool  $includeMetadata Whether to add the search parameters and result metadata.
	 *
	 * @return array<int, array<string, mixed>> The export rows.
	 */
	private function exportRows(mixed $trails, bool $includeMetadata): array {
		$columns = self::EXPORT_COLUMNS;
		if ($includeMetadata === true) {
			$columns += self::EXPORT_METADATA_COLUMNS;
		}

		$exportData = [];
		foreach ($trails as $trail) {
			$row = [];
			foreach ($columns as $column => $getter) {
				$row[$column] = $trail->$getter();
			}

			$exportData[] = $row;
		}

		return $exportData;
	}//end exportRows()

	/**
	 * The export file of the rows: content, filename, content type and size.
	 *
	 * @param array<int, array<string, mixed>> $exportData The export rows.
	 * @param mixed                            $format     The requested format; `json`, otherwise CSV.
	 *
	 * @return array<string, mixed> The export file.
	 */
	private function exportFile(array $exportData, mixed $format): array {
		$export = $this->exportContent(exportData: $exportData, format: $format);

		return [
			'content' => $export['content'],
			'filename' => 'search-trails-' . date('Y-m-d-H-i-s') . '.' . $export['extension'],
			'contentType' => $export['contentType'],
			'size' => strlen($export['content']),
		];
	}//end exportFile()

	/**
	 * The export content in the requested format, with its content type and filename extension.
	 *
	 * The CSV is built for either format, as it always was.
	 *
	 * @param array<int, array<string, mixed>> $exportData The export rows.
	 * @param mixed                            $format     The requested format; `json`, otherwise CSV.
	 *
	 * @return array{content: string|false, contentType: string, extension: string} The export content.
	 */
	private function exportContent(array $exportData, mixed $format): array {
		$csv = $this->arrayToCsv(data: $exportData);
		if ($format === 'json') {
			return ['content' => json_encode($exportData, JSON_PRETTY_PRINT), 'contentType' => 'application/json', 'extension' => 'json'];
		}

		return ['content' => $csv, 'contentType' => 'text/csv', 'extension' => 'csv'];
	}//end exportContent()

	/**
	 * Delete a single search trail log
	 *
	 * @param int $id The search trail ID to delete
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with deletion result
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function destroy(int $id): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		return $this->respond(
			build: fn (): array => $this->deletionOutcome(id: $id),
			failure: 'Deletion failed: ',
			notFound: 'Search trail not found'
		);
	}//end destroy()

	/**
	 * Validate that the search trail exists; deletion itself is not implemented in the service yet.
	 *
	 * @param int $id The search trail ID.
	 *
	 * @return array<string, mixed> The deletion outcome.
	 *
	 * @throws DoesNotExistException When the search trail does not exist.
	 */
	private function deletionOutcome(int $id): array {
		$this->searchTrailService->getSearchTrail($id);

		// In a real implementation, you'd add a deleteSearchTrail method to the service.
		return ['success' => true, 'message' => 'Search trail deletion not implemented yet'];
	}//end deletionOutcome()

	/**
	 * Delete multiple search trail logs based on filters or specific IDs
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with multiple deletion result
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	public function destroyMultiple(): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		// TODO: Implement multiple search trail deletion.
		// $ids = $this->request->getParam(key: 'ids', default: null);
		// In a real implementation, you'd add a deleteMultipleSearchTrails method to the service.
		$message = 'Multiple search trail deletion not implemented yet';
		return $this->respond(
			build: fn (): array => ['success' => true, 'results' => ['deleted' => 0, 'failed' => 0, 'message' => $message], 'message' => $message],
			failure: 'Mass deletion failed: '
		);
	}//end destroyMultiple()

	/**
	 * Convert array to CSV format
	 *
	 * @param array $data The data to convert
	 *
	 * @return string The CSV formatted string
	 */
	private function arrayToCsv(array $data): string {
		if (empty($data) === true) {
			return '';
		}

		$output = fopen('php://temp', 'r+');
		// Add headers, then the data rows.
		fputcsv($output, array_keys($data[0]));
		foreach ($data as $row) {
			fputcsv($output, array_map(static fn ($cell) => self::csvCell(cell: $cell), $row));
		}

		rewind($output);
		$csv = stream_get_contents($output);
		fclose($output);

		return $csv;
	}//end arrayToCsv()

	/**
	 * A CSV cell, with an array or object flattened to a JSON string.
	 *
	 * Keeps fputcsv from emitting an "Array to string conversion" warning
	 * (and writing the literal "Array") for nested values.
	 *
	 * @param mixed $cell The cell value.
	 *
	 * @return mixed The cell value to write.
	 */
	private static function csvCell(mixed $cell): mixed {
		if (is_array($cell) === true || is_object($cell) === true) {
			return json_encode($cell);
		}

		return $cell;
	}//end csvCell()

	/**
	 * Clear all search trail logs
	 *
	 * @return JSONResponse A JSON response indicating success or failure
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-1/tasks.md#task-3
	 */
	public function clearAll(): JSONResponse {
		$denial = $this->requireAdmin();
		if ($denial !== null) {
			return $denial;
		}

		return $this->respond(
			build: fn (): array => $this->clearAllOutcome(cleared: $this->searchTrailMapper->clearAllLogs() === true),
			failure: 'Failed to clear search trails: ',
			fields: ['success' => false]
		);
	}//end clearAll()

	/**
	 * The clear-all outcome.
	 *
	 * @param bool $cleared Whether the mapper cleared any search trails.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function clearAllOutcome(bool $cleared): array {
		if ($cleared === true) {
			return [
				'success' => true,
				'message' => 'All search trails cleared successfully',
				'deleted' => 'All expired search trails have been deleted',
			];
		}

		return ['success' => true, 'message' => 'No expired search trails found to clear', 'deleted' => 0];
	}//end clearAllOutcome()
}//end class
