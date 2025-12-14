<?php

declare(strict_types=1);

/**
 * DocumentBuilder
 *
 * Handles building Solr documents from ObjectEntity instances.
 * Extracted from GuzzleSolrService to separate document creation logic.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\GuzzleSolrService;
use Psr\Log\LoggerInterface;

/**
 * DocumentBuilder for creating Solr documents
 *
 * PRAGMATIC APPROACH: This class initially delegates to GuzzleSolrService
 * for backward compatibility, then we'll migrate methods incrementally.
 *
 * @package OCA\OpenRegister\Service\Index
 */
class DocumentBuilder
{
    /**
     * Guzzle Solr service for document creation (temporary delegation).
     *
     * @var GuzzleSolrService
     */
    private readonly GuzzleSolrService $guzzleSolrService;

    /**
     * Logger for operation tracking.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;


    /**
     * DocumentBuilder constructor
     *
     * @param GuzzleSolrService   $guzzleSolrService The backend implementation
     * @param LoggerInterface     $logger            Logger
     * @param SchemaMapper|null   $schemaMapper      Schema mapper (unused for now)
     * @param RegisterMapper|null $registerMapper    Register mapper (unused for now)
     *
     * @return void
     */
    public function __construct(
        GuzzleSolrService $guzzleSolrService,
        LoggerInterface $logger,
        ?SchemaMapper $schemaMapper = null,
        ?RegisterMapper $registerMapper = null
    ) {
        $this->guzzleSolrService = $guzzleSolrService;
        $this->logger = $logger;
        
        // Store for future use when we fully migrate
        // Currently we delegate to GuzzleSolrService
    }


    /**
     * Create a Solr document from an ObjectEntity
     *
     * PRAGMATIC: Initially delegates to GuzzleSolrService.createSolrDocument()
     * This allows us to have the handler structure in place while maintaining
     * full backward compatibility.
     *
     * TODO: Extract the actual implementation from GuzzleSolrService incrementally
     *
     * @param ObjectEntity $object         The object to convert
     * @param array        $solrFieldTypes Available Solr field types
     *
     * @return array The Solr document
     * @throws \RuntimeException If schema is not available or mapping fails
     */
    public function createDocument(
        ObjectEntity $object,
        array $solrFieldTypes = []
    ): array {
        $this->logger->debug('DocumentBuilder: Delegating to GuzzleSolrService (temporary)', [
            'object_id' => $object->getId(),
            'method' => 'createDocument'
        ]);

        // Delegate to GuzzleSolrService for now
        return $this->guzzleSolrService->createSolrDocument($object, $solrFieldTypes);
    }


}//end class
