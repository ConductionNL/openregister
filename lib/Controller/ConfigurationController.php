<?php

/**
 * OpenRegister Configuration Controller
 *
 * This file contains the controller class for handling configuration-related API requests.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\NotificationService;
use OCP\App\IAppManager;
use DateTime;
use stdClass;
use GuzzleHttp\Client;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Class ConfigurationController
 *
 * Controller for managing configurations (CRUD and management operations).
 *
 * @package OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass
 */
class ConfigurationController extends Controller
{

    /**
     * Configuration mapper instance.
     *
     * @var ConfigurationMapper The configuration mapper instance.
     */
    private ConfigurationMapper $configurationMapper;

    /**
     * Configuration service instance.
     *
     * @var ConfigurationService The configuration service instance.
     */
    private ConfigurationService $configurationService;

    /**
     * Notification service instance.
     *
     * @var NotificationService The notification service instance.
     */
    private NotificationService $notificationService;

    /**
     * GitHub handler instance.
     *
     * @var GitHubHandler The GitHub handler instance.
     */
    private GitHubHandler $githubHandler;

    /**
     * GitLab handler instance.
     *
     * @var GitLabHandler The GitLab handler instance.
     */
    private GitLabHandler $gitlabHandler;

    /**
     * Logger instance.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;

    /**
     * App manager instance.
     *
     * @var IAppManager The app manager instance.
     */
    private IAppManager $appManager;

    /**
     * Constructor
     *
     * @param string               $appName              The app name
     * @param IRequest             $request              The request object
     * @param ConfigurationMapper  $configurationMapper  Configuration mapper
     * @param ConfigurationService $configurationService Configuration service
     * @param NotificationService  $notificationService  Notification service
     * @param GitHubHandler        $githubHandler        GitHub handler
     * @param GitLabHandler        $gitlabHandler        GitLab handler
     * @param IAppManager          $appManager           App manager
     * @param LoggerInterface      $logger               Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ConfigurationMapper $configurationMapper,
        ConfigurationService $configurationService,
        NotificationService $notificationService,
        GitHubHandler $githubHandler,
        GitLabHandler $gitlabHandler,
        IAppManager $appManager,
        LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);

        $this->configurationMapper  = $configurationMapper;
        $this->configurationService = $configurationService;
        $this->notificationService  = $notificationService;
        $this->githubHandler        = $githubHandler;
        $this->gitlabHandler        = $gitlabHandler;
        $this->appManager           = $appManager;
        $this->logger = $logger;
    }//end __construct()

    /**
     * Get all configurations.
     *
     * @return JSONResponse JSON response with configurations list
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array<'Failed to fetch configurations'|Configuration>, array<never, never>>
     */
    public function index(): JSONResponse
    {
        try {
            $configurations = $this->configurationMapper->findAll();

            return new JSONResponse(data: $configurations, statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error(message: 'Failed to fetch configurations: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to fetch configurations'], statusCode: 500);
        }//end try
    }//end index()

    /**
     * Get a single configuration by ID.
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse JSON response with single configuration
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, Configuration,
     *     array<never, never>>|JSONResponse<404|500,
     *     array{error: 'Configuration not found'|'Failed to fetch configuration'},
     *     array<never, never>>
     */
    public function show(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);

            return new JSONResponse(data: $configuration, statusCode: 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Configuration not found'], statusCode: 404);
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to fetch configuration {$id}: ".$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to fetch configuration'], statusCode: 500);
        }//end try
    }//end show()

    /**
     * Enrich configuration details by fetching actual file contents
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with enriched configuration details
     */
    public function enrichDetails(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $source = strtolower($data['source'] ?? 'github');
            $owner  = $data['owner'] ?? '';
            $repo   = $data['repo'] ?? '';
            $path   = $data['path'] ?? '';
            $branch = $data['branch'] ?? 'main';

            // Validate required parameters.
            if (empty($owner) === true || empty($repo) === true || empty($path) === true) {
                return new JSONResponse(
                    data: ['error' => 'Missing required parameters: owner, repo, path'],
                    statusCode: 400
                );
            }

            $this->logger->info(
                message: 'Enriching configuration details',
                context: [
                    'source' => $source,
                    'owner'  => $owner,
                    'repo'   => $repo,
                    'path'   => $path,
                ]
            );

            // Call appropriate service.
            $details = null;
            if ($source === 'github') {
                $details = $this->githubHandler->enrichConfigurationDetails(
                    owner: $owner,
                    repo: $repo,
                    path: $path,
                    branch: $branch
                );
            }

            if ($source === 'gitlab') {
                // GitLab enrichment can be added later if needed.
                $this->logger->warning('GitLab enrichment not yet implemented');
            }

            if ($details === null) {
                return new JSONResponse(data: ['error' => 'Failed to fetch configuration details'], statusCode: 404);
            }

            return new JSONResponse(data: $details, statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error(
                message: 'Configuration enrichment failed: '.$e->getMessage(),
                context: [
                    'exception' => get_class($e),
                    'trace'     => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(data: ['error' => 'Failed to enrich configuration: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end enrichDetails()

    /**
     * Create a new configuration.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with created configuration
     */
    public function create(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            $configuration = new Configuration();
            $configuration->setTitle($data['title'] ?? 'New Configuration');
            $configuration->setDescription($data['description'] ?? '');
            $configuration->setType($data['type'] ?? 'manual');
            $configuration->setSourceType($data['sourceType'] ?? 'local');
            $configuration->setSourceUrl($data['sourceUrl'] ?? null);
            $configuration->setApp($data['app'] ?? null);
            $version = $data['version'] ?? '1.0.0';
            $configuration->setVersion($version);
            // For local configurations, sync version to localVersion.
            $configuration->setLocalVersion($data['localVersion'] ?? null);
            if ($configuration->getIsLocal() === true) {
                $configuration->setLocalVersion($data['localVersion'] ?? $version);
            }

            $configuration->setRegisters($data['registers'] ?? []);
            $configuration->setSchemas($data['schemas'] ?? []);
            $configuration->setObjects($data['objects'] ?? []);
            $configuration->setAutoUpdate($data['autoUpdate'] ?? false);
            $configuration->setNotificationGroups($data['notificationGroups'] ?? []);
            $configuration->setGithubRepo($data['githubRepo'] ?? null);
            $configuration->setGithubBranch($data['githubBranch'] ?? null);
            $configuration->setGithubPath($data['githubPath'] ?? null);

            $created = $this->configurationMapper->insert($configuration);

            $this->logger->info(message: "Created configuration: {$created->getTitle()} (ID: {$created->getId()})");

            // Return 201 Created with explicit status code.
            return new JSONResponse($created->jsonSerialize(), Http::STATUS_CREATED);
        } catch (Exception $e) {
            $this->logger->error(message: 'Failed to create configuration: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to create configuration: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end create()

    /**
     * Update an existing configuration.
     *
     * @param int $id The configuration ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with updated configuration
     */
    public function update(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $data          = $this->request->getParams();

            // Apply updates using data-driven approach.
            $this->applyConfigurationUpdates(
                configuration: $configuration,
                data: $data
            );

            $updated = $this->configurationMapper->update($configuration);

            $this->logger->info(
                message: "Updated configuration: {$updated->getTitle()} (ID: {$updated->getId()})"
            );

            return new JSONResponse(data: $updated, statusCode: 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                data: ['error' => 'Configuration not found'],
                statusCode: 404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to update configuration {$id}: ".$e->getMessage());

            return new JSONResponse(
                data: ['error' => 'Failed to update configuration: '.$e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end update()

    /**
     * Apply configuration updates from request data.
     *
     * @param Configuration $configuration Configuration entity to update
     * @param array         $data          Request data with field updates
     *
     * @return void
     */
    private function applyConfigurationUpdates(Configuration $configuration, array $data): void
    {
        // Define field mappings: field name => setter method.
        $fieldMappings = [
            'title'              => 'setTitle',
            'description'        => 'setDescription',
            'type'               => 'setType',
            'sourceType'         => 'setSourceType',
            'sourceUrl'          => 'setSourceUrl',
            'app'                => 'setApp',
            'localVersion'       => 'setLocalVersion',
            'registers'          => 'setRegisters',
            'schemas'            => 'setSchemas',
            'objects'            => 'setObjects',
            'autoUpdate'         => 'setAutoUpdate',
            'notificationGroups' => 'setNotificationGroups',
            'githubRepo'         => 'setGithubRepo',
            'githubBranch'       => 'setGithubBranch',
            'githubPath'         => 'setGithubPath',
        ];

        // Apply standard field updates.
        foreach ($fieldMappings as $field => $setter) {
            if (($data[$field] ?? null) !== null) {
                $configuration->$setter($data[$field]);
            }
        }

        // Handle version field with special logic for local configurations.
        if (($data['version'] ?? null) !== null) {
            $configuration->setVersion($data['version']);

            // For local configurations, sync version to localVersion.
            if ($configuration->getIsLocal() === true) {
                $configuration->setLocalVersion($data['version']);
            }
        }
    }//end applyConfigurationUpdates()

    /**
     * Delete a configuration.
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse Success response
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|404|500,
     *     array{error?: 'Configuration not found'|'Failed to delete configuration',
     *     success?: true}, array<never, never>>
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $this->configurationMapper->delete($configuration);

            $this->logger->info("Deleted configuration: {$configuration->getTitle()} (ID: {$id})");

            return new JSONResponse(data: ['success' => true], statusCode: 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Configuration not found'], statusCode: 404);
        } catch (Exception $e) {
            $this->logger->error("Failed to delete configuration {$id}: ".$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to delete configuration'], statusCode: 500);
        }//end try
    }//end destroy()

    /**
     * Check remote version of a configuration.
     *
     * @param int $id The configuration ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with version comparison
     */
    public function checkVersion(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);

            // Check remote version.
            $remoteVersion = $this->configurationService->checkRemoteVersion($configuration);

            if ($remoteVersion === null) {
                return new JSONResponse(data: ['error' => 'Could not check remote version'], statusCode: 500);
            }

            // Get version comparison.
            $comparison = $this->configurationService->compareVersions($configuration);

            return new JSONResponse(data: $comparison, statusCode: 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Configuration not found'], statusCode: 404);
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to check version for configuration {$id}: ".$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to fetch remote version: '.$e->getMessage()], statusCode: 500);
        } catch (Exception $e) {
            $this->logger->error("Failed to check version for configuration {$id}: ".$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to check version'], statusCode: 500);
        }//end try
    }//end checkVersion()

    /**
     * Preview configuration changes.
     *
     * @param int $id The configuration ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-suppress InvalidReturnStatement
     * @psalm-suppress InvalidReturnType
     *
     * @return JSONResponse JSON response with configuration preview
     */
    public function preview(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);

            $preview = $this->configurationService->previewConfigurationChanges(
                $configuration
            );

            if ($preview instanceof JSONResponse) {
                return $preview;
            }

            return new JSONResponse(data: $preview, statusCode: 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Configuration not found'], statusCode: 404);
        } catch (Exception $e) {
            $this->logger->error("Failed to preview configuration {$id}: ".$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to preview configuration changes'], statusCode: 500);
        }//end try
    }//end preview()

    /**
     * Import configuration with user selection.
     *
     * @param int $id The configuration ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with import result
     */
    public function import(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $data          = $this->request->getParams();
            $selection     = $data['selection'] ?? [];

            $result = $this->configurationService->importConfigurationWithSelection(
                configuration: $configuration,
                selection: $selection
            );

            // Mark notifications as processed.
            $this->notificationService->markConfigurationUpdated($configuration);

            $this->logger->info(
                "Imported configuration {$configuration->getTitle()}: ".json_encode(
                    [
                        'registers' => count($result['registers']),
                        'schemas'   => count($result['schemas']),
                        'objects'   => count($result['objects']),
                    ]
                )
            );

            return new JSONResponse(
                data: [
                    'success'        => true,
                    'registersCount' => count($result['registers']),
                    'schemasCount'   => count($result['schemas']),
                    'objectsCount'   => count($result['objects']),
                ],
                statusCode: 200
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Configuration not found'], statusCode: 404);
        } catch (Exception $e) {
            $this->logger->error("Failed to import configuration {$id}: ".$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to import configuration: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end import()

    /**
     * Export configuration to download or GitHub.
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse JSON response with configuration data
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|404|500, array, array<never, never>>
     */
    public function export(int $id): JSONResponse
    {
        try {
            $configuration  = $this->configurationMapper->find($id);
            $data           = $this->request->getParams();
            $includeObjects = ($data['includeObjects'] ?? false) === true;

            // Export the configuration.
            $exportData = $this->configurationService->exportConfig(
                input: $configuration,
                includeObjects: $includeObjects
            );

            // Return the export data directly for download.
            return new JSONResponse(data: $exportData, statusCode: 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Configuration not found'], statusCode: 404);
        } catch (Exception $e) {
            $this->logger->error("Failed to export configuration {$id}: ".$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to export configuration: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end export()

    /**
     * Discover OpenRegister configurations on GitHub or GitLab
     *
     * @return JSONResponse JSON response with search results from GitHub
     *
     * @since 0.2.10
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|400|500,
     *     array{error?: string, total_count?: int<0, max>|mixed,
     *     results?: list{0?: array{repository?: mixed, owner?: string,
     *     repo?: string, path: mixed|string, url: ''|mixed, stars?: 0|mixed,
     *     description?: ''|mixed, name: string, branch?: string,
     *     raw_url?: string, sha?: null|string,
     *     organization?: array{name: string, avatar_url: ''|mixed,
     *     type: 'User'|mixed, url: ''|mixed}, config: array,
     *     project_id?: mixed, ref?: 'main'|mixed}|mixed,...},
     *     page?: int, per_page?: int}, array<never, never>>
     */
    public function discover(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $source = strtolower($data['source'] ?? 'github');
            $search = $data['_search'] ?? '';
            $page   = (int) ($data['page'] ?? 1);

            $this->logger->info(
                'Discovering configurations',
                [
                    'source'  => $source,
                    '_search' => $search,
                    'page'    => $page,
                ]
            );

            // Validate source.
            if (in_array($source, ['github', 'gitlab']) === false) {
                return new JSONResponse(data: ['error' => 'Invalid source. Must be "github" or "gitlab"'], statusCode: 400);
            }

            // Call appropriate service.
            if ($source === 'github') {
                $this->logger->info('About to call GitHub search service');
                $results = $this->githubHandler->searchConfigurations(search: $search, page: $page);
                $this->logger->info('GitHub search completed', ['result_count' => count($results['results'] ?? [])]);
            }

            if ($source !== 'github') {
                $this->logger->info('About to call GitLab search service');
                $results = $this->gitlabHandler->searchConfigurations(
                    search: $search,
                    page: $page
                );
                $this->logger->info(
                    'GitLab search completed',
                    ['result_count' => count($results['results'] ?? [])]
                );
            }

            return new JSONResponse(data: $results, statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error(
                'Configuration discovery failed: '.$e->getMessage(),
                [
                    'source'    => $source ?? 'unknown',
                    'exception' => get_class($e),
                    'trace'     => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: ['error' => 'Failed to discover configurations: '.$e->getMessage()],
                statusCode: 500
            );
        }//end try
    }//end discover()

    /**
     * Get branches from a GitHub repository
     *
     * @since 0.2.10
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with branches list
     */
    public function getGitHubBranches(): JSONResponse
    {
        try {
            $data  = $this->request->getParams();
            $owner = $data['owner'] ?? '';
            $repo  = $data['repo'] ?? '';

            if (empty($owner) === true || empty($repo) === true) {
                return new JSONResponse(data: ['error' => 'Owner and repo parameters are required'], statusCode: 400);
            }

            $this->logger->info(
                'Fetching GitHub branches',
                [
                    'owner' => $owner,
                    'repo'  => $repo,
                ]
            );

            $branches = $this->githubHandler->getBranches(owner: $owner, repo: $repo);

            return new JSONResponse(data: ['branches' => $branches], statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to get GitHub branches: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to fetch branches: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end getGitHubBranches()

    /**
     * Get repositories that the authenticated user has access to
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with repositories list
     */
    public function getGitHubRepositories(): JSONResponse
    {
        try {
            $data    = $this->request->getParams();
            $page    = 1;
            $perPage = 100;
            if (($data['page'] ?? null) !== null) {
                $page = (int) $data['page'];
            }

            if (($data['per_page'] ?? null) !== null) {
                $perPage = (int) $data['per_page'];
            }

            $this->logger->info(
                'Fetching GitHub repositories',
                [
                    'page'     => $page,
                    'per_page' => $perPage,
                ]
            );

            $repositories = $this->githubHandler->getRepositories(
                page: $page,
                perPage: $perPage
            );

            return new JSONResponse(data: ['repositories' => $repositories], statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to get GitHub repositories: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to fetch repositories: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end getGitHubRepositories()

    /**
     * Get configuration files from a GitHub repository
     *
     * @since 0.2.10
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with configuration files
     */
    public function getGitHubConfigurations(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $owner  = $data['owner'] ?? '';
            $repo   = $data['repo'] ?? '';
            $branch = $data['branch'] ?? 'main';

            if (empty($owner) === true || empty($repo) === true) {
                return new JSONResponse(data: ['error' => 'Owner and repo parameters are required'], statusCode: 400);
            }

            $this->logger->info(
                'Fetching GitHub configurations',
                [
                    'owner'  => $owner,
                    'repo'   => $repo,
                    'branch' => $branch,
                ]
            );

            $files = $this->githubHandler->listConfigurationFiles(owner: $owner, repo: $repo, branch: $branch);

            return new JSONResponse(data: ['files' => $files], statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to get GitHub configurations: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to fetch configurations: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end getGitHubConfigurations()

    /**
     * Get branches from a GitLab project
     *
     * @since 0.2.10
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with branches list
     */
    public function getGitLabBranches(): JSONResponse
    {
        try {
            $data      = $this->request->getParams();
            $namespace = $data['namespace'] ?? '';
            $project   = $data['project'] ?? '';

            if (empty($namespace) === true || empty($project) === true) {
                return new JSONResponse(data: ['error' => 'Namespace and project parameters are required'], statusCode: 400);
            }

            // Get project ID from namespace/project path.
            $projectData = $this->gitlabHandler->getProjectByPath(namespace: $namespace, project: $project);
            $projectId   = $projectData['id'];

            $this->logger->info(
                'Fetching GitLab branches',
                [
                    'namespace'  => $namespace,
                    'project'    => $project,
                    'project_id' => $projectId,
                ]
            );

            $branches = $this->gitlabHandler->getBranches($projectId);

            return new JSONResponse(data: ['branches' => $branches], statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to get GitLab branches: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to fetch branches: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end getGitLabBranches()

    /**
     * Get configuration files from a GitLab project
     *
     * @since 0.2.10
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with configuration files
     */
    public function getGitLabConfigurations(): JSONResponse
    {
        try {
            $data      = $this->request->getParams();
            $namespace = $data['namespace'] ?? '';
            $project   = $data['project'] ?? '';
            $ref       = $data['ref'] ?? 'main';

            if (empty($namespace) === true || empty($project) === true) {
                return new JSONResponse(data: ['error' => 'Namespace and project parameters are required'], statusCode: 400);
            }

            // Get project ID from namespace/project path.
            $projectData = $this->gitlabHandler->getProjectByPath(namespace: $namespace, project: $project);
            $projectId   = $projectData['id'];

            $this->logger->info(
                'Fetching GitLab configurations',
                [
                    'namespace'  => $namespace,
                    'project'    => $project,
                    'project_id' => $projectId,
                    'ref'        => $ref,
                ]
            );

            $files = $this->gitlabHandler->listConfigurationFiles(projectId: $projectId, ref: $ref);

            return new JSONResponse(data: ['files' => $files], statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to get GitLab configurations: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to fetch configurations: '.$e->getMessage()], statusCode: 500);
        }//end try
    }//end getGitLabConfigurations()

    /**
     * Fetch configuration data from GitHub repository.
     *
     * @param array $params Request parameters containing owner, repo, path, branch
     *
     * @return (array|string)[]
     *
     * @throws Exception If parameters are missing or GitHub API call fails
     *
     * @psalm-return array{configData: array, sourceUrl: string, metadata: array{owner: string, repo: string, path: string, branch: string}}
     */
    private function fetchConfigFromGitHub(array $params): array
    {
        $owner  = $params['owner'] ?? '';
        $repo   = $params['repo'] ?? '';
        $path   = $params['path'] ?? '';
        $branch = $params['branch'] ?? 'main';

        if (empty($owner) === true || empty($repo) === true || empty($path) === true) {
            throw new Exception('Owner, repo, and path parameters are required', 400);
        }

        // Get file content from GitHub.
        $configData = $this->githubHandler->getFileContent(owner: $owner, repo: $repo, path: $path, branch: $branch);

        // Build source URL.
        $sourceUrl = "https://github.com/{$owner}/{$repo}/blob/{$branch}/{$path}";

        return [
            'configData' => $configData,
            'sourceUrl'  => $sourceUrl,
            'metadata'   => [
                'owner'  => $owner,
                'repo'   => $repo,
                'path'   => $path,
                'branch' => $branch,
            ],
        ];
    }//end fetchConfigFromGitHub()

    /**
     * Fetch configuration data from GitLab repository.
     *
     * @param array $params Request parameters containing namespace, project, path, ref
     *
     * @return array Configuration data, source URL, and metadata
     *
     * @throws Exception If parameters are missing or GitLab API call fails
     */
    private function fetchConfigFromGitLab(array $params): array
    {
        $namespace = $params['namespace'] ?? '';
        $project   = $params['project'] ?? '';
        $path      = $params['path'] ?? '';
        $ref       = $params['ref'] ?? 'main';

        if (empty($namespace) === true || empty($project) === true || empty($path) === true) {
            throw new Exception('Namespace, project, and path parameters are required', 400);
        }

        // Get project ID from namespace/project path.
        $projectData = $this->gitlabHandler->getProjectByPath(namespace: $namespace, project: $project);
        $projectId   = $projectData['id'];

        // Get file content from GitLab.
        $configData = $this->gitlabHandler->getFileContent(projectId: $projectId, path: $path, ref: $ref);

        // Build GitLab URL for sourceUrl.
        $gitlabBase = $this->gitlabHandler->getApiBase();
        $webBase    = str_replace('/api/v4', '', $gitlabBase);
        $sourceUrl  = "{$webBase}/{$namespace}/{$project}/-/blob/{$ref}/{$path}";

        return [
            'configData' => $configData,
            'sourceUrl'  => $sourceUrl,
            'metadata'   => [
                'namespace' => $namespace,
                'project'   => $project,
                'projectId' => $projectId,
                'path'      => $path,
                'ref'       => $ref,
            ],
        ];
    }//end fetchConfigFromGitLab()

    /**
     * Fetch configuration data from URL.
     *
     * @param array $params Request parameters containing url
     *
     * @return array Configuration data, source URL, and metadata
     *
     * @throws Exception If URL is missing, invalid, or fetch fails
     *
     * @psalm-return array{configData: array, sourceUrl: string, metadata: array{url: string}}
     */
    private function fetchConfigFromUrl(array $params): array
    {
        $url = $params['url'] ?? '';

        if (empty($url) === true) {
            throw new Exception('URL parameter is required', 400);
        }

        // Validate URL.
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new Exception('Invalid URL provided', 400);
        }

        // Fetch content from URL.
        $client   = new Client();
        $response = $client->request('GET', $url);
        $content  = $response->getBody()->getContents();

        $configData = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in URL response: '.json_last_error_msg());
        }

        return [
            'configData' => $configData,
            'sourceUrl'  => $url,
            'metadata'   => [
                'url' => $url,
            ],
        ];
    }//end fetchConfigFromUrl()

    /**
     * Common import pipeline for all configuration sources.
     *
     * This method handles the standard import flow:
     * 1. Fetch configuration data from source (via callback)
     * 2. Extract metadata
     * 3. Check for existing configuration
     * 4. Create configuration entity
     * 5. Import using standard flow
     * 6. Update sync status
     * 7. Return success response
     *
     * @param callable $fetchConfig Function that fetches config data from source
     * @param array    $params      Request parameters
     * @param string   $sourceType  Source type (github, gitlab, url)
     *
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress InvalidReturnStatement
     * @psalm-suppress InvalidArgument
     *
     * @return JSONResponse JSON response with import result
     */
    private function importFromSource(callable $fetchConfig, array $params, string $sourceType): JSONResponse
    {
        try {
            // Extract common parameters.
            $syncEnabled  = ($params['syncEnabled'] ?? true) === true;
            $syncInterval = (int) ($params['syncInterval'] ?? 24);

            // Log import start.
            $this->logger->info("Importing configuration from {$sourceType}", ['params' => $params]);

            // Step 1: Fetch configuration data from source (source-specific logic).
            $fetchResult = $fetchConfig($params);
            $configData  = $fetchResult['configData'];
            $sourceUrl   = $fetchResult['sourceUrl'];
            $metadata    = $fetchResult['metadata'];

            // Step 2: Extract metadata from config.
            $info          = $configData['info'] ?? [];
            $xOpenregister = $configData['x-openregister'] ?? [];
            $appId         = $xOpenregister['app'] ?? 'imported';
            $version       = $info['version'] ?? $xOpenregister['version'] ?? '1.0.0';
            $title         = $info['title'] ?? $xOpenregister['title'] ?? "Configuration from {$sourceType}";
            $description   = $info['description'] ?? $xOpenregister['description'] ?? "Imported from {$sourceType}";

            // Step 3: Check if configuration already exists for this app.
            $existingConfigs = $this->configurationMapper->findByApp($appId);
            if (count($existingConfigs) > 0) {
                return new JSONResponse(
                    data: [
                        'error'                   => $this->getExistingConfigErrorMessage($appId),
                        'existingConfigurationId' => $existingConfigs[0]->getId(),
                    ],
                    statusCode: 409
                );
            }

            // Step 4: Create Configuration entity.
            $configuration = new Configuration();
            $configuration->setTitle($title);
            $configuration->setDescription($description);
            $configuration->setType($xOpenregister['type'] ?? $sourceType);
            $configuration->setSourceType($sourceType);
            $configuration->setSourceUrl($sourceUrl);
            $configuration->setApp($appId);
            $configuration->setVersion($version);
            $configuration->setLocalVersion(null);
            // Will be set after import.
            $configuration->setIsLocal(false);
            $configuration->setSyncEnabled($syncEnabled);
            $configuration->setSyncInterval($syncInterval);
            $configuration->setAutoUpdate(false);
            $configuration->setRegisters([]);
            $configuration->setSchemas([]);
            $configuration->setObjects([]);

            // Set source-specific fields if available.
            $hasGithubMeta = isset($metadata['owner'], $metadata['repo'], $metadata['path'], $metadata['branch']);
            if ($sourceType === 'github' && $hasGithubMeta === true) {
                $configuration->setGithubRepo("{$metadata['owner']}/{$metadata['repo']}");
                $configuration->setGithubBranch($metadata['branch']);
                $configuration->setGithubPath($metadata['path']);
            }

            $configuration = $this->configurationMapper->insert($configuration);

            $this->logger->info("Created configuration entity with ID {$configuration->getId()} for app {$appId}");

            // Step 5: Import using the standard flow with the configuration entity.
            $result = $this->configurationService->importFromJson(
                data: $configData,
                configuration: $configuration,
                owner: $appId,
                appId: $appId,
                version: $version,
                force: false
            );

            // Step 6: Update configuration with sync status and imported entity IDs.
            $configuration->setLocalVersion($version);
            $configuration->setSyncStatus('success');
            $configuration->setLastSyncDate(new DateTime());

            // The importFromJson already updates the configuration with entity IDs via createOrUpdateConfiguration.
            // But we need to save the sync status.
            $this->configurationMapper->update($configuration);

            $this->logger->info("Successfully imported configuration {$configuration->getTitle()} from {$sourceType}");

            // Step 7: Return success response.
            return new JSONResponse(
                data: [
                    'success'         => true,
                    'message'         => "Configuration imported successfully from {$sourceType}",
                    'configurationId' => $configuration->getId(),
                    'result'          => [
                        'registersCount' => count($result['registers']),
                        'schemasCount'   => count($result['schemas']),
                        'objectsCount'   => count($result['objects']),
                    ],
                ],
                statusCode: 201
            );
        } catch (Exception $e) {
            // Determine status code from exception or default to 500.
            $statusCode = (int) $e->getCode();
            if ($statusCode < 400 || $statusCode >= 600) {
                $statusCode = 500;
            }

            $this->logger->error("Failed to import from {$sourceType}: ".$e->getMessage());

            return new JSONResponse(
                data: ['error' => 'Failed to import configuration: '.$e->getMessage()],
                statusCode: $statusCode
            );
        }//end try
    }//end importFromSource()

    /**
     * Import configuration from GitHub
     *
     * This method creates a Configuration entity and then imports it using the standard import flow.
     *
     * @since 0.2.10
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with import result
     */
    public function importFromGitHub(): JSONResponse
    {
        return $this->importFromSource(
            fetchConfig: fn($params) => $this->fetchConfigFromGitHub($params),
            params: $this->request->getParams(),
            sourceType: 'github'
        );
    }//end importFromGitHub()

    /**
     * Import configuration from GitLab
     *
     * This method creates a Configuration entity and then imports it using the standard import flow.
     *
     * @since 0.2.10
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with import result
     */
    public function importFromGitLab(): JSONResponse
    {
        return $this->importFromSource(
            fetchConfig: fn($params) => $this->fetchConfigFromGitLab($params),
            params: $this->request->getParams(),
            sourceType: 'gitlab'
        );
    }//end importFromGitLab()

    /**
     * Import configuration from URL
     *
     * This method creates a Configuration entity and then imports it using the standard import flow.
     *
     * @since 0.2.10
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with import result
     */
    public function importFromUrl(): JSONResponse
    {
        return $this->importFromSource(
            fetchConfig: fn($params) => $this->fetchConfigFromUrl($params),
            params: $this->request->getParams(),
            sourceType: 'url'
        );
    }//end importFromUrl()

    /**
     * Publish a local configuration to GitHub
     *
     * Exports the configuration and publishes it to the specified GitHub repository.
     * Updates the configuration with GitHub source information.
     *
     * @param int $id Configuration ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress InvalidReturnStatement
     *
     * @return JSONResponse JSON response with publish result
     */
    public function publishToGitHub(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);

            // Validate configuration is publishable.
            $validationResponse = $this->validateConfigurationForPublishing(configuration: $configuration);
            if ($validationResponse !== null) {
                return $validationResponse;
            }

            // Extract and validate request parameters.
            $params = $this->extractGitHubPublishParams(configuration: $configuration);
            if (isset($params['error']) === true) {
                return new JSONResponse(data: ['error' => $params['error']], statusCode: 400);
            }

            $this->logPublishingAttempt(id: $id, params: $params);

            // Prepare configuration data for GitHub.
            $jsonContent = $this->prepareConfigurationForGitHub(
                configuration: $configuration,
                params: $params
            );

            // Get existing file SHA for updates.
            $fileSha = $this->getExistingFileSha(params: $params);

            // Publish to GitHub.
            $result = $this->publishConfigurationToGitHub(
                params: $params,
                content: $jsonContent,
                fileSha: $fileSha
            );

            // Update local configuration with GitHub info.
            $this->updateConfigurationWithGitHubInfo(configuration: $configuration, params: $params);

            $this->logPublishingSuccess(configuration: $configuration, params: $params, result: $result);

            // Build success response with indexing information.
            return $this->buildPublishSuccessResponse(
                configuration: $configuration,
                params: $params,
                result: $result
            );
        } catch (Exception $e) {
            return $this->handlePublishingError(exception: $e);
        }//end try
    }//end publishToGitHub()

    /**
     * Get error message for existing configuration.
     *
     * @param string $appId Application ID
     *
     * @return string Error message
     */
    private function getExistingConfigErrorMessage(string $appId): string
    {
        $message  = "Configuration for app '{$appId}' already exists. ";
        $message .= 'Please update the existing configuration instead.';

        return $message;
    }//end getExistingConfigErrorMessage()

    /**
     * Validate configuration can be published
     *
     * Checks if configuration is local and can be published to GitHub.
     *
     * @param object $configuration Configuration entity.
     *
     * @return JSONResponse|null Error response if validation fails, null if valid.
     *
     * @psalm-return JSONResponse<400, array{error: 'Only local configurations can be published'}, array<never, never>>|null
     */
    private function validateConfigurationForPublishing(object $configuration): JSONResponse|null
    {
        // Only allow publishing local configurations.
        if ($configuration->getIsLocal() !== true) {
            return new JSONResponse(
                data: ['error' => 'Only local configurations can be published'],
                statusCode: 400
            );
        }

        return null;
    }//end validateConfigurationForPublishing()

    /**
     * Extract and validate GitHub publishing parameters
     *
     * Extracts owner, repo, path, branch, and commit message from request.
     * Validates required parameters and normalizes path.
     *
     * @param object $configuration Configuration entity.
     *
     * @return array<string, string> Parameters array or error array.
     */
    private function extractGitHubPublishParams(object $configuration): array
    {
        $data          = $this->request->getParams();
        $owner         = $data['owner'] ?? '';
        $repo          = $data['repo'] ?? '';
        $path          = $data['path'] ?? '';
        $branch        = $data['branch'] ?? 'main';
        $commitMessage = $data['commitMessage'] ?? "Update configuration: {$configuration->getTitle()}";

        // Validate required parameters.
        if (empty($owner) === true || empty($repo) === true) {
            return ['error' => 'Owner and repo parameters are required'];
        }

        // Normalize path: strip leading slash, generate default if empty.
        $path = ltrim($path, '/');
        if (empty($path) === true) {
            $title          = $configuration->getTitle();
            $snakeCaseTitle = $this->toSnakeCase($title ?? 'configuration');
            $path           = $snakeCaseTitle.'_openregister.json';
        }

        return [
            'owner'         => $owner,
            'repo'          => $repo,
            'path'          => $path,
            'branch'        => $branch,
            'commitMessage' => $commitMessage,
        ];
    }//end extractGitHubPublishParams()

    /**
     * Log publishing attempt
     *
     * Logs configuration publishing details for debugging.
     *
     * @param int                   $id     Configuration ID.
     * @param array<string, string> $params Publishing parameters.
     *
     * @return void
     */
    private function logPublishingAttempt(int $id, array $params): void
    {
        $this->logger->info(
            'Publishing configuration to GitHub',
            [
                'configuration_id' => $id,
                'owner'            => $params['owner'],
                'repo'             => $params['repo'],
                'path'             => $params['path'],
                'branch'           => $params['branch'],
            ]
        );
    }//end logPublishingAttempt()

    /**
     * Prepare configuration for GitHub publishing
     *
     * Exports configuration and adds GitHub metadata.
     *
     * @param object                $configuration Configuration entity.
     * @param array<string, string> $params        Publishing parameters.
     *
     * @return false|string JSON content ready for GitHub.
     */
    private function prepareConfigurationForGitHub(object $configuration, array $params): string|false
    {
        // Export configuration to array.
        $configData = $this->configurationService->exportConfig(
            input: $configuration,
            includeObjects: false
        );

        // Initialize x-openregister metadata if not present.
        if (isset($configData['x-openregister']) === false) {
            $configData['x-openregister'] = [];
        }

        // Remove local source information.
        unset($configData['x-openregister']['sourceType']);
        unset($configData['x-openregister']['sourceUrl']);

        // Add OpenRegister version and GitHub info.
        $openregisterVersion = $this->appManager->getAppVersion('openregister');
        $githubRepo          = "{$params['owner']}/{$params['repo']}";

        $configData['x-openregister']['openregister'] = $openregisterVersion;
        $configData['x-openregister']['github']       = [
            'repo'   => $githubRepo,
            'branch' => $params['branch'],
            'path'   => $params['path'],
        ];

        return json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }//end prepareConfigurationForGitHub()

    /**
     * Get existing file SHA for updates
     *
     * Retrieves the SHA of existing file on GitHub for update operations.
     *
     * @param array<string, string> $params Publishing parameters.
     *
     * @return string|null File SHA if exists, null for new files.
     */
    private function getExistingFileSha(array $params): ?string
    {
        try {
            return $this->githubHandler->getFileSha(
                owner: $params['owner'],
                repo: $params['repo'],
                path: $params['path'],
                branch: $params['branch']
            );
        } catch (Exception $e) {
            // File doesn't exist, which is fine for new files.
            $this->logger->debug('File does not exist, will create new file', ['path' => $params['path']]);
            return null;
        }
    }//end getExistingFileSha()

    /**
     * Publish configuration to GitHub
     *
     * Calls GitHub handler to publish/update the configuration file.
     *
     * @param array<string, string> $params  Publishing parameters.
     * @param string                $content JSON content to publish.
     * @param string|null           $fileSha Existing file SHA for updates.
     *
     * @return (mixed|null|true)[] Result from GitHub API.
     *
     * @psalm-return array{success: true, commit_sha: mixed|null, file_sha: mixed|null, commit_url: mixed|null, file_url: mixed|null}
     */
    private function publishConfigurationToGitHub(array $params, string $content, ?string $fileSha): array
    {
        return $this->githubHandler->publishConfiguration(
            owner: $params['owner'],
            repo: $params['repo'],
            path: $params['path'],
            branch: $params['branch'],
            content: $content,
            commitMessage: $params['commitMessage'],
            fileSha: $fileSha
        );
    }//end publishConfigurationToGitHub()

    /**
     * Update configuration with GitHub information
     *
     * Updates local configuration entity with GitHub publishing details.
     *
     * @param object                $configuration Configuration entity.
     * @param array<string, string> $params        Publishing parameters.
     *
     * @return void
     */
    private function updateConfigurationWithGitHubInfo(object $configuration, array $params): void
    {
        $githubRepo = "{$params['owner']}/{$params['repo']}";
        $sourceUrl  = "https://github.com/{$githubRepo}/blob/{$params['branch']}/{$params['path']}";

        $configuration->setGithubRepo($githubRepo);
        $configuration->setGithubBranch($params['branch']);
        $configuration->setGithubPath($params['path']);
        $configuration->setSourceUrl($sourceUrl);
        // Don't change isLocal - it stays local, but now has a published source.
        $this->configurationMapper->update($configuration);
    }//end updateConfigurationWithGitHubInfo()

    /**
     * Log publishing success
     *
     * Logs successful GitHub publishing operation.
     *
     * @param object                $configuration Configuration entity.
     * @param array<string, string> $params        Publishing parameters.
     * @param array<string, mixed>  $result        GitHub API result.
     *
     * @return void
     */
    private function logPublishingSuccess(object $configuration, array $params, array $result): void
    {
        $this->logger->info(
            "Successfully published configuration {$configuration->getTitle()} to GitHub",
            [
                'owner'    => $params['owner'],
                'repo'     => $params['repo'],
                'branch'   => $params['branch'],
                'path'     => $params['path'],
                'file_url' => $result['file_url'] ?? null,
            ]
        );
    }//end logPublishingSuccess()

    /**
     * Build success response with indexing information
     *
     * Creates success response including GitHub URLs and indexing notes.
     *
     * @param object                $configuration Configuration entity.
     * @param array<string, string> $params        Publishing parameters.
     * @param array<string, mixed>  $result        GitHub API result.
     *
     * @return JSONResponse JSON response with publish success data
     */
    private function buildPublishSuccessResponse(object $configuration, array $params, array $result): JSONResponse
    {
        // Get default branch for indexing note.
        $defaultBranch = $this->getRepositoryDefaultBranch(params: $params);

        // Build success message with indexing information.
        $message = 'Configuration published successfully to GitHub';
        if ($defaultBranch !== null && $params['branch'] !== $defaultBranch) {
            $message .= ". Note: Published to branch '{$params['branch']}' (default is '{$defaultBranch}'). ";
            $message .= 'GitHub Code Search primarily indexes the default branch, ';
            $message .= 'so this configuration may not appear in search results immediately.';
        } else {
            $message .= ". Note: GitHub Code Search may take a few minutes to index new files.";
        }

        return new JSONResponse(
            data: [
                'success'         => true,
                'message'         => $message,
                'configurationId' => $configuration->getId(),
                'commit_sha'      => $result['commit_sha'],
                'commit_url'      => $result['commit_url'],
                'file_url'        => $result['file_url'],
                'branch'          => $params['branch'],
                'default_branch'  => $defaultBranch,
                'indexing_note'   => $this->getIndexingNote(
                    defaultBranch: $defaultBranch,
                    branch: $params['branch']
                ),
            ],
            statusCode: 200
        );
    }//end buildPublishSuccessResponse()

    /**
     * Get repository default branch
     *
     * Fetches the default branch name from GitHub repository.
     *
     * @param array<string, string> $params Publishing parameters.
     *
     * @return string|null Default branch name or null if unable to fetch.
     */
    private function getRepositoryDefaultBranch(array $params): ?string
    {
        try {
            $repoInfo = $this->githubHandler->getRepositoryInfo(
                owner: $params['owner'],
                repo: $params['repo']
            );
            return $repoInfo['default_branch'] ?? 'main';
        } catch (Exception $e) {
            $this->logger->warning(
                'Could not fetch repository default branch',
                [
                    'owner' => $params['owner'],
                    'repo'  => $params['repo'],
                    'error' => $e->getMessage(),
                ]
            );
            return null;
        }
    }//end getRepositoryDefaultBranch()

    /**
     * Handle publishing error
     *
     * Logs error and returns error response.
     *
     * @param Exception $exception The exception that occurred.
     *
     * @return JSONResponse JSON response with error message
     */
    private function handlePublishingError(Exception $exception): JSONResponse
    {
        $this->logger->error('Failed to publish to GitHub: '.$exception->getMessage());

        return new JSONResponse(
            data: ['error' => 'Failed to publish configuration: '.$exception->getMessage()],
            statusCode: 500
        );
    }//end handlePublishingError()

    /**
     * Get indexing note based on branch information.
     *
     * @param string|null $defaultBranch Default branch name
     * @param string      $branch        Current branch name
     *
     * @return string Indexing note message
     */
    private function getIndexingNote(?string $defaultBranch, string $branch): string
    {
        if ($defaultBranch !== null && $branch !== $defaultBranch) {
            return "Published to non-default branch. For discovery, publish to '{$defaultBranch}' branch.";
        }

        return 'File published successfully. GitHub Code Search indexing may take a few minutes.';
    }//end getIndexingNote()

    /**
     * Convert a string to snake_case
     *
     * @param string $string The string to convert
     *
     * @return string The snake_case version
     */
    private function toSnakeCase(string $string): string
    {
        // Convert to lowercase.
        $string = strtolower($string);

        // Replace spaces and hyphens with underscores.
        $string = preg_replace('/[\s\-]+/', '_', $string);

        // Remove any non-alphanumeric characters except underscores.
        $string = preg_replace('/[^a-z0-9_]/', '', $string);

        // Remove multiple consecutive underscores.
        $string = preg_replace('/_+/', '_', $string);

        // Trim underscores from start and end.
        $string = trim($string, '_');

        return $string;
    }//end toSnakeCase()
}//end class
