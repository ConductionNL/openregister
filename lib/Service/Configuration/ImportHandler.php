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
     * Map of registers indexed by slug during import.
     *
     * @var array<string, Register> Registers indexed by slug.
     */
    private array $registersMap=[];

    /**
     * Map of schemas indexed by slug during import.
     *
     * @var array<string, Schema> Schemas indexed by slug.
     */
    private array $schemasMap=[];


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
        $this->client              = $client;
        $this->appConfig           = $appConfig;
        $this->logger              = $logger;
        $this->appDataPath         = $appDataPath;
        $this->uploadHandler       = $uploadHandler;
    }//end __construct()


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


}//end class
