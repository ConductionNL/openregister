<?php
/**
 * OpenRegister Configuration Service
 *
 * This file contains the service class for handling configuration imports and exports
 * in the OpenRegister application, supporting various formats including OpenAPI.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Ruben Linde <ruben@nextcloud.com>
 * @copyright Copyright (c) 2024, Ruben Linde (https://github.com/rubenlinde)
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/cloud-py-api/openregister
 */

namespace OCA\OpenRegister\Service;

use Exception;
use JsonException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Register;
use OCP\ILogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Class ConfigurationService
 *
 * Service for importing and exporting configurations in various formats
 *
 * @package OCA\OpenRegister\Service
 */
class ConfigurationService {
    /**
     * @var SchemaMapper The schema mapper instance
     */
    private SchemaMapper $schemaMapper;

    /**
     * @var RegisterMapper The register mapper instance
     */
    private RegisterMapper $registerMapper;

    /**
     * @var SchemaPropertyValidator The schema property validator instance
     */
    private SchemaPropertyValidator $validator;

    /**
     * @var LoggerInterface The logger instance
     */
    private LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param SchemaMapper           $schemaMapper   The schema mapper instance
     * @param RegisterMapper         $registerMapper The register mapper instance
     * @param SchemaPropertyValidator $validator     The schema property validator instance
     * @param LoggerInterface        $logger        The logger instance
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        SchemaPropertyValidator $validator,
        LoggerInterface $logger
    ) {
        $this->schemaMapper = $schemaMapper;
        $this->registerMapper = $registerMapper;
        $this->validator = $validator;
        $this->logger = $logger;
    }


    /**
     * Build OpenAPI Specification from configuration
     *
     * @param array $configuration The configuration array to build the OAS from
     * @param bool $includeObjects Whether to include objects in the registers
     *
     * @return array The OpenAPI specification
     *
     * @throws Exception If configuration is invalid
     *
     * @phpstan-param array<string, mixed> $configuration
     * @psalm-param array<string, mixed> $configuration
     */
    private function exportConfig(array $configuration = [], bool $includeObjects = false): array {
        // Initialize OpenAPI specification with default values
        $openApiSpec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $configuration['title'] ?? 'Default Title',
                'description' => $configuration['description'] ?? 'Default Description',
                'version' => $configuration['version'] ?? '1.0.0'
            ],
            'components' => [
                'schemas' => [],
                'registers' => [],
                'endpoints' => [],
                'rules' => [],
                'jobs' => [],
                'sources' => [],
                'objects' => []
            ]
        ];

        // Get all registers associated with this configuration
        $registers = $this->registerMapper->findAll(
            filters: ['configuration' => $configuration['id'] ?? null]
        );

        // Export each register and its schemas
        foreach ($registers as $register) {
            // Set the base register
            $openApiSpec['components']['registers'][$register->getSlug()] = $this->exportRegister($register);
            // Drop the schemas from the register (we need to slugifi those)
            $openApiSpec['components']['registers'][$register->getSlug()]['schemas'] = [];

            // Get and export schemas associated with this register
            $schemas = $this->registerMapper->getSchemasByRegisterId($register->getId());
            foreach ($schemas as $schema) {
                $openApiSpec['components']['schemas'][$schema->getSlug()] = $this->exportSchema($schema);
                $openApiSpec['components']['registers'][$register->getSlug()]['schemas'][] = $schema->getSlug();
            }

            // Optionally include objects in the register
            if ($includeObjects) {
                $objects = $this->registerMapper->getObjectsByRegisterId($register->getId());
                foreach ($objects as $object) {
                    $openApiSpec['components']['objects'][$object->getSlug()] = $this->exportObject($object);
                    $openApiSpec['components']['objects'][$object->getSlug()]['register'] = $register->getSlug();
                    $openApiSpec['components']['objects'][$object->getSlug()]['schema'] = $schema->getSlug();
                }
            }
        }

        return $openApiSpec;
    }

    /**
     * Export a register to OpenAPI format
     *
     * @param Register $register The register to export
     *
     * @return array The OpenAPI register specification
     */
    private function exportRegister(Register $register): array {
        // Use jsonSerialize to get the JSON representation of the register
        return $register->jsonSerialize();
    }

    /**
     * Export a schema to OpenAPI format
     *
     * @param Schema $schema The schema to export
     *
     * @return array The OpenAPI schema specification
     */
    private function exportSchema(Schema $schema): array {
        // Use jsonSerialize to get the JSON representation of the schema
        return $schema->jsonSerialize();
    }

    /**
     * Export an object to OpenAPI format
     *
     * @param ObjectEntity $object The object to export
     *
     * @return array The OpenAPI object specification
     */
    private function exportObject(ObjectEntity $object): array {
        // Use jsonSerialize to get the JSON representation of the object
        return $object->jsonSerialize();
    }

} 