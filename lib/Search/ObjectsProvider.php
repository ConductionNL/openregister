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
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-91
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\MdiIconRenderer;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\FilterDefinition;
use OCP\Search\IFilteringProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
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
 * _multitenancy: true). The provider only narrows the result set further
 * (never widens it): it constrains the query to schemas flagged
 * `searchable = true`. Excerpts are derived exclusively from the rendered
 * object the user is allowed to read, so field-level redaction applies to
 * excerpt content for free. See
 * openspec/changes/unified-search-provider/specs/unified-search-provider/spec.md.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/unified-search-provider/specs/unified-search-provider/spec.md
 */
class ObjectsProvider implements IFilteringProvider
{

    /**
     * Maximum number of results returned per unified-search page.
     *
     * @var int
     */
    private const PAGE_LIMIT = 25;

    /**
     * Number of characters of context shown on each side of an excerpt match.
     *
     * @var int
     */
    private const EXCERPT_CONTEXT = 60;

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
     * The URL generator service
     *
     * @var IURLGenerator
     */
    private readonly IURLGenerator $urlGenerator;

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
     * Deep link registry for resolving URLs to consuming apps
     *
     * @var DeepLinkRegistryService
     */
    private readonly DeepLinkRegistryService $deepLinkRegistry;

    /**
     * Schema mapper for resolving schema names
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Register mapper for resolving register names
     *
     * @var RegisterMapper
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Cache for schema/register names to avoid repeated lookups
     *
     * @var array<string, string>
     */
    private array $nameCache = [];

    /**
     * Constructor for the ObjectsProvider class
     *
     * @param IL10N                   $l10n             The localization service
     * @param IURLGenerator           $urlGenerator     The URL generator service
     * @param ObjectService           $objectService    The object service for search operations
     * @param LoggerInterface         $logger           Logger for debugging search operations
     * @param DeepLinkRegistryService $deepLinkRegistry Deep link registry for URL resolution
     * @param SchemaMapper            $schemaMapper     Schema mapper for resolving schema names
     * @param RegisterMapper          $registerMapper   Register mapper for resolving register names
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    public function __construct(
        IL10N $l10n,
        IURLGenerator $urlGenerator,
        ObjectService $objectService,
        LoggerInterface $logger,
        DeepLinkRegistryService $deepLinkRegistry,
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper
    ) {
        $this->l10n          = $l10n;
        $this->urlGenerator  = $urlGenerator;
        $this->objectService = $objectService;
        $this->logger        = $logger;
        $this->deepLinkRegistry = $deepLinkRegistry;
        $this->schemaMapper     = $schemaMapper;
        $this->registerMapper   = $registerMapper;
    }//end __construct()

    /**
     * Returns the unique identifier for this search provider
     *
     * @return string Unique identifier for the search provider
     *
     * @psalm-return 'openregister_objects'
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    public function getId(): string
    {
        return 'openregister_objects';
    }//end getId()

    /**
     * Returns the human-readable name for this search provider
     *
     * @return string Display name for the search provider
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    public function getName(): string
    {
        return $this->l10n->t('Open Register Objects');
    }//end getName()

    /**
     * Returns the order/priority of this search provider
     *
     * Lower values appear first in search results
     *
     * @param string $route           The route/context for which to get the order
     * @param array  $routeParameters Parameters for the route
     *
     * @return int
     *
     * @psalm-return     10
     * @psalm-suppress   UnusedParam Parameters required by interface but not used
     * @SuppressWarnings (PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    public function getOrder(string $route, array $routeParameters): ?int
    {
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
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    public function getSupportedFilters(): array
    {
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
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    public function getAlternateIds(): array
    {
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
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    public function getCustomFilters(): array
    {
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
     * @param IUser        $user  The user performing the search
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
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-91
     */
    public function search(IUser $user, ISearchQuery $query): SearchResult
    {
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
            $searchQuery['@self']['register'] = (int) $register;
        }

        if (empty($schema) === false) {
            $schemaId = (int) $schema;
            if (in_array($schemaId, $nonSearchableIds, true) === true) {
                return SearchResult::complete(
                    name: $this->getSectionName(),
                    entries: []
                );
            }

            $searchQuery['@self']['schema'] = $schemaId;
        } else if (empty($nonSearchableIds) === false) {
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
        $limit      = self::PAGE_LIMIT;
        $queryLimit = $query->getLimit();
        if ($queryLimit > 0 && $queryLimit < $limit) {
            $limit = $queryLimit;
        }

        $offset = 0;
        $cursor = $query->getCursor();
        if (is_numeric($cursor) === true) {
            $offset = max(0, (int) $cursor);
        }

        $searchQuery['_limit']  = $limit;
        $searchQuery['_offset'] = $offset;

        $this->logger->debug(
            message: '[ObjectsProvider] OpenRegister search requested',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'search_query' => $searchQuery,
                'has_search'   => empty($search) === false,
            ]
        );

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
                // Normalize ObjectEntity to array if needed.
                if ($result instanceof \OCA\OpenRegister\Db\ObjectEntity) {
                    $result = $result->jsonSerialize();
                }

                // Extract metadata from @self (jsonSerialize puts metadata there).
                $selfData   = $result['@self'] ?? [];
                $registerId = (int) ($selfData['register'] ?? $result['register'] ?? 0);
                $schemaId   = (int) ($selfData['schema'] ?? $result['schema'] ?? 0);
                $uuid       = $selfData['id'] ?? $result['id'] ?? '';

                // Build a flat data array for deep link URL resolution.
                // The resolveUrl method needs {uuid} and other top-level keys.
                $selfArray = [];
                if (is_array($selfData) === true) {
                    $selfArray = $selfData;
                }

                $flatData = array_merge(
                    $selfArray,
                    ['uuid' => $uuid, 'register' => $registerId, 'schema' => $schemaId]
                );

                // Try deep link registry first, fall back to OpenRegister's own route.
                $objectUrl = $this->deepLinkRegistry->resolveUrl(
                    registerId: $registerId,
                    schemaId: $schemaId,
                    objectData: $flatData
                );
                if ($objectUrl === null) {
                    $objectUrl = $this->urlGenerator->linkToRoute(
                        'openregister.objects.show',
                        ['register' => $registerId, 'schema' => $schemaId, 'id' => $uuid]
                    );
                }

                // Resolve the per-app label for this (register, schema) pair.
                $appLabel = $this->deepLinkRegistry->resolveDisplayName(
                    registerId: $registerId,
                    schemaId: $schemaId
                );

                // Icon precedence:
                // 1. the schema's own MDI icon (an explicit, per-schema choice
                // by the app author), rendered as a self-hosted data: SVG so
                // it renders in the search dropdown and passes the image CSP;
                // 2. the consuming app's registered (rounded) icon;
                // 3. the generic OpenRegister icon class.
                // The rounded avatar style only applies to the registered app
                // icon — a schema glyph is a square monochrome icon.
                // The schema glyph is served from the icon endpoint as a real
                // same-origin SVG URL and passed as the THUMBNAIL, because
                // Nextcloud search only paints a thumbnail from a URL — an
                // icon-class name or a data: URI is not rendered as an image.
                $schemaIconName = $this->resolveSchemaIcon(schemaId: $schemaId);
                $thumbnailUrl   = '';
                if (MdiIconRenderer::has(icon: $schemaIconName) === true) {
                    $thumbnailUrl = $this->urlGenerator->linkToRoute(
                        'openregister.icon.mdi',
                        ['name' => $schemaIconName]
                    );
                    $icon         = 'icon-openregister';
                    $rounded      = false;
                } else {
                    $icon    = $this->deepLinkRegistry->resolveIcon(
                        registerId: $registerId,
                        schemaId: $schemaId
                    ) ?? 'icon-openregister';
                    $rounded = ($appLabel !== null);
                }

                // Create descriptive title and subline.
                $name = $selfData['name'] ?? '';

                $title = 'Unknown Object';
                if (isset($result['title']) === true && is_string($result['title']) === true) {
                    $title = $result['title'];
                } else if (is_string($name) === true && $name !== '') {
                    $title = $name;
                } else if ($uuid !== '') {
                    $title = (string) $uuid;
                }

                $subline = $this->buildSubline(
                    object: $result,
                    registerId: $registerId,
                    schemaId: $schemaId,
                    appLabel: $appLabel,
                    term: $search
                );

                $searchResultEntries[] = new SearchResultEntry(
                    $thumbnailUrl,
                    $title,
                    $subline,
                    $objectUrl,
                    $icon,
                    $rounded
                );
            }//end foreach
        }//end if

        $this->logger->debug(
            message: '[ObjectsProvider] OpenRegister search completed',
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
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
     * @spec openspec/changes/unified-search-provider/specs/unified-search-provider/spec.md
     */
    private function getSectionName(): string
    {
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
     * @spec openspec/changes/unified-search-provider/specs/unified-search-provider/spec.md
     */
    private function getNonSearchableIds(): array
    {
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

    /**
     * Build the result subline: `{Owner} · {Register} · {Schema} — {excerpt}`.
     *
     * The owner label is the deep-link display name for claimed pairs, or
     * `Open Register` for unclaimed pairs. The excerpt is appended when a
     * term-driven match (or fallback summary/description) is available.
     *
     * @param array       $object     The rendered object data.
     * @param int         $registerId The register database ID.
     * @param int         $schemaId   The schema database ID.
     * @param string|null $appLabel   The owning app's display name, or null.
     * @param string|null $term       The search term, or null for filter-only.
     *
     * @return string The composed subline.
     *
     * @spec openspec/changes/unified-search-provider/specs/unified-search-provider/spec.md
     */
    private function buildSubline(
        array $object,
        int $registerId,
        int $schemaId,
        ?string $appLabel,
        ?string $term
    ): string {
        $owner = 'Open Register';
        if ($appLabel !== null && $appLabel !== '') {
            $owner = $appLabel;
        }

        $parts = [$owner];
        if ($registerId > 0) {
            $parts[] = $this->resolveRegisterName(registerId: $registerId);
        }

        if ($schemaId > 0) {
            $parts[] = $this->resolveSchemaName(schemaId: $schemaId);
        }

        $subline = implode(' · ', $parts);

        $excerpt = $this->buildExcerpt(object: $object, term: (string) $term);
        if ($excerpt !== '') {
            $subline .= ' — '.$excerpt;
        }

        return $subline;
    }//end buildSubline()

    /**
     * Build an excerpt around the first occurrence of the term.
     *
     * Walks the object's top-level scalar string values in property order
     * (skipping `@self`), returns ±EXCERPT_CONTEXT chars around the first
     * case-insensitive match of the term (ellipsised, matched substring
     * left verbatim). With no string match — numeric/relational hit or a
     * filter-only browse — falls back to `summary`, then a truncated
     * `description`, then an empty string. The object passed in is the
     * rendered object the user is allowed to read, so field-level security
     * already redacted hidden fields from the excerpt source.
     *
     * @param array  $object The rendered object data.
     * @param string $term   The search term (empty for filter-only browse).
     *
     * @return string The excerpt, or an empty string.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Excerpt walks fields with several optional paths.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Excerpt has multiple fallback branches.
     *
     * @spec openspec/changes/unified-search-provider/specs/unified-search-provider/spec.md
     */
    private function buildExcerpt(array $object, string $term): string
    {
        if ($term !== '') {
            foreach ($object as $key => $value) {
                if ($key === '@self' || is_string($value) === false) {
                    continue;
                }

                $position = mb_stripos($value, $term);
                if ($position === false) {
                    continue;
                }

                return $this->sliceExcerpt(value: $value, position: $position, length: mb_strlen($term));
            }
        }

        // Fallback chain: summary → truncated description → empty.
        if (isset($object['summary']) === true && is_string($object['summary']) === true && $object['summary'] !== '') {
            return $object['summary'];
        }

        if (isset($object['description']) === true && is_string($object['description']) === true && $object['description'] !== '') {
            $description = $object['description'];
            if (mb_strlen($description) > 100) {
                return mb_substr($description, 0, 100).'…';
            }

            return $description;
        }

        return '';
    }//end buildExcerpt()

    /**
     * Cut a ±context window around a match position, ellipsising the edges.
     *
     * @param string $value    The full field value.
     * @param int    $position The byte/char position of the match.
     * @param int    $length   The length of the matched term.
     *
     * @return string The ellipsised fragment with the matched substring verbatim.
     *
     * @spec openspec/changes/unified-search-provider/specs/unified-search-provider/spec.md
     */
    private function sliceExcerpt(string $value, int $position, int $length): string
    {
        $start = max(0, ($position - self::EXCERPT_CONTEXT));
        $end   = min(mb_strlen($value), ($position + $length + self::EXCERPT_CONTEXT));

        $fragment = mb_substr($value, $start, ($end - $start));

        if ($start > 0) {
            $fragment = '…'.$fragment;
        }

        if ($end < mb_strlen($value)) {
            $fragment .= '…';
        }

        return $fragment;
    }//end sliceExcerpt()

    /**
     * Resolve a schema ID to its human-readable title.
     *
     * @param int $schemaId The schema ID
     *
     * @return string The schema title or the ID as fallback
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    private function resolveSchemaName(int $schemaId): string
    {
        $key = 'schema_'.$schemaId;
        if (isset($this->nameCache[$key]) === false) {
            try {
                // Resolve display metadata with tenancy/RBAC bypassed: the
                // result object already passed those gates, and a schema owned by
                // a different organisation than the active one must still resolve
                // its human title (otherwise the result falls back to the bare id).
                $schema = $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false);
                $title  = $schema->getTitle();
                $this->nameCache[$key] = (string) $schemaId;
                if ($title !== null && $title !== '') {
                    $this->nameCache[$key] = $title;
                }
            } catch (\Exception $e) {
                $this->nameCache[$key] = (string) $schemaId;
            }
        }

        return $this->nameCache[$key];
    }//end resolveSchemaName()

    /**
     * Resolve a schema ID to its MDI icon reference (e.g. "Dog"), if set.
     *
     * @param int $schemaId The schema ID
     *
     * @return string|null The schema's icon reference, or null when unset/unknown
     *
     * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
     */
    private function resolveSchemaIcon(int $schemaId): ?string
    {
        $key = 'schemaicon_'.$schemaId;
        if (array_key_exists($key, $this->nameCache) === false) {
            $this->nameCache[$key] = '';
            try {
                // Tenancy/RBAC bypassed for the same reason as resolveSchemaName().
                $icon = $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false)->getIcon();
                if ($icon !== null) {
                    $this->nameCache[$key] = $icon;
                }
            } catch (\Exception $e) {
                $this->nameCache[$key] = '';
            }
        }

        if ($this->nameCache[$key] === '') {
            return null;
        }

        return $this->nameCache[$key];
    }//end resolveSchemaIcon()

    /**
     * Resolve a register ID to its human-readable title.
     *
     * @param int $registerId The register ID
     *
     * @return string The register title or the ID as fallback
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    private function resolveRegisterName(int $registerId): string
    {
        $key = 'register_'.$registerId;
        if (isset($this->nameCache[$key]) === false) {
            try {
                // Tenancy/RBAC bypassed for the same reason as resolveSchemaName().
                $register = $this->registerMapper->find($registerId, _multitenancy: false, _rbac: false);
                $title    = $register->getTitle();
                $this->nameCache[$key] = (string) $registerId;
                if ($title !== null && $title !== '') {
                    $this->nameCache[$key] = $title;
                }
            } catch (\Exception $e) {
                $this->nameCache[$key] = (string) $registerId;
            }
        }

        return $this->nameCache[$key];
    }//end resolveRegisterName()
}//end class
