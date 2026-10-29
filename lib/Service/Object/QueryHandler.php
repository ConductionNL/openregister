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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Search\HistoryNarrowing;
use OCA\OpenRegister\Service\Search\HistoryPredicate;
use OCA\OpenRegister\Service\Search\SearchDictionaryProvider;
use OCA\OpenRegister\Service\Search\SearchTermParser;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Handles all query and search operations for ObjectService.
 *
 * This is the LARGEST handler responsible for:
 * - find(), findSilent(), findAll(), count()
 * - searchObjects(), searchObjectsPaginated()
 * - Async and sync pagination
 * - Solr vs Database routing
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
class QueryHandler {
	/**
	 * Hard maximum page size for list/search requests. A client-supplied
	 * `_limit` above this is clamped down, so an oversized request (e.g.
	 * `_limit=1000000`) cannot force an unbounded result load (DoS/OOM).
	 */
	public const MAX_PAGE_SIZE = 1000;

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
	 * @param MagicMapper $objectMapper Unified mapper for objects.
	 * @param GetObject $getHandler Get handler.
	 * @param RenderObject $renderHandler Render handler.
	 * @param SearchQueryHandler $searchQueryHandler Search handler.
	 * @param FacetHandler $facetHandler Facet handler.
	 * @param PerformanceOptimizationHandler $performanceHandler Performance handler.
	 * @param ContentSearchHandler $contentSearchHandler Opt-in `_content_search` chunk fan-out handler.
	 * @param IAppContainer $container App container.
	 * @param LoggerInterface $logger Logger.
	 * @param IRequest $request Request object.
	 * @param HistoryNarrowing|null $historyNarrowing Resolves a history predicate to the ids the query keeps.
	 * @param SearchDictionaryProvider|null $dictionary The administered synonym and stopword dictionary.
	 * @param SearchReferenceResolver|null $referenceResolver Resolves a register/schema slug or uuid on a ready-made query.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		private readonly MagicMapper $objectMapper,
		private readonly GetObject $getHandler,
		private readonly RenderObject $renderHandler,
		private readonly SearchQueryHandler $searchQueryHandler,
		private readonly FacetHandler $facetHandler,
		private readonly PerformanceOptimizationHandler $performanceHandler,
		private readonly ContentSearchHandler $contentSearchHandler,
		private readonly IAppContainer $container,
		private readonly LoggerInterface $logger,
		private readonly IRequest $request,
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
		if ($views !== null && empty($views) === false) {
			$query = $this->searchQueryHandler->applyViewsToQuery(
				query: $query,
				viewIds: $views,
				_viewScopeRequired: $_viewScopeRequired
			);
		} elseif ($_viewScopeRequired === true) {
			// A caller bounded only by a view must never run without one.
			throw new Exception('Refusing a view-scoped search without a view to scope it by.');
		}

		// Detect if complex rendering is needed (extend, fields, filter, unset).
		$hasComplexRendering = empty($query['_extend'] ?? []) === false
			|| empty($query['_fields'] ?? null) === false
			|| empty($query['_filter'] ?? null) === false
			|| empty($query['_unset'] ?? null) === false;

		// Get active organization context for multi-tenancy.
		$activeOrgUuid = null;
		if ($_multitenancy === true) {
			$activeOrgUuid = $this->performanceHandler->getActiveOrganisationForContext();
		}

		// Execute database search.
		$result = $this->objectMapper->searchObjects(
			query: $query,
			_activeOrgUuid: $activeOrgUuid,
			_rbac: $_rbac,
			_multitenancy: $_multitenancy,
			ids: $ids,
			uses: $uses
		);

		// If _count is requested, return count instead of objects.
		if (($query['_count'] ?? false) === true || ($query['_count'] ?? false) === 'true') {
			return count($result);
		}

		// Check if any result object has a schema with property-level authorization.
		// If yes, we need to render to filter unauthorized properties.
		if ($hasComplexRendering === false && is_array($result) === true && empty($result) === false) {
			$schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
			$checkedSchemas = [];
			foreach ($result as $object) {
				$schemaId = $object->getSchema();
				if (isset($checkedSchemas[$schemaId]) === true) {
					if ($checkedSchemas[$schemaId] === true) {
						$hasComplexRendering = true;
						break;
					}

					continue;
				}

				try {
					$schema = $schemaMapper->find($schemaId);
					$checkedSchemas[$schemaId] = $schema->hasPropertyAuthorization();
					if ($checkedSchemas[$schemaId] === true) {
						$hasComplexRendering = true;
						break;
					}
				} catch (\Exception $e) {
					$checkedSchemas[$schemaId] = false;
				}
			}//end foreach
		}//end if

		// Return early if no complex rendering is needed.
		if ($hasComplexRendering === false) {
			return $result;
		}

		// Apply complex rendering (extend, fields, filter, unset).
		return $this->renderHandler->renderEntities(
			entities: $result,
			_extend: $query['_extend'] ?? [],
			_filter: $query['_filter'] ?? null,
			_fields: $query['_fields'] ?? null,
			_unset: $query['_unset'] ?? null,
			_rbac: $_rbac,
			_multitenancy: $_multitenancy
		);
	}//end searchObjects()

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
		if ($views !== null && empty($views) === false) {
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

		$window           = self::paginationWindow(query: $query);
		$historyPredicate = HistoryPredicate::parse(query: $query);
		$expansion        = $this->expandSearchTerm(query: $query);
		$queries          = self::withExpandedTerm(queries: self::paginatedAndCountQueries(query: $query, window: $window), expansion: $expansion);

		$scope = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy, 'ids' => $ids, 'uses' => $uses];
		$scope = $this->narrowByHistory(predicate: $historyPredicate, scope: $scope, metrics: $metrics);

		$searchResult = $this->searchDatabase(queries: $queries, scope: $scope, metrics: $metrics);
		$searchResult = $this->widenWithContentMatches(query: $query, searchResult: $searchResult, scope: $scope + $window, metrics: $metrics);
		$results      = $this->renderListRows(query: $query, searchResult: $searchResult, scope: $scope, metrics: $metrics);

		$paginatedResults = self::paginatedResponse(results: $results, searchResult: $searchResult, window: $window);
		$paginatedResults['@self'] += self::searchReports(query: $query, historyPredicate: $historyPredicate, expansion: $expansion);
		$paginatedResults['@self'] += self::requestedRegistersAndSchemas(query: $query, searchResult: $searchResult);
		$paginatedResults['@self'] += self::ignoredFilterReport(ignoredFilters: $searchResult['ignoredFilters'] ?? []);
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
	 * The limit, offset and page of a paginated search.
	 *
	 * `_limit=0` is valid for count- or facets-only requests; the limit is
	 * clamped to MAX_PAGE_SIZE so an oversized `_limit` cannot force an
	 * unbounded load. A page gives the offset and an offset gives the page.
	 *
	 * @param array $query The search query
	 *
	 * @return array{limit: int, offset: mixed, page: mixed}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private static function paginationWindow(array $query): array {
		$limit  = min(max(0, (int) ($query['_limit'] ?? self::DEFAULT_PAGE_SIZE)), self::MAX_PAGE_SIZE);
		$offset = $query['_offset'] ?? null;
		$page   = $query['_page'] ?? null;

		if ($page !== null && $offset === null) {
			$page   = max(1, (int) $page);
			$offset = ($page - 1) * $limit;
		}

		if ($page === null && $offset !== null && $limit > 0) {
			$page = (int) floor($offset / $limit) + 1;
		}

		return ['limit' => $limit, 'offset' => $offset ?? 0, 'page' => $page ?? 1];
	}//end paginationWindow()

	/**
	 * The page query and the count query handed to the mapper.
	 *
	 * Control keys the mapper does not read are removed, and so are the two
	 * history filter keys: left in, the search path would read them as
	 * property filters that match nothing. History is applied as a narrowing
	 * of the id set instead (see narrowByHistory()).
	 *
	 * @param array                                       $query  The search query
	 * @param array{limit: int, offset: mixed, page: mixed} $window The pagination window
	 *
	 * @return array{search: array, count: array}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private static function paginatedAndCountQueries(array $query, array $window): array {
		$paginatedQuery = array_merge($query, ['_limit' => $window['limit'], '_offset' => $window['offset']]);
		unset($paginatedQuery['_page'], $paginatedQuery['_facetable'], $paginatedQuery['_extend']);

		$countQuery = $query;
		unset($countQuery['_limit'], $countQuery['_offset'], $countQuery['_page'], $countQuery['_facetable'], $countQuery['_extend']);

		unset(
			$paginatedQuery[HistoryPredicate::WAS_EVER],
			$paginatedQuery[HistoryPredicate::CHANGED_BETWEEN],
			$countQuery[HistoryPredicate::WAS_EVER],
			$countQuery[HistoryPredicate::CHANGED_BETWEEN]
		);

		return ['search' => $paginatedQuery, 'count' => $countQuery];
	}//end paginatedAndCountQueries()

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
		$searchResult = [
			'results'        => [],
			'total'          => 0,
			'registers'      => [],
			'schemas'        => [],
			'ignoredFilters' => [],
			'source'         => 'database',
		];
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
		if (isset($searchResult['metrics']) === true) {
			$metrics['db_search'] = $searchResult['metrics']['search_ms'] ?? null;
			$metrics['db_count']  = $searchResult['metrics']['count_ms'] ?? null;
		}

		return $searchResult;
	}//end searchDatabase()

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
		$needsRender   = self::needsRendering(query: $query, extend: $extend, schemas: $searchResult['schemas'] ?? []);
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
	 * Whether the rows need the full render pipeline.
	 *
	 * @param array $query   The search query
	 * @param array $extend  The row extend list
	 * @param array $schemas The schemas of the result, as entities or serialized arrays
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private static function needsRendering(array $query, array $extend, array $schemas): bool {
		return empty($extend) === false
			|| empty($query['_fields'] ?? null) === false
			|| empty($query['_filter'] ?? null) === false
			|| empty($query['_unset'] ?? null) === false
			|| self::declaresPropertyAuthorization(schemas: $schemas) === true
			|| self::declaresLenses(schemas: $schemas) === true;
	}//end needsRendering()

	/**
	 * Whether any schema has property-level authorization.
	 *
	 * @param array $schemas Schema entities or serialized schema arrays
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private static function declaresPropertyAuthorization(array $schemas): bool {
		foreach ($schemas as $schema) {
			if ($schema instanceof \OCA\OpenRegister\Db\Schema) {
				if ($schema->hasPropertyAuthorization() === true) {
					return true;
				}

				continue;
			}

			if (is_array($schema) === true && self::propertiesDeclareAuthorization(properties: $schema['properties'] ?? null) === true) {
				return true;
			}
		}

		return false;
	}//end declaresPropertyAuthorization()

	/**
	 * Whether a serialized schema's properties carry a non-empty `authorization`.
	 *
	 * @param mixed $properties The `properties` of a serialized schema
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private static function propertiesDeclareAuthorization(mixed $properties): bool {
		foreach ($properties ?? [] as $propertyConfig) {
			if (is_array($propertyConfig) === true && empty($propertyConfig['authorization'] ?? null) === false) {
				return true;
			}
		}

		return false;
	}//end propertiesDeclareAuthorization()

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
	 * The response skeleton: rows, totals and the pagination window.
	 *
	 * @param mixed $results      The rows
	 * @param array $searchResult The search result
	 * @param array $window       The pagination window
	 *
	 * @return array
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private static function paginatedResponse(mixed $results, array $searchResult, array $window): array {
		$total = $searchResult['total'];
		$pages = 0;
		if ($window['limit'] > 0) {
			$pages = max(1, (int) ceil($total / $window['limit']));
		}

		return [
			'results' => $results,
			'total'   => $total,
			'page'    => $window['page'],
			'pages'   => $pages,
			'limit'   => $window['limit'],
			'offset'  => $window['offset'],
			'facets'  => [],
			'@self'   => ['source' => $searchResult['source'] ?? 'database'],
		];
	}//end paginatedResponse()

	/**
	 * What the history filter, the dictionary and the `_recent` lens did, for `@self`.
	 *
	 * A filter that did not read says so instead of quietly doing nothing, and an
	 * expansion is reported because it returns rows the searcher did not ask for.
	 *
	 * @param array                                                     $query            The search query
	 * @param HistoryPredicate                                          $historyPredicate The parsed history predicate
	 * @param \OCA\OpenRegister\Service\Search\DictionaryExpansion|null $expansion        The expansion, if any
	 *
	 * @return array
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	private static function searchReports(
		array $query,
		HistoryPredicate $historyPredicate,
		?\OCA\OpenRegister\Service\Search\DictionaryExpansion $expansion
	): array {
		$reports = [];
		if ($historyPredicate->narrows() === true || $historyPredicate->unparsed() !== []) {
			$reports['history'] = $historyPredicate->jsonSerialize();
		}

		if ($expansion !== null && $expansion->isReportable() === true) {
			$reports['dictionary'] = $expansion->jsonSerialize();
		}

		if (is_array($query['_recentLens'] ?? null) === true) {
			$reports['lenses']['recent'] = $query['_recentLens'];
		}

		return $reports;
	}//end searchReports()

	/**
	 * The registers and schemas indexed by id, when `_extend` asks for them in any spelling.
	 *
	 * @param array $query        The search query
	 * @param array $searchResult The search result
	 *
	 * @return array
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private static function requestedRegistersAndSchemas(array $query, array $searchResult): array {
		$extend = $query['_extend'] ?? [];
		if (is_string($extend) === true) {
			$extend = explode(',', $extend);
		}

		$included = [];
		$spellings = [
			'registers' => ['_registers', '_register', '@self.registers', '@self.register'],
			'schemas'   => ['_schemas', '_schema', '@self.schemas', '@self.schema'],
		];
		foreach ($spellings as $key => $names) {
			$asked = array_filter($names, static fn (string $name): bool => in_array($name, $extend, true));
			if ($asked !== [] && empty($searchResult[$key] ?? []) === false) {
				$included[$key] = $searchResult[$key];
			}
		}

		return $included;
	}//end requestedRegistersAndSchemas()

	/**
	 * The ignored filters, with a hint when one looks like a control parameter missing its `_`.
	 *
	 * @param array $ignoredFilters The filters the search ignored
	 *
	 * @return array
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	private static function ignoredFilterReport(array $ignoredFilters): array {
		if (empty($ignoredFilters) === true) {
			return [];
		}

		$report         = ['ignoredFilters' => $ignoredFilters];
		$controlParams  = ['limit', 'offset', 'page', 'order', 'sort', 'search', 'extend', 'fields', 'filter', 'unset'];
		$mistakenParams = array_intersect($ignoredFilters, $controlParams);
		if (empty($mistakenParams) === false) {
			$suggestions    = array_map(fn ($param) => "_{$param}", $mistakenParams);
			$report['hint'] = 'Query returned 0 results because '.implode(', ', $mistakenParams)
				.' was treated as a property filter. Did you mean '.implode(', ', $suggestions)
				.'? Control params require underscore prefix.';
		}

		return $report;
	}//end ignoredFilterReport()

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
			$facetStart         = microtime(true);
			$facetResult        = $this->facetHandler->getFacetsForObjects($countQuery);
			$response['facets'] = $facetResult['facets'] ?? [];
			$metrics['facets']  = round((microtime(true) - $facetStart) * 1000, 2);
			if (isset($facetResult['performance_metadata']['facet_db_ms']) === true) {
				$metrics['facets_breakdown'] = $facetResult['performance_metadata']['facet_db_ms'];
			}
		}

		if (($query['_facetable'] ?? false) === true || ($query['_facetable'] ?? false) === 'true') {
			$facetableStart        = microtime(true);
			$response['facetable'] = $this->facetHandler->getFacetableFields(baseQuery: $countQuery, _sampleSize: 100);
			$metrics['facetable']  = round((microtime(true) - $facetableStart) * 1000, 2);
		}

		return $response;
	}//end withFacets()

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
		if ($this->dictionary === null) {
			return null;
		}

		$term = ($query['_search'] ?? null);
		if (is_string($term) === false || trim($term) === '') {
			return null;
		}

		if ((new SearchTermParser())->needsParsing(term: trim($term)) === true) {
			return null;
		}

		$dictionary = $this->dictionary->forLanguage();
		if ($dictionary->isEmpty() === true) {
			return null;
		}

		return $dictionary->expand(
			term: trim($term),
			perGroupCap: $this->dictionary->perGroupCap(),
			perQueryCap: $this->dictionary->perQueryCap()
		);
	}//end expandSearchTerm()

	/**
	 * Whether any of the schemas declares a lens. A lens is resolved while an
	 * object is rendered, so rows of such a schema take the render path, or a
	 * list would answer without the values a single read shows.
	 *
	 * @param array<int|string, mixed> $schemas Schema entities or serialised schemas.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public static function declaresLenses(array $schemas): bool {
		foreach ($schemas as $schema) {
			if ($schema instanceof \OCA\OpenRegister\Db\Schema && $schema->getLenses() !== []) {
				return true;
			}

			if (is_array($schema) === true && empty($schema['configuration'][\OCA\OpenRegister\Db\Schema::LENS_ANNOTATION] ?? null) === false) {
				return true;
			}
		}

		return false;
	}//end declaresLenses()
}//end class
