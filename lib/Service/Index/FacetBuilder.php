<?php

declare(strict_types=1);

/*
 * FacetBuilder
 *
 * Handles Solr facet building operations.
 * Extracted from GuzzleSolrService to separate facet logic.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-12
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Index;

use OCA\OpenRegister\Service\Index\Backends\SolrBackend;
use Psr\Log\LoggerInterface;

/**
 * FacetBuilder for Solr facet operations
 *
 * PRAGMATIC APPROACH: Initially delegates to SolrBackend.
 * Methods will be migrated incrementally.
 *
 * @package OCA\OpenRegister\Service\Index
 */
class FacetBuilder
{

    /**
     * Solr backend service (temporary delegation).
     *
     * @var SolrBackend
     */
    private readonly SolrBackend $solrBackend;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;


    /**
     * FacetBuilder constructor
     *
     * @param SolrBackend     $solrBackend Backend implementation
     * @param LoggerInterface $logger      Logger
     *
     * @return void
     */
    public function __construct(
        SolrBackend $solrBackend,
        LoggerInterface $logger
    ) {
        $this->solrBackend = $solrBackend;
        $this->logger      = $logger;

    }//end __construct()


    /**
     * Get facetable fields for configuration
     *
     * @return array Facetable fields
     */
    public function getRawSolrFieldsForFacetConfiguration(): array
    {
        $this->logger->debug('FacetBuilder: Delegating to SolrBackend');

        return $this->solrBackend->getRawSolrFieldsForFacetConfiguration();

    }//end getRawSolrFieldsForFacetConfiguration()


}//end class
