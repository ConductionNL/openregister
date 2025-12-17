<?php
/**
 * OpenRegister Import Handler
 *
 * This file contains the handler class for importing configurations
 * from various sources in the OpenRegister application.
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

use Exception;
use stdClass;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ImportHandler
 *
 * Handles importing configurations from JSON data, files, and applications.
 *
 * @package OCA\OpenRegister\Service\Configuration
 */
class ImportHandler
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
     * HTTP client for fetching JSON from URLs.
     *
     * @var Client The Guzzle HTTP client instance.
     */
    private readonly Client $client;

    /**
     * App config for storing version information.
     *
     * @var IAppConfig The app config instance.
     */
    private readonly IAppConfig $appConfig;

    /**
     * Logger instance for logging operations.
     *
     * @var LoggerInterface The logger instance.
     */
    private readonly LoggerInterface $logger;

    /**
     * App data path for resolving file paths.
     *
     * @var string The app data path.
     */
    private readonly string $appDataPath;

    /**
     * Upload handler for processing uploaded JSON data.
     *
     * @var UploadHandler The upload handler instance.
     */
    private readonly UploadHandler $uploadHandler;

    /**
     * Object service for object CRUD operations.
     *
     * @var ObjectService|null The object service instance.
     */
    private ?ObjectService $objectService = null;

    /**
     * OpenConnector configuration service for integration.
     *
     * @var mixed|null The OpenConnector configuration service.
     */
    private mixed $openConnectorConfigurationService = null;

    /**
     * Map of registers indexed by slug during import.
     *
     * @var array<string, Register> Registers indexed by slug.
     */
    private array $registersMap = [];

    /**
     * Map of schemas indexed by slug during import.
     *
     * @var array<string, Schema> Schemas indexed by slug.
     */
    private array $schemasMap = [];


    /**
     * Constructor for ImportHandler.
     *
     * @param SchemaMapper        $schemaMapper        The schema mapper.
     * @param RegisterMapper      $registerMapper      The register mapper.
     * @param ObjectEntityMapper  $objectEntityMapper  The object entity mapper.
     * @param ConfigurationMapper $configurationMapper The configuration mapper.
     * @param Client              $client              The HTTP client for URL fetching.
     * @param IAppConfig          $appConfig           The app config.
     * @param LoggerInterface     $logger              The logger interface.
     * @param string              $appDataPath         The app data path.
     * @param UploadHandler       $uploadHandler       The upload handler.
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        ObjectEntityMapper $objectEntityMapper,
        ConfigurationMapper $configurationMapper,
        Client $client,
        IAppConfig $appConfig,
        LoggerInterface $logger,
        string $appDataPath,
        UploadHandler $uploadHandler
    ) {
        $this->schemaMapper        = $schemaMapper;
        $this->registerMapper      = $registerMapper;
        $this->objectEntityMapper  = $objectEntityMapper;
        $this->configurationMapper = $configurationMapper;
        $this->client        = $client;
        $this->appConfig     = $appConfig;
        $this->logger        = $logger;
        $this->appDataPath   = $appDataPath;
        $this->uploadHandler = $uploadHandler;

    }//end __construct()


    /**
     * Set the ObjectService dependency.
     *
     * This method allows setting the ObjectService after construction
     * to avoid circular dependency issues.
     *
     * @param ObjectService $objectService The object service instance.
     *
     * @return void
     */
    public function setObjectService(ObjectService $objectService): void
    {
        $this->objectService = $objectService;

    }//end setObjectService()


    /**
     * Set the OpenConnector ConfigurationService dependency.
     *
     * This method allows setting the OpenConnector configuration service
     * after construction for optional integration.
     *
     * @param mixed $service The OpenConnector configuration service.
     *
     * @return void
     */
    public function setOpenConnectorConfigurationService(mixed $service): void
    {
        $this->openConnectorConfigurationService = $service;

    }//end setOpenConnectorConfigurationService()


    /**
     * Decode JSON or YAML string data into PHP array.
     *
     * @param string      $data The string data to decode.
     * @param string|null $type The content type.
     *
     * @return array|null The decoded array or null if decoding fails.
     */
    public function decode(string $data, ?string $type): ?array
    {
        switch ($type) {
            case 'application/json':
                $phpArray = json_decode(json: $data, associative: true);
                break;
            case 'application/yaml':
                $phpArray = Yaml::parse(input: $data);
                break;
            default:
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

        $phpArray = $this->ensureArrayStructure($phpArray);
        return $phpArray;

    }//end decode()


    /**
     * Recursively converts stdClass objects to arrays.
     *
     * @param mixed $data The data to convert.
     *
     * @return array The converted array data.
     */
    public function ensureArrayStructure(mixed $data): array
    {
        if (is_object($data) === true) {
            $data = (array) $data;
        }

        if (is_array($data) === true) {
            foreach ($data as $key => $value) {
                if (is_object($value) === true || is_array($value) === true) {
                    $data[$key] = $this->ensureArrayStructure($value);
                }
            }
        }

        return $data;

    }//end ensureArrayStructure()


    /**
     * Get JSON data from uploaded file.
     *
     * @param array       $uploadedFile The uploaded file data.
     * @param string|null $_type        Unused parameter.
     *
     * @return array|JSONResponse The decoded array or error response.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getJSONfromFile(array $uploadedFile, ?string $_type=null): array|JSONResponse
    {
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
     * Fetch JSON from URL using HTTP GET.
     *
     * @param string $url The URL to fetch.
     *
     * @return array|JSONResponse The decoded array or error response.
     *
     * @throws GuzzleException
     */
    public function getJSONfromURL(string $url): array|JSONResponse
    {
        try {
            $response = $this->client->request('GET', $url);
        } catch (GuzzleException $e) {
            return new JSONResponse(data: ['error' => 'Failed to do a GET api-call on url: '.$url.' '.$e->getMessage()], statusCode: 400);
        }

        $responseBody = $response->getBody()->getContents();
        $contentType  = $response->getHeaderLine('Content-Type');
        $phpArray     = $this->decode(data: $responseBody, type: $contentType);

        if ($phpArray === null) {
            return new JSONResponse(
                data: ['error' => 'Failed to parse response body as JSON or YAML', 'Content-Type' => $contentType],
                statusCode: 400
            );
        }

        return $phpArray;

    }//end getJSONfromURL()


    /**
     * Get JSON data from request body.
     *
     * @param array|string $phpArray The request body data.
     *
     * @return array|JSONResponse The processed array or error response.
     */
    public function getJSONfromBody(array | string $phpArray): array|JSONResponse
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

        $phpArray = $this->ensureArrayStructure($phpArray);
        return $phpArray;

    }//end getJSONfromBody()


    /**
     * Import a register from configuration data.
     *
     * @param array       $data    The register data.
     * @param string|null $owner   The owner of the register.
     * @param string|null $appId   The application ID.
     * @param string|null $version The version.
     * @param bool        $force   Force import even if version is not newer.
     *
     * @return Register The imported register.
     *
     * @throws Exception If import fails.
     */
    public function importRegister(array $data, ?string $owner=null, ?string $appId=null, ?string $version=null, bool $force=false): Register
    {
        try {
            // Ensure data is consistently an array by converting any stdClass objects.
            $data = $this->ensureArrayStructure($data);

            // Remove id, uuid, and organisation from the data.
            // Organisation is instance-specific and should not be imported.
            unset($data['id'], $data['uuid'], $data['organisation']);

            // Check if register already exists by slug.
            $existingRegister = null;
            try {
                $existingRegister = $this->registerMapper->find(strtolower($data['slug']));
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // Register doesn't exist in current organisation context, we'll create a new one.
                $this->logger->info(message: "Register '{$data['slug']}' not found in current organisation context, will create new one");
            } catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
                // Multiple registers found with the same identifier.
                $this->handleDuplicateRegisterError(slug: $data['slug'], appId: $appId ?? 'unknown', version: $version ?? 'unknown');
            }

            if ($existingRegister !== null) {
                // Compare versions using version_compare for proper semver comparison.
                if ($force === false && version_compare($data['version'], $existingRegister->getVersion() ?? '0.0.0', '<=') === true) {
                    $this->logger->info(message: 'Skipping register import as existing version is newer or equal.');
                    // Even though we're skipping the update, we still need to add it to the map.
                    return $existingRegister;
                }

                // Update existing register.
                $existingRegister = $this->registerMapper->updateFromArray(id: $existingRegister->getId(), object: $data);
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
            $this->logger->error(message: 'Failed to import register: '.$e->getMessage());
            throw new Exception('Failed to import register: '.$e->getMessage());
        }//end try

    }//end importRegister()


    /**
     * Handle duplicate register error during import.
     *
     * @param string $slug    The register slug that has duplicates.
     * @param string $appId   The application ID attempting the import.
     * @param string $version The version being imported.
     *
     * @return void
     *
     * @throws Exception Always throws with duplicate register information.
     */
    private function handleDuplicateRegisterError(string $slug, string $appId, string $version): void
    {
        // Get details about the duplicate registers.
        $duplicateInfo = $this->getDuplicateRegisterInfo($slug);

        $errorMessage = sprintf(
            "Duplicate register detected during import from app '%s' (version %s). "
            ."Register with slug '%s' has multiple entries in the database: %s. "
            ."Please resolve this by removing duplicate entries or updating the register slugs to be unique. "
            ."You can identify duplicates by checking registers with the same slug, uuid, or id.",
            $appId,
            $version,
            $slug,
            $duplicateInfo
        );

        $this->logger->error(message: $errorMessage);
        throw new Exception($errorMessage);

    }//end handleDuplicateRegisterError()


    /**
     * Get detailed information about duplicate registers.
     *
     * @param string $slug The register slug to check for duplicates.
     *
     * @return string Formatted string with duplicate register information.
     */
    private function getDuplicateRegisterInfo(string $slug): string
    {
        try {
            // Try to get all registers with this slug to provide detailed info.
            $registers  = $this->registerMapper->findAll();
            $duplicates = array_filter(
                $registers,
                function ($register) use ($slug) {
                    return strtolower($register->getSlug() ?? '') === strtolower($slug);
                }
            );

            if (count($duplicates) <= 1) {
                return "Unable to retrieve detailed duplicate information";
            }

            $info = [];
            foreach ($duplicates as $register) {
                // Format created date.
                if ($register->getCreated() !== null) {
                    $registerCreated = $register->getCreated()->format('Y-m-d H:i:s');
                } else {
                    $registerCreated = 'unknown';
                }

                $info[] = sprintf(
                    "ID: %s, UUID: %s, Title: '%s', Created: %s",
                    $register->getId(),
                    $register->getUuid() ?? '',
                    $register->getTitle() ?? '',
                    $registerCreated
                );
            }

            return implode('; ', $info);
        } catch (Exception $e) {
            return "Unable to retrieve duplicate information: ".$e->getMessage();
        }//end try

    }//end getDuplicateRegisterInfo()


    /**
     * Handle duplicate schema error during import.
     *
     * @param string $slug    The schema slug that has duplicates.
     * @param string $appId   The application ID attempting the import.
     * @param string $version The version being imported.
     *
     * @return void
     *
     * @throws Exception Always throws with duplicate schema information.
     */
    private function handleDuplicateSchemaError(string $slug, string $appId, string $version): void
    {
        // Get details about the duplicate schemas.
        $duplicateInfo = $this->getDuplicateSchemaInfo($slug);

        $errorMessage = sprintf(
            "Duplicate schema detected during import from app '%s' (version %s). "
            ."Schema with slug '%s' has multiple entries in the database: %s. "
            ."Please resolve this by removing duplicate entries or updating the schema slugs to be unique. "
            ."You can identify duplicates by checking schemas with the same slug, uuid, or id.",
            $appId,
            $version,
            $slug,
            $duplicateInfo
        );

        $this->logger->error(message: $errorMessage);
        throw new Exception($errorMessage);

    }//end handleDuplicateSchemaError()


    /**
     * Get detailed information about duplicate schemas.
     *
     * @param string $slug The schema slug to check for duplicates.
     *
     * @return string Formatted string with duplicate schema information.
     */
    private function getDuplicateSchemaInfo(string $slug): string
    {
        try {
            // Try to get all schemas with this slug to provide detailed info.
            $schemas    = $this->schemaMapper->findAll();
            $duplicates = array_filter(
                $schemas,
                function ($schema) use ($slug) {
                    return strtolower($schema->getSlug() ?? '') === strtolower($slug);
                }
            );

            if (count($duplicates) <= 1) {
                return "Unable to retrieve detailed duplicate information";
            }

            $info = [];
            foreach ($duplicates as $schema) {
                // Format created date.
                if ($schema->getCreated() !== null) {
                    $createdDate = $schema->getCreated()->format('Y-m-d H:i:s');
                } else {
                    $createdDate = 'unknown';
                }

                $info[] = sprintf(
                    "ID: %s, UUID: %s, Title: '%s', Created: %s",
                    $schema->getId(),
                    $schema->getUuid() ?? '',
                    $schema->getTitle() ?? '',
                    $createdDate
                );
            }

            return implode('; ', $info);
        } catch (Exception $e) {
            return "Unable to retrieve duplicate information: ".$e->getMessage();
        }//end try

    }//end getDuplicateSchemaInfo()


    /**
     * Import a schema from configuration data.
     *
     * @param array       $data           The schema data with slugs to be converted to IDs.
     * @param array       $slugsAndIdsMap Slugs with their IDs for quick lookup.
     * @param string|null $owner          The owner of the schema.
     * @param string|null $appId          The application ID importing the schema.
     * @param string|null $version        The version of the import.
     * @param bool        $force          Force import even if version is not newer.
     *
     * @return Schema The imported schema.
     *
     * @throws Exception If import fails.
     */
    public function importSchema(
        array $data,
        array $slugsAndIdsMap,
        ?string $owner=null,
        ?string $appId=null,
        ?string $version=null,
        bool $force=false
    ): Schema {
        try {
            // Remove id, uuid, and organisation from the data.
            unset($data['id'], $data['uuid'], $data['organisation']);

            // Fix properties that don't have types or have invalid formats.
            if (($data['properties'] ?? null) !== null) {
                foreach ($data['properties'] as $key => &$property) {
                    // Ensure property is always an array.
                    if (is_object($property) === true) {
                        $property = (array) $property;
                    }

                    // Only set title to key if no title exists, to preserve existing titles.
                    if (isset($property['title']) === true || empty($property['title']) === true) {
                        $property['title'] = $key;
                    }

                    // Fix empty objects that became arrays during JSON deserialization.
                    if (($property['objectConfiguration'] ?? null) !== null) {
                        if (is_array($property['objectConfiguration']) === true && $property['objectConfiguration'] === []) {
                            $property['objectConfiguration'] = new stdClass();
                        }
                    }

                    if (($property['fileConfiguration'] ?? null) !== null) {
                        if (is_array($property['fileConfiguration']) === true && $property['fileConfiguration'] === []) {
                            $property['fileConfiguration'] = new stdClass();
                        }
                    }

                    // Do the same for array items.
                    if (($property['items'] ?? null) !== null) {
                        if (is_object($property['items']) === true) {
                            $property['items'] = (array) $property['items'];
                        }

                        if (($property['items']['objectConfiguration'] ?? null) !== null) {
                            if (is_array($property['items']['objectConfiguration']) === true && $property['items']['objectConfiguration'] === []) {
                                $property['items']['objectConfiguration'] = new stdClass();
                            }
                        }

                        if (($property['items']['fileConfiguration'] ?? null) !== null) {
                            if (is_array($property['items']['fileConfiguration']) === true && $property['items']['fileConfiguration'] === []) {
                                $property['items']['fileConfiguration'] = new stdClass();
                            }
                        }
                    }

                    if (isset($property['type']) === false) {
                        $property['type'] = 'string';
                    }

                    if (($property['format'] ?? null) !== null
                        && ($property['format'] === 'string'
                        || $property['format'] === 'binary'
                        || $property['format'] === 'byte')
                    ) {
                        unset($property['format']);
                    }

                    if (($property['items']['format'] ?? null) !== null
                        && ($property['items']['format'] === 'string'
                        || $property['items']['format'] === 'binary'
                        || $property['items']['format'] === 'byte')
                    ) {
                        unset($property['items']['format']);
                    }

                    // Check if we have the schema for the slug and set that id.
                    if (($property['$ref'] ?? null) !== null) {
                        if (($slugsAndIdsMap[$property['$ref']] ?? null) !== null) {
                            $property['$ref'] = $slugsAndIdsMap[$property['$ref']];
                        } else if (($this->schemasMap[$property['$ref']] ?? null) !== null) {
                            $property['$ref'] = $this->schemasMap[$property['$ref']]->getId();
                        }
                    }

                    if (($property['items']['$ref'] ?? null) !== null) {
                        if (($slugsAndIdsMap[$property['items']['$ref']] ?? null) !== null) {
                            $property['items']['$ref'] = $slugsAndIdsMap[$property['items']['$ref']];
                        } else if (($this->schemasMap[$property['items']['$ref']] ?? null) !== null) {
                            $property['$ref'] = $this->schemasMap[$property['items']['$ref']]->getId();
                        }
                    }

                    // Ensure objectConfiguration is an array for consistent access before any checks.
                    if (($property['objectConfiguration'] ?? null) !== null && is_object($property['objectConfiguration']) === true) {
                        $property['objectConfiguration'] = (array) $property['objectConfiguration'];
                    }

                    // Handle register slug/ID in objectConfiguration (new structure).
                    if (is_array($property['objectConfiguration'] ?? null) === true
                        && ($property['objectConfiguration']['register'] ?? null) !== null) {
                        $registerSlug = $property['objectConfiguration']['register'];
                        if (($this->registersMap[$registerSlug] ?? null) !== null) {
                            $property['objectConfiguration']['register'] = $this->registersMap[$registerSlug]->getId();
                        } else if ($registerSlug !== null) {
                            // Try to find existing register in database.
                            try {
                                $existingRegister = $this->registerMapper->find($registerSlug);
                                $property['objectConfiguration']['register'] = $existingRegister->getId();
                                $this->registersMap[$registerSlug]           = $existingRegister;
                            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                                $this->logger->info(
                                    sprintf(
                                        'Register with slug %s not found in current organisation context '
                                        .'during schema property import (will be resolved after registers are imported).',
                                        $registerSlug
                                    )
                                );
                                unset($property['objectConfiguration']['register']);
                            }
                        }
                    }//end if

                    // Handle schema slug/ID in objectConfiguration (new structure).
                    if (is_array($property['objectConfiguration'] ?? null) === true
                        && ($property['objectConfiguration']['schema'] ?? null) !== null) {
                        $schemaSlug = $property['objectConfiguration']['schema'];
                        if (empty($schemaSlug) === false) {
                            if (($this->schemasMap[$schemaSlug] ?? null) !== null) {
                                $property['objectConfiguration']['schema'] = $this->schemasMap[$schemaSlug]->getId();
                            } else {
                                // Try to find existing schema in database.
                                try {
                                    $existingSchema = $this->schemaMapper->find($schemaSlug);
                                    $property['objectConfiguration']['schema'] = $existingSchema->getId();
                                    $this->schemasMap[$schemaSlug] = $existingSchema;
                                } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                                    $this->logger->info(
                                        sprintf(
                                            'Schema with slug %s not found in current organisation context '
                                            .'during schema property import (will be resolved after schemas are imported).',
                                            $schemaSlug
                                        )
                                    );
                                    unset($property['objectConfiguration']['schema']);
                                }
                            }
                        }//end if
                    }//end if

                    // Ensure items and its objectConfiguration are arrays for consistent access.
                    if (($property['items'] ?? null) !== null) {
                        if (is_object($property['items']) === true) {
                            $property['items'] = (array) $property['items'];
                        }

                        if (is_array($property['items']) === true
                            && ($property['items']['objectConfiguration'] ?? null) !== null
                            && is_object($property['items']['objectConfiguration']) === true
                        ) {
                            $property['items']['objectConfiguration'] = (array) $property['items']['objectConfiguration'];
                        }
                    }

                    // Handle register slug/ID in array items objectConfiguration (new structure).
                    if (is_array($property['items'] ?? []) === true
                        && is_array($property['items']['objectConfiguration'] ?? []) === true
                        && isset($property['items']['objectConfiguration']['register']) === true
                    ) {
                        $registerSlug = $property['items']['objectConfiguration']['register'];
                        if (($this->registersMap[$registerSlug] ?? null) !== null) {
                            $property['items']['objectConfiguration']['register'] = $this->registersMap[$registerSlug]->getId();
                        } else if ($registerSlug !== null) {
                            // Try to find existing register in database.
                            try {
                                $existingRegister = $this->registerMapper->find($registerSlug);
                                $property['items']['objectConfiguration']['register'] = $existingRegister->getId();
                                $this->registersMap[$registerSlug] = $existingRegister;
                            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                                $this->logger->info(
                                    sprintf(
                                        'Register with slug %s not found in current organisation context '
                                        .'during array items schema property import '
                                        .'(will be resolved after registers are imported).',
                                        $registerSlug
                                    )
                                );
                                unset($property['items']['objectConfiguration']['register']);
                            }
                        }
                    }//end if

                    // Handle schema slug/ID in array items objectConfiguration (new structure).
                    if (is_array($property['items'] ?? []) === true
                        && is_array($property['items']['objectConfiguration'] ?? []) === true
                        && isset($property['items']['objectConfiguration']['schema']) === true
                    ) {
                        $schemaSlug = $property['items']['objectConfiguration']['schema'];
                        if (empty($schemaSlug) === false) {
                            if (($this->schemasMap[$schemaSlug] ?? null) !== null) {
                                $property['items']['objectConfiguration']['schema'] = $this->schemasMap[$schemaSlug]->getId();
                            } else {
                                // Try to find existing schema in database.
                                try {
                                    $existingSchema = $this->schemaMapper->find($schemaSlug);
                                    $property['items']['objectConfiguration']['schema'] = $existingSchema->getId();
                                    $this->schemasMap[$schemaSlug] = $existingSchema;
                                } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                                    $this->logger->info(
                                        sprintf(
                                            'Schema with slug %s not found in current organisation context '
                                            .'during array items schema property import '
                                            .'(will be resolved after schemas are imported).',
                                            $schemaSlug
                                        )
                                    );
                                    unset($property['items']['objectConfiguration']['schema']);
                                }
                            }
                        }//end if
                    }//end if

                    // Legacy support: Handle old register property structure.
                    if (($property['register'] ?? null) !== null) {
                        if (($slugsAndIdsMap[$property['register']] ?? null) !== null) {
                            $property['register'] = $slugsAndIdsMap[$property['register']];
                        } else if (($this->registersMap[$property['register']] ?? null) !== null) {
                            $property['register'] = $this->registersMap[$property['register']]->getId();
                        }
                    }

                    if (is_array($property['items'] ?? []) === true && isset($property['items']['register']) === true) {
                        if (($slugsAndIdsMap[$property['items']['register']] ?? null) !== null) {
                            $property['items']['register'] = $slugsAndIdsMap[$property['items']['register']];
                        } else if (($this->registersMap[$property['items']['register']] ?? null) !== null) {
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
                $this->logger->info(message: "Schema '{$data['slug']}' not found in current organisation context, will create new one");
            } catch (\OCA\OpenRegister\Exception\ValidationException $e) {
                $this->logger->info(message: "Schema '{$data['slug']}' not found (ValidationException), will create new one");
            } catch (\OCP\AppFramework\Db\MultipleObjectsReturnedException $e) {
                $this->handleDuplicateSchemaError(slug: $data['slug'], appId: $appId ?? 'unknown', version: $version ?? 'unknown');
            }

            if ($existingSchema !== null) {
                // Compare versions using version_compare for proper semver comparison.
                if ($force === false && version_compare($data['version'], $existingSchema->getVersion() ?? '0.0.0', '<=') === true) {
                    $this->logger->info(message: 'Skipping schema import as existing version is newer or equal.');
                    return $existingSchema;
                }

                // Update existing schema.
                $existingSchema = $this->schemaMapper->updateFromArray(id: $existingSchema->getId(), object: $data);
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
            $this->logger->error(message: 'Failed to import schema: '.$e->getMessage());
            throw new Exception('Failed to import schema: '.$e->getMessage(), $e->getCode(), $e);
        }//end try

    }//end importSchema()


    /**
     * Import configuration data from JSON structure.
     *
     * This is the core import method that processes all configuration components
     * including schemas, registers, and objects. It handles version checking,
     * entity mapping, and optional OpenConnector integration.
     *
     * @param array              $data          The configuration data to import.
     * @param Configuration|null $configuration The configuration entity for tracking (REQUIRED).
     * @param string|null        $owner         The owner of the imported entities.
     * @param string|null        $appId         The application ID.
     * @param string|null        $version       The configuration version.
     * @param bool               $force         Force import regardless of version checks.
     *
     * @return array The import results containing created/updated entities.
     *
     * @throws Exception If configuration entity is missing or import fails.
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
    public function importFromJson(
        array $data,
        ?Configuration $configuration=null,
        ?string $owner=null,
        ?string $appId=null,
        ?string $version=null,
        bool $force=false
    ): array {
        // ⚠️ CRITICAL: Configuration entity is required for proper tracking.
        if ($configuration === null) {
            throw new Exception(
                'importFromJson must be called with a Configuration entity. '
                .'Direct imports without a Configuration are not allowed to ensure proper entity tracking. '
                .'Please create a Configuration entity first before importing.'
            );
        }

        // Ensure data is consistently an array by converting any stdClass objects.
        $data = $this->ensureArrayStructure($data);

        // Extract appId and version from data if not provided as parameters.
        if ($appId === null && (($data['appId'] ?? null) !== null)) {
            $appId = $data['appId'];
        }

        if ($version === null && (($data['version'] ?? null) !== null)) {
            $version = $data['version'];
        }

        // Perform version check if appId and version are available (unless force is enabled).
        if ($appId !== null && $version !== null && $force === false) {
            $storedVersion = $this->appConfig->getValueString('openregister', "imported_config_{$appId}_version", '');

            // If we have a stored version, compare it with the current version.
            if ($storedVersion !== '' && version_compare($version, $storedVersion, '<=') === true) {
                $this->logger->info(
                    message: "Skipping import for app {$appId} - current version {$version} "."is not newer than stored version {$storedVersion}"
                );

                // Return empty result to indicate no import was performed.
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

        // Log force import if enabled.
        if ($force === true && $appId !== null && $version !== null) {
            $this->logger->info(message: "Force import enabled for app {$appId} version {$version} - bypassing version check");
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
        if (($data['components']['schemas'] ?? null) !== null && is_array($data['components']['schemas']) === true) {
            $slugsAndIdsMap = $this->schemaMapper->getSlugToIdMap();
            $this->logger->info(
                message: 'Starting schema import process',
                context: [
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

                if (isset($schemaData['title']) === true && is_string($key) === true) {
                    $schemaData['title'] = $key;
                }

                try {
                    $schema = $this->importSchema(
                        data: $schemaData,
                        slugsAndIdsMap: $slugsAndIdsMap,
                        owner: $owner,
                        appId: $appId,
                        version: $version,
                        force: $force
                    );
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
                } catch (Exception $e) {
                    $this->logger->error(
                        'Failed to import schema',
                        [
                            'schemaKey' => $key,
                            'error'     => $e->getMessage(),
                            'trace'     => $e->getTraceAsString(),
                        ]
                    );
                    // Continue with other schemas instead of failing the entire import.
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
        if (($data['components']['registers'] ?? null) !== null && is_array($data['components']['registers']) === true) {
            foreach ($data['components']['registers'] as $slug => $registerData) {
                $slug = strtolower($slug);

                if (($registerData['schemas'] ?? null) !== null && is_array($registerData['schemas']) === true) {
                    $schemaIds = [];
                    foreach ($registerData['schemas'] as $schemaSlug) {
                        if (($this->schemasMap[$schemaSlug] ?? null) !== null) {
                            $schemaSlug  = strtolower($schemaSlug);
                            $schemaIds[] = $this->schemasMap[$schemaSlug]->getId();
                        } else {
                            // Try to find existing schema in database.
                            // Note: May fail due to organisation filtering during cross-instance import.
                            try {
                                $existingSchema = $this->schemaMapper->find(strtolower($schemaSlug));
                                $schemaIds[]    = $existingSchema->getId();
                                // Add to map for object processing.
                                $this->schemasMap[$schemaSlug] = $existingSchema;
                            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                                $this->logger->info(
                                    sprintf(
                                        'Schema with slug %s not found in current organisation context '
                                        .'during register import (will be created if defined in import).',
                                        $schemaSlug
                                    )
                                );
                            }
                        }//end if
                    }//end foreach

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

        // NOTE: We do NOT build ID maps - we'll pass the actual objects to avoid organisation filter issues.
        // When saveObject() receives Register/Schema objects, it skips the find() lookup entirely.
        // Process and import objects.
        if (($data['components']['objects'] ?? null) !== null && is_array($data['components']['objects']) === true) {
            foreach ($data['components']['objects'] as $objectData) {
                // Log raw values before any mapping.
                $rawRegister = $objectData['@self']['register'] ?? null;
                $rawSchema   = $objectData['@self']['schema'] ?? null;
                $rawSlug     = $objectData['@self']['slug'] ?? null;

                // Only import objects with a slug.
                $slug = $rawSlug;
                if (empty($slug) === true) {
                    continue;
                }

                // Get the actual Register and Schema objects from maps (not IDs!).
                // This is CRITICAL - passing objects avoids organisation filter in find().
                $registerObject = $this->registersMap[$rawRegister] ?? null;
                $schemaObject   = $this->schemasMap[$rawSchema] ?? null;
                if ($registerObject === null || $schemaObject === null) {
                    $this->logger->warning(
                        'Skipping object import - register or schema not found in maps',
                        [
                            'objectSlug'    => $slug,
                            'registerSlug'  => $rawRegister,
                            'schemaSlug'    => $rawSchema,
                            'registerFound' => $registerObject !== null,
                            'schemaFound'   => $schemaObject !== null,
                        ]
                    );
                    continue;
                }

                // Get IDs for searching existing objects.
                $registerId = $registerObject->getId();
                $schemaId   = $schemaObject->getId();

                // Use ObjectService::searchObjects to find existing object by register+schema+slug.
                $search = [
                    '@self'  => [
                        'register' => (int) $registerId,
                        'schema'   => (int) $schemaId,
                        'slug'     => $slug,
                    ],
                    '_limit' => 1,
                ];
                $this->logger->debug(message: 'Import object search filter', context: ['filter' => $search]);

                // Search for existing object.
                $results = $this->objectService->searchObjects(query: $search, _rbac: true, _multitenancy: true);
                if ((is_array($results) === true) && count($results) > 0) {
                    $existingObject = $results[0];
                } else {
                    $existingObject = null;
                }

                if ($existingObject === null) {
                    $this->logger->info(
                        'No existing object found - will create new object',
                        [
                            'registerId' => $registerId,
                            'schemaId'   => $schemaId,
                            'slug'       => $slug,
                        ]
                    );
                }

                // Replace string slugs with integer IDs in objectData's @self metadata.
                // This prevents any internal lookups from using string slugs.
                $objectData['@self']['register'] = (int) $registerId;
                $objectData['@self']['schema']   = (int) $schemaId;

                if ($existingObject !== null) {
                    if (is_array($existingObject) === true) {
                        $existingObjectData = $existingObject;
                    } else {
                        $existingObjectData = $existingObject->jsonSerialize();
                    }

                    $importedVersion = $objectData['@self']['version'] ?? $objectData['version'] ?? '1.0.0';
                    $existingVersion = $existingObjectData['@self']['version'] ?? $existingObjectData['version'] ?? '1.0.0';
                    if (version_compare($importedVersion, $existingVersion, '>') > 0) {
                        $uuid = $existingObjectData['@self']['id'] ?? $existingObjectData['id'] ?? null;
                        // CRITICAL: Pass Register and Schema OBJECTS, not IDs.
                        // This avoids organisation filter issues in find().
                        $object = $this->objectService->saveObject(
                            object: $objectData,
                            register: $registerObject,
                            schema: $schemaObject,
                            uuid: $uuid
                        );
                        $result['objects'][] = $object;
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
                    // Create new object.
                    // CRITICAL: Pass Register and Schema OBJECTS, not IDs.
                    // This avoids organisation filter issues in find().
                    $object = $this->objectService->saveObject(
                        object: $objectData,
                        register: $registerObject,
                        schema: $schemaObject
                    );
                    $result['objects'][] = $object;
                }//end if
            }//end foreach
        }//end if

        // Process OpenConnector integration if available.
        if ($this->openConnectorConfigurationService !== null) {
            try {
                $openConnectorResult = $this->openConnectorConfigurationService->importConfiguration($data);
                $result = array_replace_recursive($openConnectorResult, $result);
            } catch (Exception $e) {
                $this->logger->warning('OpenConnector integration failed: '.$e->getMessage());
            }
        }

        // Create or update configuration entity to track imported data.
        if ($appId !== null
            && $version !== null
            && (count($result['registers']) > 0
            || count($result['schemas']) > 0
            || count($result['objects']) > 0)
        ) {
            $this->createOrUpdateConfiguration(data: $data, appId: $appId, version: $version, result: $result, owner: $owner);
        }

        // Store the version information if appId and version are available.
        if ($appId !== null && $version !== null) {
            $this->appConfig->setValueString('openregister', "imported_config_{$appId}_version", $version);
            $this->logger->info(message: "Stored version {$version} for app {$appId} after successful import");
        }

        return $result;

    }//end importFromJson()


    /**
     * Import configuration from an app's JSON data.
     *
     * This is a convenience wrapper method for apps that want to import their
     * configuration. It creates or finds a Configuration entity, performs the
     * import via importFromJson, and updates the Configuration tracking.
     *
     * @param string $appId   The application ID.
     * @param array  $data    The configuration data.
     * @param string $version The configuration version.
     * @param bool   $force   Force import regardless of version.
     *
     * @return array The import results.
     *
     * @throws Exception If import fails.
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
    public function importFromApp(string $appId, array $data, string $version, bool $force=false): array
    {
        try {
            // Ensure data is consistently an array by converting any stdClass objects.
            $data = $this->ensureArrayStructure($data);

            // Try to find existing configuration for this app.
            // First check by sourceUrl (unique identifier), then by appId.
            $configuration = null;
            $xOpenregister = $data['x-openregister'] ?? [];
            $sourceUrl     = $xOpenregister['sourceUrl'] ?? null;

            // If sourceUrl is provided, try to find by sourceUrl first (ensures uniqueness).
            if ($sourceUrl !== null) {
                try {
                    $configuration = $this->configurationMapper->findBySourceUrl($sourceUrl);
                    if ($configuration !== null) {
                        $this->logger->info(
                            "Found existing configuration by sourceUrl",
                            [
                                'sourceUrl'       => $sourceUrl,
                                'configurationId' => $configuration->getId(),
                                'currentVersion'  => $configuration->getVersion(),
                            ]
                        );
                    }
                } catch (Exception $e) {
                    // No configuration found by sourceUrl.
                }
            }

            // If not found by sourceUrl, try by appId.
            if ($configuration === null) {
                try {
                    $configurations = $this->configurationMapper->findByApp($appId);
                    if (count($configurations) > 0) {
                        // Use the first (most recent) configuration.
                        $configuration = $configurations[0];
                        $this->logger->info(
                            "Found existing configuration for app {$appId}",
                            [
                                'configurationId' => $configuration->getId(),
                                'currentVersion'  => $configuration->getVersion(),
                            ]
                        );
                    }
                } catch (Exception $e) {
                    // No existing configuration found, we'll create a new one.
                    $this->logger->info(message: "No existing configuration found for app {$appId}, will create new one");
                }
            }

            // Create new configuration if none exists.
            if ($configuration === null) {
                $configuration = new Configuration();

                // Extract metadata following OAS standard first, then x-openregister extension.
                $info          = $data['info'] ?? [];
                $xOpenregister = $data['x-openregister'] ?? [];

                // Standard OAS properties from info section.
                $title       = $info['title'] ?? $xOpenregister['title'] ?? $data['title'] ?? "Configuration for {$appId}";
                $description = $info['description'] ?? $xOpenregister['description'] ?? $data['description']
                    ?? "Configuration imported by application {$appId}";

                // OpenRegister-specific properties.
                $type = $xOpenregister['type'] ?? $data['type'] ?? 'app';

                $configuration->setTitle($title);
                $configuration->setDescription($description);
                $configuration->setType($type);
                $configuration->setApp($appId);
                $configuration->setVersion($version);

                // Mark as local configuration (maintained by the app).
                $configuration->setIsLocal(true);
                $configuration->setSyncEnabled(false);
                $configuration->setSyncStatus('never');

                // Set version requirements from x-openregister if available.
                if (($xOpenregister['openregister'] ?? null) !== null) {
                    $configuration->setOpenregister($xOpenregister['openregister']);
                }

                // Set additional metadata from x-openregister if available.
                // Note: Internal properties (autoUpdate, notificationGroups, owner, organisation).
                // are not imported as they are instance-specific settings.
                if (($xOpenregister['sourceType'] ?? null) !== null) {
                    $configuration->setSourceType($xOpenregister['sourceType']);
                }

                if (($xOpenregister['sourceUrl'] ?? null) !== null) {
                    $configuration->setSourceUrl($xOpenregister['sourceUrl']);
                }

                // Support both nested github structure (new) and flat structure (backward compatibility).
                if (($xOpenregister['github'] ?? null) !== null && is_array($xOpenregister['github']) === true) {
                    // New nested structure.
                    if (($xOpenregister['github']['repo'] ?? null) !== null) {
                        $configuration->setGithubRepo($xOpenregister['github']['repo']);
                    }

                    if (($xOpenregister['github']['branch'] ?? null) !== null) {
                        $configuration->setGithubBranch($xOpenregister['github']['branch']);
                    }

                    if (($xOpenregister['github']['path'] ?? null) !== null) {
                        $configuration->setGithubPath($xOpenregister['github']['path']);
                    }
                } else {
                    // Legacy flat structure (backward compatibility).
                    if (($xOpenregister['githubRepo'] ?? null) !== null) {
                        $configuration->setGithubRepo($xOpenregister['githubRepo']);
                    }

                    if (($xOpenregister['githubBranch'] ?? null) !== null) {
                        $configuration->setGithubBranch($xOpenregister['githubBranch']);
                    }

                    if (($xOpenregister['githubPath'] ?? null) !== null) {
                        $configuration->setGithubPath($xOpenregister['githubPath']);
                    }
                }//end if

                $configuration->setRegisters([]);
                $configuration->setSchemas([]);
                $configuration->setObjects([]);

                // Insert the configuration to get an ID.
                $configuration = $this->configurationMapper->insert($configuration);

                $this->logger->info(
                    "Created new configuration for app {$appId}",
                    [
                        'configurationId' => $configuration->getId(),
                        'version'         => $version,
                    ]
                );
            }//end if

            // Perform the import using the configuration entity.
            $result = $this->importFromJson(
                data: $data,
                configuration: $configuration,
                owner: $appId,
                appId: $appId,
                version: $version,
                force: $force
            );

            // Update the configuration with the import results.
            if (count($result['registers']) > 0 || count($result['schemas']) > 0 || count($result['objects']) > 0) {
                // Merge imported entity IDs with existing ones.
                $existingRegisterIds = $configuration->getRegisters();
                $existingSchemaIds   = $configuration->getSchemas();
                $existingObjectIds   = $configuration->getObjects();

                foreach ($result['registers'] as $register) {
                    if ($register instanceof Register && in_array($register->getId(), $existingRegisterIds, true) === false) {
                        $existingRegisterIds[] = $register->getId();
                    }
                }

                foreach ($result['schemas'] as $schema) {
                    if ($schema instanceof Schema && in_array($schema->getId(), $existingSchemaIds, true) === false) {
                        $existingSchemaIds[] = $schema->getId();
                    }
                }

                foreach ($result['objects'] as $object) {
                    if ($object instanceof ObjectEntity && in_array($object->getId(), $existingObjectIds, true) === false) {
                        $existingObjectIds[] = $object->getId();
                    }
                }

                $configuration->setRegisters($existingRegisterIds);
                $configuration->setSchemas($existingSchemaIds);
                $configuration->setObjects($existingObjectIds);
                $configuration->setVersion($version);

                // Update metadata following OAS standard first, then x-openregister extension.
                // This ensures sourceUrl and other tracking info stays current.
                $info          = $data['info'] ?? [];
                $xOpenregister = $data['x-openregister'] ?? [];

                // Standard OAS properties from info section.
                if (($info['title'] ?? null) !== null) {
                    $configuration->setTitle($info['title']);
                } else if (($xOpenregister['title'] ?? null) !== null) {
                    $configuration->setTitle($xOpenregister['title']);
                }

                if (($info['description'] ?? null) !== null) {
                    $configuration->setDescription($info['description']);
                } else if (($xOpenregister['description'] ?? null) !== null) {
                    $configuration->setDescription($xOpenregister['description']);
                }

                // OpenRegister-specific properties from x-openregister.
                if (($xOpenregister['sourceType'] ?? null) !== null) {
                    $configuration->setSourceType($xOpenregister['sourceType']);
                }

                if (($xOpenregister['sourceUrl'] ?? null) !== null) {
                    $configuration->setSourceUrl($xOpenregister['sourceUrl']);
                }

                // Update github properties (nested or flat).
                if (($xOpenregister['github'] ?? null) !== null && is_array($xOpenregister['github']) === true) {
                    if (($xOpenregister['github']['repo'] ?? null) !== null) {
                        $configuration->setGithubRepo($xOpenregister['github']['repo']);
                    }

                    if (($xOpenregister['github']['branch'] ?? null) !== null) {
                        $configuration->setGithubBranch($xOpenregister['github']['branch']);
                    }

                    if (($xOpenregister['github']['path'] ?? null) !== null) {
                        $configuration->setGithubPath($xOpenregister['github']['path']);
                    }
                } else {
                    // Legacy flat structure.
                    if (($xOpenregister['githubRepo'] ?? null) !== null) {
                        $configuration->setGithubRepo($xOpenregister['githubRepo']);
                    }

                    if (($xOpenregister['githubBranch'] ?? null) !== null) {
                        $configuration->setGithubBranch($xOpenregister['githubBranch']);
                    }

                    if (($xOpenregister['githubPath'] ?? null) !== null) {
                        $configuration->setGithubPath($xOpenregister['githubPath']);
                    }
                }//end if

                $this->configurationMapper->update($configuration);

                $this->logger->info(
                    "Updated configuration entity for app {$appId}",
                    [
                        'configurationId' => $configuration->getId(),
                        'totalRegisters'  => count($existingRegisterIds ?? []),
                        'totalSchemas'    => count($existingSchemaIds ?? []),
                        'totalObjects'    => count($existingObjectIds ?? []),
                    ]
                );
            }//end if

            return $result;
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to import configuration for app {$appId}: ".$e->getMessage());
            throw new Exception("Failed to import configuration for app {$appId}: ".$e->getMessage());
        }//end try

    }//end importFromApp()


    /**
     * Import configuration from a file path.
     *
     * This method reads a JSON configuration file from the filesystem,
     * resolves the path relative to Nextcloud root, and imports it.
     *
     * @param string $appId    The application ID.
     * @param string $filePath The path to the configuration file (relative to Nextcloud root).
     * @param string $version  The configuration version.
     * @param bool   $force    Force import regardless of version.
     *
     * @return array The import results.
     *
     * @throws Exception If file reading or import fails.
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
    public function importFromFilePath(string $appId, string $filePath, string $version, bool $force=false): array
    {
        try {
            // Resolve the file path relative to Nextcloud root.
            // Try multiple resolution strategies.
            $fullPath = $this->appDataPath.'/../../../'.$filePath;
            $fullPath = realpath($fullPath);

            // If realpath fails, try direct path from Nextcloud root.
            if ($fullPath === false) {
                $fullPath = '/var/www/html/'.$filePath;
                // Normalize the path.
                $fullPath = str_replace('//', '/', $fullPath);
            }

            if ($fullPath === false || file_exists($fullPath) === false) {
                throw new Exception("Configuration file not found: {$filePath}");
            }

            // Read the file contents.
            $jsonContent = file_get_contents($fullPath);
            if ($jsonContent === false) {
                throw new Exception("Failed to read configuration file: {$filePath}");
            }

            // Parse JSON.
            $data = json_decode($jsonContent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in configuration file: ".json_last_error_msg());
            }

            // Set the sourceUrl in the data if not already set.
            // This allows the cron job to track the file location.
            if (isset($data['x-openregister']) === false) {
                $data['x-openregister'] = [];
            }

            if (isset($data['x-openregister']['sourceUrl']) === false) {
                $data['x-openregister']['sourceUrl'] = $filePath;
            }

            if (isset($data['x-openregister']['sourceType']) === false) {
                $data['x-openregister']['sourceType'] = 'local';
            }

            // Call importFromApp with the parsed data.
            return $this->importFromApp(
                appId: $appId,
                data: $data,
                version: $version,
                force: $force
            );
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to import configuration from file: '.$e->getMessage(),
                [
                    'appId'    => $appId,
                    'filePath' => $filePath,
                ]
            );
            throw new Exception('Failed to import configuration from file: '.$e->getMessage());
        }//end try

    }//end importFromFilePath()


    /**
     * Create or update a Configuration entity to track imports.
     *
     * @param array       $data    The original import data.
     * @param string      $appId   The application ID.
     * @param string      $version The version of the import.
     * @param array       $result  The import result containing created entities.
     * @param string|null $owner   The owner of the configuration.
     *
     * @return Configuration The created or updated configuration.
     *
     * @throws Exception If configuration creation/update fails.
     */
    public function createOrUpdateConfiguration(array $data, string $appId, string $version, array $result, ?string $owner=null): Configuration
    {
        try {
            // Ensure data is consistently an array by converting any stdClass objects.
            $data = $this->ensureArrayStructure($data);

            // Try to find existing configuration for this app.
            $existingConfiguration = null;
            try {
                $configurations = $this->configurationMapper->findByApp($appId);
                if (count($configurations) > 0) {
                    $existingConfiguration = $configurations[0];
                }
            } catch (Exception $e) {
                // No existing configuration found, we'll create a new one.
            }

            // Extract metadata following OAS standard first, then x-openregister extension.
            $info          = $data['info'] ?? [];
            $xOpenregister = $data['x-openregister'] ?? [];

            // Standard OAS properties from info section.
            $title       = $info['title'] ?? $xOpenregister['title'] ?? $data['title'] ?? "Configuration for {$appId}";
            $description = $info['description'] ?? $xOpenregister['description'] ?? $data['description']
                ?? "Imported configuration for application {$appId}";

            // OpenRegister-specific properties.
            $type = $xOpenregister['type'] ?? $data['type'] ?? 'imported';

            // Collect IDs of imported entities.
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
                // Update existing configuration.
                $existingConfiguration->setTitle($title);
                $existingConfiguration->setDescription($description);
                $existingConfiguration->setType($type);
                $existingConfiguration->setVersion($version);

                // Merge with existing IDs to avoid losing previously imported entities.
                $existingRegisterIds = $existingConfiguration->getRegisters() ?? [];
                $existingSchemaIds   = $existingConfiguration->getSchemas() ?? [];
                $existingObjectIds   = $existingConfiguration->getObjects() ?? [];

                $existingConfiguration->setRegisters(array_unique(array_merge($existingRegisterIds, $registerIds)));
                $existingConfiguration->setSchemas(array_unique(array_merge($existingSchemaIds, $schemaIds)));
                $existingConfiguration->setObjects(array_unique(array_merge($existingObjectIds, $objectIds)));

                $configuration = $this->configurationMapper->update($existingConfiguration);
                $this->logger->info(message: "Updated existing configuration for app {$appId} with version {$version}");
            } else {
                // Create new configuration.
                $configuration = new Configuration();
                $configuration->setTitle($title);
                $configuration->setDescription($description);
                $configuration->setType($type);
                $configuration->setApp($appId);
                $configuration->setVersion($version);
                $configuration->setRegisters($registerIds);
                $configuration->setSchemas($schemaIds);
                $configuration->setObjects($objectIds);

                // Mark as local configuration (maintained by the app).
                $configuration->setIsLocal(true);
                $configuration->setSyncEnabled(false);
                $configuration->setSyncStatus('never');

                // Set version requirements from x-openregister if available.
                if (($xOpenregister['openregister'] ?? null) !== null) {
                    $configuration->setOpenregister($xOpenregister['openregister']);
                }

                // Set additional metadata from x-openregister if available.
                if (($xOpenregister['sourceType'] ?? null) !== null) {
                    $configuration->setSourceType($xOpenregister['sourceType']);
                }

                if (($xOpenregister['sourceUrl'] ?? null) !== null) {
                    $configuration->setSourceUrl($xOpenregister['sourceUrl']);
                }

                // Support both nested github structure (new) and flat structure (backward compatibility).
                if (($xOpenregister['github'] ?? null) !== null && is_array($xOpenregister['github']) === true) {
                    // New nested structure.
                    if (($xOpenregister['github']['repo'] ?? null) !== null) {
                        $configuration->setGithubRepo($xOpenregister['github']['repo']);
                    }

                    if (($xOpenregister['github']['branch'] ?? null) !== null) {
                        $configuration->setGithubBranch($xOpenregister['github']['branch']);
                    }

                    if (($xOpenregister['github']['path'] ?? null) !== null) {
                        $configuration->setGithubPath($xOpenregister['github']['path']);
                    }
                } else {
                    // Legacy flat structure (backward compatibility).
                    if (($xOpenregister['githubRepo'] ?? null) !== null) {
                        $configuration->setGithubRepo($xOpenregister['githubRepo']);
                    }

                    if (($xOpenregister['githubBranch'] ?? null) !== null) {
                        $configuration->setGithubBranch($xOpenregister['githubBranch']);
                    }

                    if (($xOpenregister['githubPath'] ?? null) !== null) {
                        $configuration->setGithubPath($xOpenregister['githubPath']);
                    }
                }//end if

                // Set owner from parameter if provided (for backward compatibility).
                if ($owner !== null) {
                    $configuration->setOwner($owner);
                }

                $configuration = $this->configurationMapper->insert($configuration);
                $this->logger->info(message: "Created new configuration for app {$appId} with version {$version}");
            }//end if

            return $configuration;
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to create or update configuration for app {$appId}: ".$e->getMessage());
            throw new Exception("Failed to create or update configuration: ".$e->getMessage());
        }//end try

    }//end createOrUpdateConfiguration()


}//end class
