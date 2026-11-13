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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCA\OpenRegister\Service\SearchMeasurement;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedFilterExpander;
use OCA\OpenRegister\Support\FilterParams;
use OCP\IUserSession;
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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Query building reads views, schemas, watchers and references
 */
class SearchQueryHandler {

	/**
	 * The parameters that filter on an object's metadata, moved into `@self`.
	 *
	 * @var array<int, string>
	 */
	private const METADATA_FIELDS = [
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
	 * @param ViewScopeApplier $viewScope Merges a view's stored query into a search.
	 * @param SchemaMapperInterface $schemaMapper Mapper for schema operations.
	 * @param SettingsServiceInterface $settingsService Service for settings operations.
	 * @param LoggerInterface $logger Logger for performance monitoring.
	 * @param SearchTrailService $searchTrailService Service for recording search trails.
	 * @param IUserSession|null $userSession Resolves the caller for that lens.
	 * @param CodedFilterExpander|null $codedFilters Expands a branch filter into the concepts under it.
	 * @param SearchReferenceResolver|null $referenceResolver Resolves a register/schema slug or uuid to its id.
	 * @param ReadHistoryService|null $readHistory The read history behind the `_recent` lens.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		private readonly ViewScopeApplier $viewScope,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
		private readonly SearchTrailService $searchTrailService,
		private readonly ?IUserSession $userSession = null,
		// The branch-filter expander. Nullable with a null default so the many
		// unit tests that build this handler positionally keep working; the
		// container resolves the real instance by type in production.
		private readonly ?CodedFilterExpander $codedFilters = null,
		// The register/schema reference resolver. Nullable and last for the same
		// reason as the expander above. Absent, buildSearchQuery() still refuses
		// a reference it cannot read rather than int-casting it into an empty
		// page; present, it resolves a slug or uuid the way the write path does.
		private readonly ?SearchReferenceResolver $referenceResolver = null,
		// The read history behind the `_recent` lens. Nullable and last for the
		// same reason; absent, the lens answers an empty page that says the
		// history could not be read, never the whole register.
		private readonly ?ReadHistoryService $readHistory = null,
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

		return ($this->schemaOrNull(reference: $schema)?->getObjectSource() !== null);
	}//end schemaHasObjectSource()

	/**
	 * Read one schema, answering null when it cannot be read.
	 *
	 * @param mixed $reference The schema reference, as a schema list may hold it.
	 *
	 * @return Schema|null The schema.
	 */
	private function schemaOrNull(mixed $reference): ?Schema {
		try {
			return $this->schemaMapper->find(id: $reference, _rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			return null;
		}
	}//end schemaOrNull()

	/**
	 * Whether the target schema declares a property literally named `filter`.
	 *
	 * Only such a schema can mean `filter[x]=v` as a filter on its own `filter`
	 * property, so only there is the bracket spelling left alone. A list of
	 * schemas (cross-table search) counts when any one of them declares it. A
	 * schema that cannot be resolved does not, so the lift applies.
	 *
	 * A SYSTEM-level structural lookup, like {@see schemaHasObjectSource()}: it
	 * decides how the parameters are parsed and returns no schema data, so it
	 * bypasses RBAC and multitenancy. The data read stays checked downstream.
	 *
	 * @param int|string|array|null $schema The schema id/slug, or a list of them.
	 *
	 * @return bool True when a target schema declares a `filter` property.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
	 */
	private function schemaDeclaresFilterProperty(int|string|array|null $schema): bool {
		foreach ((array)$schema as $schemaRef) {
			$properties = ($this->schemaOrNull(reference: $schemaRef)?->getProperties() ?? []);
			if (array_key_exists(FilterParams::FILTER_KEY, $properties) === true) {
				return true;
			}
		}

		return false;
	}//end schemaDeclaresFilterProperty()

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
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function buildSearchQuery(
		array $requestParams,
		int|string|array|null $register = null,
		int|string|array|null $schema = null,
		?array $ids = null,
	): array {
		$params = $this->searchableParams(requestParams: $requestParams, schema: $schema);
		$scope = (new SearchScopeFilters(resolver: $this->referenceResolver))->forScope(register: $register, schema: $schema);
		$parts = $this->splitSearchParams(params: $params, self: $scope);
		$query = array_merge(['@self' => $parts->self], $this->expandedFilters(filters: $parts->objectFilters, schema: $schema));

		if ($ids !== null) {
			$query['_ids'] = $ids;
		}

		return $this->lenses()->apply(query: $this->mergeSpecialParams(query: $query, special: $parts->special));
	}//end buildSearchQuery()

	/**
	 * The lenses of this request.
	 *
	 * @return SearchLenses The lenses.
	 */
	private function lenses(): SearchLenses {
		return new SearchLenses(userSession: $this->userSession, readHistory: $this->readHistory);
	}//end lenses()

	/**
	 * The request parameters a search can filter on, under their real names.
	 *
	 * @param array                 $requestParams The request parameters.
	 * @param int|string|array|null $schema        The schema, or schemas, searched.
	 *
	 * @return array The parameters.
	 */
	private function searchableParams(array $requestParams, int|string|array|null $schema): array {
		return $this->filterableParams(
			params: $this->liftBracketFilterUnlessDeclared(
				params: $this->restoreDottedParams(requestParams: $requestParams, schema: $schema),
				schema: $schema
			)
		);
	}//end searchableParams()

	/**
	 * Expand a branch filter into the concepts it stands for.
	 *
	 * `?categorie[branch]=<uri>` becomes `categorie` IN (the branch root plus
	 * every narrower concept under it), walked at query time and bounded by
	 * depth, so moving a concept in the scheme changes what the filter matches
	 * with no reindex anywhere.
	 *
	 * @param array                 $filters The object filters.
	 * @param int|string|array|null $schema  The schema, or schemas, searched.
	 *
	 * @return array The filters.
	 */
	private function expandedFilters(array $filters, int|string|array|null $schema): array {
		if ($this->codedFilters === null) {
			return $filters;
		}

		return $this->codedFilters->expand(filters: $filters, schemaRef: $schema);
	}//end expandedFilters()

	/**
	 * Undo PHP's dot-to-underscore mangling of parameter names (`@self_register` back to `@self.register`).
	 *
	 * Underscore-prefixed system parameters (`_limit`, `_offset`) stay as they are. A schema served
	 * from an external object source has FLAT, snake_case columns (`product_line`), so for it only
	 * `@…` metadata keys are un-mangled; splitting its column names would silently drop the filter.
	 *
	 * @param array                 $requestParams The request parameters.
	 * @param int|string|array|null $schema        The schema reference of the search.
	 *
	 * @return array<string, mixed> The parameters with nested paths rebuilt.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function restoreDottedParams(array $requestParams, int|string|array|null $schema): array {
		$keepsUnderscores = $this->schemaHasObjectSource(schema: $schema);

		$tree = new DottedParamTree();
		foreach ($requestParams as $key => $value) {
			if ($this->nestsAtUnderscores(key: $key, keepsUnderscores: $keepsUnderscores) === true) {
				$tree->nest(key: $key, value: $value);
				continue;
			}

			$tree->set(key: $key, value: $value);
		}

		return $tree->params;
	}//end restoreDottedParams()

	/**
	 * Whether the underscores in a parameter name stand for dots PHP mangled.
	 *
	 * @param string $key              The parameter name.
	 * @param bool   $keepsUnderscores Whether the schema's columns are flat snake_case.
	 *
	 * @return bool True when the name nests.
	 */
	private function nestsAtUnderscores(string $key, bool $keepsUnderscores): bool {
		if (str_starts_with(haystack: $key, needle: '_') === true || str_contains($key, '_') === false) {
			return false;
		}

		return ($keepsUnderscores === false || str_starts_with(haystack: $key, needle: '@') === true);
	}//end nestsAtUnderscores()

	/**
	 * Read the bracket spelling `filter[x]=v` as `x=v`, as the aggregation endpoints do (openregister#3611).
	 *
	 * Not for a schema that really declares a `filter` property: there the property filter is meant.
	 *
	 * @param array<string, mixed>  $params The parameters.
	 * @param int|string|array|null $schema The schema reference of the search.
	 *
	 * @return array<string, mixed> The parameters.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function liftBracketFilterUnlessDeclared(array $params, int|string|array|null $schema): array {
		if (is_array($params[FilterParams::FILTER_KEY] ?? null) === true
			&& $this->schemaDeclaresFilterProperty(schema: $schema) === false
		) {
			return FilterParams::liftBracketFilter(params: $params);
		}

		return $params;
	}//end liftBracketFilterUnlessDeclared()

	/**
	 * The parameters without the route and the system parameters that are never filters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return array<string, mixed> The remaining parameters.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function filterableParams(array $params): array {
		unset($params['_route']);
		foreach (FilterParams::OBJECT_SYSTEM_PARAMS as $systemParam) {
			unset($params[$systemParam]);
		}

		return $params;
	}//end filterableParams()

	/**
	 * Sort the parameters into `@self` metadata filters, underscore parameters and object field filters.
	 *
	 * A metadata parameter never overrides the call's own register or schema. An underscore
	 * parameter is kept both as a special parameter and among the object field filters.
	 *
	 * @param array<string, mixed> $params The parameters.
	 * @param array<string, mixed> $self   The call's `@self` filters.
	 *
	 * @return SearchQueryParts The sorted parameters.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function splitSearchParams(array $params, array $self): SearchQueryParts {
		$metadata = array_flip(self::METADATA_FIELDS);

		return new SearchQueryParts(
			self: ($self + array_intersect_key($params, $metadata)),
			special: array_filter($params, static fn (string $key): bool => str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY),
			objectFilters: array_diff_key($params, $metadata)
		);
	}//end splitSearchParams()

	/**
	 * Add the underscore parameters to the query, with `_ids` normalised to an array.
	 *
	 * URL parameters like `_ids=uuid1,uuid2` arrive as one string, but MagicMapper expects an array.
	 *
	 * @param array<string, mixed> $query   The query.
	 * @param array<string, mixed> $special The underscore parameters.
	 *
	 * @return array<string, mixed> The query.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function mergeSpecialParams(array $query, array $special): array {
		if (isset($special['ids']) === true) {
			$query['_ids'] = $special['ids'];
			unset($special['ids']);
		}

		$query = array_merge($query, $special);

		if (isset($query['_ids']) === true && is_string($query['_ids']) === true) {
			$query['_ids'] = array_filter(array_map('trim', explode(',', $query['_ids'])));
		}

		return $query;
	}//end mergeSpecialParams()

	/**
	 * Apply view filters to a query.
	 *
	 * The merge itself lives in {@see ViewScopeApplier}, which owns the
	 * ViewMapper and the fail-closed contract around `$_viewScopeRequired`.
	 * This handler keeps the entry point its callers already use.
	 *
	 * @param array<string, mixed> $query Base query parameters.
	 * @param array<int|string> $viewIds View IDs to apply (can be int or string IDs).
	 * @param bool $_viewScopeRequired Whether the view filter is load-bearing for this
	 *                                 caller, making any failure to apply it fatal.
	 *
	 * @return array<string, mixed> Query with view filters applied
	 *
	 * @throws Exception When `$_viewScopeRequired` is true and a view cannot be applied.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the fail-closed contract, not a mode switch
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function applyViewsToQuery(array $query, array $viewIds, bool $_viewScopeRequired = false): array {
		if ($_viewScopeRequired === true) {
			return $this->viewScope->applyRequired(query: $query, viewIds: $viewIds);
		}

		return $this->viewScope->apply(query: $query, viewIds: $viewIds);
	}//end applyViewsToQuery()

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
		// The callback is a closure rather than the `[$this, 'flushSearchTrails']`
		// array form it replaced: a string method name is invisible to every
		// static tool, so renaming the flush would break the registration with
		// no error anywhere — trails would just stop being written.
		if ($this->trailFlushRegistered === false) {
			$this->trailFlushRegistered = true;
			register_shutdown_function(
				function (): void {
					$this->flushSearchTrails();
				}
			);
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
					measured: new SearchMeasurement(
						resultCount: $entry['resultCount'],
						totalResults: $entry['totalResults'],
						responseTime: $entry['responseTime'],
						executionType: $entry['executionType']
					)
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
