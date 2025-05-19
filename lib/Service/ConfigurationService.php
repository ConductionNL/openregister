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
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Symfony\Component\Yaml\Yaml;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Class ConfigurationService
 *
 * Service for importing and exporting configurations in various formats.
 *
 * @package OCA\OpenRegister\Service
 */
class ConfigurationService
{

    /**
     * Schema mapper instance for handling schema operations.
     *
     * @var SchemaMapper The schema mapper instance.
     */
    private SchemaMapper $schemaMapper;

    /**
     * Register mapper instance for handling register operations.
     *
     * @var RegisterMapper The register mapper instance.
     */
    private RegisterMapper $registerMapper;

    /**
     * Object mapper instance for handling object operations.
     *
     * @var ObjectEntityMapper The object mapper instance.
     */
    private ObjectEntityMapper $objectEntityMapper;

    /**
     * Configuration mapper instance for handling configuration operations.
     *
     * @var ConfigurationMapper The configuration mapper instance.
     */
    private ConfigurationMapper $configurationMapper;

    /**
     * OpenConnector service instance for handling OpenConnector operations.
     *
     * @var OCA\OpenConnector\Service\ConfigurationService The OpenConnector service instance.
     */
    private $openConnectorConfigurationService;

    /**
     * App manager for checking installed apps.
     *
     * @var \OCP\App\IAppManager The app manager instance.
     */
    private $appManager;

    /**
     * Container for getting services.
     *
     * @var \Psr\Container\ContainerInterface The container instance.
     */
    private $container;

    /**
     * Schema property validator instance for validating schema properties.
     *
     * @var SchemaPropertyValidatorService The schema property validator instance.
     */
    private SchemaPropertyValidatorService $validator;

    /**
     * Logger instance for logging operations.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;

    /**
     * Map of registers indexed by slug during import, by id during export.
     *
     * @var array<string, Register> Registers indexed by slug during import, by id during export.
     */
    private array $registersMap = [];

    /**
     * Map of schemas indexed by slug during import, by id during export.
     *
     * @var array<string, Schema> Schemas indexed by slug during import, by id during export.
     */
    private array $schemasMap = [];

    /**
     * HTTP Client for making external requests.
     *
     * @var Client The HTTP client instance.
     */
    private Client $client;

    /**
     * Object service instance for handling object operations.
     *
     * @var ObjectService The object service instance.
     */
    private ObjectService $objectService;


    /**
     * Constructor
     *
     * @param SchemaMapper                      $schemaMapper        The schema mapper instance
     * @param RegisterMapper                    $registerMapper      The register mapper instance
     * @param ObjectEntityMapper                $objectEntityMapper  The object mapper instance
     * @param ConfigurationMapper               $configurationMapper The configuration mapper instance
     * @param SchemaPropertyValidatorService    $validator           The schema property validator instance
     * @param LoggerInterface                   $logger              The logger instance
     * @param \OCP\App\IAppManager              $appManager          The app manager instance
     * @param \Psr\Container\ContainerInterface $container           The container instance
     * @param Client                            $client              The HTTP client instance
     * @param ObjectService                     $objectService       The object service instance
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        ObjectEntityMapper $objectEntityMapper,
        ConfigurationMapper $configurationMapper,
        SchemaPropertyValidatorService $validator,
        LoggerInterface $logger,
        IAppManager $appManager,
        ContainerInterface $container,
        Client $client,
        ObjectService $objectService
    ) {
        $this->schemaMapper        = $schemaMapper;
        $this->registerMapper      = $registerMapper;
        $this->objectEntityMapper  = $objectEntityMapper;
        $this->configurationMapper = $configurationMapper;
        $this->validator           = $validator;
        $this->logger        = $logger;
        $this->appManager    = $appManager;
        $this->container     = $container;
        $this->client        = $client;
        $this->objectService = $objectService;

    }//end __construct()


    /**
     * Attempts to retrieve the OpenConnector service from the container.
     *
     * @return bool True if the OpenConnector service is available, false otherwise.
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getOpenConnector(): bool
    {
        if (in_array(needle: 'openconnector', haystack: $this->appManager->getInstalledApps()) === true) {
            try {
                // Attempt to get the OpenConnector service from the container.
                $this->openConnectorConfigurationService = $this->container->get('OCA\OpenConnector\Service\ConfigurationService');
                return true;
            } catch (Exception $e) {
                // If the service is not available, return false.
                return false;
            }
        }

        return false;

    }//end getOpenConnector()


    /**
     * Build OpenAPI Specification from configuration or register
     *
     * @param array|Configuration|Register $input          The configuration array, Configuration object, or Register object to build the OAS from.
     * @param bool                         $includeObjects Whether to include objects in the registers.
     *
     * @return array The OpenAPI specification.
     *
     * @throws Exception If configuration is invalid.
     *
     * @phpstan-param array<string, mixed>|Configuration|Register $input
     * @psalm-param   array<string, mixed>|Configuration|Register $input
     */
    public function exportConfig(array | Configuration | Register $input=[], bool $includeObjects=false): array
    {
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
                'id'          => $input->getId(),
                'title'       => $input->getTitle(),
                'description' => $input->getDescription(),
                'version'     => $input->getVersion(),
            ];
        } else if ($input instanceof Register) {
            // Pass the register as an array to the exportConfig function.
            $registers = [$input];
            // Set the info from the register.
            $openApiSpec['info'] = [
                'id'          => $input->getId(),
                'title'       => $input->getTitle(),
                'description' => $input->getDescription(),
                'version'     => $input->getVersion(),
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
        }//end if


        // Export each register and its schemas.
        foreach ($registers as $register) {
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
            $idsAndSlugsMap = $this->schemaMapper->getIdToSlugMap();

            foreach ($schemas as $schema) {
                // Store schema in map by ID for reference.
                $this->schemasMap[$schema->getId()] = $schema;

                $openApiSpec['components']['schemas'][$schema->getSlug()] = $this->exportSchema($schema, $idsAndSlugsMap);
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

            // Get the OpenConnector service.
            $openConnector = $this->getOpenConnector();
            if ($openConnector === true) {
                $openConnectorConfig = $this->openConnectorConfigurationService->exportRegister($register->getId());

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
     * Export a register to OpenAPI format
     *
     * @param Register $register The register to export
     *
     * @return array The OpenAPI register specification
     */
    private function exportRegister(Register $register): array
    {
        // Use jsonSerialize to get the JSON representation of the register.
        $registerArray = $register->jsonSerialize();

        // Unset id and uuid if they are present.
        unset($registerArray['id'], $registerArray['uuid']);

        return $registerArray;

    }//end exportRegister()


    /**
     * Export a schema to OpenAPI format
     *
     * @param Schema $schema The schema to export
     *
     * @return array The OpenAPI schema specification
     */
    private function exportSchema(Schema $schema, array $idsAndSlugsMap): array
    {
        // Use jsonSerialize to get the JSON representation of the schema.
        $schemaArray = $schema->jsonSerialize();

        // Unset id and uuid if they are present.
        unset($schemaArray['id'], $schemaArray['uuid']);

        foreach ($schemaArray['properties'] as &$property) {
            if (isset($property['$ref']) === true) {
                $schemaId = $this->getLastNumericSegment(url: $property['$ref']);
                if (isset($idsAndSlugsMap[$schemaId]) === true) {
                    $property['$ref'] = $idsAndSlugsMap[$schemaId];
                }
            }

            if (isset($property['items']['$ref']) === true) {
                $schemaId = $this->getLastNumericSegment(url: $property['items']['$ref']);
                if (isset($idsAndSlugsMap[$schemaId]) === true) {
                    $property['items']['$ref'] = $idsAndSlugsMap[$schemaId];
                }
            }
        }

        return $schemaArray;

    }//end exportSchema()


    private function getLastNumericSegment(string $url) {
        $url = rtrim($url, '/');

        $parts = explode('/', $url);
        $lastSegment = end($parts);

        return is_numeric($lastSegment) ? $lastSegment : $url;
    }



    /**
     * Export an object to OpenAPI format
     *
     * @param ObjectEntity $object The object to export
     *
     * @return array The OpenAPI object specification
     */
    private function exportObject(ObjectEntity $object): array
    {
        // Use jsonSerialize to get the JSON representation of the object.
        return $object->jsonSerialize();

    }//end exportObject()


    /**
     * Gets the uploaded json from the request data and returns it as a PHP array.
     * Will first try to find an uploaded 'file', then if an 'url' is present in the body,
     * and lastly if a 'json' dump has been posted.
     *
     * @param array      $data          All request params
     * @param array|null $uploadedFiles The uploaded files array
     *
     * @return array|JSONResponse A PHP array with the uploaded json data or a JSONResponse in case of an error
     * @throws Exception
     * @throws GuzzleException
     */
    public function getUploadedJson(array $data, ?array $uploadedFiles): array | JSONResponse
    {
        // Define the allowed keys for input validation.
        $allowedKeys = ['url', 'json'];

        // Find which of the allowed keys are in the array for processing.
        $matchingKeys = array_intersect_key($data, array_flip($allowedKeys));

        // Check if there is no matching key or no input provided.
        if (count($matchingKeys) === 0 && empty($uploadedFiles) === true) {
            $errorMessage = 'Missing required keys in POST body: url, json, or file in form-data.';
            return new JSONResponse(data: ['error' => $errorMessage], statusCode: 400);
        }

        // Process uploaded files if present.
        if (empty($uploadedFiles) === false) {
            if (count($uploadedFiles) === 1) {
                return $this->getJSONfromFile(uploadedFile: $uploadedFiles[array_key_first($uploadedFiles)]);
            }

            return new JSONResponse(data: ['message' => 'Expected only 1 file.'], statusCode: 400);
        }

        // Process URL if provided in the post body.
        if (empty($data['url']) === false) {
            return $this->getJSONfromURL(url: $data['url']);
        }

        // Process JSON blob from the post body.
        return $this->getJSONfromBody($data['json']);

    }//end getUploadedJson()


    /**
     * A function used to decode file content or the response of an url get call.
     * Before the data can be used to create or update an object.
     *
     * @param string      $data The file content or the response body content.
     * @param string|null $type The file MIME type or the response Content-Type header.
     *
     * @return array|null The decoded data or null.
     */
    private function decode(string $data, ?string $type): ?array
    {
        switch ($type) {
            case 'application/json':
                $phpArray = json_decode(json: $data, associative: true);
                break;
            case 'application/yaml':
                $phpArray = Yaml::parse(input: $data);
                break;
            default:
                // If Content-Type is not specified or not recognized, try to parse as JSON first, then YAML.
                $phpArray = json_decode(json: $data, associative: true);
                if ($phpArray === null || $phpArray === false) {
                    try {
                        $phpArray = Yaml::parse(input: $data);
                    } catch (Exception $exception) {
                        $phpArray = null;
                    }
                }
                break;
        }

        if ($phpArray === null || $phpArray === false) {
            return null;
        }

        return $phpArray;

    }//end decode()


    /**
     * Gets uploaded file content from a file in the api request as PHP array and use it for creating/updating an object.
     *
     * @param array       $uploadedFile The uploaded file.
     * @param string|null $type         If the uploaded file should be a specific type of object.
     *
     * @return array A PHP array with the uploaded json data or a JSONResponse in case of an error.
     */
    private function getJSONfromFile(array $uploadedFile, ?string $type=null): array | JSONResponse
    {
        // Check for upload errors.
        if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
            return new JSONResponse(data: ['error' => 'File upload error: '.$uploadedFile['error']], statusCode: 400);
        }

        $fileExtension = pathinfo(path: $uploadedFile['name'], flags: PATHINFO_EXTENSION);
        $fileContent   = file_get_contents(filename: $uploadedFile['tmp_name']);

        $phpArray = $this->decode(data: $fileContent, type: $fileExtension);
        if ($phpArray === null) {
            return new JSONResponse(
                data: ['error' => 'Failed to decode file content as JSON or YAML', 'MIME-type' => $fileExtension],
                statusCode: 400
            );
        }

        return $phpArray;

    }//end getJSONfromFile()


    /**
     * Uses Guzzle to call the given URL and returns response as PHP array.
     *
     * @param string $url The URL to call.
     *
     * @throws GuzzleException
     *
     * @return array|JSONResponse The response from the call converted to PHP array or JSONResponse in case of an error.
     */
    private function getJSONfromURL(string $url): array | JSONResponse
    {
        try {
            $response = $this->client->request('GET', $url);
        } catch (GuzzleException $e) {
            return new JSONResponse(data: ['error' => 'Failed to do a GET api-call on url: '.$url.' '.$e->getMessage()], statusCode: 400);
        }

        $responseBody = $response->getBody()->getContents();

        // Use Content-Type header to determine the format.
        $contentType = $response->getHeaderLine('Content-Type');
        $phpArray    = $this->decode(data: $responseBody, type: $contentType);

        if ($phpArray === null) {
            return new JSONResponse(
                data: ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => $contentType],
                statusCode: 400
               );
        }

        return $phpArray;

    }//end getJSONfromURL()


    /**
     * Uses the given string or array as PHP array for creating/updating an object.
     *
     * @param array|string $phpArray An array or string containing a json blob of data.
     * @param string|null  $type     If the object should be a specific type of object.
     *
     * @return array A PHP array with the uploaded json data or a JSONResponse in case of an error.
     */
    private function getJSONfromBody(array | string $phpArray): array | JSONResponse
    {
        if (is_string($phpArray) === true) {
            $phpArray = json_decode($phpArray, associative: true);
        }

        if ($phpArray === null || $phpArray === false) {
            return new JSONResponse(
                data: ['error' => 'Failed to decode JSON input'],
                statusCode: 400
            );
        }

        return $phpArray;

    }//end getJSONfromBody()


    /**
     * Import configuration from a JSON file.
     *
     * This method imports configuration data from a JSON file. It can handle:
     * - Full configurations with schemas, registers, and objects
     * - Partial configurations with only objects (using existing schemas and registers)
     * - Objects with references to existing schemas and registers
     *
     * @param array       $data  The configuration JSON content
     * @param string|null $owner The owner of the imported data
     *
     * @throws JsonException If JSON parsing fails
     * @throws Exception     If schema validation fails or format is unsupported
     * @return array        Array of created/updated entities
     *
     * @phpstan-return array{
     *     registers: array<Register>,
     *     schemas: array<Schema>,
     *     objects: array<ObjectEntity>,
     *     endpoints: array,
     *     sources: array,
     *     mappings: array,
     *     jobs: array,
     *     synchronizations: array,
     *     rules: array
     * }
     */
    public function importFromJson(array $data, ?string $owner=null): array
    {
        // Reset the maps for this import.
        $this->registersMap = [];
        $this->schemasMap   = [];

        $result = [
            'registers'        => [],
            'schemas'          => [],
            'endpoints'        => [],
            'sources'          => [],
            'mappings'         => [],
            'jobs'             => [],
            'synchronizations' => [],
            'rules'            => [],
            'objects'          => [],
        ];

        // Process and import schemas if present.
        if (isset($data['components']['schemas']) === true && is_array($data['components']['schemas']) === true) {
            foreach ($data['components']['schemas'] as $key => $schemaData) {
                if (isset($schemaData['title']) === false && is_string($key) === true) {
                    $schemaData['title'] = $key;
                }

                $schema = $this->importSchema($schemaData, $owner);
                if ($schema !== null) {
                    // Store schema in map by slug for reference.
                    $this->schemasMap[$schema->getSlug()] = $schema;
                    $result['schemas'][] = $schema;
                }
            }
        }

        // Process and import registers if present.
        if (isset($data['components']['registers']) === true && is_array($data['components']['registers']) === true) {
            foreach ($data['components']['registers'] as $slug => $registerData) {
                $slug = strtolower($slug);

                if (isset($registerData['schemas']) === true && is_array($registerData['schemas']) === true) {
                    $schemaIds = [];
                    foreach ($registerData['schemas'] as $schemaSlug) {
                        if (isset($this->schemasMap[$schemaSlug]) === true) {
                            $schemaSlug  = strtolower($schemaSlug);
                            $schemaIds[] = $this->schemasMap[$schemaSlug]->getId();
                        } else {
                            // Try to find existing schema in database.
                            try {
                                $existingSchema = $this->schemaMapper->find(strtolower($schemaSlug));
                                $schemaIds[]    = $existingSchema->getId();
                                // Add to map for object processing.
                                $this->schemasMap[$schemaSlug] = $existingSchema;
                            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                                $this->logger->warning(
                                    sprintf('Schema with slug %s not found during register import.', $schemaSlug)
                                );
                            }
                        }
                    }

                    $registerData['schemas'] = $schemaIds;
                }//end if

                $register = $this->importRegister($registerData, $owner);
                if ($register !== null) {
                    // Store register in map by slug for reference.
                    $this->registersMap[$slug] = $register;
                    $result['registers'][]     = $register;
                }
            }//end foreach
        }//end if

        // Process and import objects.
        if (isset($data['components']['objects']) === true && is_array($data['components']['objects']) === true) {
            foreach ($data['components']['objects'] as $objectData) {
                // Map register and schema slugs to their respective IDs.
                if (isset($objectData['@self']['register']) === true) {
                    $registerSlug = strtolower($objectData['@self']['register']);
                    if (isset($this->registersMap[$registerSlug]) === true) {
                        $objectData['@self']['register'] = $this->registersMap[$registerSlug]->getId();
                    } else {
                        // Try to find existing register in database.
                        try {
                            $existingRegister = $this->registerMapper->find($registerSlug);
                            $objectData['@self']['register'] = $existingRegister->getId();
                            // Add to map for future object processing.
                            $this->registersMap[$registerSlug] = $existingRegister;
                        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                            $this->logger->warning(
                                sprintf('Register with slug %s not found during object import.', $registerSlug)
                            );
                            continue;
                        }
                    }
                } else {
                    $this->logger->warning('Object data missing required register reference.');
                    continue;
                }//end if

                if (isset($objectData['@self']['schema']) === true) {
                    $schemaSlug = strtolower($objectData['@self']['schema']);
                    if (isset($this->schemasMap[$schemaSlug]) === true) {
                        $objectData['@self']['schema'] = $this->schemasMap[$schemaSlug]->getId();
                    } else {
                        // Try to find existing schema in database.
                        try {
                            $existingSchema = $this->schemaMapper->find($schemaSlug);
                            $objectData['@self']['schema'] = $existingSchema->getId();
                            // Add to map for future object processing.
                            $this->schemasMap[$schemaSlug] = $existingSchema;
                        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                            $this->logger->warning(
                                sprintf('Schema with slug %s not found during object import.', $schemaSlug)
                            );
                            continue;
                        }
                    }
                } else {
                    $this->logger->warning('Object data missing required schema reference.');
                    continue;
                }//end if

                $object = $this->importObject($objectData, $owner);
                if ($object !== null) {
                    $result['objects'][] = $object;
                }
            }//end foreach
        }//end if

        // Process OpenConnector integration if available.
        $openConnector = $this->getOpenConnector();
        if ($openConnector === true) {
            $openConnectorResult = $this->openConnectorConfigurationService->importConfiguration($data);
            $result = array_replace_recursive($openConnectorResult, $result);
        }

        return $result;

    }//end importFromJson()


    /**
     * Import a register from configuration data
     *
     * @param array       $data  The register data.
     * @param string|null $owner The owner of the register.
     *
     * @return Register|null The imported register or null if skipped.
     */
    private function importRegister(array $data, ?string $owner=null): ?Register
    {
        try {
            // Remove id and uuid from the data.
            unset($data['id'], $data['uuid']);

            // Check if register already exists by slug.
            $existingRegister = null;
            try {
                $existingRegister = $this->registerMapper->find(strtolower($data['slug']));
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Register doesn't exist, we'll create a new one.
            }

            if ($existingRegister !== null) {
                // Compare versions using version_compare for proper semver comparison.
                if (version_compare($data['version'], $existingRegister->getVersion(), '<=') === true) {
                    $this->logger->info('Skipping register import as existing version is newer or equal.');
                    // Even though we're skipping the update, we still need to add it to the map.
                    return $existingRegister;
                }

                // Update existing register.
                $existingRegister = $this->registerMapper->updateFromArray($existingRegister->getId(), $data);
                if ($owner !== null) {
                    $existingRegister->setOwner($owner);
                }

                return $this->registerMapper->update($existingRegister);
            }

            // Create new register.
            $register = $this->registerMapper->createFromArray($data);
            if ($owner !== null) {
                $register->setOwner($owner);
                $register = $this->registerMapper->update($register);
            }

            return $register;
        } catch (Exception $e) {
            $this->logger->error('Failed to import register: '.$e->getMessage());
            throw new Exception('Failed to import register: '.$e->getMessage());
        }//end try

    }//end importRegister()


    /**
     * Import a schema from configuration data
     *
     * @param array       $data  The schema data.
     * @param string|null $owner The owner of the schema.
     *
     * @return Schema|null The imported schema or null if skipped.
     */
    private function importSchema(array $data, ?string $owner=null): ?Schema
    {
        try {
            // Remove id and uuid from the data.
            unset($data['id'], $data['uuid']);

            // @todo this shouldnt be necessary if we fully supported oas
            // if properties is oneOf or allOf (which we dont support yet) it wont have a type, this is a hacky fix so it doesnt break the whole process.
            // sets type to string if no type
            // defaults title to its key in the oas so we dont have whitespaces (which is seen sometimes in defined titles in properties) in the property key
            // removes format if format is string
            if (isset($data['properties']) === true) {
                foreach ($data['properties'] as $key => &$property) {
                    $property['title'] = $key;
                    if (isset($property['type']) === false) {
                        $property['type'] = 'string';
                    }
                    if (isset($property['format']) === true && ($property['format'] === 'string' || $property['format'] === 'binary' || $property['format'] === 'byte')) {
                        unset($property['format']);
                    }
                    if (isset($property['items']['format']) === true && ($property['items']['format'] === 'string' || $property['items']['format'] === 'binary' || $property['items']['format'] === 'byte')) {
                        unset($property['items']['format']);
                    }
                }
            }


            // Check if schema already exists by slug.
            $existingSchema = null;
            try {
                $existingSchema = $this->schemaMapper->find(strtolower($data['slug']));
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Schema doesn't exist, we'll create a new one.
            }

            if ($existingSchema !== null) {
                // Compare versions using version_compare for proper semver comparison.
                if (version_compare($data['version'], $existingSchema->getVersion(), '<=') === true) {
                    $this->logger->info('Skipping schema import as existing version is newer or equal.');
                    // Even though we're skipping the update, we still need to add it to the map.
                    return $existingSchema;
                }

                // Update existing schema.
                $existingSchema = $this->schemaMapper->updateFromArray($existingSchema->getId(), $data);
                if ($owner !== null) {
                    $existingSchema->setOwner($owner);
                }

                return $this->schemaMapper->update($existingSchema);
            }

            // Create new schema.
            $schema = $this->schemaMapper->createFromArray($data);
            if ($owner !== null) {
                $schema->setOwner($owner);
                $schema = $this->schemaMapper->update($schema);
            }

            return $schema;
        } catch (Exception $e) {
            $this->logger->error('Failed to import schema: '.$e->getMessage());
            throw new Exception('Failed to import schema: '.$e->getMessage(), $e->getCode(), $e);
        }//end try

    }//end importSchema()


    /**
     * Import an object from configuration data
     *
     * @param array       $data  The object data.
     * @param string|null $owner The owner of the object.
     *
     * @return ObjectEntity|null The imported object or null if skipped.
     */
    private function importObject(array $data, ?string $owner=null): ?ObjectEntity
    {
        try {
            // Determine the UUID or ID to use for finding the existing object.
            $uuid = $data['uuid'] ?? $data['id'] ?? null;

            // Check if object already exists by UUID or ID.
            $existingObject = null;

            if ($uuid !== null) {
                try {
                    $existingObject = $this->objectEntityMapper->find($uuid);
                } catch (\Exception $e) {
                    // Catch all exceptions, object doesn't exist or other error occurred, we'll create a new one.
                    $this->logger->error('Error finding object: '.$e->getMessage());
                }
            }

            // Set the register and schema context for the object service.
            $this->objectService->setRegister($data['@self']['register']);
            $this->objectService->setSchema($data['@self']['schema']);

            // Save the object using the object service.
            $object = $this->objectService->saveObject(
                object: $data,
                uuid: $uuid ?? null
            );

            return $object;
        } catch (Exception $e) {
            $this->logger->error('Failed to import object: '.$e->getMessage());
            throw new Exception('Failed to import object: '.$e->getMessage());
        }//end try

    }//end importObject()


    /**
     * Import a configuration from Open Connector
     *
     * This method attempts to import a configuration from Open Connector if it is available.
     * It will check if the Open Connector service is available and then call its exportRegister function.
     *
     * @param string $registerId The ID of the register to import from Open Connector
     * @param string $owner      The owner of the configuration
     *
     * @return Configuration|null The imported configuration or null if import failed
     *
     * @throws Exception If there is an error during import
     */
    public function importFromOpenConnector(string $registerId, string $owner): ?Configuration
    {
        // Check if Open Connector is available
        if ($this->getOpenConnector() === false) {
            $this->logger->warning('Open Connector is not available for importing configuration');
            return null;
        }

        try {
            // Call the exportRegister function on the Open Connector service
            $exportedData = $this->openConnectorConfigurationService->exportRegister($registerId);

            if (empty($exportedData)) {
                $this->logger->error('No data received from Open Connector export');
                return null;
            }

            // Create a new configuration from the exported data
            $configuration = new Configuration();
            $configuration->setTitle($exportedData['title'] ?? 'Imported from Open Connector');
            $configuration->setDescription($exportedData['description'] ?? 'Configuration imported from Open Connector');
            $configuration->setType('openconnector');
            $configuration->setOwner($owner);
            $configuration->setVersion($exportedData['version'] ?? '1.0.0');
            $configuration->setRegisters($exportedData['registers'] ?? []);

            // Save the configuration
            return $this->configurationMapper->insert($configuration);

        } catch (Exception $e) {
            $this->logger->error('Failed to import configuration from Open Connector: ' . $e->getMessage());
            throw new Exception('Failed to import configuration from Open Connector: ' . $e->getMessage());
        }
    }

}//end class
