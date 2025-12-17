<?php

/**
 * SolrFacetProcessor
 *
 * Handles facet processing operations for Solr.
 * Manages facet discovery, building, and formatting.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index\Backends\Solr
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index\Backends\Solr;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * SolrFacetProcessor
 *
 * Processes facets for search results.
 *
 * NOTE: This is a simplified initial implementation.
 * Full facet processing (25+ methods) can be migrated from SolrBackend incrementally.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index\Backends\Solr
 */
class SolrFacetProcessor
{

    /**
     * HTTP client.
     *
     * @var SolrHttpClient
     */
    private readonly SolrHttpClient $httpClient;

    /**
     * Collection manager.
     *
     * @var SolrCollectionManager
     */
    private readonly SolrCollectionManager $collectionManager;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param SolrHttpClient        $httpClient        HTTP client
     * @param SolrCollectionManager $collectionManager Collection manager
     * @param LoggerInterface       $logger            Logger
     *
     * @return void
     */
    public function __construct(
        SolrHttpClient $httpClient,
        SolrCollectionManager $collectionManager,
        LoggerInterface $logger
    ) {
        $this->httpClient        = $httpClient;
        $this->collectionManager = $collectionManager;
        $this->logger            = $logger;

    }//end __construct()


    /**
     * Get facetable fields from Solr schema.
     *
     * NOTE: This is a simplified implementation.
     * Full implementation with 25+ facet methods can be migrated from SolrBackend.
     *
     * @return array Facetable fields
     */
    public function getRawSolrFieldsForFacetConfiguration(): array
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            $this->logger->warning('[SolrFacetProcessor] No active collection');
            return [];
        }

        try {
            $url  = $this->httpClient->getEndpointUrl($collection).'/schema/fields?wt=json';
            $data = $this->httpClient->get($url);

            $fields    = $data['fields'] ?? [];
            $facetable = [];

            foreach ($fields as $field) {
                // Fields with _s, _ss, _i suffixes are typically facetable.
                $name = $field['name'] ?? '';
                if (str_ends_with($name, '_s') === true || str_ends_with($name, '_ss') === true || str_ends_with($name, '_i') === true) {
                    $facetable[] = [
                        'name' => $name,
                        'type' => $field['type'] ?? 'unknown',
                    ];
                }
            }

            $this->logger->debug(
                    '[SolrFacetProcessor] Found facetable fields',
                    [
                        'count' => count($facetable),
                    ]
                    );

            return $facetable;
        } catch (Exception $e) {
            $this->logger->error(
                    '[SolrFacetProcessor] Failed to get facetable fields',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );
            return [];
        }//end try

    }//end getRawSolrFieldsForFacetConfiguration()


    /**
     * Build facet query for search.
     *
     * @param array $facetFields Fields to facet on
     *
     * @return array Facet query parameters
     */
    public function buildFacetQuery(array $facetFields): array
    {
        if (empty($facetFields) === true) {
            return [];
        }

        return [
            'facet'       => 'true',
            'facet.field' => $facetFields,
            'facet.limit' => 100,
        ];

    }//end buildFacetQuery()


    /**
     * Process facet response from Solr.
     *
     * @param array $solrResponse Solr search response with facets
     *
     * @return array Processed facets
     */
    public function processFacetResponse(array $solrResponse): array
    {
        $facetCounts = $solrResponse['facet_counts'] ?? [];
        $facetFields = $facetCounts['facet_fields'] ?? [];

        $processed = [];

        foreach ($facetFields as $fieldName => $values) {
            $items = [];

            // Solr returns facets as [value1, count1, value2, count2, ...].
            for ($i = 0; $i < count($values); $i += 2) {
                if (isset($values[$i], $values[$i + 1]) === true) {
                    $items[] = [
                        'value' => $values[$i],
                        'count' => $values[$i + 1],
                    ];
                }
            }

            if (empty($items) === false) {
                $processed[$fieldName] = [
                    'field' => $fieldName,
                    'items' => $items,
                ];
            }
        }

        return $processed;

    }//end processFacetResponse()


}//end class
