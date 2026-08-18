<?php

/**
 * OpenRegister ObjectsProvider
 *
 * This file contains the provider class for the objects search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
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

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCP\IL10N;
use OCP\IUser;
use OCP\Search\FilterDefinition;
use OCP\Search\IFilteringProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use Psr\Log\LoggerInterface;

/**
 * ObjectsProvider class for the objects search.
 *
 * This class is the single, fleet-wide Nextcloud unified-search provider
 * (id `openregister_objects`) over OpenRegister objects. Leaf apps do NOT
 * register their own OCP\Search\IProvider; they participate by claiming
 * (register, schema) pairs through the deep-link registry, which supplies
 * result URLs, icons, and display names.
 *
 * SECURITY CONTRACT — the provider performs NO second access filter. All
 * RBAC scoping, tenant isolation, the published predicate, and row/field
 * level security are enforced inside the OR search pipeline, by always
 * delegating to ObjectService::searchObjectsPaginated(query, _rbac: true,
 * _multitenancy: true). The provider narrows the result set by schema
 * (`searchable = true`), and widens the MATCH — never the entitlement — with
 * `_content_search: true`, which brings in objects whose attached-file chunk
 * text matches. That fan-out receives the same `_rbac`/`_multitenancy` flags,
 * so a chunk hit is filtered exactly like a metadata hit.
 *
 * Excerpts are derived exclusively from the rendered object the user is
 * allowed to read, so field-level redaction applies to excerpt content for
 * free. THIS IS LOAD-BEARING NOW THAT FILE TEXT IS IN SCOPE: an excerpt built
 * from chunk text could surface a value the reader is redacted out of, while
 * the object itself stayed correctly filtered. See
 * openspec/changes/unified-search-provider/specs/unified-search-provider/spec.md.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/unified-search-provider/spec.md
 */
class ObjectsProvider implements IFilteringProvider {

	/**
	 * Maximum number of results returned per unified-search page.
	 *
	 * @var int
	 */
	private const PAGE_LIMIT = 25;

	/**
	 * Request-scoped cache of schema IDs flagged `searchable = false`.
	 *
	 * Null means not yet resolved this request.
	 *
	 * @var int[]|null
	 */
	private ?array $nonSearchableIds = null;

	/**
	 * The localization service
	 *
	 * @var IL10N
	 */
	private readonly IL10N $l10n;

	/**
	 * The object service for advanced search operations
	 *
	 * @var ObjectService
	 */
	private readonly ObjectService $objectService;

	/**
	 * Logger for debugging search operations
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Schema mapper for resolving the searchable-schema opt-out
	 *
	 * @var SchemaMapper
	 */
	private readonly SchemaMapper $schemaMapper;

	/**
	 * Shared result-formatting service (icon precedence, deep-link URL,
	 * subline/excerpt building).
	 *
	 * @var ObjectSearchResultFormatter
	 */
	private readonly ObjectSearchResultFormatter $resultFormatter;

	/**
	 * Constructor for the ObjectsProvider class
	 *
	 * @param IL10N $l10n The localization service
	 * @param ObjectService $objectService The object service for search operations
	 * @param LoggerInterface $logger Logger for debugging search operations
	 * @param SchemaMapper $schemaMapper Schema mapper for the searchable-schema opt-out
	 * @param ObjectSearchResultFormatter $resultFormatter Shared result-formatting service
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		IL10N $l10n,
		ObjectService $objectService,
		LoggerInterface $logger,
		SchemaMapper $schemaMapper,
		ObjectSearchResultFormatter $resultFormatter,
	) {
		$this->l10n = $l10n;
		$this->objectService = $objectService;
		$this->logger = $logger;
		$this->schemaMapper = $schemaMapper;
		$this->resultFormatter = $resultFormatter;
	}//end __construct()

	/**
	 * Returns the unique identifier for this search provider
	 *
	 * @return string Unique identifier for the search provider
	 *
	 * @psalm-return 'openregister_objects'
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getId(): string {
		return 'openregister_objects';
	}//end getId()

	/**
	 * Returns the human-readable name for this search provider
	 *
	 * @return string Display name for the search provider
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getName(): string {
		return $this->l10n->t('Open Register Objects');
	}//end getName()

	/**
	 * Returns the order/priority of this search provider
	 *
	 * Lower values appear first in search results
	 *
	 * @param string $route The route/context for which to get the order
	 * @param array $routeParameters Parameters for the route
	 *
	 * @return int
	 *
	 * @psalm-return     10
	 * @psalm-suppress   UnusedParam Parameters required by interface but not used
	 * @SuppressWarnings (PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getOrder(string $route, array $routeParameters): ?int {
		// Parameters $route and $routeParameters required by interface but not used.
		unset($route, $routeParameters);
		return 10;
	}//end getOrder()

	/**
	 * Returns the list of supported filters for the search provider
	 *
	 * @return string[]
	 *
	 * @psalm-return   list{'term', 'since', 'until', 'person', 'register', 'schema'}
	 * @phpstan-return array<string>
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getSupportedFilters(): array {
		return [
			// Generic.
			'term',
			'since',
			'until',
			'person',
			// Open Register Specific.
			'register',
			'schema',
		];
	}//end getSupportedFilters()

	/**
	 * Returns the list of alternate IDs for the search provider
	 *
	 * @return array
	 *
	 * @psalm-return   array<never, never>
	 * @phpstan-return array<string>
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getAlternateIds(): array {
		return [];
	}//end getAlternateIds()

	/**
	 * Returns the list of custom filters for the search provider
	 *
	 * @return FilterDefinition[]
	 *
	 * @psalm-return   list{FilterDefinition, FilterDefinition}
	 * @phpstan-return list<\OCP\Search\FilterDefinition>
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getCustomFilters(): array {
		return [
			new FilterDefinition(name: 'register', type: FilterDefinition::TYPE_STRING),
			new FilterDefinition(name: 'schema', type: FilterDefinition::TYPE_STRING),
		];
	}//end getCustomFilters()

	/**
	 * Performs a search based on the provided query using searchObjectsPaginated
	 *
	 * This method integrates with Nextcloud's search interface by converting
	 * search query filters to OpenRegister's advanced search parameters and
	 * using the optimized searchObjectsPaginated method for best performance.
	 *
	 * @param IUser $user The user performing the search
	 * @param ISearchQuery $query The search query from Nextcloud
	 *
	 * @return SearchResult The search results formatted for Nextcloud's search interface
	 *
	 * @throws \Exception If search operation fails
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.StaticAccess)          SearchResult::complete is standard Nextcloud search pattern
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Search requires handling many filter and sort options
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Search filter building requires many conditional checks
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 * Search requires handling many filters, building queries, and formatting results
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		// Initialize filters array.
		$filters = [];

		/*
		 * @var string|null $register
		 */

		$register = $query->getFilter('register')?->get();
		if ($register !== null) {
			$filters['register'] = $register;
		}

		/*
		 * @var string|null $schema
		 */

		$schema = $query->getFilter('schema')?->get();
		if ($schema !== null) {
			$filters['schema'] = $schema;
		}

		/*
		 * @var string|null $search
		 */

		$search = $query->getFilter('term')?->get();

		/*
		 * @var string|null $since
		 */

		$since = $query->getFilter('since')?->get();

		/*
		 * @var string|null $until
		 */

		$until = $query->getFilter('until')?->get();

		// Build search query for searchObjectsPaginated.
		$searchQuery = [];

		// Add search term if provided.
		if (empty($search) === false) {
			$searchQuery['_search'] = $search;
		}

		// Resolve the searchable-schema opt-out once per request.
		$nonSearchableIds = $this->getNonSearchableIds();

		// Add filters to @self metadata section. When an explicit schema
		// filter targets a non-searchable schema, the opt-out wins: return
		// an empty (complete) result set rather than leaking it.
		if (empty($register) === false) {
			$searchQuery['@self']['register'] = (int)$register;
		}

		if (empty($schema) === false) {
			$schemaId = (int)$schema;
			if (in_array($schemaId, $nonSearchableIds, true) === true) {
				return SearchResult::complete(
					name: $this->getSectionName(),
					entries: []
				);
			}

			$searchQuery['@self']['schema'] = $schemaId;
		} elseif (empty($nonSearchableIds) === false) {
			// No explicit schema filter: constrain the query to the
			// searchable-schema allow-list so opted-out schemas never
			// contribute results, applied inside the query (not by
			// post-filtering a page).
			$searchableIds = $this->schemaMapper->findSearchableIds();
			if (empty($searchableIds) === true) {
				return SearchResult::complete(
					name: $this->getSectionName(),
					entries: []
				);
			}

			$searchQuery['@self']['schema'] = $searchableIds;
		}//end if

		// Add date filters if provided.
		if ($since !== null) {
			$searchQuery['@self']['created'] = ['$gte' => $since];
		}

		if ($until !== null) {
			if (($searchQuery['@self']['created'] ?? null) !== null) {
				$searchQuery['@self']['created']['$lte'] = $until;
			}

			if (($searchQuery['@self']['created'] ?? null) === null) {
				$searchQuery['@self']['created'] = ['$lte' => $until];
			}
		}

		// Cursor pagination: cursor is an integer offset serialised as a
		// string (matching the NC core files/contacts providers). Limit is
		// capped at PAGE_LIMIT.
		$limit = self::PAGE_LIMIT;
		$queryLimit = $query->getLimit();
		if ($queryLimit > 0 && $queryLimit < $limit) {
			$limit = $queryLimit;
		}

		$offset = 0;
		$cursor = $query->getCursor();
		if (is_numeric($cursor) === true) {
			$offset = max(0, (int)$cursor);
		}

		$searchQuery['_limit'] = $limit;
		$searchQuery['_offset'] = $offset;

		$this->logger->debug(
			message: '[ObjectsProvider] OpenRegister search requested',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'search_query' => $searchQuery,
				'has_search' => empty($search) === false,
			]
		);

		// Widen the match to text OpenRegister has already extracted from
		// attached files (ZKN-CONTENT-001). Without this the provider searches
		// object METADATA only, so a term that appears solely inside an
		// attached PDF finds nothing — while OR holds that text indexed in
		// `openregister_chunks` and `ChunkMapper::searchByKeyword()` can find
		// it. That gap was measured 2026-08-15: this file contained zero chunk
		// references.
		//
		// It is safe to turn on HERE, and only because the fan-out is not a
		// second query path around the guard rails: `QueryHandler` forwards
		// `_rbac` and `_multitenancy` into `augmentWithChunkMatches()`, so a
		// chunk hit on an object the caller may not read is filtered by the
		// same pipeline that filters a metadata hit. The provider still
		// applies no second access filter of its own.
		//
		// A chunk is a fragment of a file; the row appended is the OWNING
		// OBJECT, which is the thing with a deep-link URL, an icon and a
		// title. A bare chunk would not be navigable.
		$searchQuery['_content_search'] = true;

		// Delegate to the OR search pipeline. RBAC, tenant isolation, the
		// published predicate, and soft-delete exclusion are ALL enforced
		// here — the provider applies no second access filter. Fail soft on
		// a broken pipeline/register so the top-bar search never errors out.
		try {
			$searchResults = $this->objectService->searchObjectsPaginated(query: $searchQuery, _rbac: true, _multitenancy: true);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[ObjectsProvider] OpenRegister search failed, returning empty result: {error}',
				['error' => $e->getMessage()]
			);
			return SearchResult::complete(
				name: $this->getSectionName(),
				entries: []
			);
		}

		// Convert results to SearchResultEntry format.
		$searchResultEntries = [];
		if (empty($searchResults['results']) === false) {
			foreach ($searchResults['results'] as $result) {
				$searchResultEntries[] = $this->resultFormatter->format(result: $result, term: $search);
			}//end foreach
		}//end if

		$this->logger->debug(
			message: '[ObjectsProvider] OpenRegister search completed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'results_count' => count($searchResultEntries),
				'total_results' => $searchResults['total'] ?? 0,
			]
		);

		// A full page implies there may be more; hand back a paginated
		// result carrying the next offset as the cursor. A short or empty
		// page completes the result.
		if (count($searchResultEntries) >= $limit) {
			return SearchResult::paginated(
				$this->getSectionName(),
				$searchResultEntries,
				($offset + $limit)
			);
		}

		return SearchResult::complete(
			name: $this->getSectionName(),
			entries: $searchResultEntries
		);
	}//end search()

	/**
	 * The localized provider section name shown in unified search.
	 *
	 * @return string The section title.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function getSectionName(): string {
		return $this->l10n->t('Open Register Objects');
	}//end getSectionName()

	/**
	 * Resolve the request-scoped set of non-searchable schema IDs.
	 *
	 * Cached for the lifetime of the request; fails soft (treats all
	 * schemas as searchable) if the mapper lookup errors.
	 *
	 * @return int[] Schema IDs flagged `searchable = false`.
	 *
	 * @psalm-return list<int>
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function getNonSearchableIds(): array {
		if ($this->nonSearchableIds !== null) {
			return $this->nonSearchableIds;
		}

		try {
			$this->nonSearchableIds = $this->schemaMapper->findNonSearchableIds();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[ObjectsProvider] Failed to resolve non-searchable schemas, treating all as searchable: {error}',
				['error' => $e->getMessage()]
			);
			$this->nonSearchableIds = [];
		}

		return $this->nonSearchableIds;
	}//end getNonSearchableIds()
}//end class
