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
use OCP\IAppConfig;
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
     * App config for storing configuration metadata.
     *
     * @var \OCP\IAppConfig The app config instance.
     */
    private $appConfig;

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
     * @param \OCP\IAppConfig                   $appConfig           The app config instance
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
        IAppConfig $appConfig,
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
        $this->appConfig     = $appConfig;
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
            $schemaIdsAndSlugsMap   = $this->schemaMapper->getIdToSlugMap();
            $registerIdsAndSlugsMap = $this->registerMapper->getIdToSlugMap();

            foreach ($schemas as $schema) {
                // Store schema in map by ID for reference.
                $this->schemasMap[$schema->getId()] = $schema;

                $openApiSpec['components']['schemas'][$schema->getSlug()] = $this->exportSchema($schema, $schemaIdsAndSlugsMap, $registerIdsAndSlugsMap);
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
     * This method exports a schema and converts internal IDs to slugs for portability.
     * It handles both the new objectConfiguration structure (with register and schema IDs)
     * and the legacy register property structure for backward compatibility.
     *
     * @param Schema $schema                 The schema to export
     * @param array  $schemaIdsAndSlugsMap   Map of schema IDs to slugs
     * @param array  $registerIdsAndSlugsMap Map of register IDs to slugs
     *
     * @return array The OpenAPI schema specification with IDs converted to slugs
     */
    private function exportSchema(Schema $schema, array $schemaIdsAndSlugsMap, array $registerIdsAndSlugsMap): array
    {
        // Use jsonSerialize to get the JSON representation of the schema.
        $schemaArray = $schema->jsonSerialize();

        // Unset id and uuid if they are present.
        unset($schemaArray['id'], $schemaArray['uuid']);

        foreach ($schemaArray['properties'] as &$property) {
            // Ensure property is always an array
            if (is_object($property)) {
                $property = (array) $property;
            }

            if (isset($property['$ref']) === true) {
                $schemaId = $this->getLastNumericSegment(url: $property['$ref']);
                if (isset($schemaIdsAndSlugsMap[$schemaId]) === true) {
                    $property['$ref'] = $schemaIdsAndSlugsMap[$schemaId];
                }
            }

            if (isset($property['items']['$ref']) === true) {
                // Ensure items is an array for consistent access
                if (is_object($property['items'])) {
                    $property['items'] = (array) $property['items'];
                }

                $schemaId = $this->getLastNumericSegment(url: $property['items']['$ref']);
                if (isset($schemaIdsAndSlugsMap[$schemaId]) === true) {
                    $property['items']['$ref'] = $schemaIdsAndSlugsMap[$schemaId];
                }
            }

            // Handle register ID in objectConfiguration (new structure)
            if (isset($property['objectConfiguration']['register']) === true) {
                // Ensure objectConfiguration is an array for consistent access
                if (is_object($property['objectConfiguration'])) {
                    $property['objectConfiguration'] = (array) $property['objectConfiguration'];
                }

                $registerId = $property['objectConfiguration']['register'];
                if (is_numeric($registerId) && isset($registerIdsAndSlugsMap[$registerId]) === true) {
                    $property['objectConfiguration']['register'] = $registerIdsAndSlugsMap[$registerId];
                }
            }

            // Handle schema ID in objectConfiguration (new structure)
            if (isset($property['objectConfiguration']['schema']) === true) {
                // Ensure objectConfiguration is an array for consistent access
                if (is_object($property['objectConfiguration'])) {
                    $property['objectConfiguration'] = (array) $property['objectConfiguration'];
                }

                $schemaId = $property['objectConfiguration']['schema'];
                if (is_numeric($schemaId) && isset($schemaIdsAndSlugsMap[$schemaId]) === true) {
                    $property['objectConfiguration']['schema'] = $schemaIdsAndSlugsMap[$schemaId];
                }
            }

            // Handle register ID in array items objectConfiguration (new structure)
            if (isset($property['items']['objectConfiguration']['register']) === true) {
                // Ensure items and objectConfiguration are arrays for consistent access
                if (is_object($property['items'])) {
                    $property['items'] = (array) $property['items'];
                }

                if (is_object($property['items']['objectConfiguration'])) {
                    $property['items']['objectConfiguration'] = (array) $property['items']['objectConfiguration'];
                }

                $registerId = $property['items']['objectConfiguration']['register'];
                if (is_numeric($registerId) && isset($registerIdsAndSlugsMap[$registerId]) === true) {
                    $property['items']['objectConfiguration']['register'] = $registerIdsAndSlugsMap[$registerId];
                }
            }

            // Handle schema ID in array items objectConfiguration (new structure)
            if (isset($property['items']['objectConfiguration']['schema']) === true) {
                // Ensure items and objectConfiguration are arrays for consistent access
                if (is_object($property['items'])) {
                    $property['items'] = (array) $property['items'];
                }

                if (is_object($property['items']['objectConfiguration'])) {
                    $property['items']['objectConfiguration'] = (array) $property['items']['objectConfiguration'];
                }

                $schemaId = $property['items']['objectConfiguration']['schema'];
                if (is_numeric($schemaId) && isset($schemaIdsAndSlugsMap[$schemaId]) === true) {
                    $property['items']['objectConfiguration']['schema'] = $schemaIdsAndSlugsMap[$schemaId];
                }
            }

            // Legacy support: Handle old register property structure
            if (isset($property['register']) === true) {
                if (is_string($property['register']) === true) {
                    $registerId = $this->getLastNumericSegment(url: $property['register']);
                    if (isset($registerIdsAndSlugsMap[$registerId]) === true) {
                        $property['register'] = $registerIdsAndSlugsMap[$registerId];
                    }
                }
            }

            if (isset($property['items']['register']) === true) {
                // Ensure items is an array for consistent access
                if (is_object($property['items'])) {
                    $property['items'] = (array) $property['items'];
                }

                if (is_string($property['items']['register']) === true) {
                    $registerId = $this->getLastNumericSegment(url: $property['items']['register']);
                    if (isset($registerIdsAndSlugsMap[$registerId]) === true) {
                        $property['items']['register'] = $registerIdsAndSlugsMap[$registerId];
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
     * @param  string $url The input URL to evaluate
     * @return string The numeric value if found, or the original URL
     *
     * @throws InvalidArgumentException If the URL is not a string
     */
    private function getLastNumericSegment(string $url): string
    {
        // Remove trailing slashes from the URL
        $url = rtrim($url, '/');

        // Split the URL by '/' to get individual segments
        $parts = explode('/', $url);

        // Get the last segment
        $lastSegment = end($parts);

        // Return numeric segment if found, otherwise return original URL
        return is_numeric($lastSegment) ? $lastSegment : $url;

    }//end getLastNumericSegment()


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

        // Ensure all data is consistently arrays by converting any stdClass objects
        $phpArray = $this->ensureArrayStructure($phpArray);

        return $phpArray;

    }//end decode()


    /**
     * Recursively converts stdClass objects to arrays to ensure consistent data structure
     *
     * @param  mixed $data The data to convert
     * @return array The converted array data
     */
    private function ensureArrayStructure(mixed $data): array
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_object($value)) {
                    $data[$key] = $this->ensureArrayStructure($value);
                } else if (is_array($value)) {
                    $data[$key] = $this->ensureArrayStructure($value);
                }
            }
        }

        return $data;

    }//end ensureArrayStructure()


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

        // Ensure all data is consistently arrays by converting any stdClass objects
        $phpArray = $this->ensureArrayStructure($phpArray);

        return $phpArray;

    }//end getJSONfromBody()


    /**
     * Import configuration from a JSON file.
     *
     * This method imports configuration data from a JSON file. It can handle:
     * - Full configurations with schemas, registers, and objects
     * - Partial configurations with only objects (using existing schemas and registers)
     * - Objects with references to existing schemas and registers
     * - Version checking to prevent unnecessary imports
     *
     * @param array       $data    The configuration JSON content
     * @param string|null $owner   The owner of the imported data
     * @param string|null $appId   The app ID for version tracking (optional, will be extracted from data if not provided)
     * @param string|null $version The version for version tracking (optional, will be extracted from data if not provided)
     * @param bool        $force   Force import even if the same or newer version already exists
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
    public function importFromJson(array $data, ?string $owner=null, ?string $appId=null, ?string $version=null, bool $force=false): array
    {
        // Ensure data is consistently an array by converting any stdClass objects
        $data = $this->ensureArrayStructure($data);

        // Extract appId and version from data if not provided as parameters
        if ($appId === null && isset($data['appId']) === true) {
            $appId = $data['appId'];
        }

        if ($version === null && isset($data['version']) === true) {
            $version = $data['version'];
        }

        // Perform version check if appId and version are available (unless force is enabled)
        if ($appId !== null && $version !== null && $force === false) {
            $storedVersion = $this->appConfig->getValueString('openregister', "imported_config_{$appId}_version", '');

            // If we have a stored version, compare it with the current version
            if ($storedVersion !== '' && version_compare($version, $storedVersion, '<=') === true) {
                $this->logger->info("Skipping import for app {$appId} - current version {$version} is not newer than stored version {$storedVersion}");

                // Return empty result to indicate no import was performed
                return [
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
            }
        }//end if

        // Log force import if enabled
        if ($force === true && $appId !== null && $version !== null) {
            $this->logger->info("Force import enabled for app {$appId} version {$version} - bypassing version check");
        }

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
            $slugsAndIdsMap = $this->schemaMapper->getSlugToIdMap();
            $this->logger->info(
                    'Starting schema import process',
                    [
                        'totalSchemas' => count($data['components']['schemas']),
                        'schemaKeys'   => array_keys($data['components']['schemas']),
                    ]
                    );

            foreach ($data['components']['schemas'] as $key => $schemaData) {
                $this->logger->info(
                        'Processing schema',
                        [
                            'schemaKey'   => $key,
                            'schemaTitle' => $schemaData['title'] ?? 'no title',
                            'schemaSlug'  => $schemaData['slug'] ?? 'no slug',
                        ]
                        );

                if (isset($schemaData['title']) === false && is_string($key) === true) {
                    $schemaData['title'] = $key;
                }

                try {
                    $schema = $this->importSchema(data: $schemaData, slugsAndIdsMap: $slugsAndIdsMap, owner: $owner, appId: $appId, version: $version, force: $force);
                    if ($schema !== null) {
                        // Store schema in map by slug for reference.
                        $this->schemasMap[$schema->getSlug()] = $schema;
                        $result['schemas'][] = $schema;
                        $this->logger->info(
                                'Successfully imported schema',
                                [
                                    'schemaKey'  => $key,
                                    'schemaSlug' => $schema->getSlug(),
                                    'schemaId'   => $schema->getId(),
                                ]
                                );
                    } else {
                        $this->logger->warning(
                                'Schema import returned null',
                                [
                                    'schemaKey'  => $key,
                                    'schemaData' => array_keys($schemaData),
                                ]
                                );
                    }//end if
                } catch (\Exception $e) {
                    $this->logger->error(
                            'Failed to import schema',
                            [
                                'schemaKey' => $key,
                                'error'     => $e->getMessage(),
                                'trace'     => $e->getTraceAsString(),
                            ]
                            );
                    // Continue with other schemas instead of failing the entire import
                }//end try
            }//end foreach

            $this->logger->info(
                    'Schema import process completed',
                    [
                        'importedCount'   => count($result['schemas']),
                        'importedSchemas' => array_map(fn($schema) => $schema->getSlug(), $result['schemas']),
                    ]
                    );
        }//end if

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

                $register = $this->importRegister(data: $registerData, owner: $owner, appId: $appId, version: $version, force: $force);
                if ($register !== null) {
                    // Store register in map by slug for reference.
                    $this->registersMap[$slug] = $register;
                    $result['registers'][]     = $register;
                }
            }//end foreach
        }//end if

        // Build register slug to ID map after register import
        $registerSlugToId = [];
        foreach ($this->registersMap as $slug => $register) {
            if ($register instanceof \OCA\OpenRegister\Db\Register) {
                $registerSlugToId[$slug] = $register->getId();
            }
        }

        // Build schema slug to ID map after schema import
        $schemaSlugToId = [];
        foreach ($this->schemasMap as $slug => $schema) {
            if ($schema instanceof \OCA\OpenRegister\Db\Schema) {
                $schemaSlugToId[$slug] = $schema->getId();
            }
        }

        // Process and import objects.
        if (isset($data['components']['objects']) === true && is_array($data['components']['objects']) === true) {
            foreach ($data['components']['objects'] as $objectData) {
                // Log raw values before any mapping
                $rawRegister = $objectData['@self']['register'] ?? null;
                $rawSchema   = $objectData['@self']['schema'] ?? null;
                $rawSlug     = $objectData['@self']['slug'] ?? null;

                // Only import objects with a slug
                $slug = $rawSlug;
                if (empty($slug)) {
                    continue;
                }

                // Map register and schema
                $registerId = $registerSlugToId[$rawRegister] ?? null;
                $schemaId   = $schemaSlugToId[$rawSchema] ?? null;
                if (empty($registerId) || empty($schemaId)) {
                    continue;
                }

                // Use ObjectService::searchObjects to find existing object by register+schema+slug
                $search = [
                    '@self'  => [
                        'register' => (int) $registerId,
                // ensure integer
                        'schema'   => (int) $schemaId,
                // ensure integer
                        'slug'     => $slug,
                // string
                    ],
                    '_limit' => 1,
                ];
                $this->logger->debug('Import object search filter', ['filter' => $search]);
                // Log what we are searching for (now as warning for visibility)
                $this->logger->warning(
                        'Import: searching for existing object',
                        [
                            'registerId'   => $registerId,
                            'schemaId'     => $schemaId,
                            'slug'         => $slug,
                            'searchFilter' => $search,
                        ]
                        );
                // TEMP: Always log search filter to Docker logs for debugging
                $results = $this->objectService->searchObjects($search, true, true);
                $this->logger->warning(
                        'Import: search result',
                        [
                            'resultType'  => gettype($results),
                            'resultCount' => is_array($results) ? count($results) : null,
                            'resultValue' => $results,
                        ]
                        );
                $existingObject = is_array($results) && count($results) > 0 ? $results[0] : null;
                if (!$existingObject) {
                    $this->logger->warning(
                            'Import: No existing object found for update',
                            [
                                'registerId'   => $registerId,
                                'schemaId'     => $schemaId,
                                'slug'         => $slug,
                                'searchFilter' => $search,
                            ]
                            );
                    $this->logger->error(
                            'No existing object found for insert, about to insert new object',
                            [
                                'registerId' => $registerId,
                                'schemaId'   => $schemaId,
                                'slug'       => $slug,
                                'search'     => $search,
                                'objectData' => $objectData,
                            ]
                            );
                }//end if

                if ($existingObject) {
                    $existingObjectData = is_array($existingObject) ? $existingObject : $existingObject->jsonSerialize();
                    $importedVersion    = $objectData['@self']['version'] ?? $objectData['version'] ?? '1.0.0';
                    $existingVersion    = $existingObjectData['@self']['version'] ?? $existingObjectData['version'] ?? '1.0.0';
                    if (version_compare($importedVersion, $existingVersion, '>')) {
                        $uuid   = $existingObjectData['@self']['id'] ?? $existingObjectData['id'] ?? null;
                        $object = $this->objectService->saveObject(
                            object: $objectData,
                            register: (int) $registerId,
                            schema: (int) $schemaId,
                            uuid: $uuid
                        );
                        if ($object !== null) {
                            $result['objects'][] = $object;
                        }
                    } else {
                        $this->logger->info(
                                'Skipped object update: imported version not higher',
                                [
                                    'slug'            => $slug,
                                    'register'        => $registerId,
                                    'schema'          => $schemaId,
                                    'importedVersion' => $importedVersion,
                                    'existingVersion' => $existingVersion,
                                ]
                                );
                        continue;
                    }//end if
                } else {
                    // Create new object
                    $object = $this->objectService->saveObject(
                        object: $objectData,
                        register: (int) $registerId,
                        schema: (int) $schemaId
                    );
                    if ($object !== null) {
                        $result['objects'][] = $object;
                    }
                }//end if
            }//end foreach
        }//end if

        // Process OpenConnector integration if available.
        $openConnector = $this->getOpenConnector();
        if ($openConnector === true) {
            $openConnectorResult = $this->openConnectorConfigurationService->importConfiguration($data);
            $result = array_replace_recursive($openConnectorResult, $result);
        }

        // Create or update configuration entity to track imported data
        if ($appId !== null && $version !== null && (count($result['registers']) > 0 || count($result['schemas']) > 0 || count($result['objects']) > 0)) {
            $this->createOrUpdateConfiguration($data, $appId, $version, $result, $owner);
        }

        // Store the version information if appId and version are available
        if ($appId !== null && $version !== null) {
            $this->appConfig->setValueString('openregister', "imported_config_{$appId}_version", $version);
            $this->logger->info("Stored version {$version} for app {$appId} after successful import");
        }

        return $result;

    }//end importFromJson()


    /**
     * Create or update a configuration entity to track imported data
     *
     * This method creates or updates a Configuration entity to track which registers,
     * schemas, and objects are managed by a specific app configuration.
     *
     * @param array       $data    The original import data
     * @param string      $appId   The application ID
     * @param string      $version The version of the import
     * @param array       $result  The import result containing created entities
     * @param string|null $owner   The owner of the configuration (for backwards compatibility)
     *
     * @return Configuration The created or updated configuration
     *
     * @throws Exception If configuration creation/update fails
     */
    private function createOrUpdateConfiguration(array $data, string $appId, string $version, array $result, ?string $owner=null): Configuration
    {
        try {
            // Ensure data is consistently an array by converting any stdClass objects
            $data = $this->ensureArrayStructure($data);

            // Try to find existing configuration for this app
            $existingConfiguration = null;
            try {
                $configurations = $this->configurationMapper->findByApp($appId);
                if (count($configurations) > 0) {
                    $existingConfiguration = $configurations[0];
                    // Get the first (most recent) configuration
                }
            } catch (\Exception $e) {
                // No existing configuration found, we'll create a new one
            }

            // Extract title and description from import data
            $title       = $data['info']['title'] ?? $data['title'] ?? "Configuration for {$appId}";
            $description = $data['info']['description'] ?? $data['description'] ?? "Imported configuration for application {$appId}";
            $type        = $data['type'] ?? 'imported';

            // Collect IDs of imported entities
            $registerIds = [];
            foreach ($result['registers'] as $register) {
                if ($register instanceof Register) {
                    $registerIds[] = $register->getId();
                }
            }

            $schemaIds = [];
            foreach ($result['schemas'] as $schema) {
                if ($schema instanceof Schema) {
                    $schemaIds[] = $schema->getId();
                }
            }

            $objectIds = [];
            foreach ($result['objects'] as $object) {
                if ($object instanceof ObjectEntity) {
                    $objectIds[] = $object->getId();
                }
            }

            if ($existingConfiguration !== null) {
                // Update existing configuration
                $existingConfiguration->setTitle($title);
                $existingConfiguration->setDescription($description);
                $existingConfiguration->setType($type);
                $existingConfiguration->setVersion($version);

                // Merge with existing IDs to avoid losing previously imported entities
                $existingRegisterIds = $existingConfiguration->getRegisters();
                $existingSchemaIds   = $existingConfiguration->getSchemas();
                $existingObjectIds   = $existingConfiguration->getObjects();

                $existingConfiguration->setRegisters(array_unique(array_merge($existingRegisterIds, $registerIds)));
                $existingConfiguration->setSchemas(array_unique(array_merge($existingSchemaIds, $schemaIds)));
                $existingConfiguration->setObjects(array_unique(array_merge($existingObjectIds, $objectIds)));

                $configuration = $this->configurationMapper->update($existingConfiguration);
                $this->logger->info("Updated existing configuration for app {$appId} with version {$version}");
            } else {
                // Create new configuration
                $configuration = new Configuration();
                $configuration->setTitle($title);
                $configuration->setDescription($description);
                $configuration->setType($type);
                $configuration->setApp($appId);
                $configuration->setVersion($version);
                $configuration->setRegisters($registerIds);
                $configuration->setSchemas($schemaIds);
                $configuration->setObjects($objectIds);

                $configuration = $this->configurationMapper->insert($configuration);
                $this->logger->info("Created new configuration for app {$appId} with version {$version}");
            }//end if

            return $configuration;
        } catch (\Exception $e) {
            $this->logger->error("Failed to create or update configuration for app {$appId}: ".$e->getMessage());
            throw new Exception("Failed to create or update configuration: ".$e->getMessage());
        }//end try

    }//end createOrUpdateConfiguration()


    /**
     * Import a register from configuration data
     *
     * @param array       $data  The register data.
     * @param string|null $owner The owner of the register.
     *
     * @return Register|null The imported register or null if skipped.
     */
    private function importRegister(array $data, ?string $owner=null, ?string $appId=null, ?string $version=null, bool $force=false): ?Register
    {
        try {
            // Ensure data is consistently an array by converting any stdClass objects
            $data = $this->ensureArrayStructure($data);

            // Remove id and uuid from the data.
            unset($data['id'], $data['uuid']);

            // Check if register already exists by slug.
            $existingRegister = null;
            try {
                $existingRegister = $this->registerMapper->find(strtolower($data['slug']));
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Register doesn't exist, we'll create a new one.
            } catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
                // Multiple registers found with the same identifier
                $this->handleDuplicateRegisterError($data['slug'], $appId ?? 'unknown', $version ?? 'unknown');
            }

            if ($existingRegister !== null) {
                // Compare versions using version_compare for proper semver comparison.
                if ($force === false && version_compare($data['version'], $existingRegister->getVersion(), '<=') === true) {
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
     * This method imports a schema and converts slugs back to internal IDs.
     * It handles both the new objectConfiguration structure (with register and schema slugs)
     * and the legacy register property structure for backward compatibility.
     * Schema and register references are resolved to their numeric IDs in the database.
     *
     * @param array       $data           The schema data with slugs to be converted to IDs
     * @param array       $slugsAndIdsMap Slugs with their IDs for quick lookup
     * @param string|null $owner          The owner of the schema
     * @param string|null $appId          The application ID importing the schema
     * @param string|null $version        The version of the import
     * @param bool        $force          Force import even if version is not newer
     *
     * @return Schema|null The imported schema or null if skipped
     */
    private function importSchema(array $data, array $slugsAndIdsMap, ?string $owner=null, ?string $appId=null, ?string $version=null, bool $force=false): ?Schema
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
                    // Ensure property is always an array
                    if (is_object($property)) {
                        $property = (array) $property;
                    }

                    // Only set title to key if no title exists, to preserve existing titles
                    if (isset($property['title']) === false || empty($property['title']) === true) {
                        $property['title'] = $key;
                    }

                    // Fix empty objects that became arrays during JSON deserialization
                    // objectConfiguration and fileConfiguration should always be objects, not arrays
                    if (isset($property['objectConfiguration']) === true) {
                        if (is_array($property['objectConfiguration']) && $property['objectConfiguration'] === []) {
                            $property['objectConfiguration'] = new \stdClass();
                        }
                    }

                    if (isset($property['fileConfiguration']) === true) {
                        if (is_array($property['fileConfiguration']) && $property['fileConfiguration'] === []) {
                            $property['fileConfiguration'] = new \stdClass();
                        }
                    }

                    // Do the same for array items
                    if (isset($property['items']) === true) {
                        // Ensure items is an array first
                        if (is_object($property['items'])) {
                            $property['items'] = (array) $property['items'];
                        }

                        if (isset($property['items']['objectConfiguration']) === true) {
                            if (is_array($property['items']['objectConfiguration']) && $property['items']['objectConfiguration'] === []) {
                                $property['items']['objectConfiguration'] = new \stdClass();
                            }
                        }

                        if (isset($property['items']['fileConfiguration']) === true) {
                            if (is_array($property['items']['fileConfiguration']) && $property['items']['fileConfiguration'] === []) {
                                $property['items']['fileConfiguration'] = new \stdClass();
                            }
                        }
                    }

                    if (isset($property['type']) === false) {
                        $property['type'] = 'string';
                    }

                    if (isset($property['format']) === true && ($property['format'] === 'string' || $property['format'] === 'binary' || $property['format'] === 'byte')) {
                        unset($property['format']);
                    }

                    if (isset($property['items']['format']) === true && ($property['items']['format'] === 'string' || $property['items']['format'] === 'binary' || $property['items']['format'] === 'byte')) {
                        unset($property['items']['format']);
                    }

                    // Check if we have the schema for the slug and set that id.
                    if (isset($property['$ref']) === true) {
                        if (isset($slugsAndIdsMap[$property['$ref']]) === true) {
                            $property['$ref'] = $slugsAndIdsMap[$property['$ref']];
                        } else if (isset($this->schemasMap[$property['$ref']]) === true) {
                            $property['$ref'] = $this->schemasMap[$property['$ref']]->getId();
                        }
                    }

                    if (isset($property['items']['$ref']) === true) {
                        if (isset($slugsAndIdsMap[$property['items']['$ref']]) === true) {
                            $property['items']['$ref'] = $slugsAndIdsMap[$property['items']['$ref']];
                        } else if (isset($this->schemasMap[$property['items']['$ref']]) === true) {
                            $property['$ref'] = $this->schemasMap[$property['items']['$ref']]->getId();
                        }
                    }

                    // Ensure objectConfiguration is an array for consistent access before any checks
                    if (isset($property['objectConfiguration']) && is_object($property['objectConfiguration'])) {
                        $property['objectConfiguration'] = (array) $property['objectConfiguration'];
                    }

                    // Handle register slug/ID in objectConfiguration (new structure)
                    if (isset($property['objectConfiguration']['register']) === true) {
                        $registerSlug = $property['objectConfiguration']['register'];
                        if (isset($this->registersMap[$registerSlug]) === true) {
                            $property['objectConfiguration']['register'] = $this->registersMap[$registerSlug]->getId();
                        } else {
                            // Try to find existing register in database
                            try {
                                $existingRegister = $this->registerMapper->find($registerSlug);
                                $property['objectConfiguration']['register'] = $existingRegister->getId();
                                // Add to map for future reference
                                $this->registersMap[$registerSlug] = $existingRegister;
                            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                                $this->logger->warning(
                                    sprintf('Register with slug %s not found during schema property import.', $registerSlug)
                                );
                                // Remove the register reference if not found
                                unset($property['objectConfiguration']['register']);
                            }
                        }
                    }

                    // Handle schema slug/ID in objectConfiguration (new structure)
                    if (isset($property['objectConfiguration']['schema']) === true) {
                        $schemaSlug = $property['objectConfiguration']['schema'];
                        // Only process non-empty schema slugs
                        if (!empty($schemaSlug)) {
                            if (isset($this->schemasMap[$schemaSlug]) === true) {
                                $property['objectConfiguration']['schema'] = $this->schemasMap[$schemaSlug]->getId();
                            } else {
                                // Try to find existing schema in database
                                try {
                                    $existingSchema = $this->schemaMapper->find($schemaSlug);
                                    $property['objectConfiguration']['schema'] = $existingSchema->getId();
                                    // Add to map for future reference
                                    $this->schemasMap[$schemaSlug] = $existingSchema;
                                } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                                    $this->logger->warning(
                                        sprintf('Schema with slug %s not found during schema property import.', $schemaSlug)
                                    );
                                    // Remove the schema reference if not found
                                    unset($property['objectConfiguration']['schema']);
                                }
                            }
                        }

                        // If schemaSlug is empty, preserve the empty schema field as-is
                    }//end if

                    // Ensure items and its objectConfiguration are arrays for consistent access before any checks
                    if (isset($property['items'])) {
                        if (is_object($property['items'])) {
                            $property['items'] = (array) $property['items'];
                        }

                        if (isset($property['items']['objectConfiguration']) && is_object($property['items']['objectConfiguration'])) {
                            $property['items']['objectConfiguration'] = (array) $property['items']['objectConfiguration'];
                        }
                    }

                    // Handle register slug/ID in array items objectConfiguration (new structure)
                    if (isset($property['items']['objectConfiguration']['register']) === true) {
                        $registerSlug = $property['items']['objectConfiguration']['register'];
                        if (isset($this->registersMap[$registerSlug]) === true) {
                            $property['items']['objectConfiguration']['register'] = $this->registersMap[$registerSlug]->getId();
                        } else {
                            // Try to find existing register in database
                            try {
                                $existingRegister = $this->registerMapper->find($registerSlug);
                                $property['items']['objectConfiguration']['register'] = $existingRegister->getId();
                                // Add to map for future reference
                                $this->registersMap[$registerSlug] = $existingRegister;
                            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                                $this->logger->warning(
                                    sprintf('Register with slug %s not found during array items schema property import.', $registerSlug)
                                );
                                // Remove the register reference if not found
                                unset($property['items']['objectConfiguration']['register']);
                            }
                        }
                    }

                    // Handle schema slug/ID in array items objectConfiguration (new structure)
                    if (isset($property['items']['objectConfiguration']['schema']) === true) {
                        $schemaSlug = $property['items']['objectConfiguration']['schema'];
                        // Only process non-empty schema slugs
                        if (!empty($schemaSlug)) {
                            if (isset($this->schemasMap[$schemaSlug]) === true) {
                                $property['items']['objectConfiguration']['schema'] = $this->schemasMap[$schemaSlug]->getId();
                            } else {
                                // Try to find existing schema in database
                                try {
                                    $existingSchema = $this->schemaMapper->find($schemaSlug);
                                    $property['items']['objectConfiguration']['schema'] = $existingSchema->getId();
                                    // Add to map for future reference
                                    $this->schemasMap[$schemaSlug] = $existingSchema;
                                } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                                    $this->logger->warning(
                                        sprintf('Schema with slug %s not found during array items schema property import.', $schemaSlug)
                                    );
                                    // Remove the schema reference if not found
                                    unset($property['items']['objectConfiguration']['schema']);
                                }
                            }
                        }

                        // If schemaSlug is empty, preserve the empty schema field as-is
                    }//end if

                    // Legacy support: Handle old register property structure
                    if (isset($property['register']) === true) {
                        if (isset($slugsAndIdsMap[$property['register']]) === true) {
                            $property['register'] = $slugsAndIdsMap[$property['register']];
                        } else if (isset($this->registersMap[$property['register']]) === true) {
                            $property['register'] = $this->registersMap[$property['register']]->getId();
                        }
                    }

                    if (isset($property['items']['register']) === true) {
                        if (isset($slugsAndIdsMap[$property['items']['register']]) === true) {
                            $property['items']['register'] = $slugsAndIdsMap[$property['items']['register']];
                        } else if (isset($this->registersMap[$property['items']['register']]) === true) {
                            $property['items']['register'] = $this->registersMap[$property['items']['register']]->getId();
                        }
                    }
                }//end foreach
            }//end if

            // Check if schema already exists by slug.
            $existingSchema = null;
            try {
                $existingSchema = $this->schemaMapper->find(strtolower($data['slug']));
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Schema doesn't exist, we'll create a new one.
            } catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
                // Multiple schemas found with the same identifier
                $this->handleDuplicateSchemaError($data['slug'], $appId ?? 'unknown', $version ?? 'unknown');
            }

            if ($existingSchema !== null) {
                // Compare versions using version_compare for proper semver comparison.
                if ($force === false && version_compare($data['version'], $existingSchema->getVersion(), '<=') === true) {
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
     * This method imports objects using a combination of register, schema slug, and object name
     * to determine uniqueness instead of UUID. It also performs version checking to prevent
     * downgrading existing objects to older versions.
     *
     * @param array       $data  The object data.
     * @param string|null $owner The owner of the object.
     *
     * @return ObjectEntity|null The imported object or null if skipped.
     * @throws Exception If object import fails.
     */
    private function importObject(array $data, ?string $owner=null): ?ObjectEntity
    {
        try {
            // Ensure data is consistently an array by converting any stdClass objects
            $data = $this->ensureArrayStructure($data);

            // Validate required @self metadata
            if (!isset($data['@self']['register']) || !isset($data['@self']['schema']) || !isset($data['name'])) {
                $this->logger->warning('Object data missing required @self metadata (register, schema) or name field');
                return null;
            }

            $registerId    = $data['@self']['register'];
            $schemaId      = $data['@self']['schema'];
            $objectName    = $data['name'];
            $objectVersion = $data['@self']['version'] ?? $data['version'] ?? '1.0.0';

            // Find existing objects using register, schema, and name combination for uniqueness
            $existingObjects = $this->objectEntityMapper->findAll(
                    [
                        'filters' => [
                            'register' => $registerId,
                            'schema'   => $schemaId,
                            'name'     => $objectName,
                        ],
                    ]
                    );

            $existingObject = null;
            if (!empty($existingObjects)) {
                $existingObject = $existingObjects[0];
                // Take the first match
                $existingObjectData = $existingObject->jsonSerialize();
                $existingVersion    = $existingObjectData['@self']['version'] ?? $existingObjectData['version'] ?? '1.0.0';

                // Compare versions using version_compare for proper semver comparison
                if (version_compare($objectVersion, $existingVersion, '<=')) {
                    $this->logger->info(
                        sprintf(
                            'Skipping object import as existing version (%s) is newer or equal to import version (%s) for object: %s',
                            $existingVersion,
                            $objectVersion,
                            $objectName
                        )
                    );
                    // Return the existing object without updating
                    return $existingObject;
                }

                $this->logger->info(
                    sprintf(
                        'Updating existing object "%s" from version %s to %s',
                        $objectName,
                        $existingVersion,
                        $objectVersion
                    )
                );
            } else {
                $this->logger->info(
                    sprintf(
                        'Creating new object "%s" with version %s',
                        $objectName,
                        $objectVersion
                    )
                );
            }//end if

            // Set the register and schema context for the object service
            $this->objectService->setRegister($registerId);
            $this->objectService->setSchema($schemaId);

            // Ensure version is set in @self metadata
            if (!isset($data['@self']['version'])) {
                $data['@self']['version'] = $objectVersion;
            }

            // Use existing object's UUID if available, otherwise let the service generate a new one
            $uuid = $existingObject ? $existingObject->getUuid() : ($data['uuid'] ?? $data['id'] ?? null);

            // Save the object using the object service
            $object = $this->objectService->saveObject(
                object: $data,
                uuid: $uuid
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
            $this->logger->error('Failed to import configuration from Open Connector: '.$e->getMessage());
            throw new Exception('Failed to import configuration from Open Connector: '.$e->getMessage());
        }//end try

    }//end importFromOpenConnector()


    /**
     * Get the currently configured version for a specific app.
     *
     * This method retrieves the stored version information for an app
     * that was previously imported through the importFromJson method.
     *
     * @param string $appId The application ID to get the version for
     *
     * @return string|null The stored version string, or null if not found
     *
     * @phpstan-return string|null
     */
    public function getConfiguredAppVersion(string $appId): ?string
    {
        try {
            $storedVersion = $this->appConfig->getValueString('openregister', "imported_config_{$appId}_version", '');

            return $storedVersion !== '' ? $storedVersion : null;
        } catch (\Exception $e) {
            $this->logger->error("Failed to get configured version for app {$appId}: ".$e->getMessage());
            return null;
        }

    }//end getConfiguredAppVersion()


    /**
     * Handle duplicate schema error with detailed information.
     *
     * This method provides a clear error message when duplicate schemas are found,
     * including information about which identifier is duplicated and from which app/version.
     *
     * @param string $slug    The schema slug that has duplicates
     * @param string $appId   The application ID that encountered the duplicate
     * @param string $version The version of the import that encountered the duplicate
     *
     * @throws \Exception Always throws an exception with detailed duplicate information
     */
    private function handleDuplicateSchemaError(string $slug, string $appId, string $version): void
    {
        // Get details about the duplicate schemas
        $duplicateInfo = $this->getDuplicateSchemaInfo($slug);

        $errorMessage = sprintf(
            "Duplicate schema detected during import from app '%s' (version %s). "."Schema with slug '%s' has multiple entries in the database: %s. "."Please resolve this by removing duplicate entries or updating the schema slugs to be unique. "."You can identify duplicates by checking schemas with the same slug, uuid, or id.",
            $appId,
            $version,
            $slug,
            $duplicateInfo
        );

        $this->logger->error($errorMessage);
        throw new \Exception($errorMessage);

    }//end handleDuplicateSchemaError()


    /**
     * Get detailed information about duplicate schemas.
     *
     * @param string $slug The schema slug to check for duplicates
     *
     * @return string Formatted string with duplicate schema information
     */
    private function getDuplicateSchemaInfo(string $slug): string
    {
        try {
            // Try to get all schemas with this slug to provide detailed info
            $schemas    = $this->schemaMapper->findAll();
            $duplicates = array_filter(
                    $schemas,
                    function ($schema) use ($slug) {
                        return strtolower($schema->getSlug()) === strtolower($slug);
                    }
                    );

            if (count($duplicates) <= 1) {
                return "Unable to retrieve detailed duplicate information";
            }

            $info = [];
            foreach ($duplicates as $schema) {
                $info[] = sprintf(
                    "ID: %s, UUID: %s, Title: '%s', Created: %s",
                    $schema->getId(),
                    $schema->getUuid(),
                    $schema->getTitle(),
                    $schema->getCreated() ? $schema->getCreated()->format('Y-m-d H:i:s') : 'unknown'
                );
            }

            return implode('; ', $info);
        } catch (\Exception $e) {
            return "Unable to retrieve duplicate information: ".$e->getMessage();
        }//end try

    }//end getDuplicateSchemaInfo()


    /**
     * Handle duplicate register error with detailed information.
     *
     * This method provides a clear error message when duplicate registers are found,
     * including information about which identifier is duplicated and from which app/version.
     *
     * @param string $slug    The register slug that has duplicates
     * @param string $appId   The application ID that encountered the duplicate
     * @param string $version The version of the import that encountered the duplicate
     *
     * @throws \Exception Always throws an exception with detailed duplicate information
     */
    private function handleDuplicateRegisterError(string $slug, string $appId, string $version): void
    {
        // Get details about the duplicate registers
        $duplicateInfo = $this->getDuplicateRegisterInfo($slug);

        $errorMessage = sprintf(
            "Duplicate register detected during import from app '%s' (version %s). "."Register with slug '%s' has multiple entries in the database: %s. "."Please resolve this by removing duplicate entries or updating the register slugs to be unique. "."You can identify duplicates by checking registers with the same slug, uuid, or id.",
            $appId,
            $version,
            $slug,
            $duplicateInfo
        );

        $this->logger->error($errorMessage);
        throw new \Exception($errorMessage);

    }//end handleDuplicateRegisterError()


    /**
     * Get detailed information about duplicate registers.
     *
     * @param string $slug The register slug to check for duplicates
     *
     * @return string Formatted string with duplicate register information
     */
    private function getDuplicateRegisterInfo(string $slug): string
    {
        try {
            // Try to get all registers with this slug to provide detailed info
            $registers  = $this->registerMapper->findAll();
            $duplicates = array_filter(
                    $registers,
                    function ($register) use ($slug) {
                        return strtolower($register->getSlug()) === strtolower($slug);
                    }
                    );

            if (count($duplicates) <= 1) {
                return "Unable to retrieve detailed duplicate information";
            }

            $info = [];
            foreach ($duplicates as $register) {
                $info[] = sprintf(
                    "ID: %s, UUID: %s, Title: '%s', Created: %s",
                    $register->getId(),
                    $register->getUuid(),
                    $register->getTitle(),
                    $register->getCreated() ? $register->getCreated()->format('Y-m-d H:i:s') : 'unknown'
                );
            }

            return implode('; ', $info);
        } catch (\Exception $e) {
            return "Unable to retrieve duplicate information: ".$e->getMessage();
        }//end try

    }//end getDuplicateRegisterInfo()


}//end class
