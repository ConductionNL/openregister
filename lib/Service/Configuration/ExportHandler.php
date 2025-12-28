<?php

/**
 * OpenRegister Export Handler
 *
 * This file contains the handler class for exporting configurations
 * in OpenAPI format from the OpenRegister application.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use Psr\Log\LoggerInterface;

/**
 * Class ExportHandler
 *
 * Handles exporting configurations, registers, and schemas to OpenAPI format.
 *
 * @package OCA\OpenRegister\Service\Configuration
 */
class ExportHandler
{
    /**
     * Schema mapper instance for handling schema operations.
     *
     * @var SchemaMapper The schema mapper instance.
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Register mapper instance for handling register operations.
     *
     * @var RegisterMapper The register mapper instance.
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Object mapper instance for handling object operations.
     *
     * @var ObjectEntityMapper The object mapper instance.
     */
    private readonly ObjectEntityMapper $objectEntityMapper;

    /**
     * Configuration mapper instance for handling configuration operations.
     *
     * @var ConfigurationMapper The configuration mapper instance.
     */
    private readonly ConfigurationMapper $configurationMapper;

    /**
     * Logger instance for logging operations.
     *
     * @var LoggerInterface The logger instance.
     */
    private readonly LoggerInterface $logger;

    /**
     * Map of registers indexed by ID during export.
     *
     * @var array<int, Register> Registers indexed by ID.
     */
    private array $registersMap = [];

    /**
     * Map of schemas indexed by ID during export.
     *
     * @var array<int, Schema> Schemas indexed by ID.
     */
    private array $schemasMap = [];

    /**
     * Constructor for ExportHandler.
     *
     * @param SchemaMapper        $schemaMapper        The schema mapper.
     * @param RegisterMapper      $registerMapper      The register mapper.
     * @param ObjectEntityMapper  $objectEntityMapper  The object entity mapper.
     * @param ConfigurationMapper $configurationMapper The configuration mapper.
     * @param LoggerInterface     $logger              The logger interface.
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        ObjectEntityMapper $objectEntityMapper,
        ConfigurationMapper $configurationMapper,
        LoggerInterface $logger
    ) {
        $this->schemaMapper        = $schemaMapper;
        $this->registerMapper      = $registerMapper;
        $this->objectEntityMapper  = $objectEntityMapper;
        $this->configurationMapper = $configurationMapper;
        $this->logger = $logger;
    }//end __construct()

    /**
     * Export configuration to OpenAPI format.
     *
     * This method exports a configuration, register, or array to OpenAPI 3.0.0 format,
     * including all associated registers, schemas, and optionally objects.
     *
     * @param array|Configuration|Register $input                The input to export (Configuration, Register, or array).
     * @param bool                         $includeObjects       Whether to include objects in the export. Defaults to false.
     * @param object|null                  $openConnectorService Optional OpenConnector service for additional export data.
     *
     * @return array The OpenAPI specification array.
     *
     * @throws \OCP\DB\Exception If database operations fail.
     */
    public function exportConfig(
        array|Configuration|Register $input = [],
        bool $includeObjects = false,
        ?object $openConnectorService = null
    ): array {
        // Reset the maps for this export.
        $this->registersMap = [];
        $this->schemasMap   = [];

        // Initialize OpenAPI specification with default values.
        $openApiSpec = [
            'openapi'    => '3.0.0',
            'components' => [
                'registers'        => [],
                'schemas'          => [],
                'endpoints'        => [],
                'sources'          => [],
                'mappings'         => [],
                'jobs'             => [],
                'synchronizations' => [],
                'rules'            => [],
                'objects'          => [],
            ],
        ];

        // Determine if input is an array, Configuration, or Register object.
        if ($input instanceof Configuration) {
            $configuration = $input;

            // Get all registers associated with this configuration.
            $registers = $configuration->getRegisters();

            // Set the info from the configuration.
            $openApiSpec['info'] = [
                'title'       => $input->getTitle(),
                'description' => $input->getDescription(),
                'version'     => $input->getVersion(),
            ];

            // Add OpenRegister-specific metadata as an extension following OpenAPI spec.
            // Https://swagger.io/docs/specification/v3_0/openapi-extensions/.
            // Standard OAS properties (title, description, version) are in the info section above.
            // Note: Internal properties (autoUpdate, notificationGroups, owner, organisation, registers,.
            // Schemas, objects, views, agents, sources, applications) are excluded as they are.
            // Instance-specific or automatically managed during import.
            $openApiSpec['x-openregister'] = [
                'type'         => $input->getType(),
                'app'          => $input->getApp(),
                'sourceType'   => $input->getSourceType(),
                'sourceUrl'    => $input->getSourceUrl(),
                'openregister' => $input->getOpenregister(),
                'github'       => [
                    'repo'   => $input->getGithubRepo(),
                    'branch' => $input->getGithubBranch(),
                    'path'   => $input->getGithubPath(),
                ],
            ];
        } elseif ($input instanceof Register) {
            // Pass the register as an array to the exportConfig function.
            $registers = [$input];
            // Set the info from the register.
            $openApiSpec['info'] = [
                'title'       => $input->getTitle(),
                'description' => $input->getDescription(),
                'version'     => $input->getVersion(),
            ];

            // Add minimal x-openregister metadata for register export.
            $openApiSpec['x-openregister'] = [
                'type' => 'register',
            ];
        } else {
            // Get all registers associated with this configuration.
            $configuration = $this->configurationMapper->find($input['id']);

            // Get all registers associated with this configuration.
            $registers = $configuration->getRegisters();

            // Set the info from the configuration.
            $openApiSpec['info'] = [
                'title'       => $input['title'] ?? 'Default Title',
                'description' => $input['description'] ?? 'Default Description',
                'version'     => $input['version'] ?? '1.0.0',
            ];

            // Add x-openregister metadata if available in input.
            if (($input['x-openregister'] ?? null) !== null) {
                $openApiSpec['x-openregister'] = $input['x-openregister'];
            } else {
                // Create basic metadata from input.
                $openApiSpec['x-openregister'] = [
                    'title'       => $input['title'] ?? null,
                    'description' => $input['description'] ?? null,
                    'type'        => $input['type'] ?? null,
                    'app'         => $input['app'] ?? null,
                    'version'     => $input['version'] ?? '1.0.0',
                ];
            }
        }//end if

        // Export each register and its schemas.
        foreach ($registers ?? [] as $register) {
            if ($register instanceof Register === false && is_int($register) === true) {
                $register = $this->registerMapper->find($register);
            }

            // Store register in map by ID for reference.
            $this->registersMap[$register->getId()] = $register;

            // Set the base register.
            $openApiSpec['components']['registers'][$register->getSlug()] = $this->exportRegister($register);
            // Drop the schemas from the register (we need to slugify those).
            $openApiSpec['components']['registers'][$register->getSlug()]['schemas'] = [];

            // Get and export schemas associated with this register.
            $schemas = $this->registerMapper->getSchemasByRegisterId($register->getId());
            $schemaIdsAndSlugsMap   = $this->schemaMapper->getIdToSlugMap();
            $registerIdsAndSlugsMap = $this->registerMapper->getIdToSlugMap();

            foreach ($schemas as $schema) {
                // Store schema in map by ID for reference.
                $this->schemasMap[$schema->getId()] = $schema;

                $openApiSpec['components']['schemas'][$schema->getSlug()] = $this->exportSchema(
                    schema: $schema,
                    schemaIdsAndSlugsMap: $schemaIdsAndSlugsMap,
                    registerIdsAndSlugsMap: $registerIdsAndSlugsMap
                );
                $openApiSpec['components']['registers'][$register->getSlug()]['schemas'][] = $schema->getSlug();
            }

            // Optionally include objects in the register.
            if ($includeObjects === true) {
                $objects = $this->objectEntityMapper->findAll(
                    filters: ['register' => $register->getId()]
                );

                foreach ($objects as $object) {
                    // Use maps to get slugs.
                    $object = $object->jsonSerialize();
                    $object['@self']['register'] = $this->registersMap[$object['@self']['register']]->getSlug();
                    $object['@self']['schema']   = $this->schemasMap[$object['@self']['schema']]->getSlug();
                    $openApiSpec['components']['objects'][] = $object;
                }
            }

            // Get the OpenConnector service if provided.
            if ($openConnectorService !== null) {
                $openConnectorConfig = $openConnectorService->exportRegister($register->getId());

                // Merge the OpenAPI specification over the OpenConnector configuration.
                $openApiSpec = array_replace_recursive(
                    $openConnectorConfig,
                    $openApiSpec
                );
            }
        }//end foreach

        return $openApiSpec;
    }//end exportConfig()

    /**
     * Export a register to OpenAPI format.
     *
     * This method converts a Register entity to an array suitable for OpenAPI export,
     * removing instance-specific properties like id, uuid, and organisation.
     *
     * @param Register $register The register to export.
     *
     * @return array The OpenAPI register specification.
     *
     * @psalm-return array{slug: null|string, title: null|string, version: null|string, description: null|string, schemas: array<int|string>, source: null|string, tablePrefix: null|string, folder: null|string, updated: null|string, created: null|string, owner: null|string, application: null|string, authorization: array|null, groups: array<string, list<string>>, quota: array{storage: null, bandwidth: null, requests: null, users: null, groups: null}, usage: array{storage: 0, bandwidth: 0, requests: 0, users: 0, groups: int<0, max>}, deleted: null|string, published: null|string, depublished: null|string}
     */
    private function exportRegister(Register $register): array
    {
        // Use jsonSerialize to get the JSON representation of the register.
        $registerArray = $register->jsonSerialize();

        // Unset id, uuid, and organisation if they are present.
        // Organisation is instance-specific and should not be exported.
        unset($registerArray['id'], $registerArray['uuid'], $registerArray['organisation']);

        return $registerArray;
    }//end exportRegister()

    /**
     * Export a schema to OpenAPI format.
     *
     * This method exports a schema and converts internal IDs to slugs for portability.
     * It handles both the new objectConfiguration structure (with register and schema IDs)
     * and the legacy register property structure for backward compatibility.
     *
     * @param Schema $schema                 The schema to export.
     * @param array  $schemaIdsAndSlugsMap   Map of schema IDs to slugs.
     * @param array  $registerIdsAndSlugsMap Map of register IDs to slugs.
     *
     * @return ((mixed|string[])[]|bool|int|null|string)[]
     *
     * @psalm-return array{uri: null|string, slug: null|string, title: null|string, description: null|string, version: null|string, summary: null|string, icon: null|string, required: array, properties: array, archive: array|null, source: null|string, hardValidation: bool, immutable: bool, searchable: bool, updated: null|string, created: null|string, maxDepth: int, owner: null|string, application: null|string, groups: array<string, list<string>>|null, authorization: array|null, deleted: null|string, published: null|string, depublished: null|string, configuration: array|null|string, allOf: array|null, oneOf: array|null, anyOf: array|null}
     */
    private function exportSchema(Schema $schema, array $schemaIdsAndSlugsMap, array $registerIdsAndSlugsMap): array
    {
        // Use jsonSerialize to get the JSON representation of the schema.
        $schemaArray = $schema->jsonSerialize();

        // Unset id, uuid, and organisation if they are present.
        // Organisation is instance-specific and should not be exported.
        unset($schemaArray['id'], $schemaArray['uuid'], $schemaArray['organisation']);

        foreach ($schemaArray['properties'] as &$property) {
            // Ensure property is always an array.
            if (is_object($property) === true) {
                $property = (array) $property;
            }

            if (($property['$ref'] ?? null) !== null) {
                $schemaId = $this->getLastNumericSegment(url: $property['$ref']);
                if (($schemaIdsAndSlugsMap[$schemaId] ?? null) !== null) {
                    $property['$ref'] = $schemaIdsAndSlugsMap[$schemaId];
                }
            }

            if (($property['items']['$ref'] ?? null) !== null) {
                // Ensure items is an array for consistent access.
                if (is_object($property['items']) === true) {
                    $property['items'] = (array) $property['items'];
                }

                $schemaId = $this->getLastNumericSegment(url: $property['items']['$ref']);
                if (($schemaIdsAndSlugsMap[$schemaId] ?? null) !== null) {
                    $property['items']['$ref'] = $schemaIdsAndSlugsMap[$schemaId];
                }
            }

            // Handle register ID in objectConfiguration (new structure).
            if (($property['objectConfiguration']['register'] ?? null) !== null) {
                // Ensure objectConfiguration is an array for consistent access.
                if (is_object($property['objectConfiguration']) === true) {
                    $property['objectConfiguration'] = (array) $property['objectConfiguration'];
                }

                $registerId = $property['objectConfiguration']['register'];
                if (is_numeric($registerId) === true) {
                    $registerIdStr = (string) $registerId;
                    if (($registerIdsAndSlugsMap[$registerIdStr] ?? null) !== null) {
                        /*
                         * @var array<int|string, string> $registerIdsAndSlugsMap
                         */

                        $property['objectConfiguration']['register'] = $registerIdsAndSlugsMap[$registerIdStr];
                    }
                }
            }

            // Handle schema ID in objectConfiguration (new structure).
            if (($property['objectConfiguration']['schema'] ?? null) !== null) {
                // Ensure objectConfiguration is an array for consistent access.
                if (is_object($property['objectConfiguration']) === true) {
                    $property['objectConfiguration'] = (array) $property['objectConfiguration'];
                }

                $schemaId = $property['objectConfiguration']['schema'];
                if (is_numeric($schemaId) === true) {
                    $schemaIdStr = (string) $schemaId;
                    if (($schemaIdsAndSlugsMap[$schemaIdStr] ?? null) !== null) {
                        /*
                         * @var array<int|string, string> $schemaIdsAndSlugsMap
                         */

                        $property['objectConfiguration']['schema'] = $schemaIdsAndSlugsMap[$schemaIdStr];
                    }
                }
            }

            // Handle register ID in array items objectConfiguration (new structure).
            if (($property['items']['objectConfiguration']['register'] ?? null) !== null) {
                // Ensure items and objectConfiguration are arrays for consistent access.
                if (is_object($property['items']) === true) {
                    $property['items'] = (array) $property['items'];
                }

                if (is_object($property['items']['objectConfiguration']) === true) {
                    $property['items']['objectConfiguration'] = (array) $property['items']['objectConfiguration'];
                }

                $registerId = $property['items']['objectConfiguration']['register'];
                if (is_numeric($registerId) === true) {
                    $registerIdStr = (string) $registerId;
                    if (($registerIdsAndSlugsMap[$registerIdStr] ?? null) !== null) {
                        /*
                         * @var array<int|string, string> $registerIdsAndSlugsMap
                         */

                        $property['items']['objectConfiguration']['register'] = $registerIdsAndSlugsMap[$registerIdStr];
                    }
                }
            }//end if

            // Handle schema ID in array items objectConfiguration (new structure).
            if (($property['items']['objectConfiguration']['schema'] ?? null) !== null) {
                // Ensure items and objectConfiguration are arrays for consistent access.
                if (is_object($property['items']) === true) {
                    $property['items'] = (array) $property['items'];
                }

                if (is_object($property['items']['objectConfiguration']) === true) {
                    $property['items']['objectConfiguration'] = (array) $property['items']['objectConfiguration'];
                }

                $schemaId = $property['items']['objectConfiguration']['schema'];
                if (is_numeric($schemaId) === true) {
                    $schemaIdStr = (string) $schemaId;
                    if (($schemaIdsAndSlugsMap[$schemaIdStr] ?? null) !== null) {
                        /*
                         * @var array<int|string, string> $schemaIdsAndSlugsMap
                         */

                        $property['items']['objectConfiguration']['schema'] = $schemaIdsAndSlugsMap[$schemaIdStr];
                    }
                }
            }//end if

            // Legacy support: Handle old register property structure.
            if (($property['register'] ?? null) !== null) {
                if (is_string($property['register']) === true) {
                    $registerId    = $this->getLastNumericSegment(url: $property['register']);
                    $registerIdStr = $registerId;
                    if (($registerIdsAndSlugsMap[$registerIdStr] ?? null) !== null) {
                        /*
                         * @var array<int|string, string> $registerIdsAndSlugsMap
                         */

                        $property['register'] = $registerIdsAndSlugsMap[$registerIdStr];
                    }
                }
            }

            if (($property['items']['register'] ?? null) !== null) {
                // Ensure items is an array for consistent access.
                if (is_object($property['items']) === true) {
                    $property['items'] = (array) $property['items'];
                }

                if (is_string($property['items']['register']) === true) {
                    $registerId    = $this->getLastNumericSegment(url: $property['items']['register']);
                    $registerIdStr = $registerId;
                    if (($registerIdsAndSlugsMap[$registerIdStr] ?? null) !== null) {
                        /*
                         * @var array<int|string, string> $registerIdsAndSlugsMap
                         */

                        $property['items']['register'] = $registerIdsAndSlugsMap[$registerIdStr];
                    }
                }
            }
        }//end foreach

        return $schemaArray;
    }//end exportSchema()

    /**
     * Get the last segment of a URL if it is numeric.
     *
     * This method takes a URL string, removes trailing slashes, splits it by '/' and
     * checks if the last segment is numeric. If it is, returns that numeric value,
     * otherwise returns the original URL.
     *
     * @param string $url The input URL to evaluate.
     *
     * @return string The numeric value if found, or the original URL.
     *
     * @throws \InvalidArgumentException If the URL is not a string.
     */
    private function getLastNumericSegment(string $url): string
    {
        // Remove trailing slashes from the URL.
        $url = rtrim($url, '/');

        // Split the URL by '/' to get individual segments.
        $parts = explode('/', $url);

        // Get the last segment.
        $lastSegment = end($parts);

        // Return numeric segment if found, otherwise return original URL.
        if (is_numeric($lastSegment) === true) {
            return $lastSegment;
        } else {
            return $url;
        }
    }//end getLastNumericSegment()
}//end class
