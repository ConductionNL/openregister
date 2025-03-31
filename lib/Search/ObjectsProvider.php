<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Search;

use OCP\IUser;
use OCP\Search\FilterDefinition;
use OCP\Search\IFilteringProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;
use OCP\IL10N;
use OCP\IURLGenerator;

use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\SearchService;
// Todo do we need thisclass ObjectsProvider implements IFilteringProvider
{

    private IL10N $l10n;

    private IURLGenerator $urlGenerator;


    public function __construct(IL10N $l10n, IURLGenerator $urlGenerator)
    {
        $this->l10n         = $l10n;
        $this->urlGenerator = $urlGenerator;

    }//end __construct()


    public function getSupportedFilters(): array
    {
        return [
            // Generic
            'term',
            'since',
            'until',
            'person',
            // Open Register Specific
            'register',
            'schema',
        ];

    }//end getSupportedFilters()


    public function getAlternateIds(): array
    {
        return [];

    }//end getAlternateIds()


    public function getCustomFilters(): array
    {
        return [
            new FilterDefinition('register', FilterDefinition::TYPE_STRING),
            new FilterDefinition('schema', FilterDefinition::TYPE_STRING),
        ];

    }//end getCustomFilters()


    public function search(IUser $user, ISearchQuery $query): SearchResult
    {
        // Retrieve filters
        $filters = [];
        /*
         * @var $register ?string
         */
        $register = $query->getFilter('register')?->get();
        if ($register !== null) {
            $filters['register'] = $register;
        }

        $schema = $query->getFilter('schema')?->get();
        if ($schema !== null) {
            $filters['schema'] = $schema;
        }

        /*
         * @var $term ?string
         */
        $search = $query->getFilter('term')?->get();
        /*
         * @var $since ?string
         */
        $since = $query->getFilter('since')?->get();
        /*
         * @var $until ?string
         */
        $until = $query->getFilter('until')?->get();

        // @todo: implement pagination
        $limit  = null;
        $offset = null;
        $order  = null;

        // Get the objects
        $results = $this->objectEntityMapper->findAll(
            limit: $limit,
            offset: $offset,
            filters: $filters,
            sort: $order,
            search: $search
        );

        // Convert results to SearchResult
        $searchResultEntries = [];
        foreach ($results as $result) {
            $searchResultEntries[] = new SearchResultEntry(
                $this->urlGenerator->linkToRoute(
                    'openregister.objects.show',
                    ['id' => $result->getUuid()]
                ),
                $result->getUuid(),
                'An Open Register Object',
            // @todo: add regsiter and schema to the description
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
