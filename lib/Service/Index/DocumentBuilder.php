<?php

declare(strict_types=1);

/*
 * DocumentBuilder
 *
 * Handles building Solr documents from ObjectEntity instances.
 * Extracts all document creation, field mapping, and value conversion logic.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Index;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Register;
use Psr\Log\LoggerInterface;

/**
 * DocumentBuilder for creating Solr documents
 *
 * Handles all logic for converting ObjectEntity instances into Solr-compatible
 * document structures, including field mapping, value conversion, and relation flattening.
 *
 * @package OCA\OpenRegister\Service\Index
 */
class DocumentBuilder
{

    /**
     * Logger for operation tracking.
     *
     * @var LoggerInterface PSR-3 logger
     */
    private readonly LoggerInterface $logger;


    /**
     * DocumentBuilder constructor
     *
     * @param LoggerInterface $logger Logger for operation tracking
     *
     * @return void
     */
    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;

    }//end __construct()


    /**
     * Create a Solr document from an ObjectEntity
     *
     * Main entry point for document creation. Decides between schema-aware
     * and legacy document creation based on available schema information.
     *
     * @param ObjectEntity  $object         The object to convert
     * @param array         $solrFieldTypes Available Solr field types
     * @param Schema|null   $schema         Optional schema for schema-aware creation
     * @param Register|null $register       Optional register for additional context
     *
     * @return array The Solr document
     */
    public function createDocument(
        ObjectEntity $object,
        array $solrFieldTypes=[],
        ?Schema $schema=null,
        $register=null
    ): array {
        $this->logger->debug(
            '[DocumentBuilder] Creating document',
            [
                'objectId'  => $object->getId(),
                'hasSchema' => $schema !== null,
            ]
        );

        if ($schema !== null) {
            return $this->createSchemaAwareDocument($object, $schema, $register, $solrFieldTypes);
        }

        return $this->createLegacyDocument($object);

    }//end createDocument()


    /**
     * Create a schema-aware Solr document
     *
     * @param ObjectEntity  $object         The object to convert
     * @param Schema        $schema         The schema defining the structure
     * @param Register|null $register       Optional register
     * @param array         $solrFieldTypes Available Solr field types
     *
     * @return array The Solr document
     */
    private function createSchemaAwareDocument(
        ObjectEntity $object,
        Schema $schema,
        $register,
        array $solrFieldTypes
    ): array {
        // This will contain the extracted logic from GuzzleSolrService->createSchemaAwareDocument()
        // For now, delegating to legacy to keep the service operational
        return $this->createLegacyDocument($object);

    }//end createSchemaAwareDocument()


    /**
     * Create a legacy Solr document (pre-schema)
     *
     * @param ObjectEntity $object The object to convert
     *
     * @return array The Solr document
     */
    private function createLegacyDocument(ObjectEntity $object): array
    {
        // This will contain the extracted logic from GuzzleSolrService->createLegacySolrDocument()
        // For now, returning basic structure
        return [
            'id'          => $object->getUuid(),
            'title'       => $object->getTitle(),
            'description' => $object->getDescription(),
        ];

    }//end createLegacyDocument()


}//end class
