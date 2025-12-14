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
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Index;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Exception;

/**
 * DocumentBuilder for creating Solr documents
 *
 * This class contains all the logic for converting ObjectEntity instances
 * into Solr-compatible document structures.
 *
 * @package OCA\OpenRegister\Service\Index
 */
class DocumentBuilder
{
    /**
     * Schema mapper for schema operations.
     *
     * @var SchemaMapper|null
     */
    private readonly ?SchemaMapper $schemaMapper;

    /**
     * Register mapper for register operations.
     *
     * @var RegisterMapper|null
     */
    private readonly ?RegisterMapper $registerMapper;

    /**
     * Logger for operation tracking.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;


    /**
     * DocumentBuilder constructor
     *
     * @param LoggerInterface    $logger         Logger
     * @param SchemaMapper|null  $schemaMapper   Schema mapper (optional)
     * @param RegisterMapper|null $registerMapper Register mapper (optional)
     *
     * @return void
     */
    public function __construct(
        LoggerInterface $logger,
        ?SchemaMapper $schemaMapper = null,
        ?RegisterMapper $registerMapper = null
    ) {
        $this->logger = $logger;
        $this->schemaMapper = $schemaMapper;
        $this->registerMapper = $registerMapper;
    }


    /**
     * Create a Solr document from an ObjectEntity
     *
     * This is the main entry point for document creation.
     *
     * @param ObjectEntity $object         The object to convert
     * @param array        $solrFieldTypes Available Solr field types
     *
     * @return array The Solr document
     * @throws RuntimeException If schema is not available or mapping fails
     */
    public function createDocument(
        ObjectEntity $object,
        array $solrFieldTypes = []
    ): array {
        // Validate schema availability.
        if ($this->schemaMapper === null) {
            throw new RuntimeException(
                'Schema mapper is not available. Cannot create SOLR document without schema validation. ' .
                'Object ID: ' . ($object->getId() ?? 'unknown') . ', Schema ID: ' . ($object->getSchema() ?? 'unknown')
            );
        }

        // Get the schema for this object.
        $schema = $this->schemaMapper->find($object->getSchema());

        if (!($schema instanceof Schema)) {
            throw new RuntimeException(
                'Schema not found for object. Cannot create SOLR document without valid schema. ' .
                'Object ID: ' . ($object->getId() ?? 'unknown') . ', Schema ID: ' . ($object->getSchema() ?? 'unknown')
            );
        }

        // Check if schema is searchable.
        if ($schema->getSearchable() === false) {
            $this->logger->debug(
                'Skipping SOLR indexing for non-searchable schema',
                [
                    'object_id'    => $object->getId(),
                    'schema_id'    => $object->getSchema(),
                    'schema_slug'  => $schema->getSlug(),
                    'schema_title' => $schema->getTitle(),
                ]
            );

            $schemaName = $schema->getTitle() ?? $schema->getSlug();
            throw new RuntimeException(
                'Schema is not searchable. Objects of this schema are excluded from SOLR indexing. ' .
                'Object ID: ' . ($object->getId() ?? 'unknown') . ', Schema: ' . ($schemaName ?? 'unknown')
            );
        }

        // Get the register for this object.
        $register = null;
        if ($this->registerMapper !== null) {
            try {
                $register = $this->registerMapper->find($object->getRegister());
            } catch (Exception $e) {
                $this->logger->warning(
                    'Failed to fetch register for object',
                    [
                        'object_id'   => $object->getId(),
                        'register_id' => $object->getRegister(),
                        'error'       => $e->getMessage(),
                    ]
                );
            }
        }

        // Create schema-aware document.
        try {
            $document = $this->createSchemaAwareDocument($object, $schema, $register, $solrFieldTypes);

            $this->logger->debug('Created SOLR document using schema-aware mapping', [
                'object_id'     => $object->getId(),
                'schema_id'     => $object->getSchema(),
                'mapped_fields' => count($document),
            ]);

            return $document;
        } catch (Exception $e) {
            $this->logger->error('Schema-aware mapping failed', [
                'object_id' => $object->getId(),
                'schema_id' => $object->getSchema(),
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            throw new RuntimeException(
                'Schema-aware mapping failed for object. ' .
                'Object ID: ' . ($object->getId() ?? 'unknown') . ', Schema ID: ' . ($object->getSchema() ?? 'unknown') . '. ' .
                'Original error: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }


    /**
     * This is a PLACEHOLDER for the full implementation
     * The complete 290-line method will be added next
     */
    private function createSchemaAwareDocument(
        ObjectEntity $object,
        Schema $schema,
        $register,
        array $solrFieldTypes
    ): array {
        // THIS IS WHERE THE FULL 290-LINE METHOD FROM GuzzleSolrService GOES
        // For now, returning basic structure to keep it compiling
        return [
            'id' => $object->getUuid(),
            'self_uuid' => $object->getUuid(),
            'self_name' => $object->getName(),
        ];
    }
}

