<?php

/**
 * SearchQueryHandler - Search and Query Operations Handler
 *
 * Handles all search query building, execution, and pagination operations.
 * This handler separates search-related business logic from the main ObjectService,
 * improving code organization and maintainability.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 * @spec openspec/specs/zoeken-filteren/spec.md
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * SearchQueryHandler class
 *
 * Handles search query operations including:
 * - Query building and parameter normalization
 * - View-based filtering
 * - Search execution (sync/async/database)
 * - Pagination URL generation
 * - Search trail logging
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex search query building and optimization logic
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SearchQueryHandler {

	/**
	 * Memoized effective search-trail recording mode for this request.
	 *
	 * The recording mode is read from settings once per request instead of
	 * twice per search (getEffectiveRecordingMode() + the enabled check in
	 * logSearchTrail()). Null until the first read. Long-running processes
	 * (CLI) keep the first value for their lifetime, which is acceptable
	 * for a best-effort analytics trail.
	 *
	 * @var string|null
	 */
	private ?string $recordingModeMemo = null;

	/**
	 * In-request buffer of search-trail entries pending persistence.
	 *
	 * Entries accumulate during the request and are flushed after the
	 * response by a shutdown function (mirrors ProcessingLogService's
	 * buffered emission), keeping the INSERT off the hot search path.
	 * The trail is best-effort: losing buffered rows on a fatal is
	 * acceptable.
	 *
	 * @var array<int, array{query: array, resultCount: int, totalResults: int, responseTime: float, executionType: string}>
	 */
	private array $searchTrailBuffer = [];

	/**
	 * Whether the shutdown flush for the trail buffer is registered.
	 *
	 * @var boolean
	 */
	private bool $trailFlushRegistered = false;

	/**
	 * SearchQueryHandler constructor.
	 *
	 * @param ViewMapper $viewMapper Mapper for view operations.
	 * @param SchemaMapper $schemaMapper Mapper for schema operations.
	 * @param SettingsService $settingsService Service for settings operations.
	 * @param LoggerInterface $logger Logger for performance monitoring.
	 * @param IRequest $request Request object.
	 * @param SearchTrailService $searchTrailService Service for recording search trails.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		private readonly ViewMapper $viewMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly IRequest $request,
		private readonly SearchTrailService $searchTrailService,
	) {
	}//end __construct()

	/**
	 * Whether the target schema is served by an external object-source (DBAL
	 * virtual register), whose columns are flat snake_case and must not be run
	 * through the dot-un-mangling in {@see buildSearchQuery()}.
	 *
	 * Resolves the schema id/slug via the mapper (request-cached). A
	 * multi-schema (array) or absent schema, or any resolution failure, yields
	 * false so the legacy magic-table behaviour is preserved.
	 *
	 * This is a SYSTEM-level structural lookup (does the schema declare an
	 * object-source?), used only to decide how query params are parsed — it
	 * returns no schema data to the caller, so it MUST bypass RBAC and
	 * multitenancy. Otherwise, when saasMode is active and the schema lives in
	 * a different organisation than the caller's active org, the tenant-scoped
	 * find() cannot see it, this returns false, and snake_case column filters
	 * (e.g. `app_id`) are wrongly dot-un-mangled — silently emptying every
	 * per-object filter on a cross-org DBAL register. The actual data read is
	 * still RBAC/tenant-checked downstream in paginateObjectSource() (mirrors
	 * the object-source Source lookup, openregister#2089).
	 *
	 * @param int|string|array|null $schema The schema id/slug (single value only).
	 *
	 * @return bool True when the schema declares an x-openregister-object-source.
	 */
	private function schemaHasObjectSource(int|string|array|null $schema): bool {
		if ($schema === null || is_array($schema) === true) {
			return false;
		}

		try {
			$entity = $this->schemaMapper->find(id: $schema, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return false;
		}

		return ($entity->getObjectSource() !== null);
	}//end schemaHasObjectSource()

	/**
	 * Build search query from request parameters
	 *
	 * Converts HTTP request parameters into a structured query array for searchObjectsPaginated.
	 * Handles PHP's dot-to-underscore parameter name conversion, extracts metadata filters,
	 * and separates object field filters from system parameters.
	 *
	 * @param array $requestParams Request parameters from HTTP request.
	 * @param int|string|array|null $register Optional register ID(s) to filter by.
	 * @param int|string|array|null $schema Optional schema ID(s) to filter by.
	 * @param array|null $ids Optional array of object IDs to filter by.
	 *
	 * @return ((int[]|mixed)[]|mixed)[]
	 *
	 * @psalm-return array{'@self': array<string, array<int>|int|mixed>|mixed, _ids?: array|mixed,...}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex query building with parameter reconstruction
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Many paths for handling different parameter formats
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Handles extensive parameter processing for query building
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function buildSearchQuery(
		array $requestParams,
		int|string|array|null $register = null,
		int|string|array|null $schema = null,
		?array $ids = null,
	): array {
		// A schema served from an external object-source (DBAL virtual register)
		// has FLAT, snake_case columns — `product_line`, `app_slug`,
		// `competitor_id`. The dot-un-mangling in STEP 1 (which rebuilds
		// `@self.register` from PHP's mangled `@self_register`) would wrongly
		// split those column names into nested arrays
		// (`product_line` → ['product' => ['line' => …]]), silently dropping the
		// filter. Detect object-source schemas so their snake_case object-field
		// keys stay literal; `@self.*` metadata keys still un-mangle. Magic-table
		// schemas are unaffected.
		$isObjectSourceSchema = $this->schemaHasObjectSource(schema: $schema);

		// STEP 1: Fix PHP's dot-to-underscore mangling in query parameter names.
		// PHP converts dots to underscores in parameter names, e.g.:.
		// @self.register → @self_register.
		// Person.address.street → person_address_street.
		// We need to reconstruct nested arrays from underscore-separated paths.
		$fixedParams = [];
		foreach ($requestParams as $key => $value) {
			// Skip parameters that start with underscore (system parameters like _limit, _offset).
			if (str_starts_with(haystack: $key, needle: '_') === true) {
				$fixedParams[$key] = $value;
				continue;
			}

			// Check if key contains underscores (indicating PHP mangled dots).
			// For object-source schemas, only un-mangle `@…` metadata keys and
			// keep snake_case object-field keys (real DBAL columns) literal.
			if (str_contains($key, '_') === true
				&& ($isObjectSourceSchema === false || str_starts_with(haystack: $key, needle: '@') === true)
			) {
				// Split by underscore to reconstruct nested structure.
				$parts = explode('_', $key);

				// Build nested array structure.
				$current = &$fixedParams;
				$lastIndex = count($parts) - 1;

				foreach ($parts as $index => $part) {
					if ($index === $lastIndex) {
						// Last part: assign the value.
						$current[$part] = $value;
						continue;
					}

					// Intermediate part: create nested array if needed.
					if (isset($current[$part]) === false) {
						$current[$part] = [];
					}

					if (isset($current[$part]) === true) {
						/*
						 * Ensure it's an array, reset if not.
						 * @psalm-suppress TypeDoesNotContainType - $current[$part] may have been set to non-array earlier
						 */

						if (is_array($current[$part]) === false) {
							$current[$part] = [];
						}
					}

					$current = &$current[$part];
				}//end foreach

				continue;
			}//end if

			// No underscores: use as-is.
			$fixedParams[$key] = $value;
		}//end foreach

		// STEP 2: Remove system parameters that shouldn't be used as filters.
		$params = $fixedParams;
		unset(
			$params['id'],
			$params['_route'],
			$params['rbac'],
			$params['multi'],
			$params['deleted']
		);

		// Build the query structure for searchObjectsPaginated.
		$query = [];

		// Extract metadata filters into @self.
		$metadataFields = [
			'register',
			'schema',
			'uuid',
			'organisation',
			'owner',
			'application',
			'created',
			'updated',
			'deleted',
		];
		$query['@self'] = [];

		// Add register and schema to @self if provided.
		// Support both single values and arrays for multi-register/schema filtering.
		if ($register !== null) {
			/*
			 * @var int|string|array $registerValue
			 */

			$registerValue = $register;
			$query['@self']['register'] = (int)$registerValue;
			if (is_array($registerValue) === true) {
				// Convert array values to integers.
				$query['@self']['register'] = array_map('intval', $registerValue);
			}
		}

		if ($schema !== null) {
			/*
			 * @var int|string|array $schemaValue
			 */

			$schemaValue = $schema;
			$query['@self']['schema'] = (int)$schemaValue;
			if (is_array($schemaValue) === true) {
				// Convert array values to integers.
				$query['@self']['schema'] = array_map('intval', $schemaValue);
			}
		}

		// Query structure built successfully.
		// Extract special underscore parameters.
		$specialParams = [];
		$objectFilters = [];

		foreach ($params as $key => $value) {
			if (str_starts_with(haystack: $key, needle: '_') === true) {
				$specialParams[$key] = $value;
			} elseif (in_array(needle: $key, haystack: $metadataFields) === true) {
				// Only add to @self if not already set from function parameters.
				if (isset($query['@self'][$key]) === false) {
					$query['@self'][$key] = $value;
				}

				continue;
			}

			// This is an object field filter.
			$objectFilters[$key] = $value;
		}

		// Add object field filters directly to query.
		$query = array_merge($query, $objectFilters);

		// Add IDs if provided.
		if ($ids !== null) {
			$query['_ids'] = $ids;
		}

		// Support both 'ids' and '_ids' parameters for flexibility.
		if (isset($specialParams['ids']) === true) {
			$query['_ids'] = $specialParams['ids'];
			// Remove to avoid duplication.
			unset($specialParams['ids']);
		}

		// Add all special parameters (they'll be handled by searchObjectsPaginated).
		$query = array_merge($query, $specialParams);

		// Normalize _ids from comma-separated string to array.
		// URL query parameters like _ids=uuid1,uuid2 arrive as a single string,
		// but downstream code (MagicMapper) expects an array.
		if (isset($query['_ids']) === true && is_string($query['_ids']) === true) {
			$query['_ids'] = array_filter(array_map('trim', explode(',', $query['_ids'])));
		}

		return $query;
	}//end buildSearchQuery()

	/**
	 * Apply view filters to a query
	 *
	 * Converts view definitions into query parameters by merging view->query into the base query.
	 * Supports multiple views - their filters are combined (OR logic for same field, AND for different fields).
	 *
	 * @param array<string, mixed> $query Base query parameters.
	 * @param array<int|string> $viewIds View IDs to apply (can be int or string IDs).
	 *
	 * @return array<string, mixed> Query with view filters applied
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex view merging with multiple filter types
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple view filter paths for registers, schemas, and search terms
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function applyViewsToQuery(array $query, array $viewIds): array {
		if (empty($viewIds) === true) {
			return $query;
		}

		$this->logger->debug(
			message: '[SearchQueryHandler] Applying views to query',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'viewIds' => $viewIds,
				'originalQuery' => array_keys($query),
			]
		);

		foreach ($viewIds as $viewId) {
			try {
				$view = $this->viewMapper->find($viewId);
				$viewQuery = $view->getQuery();

				// Apply registers filter using @self metadata (format MagicMapper understands).
				if (empty($viewQuery['registers']) === false) {
					if (isset($query['@self']) === false) {
						$query['@self'] = [];
					}

					$registerValue = $query['@self']['register'] ?? null;
					$registerArray = [];
					if (is_array($registerValue) === true) {
						$registerArray = $registerValue;
					} elseif ($registerValue !== null && $registerValue !== false) {
						$registerArray = [$registerValue];
					}

					$query['@self']['register'] = array_unique(
						array_merge(
							$registerArray,
							$viewQuery['registers']
						)
					);
				}//end if

				// Apply schemas filter using @self metadata (format MagicMapper understands).
				if (empty($viewQuery['schemas']) === false) {
					if (isset($query['@self']) === false) {
						$query['@self'] = [];
					}

					$schemaValue = $query['@self']['schema'] ?? null;
					$schemaArray = [];
					if (is_array($schemaValue) === true) {
						$schemaArray = $schemaValue;
					} elseif ($schemaValue !== null && $schemaValue !== false) {
						$schemaArray = [$schemaValue];
					}

					$query['@self']['schema'] = array_unique(
						array_merge(
							$schemaArray,
							$viewQuery['schemas']
						)
					);
				}//end if

				// Apply search terms.
				if (empty($viewQuery['searchTerms']) === false) {
					$searchTerms = $viewQuery['searchTerms'];
					if (is_array($viewQuery['searchTerms']) === true) {
						$searchTerms = implode(' ', $viewQuery['searchTerms']);
					}

					// Merge with the caller's existing search if there is one,
					// otherwise take the view's terms as-is.
					//
					// This used to assign $query['_search'] = $searchTerms BEFORE
					// the isset()/empty() test, which then always passed and
					// appended the same terms a second time — a view search for
					// "foo" was sent to the backend as "foo foo".
					if (empty($query['_search']) === false) {
						$query['_search'] .= ' ' . $searchTerms;
					} else {
						$query['_search'] = $searchTerms;
					}
				}//end if

				$this->logger->debug(
					message: '[SearchQueryHandler] Applied view to query',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'viewId' => $viewId,
						'registers' => $viewQuery['registers'] ?? [],
						'schemas' => $viewQuery['schemas'] ?? [],
						'hasSearchTerms' => empty($viewQuery['searchTerms']) === false,
					]
				);
			} catch (Exception $e) {
				$this->logger->warning(
					message: '[SearchQueryHandler] Failed to apply view',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'viewId' => $viewId,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $query;
	}//end applyViewsToQuery()

	/**
	 * Clean and normalize query parameters
	 *
	 * Converts legacy query parameter formats to the standard format used by MagicMapper.
	 * Handles ordering, operator suffixes (_in, _gt, _lt, etc.), and normalizes parameter names.
	 *
	 * @param array<string, mixed> $parameters Query parameters to clean.
	 *
	 * @return array<string, mixed> Cleaned query parameters
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple conditional paths for parameter normalization
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function cleanQuery(array $parameters): array {
		$newParameters = [];

		// 1. Handle ordering.
		if (isset($parameters['ordering']) === true) {
			$ordering = $parameters['ordering'];
			$direction = 'ASC';
			if (str_starts_with($ordering, '-') === true) {
				$direction = 'DESC';
			}

			$field = ltrim($ordering, '-');
			$newParameters['_order'] = [$field => $direction];
			unset($parameters['ordering']);
		}

		// 2. Normalize keys: replace '__' with '_'.
		$normalized = [];
		foreach ($parameters as $key => $value) {
			$normalized[str_replace('__', '_', $key)] = $value;
		}

		// 3. Process parameters (no nested loops).
		foreach ($normalized as $key => $value) {
			if (preg_match('/^(.*)_(in|gt|lt|gte|lte|isnull)$/', $key, $matches) === 1) {
				// Suppress unused variable warning for $matches[0] (full match).
				unset($matches[0]);
				[$base, $suffix] = array_values($matches);

				switch ($suffix) {
					case 'in':
					case 'gt':
					case 'lt':
					case 'gte':
					case 'lte':
						$newParameters[$base][$suffix] = $value;
						break;

					case 'isnull':
						$newParameters[$base] = 'IS NOT NULL';
						if ($value === true) {
							$newParameters[$base] = 'IS NULL';
						}
						break;
				}//end switch

				continue;
			}//end if

			$newParameters[$key] = $value;
		}//end foreach

		return $newParameters;
	}//end cleanQuery()

	/**
	 * Add pagination URLs to search results
	 *
	 * Generates next and previous page URLs based on current page and total pages.
	 * Only adds URLs when pagination is needed (pages > 1).
	 *
	 * @param array<string, mixed> $paginatedResults Search results array (passed by reference).
	 * @param int $page Current page number.
	 * @param int $pages Total number of pages.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function addPaginationUrls(array &$paginatedResults, int $page, int $pages): void {
		// **PERFORMANCE OPTIMIZATION**: Only generate URLs if pagination is needed.
		if ($pages <= 1) {
			return;
		}

		$currentUrl = $this->request->getRequestUri();

		// Add next page link if there are more pages.
		if ($page < $pages) {
			$nextPage = ($page + 1);
			$nextUrl = preg_replace('/([?&])page=\d+/', '$1page=' . $nextPage, $currentUrl);
			if (strpos($nextUrl, 'page=') === false) {
				$nextUrl .= $this->getUrlSeparator(url: $nextUrl) . 'page=' . $nextPage;
			}

			$paginatedResults['next'] = $nextUrl;
		}

		// Add previous page link if not on first page.
		if ($page > 1) {
			$prevPage = ($page - 1);
			$prevUrl = preg_replace('/([?&])page=\d+/', '$1page=' . $prevPage, $currentUrl);
			if (strpos($prevUrl, 'page=') === false) {
				$prevUrl .= $this->getUrlSeparator(url: $prevUrl) . 'page=' . $prevPage;
			}

			$paginatedResults['prev'] = $prevUrl;
		}
	}//end addPaginationUrls()

	/**
	 * Get URL separator character (? or &)
	 *
	 * Determines whether to use '?' or '&' when adding query parameters to a URL.
	 *
	 * @param string $url URL to check.
	 *
	 * @return string '?' if URL has no query string, '&' otherwise
	 *
	 * @psalm-return '&'|'?'
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function getUrlSeparator(string $url): string {
		if (strpos($url, '?') === false) {
			return '?';
		}

		return '&';
	}//end getUrlSeparator()

	/**
	 * Buffer a search trail entry for deferred persistence
	 *
	 * Records a search trail entry if search trails are enabled in settings.
	 * The entry is buffered in-request and persisted after the response by a
	 * shutdown function (see flushSearchTrails()), so the trail INSERT never
	 * adds latency to the search itself. The trail is best-effort by
	 * contract: buffered rows lost on a fatal are acceptable.
	 *
	 * @param array<string, mixed> $_query Search query array.
	 * @param int $_resultCount Number of results returned.
	 * @param int $_totalResults Total number of matching results.
	 * @param float $_executionTime Execution time in milliseconds.
	 * @param string $_executionType Type of execution (sync, async, optimized, etc.).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/search-trail-recording/spec.md
	 */
	public function logSearchTrail(
		array $_query,
		int $_resultCount,
		int $_totalResults,
		float $_executionTime,
		string $_executionType = 'sync',
	): void {
		// Only record when trails are enabled. Reuses the memoized recording
		// mode so the settings are read at most once per request instead of a
		// second time here.
		if ($this->getEffectiveRecordingMode() === 'none') {
			return;
		}

		$this->searchTrailBuffer[] = [
			'query' => $_query,
			'resultCount' => $_resultCount,
			'totalResults' => $_totalResults,
			'responseTime' => $_executionTime,
			'executionType' => $_executionType,
		];

		// Register the deferred flush once per request; it runs after the
		// response has been generated so the write cost is off the hot path.
		if ($this->trailFlushRegistered === false) {
			$this->trailFlushRegistered = true;
			register_shutdown_function([$this, 'flushSearchTrails']);
		}
	}//end logSearchTrail()

	/**
	 * Flush buffered search-trail entries to storage
	 *
	 * Runs as a shutdown function after the response, and may be called
	 * directly (tests, CLI) to force persistence. Fail-soft per entry: a
	 * failed insert is logged and dropped — the trail is best-effort and
	 * must never surface an error to the request.
	 *
	 * @return int Number of entries persisted.
	 *
	 * @spec openspec/specs/search-trail-recording/spec.md
	 */
	public function flushSearchTrails(): int {
		$persisted = 0;
		$entries = $this->searchTrailBuffer;

		// Clear the buffer up front so re-entrant calls never double-write.
		$this->searchTrailBuffer = [];

		foreach ($entries as $entry) {
			try {
				// The mapper extracts the search term from query['_search'] into
				// the search_term column that the popular-terms stats aggregate.
				$this->searchTrailService->createSearchTrail(
					query: $entry['query'],
					resultCount: $entry['resultCount'],
					totalResults: $entry['totalResults'],
					responseTime: $entry['responseTime'],
					executionType: $entry['executionType']
				);
				$persisted++;
			} catch (\Throwable $e) {
				// Log the error but never fail: the trail is best-effort.
				$this->logger->warning(
					message: '[SearchQueryHandler] Failed to record search trail',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'error' => $e->getMessage(),
					]
				);
			}//end try
		}//end foreach

		return $persisted;
	}//end flushSearchTrails()

	/**
	 * Resolve the effective search-trail recording mode.
	 *
	 * Returns 'none' when search trails are disabled (master switch /
	 * back-compat), otherwise the configured `searchTrailRecordingMode`
	 * ('all', '_search', or 'none'; default '_search'). Falls back to
	 * '_search' if settings cannot be read. The resolved mode is memoized
	 * per request so repeated calls (mode gate + logSearchTrail) read the
	 * settings once.
	 *
	 * @return string One of 'all', '_search', 'none'.
	 *
	 * @spec openspec/specs/search-trail-recording/spec.md
	 */
	public function getEffectiveRecordingMode(): string {
		if ($this->recordingModeMemo !== null) {
			return $this->recordingModeMemo;
		}

		$this->recordingModeMemo = $this->resolveRecordingMode();

		return $this->recordingModeMemo;
	}//end getEffectiveRecordingMode()

	/**
	 * Read the recording mode from settings (uncached).
	 *
	 * @return string One of 'all', '_search', 'none'.
	 */
	private function resolveRecordingMode(): string {
		try {
			$retentionSettings = $this->settingsService->getRetentionSettingsOnly();
			if (($retentionSettings['searchTrailsEnabled'] ?? true) === false) {
				return 'none';
			}

			$mode = $retentionSettings['searchTrailRecordingMode'] ?? '_search';
			if (in_array($mode, ['all', '_search', 'none'], true) === true) {
				return $mode;
			}

			return '_search';
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[SearchQueryHandler] Failed to read recording mode, defaulting to _search',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return '_search';
		}//end try
	}//end resolveRecordingMode()
}//end class
