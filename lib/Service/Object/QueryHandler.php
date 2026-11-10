<?php

/**
 * QueryHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
 */

namespace OCA\OpenRegister\Service\Object;

use ArrayObject;
use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Service\Search\HistoryNarrowing;
use OCA\OpenRegister\Service\Search\HistoryPredicate;
use OCA\OpenRegister\Service\Search\SearchDictionaryProvider;
use OCA\OpenRegister\Service\Search\SearchTermParser;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\IAppContainer;

/**
 * Handles all query and search operations for ObjectService.
 *
 * This is the LARGEST handler responsible for:
 * - find(), findSilent(), findAll(), count()
 * - searchObjects(), searchObjectsPaginated()
 * - Async and sync pagination
 * - Performance optimizations
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex query routing and optimization logic
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Query operations require many handler dependencies
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * Boolean flags are part of established API pattern for RBAC/multitenancy filtering.
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)     Complex business logic requires multiple conditional paths
 * @SuppressWarnings(PHPMD.NPathComplexity)          Query operations have inherently complex execution paths
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)    Query methods handle complex operations that benefit from cohesion
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class QueryHandler implements QueryHandlerInterface {
	/**
	 * What a search answers when history left nothing to search in.
	 *
	 * @var array<string, mixed>
	 */
	private const NO_SEARCH_RESULT = [
		'results'        => [],
		'total'          => 0,
		'registers'      => [],
		'schemas'        => [],
		'ignoredFilters' => [],
		'source'         => 'database',
	];

	/**
	 * Page size a list/search request gets when it asks for none.
	 *
	 * Named rather than repeated so the number the capabilities answer
	 * publishes and the number this method applies are the same constant. A
	 * published limit that drifts from the enforced one is worse than no
	 * published limit: an integrator sizes their paging against it once and
	 * never checks again.
	 *
	 * @var integer
	 */
	public const DEFAULT_PAGE_SIZE = 20;

	/**
	 * Constructor for QueryHandler.
	 *
	 * @param MagicMapperInterface $objectMapper Unified mapper for objects.
	 * @param RenderObject $renderHandler Render handler.
	 * @param SearchQueryHandler $searchQueryHandler Search handler.
	 * @param FacetHandler $facetHandler Facet handler.
	 * @param PerformanceOptimizationHandler $performanceHandler Performance handler.
	 * @param ContentSearchHandler $contentSearchHandler Opt-in `_content_search` chunk fan-out handler.
	 * @param IAppContainer $container App container.
	 * @param HistoryNarrowing|null $historyNarrowing Resolves a history predicate to the ids the query keeps.
	 * @param SearchDictionaryProvider|null $dictionary The administered synonym and stopword dictionary.
	 * @param SearchReferenceResolver|null $referenceResolver Resolves a register/schema slug or uuid on a ready-made query.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectMapper,
		private readonly RenderObject $renderHandler,
		private readonly SearchQueryHandler $searchQueryHandler,
		private readonly FacetHandler $facetHandler,
		private readonly PerformanceOptimizationHandler $performanceHandler,
		private readonly ContentSearchHandler $contentSearchHandler,
		private readonly IAppContainer $container,
		// LAST AND NULLABLE on purpose: every existing construction of this
		// handler, in production wiring and in tests, keeps working unchanged.
		private readonly ?HistoryNarrowing $historyNarrowing = null,
		private readonly ?SearchDictionaryProvider $dictionary = null,
		private readonly ?SearchReferenceResolver $referenceResolver = null,
	) {
	}//end __construct()

	/**
	 * Resolve the register and schema references a ready-made query carries.
	 *
	 * `@self.register`, `@self.schema` and their `_register` / `_schema` and
	 * plural spellings arrive here as ids, uuids or slugs, because the caller
	 * built the query by hand. Downstream they meet `(int)`, and `(int)'zaken'`
	 * is `0`: the search then ran against a register that cannot exist and
	 * reported nothing found. filinq's download gate read that as "no agreement
	 * rule", dossiq's cascades read it as "nothing linked".
	 *
	 * A reference is resolved when it needs resolving and refused when it names
	 * nothing. Both are what the write path already did with the same value.
	 *
	 * @param array $query The search query.
	 *
	 * @phpstan-param array<string, mixed> $query
	 * @psalm-param   array<string, mixed> $query
	 *
	 * @return array The query with every register/schema reference resolved.
	 *
	 * @phpstan-return array<string, mixed>
	 * @psalm-return   array<string, mixed>
	 *
	 * @throws \OCA\OpenRegister\Exception\RegisterNotFoundException When a register reference names no register.
	 * @throws \OCA\OpenRegister\Exception\SchemaNotFoundException When a schema reference names no schema.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function normaliseReferences(array $query): array {
		if ($this->referenceResolver === null) {
			return $query;
		}

		return $this->referenceResolver->normaliseQuery(query: $query);
	}//end normaliseReferences()

	/**
	 * Count search objects matching the query.
	 *
	 * @param array $query The search query.
	 * @param bool $_rbac Whether to apply RBAC checks.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 * @param array|null $ids Optional array of IDs to filter by.
	 * @param string|null $uses Optional uses parameter.
	 *
	 * @psalm-param   array<string, mixed> $query
	 * @psalm-param   array<int, string>|null $ids
	 * @phpstan-param array<string, mixed> $query
	 * @phpstan-param array<int, string>|null $ids
	 *
	 * @return int The count of matching objects.
	 *
	 * @psalm-return   int
	 * @phpstan-return int
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function countSearchObjects(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $ids = null,
		?string $uses = null,
	): int {
		// A count is where the empty page hurt most: dossiq persisted a usage
		// right as false from a count that never ran, because the schema
		// reference behind it int-cast to 0. Resolve or refuse, never zero.
		$query = $this->normaliseReferences(query: $query);

		$activeOrgUuid = null;
		if ($_multitenancy === true) {
			$activeOrgUuid = $this->performanceHandler->getActiveOrganisationForContext();
		}

		// Count uses the unified mapper's countSearchObjects for proper magic mapper routing.
		return $this->objectMapper->countSearchObjects(
			query: $query,
			_activeOrgUuid: $activeOrgUuid,
			_rbac: $_rbac,
			_multitenancy: $_multitenancy,
			ids: $ids,
			uses: $uses
		);
	}//end countSearchObjects()

	/**
	 * Search objects using clean query structure.
	 *
	 * @param array $query The search query.
	 * @param bool $_rbac Whether to apply RBAC checks.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 * @param array|null $ids Optional array of IDs to filter by.
	 * @param string|null $uses Optional uses parameter.
	 * @param array|null $views Optional view IDs to apply.
	 * @param bool $_viewScopeRequired Whether the view filter is the caller's only bound,
	 *                                 making any failure to apply it fatal instead of logged.
	 *
	 * @psalm-param array<string, mixed> $query
	 * @psalm-param array<int, string>|null $ids
	 * @psalm-param array<int, string>|null $views
	 *
	 * @phpstan-param array<string, mixed> $query
	 * @phpstan-param array<int, string>|null $ids
	 * @phpstan-param array<int, string>|null $views
	 *
	 * @return ObjectEntity[]|int
	 *
	 * @psalm-return   int<0, max>|list<ObjectEntity>
	 * @phpstan-return array<int, ObjectEntity>|int
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 * @throws \Exception If `$_viewScopeRequired` is set and the view cannot be applied.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the `_rbac` / `_multitenancy` convention
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function searchObjects(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		?array $ids = null,
		?string $uses = null,
		?array $views = null,
		bool $_viewScopeRequired = false,
	): array|int {
		// A caller that hands a ready-made query instead of going through
		// buildSearchQuery() reaches the same int-cast further down, in
		// MagicMapper. Resolve here too, so a slug means the same thing on both
		// routes (openregister#3990).
		$query = $this->normaliseReferences(query: $query);

		// Apply view filters if provided.
		if (empty($views) === false) {
			$query = $this->searchQueryHandler->applyViewsToQuery(
				query: $query,
				viewIds: $views,
				_viewScopeRequired: $_viewScopeRequired
			);
		} elseif ($_viewScopeRequired === true) {
			// A caller bounded only by a view must never run without one.
			throw new Exception('Refusing a view-scoped search without a view to scope it by.');
		}

		// Execute database search.
		$result = $this->objectMapper->searchObjects(
			query: $query,
			_activeOrgUuid: $this->activeOrganisation(multitenancy: $_multitenancy),
			_rbac: $_rbac,
			_multitenancy: $_multitenancy,
			ids: $ids,
			uses: $uses
		);

		$shaper = new SearchResultShaper(renderHandler: $this->renderHandler, container: $this->container);
		return $shaper->shape(
			result: $result,
			request: new SearchRenderRequest(query: $query, rbac: $_rbac, multitenancy: $_multitenancy)
		);
	}//end searchObjects()

	/**
	 * The active organisation a search is scoped to, or null without multitenancy.
	 *
	 * @param bool $multitenancy Whether multitenancy filtering applies.
	 *
	 * @return string|null The organisation uuid of the session context.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function activeOrganisation(bool $multitenancy): ?string {
		if ($multitenancy === false) {
			return null;
		}

		return $this->performanceHandler->getActiveOrganisationForContext();
	}//end activeOrganisation()

	/**
	 * Search objects with pagination (main entry point).
	 *
	 * @param array $query The search query.
	 * @param bool $_rbac Whether to apply RBAC checks.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 * @param bool $deleted Whether to include deleted objects.
	 * @param array|null $ids Optional array of IDs to filter by.
	 * @param string|null $uses Optional uses parameter.
	 * @param array|null $views Optional view IDs to apply.
	 *
	 * @psalm-param   array<string, mixed> $query
	 * @psalm-param   array<int, string>|null $ids
	 * @psalm-param   array<int, string>|null $views
	 * @phpstan-param array<string, mixed> $query
	 * @phpstan-param array<int, string>|null $ids
	 * @phpstan-param array<int, string>|null $views
	 *
	 * @return array Paginated search results.
	 *
	 * @psalm-return   array<string, mixed>
	 * @phpstan-return array<string, mixed>
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function searchObjectsPaginated(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $deleted = false,
		?array $ids = null,
		?string $uses = null,
		?array $views = null,
	): array {
		// Apply view filters if provided.
		if (empty($views) === false) {
			$query = $this->searchQueryHandler->applyViewsToQuery(query: $query, viewIds: $views);
		}

		// Strip deprecated _source parameter (silently ignore for backward compatibility).
		unset($query['_source']);

		// Use database search (the only search backend; the external Solr/index tier was removed).
		$result = $this->searchObjectsPaginatedDatabase(
			query: $query,
			_rbac: $_rbac,
			_multitenancy: $_multitenancy,
			deleted: $deleted,
			ids: $ids,
			uses: $uses
		);
		// Use source from result if available (e.g., magic_mapper for multi-schema), otherwise default to database.
		$result['@self']['source'] = $result['@self']['source'] ?? 'database';
		$result['@self']['query'] = $query;
		$result['@self']['rbac'] = $_rbac;
		$result['@self']['multi'] = $_multitenancy;
		$result['@self']['deleted'] = $deleted;

		return $result;
	}//end searchObjectsPaginated()

	/**
	 * Database-based paginated search (extracted from main method).
	 *
	 * @param array $query The search query.
	 * @param bool $_rbac Whether to apply RBAC checks.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 * @param bool $deleted Whether to include deleted objects.
	 * @param array|null $ids Optional array of IDs to filter by.
	 * @param string|null $uses Optional uses parameter.
	 *
	 * @psalm-param   array<string, mixed> $query
	 * @psalm-param   array<int, string>|null $ids
	 * @phpstan-param array<string, mixed> $query
	 * @phpstan-param array<int, string>|null $ids
	 *
	 * @return array Paginated search results.
	 *
	 * @psalm-return   array<string, mixed>
	 * @phpstan-return array<string, mixed>
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	public function searchObjectsPaginatedDatabase(
		array $query = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
		bool $deleted = false,
		?array $ids = null,
		?string $uses = null,
	): array {
		$startTime = microtime(true);
		$metrics   = self::newMetrics();

		// Same seam as searchObjects(): a register or schema reference that
		// names nothing is refused here, never answered with an empty page.
		$query = $this->normaliseReferences(query: $query);

		$paging           = new SearchPaging();
		$reports          = new SearchResponseReports();
		$window           = $paging->window(query: $query);
		$historyPredicate = HistoryPredicate::parse(query: $query);
		$expansion        = $this->expandSearchTerm(query: $query);
		$queries          = self::withExpandedTerm(queries: $paging->queries(query: $query, window: $window), expansion: $expansion);

		$scope = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'ids' => $ids, 'uses' => $uses];
		$scope = $this->narrowByHistory(predicate: $historyPredicate, scope: $scope, metrics: $metrics);

		$searchResult = $this->searchDatabase(queries: $queries, scope: $scope, metrics: $metrics);
		$searchResult = $this->widenWithContentMatches(query: $query, searchResult: $searchResult, scope: $scope + $window, metrics: $metrics);
		$results      = $this->renderListRows(query: $query, searchResult: $searchResult, scope: $scope, metrics: $metrics);

		$paginatedResults = $paging->response(results: $results, searchResult: $searchResult, window: $window);
		$paginatedResults['@self'] += $reports->searchReports(query: $query, historyPredicate: $historyPredicate, expansion: $expansion);
		$paginatedResults['@self'] += $reports->requestedRegistersAndSchemas(query: $query, searchResult: $searchResult);
		$paginatedResults['@self'] += $reports->ignoredFilterReport(ignoredFilters: $searchResult['ignoredFilters'] ?? []);
		$paginatedResults           = $this->withFacets(query: $query, countQuery: $queries['count'], response: $paginatedResults, metrics: $metrics);

		// Always add performance metrics to @self for debugging.
		$metrics['total'] = round((microtime(true) - $startTime) * 1000, 2);
		$paginatedResults['@self']['metrics'] = $metrics->getArrayCopy();

		return $paginatedResults;
	}//end searchObjectsPaginatedDatabase()

	/**
	 * An empty collector for the timings of one search, keyed by step.
	 *
	 * @return ArrayObject<string, mixed>
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private static function newMetrics(): ArrayObject {
		return new ArrayObject();
	}//end newMetrics()

	/**
	 * The queries with the search term the administered dictionary rewrote (ADR-007).
	 *
	 * @param array{search: array, count: array}                          $queries   The page and count queries
	 * @param \OCA\OpenRegister\Service\Search\DictionaryExpansion|null $expansion The expansion, if any
	 *
	 * @return array{search: array, count: array}
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	private static function withExpandedTerm(array $queries, ?\OCA\OpenRegister\Service\Search\DictionaryExpansion $expansion): array {
		if ($expansion !== null && $expansion->changed() === true) {
			$queries['search']['_search'] = $expansion->term();
			$queries['count']['_search']  = $expansion->term();
		}

		return $queries;
	}//end withExpandedTerm()

	/**
	 * Narrow the id set by the history predicate, and resolve the active organisation.
	 *
	 * The narrowing only takes objects away from what the search allows; the
	 * search still enforces RBAC, tenant isolation and the published predicate.
	 * An empty narrowed set is marked `narrowedToNothing`, because an empty id
	 * set passed on would read as "no id filter" and answer the whole register.
	 *
	 * @param HistoryPredicate $predicate The parsed history predicate
	 * @param array            $scope     rbac, multitenancy, ids and uses of the search
	 * @param ArrayObject     $metrics   Collects timings
	 *
	 * @return array The scope with `ids`, `narrowedToNothing` and `activeOrgUuid` set
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	private function narrowByHistory(HistoryPredicate $predicate, array $scope, ArrayObject $metrics): array {
		$scope['narrowedToNothing'] = false;
		if ($predicate->narrows() === true && $this->historyNarrowing !== null) {
			$historyStart               = microtime(true);
			$scope['ids']               = $this->historyNarrowing->narrow(predicate: $predicate, ids: $scope['ids']);
			$scope['narrowedToNothing'] = ($scope['ids'] === []);
			$metrics['history']         = round((microtime(true) - $historyStart) * 1000, 2);
		}

		$scope['activeOrgUuid'] = null;
		if ($scope['multitenancy'] === true) {
			$scope['activeOrgUuid'] = $this->performanceHandler->getActiveOrganisationForContext();
		}

		return $scope;
	}//end narrowByHistory()

	/**
	 * Run the combined search and count, or answer an empty page when history left no candidates.
	 *
	 * @param array{search: array, count: array} $queries The page and count queries
	 * @param array                              $scope   The narrowed search scope
	 * @param ArrayObject                       $metrics Collects timings
	 *
	 * @return array The mapper's search result
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private function searchDatabase(array $queries, array $scope, ArrayObject $metrics): array {
		$searchStart  = microtime(true);
		$searchResult = self::NO_SEARCH_RESULT;
		if ($scope['narrowedToNothing'] === false) {
			$searchResult = $this->objectMapper->searchObjectsPaginated(
				searchQuery: $queries['search'],
				countQuery: $queries['count'],
				_activeOrgUuid: $scope['activeOrgUuid'],
				_rbac: $scope['rbac'],
				_multitenancy: $scope['multitenancy'],
				ids: $scope['ids'],
				uses: $scope['uses']
			);
		}

		$metrics['search'] = round((microtime(true) - $searchStart) * 1000, 2);
		self::recordMapperMetrics(searchResult: $searchResult, metrics: $metrics);

		return $searchResult;
	}//end searchDatabase()

	/**
	 * Copy the timings a mapper reports into the metrics.
	 *
	 * @param array       $searchResult The mapper's answer.
	 * @param ArrayObject $metrics      The metrics.
	 *
	 * @return void
	 */
	private static function recordMapperMetrics(array $searchResult, ArrayObject $metrics): void {
		if (isset($searchResult['metrics']) === true) {
			$metrics['db_search'] = $searchResult['metrics']['search_ms'] ?? null;
			$metrics['db_count']  = $searchResult['metrics']['count_ms'] ?? null;
		}
	}//end recordMapperMetrics()

	/**
	 * Widen the result with objects whose chunk text matches `_search`, when `_content_search` asks for it.
	 *
	 * Opt-in (ZKN-CONTENT-001): without it no query touches openregister_chunks.
	 * It runs before rendering so appended rows get the same `_extend`/`_fields`
	 * treatment (ZKN-CONTENT-003).
	 *
	 * @param array        $query        The search query
	 * @param array        $searchResult The mapper's search result
	 * @param array        $scope        The search scope plus the pagination window
	 * @param ArrayObject $metrics      Collects timings
	 *
	 * @return array The search result with `results` and `total` widened
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private function widenWithContentMatches(array $query, array $searchResult, array $scope, ArrayObject $metrics): array {
		if (filter_var($query['_content_search'] ?? false, FILTER_VALIDATE_BOOLEAN) === false) {
			return $searchResult;
		}

		$contentSearchStart = microtime(true);
		$augmented          = $this->contentSearchHandler->augmentWithChunkMatches(
			query: $query,
			results: $searchResult['results'],
			total: $searchResult['total'],
			limit: $scope['limit'],
			_rbac: $scope['rbac'],
			_multitenancy: $scope['multitenancy'],
			offset: $scope['offset'],
			activeOrgUuid: $scope['activeOrgUuid']
		);
		$searchResult['results']   = $augmented['results'];
		$searchResult['total']     = $augmented['total'];
		$metrics['content_search'] = round((microtime(true) - $contentSearchStart) * 1000, 2);

		return $searchResult;
	}//end widenWithContentMatches()

	/**
	 * Render the rows when the request or a schema needs it, otherwise take the cheap path.
	 *
	 * Rendering is needed for `_extend` (other than the schema and register,
	 * which the response carries at `@self` level), `_fields`, `_filter`,
	 * `_unset`, property-level authorization or a lens.
	 *
	 * @param array        $query        The search query
	 * @param array        $searchResult The search result
	 * @param array        $scope        The search scope
	 * @param ArrayObject $metrics      Collects timings
	 *
	 * @return mixed The rows
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private function renderListRows(array $query, array $searchResult, array $scope, ArrayObject $metrics): mixed {
		$results       = $searchResult['results'];
		$extend        = self::rowExtend(query: $query);
		$renderNeed    = new SearchRenderNeed();
		$needsRender   = $renderNeed->asked(query: $query, extend: $extend) || $renderNeed->declaredBy(schemas: ($searchResult['schemas'] ?? []));
		if (is_array($results) === false) {
			return $results;
		}

		if ($needsRender === false) {
			$this->prepareCheapRows(results: $results, rbac: $scope['rbac'], metrics: $metrics);
			return $results;
		}

		$renderStart = microtime(true);
		$results     = $this->renderHandler->renderEntities(
			entities: $results,
			_extend: $extend,
			_filter: $query['_filter'] ?? null,
			_fields: $query['_fields'] ?? null,
			_unset: $query['_unset'] ?? null,
			_rbac: $scope['rbac'],
			_multitenancy: $scope['multitenancy']
		);
		$metrics['render'] = round((microtime(true) - $renderStart) * 1000, 2);

		return $results;
	}//end renderListRows()

	/**
	 * The `_extend` list for the rows, without the schema and register the response carries itself.
	 *
	 * @param array $query The search query
	 *
	 * @return array
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private static function rowExtend(array $query): array {
		$extend = $query['_extend'] ?? [];
		if (is_string($extend) === true) {
			$extend = array_filter(array_map('trim', explode(',', $extend)));
		}

		return array_filter(
			$extend,
			function (string $item): bool {
				return !in_array($item, ['@self.schema', '@self.register', '_schema', '_register'], true);
			}
		);
	}//end rowExtend()

	/**
	 * The cheap path for rows that skip renderEntities().
	 *
	 * Every list row must still carry `@self.files` (one batched lookup), have
	 * its translatable properties projected to the negotiated language, and
	 * have write-only properties redacted (openregister#380, ocon#147), so a
	 * list read never shows what a single-object read would strip.
	 *
	 * @param array        $results The rows, modified in place
	 * @param bool         $rbac    Whether access control applies
	 * @param ArrayObject $metrics Collects timings
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The rbac flag gates the redaction
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private function prepareCheapRows(array &$results, bool $rbac, ArrayObject $metrics): void {
		$filesStart = microtime(true);
		$this->renderHandler->attachLightweightFilesToRows(rows: $results);
		$metrics['lightweightFiles'] = round((microtime(true) - $filesStart) * 1000, 2);

		$translateStart = microtime(true);
		$this->renderHandler->resolveTranslationsForRows(rows: $results);
		$metrics['translations'] = round((microtime(true) - $translateStart) * 1000, 2);

		$redactStart = microtime(true);
		$this->renderHandler->redactWriteOnlyFromRows(rows: $results, _rbac: $rbac);
		$metrics['writeOnlyRedaction'] = round((microtime(true) - $redactStart) * 1000, 2);
	}//end prepareCheapRows()

	/**
	 * Add facets and facetable fields when the query asks for them.
	 *
	 * @param array        $query      The search query
	 * @param array        $countQuery The count query, which facets are computed over
	 * @param array        $response   The response so far
	 * @param ArrayObject $metrics    Collects timings
	 *
	 * @return array
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private function withFacets(array $query, array $countQuery, array $response, ArrayObject $metrics): array {
		if (empty($query['_facets']) === false) {
			$response['facets'] = $this->facetResults(countQuery: $countQuery, metrics: $metrics);
		}

		if (($query['_facetable'] ?? false) === true || ($query['_facetable'] ?? false) === 'true') {
			$facetableStart        = microtime(true);
			$response['facetable'] = $this->facetHandler->getFacetableFields(baseQuery: $countQuery, _sampleSize: 100);
			$metrics['facetable']  = round((microtime(true) - $facetableStart) * 1000, 2);
		}

		return $response;
	}//end withFacets()

	/**
	 * The facets of a search, timed.
	 *
	 * @param array       $countQuery The query without paging.
	 * @param ArrayObject $metrics    The metrics.
	 *
	 * @return array The facets.
	 */
	private function facetResults(array $countQuery, ArrayObject $metrics): array {
		$facetStart        = microtime(true);
		$facetResult       = $this->facetHandler->getFacetsForObjects($countQuery);
		$metrics['facets'] = round((microtime(true) - $facetStart) * 1000, 2);
		if (isset($facetResult['performance_metadata']['facet_db_ms']) === true) {
			$metrics['facets_breakdown'] = $facetResult['performance_metadata']['facet_db_ms'];
		}

		return ($facetResult['facets'] ?? []);
	}//end facetResults()

	/**
	 * Rewrite a plain search term through the administered dictionary.
	 *
	 * Answers null when there is nothing to do: no dictionary wired, no term,
	 * an empty dictionary, or a term that already carries operators, brackets,
	 * quotes or wildcards. That last one is deliberate — the person has said
	 * precisely what they want, and splicing synonyms into their expression
	 * would answer a different question while looking like the same search.
	 *
	 * @param array $query The search query.
	 *
	 * @phpstan-param array<string, mixed> $query
	 *
	 * @psalm-param array<string, mixed> $query
	 *
	 * @return \OCA\OpenRegister\Service\Search\DictionaryExpansion|null The expansion, or null.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	private function expandSearchTerm(array $query): ?\OCA\OpenRegister\Service\Search\DictionaryExpansion {
		$term = self::plainSearchTerm(query: $query);
		if ($this->dictionary === null || $term === null) {
			return null;
		}

		$dictionary = $this->dictionary->forLanguage();
		if ($dictionary->isEmpty() === true) {
			return null;
		}

		return $dictionary->expand(
			term: $term,
			perGroupCap: $this->dictionary->perGroupCap(),
			perQueryCap: $this->dictionary->perQueryCap()
		);
	}//end expandSearchTerm()

	/**
	 * The search term, when it is plain words a dictionary can expand.
	 *
	 * @param array $query The query.
	 *
	 * @return string|null The trimmed term.
	 */
	private static function plainSearchTerm(array $query): ?string {
		$term = ($query['_search'] ?? null);
		if (is_string($term) === false || trim($term) === '') {
			return null;
		}

		if ((new SearchTermParser())->needsParsing(term: trim($term)) === true) {
			return null;
		}

		return trim($term);
	}//end plainSearchTerm()

}//end class
