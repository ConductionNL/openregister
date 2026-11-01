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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCP\IL10N;
use OCP\IUser;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Search\SearchScopes;
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
 * QUERY SHAPE — every schema is its own table, and the pipeline answers a
 * cross-schema search with ONE statement that unions all of them. The
 * database locks each table, and each of its indexes, for the whole
 * statement, so the number of schemas one statement spans is bounded here
 * (SCHEMA_CHUNK_SIZE): the searchable allow-list is always passed, in
 * chunks, and the chunk pages are merged in the pipeline's own order. A
 * chunk that fails is an ERROR in the log and a gap in the page, never a
 * silent "no results" for the whole section.
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
	 * Upper bound on the number of schemas one pipeline call may span.
	 *
	 * A cross-schema search is one UNION ALL over one table per schema,
	 * and Postgres holds a lock on every table AND every index the planner
	 * opens for the whole statement. Measured 2026-09-07 on the fleet dev
	 * instance: 1,272 searchable schemas with ~14 indexes each, against a
	 * lock table of max_locks_per_transaction (64) × max_connections (200)
	 * = 12,800 slots, so the statement died with SQLSTATE[53200] "out of
	 * shared memory" and the top-bar search answered nothing. Fifty
	 * schemas is roughly 750 locks per statement, which fits the smallest
	 * default lock table (64 × 100 = 6,400) with room for other sessions.
	 * Raising the database setting is not the fix: the next clone register
	 * would exhaust it again.
	 *
	 * @var int
	 */
	private const SCHEMA_CHUNK_SIZE = 50;

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
	 * Registers, for resolving which one owns a schema a scope names.
	 *
	 * @var RegisterMapper|null
	 */
	private readonly ?RegisterMapper $registerMapper;

	/**
	 * Constructor for the ObjectsProvider class
	 *
	 * @param IL10N $l10n The localization service
	 * @param ObjectService $objectService The object service for search operations
	 * @param LoggerInterface $logger Logger for debugging search operations
	 * @param SchemaMapper $schemaMapper Schema mapper for the searchable-schema opt-out
	 * @param ObjectSearchResultFormatter $resultFormatter Shared result-formatting service
	 * @param RegisterMapper|null $registerMapper Resolves a register named in a search scope. Nullable and last
	 *                                            so no positional caller shifts; absent, a scope naming a
	 *                                            register narrows nothing, which is the pre-scope behaviour
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
		// Appended LAST and nullable, deliberately: a new constructor argument
		// inserted anywhere else shifts every positional caller, and the
		// resulting TypeError names the argument AFTER the one that moved.
		// Null simply means a scope naming a register narrows nothing, which
		// is the pre-scope behaviour.
		?RegisterMapper $registerMapper = null,
	) {
		$this->l10n = $l10n;
		$this->objectService = $objectService;
		$this->logger = $logger;
		$this->schemaMapper = $schemaMapper;
		$this->resultFormatter = $resultFormatter;
		$this->registerMapper = $registerMapper;
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
			// What to look in (content-search-index). `app:<id>`,
			// `register:<slug>`, `schema:<slug>` or `files`, comma separated.
			'scopes',
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
			new FilterDefinition(name: 'scopes', type: FilterDefinition::TYPE_STRING),
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
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		/*
		 * @var string|null $search
		 */

		$search = $query->getFilter('term')?->get();
		$searchQuery = $this->buildBaseQuery(query: $query, search: $search);

		$target = $this->resolveSchemaTarget(query: $query);
		if ($target === null) {
			return SearchResult::complete(
				name: $this->getSectionName(),
				entries: []
			);
		}

		if ($target['schema'] !== null) {
			$searchQuery['@self']['schema'] = $target['schema'];
		}

		$schemaChunks = $target['chunks'];
		$searchQuery = $this->withCreatedRange(searchQuery: $searchQuery, query: $query);
		[$limit, $offset] = $this->pageWindow(query: $query);

		$this->logger->debug(
			message: '[ObjectsProvider] OpenRegister search requested',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'search_query' => $searchQuery,
				'has_search' => empty($search) === false,
				'limit' => $limit,
				'offset' => $offset,
				'schema_chunks' => count($schemaChunks),
			]
		);

		// Widen the match to text OpenRegister has already extracted from
		// attached files (ZKN-CONTENT-001), so a term that appears only inside
		// an attached PDF is found. Safe because `QueryHandler` forwards `_rbac`
		// and `_multitenancy` into `augmentWithChunkMatches()`: a chunk hit on an
		// object the caller may not read is filtered by the same pipeline that
		// filters a metadata hit. The row appended is the OWNING OBJECT, which
		// has a deep-link URL, an icon and a title; a bare chunk would not.
		$searchQuery['_content_search'] = true;

		// Delegate to the OR search pipeline, one call per schema chunk.
		// RBAC, tenant isolation, the published predicate, and soft-delete
		// exclusion are ALL enforced there — the provider applies no second
		// access filter. A chunk that fails is logged as an error and
		// skipped, so the top-bar search never errors out and never blanks
		// the section for one broken table.
		$searchResults = $this->searchChunks(
			query: $searchQuery,
			schemaChunks: $schemaChunks,
			limit: $limit,
			offset: $offset
		);

		$searchResultEntries = [];
		foreach ($searchResults['results'] as $result) {
			$searchResultEntries[] = $this->resultFormatter->format(result: $result, term: $search);
		}

		$this->logger->debug(
			message: '[ObjectsProvider] OpenRegister search completed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'results_count' => count($searchResultEntries),
				'total_results' => $searchResults['total'],
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
	 * The pipeline query for the search term and the register filter.
	 *
	 * The register reference travels as written, int-cast only when it is all
	 * digits: a slug cast to int is `0`, which names no register. ObjectService
	 * resolves the reference and refuses one that names no register
	 * (openregister#3990).
	 *
	 * @param ISearchQuery $query  The search query from Nextcloud
	 * @param mixed        $search The search term filter value
	 *
	 * @return array<string, mixed> The pipeline query.
	 */
	private function buildBaseQuery(ISearchQuery $query, mixed $search): array {
		$searchQuery = [];
		if (empty($search) === false) {
			$searchQuery['_search'] = $search;
		}

		$register = $query->getFilter('register')?->get();
		if (empty($register) === false) {
			$registerRef = trim((string)$register);
			$searchQuery['@self']['register'] = $registerRef;
			if (ctype_digit($registerRef) === true) {
				$searchQuery['@self']['register'] = (int)$registerRef;
			}
		}

		return $searchQuery;
	}//end buildBaseQuery()

	/**
	 * The schema chunks a search fans out over, and the explicit schema filter.
	 *
	 * An explicit schema filter gives a single null chunk ("the schema already in
	 * the query"); a reference that resolves to nothing travels as written, so the
	 * one refusal lives in ObjectService. Without it the search is bounded to the
	 * searchable-schema allow-list, chunked by SCHEMA_CHUNK_SIZE, because an
	 * unconstrained union over every schema table exhausts the database lock table
	 * on a many-schema instance. Null means the answer is an empty result: the
	 * filter names an opted-out schema, nothing is searchable, or the scopes
	 * narrow to nothing.
	 *
	 * @param ISearchQuery $query The search query from Nextcloud
	 *
	 * @return array{chunks: array<int, int[]|null>, schema: mixed}|null The chunks and schema filter, or null.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SearchScopes::parse is the named constructor of a value object
	 */
	private function resolveSchemaTarget(ISearchQuery $query): ?array {
		/*
		 * @var string|null $schema
		 */

		$schema = $query->getFilter('schema')?->get();

		// Read before the schema list is built, because it is what narrows that list.
		$scopes = SearchScopes::parse(raw: $query->getFilter('scopes')?->get());

		$nonSearchableIds = $this->getNonSearchableIds();

		if (empty($schema) === false) {
			// The opt-out list is numeric, so a slug is resolved before the comparison.
			$schemaId = $this->schemaIdOf(reference: $schema);
			if ($schemaId !== null && in_array($schemaId, $nonSearchableIds, true) === true) {
				return null;
			}

			return ['chunks' => [null], 'schema' => ($schemaId ?? $schema)];
		}

		$searchableIds = $this->narrowedSearchableIds(scopes: $scopes);
		if (empty($searchableIds) === true) {
			return null;
		}

		return ['chunks' => array_chunk($searchableIds, self::SCHEMA_CHUNK_SIZE), 'schema' => null];
	}//end resolveSchemaTarget()

	/**
	 * The searchable schema ids, narrowed by the declared scopes.
	 *
	 * 🔴 NARROWED BEFORE THE CHUNK LOOP, NEVER AFTER IT (D-4). Filtering the page
	 * afterwards would make a scoped search cost more than an unscoped one and
	 * would cut the page boundary before the unwanted rows were removed. An empty
	 * narrowing is a real answer, not a reason to widen back to everything.
	 *
	 * @param SearchScopes $scopes The scopes the caller declared
	 *
	 * @return array<int, int> The schema ids to search.
	 */
	private function narrowedSearchableIds(SearchScopes $scopes): array {
		$searchableIds = $this->getSearchableIds();
		if (empty($searchableIds) === true || $scopes->narrows() === false) {
			return $searchableIds;
		}

		$scoped = $scopes->narrowSchemas(schemas: $this->describeSearchableSchemas());
		return array_values(array_intersect($searchableIds, $scoped));
	}//end narrowedSearchableIds()

	/**
	 * Add the `since` / `until` filters as a created-date range.
	 *
	 * @param array<string, mixed> $searchQuery The pipeline query
	 * @param ISearchQuery         $query       The search query from Nextcloud
	 *
	 * @return array<string, mixed> The pipeline query with the range.
	 */
	private function withCreatedRange(array $searchQuery, ISearchQuery $query): array {
		/*
		 * @var string|null $since
		 */

		$since = $query->getFilter('since')?->get();

		/*
		 * @var string|null $until
		 */

		$until = $query->getFilter('until')?->get();

		if ($since !== null) {
			$searchQuery['@self']['created'] = ['$gte' => $since];
		}

		if ($until === null) {
			return $searchQuery;
		}

		if (($searchQuery['@self']['created'] ?? null) !== null) {
			$searchQuery['@self']['created']['$lte'] = $until;
			return $searchQuery;
		}

		$searchQuery['@self']['created'] = ['$lte' => $until];
		return $searchQuery;
	}//end withCreatedRange()

	/**
	 * The page size and offset of a search.
	 *
	 * The cursor is an integer offset serialised as a string (matching the NC
	 * core files/contacts providers). The limit is capped at PAGE_LIMIT.
	 *
	 * @param ISearchQuery $query The search query from Nextcloud
	 *
	 * @return array{0: int, 1: int} The limit and the offset.
	 */
	private function pageWindow(ISearchQuery $query): array {
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

		return [$limit, $offset];
	}//end pageWindow()

	/**
	 * Run the pipeline search per schema chunk and merge the pages.
	 *
	 * One chunk pages inside the pipeline, exactly as before. Several
	 * chunks each answer the head of their OWN ordering up to the end of
	 * the requested page, because the page boundary only exists after the
	 * merge; the merge re-sorts the combined head the way the pipeline
	 * orders a cross-schema page (`_search_score DESC, _uuid ASC`, which
	 * reaches the rendered row as `@self.relevance` and `@self.id`) and
	 * slices the page out of it. The pipeline caps one call at its maximum
	 * page size, so a merged page whose end lies beyond that cap is served
	 * from a truncated head; at 25 entries per page that is page 41.
	 *
	 * A chunk that throws is logged at ERROR level with the schema ids it
	 * spanned and skipped. The other chunks still answer.
	 *
	 * @param array<string, mixed>    $query        The pipeline query, without limit, offset or schema list.
	 * @param array<int, int[]|null>  $schemaChunks Schema-id chunks; a null chunk means the query already names its schema.
	 * @param int                     $limit        Page size.
	 * @param int                     $offset       Page offset.
	 *
	 * @return array{results: array<int, mixed>, total: int} The page rows in pipeline order, and the summed total.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function searchChunks(array $query, array $schemaChunks, int $limit, int $offset): array {
		$chunkCount = count($schemaChunks);
		$single = ($chunkCount === 1);
		$rows = [];
		$total = 0;

		foreach ($schemaChunks as $index => $chunk) {
			$chunkQuery = $query;
			if ($chunk !== null) {
				$chunkQuery['@self']['schema'] = $chunk;
			}

			$chunkQuery['_limit'] = $limit;
			$chunkQuery['_offset'] = $offset;
			if ($single === false) {
				$chunkQuery['_limit'] = ($offset + $limit);
				$chunkQuery['_offset'] = 0;
			}

			try {
				$page = $this->objectService->searchObjectsPaginated(query: $chunkQuery, _rbac: true, _multitenancy: true);
			} catch (\Throwable $e) {
				$this->logger->error(
					'[ObjectsProvider] OpenRegister search failed for schema chunk {chunk} of {chunks}; the other chunks still answer: {error}',
					[
						'chunk' => ($index + 1),
						'chunks' => $chunkCount,
						'schemas' => $chunk,
						'error' => $e->getMessage(),
						'exception' => $e,
					]
				);
				continue;
			}

			foreach (($page['results'] ?? []) as $row) {
				$rows[] = $row;
			}

			$total += (int)($page['total'] ?? 0);
		}//end foreach

		if ($single === true) {
			return ['results' => $rows, 'total' => $total];
		}

		usort($rows, fn (mixed $a, mixed $b): int => $this->compareRows(a: $a, b: $b));

		return [
			'results' => array_slice($rows, $offset, $limit),
			'total' => $total,
		];
	}//end searchChunks()

	/**
	 * Order two result rows the way the pipeline orders a cross-schema page.
	 *
	 * Relevance descending, then uuid ascending as the stable tiebreaker.
	 *
	 * @param mixed $a A rendered row (array) or an ObjectEntity.
	 * @param mixed $b A rendered row (array) or an ObjectEntity.
	 *
	 * @return int Negative when $a sorts first, positive when $b does.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function compareRows(mixed $a, mixed $b): int {
		[$scoreA, $uuidA] = $this->orderKey(row: $a);
		[$scoreB, $uuidB] = $this->orderKey(row: $b);
		if ($scoreA !== $scoreB) {
			return $scoreB <=> $scoreA;
		}

		return strcmp($uuidA, $uuidB);
	}//end compareRows()

	/**
	 * The (relevance, uuid) pair a row is ordered on.
	 *
	 * @param mixed $row A rendered row (array) or an ObjectEntity.
	 *
	 * @return array{0: float, 1: string} Relevance (0 when absent) and uuid ('' when absent).
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function orderKey(mixed $row): array {
		if ($row instanceof ObjectEntity) {
			return [(float)($row->getRelevance() ?? 0), (string)($row->getUuid() ?? '')];
		}

		if (is_array($row) === false) {
			return [0.0, ''];
		}

		$self = $row['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		$score = $self['relevance'] ?? $row['relevance'] ?? 0;
		$uuid = $self['id'] ?? $row['id'] ?? $self['uuid'] ?? '';

		return [(float)$score, (string)$uuid];
	}//end orderKey()

	/**
	 * Resolve the searchable-schema allow-list.
	 *
	 * Fails soft to an empty list, which the caller answers with an empty
	 * result, and says so at ERROR level: a lookup that cannot run is a
	 * broken instance, not a search with no hits.
	 *
	 * @return int[] Schema IDs flagged `searchable = true`.
	 *
	 * @psalm-return list<int>
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function getSearchableIds(): array {
		try {
			return $this->schemaMapper->findSearchableIds();
		} catch (\Throwable $e) {
			$this->logger->error(
				'[ObjectsProvider] Failed to resolve searchable schemas, returning no results: {error}',
				['error' => $e->getMessage(), 'exception' => $e]
			);
			return [];
		}
	}//end getSearchableIds()

	/**
	 * The searchable schemas with the slugs a scope names them by.
	 *
	 * 🔑 THE REGISTER SLUG COMES FROM THE REGISTER THAT OWNS THE SCHEMA, not
	 * from the first register that happens to load. A schema belongs to exactly
	 * one register, and pairing it with the wrong one makes `register:dossiq`
	 * answer somebody else's rows — true-looking, and about the wrong data.
	 *
	 * Fails soft to an empty list, like {@see getSearchableIds()}: the caller
	 * then narrows to nothing and answers an empty page, which is the same
	 * outcome as a lookup that cannot run, and says so at ERROR level.
	 *
	 * @return array<int, array{id: int, slug: string, register: string}> The schemas.
	 *
	 * @spec openspec/changes/content-search-index/specs/unified-search-provider/spec.md#requirement-the-provider-accepts-scopes-and-advertises-them
	 */
	private function describeSearchableSchemas(): array {
		try {
			$searchable = array_flip($this->schemaMapper->findSearchableIds());
			$owner = [];
			foreach (($this->registerMapper?->findAll() ?? []) as $register) {
				foreach (($register->getSchemas() ?? []) as $schemaId) {
					$owner[(int)$schemaId] = (string)$register->getSlug();
				}
			}

			$described = [];
			foreach ($this->schemaMapper->findAll() as $schema) {
				$id = (int)$schema->getId();
				if (array_key_exists($id, $searchable) === false) {
					continue;
				}

				$described[] = [
					'id' => $id,
					'slug' => (string)$schema->getSlug(),
					'register' => ($owner[$id] ?? ''),
				];
			}

			return $described;
		} catch (\Throwable $e) {
			$this->logger->error(
				'[ObjectsProvider] Failed to describe searchable schemas for scoping: {error}',
				['error' => $e->getMessage(), 'exception' => $e]
			);

			return [];
		}//end try
	}//end describeSearchableSchemas()

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
	 * The numeric id a schema reference names, when it names one.
	 *
	 * A filter value typed into unified search can be an id, a uuid or a slug.
	 * Only the id could ever be compared against the opt-out list, so the other
	 * two are resolved here. Null means "this reference resolves to nothing as
	 * far as this provider can tell", and the reference is then passed on
	 * unchanged so that the search path refuses it by name rather than this
	 * provider quietly returning an empty section.
	 *
	 * @param string $reference The schema id, uuid or slug from the filter.
	 *
	 * @return int|null The schema id, or null when the reference does not resolve.
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	private function schemaIdOf(string $reference): ?int {
		$trimmed = trim($reference);
		if (ctype_digit($trimmed) === true && (int)$trimmed > 0) {
			return (int)$trimmed;
		}

		try {
			return (int)$this->schemaMapper->find($trimmed, _rbac: false, _multitenancy: false)->getId();
		} catch (\Throwable $e) {
			return null;
		}
	}//end schemaIdOf()

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
