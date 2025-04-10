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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
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
class ConfigurationService
{

    /**
     * @var SchemaMapper The schema mapper instance
     */
    private SchemaMapper $schemaMapper;

    /**
     * @var RegisterMapper The register mapper instance
     */
    private RegisterMapper $registerMapper;

    /**
     * @var ObjectMapper The object mapper instance
     */
    private ObjectMapper $objectMapper;

    /**
     * @var SchemaPropertyValidator The schema property validator instance
     */
    private SchemaPropertyValidator $validator;

    /**
     * @var LoggerInterface The logger instance
     */
    private LoggerInterface $logger;

    /**
     * @var array<string, Register> Registers indexed by slug during import, by id during export
     */
    private array $registersMap = [];

    /**
     * @var array<string, Schema> Schemas indexed by slug during import, by id during export
     */
    private array $schemasMap = [];


    /**
     * Constructor
     *
     * @param SchemaMapper            $schemaMapper   The schema mapper instance
     * @param RegisterMapper          $registerMapper The register mapper instance
     * @param ObjectMapper            $objectMapper   The object mapper instance
     * @param SchemaPropertyValidator $validator      The schema property validator instance
     * @param LoggerInterface         $logger         The logger instance
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        ObjectMapper $objectMapper,
        SchemaPropertyValidator $validator,
        LoggerInterface $logger
    ) {
        $this->schemaMapper   = $schemaMapper;
        $this->registerMapper = $registerMapper;
        $this->objectMapper   = $objectMapper;
        $this->validator      = $validator;
        $this->logger         = $logger;

    }//end __construct()


    /**
     * Build OpenAPI Specification from configuration or register
     *
     * @param array|Configuration|Register $input          The configuration array, Configuration object, or Register object to build the OAS from
     * @param bool                         $includeObjects Whether to include objects in the registers
     *
     * @return array The OpenAPI specification
     *
     * @throws Exception If configuration is invalid
     *
     * @phpstan-param array<string, mixed>|Configuration|Register $input
     * @psalm-param   array<string, mixed>|Configuration|Register $input
     */
    private function exportConfig(array | Configuration | Register $input=[], bool $includeObjects=false): array
    {
        // Reset the maps for this export
        $this->registersMap = [];
        $this->schemasMap   = [];

        // Initialize OpenAPI specification with default values
        $openApiSpec = [
            'openapi'    => '3.0.0',
            'components' => [
                'schemas'   => [],
                'registers' => [],
                'endpoints' => [],
                'rules'     => [],
                'jobs'      => [],
                'sources'   => [],
                'objects'   => [],
            ],
        ];

        // Determine if input is an array, Configuration, or Register object
        if ($input instanceof Configuration) {
            // Get all registers associated with this configuration
            $registers = $this->registerMapper->findAll(
                filters: ['configuration' => $input->getId()]
            );
            // Set the info from the configuration
            $openApiSpec['info'] = [
                'id'          => $input->getId(),
                'title'       => $input->getTitle(),
                'description' => $input->getDescription(),
                'version'     => $input->getVersion(),
            ];
        } else if ($input instanceof Register) {
            // Pass the register as an array to the exportConfig function
            $registers = [$input];
            // Set the info from the register
            $openApiSpec['info'] = [
                'id'          => $input->getId(),
                'title'       => $input->getTitle(),
                'description' => $input->getDescription(),
                'version'     => $input->getVersion(),
            ];
        } else {
            // Get all registers associated with this configuration
            $registers = $this->registerMapper->findAll(
                filters: ['configuration' => $input['id'] ?? null]
            );
            // Set the info from the configuration
            $openApiSpec['info'] = [
                'title'       => $input['title'] ?? 'Default Title',
                'description' => $input['description'] ?? 'Default Description',
                'version'     => $input['version'] ?? '1.0.0',
            ];
        }//end if

        // Export each register and its schemas
        foreach ($registers as $register) {
            // Store register in map by ID for reference
            $this->registersMap[$register->getId()] = $register;

            // Set the base register
            $openApiSpec['components']['registers'][$register->getSlug()] = $this->exportRegister($register);
            // Drop the schemas from the register (we need to slugify those)
            $openApiSpec['components']['registers'][$register->getSlug()]['schemas'] = [];

            // Get and export schemas associated with this register
            $schemas = $this->registerMapper->getSchemasByRegisterId($register->getId());
            foreach ($schemas as $schema) {
                // Store schema in map by ID for reference
                $this->schemasMap[$schema->getId()] = $schema;

                $openApiSpec['components']['schemas'][$schema->getSlug()] = $this->exportSchema($schema);
                $openApiSpec['components']['registers'][$register->getSlug()]['schemas'][] = $schema->getSlug();
            }

            // Optionally include objects in the register
            if ($includeObjects) {
                $objects = $this->registerMapper->getObjectsByRegisterId($register->getId());
                foreach ($objects as $object) {
                    $openApiSpec['components']['objects'][$object->getSlug()] = $this->exportObject($object);
                    // Use maps to get slugs
                    $openApiSpec['components']['objects'][$object->getSlug()]['register'] = $this->registersMap[$object->getRegisterId()]->getSlug();
                    $openApiSpec['components']['objects'][$object->getSlug()]['schema']   = $this->schemasMap[$object->getSchemaId()]->getSlug();
                }
            }
        }//end foreach

        return $openApiSpec;

    }//end exportConfig()


    /**
     * Export a register to OpenAPI format
     *
     * @param Register $register The register to export
     *
     * @return array The OpenAPI register specification
     */
    private function exportRegister(Register $register): array
    {
        // Use jsonSerialize to get the JSON representation of the register
        return $register->jsonSerialize();

    }//end exportRegister()


    /**
     * Export a schema to OpenAPI format
     *
     * @param Schema $schema The schema to export
     *
     * @return array The OpenAPI schema specification
     */
    private function exportSchema(Schema $schema): array
    {
        // Use jsonSerialize to get the JSON representation of the schema
        return $schema->jsonSerialize();

    }//end exportSchema()


    /**
     * Export an object to OpenAPI format
     *
     * @param ObjectEntity $object The object to export
     *
     * @return array The OpenAPI object specification
     */
    private function exportObject(ObjectEntity $object): array
    {
        // Use jsonSerialize to get the JSON representation of the object
        return $object->jsonSerialize();

    }//end exportObject()


    /**
     * Import configuration from a JSON file
     *
     * @param string      $jsonContent    The configuration JSON content
     * @param bool        $includeObjects Whether to include objects in the import
     * @param string|null $owner          The owner of the schemas and registers
     *
     * @throws JsonException If JSON parsing fails
     * @throws Exception If schema validation fails or format is unsupported
     * @return array Array of created/updated entities
     */
    public function importFromJson(string $jsonContent, bool $includeObjects=false, ?string $owner=null): array
    {
        // Reset the maps for this import
        $this->registersMap = [];
        $this->schemasMap   = [];

        try {
            $data = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('Failed to parse JSON: '.$e->getMessage());
            throw new Exception('Invalid JSON format: '.$e->getMessage());
        }

        $result = [
            'registers' => [],
            'schemas'   => [],
            'objects'   => [],
        ];

        // Import schemas first
        if (isset($data['components']['schemas'])) {
            foreach ($data['components']['schemas'] as $slug => $schemaData) {
                $schema = $this->importSchema($schemaData, $owner);
                if ($schema) {
                    // Store schema in map by slug for reference
                    $this->schemasMap[$slug] = $schema;
                    $result['schemas'][]     = $schema;
                }
            }
        }

        // Import registers after schemas so we can link them
        if (isset($data['components']['registers'])) {
            foreach ($data['components']['registers'] as $slug => $registerData) {
                // Convert schema slugs to IDs
                if (isset($registerData['schemas']) && is_array($registerData['schemas'])) {
                    $schemaIds = [];
                    foreach ($registerData['schemas'] as $schemaSlug) {
                        if (isset($this->schemasMap[$schemaSlug])) {
                            $schemaIds[] = $this->schemasMap[$schemaSlug]->getId();
                        } else {
                            $this->logger->warning('Schema with slug '.$schemaSlug.' not found during register import');
                        }
                    }

                    $registerData['schemas'] = $schemaIds;
                }

                $register = $this->importRegister($registerData, $owner);
                if ($register) {
                    // Store register in map by slug for reference
                    $this->registersMap[$slug] = $register;
                    $result['registers'][]     = $register;
                }
            }//end foreach
        }//end if

        // Import objects if includeObjects is true
        if ($includeObjects && isset($data['components']['objects'])) {
            foreach ($data['components']['objects'] as $slug => $objectData) {
                // Use maps to get IDs from slugs
                if (isset($objectData['register']) && isset($this->registersMap[$objectData['register']])) {
                    $objectData['registerId'] = $this->registersMap[$objectData['register']]->getId();
                } else {
                    $this->logger->warning('Register with slug '.$objectData['register'].' not found during object import');
                    continue;
                    // Skip this object as we can't link it to a register
                }

                if (isset($objectData['schema']) && isset($this->schemasMap[$objectData['schema']])) {
                    $objectData['schemaId'] = $this->schemasMap[$objectData['schema']]->getId();
                } else {
                    $this->logger->warning('Schema with slug '.$objectData['schema'].' not found during object import');
                    continue;
                    // Skip this object as we can't link it to a schema
                }

                $object = $this->importObject($objectData, $owner);
                if ($object) {
                    $result['objects'][] = $object;
                }
            }//end foreach
        }//end if

        return $result;

    }//end importFromJson()


    /**
     * Import a register from configuration data
     *
     * @param array       $data  The register data
     * @param string|null $owner The owner of the register
     *
     * @return Register|null The imported register or null if skipped
     */
    private function importRegister(array $data, ?string $owner=null): ?Register
    {
        try {
            // Check if register already exists by slug
            $existingRegister = null;
            try {
                $existingRegister = $this->registerMapper->findBySlug($data['slug']);
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Register doesn't exist, we'll create a new one
            }

            if ($existingRegister) {
                // Compare versions using version_compare for proper semver comparison
                if (version_compare($data['version'], $existingRegister->getVersion(), '<=')) {
                    $this->logger->info('Skipping register import as existing version is newer or equal');
                    return null;
                }

                // Update existing register
                $existingRegister->hydrate($data);
                if ($owner) {
                    $existingRegister->setOwner($owner);
                }

                return $this->registerMapper->update($existingRegister);
            }

            // Create new register
            $register = new Register();
            $register->hydrate($data);
            if ($owner) {
                $register->setOwner($owner);
            }

            return $this->registerMapper->insert($register);
        } catch (Exception $e) {
            $this->logger->error('Failed to import register: '.$e->getMessage());
            throw new Exception('Failed to import register: '.$e->getMessage());
        }//end try

    }//end importRegister()


    /**
     * Import a schema from configuration data
     *
     * @param array       $data  The schema data
     * @param string|null $owner The owner of the schema
     *
     * @return Schema|null The imported schema or null if skipped
     */
    private function importSchema(array $data, ?string $owner=null): ?Schema
    {
        try {
            // Check if schema already exists by slug
            $existingSchema = null;
            try {
                $existingSchema = $this->schemaMapper->findBySlug($data['slug']);
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Schema doesn't exist, we'll create a new one
            }

            if ($existingSchema) {
                // Compare versions using version_compare for proper semver comparison
                if (version_compare($data['version'], $existingSchema->getVersion(), '<=')) {
                    $this->logger->info('Skipping schema import as existing version is newer or equal');
                    return null;
                }

                // Update existing schema
                $existingSchema->hydrate($data, $this->validator);
                if ($owner) {
                    $existingSchema->setOwner($owner);
                }

                return $this->schemaMapper->update($existingSchema);
            }

            // Create new schema
            $schema = new Schema();
            $schema->hydrate($data, $this->validator);
            if ($owner) {
                $schema->setOwner($owner);
            }

            return $this->schemaMapper->insert($schema);
        } catch (Exception $e) {
            $this->logger->error('Failed to import schema: '.$e->getMessage());
            throw new Exception('Failed to import schema: '.$e->getMessage());
        }//end try

    }//end importSchema()


    /**
     * Import an object from configuration data
     *
     * @param array       $data  The object data
     * @param string|null $owner The owner of the object
     *
     * @return ObjectEntity|null The imported object or null if skipped
     */
    private function importObject(array $data, ?string $owner=null): ?ObjectEntity
    {
        try {
            // Check if object already exists by UUID
            $existingObject = null;
            try {
                $existingObject = $this->objectMapper->findByUuid($data['uuid']);
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Object doesn't exist, we'll create a new one
            }

            if ($existingObject) {
                // Compare versions using version_compare for proper semver comparison
                if (version_compare($data['version'], $existingObject->getVersion(), '<=')) {
                    $this->logger->info('Skipping object import as existing version is newer or equal');
                    return null;
                }

                // Update existing object
                $existingObject->hydrate($data);
                if ($owner) {
                    $existingObject->setOwner($owner);
                }

                return $this->objectMapper->update($existingObject);
            }

            // Create new object
            $object = new ObjectEntity();
            $object->hydrate($data);
            if ($owner) {
                $object->setOwner($owner);
            }

            return $this->objectMapper->insert($object);
        } catch (Exception $e) {
            $this->logger->error('Failed to import object: '.$e->getMessage());
            throw new Exception('Failed to import object: '.$e->getMessage());
        }//end try

    }//end importObject()


}//end class
