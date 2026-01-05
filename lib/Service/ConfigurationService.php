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
use RuntimeException;
use DateTime;
use stdClass;
use OCA\OpenRegister\Service\Handler\ViewHandler;
use OCA\OpenRegister\Service\Handler\AgentHandler;
use OCA\OpenRegister\Service\Handler\OrganisationHandler;
use OCA\OpenRegister\Service\Handler\ApplicationHandler;
use OCA\OpenRegister\Service\Handler\SourceHandler;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\Configuration\CacheHandler;
use OCA\OpenRegister\Service\Configuration\ExportHandler;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\PreviewHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
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
     * OpenConnector service instance for handling OpenConnector operations
     *
     * Lazily loaded from container when OpenConnector app is installed.
     *
     * @var object|null OpenConnector service instance (from openconnector app) or null
     */
    private ?object $openConnectorConfigurationService = null;

    /**
     * App manager for checking installed apps
     *
     * Used to check if OpenConnector app is installed.
     *
     * @var IAppManager App manager instance
     */
    private readonly IAppManager $appManager;

    /**
     * Container for getting services
     *
     * Used to lazily load OpenConnector service when needed.
     *
     * @var ContainerInterface Container instance
     */
    private readonly ContainerInterface $container;

    /**
     * App config for storing configuration metadata
     *
     * Used for reading and writing configuration metadata.
     *
     * @var IAppConfig App config instance
     */
    private readonly IAppConfig $appConfig;

    /**
     * Logger instance for logging operations.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;

    /**
     * Export handler for configuration export operations.
     *
     * @var ExportHandler The export handler instance.
     */
    private readonly ExportHandler $exportHandler;

    /**
     * Upload handler for file upload and JSON parsing operations.
     *
     * @var UploadHandler The upload handler instance.
     */
    private readonly UploadHandler $uploadHandler;

    /**
     * Import handler for configuration import operations.
     *
     * @var ImportHandler The import handler instance.
     */
    private readonly ImportHandler $importHandler;

    /**
     * Map of registers indexed by slug during import, by id during export.
     *
     * @var array<string|int, Register> Registers indexed by slug during import, by id during export.
     */
    private array $registersMap = [];

    /**
     * Map of schemas indexed by slug during import, by id during export.
     *
     * @var array<string|int, Schema> Schemas indexed by slug during import, by id during export.
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
     * Application data path
     *
     * @var string The application data path
     */
    private string $appDataPath;

    /**
     * GitHub handler for GitHub API operations
     *
     * @var GitHubHandler The GitHub handler instance.
     */
    private readonly GitHubHandler $githubHandler;

    /**
     * GitLab handler for GitLab API operations
     *
     * @var GitLabHandler The GitLab handler instance.
     */
    private readonly GitLabHandler $gitlabHandler;

    /**
     * Cache handler for configuration caching
     *
     * @var CacheHandler The cache handler instance.
     */
    private readonly CacheHandler $cacheHandler;

    /**
     * Preview handler for configuration preview operations
     *
     * @var PreviewHandler The preview handler instance.
     */
    private readonly PreviewHandler $previewHandler;

    /**
     * Constructor
     *
     * Initializes service with required dependencies for configuration operations.
     *
     * @param SchemaMapper        $schemaMapper        Schema mapper for schema operations
     * @param RegisterMapper      $registerMapper      Register mapper for register operations
     * @param ObjectEntityMapper  $objectEntityMapper  Object entity mapper for object operations
     * @param ConfigurationMapper $configurationMapper Configuration mapper for configuration operations
     * @param IAppManager         $appManager          App manager for checking installed apps
     * @param ContainerInterface  $container           Container for lazy service loading
     * @param IAppConfig          $appConfig           App config for configuration metadata
     * @param LoggerInterface     $logger              Logger for error tracking
     * @param Client              $client              HTTP client for external requests
     * @param ObjectService       $objectService       Object service for object operations
     * @param GitHubHandler       $githubHandler       GitHub handler for GitHub operations
     * @param GitLabHandler       $gitlabHandler       GitLab handler for GitLab operations
     * @param CacheHandler        $cacheHandler        Cache handler for configuration caching
     * @param PreviewHandler      $previewHandler      Preview handler for preview operations
     * @param ExportHandler       $exportHandler       Export handler for export operations
     * @param ImportHandler       $importHandler       Import handler for import operations
     * @param UploadHandler       $uploadHandler       Upload handler for file upload operations
     * @param string              $appDataPath         Application data path
     *
     * @return void
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        ObjectEntityMapper $objectEntityMapper,
        ConfigurationMapper $configurationMapper,
        IAppManager $appManager,
        ContainerInterface $container,
        IAppConfig $appConfig,
        LoggerInterface $logger,
        Client $client,
        ObjectService $objectService,
        GitHubHandler $githubHandler,
        GitLabHandler $gitlabHandler,
        CacheHandler $cacheHandler,
        PreviewHandler $previewHandler,
        ExportHandler $exportHandler,
        ImportHandler $importHandler,
        UploadHandler $uploadHandler,
        string $appDataPath
    ) {
        $this->logger = $logger;
        $this->logger->debug('ConfigurationService constructor started.');
        // Store dependencies for use in service methods.
        $this->schemaMapper        = $schemaMapper;
        $this->registerMapper      = $registerMapper;
        $this->objectEntityMapper  = $objectEntityMapper;
        $this->configurationMapper = $configurationMapper;
        $this->appManager          = $appManager;
        $this->container           = $container;
        $this->appConfig           = $appConfig;
        $this->client = $client;
        $this->logger->debug('ConfigurationService about to assign objectService.');
        $this->objectService = $objectService;
        $this->logger->debug('ConfigurationService assigned objectService.');
        $this->githubHandler  = $githubHandler;
        $this->gitlabHandler  = $gitlabHandler;
        $this->cacheHandler   = $cacheHandler;
        $this->previewHandler = $previewHandler;
        $this->exportHandler  = $exportHandler;
        $this->importHandler  = $importHandler;
        $this->uploadHandler  = $uploadHandler;
        $this->appDataPath    = $appDataPath;

        // Wire dependencies into ImportHandler to avoid circular dependency issues.
        $this->importHandler->setObjectService($this->objectService);

        // Wire OpenConnectorConfigurationService if available.
        if ($this->getOpenConnector() === true) {
            $this->importHandler->setOpenConnectorConfigurationService($this->openConnectorConfigurationService);
        }

        // Wire PreviewHandler with ConfigurationService reference.
        $this->previewHandler->setConfigurationService($this);
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
                $serviceName = 'OCA\OpenConnector\Service\ConfigurationService';
                $this->openConnectorConfigurationService = $this->container->get($serviceName);
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
     * @param array|Configuration|Register $input          The configuration array, Configuration object,
     *                                                     or Register object to build the OAS from.
     * @param bool                         $includeObjects Whether to include objects in the registers.
     *
     * @psalm-param   array<string, mixed>|Configuration|Register $input
     * @phpstan-param array<string, mixed>|Configuration|Register $input
     *
     * @return array The OpenAPI specification.
     *
     * @throws Exception If configuration is invalid.
     */
    public function exportConfig(array | Configuration | Register $input=[], bool $includeObjects=false): array
    {
        // Delegate to ExportHandler for the actual export logic.
        $openConnectorService = null;
        $openConnector        = $this->getOpenConnector();
        if ($openConnector === true) {
            $openConnectorService = $this->openConnectorConfigurationService;
        }

        return $this->exportHandler->exportConfig(
            input: $input,
            includeObjects: $includeObjects,
            openConnectorService: $openConnectorService
        );
    }//end exportConfig()

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
        // Delegate to UploadHandler for processing uploaded JSON data.
        return $this->uploadHandler->getUploadedJson(
            data: $data,
            uploadedFiles: $uploadedFiles
        );
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
        return $this->importHandler->decode(
            data: $data,
            type: $type
        );
    }//end decode()

    /**
     * Recursively converts stdClass objects to arrays to ensure consistent data structure (DELEGATED).
     *
     * @param mixed $data The data to convert.
     *
     * @return array The converted array data.
     */
    private function ensureArrayStructure(mixed $data): array
    {
        return $this->importHandler->ensureArrayStructure($data);
    }//end ensureArrayStructure()

    /**
     * Get JSON data from uploaded file (DELEGATED).
     *
     * @param array       $uploadedFile The uploaded file.
     * @param string|null $_type        If the uploaded file should be a specific type of object (unused).
     *
     * @return JSONResponse|array A PHP array with the uploaded json data or a JSONResponse in case of an error.
     *
     * @SuppressWarnings (PHPMD.UnusedFormalParameter)
     */
    private function getJSONfromFile(array $uploadedFile, ?string $_type=null): array|JSONResponse
    {
        return $this->importHandler->getJSONfromFile(
            uploadedFile: $uploadedFile,
            _type: $_type
        );
    }//end getJSONfromFile()

    /**
     * Uses Guzzle to call the given URL and returns response as PHP array (DELEGATED).
     *
     * @param string $url The URL to call.
     *
     * @throws GuzzleException
     *
     * @return JSONResponse|array
     */
    private function getJSONfromURL(string $url): array|JSONResponse
    {
        return $this->importHandler->getJSONfromURL($url);
    }//end getJSONfromURL()

    /**
     * Get JSON data from request body (DELEGATED).
     *
     * @param array|string $phpArray The PHP array or string to process.
     *
     * @return JSONResponse|array A PHP array with the uploaded json data or a JSONResponse in case of an error.
     */
    private function getJSONfromBody(array | string $phpArray): array|JSONResponse
    {
        return $this->importHandler->getJSONfromBody($phpArray);
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
     * ⚠️ IMPORTANT: This method MUST be called with a Configuration entity.
     * Direct calls without a Configuration entity will throw an exception.
     * This ensures proper tracking of imported entities.
     *
     * @param array              $data          The configuration JSON content
     * @param Configuration|null $configuration The configuration entity (REQUIRED)
     * @param string|null        $owner         The owner of the imported data (deprecated, use configuration->app)
     * @param string|null        $appId         The app ID for version tracking (deprecated, use configuration->app)
     * @param string|null        $version       The version for version tracking (deprecated, use data version)
     * @param bool               $force         Force import even if the same or newer version already exists
     *
     * @throws Exception If called without a Configuration entity or if import fails
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
    public function importFromJson(
        array $data,
        ?Configuration $configuration=null,
        ?string $owner=null,
        ?string $appId=null,
        ?string $version=null,
        bool $force=false
    ): array {
        return $this->importHandler->importFromJson(
            data: $data,
            configuration: $configuration,
            owner: $owner,
            appId: $appId,
            version: $version,
            force: $force
        );
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
     *
     * @psalm-suppress UnusedReturnValue
     */
    private function createOrUpdateConfiguration(
        array $data,
        string $appId,
        string $version,
        array $result,
        ?string $owner=null
    ): Configuration {
        return $this->importHandler->createOrUpdateConfiguration(
            data: $data,
            appId: $appId,
            version: $version,
            result: $result,
            owner: $owner
        );
    }//end createOrUpdateConfiguration()

    /**
     * Import a register from configuration data
     *
     * @param array       $data    The register data.
     * @param string|null $owner   The owner of the register.
     * @param string|null $appId   The application ID.
     * @param string|null $version The version string.
     * @param bool        $force   Whether to force the import.
     *
     * @return Register The imported register or null if skipped.
     */
    private function importRegister(
        array $data,
        ?string $owner=null,
        ?string $appId=null,
        ?string $version=null,
        bool $force=false
    ): Register {
        return $this->importHandler->importRegister(
            data: $data,
            owner: $owner,
            appId: $appId,
            version: $version,
            force: $force
        );
    }//end importRegister()

    /**
     * Import a schema from configuration data
     *
     * @param array       $data           The schema data.
     * @param array       $slugsAndIdsMap Map of slugs to IDs.
     * @param string|null $owner          The owner of the schema.
     * @param string|null $appId          The application ID.
     * @param string|null $version        The version string.
     * @param bool        $force          Whether to force the import.
     *
     * @return Schema The imported schema.
     */
    private function importSchema(
        array $data,
        array $slugsAndIdsMap,
        ?string $owner=null,
        ?string $appId=null,
        ?string $version=null,
        bool $force=false
    ): Schema {
        return $this->importHandler->importSchema(
            data: $data,
            slugsAndIdsMap: $slugsAndIdsMap,
            owner: $owner,
            appId: $appId,
            version: $version,
            force: $force
        );
    }//end importSchema()

    /**
     * Import configuration from a file path.
     *
     * This method reads a configuration file from the filesystem and imports it.
     * It's designed to be used by apps that store their configurations as JSON files
     * and want OpenRegister to handle the file reading and import process.
     *
     * The file path should be relative to the Nextcloud root
     * (e.g., 'apps-extra/opencatalogi/lib/Settings/publication_register.json')
     * This enables the cron job to later check if the configuration file has been updated.
     *
     * @param string $appId    The application ID (e.g. 'opencatalogi')
     * @param string $filePath The file path relative to Nextcloud root
     * @param string $version  The version of the configuration
     * @param bool   $force    Whether to force import regardless of version checks
     *
     * @return (ObjectEntity|Register|Schema|mixed)[][] Import result with counts and IDs
     *
     * @throws Exception If file cannot be read or import fails
     *
     * @since 0.2.10
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     *
     * @psalm-return array{
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
        return $this->importHandler->importFromFilePath(
            appId: $appId,
            filePath: $filePath,
            version: $version,
            force: $force
        );
    }//end importFromFilePath()

    /**
     * Import configuration from an app's JSON data.
     *
     * This is a convenience wrapper method for apps that want to import their
     * configuration without manually managing Configuration entities. It:
     * - Finds or creates a Configuration entity for the app
     * - Handles version checking
     * - Calls importFromJson with proper entity tracking
     *
     * @param string $appId   The application ID (e.g. 'opencatalogi')
     * @param array  $data    The configuration data to import
     * @param string $version The version of the configuration
     * @param bool   $force   Whether to force import regardless of version checks
     *
     * @return array The import results
     * @throws Exception If import fails
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
        return $this->importHandler->importFromApp(
            appId: $appId,
            data: $data,
            version: $version,
            force: $force
        );
    }//end importFromApp()

    /**
     * Check the remote version of a configuration
     *
     * Fetches the configuration from the source URL and extracts its version.
     * Updates the configuration entity with the remote version and last checked timestamp.
     *
     * @param Configuration $configuration The configuration to check
     *
     * @return string|null The remote version, or null if check failed
     * @throws GuzzleException If HTTP request fails
     */
    public function checkRemoteVersion(Configuration $configuration): ?string
    {
        // Only check remote sources.
        if ($configuration->isRemoteSource() === false) {
            $this->logger->info(message: 'Configuration is not from a remote source, skipping version check');
            return null;
        }

        $sourceUrl = $configuration->getSourceUrl();
        if (empty($sourceUrl) === true) {
            $this->logger->warning(message: 'Configuration has no source URL, cannot check remote version');
            return null;
        }

        try {
            // Fetch the remote configuration.
            $remoteData = $this->getJSONfromURL($sourceUrl);

            if ($remoteData instanceof JSONResponse) {
                $this->logger->error(
                    message: 'Failed to fetch remote configuration',
                    context: ['error' => $remoteData->getData()]
                );
                return null;
            }

            // Extract version from remote data.
            $remoteVersion = $remoteData['version'] ?? $remoteData['info']['version'] ?? null;

            if ($remoteVersion === null) {
                $this->logger->warning(message: 'Remote configuration does not contain a version field');
                return null;
            }

            // Update the configuration with remote version and last checked time.
            $configuration->setRemoteVersion($remoteVersion);
            $configuration->setLastChecked(new DateTime());
            $this->configurationMapper->update($configuration);

            $configId = $configuration->getId();
            $this->logger->info(
                message: "Checked remote version for configuration {$configId}: {$remoteVersion}"
            );

            return $remoteVersion;
        } catch (GuzzleException $e) {
            $configId = $configuration->getId();
            $errorMsg = $e->getMessage();
            $this->logger->error(
                message: "Failed to check remote version for configuration {$configId}: {$errorMsg}"
            );
            throw $e;
        } catch (Exception $e) {
            $this->logger->error(message: "Unexpected error checking remote version: ".$e->getMessage());
            return null;
        }//end try
    }//end checkRemoteVersion()

    /**
     * Compare versions and get update status
     *
     * Returns detailed information about version comparison including
     * whether an update is available and what the versions are.
     *
     * @param Configuration $configuration The configuration to compare versions for
     *
     * @return (bool|null|string)[]
     *
     * @phpstan-return array{
     *     hasUpdate: bool,
     *     localVersion: string|null,
     *     remoteVersion: string|null,
     *     lastChecked: string|null,
     *     message: string
     * }
     *
     * @psalm-return array{hasUpdate: bool, localVersion: null|string,
     *     remoteVersion: null|string, lastChecked: null|string,
     *     message: string}
     */
    public function compareVersions(Configuration $configuration): array
    {
        $localVersion  = $configuration->getLocalVersion();
        $remoteVersion = $configuration->getRemoteVersion();
        $lastChecked   = $configuration->getLastChecked();

        // Format last checked date.
        if ($lastChecked !== null) {
            $lastCheckedFormatted = $lastChecked->format('c');
        } else {
            $lastCheckedFormatted = null;
        }

        // Build result array.
        $result = [
            'hasUpdate'     => false,
            'localVersion'  => $localVersion,
            'remoteVersion' => $remoteVersion,
            'lastChecked'   => $lastCheckedFormatted,
            'message'       => '',
        ];

        // Check if we have both versions to compare.
        if ($localVersion === null) {
            $result['message'] = 'No local version information available';
            return $result;
        }

        if ($remoteVersion === null) {
            $result['message'] = 'No remote version information available. Check remote version first.';
            return $result;
        }

        // Compare versions.
        $comparison = version_compare($remoteVersion, $localVersion);

        if ($comparison > 0) {
            $result['hasUpdate'] = true;
            $result['message']   = "Update available: {$localVersion} → {$remoteVersion}";
        } else if ($comparison === 0) {
            $result['message'] = 'Local version is up to date';
        } else {
            $result['message'] = 'Local version is newer than remote version';
        }

        return $result;
    }//end compareVersions()

    /**
     * Fetch remote configuration data
     *
     * Downloads the configuration file from the source URL and returns
     * the parsed data as an array.
     *
     * @param Configuration $configuration The configuration to fetch
     *
     * @return JSONResponse|array
     *
     * @throws GuzzleException If HTTP request fails
     */
    public function fetchRemoteConfiguration(Configuration $configuration): array|JSONResponse
    {
        // Only fetch from remote sources.
        if ($configuration->isRemoteSource() === false) {
            return new JSONResponse(
                data: ['error' => 'Configuration is not from a remote source'],
                statusCode: 400
            );
        }

        $sourceUrl = $configuration->getSourceUrl();
        if (empty($sourceUrl) === true) {
            return new JSONResponse(
                data: ['error' => 'Configuration has no source URL'],
                statusCode: 400
            );
        }

        try {
            $this->logger->info(message: "Fetching remote configuration from: {$sourceUrl}");

            // Use existing method to fetch and parse the remote configuration.
            $remoteData = $this->getJSONfromURL($sourceUrl);

            if ($remoteData instanceof JSONResponse) {
                return $remoteData;
            }

            $schemaCount   = count($remoteData['components']['schemas'] ?? []);
            $registerCount = count($remoteData['components']['registers'] ?? []);
            $this->logger->info(
                "Successfully fetched remote configuration with {$schemaCount} schemas and {$registerCount} registers"
            );

            return $remoteData;
        } catch (GuzzleException $e) {
            $this->logger->error(message: "Failed to fetch remote configuration: ".$e->getMessage());
            return new JSONResponse(
                data: ['error' => 'Failed to fetch remote configuration: '.$e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end fetchRemoteConfiguration()

    /**
     * Preview configuration changes before importing
     *
     * Compares the remote configuration with local entities and returns
     * a detailed list of changes that would be applied, organized by entity type.
     * Each change includes the action (create/update/skip), current state,
     * and proposed state.
     *
     * @param Configuration $configuration The configuration to preview
     *
     * @return array|JSONResponse
     *
     * @throws GuzzleException If fetching remote configuration fails
     */
    public function previewConfigurationChanges(Configuration $configuration): array|JSONResponse
    {
        return $this->previewHandler->previewConfigurationChanges($configuration);
    }//end previewConfigurationChanges()

    /**
     * Preview changes for a single register
     *
     * @param string $slug         The register slug
     * @param array  $registerData The register data from remote configuration
     *
     * @return array Preview information for this register
     *
     * @phpstan-return array{
     *     type: string,
     *     action: string,
     *     slug: string,
     *     title: string,
     *     current: array|null,
     *     proposed: array,
     *     changes: array
     * }
     */
    private function previewRegisterChange(string $slug, array $registerData): array
    {
        $slug = strtolower($slug);
        return $this->previewHandler->previewRegisterChange(
            slug: $slug,
            registerData: $registerData
        );

        /*
         * Preview changes for a single schema
         *
         * @param string $slug       The schema slug
         * @param array  $schemaData The schema data from remote configuration
         *
         * @return array Preview information for this schema
         *
         * @phpstan-return array{
         *     type: string,
         *     action: string,
         *     slug: string,
         *     title: string,
         *     current: array|null,
         *     proposed: array,
         *     changes: array
         * }
         */

        // Note: $schemaData is received as parameter but currently unused - keeping for API consistency.
        return $this->previewHandler->previewSchemaChange(
            slug: $slug,
            schemaData: $schemaData
        );
    }//end previewRegisterChange()

    /**
     * Compare two arrays and return a list of differences
     *
     * @param array  $current  The current data
     * @param array  $proposed The proposed data
     * @param string $prefix   Field name prefix for nested comparison
     *
     * @return array List of differences
     */
    private function compareArrays(array $current, array $proposed, string $prefix=''): array
    {
        return $this->previewHandler->compareArrays(
            current: $current,
            proposed: $proposed,
            prefix: $prefix
        );
    }//end compareArrays()

    /**
     * Check if an array is a simple (non-nested) array
     *
     * @param array $array The array to check
     *
     * @return bool True if the array contains only scalar values
     */
    private function isSimpleArray(array $array): bool
    {
        foreach ($array as $value) {
            if (is_array($value) === true) {
                return false;
            }
        }

        return true;
    }//end isSimpleArray()

    /**
     * Get the configured app version from appconfig
     *
     * This method retrieves the stored version of a given app from the appconfig,
     * which is used to track which version of configuration was last imported.
     *
     * @param string $appId The app ID to get the version for.
     *
     * @return null|string The configured version or null if not set.
     */
    public function getConfiguredAppVersion(string $appId): string|null
    {
        // Get the stored version from appconfig.
        // The key format is: <appId>_config_version.
        $versionKey = $appId.'_config_version';

        try {
            // Try to get the value from appconfig.
            $version = $this->appConfig->getValueString(
                app: 'openregister',
                key: $versionKey,
                default: ''
            );

            // Return null if empty string.
            if ($version === '') {
                return null;
            }

            return $version;
        } catch (Exception $e) {
            // Log error and return null.
            $this->logger->error(
                message: 'Failed to get configured app version',
                context: [
                    'appId' => $appId,
                    'error' => $e->getMessage(),
                ]
            );

            return null;
        }//end try
    }//end getConfiguredAppVersion()

    /**
     * Set the configured app version in appconfig
     *
     * This method stores the version of a configuration that was imported,
     * allowing version tracking and comparison for updates.
     *
     * @param string $appId   The app ID to set the version for.
     * @param string $version The version to store.
     *
     * @return void
     */
    public function setConfiguredAppVersion(string $appId, string $version): void
    {
        // The key format is: <appId>_config_version.
        $versionKey = $appId.'_config_version';

        try {
            // Store the version in appconfig.
            $this->appConfig->setValueString(
                app: 'openregister',
                key: $versionKey,
                value: $version
            );

            $this->logger->info(
                message: 'Configured app version updated',
                context: [
                    'appId'   => $appId,
                    'version' => $version,
                ]
            );
        } catch (Exception $e) {
            // Log error but don't throw - version tracking is not critical.
            $this->logger->error(
                message: 'Failed to set configured app version',
                context: [
                    'appId'   => $appId,
                    'version' => $version,
                    'error'   => $e->getMessage(),
                ]
            );
        }//end try
    }//end setConfiguredAppVersion()

    /**
     * Search GitHub for OpenRegister configurations
     *
     * Delegates to GitHubHandler.
     *
     * @param string $search  Search terms
     * @param int    $page    Page number
     * @param int    $perPage Results per page
     *
     * @return (((array|int|mixed|null|string)[]|mixed)[]|int|mixed)[] Search results
     *
     * @throws Exception If search fails
     *
     * @psalm-return array{total_count: 0|mixed,
     *     results: list{0?: array{repository: mixed, owner: string,
     *     repo: string, path: string, url: mixed, stars: 0|mixed,
     *     description: ''|mixed, name: string, branch: string,
     *     raw_url: string, sha: null|string,
     *     organization: array{name: string, avatar_url: ''|mixed,
     *     type: 'User'|mixed, url: ''|mixed}, config: array},...},
     *     page: int, per_page: int}
     */
    public function searchGitHub(string $search='', int $page=1, int $perPage=30): array
    {
        return $this->githubHandler->searchConfigurations(
            search: $search,
            page: $page,
            perPage: $perPage
        );
    }//end searchGitHub()

    /**
     * Search GitLab for OpenRegister configurations
     *
     * Delegates to GitLabHandler.
     *
     * @param string $search  Search terms
     * @param int    $page    Page number
     * @param int    $perPage Results per page
     *
     * @return ((((null|string)[]|mixed|string)[]|mixed)[]|int)[] Search results
     *
     * @throws Exception If search fails
     *
     * @psalm-return array{total_count: int<0, max>,
     *     results: list{0?: array{project_id: mixed, path: mixed,
     *     ref: 'main'|mixed, url: ''|mixed, name: string,
     *     config: array{title: string, description: '',
     *     version: 'unknown', app: null, type: 'unknown'}},...},
     *     page: int, per_page: int}
     */
    public function searchGitLab(string $search='', int $page=1, int $perPage=30): array
    {
        return $this->gitlabHandler->searchConfigurations(
            search: $search,
            page: $page,
            perPage: $perPage
        );
    }//end searchGitLab()

    /**
     * Get GitHubHandler for direct access
     *
     * @return GitHubHandler The GitHub handler
     */
    public function getGitHubHandler(): GitHubHandler
    {
        return $this->githubHandler;
    }//end getGitHubHandler()

    /**
     * Get GitLabHandler for direct access
     *
     * @return GitLabHandler The GitLab handler
     */
    public function getGitLabHandler(): GitLabHandler
    {
        return $this->gitlabHandler;
    }//end getGitLabHandler()

    /**
     * Get CacheHandler for direct access
     *
     * @return CacheHandler The cache handler
     */
    public function getCacheHandler(): CacheHandler
    {
        return $this->cacheHandler;
    }//end getCacheHandler()

    /**
     * Import configuration with selection.
     *
     * Delegates to PreviewHandler.
     *
     * @param Configuration $configuration Configuration to import
     * @param array         $selection     Selection of items to import
     *
     * @return array Import results
     */
    public function importConfigurationWithSelection(Configuration $configuration, array $selection): array
    {
        return $this->previewHandler->importConfigurationWithSelection(configuration: $configuration, selection: $selection);
    }//end importConfigurationWithSelection()
}//end class
