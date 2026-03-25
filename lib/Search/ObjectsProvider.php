<?php

/**
 * OpenRegister ObjectsProvider
 *
 * This file contains the provider class for the objects search.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
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
 * This class implements the IFilteringProvider interface to provide
 * search functionality for objects in the OpenRegister app using the
 * advanced searchObjectsPaginated method for optimal performance.
 */
class ObjectsProvider implements IFilteringProvider
{

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
        $this->l10n             = $l10n;
        $this->urlGenerator     = $urlGenerator;
        $this->objectService    = $objectService;
        $this->logger           = $logger;
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
     */
    public function getId(): string
    {
        return 'openregister_objects';
    }//end getId()

    /**
     * Returns the human-readable name for this search provider
     *
     * @return string Display name for the search provider
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

        // @todo: implement pagination.
        // Note: order parameter not currently used in search
        // Build search query for searchObjectsPaginated.
        $searchQuery = [];

        // Add search term if provided.
        if (empty($search) === false) {
            $searchQuery['_search'] = $search;
        }

        // Add filters to @self metadata section.
        if (empty($register) === false) {
            $searchQuery['@self']['register'] = (int) $register;
        }

        if (empty($schema) === false) {
            $searchQuery['@self']['schema'] = (int) $schema;
        }

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

        // Set pagination limits for Nextcloud search (defaults).
        $searchQuery['_limit']  = 25;
        $searchQuery['_offset'] = 0;

        $this->logger->debug(
            message: '[ObjectsProvider] OpenRegister search requested',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'search_query' => $searchQuery,
                'has_search'   => empty($search) === false,
            ]
        );

        // Use searchObjectsPaginated for optimal performance.
        $searchResults = $this->objectService->searchObjectsPaginated(query: $searchQuery, _rbac: true, _multitenancy: true);

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

                // Use registered app icon or fall back to OpenRegister icon.
                $icon = $this->deepLinkRegistry->resolveIcon(
                    registerId: $registerId,
                    schemaId: $schemaId
                ) ?? 'icon-openregister';

                // Create descriptive title and description.
                $name = $selfData['name'] ?? '';

                $title = 'Unknown Object';
                if (isset($result['title']) === true) {
                    $title = $result['title'];
                } else if ($name !== '') {
                    $title = $name;
                } else if ($uuid !== '') {
                    $title = $uuid;
                }

                $description = $this->buildDescription(object: array_merge($result, $selfData));

                $searchResultEntries[] = new SearchResultEntry(
                    $objectUrl,
                    $title,
                    $description,
                    $objectUrl,
                    $icon
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

        return SearchResult::complete(
            name: $this->l10n->t(text: 'Open Register Objects'),
            entries: $searchResultEntries
        );
    }//end search()

    /**
     * Resolve a schema ID to its human-readable title.
     *
     * @param int $schemaId The schema ID
     *
     * @return string The schema title or the ID as fallback
     */
    private function resolveSchemaName(int $schemaId): string
    {
        $key = 'schema_'.$schemaId;
        if (isset($this->nameCache[$key]) === false) {
            try {
                $schema = $this->schemaMapper->find($schemaId);
                $this->nameCache[$key] = $schema->getTitle() ?: (string) $schemaId;
            } catch (\Exception $e) {
                $this->nameCache[$key] = (string) $schemaId;
            }
        }

        return $this->nameCache[$key];
    }//end resolveSchemaName()

    /**
     * Resolve a register ID to its human-readable title.
     *
     * @param int $registerId The register ID
     *
     * @return string The register title or the ID as fallback
     */
    private function resolveRegisterName(int $registerId): string
    {
        $key = 'register_'.$registerId;
        if (isset($this->nameCache[$key]) === false) {
            try {
                $register = $this->registerMapper->find($registerId);
                $this->nameCache[$key] = $register->getTitle() ?: (string) $registerId;
            } catch (\Exception $e) {
                $this->nameCache[$key] = (string) $registerId;
            }
        }

        return $this->nameCache[$key];
    }//end resolveRegisterName()

    /**
     * Build a descriptive text for search results
     *
     * @param array $object Object data from searchObjectsPaginated
     *
     * @return string Formatted description for search result
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Description building requires multiple optional field checks
     * @SuppressWarnings(PHPMD.NPathComplexity)      Description building has multiple optional data paths
     */
    private function buildDescription(array $object): string
    {
        $parts = [];

        // Add schema/register names (resolved from IDs) if available.
        if (empty($object['schema']) === false) {
            $parts[] = $this->resolveSchemaName((int) $object['schema']);
        }

        if (empty($object['register']) === false) {
            $parts[] = $this->resolveRegisterName((int) $object['register']);
        }

        // Add summary/description if available.
        if (empty($object['summary']) === false) {
            $parts[] = $object['summary'];
        } else if (empty($object['description']) === false && is_string($object['description']) === true) {
            $descriptionPart = substr($object['description'], 0, 100);
            if (strlen($object['description']) > 100) {
                $descriptionPart .= '...';
            }

            $parts[] = $descriptionPart;
        }

        // Add last updated info if available.
        if (empty($object['updated']) === false) {
            $parts[] = $this->l10n->t('Updated: %s', date('Y-m-d H:i', strtotime($object['updated'])));
        }

        $description = implode(' • ', $parts);
        if ($description !== '') {
            return $description;
        }

        return $this->l10n->t(text: 'Open Register Object');
    }//end buildDescription()
}//end class
