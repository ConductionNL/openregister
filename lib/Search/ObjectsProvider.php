<?php
/**
 * OpenRegister ObjectsProvider
 *
 * This file contains the provider class for the objects search.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\FilterDefinition;
use OCP\Search\IFilteringProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * ObjectsProvider class for the objects search.
 *
 * This class implements the IFilteringProvider interface to provide
 * search functionality for objects in the OpenRegister app.
 *
 * @psalm-suppress MissingConstructor
 */
class ObjectsProvider implements IFilteringProvider
{

    /**
     * The localization service
     *
     * @var IL10N
     */
    private IL10N $l10n;

    /**
     * The URL generator service
     *
     * @var IURLGenerator
     */
    private IURLGenerator $urlGenerator;


    /**
     * Constructor for the ObjectsProvider class
     *
     * @param IL10N         $l10n         The localization service
     * @param IURLGenerator $urlGenerator The URL generator service
     *
     * @return void
     */
    public function __construct(IL10N $l10n, IURLGenerator $urlGenerator)
    {
        $this->l10n         = $l10n;
        $this->urlGenerator = $urlGenerator;

    }//end __construct()


    /**
     * Returns the list of supported filters for the search provider
     *
     * @return string[] List of supported filter names
     *
     * @psalm-return array<string>
     *
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
     * @return string[] List of alternate IDs
     *
     * @psalm-return array<string>
     *
     * @phpstan-return array<string>
     */
    public function getAlternateIds(): array
    {
        return [];

    }//end getAlternateIds()


    /**
     * Returns the list of custom filters for the search provider
     *
     * @return FilterDefinition[] List of custom filter definitions
     *
     * @psalm-return array<FilterDefinition>
     *
     * @phpstan-return array<FilterDefinition>
     */
    public function getCustomFilters(): array
    {
        return [
            new FilterDefinition('register', FilterDefinition::TYPE_STRING),
            new FilterDefinition('schema', FilterDefinition::TYPE_STRING),
        ];

    }//end getCustomFilters()


    /**
     * Performs a search based on the provided query
     *
     * @param IUser        $user  The user performing the search
     * @param ISearchQuery $query The search query
     *
     * @return SearchResult The search results
     *
     * @psalm-suppress PropertyNotSetInConstructor
     *
     * @phpstan-ignore-next-line
     */
    public function search(IUser $user, ISearchQuery $query): SearchResult
    {
        // Retrieve filters.
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
        $limit  = null;
        $offset = null;
        $order  = null;

        // Get the objects.
        $results = $this->objectEntityMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            sort: $order,
            search: $search
        );

        // Convert results to SearchResult.
        $searchResultEntries = [];
        foreach ($results as $result) {
            $searchResultEntries[] = new SearchResultEntry(
                $this->urlGenerator->linkToRoute(
                    'openregister.objects.show',
                    ['id' => $result->getUuid()]
                ),
                $result->getUuid(),
                'An Open Register Object',
                // @todo: add register and schema to the description
                $this->urlGenerator->linkToRoute(
                    'openregister.objects.show',
                    ['id' => $result->getUuid()]
                )
            );
        }

        return SearchResult::complete(
            $this->l10n->t('Open Register'),
            $searchResultEntries
        );

    }//end search()


}//end class
