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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse List of configurations
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The configuration details
     *
     * @psalm-return JSONResponse<200, Configuration, array<never, never>>|JSONResponse<404|500, array{error: 'Configuration not found'|'Failed to fetch configuration'}, array<never, never>>
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
     * @psalm-return JSONResponse<int, array{error?: string, title?: mixed|string, description?: ''|mixed, version?: 'v.unknown'|mixed, app?: mixed|null, type?: 'unknown'|mixed, openregister?: mixed|null}, array<never, never>>
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
                return new JSONResponse(data: ['error' => 'Missing required parameters: owner, repo, path'], statusCode: 400);
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
                $details = $this->githubHandler->enrichConfigurationDetails(owner: $owner, repo: $repo, path: $path, branch: $branch);
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
     * @psalm-return JSONResponse<201, Configuration, array<never, never>>|JSONResponse<500, array{error: string}, array<never, never>>
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
     * @psalm-return JSONResponse<200, Configuration, array<never, never>>|JSONResponse<404|500, array{error: string}, array<never, never>>
     */
    public function update(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $data          = $this->request->getParams();

            // Update fields if provided.
            if (($data['title'] ?? null) !== null) {
                $configuration->setTitle($data['title']);
            }

            if (($data['description'] ?? null) !== null) {
                $configuration->setDescription($data['description']);
            }

            if (($data['type'] ?? null) !== null) {
                $configuration->setType($data['type']);
            }

            if (($data['sourceType'] ?? null) !== null) {
                $configuration->setSourceType($data['sourceType']);
            }

            if (($data['sourceUrl'] ?? null) !== null) {
                $configuration->setSourceUrl($data['sourceUrl']);
            }

            if (($data['app'] ?? null) !== null) {
                $configuration->setApp($data['app']);
            }

            if (($data['version'] ?? null) !== null) {
                $configuration->setVersion($data['version']);
                // For local configurations, sync version to localVersion.
                if ($configuration->getIsLocal() === true) {
                    $configuration->setLocalVersion($data['version']);
                }
            }

            if (($data['localVersion'] ?? null) !== null) {
                $configuration->setLocalVersion($data['localVersion']);
            }

            if (($data['registers'] ?? null) !== null) {
                $configuration->setRegisters($data['registers']);
            }

            if (($data['schemas'] ?? null) !== null) {
                $configuration->setSchemas($data['schemas']);
            }

            if (($data['objects'] ?? null) !== null) {
                $configuration->setObjects($data['objects']);
            }

            if (($data['autoUpdate'] ?? null) !== null) {
                $configuration->setAutoUpdate($data['autoUpdate']);
            }

            if (($data['notificationGroups'] ?? null) !== null) {
                $configuration->setNotificationGroups($data['notificationGroups']);
            }

            if (($data['githubRepo'] ?? null) !== null) {
                $configuration->setGithubRepo($data['githubRepo']);
            }

            if (($data['githubBranch'] ?? null) !== null) {
                $configuration->setGithubBranch($data['githubBranch']);
            }

            if (($data['githubPath'] ?? null) !== null) {
                $configuration->setGithubPath($data['githubPath']);
            }

            $updated = $this->configurationMapper->update($configuration);

            $this->logger->info(message: "Updated configuration: {$updated->getTitle()} (ID: {$updated->getId()})");

            return new JSONResponse(data: $updated, statusCode: 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(data: ['error' => 'Configuration not found'], statusCode: 404);
        } catch (Exception $e) {
            $this->logger->error("Failed to update configuration {$id}: ".$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to update configuration: '.$e->getMessage()], statusCode: 500);
        }//end try

    }//end update()

    /**
     * Delete a configuration.
     *
     * @param int $id The configuration ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Success response
     *
     * @psalm-return JSONResponse<200|404|500, array{error?: 'Configuration not found'|'Failed to delete configuration', success?: true}, array<never, never>>
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
     * @psalm-return JSONResponse<200|404|500, array{error?: string, hasUpdate?: bool, localVersion?: null|string, remoteVersion?: null|string, lastChecked?: null|string, message?: string}, array<never, never>>
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
     * @return JSONResponse Preview of changes
     *
     * @psalm-return JSONResponse<200|404|500, array{error?: 'Configuration not found'|'Failed to preview configuration changes', registers?: list<array{action: string, changes: array, current: array|null, proposed: array, slug: string, title: string, type: string}>, schemas?: list<array{action: string, changes: array, current: array|null, proposed: array, slug: string, title: string, type: string}>, objects?: list<array{action: string, changes: array, current: array|null, proposed: array, register: string, schema: string, slug: string, title: string, type: string}>, endpoints?: array<never, never>, sources?: array<never, never>, mappings?: array<never, never>, jobs?: array<never, never>, synchronizations?: array<never, never>, rules?: array<never, never>, metadata?: array{configurationId: int, configurationTitle: null|string, sourceUrl: null|string, remoteVersion: mixed|null, localVersion: null|string, previewedAt: string, totalChanges: int<0, max>}}, array<never, never>>|JSONResponse<int, \JsonSerializable|array|null|scalar|stdClass, array<string, mixed>>
     */
    public function preview(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);

            $preview = $this->configurationService->previewConfigurationChanges($configuration);

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
     * @psalm-return JSONResponse<200|404|500, array{error?: string, success?: true, registersCount?: int<0, max>, schemasCount?: int<0, max>, objectsCount?: int<0, max>}, array<never, never>>
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Export result with download URL or success message
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @since 0.2.10
     *
     * @psalm-return JSONResponse<200|400|500, array{error?: string, total_count?: int<0, max>|mixed, results?: list{0?: array{repository?: mixed, owner?: string, repo?: string, path: mixed|string, url: ''|mixed, stars?: 0|mixed, description?: ''|mixed, name: string, branch?: string, raw_url?: string, sha?: null|string, organization?: array{name: string, avatar_url: ''|mixed, type: 'User'|mixed, url: ''|mixed}, config: array, project_id?: mixed, ref?: 'main'|mixed}|mixed,...}, page?: int, per_page?: int}, array<never, never>>
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
                $results = $this->gitlabHandler->searchConfigurations(search: $search, page: $page);
                $this->logger->info('GitLab search completed', ['result_count' => count($results['results'] ?? [])]);
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

            return new JSONResponse(data: ['error' => 'Failed to discover configurations: '.$e->getMessage()], statusCode: 500);
        }//end try

    }//end discover()

    /**
     * Get branches from a GitHub repository
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @since 0.2.10
     *
     * @psalm-return JSONResponse<200|400|500, array{error?: string, branches?: array<array{name: mixed, commit: mixed|null, protected: false|mixed}>}, array<never, never>>
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
     * @psalm-return JSONResponse<200|500, array{error?: string, repositories?: array<array{id: mixed, name: mixed, full_name: mixed, owner: mixed, owner_type: mixed, private: mixed, description: ''|mixed, default_branch: 'main'|mixed, url: mixed, api_url: mixed}>}, array<never, never>>
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

            $repositories = $this->githubHandler->getRepositories(page: $page, perPage: $perPage);

            return new JSONResponse(data: ['repositories' => $repositories], statusCode: 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to get GitHub repositories: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to fetch repositories: '.$e->getMessage()], statusCode: 500);
        }//end try

    }//end getGitHubRepositories()

    /**
     * Get configuration files from a GitHub repository
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @since 0.2.10
     *
     * @psalm-return JSONResponse<200|400|500, array{error?: string, files?: list{0?: array{path: mixed, sha: mixed|null, url: mixed|null, config: array{title: mixed|string, description: ''|mixed, version: '1.0.0'|mixed, app: mixed|null, type: 'manual'|mixed}},...}}, array<never, never>>
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @since 0.2.10
     *
     * @psalm-return JSONResponse<200|400|500, array{error?: string, branches?: array<array{name: mixed, commit: mixed|null, protected: false|mixed, default: false|mixed}>}, array<never, never>>
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @since 0.2.10
     *
     * @psalm-return JSONResponse<200|400|500, array{error?: string, files?: list<array{config: array{app: mixed|null, description: ''|mixed, title: mixed|string, type: 'manual'|mixed, version: '1.0.0'|mixed}, id: mixed|null, path: mixed}>}, array<never, never>>
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
     * Import configuration from GitHub
     *
     * This method creates a Configuration entity and then imports it using the standard import flow.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @since 0.2.10
     *
     * @psalm-return JSONResponse<int, array{error?: string, existingConfigurationId?: int, success?: true, message?: 'Configuration imported successfully from GitHub', configurationId?: int, result?: array{registersCount: int<0, max>, schemasCount: int<0, max>, objectsCount: int<0, max>}}, array<never, never>>
     */
    public function importFromGitHub(): JSONResponse
    {
        try {
            $data         = $this->request->getParams();
            $owner        = $data['owner'] ?? '';
            $repo         = $data['repo'] ?? '';
            $path         = $data['path'] ?? '';
            $branch       = $data['branch'] ?? 'main';
            $syncEnabled  = ($data['syncEnabled'] ?? true) === true;
            $syncInterval = (int) ($data['syncInterval'] ?? 24);

            if (empty($owner) === true || empty($repo) === true || empty($path) === true) {
                return new JSONResponse(
                    data: ['error' => 'Owner, repo, and path parameters are required'],
                    statusCode: 400
                );
            }

            $this->logger->info(
                    'Importing configuration from GitHub',
                    [
                        'owner'  => $owner,
                        'repo'   => $repo,
                        'path'   => $path,
                        'branch' => $branch,
                    ]
                    );

            // Step 1: Get file content from GitHub.
            $configData = $this->githubHandler->getFileContent(owner: $owner, repo: $repo, path: $path, branch: $branch);

            // Extract metadata from config.
            $info          = $configData['info'] ?? [];
            $xOpenregister = $configData['x-openregister'] ?? [];
            $appId         = $xOpenregister['app'] ?? 'imported';
            $version       = $info['version'] ?? $xOpenregister['version'] ?? '1.0.0';
            $title         = $info['title'] ?? $xOpenregister['title'] ?? "Configuration from {$owner}/{$repo}";
            $description   = $info['description'] ?? $xOpenregister['description'] ?? "Imported from GitHub: {$owner}/{$repo}/{$path}";

            // Check if configuration already exists for this app.
            $existingConfigurations = $this->configurationMapper->findByApp($appId);
            if (count($existingConfigurations) > 0) {
                return new JSONResponse(
                    data: [
                        'error'                   => $this->getExistingConfigErrorMessage($appId),
                        'existingConfigurationId' => $existingConfigurations[0]->getId(),
                    ],
                    statusCode: 409
                );
            }

            // Step 2: Create Configuration entity.
            $configuration = new Configuration();
            $configuration->setTitle($title);
            $configuration->setDescription($description);
            $configuration->setType($xOpenregister['type'] ?? 'github');
            $configuration->setSourceType('github');
            $configuration->setSourceUrl("https://github.com/{$owner}/{$repo}/blob/{$branch}/{$path}");
            $configuration->setApp($appId);
            $configuration->setVersion($version);
            $configuration->setLocalVersion(null);
            // Will be set after import.
            $configuration->setIsLocal(false);
            $configuration->setGithubRepo("{$owner}/{$repo}");
            $configuration->setGithubBranch($branch);
            $configuration->setGithubPath($path);
            $configuration->setSyncEnabled($syncEnabled);
            $configuration->setSyncInterval($syncInterval);
            $configuration->setAutoUpdate(false);
            $configuration->setRegisters([]);
            $configuration->setSchemas([]);
            $configuration->setObjects([]);

            $configuration = $this->configurationMapper->insert($configuration);

            $this->logger->info("Created configuration entity with ID {$configuration->getId()} for app {$appId}");

            // Step 3: Import using the standard flow with the configuration entity.
            $result = $this->configurationService->importFromJson(
                data: $configData,
                configuration: $configuration,
                owner: $appId,
                appId: $appId,
                version: $version,
                force: false
            );

            // Step 4: Update configuration with sync status and imported entity IDs.
            $configuration->setLocalVersion($version);
            $configuration->setSyncStatus('success');
            $configuration->setLastSyncDate(new DateTime());

            // The importFromJson already updates the configuration with entity IDs via createOrUpdateConfiguration.
            // But we need to save the sync status.
            $this->configurationMapper->update($configuration);

            $this->logger->info("Successfully imported configuration {$configuration->getTitle()} from GitHub");

            return new JSONResponse(
                data: [
                    'success'         => true,
                    'message'         => 'Configuration imported successfully from GitHub',
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
            $this->logger->error('Failed to import from GitHub: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to import configuration: '.$e->getMessage()], statusCode: 500);
        }//end try

    }//end importFromGitHub()

    /**
     * Import configuration from GitLab
     *
     * This method creates a Configuration entity and then imports it using the standard import flow.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @since 0.2.10
     *
     * @psalm-return JSONResponse<int, array{error?: string, existingConfigurationId?: int, success?: true, message?: 'Configuration imported successfully from GitLab', configurationId?: int, result?: array{registersCount: int<0, max>, schemasCount: int<0, max>, objectsCount: int<0, max>}}, array<never, never>>
     */
    public function importFromGitLab(): JSONResponse
    {
        try {
            $data         = $this->request->getParams();
            $namespace    = $data['namespace'] ?? '';
            $project      = $data['project'] ?? '';
            $path         = $data['path'] ?? '';
            $ref          = $data['ref'] ?? 'main';
            $syncEnabled  = ($data['syncEnabled'] ?? true) === true;
            $syncInterval = (int) ($data['syncInterval'] ?? 24);

            if (empty($namespace) === true || empty($project) === true || empty($path) === true) {
                return new JSONResponse(
                    data: ['error' => 'Namespace, project, and path parameters are required'],
                    statusCode: 400
                );
            }

            // Get project ID from namespace/project path.
            $projectData = $this->gitlabHandler->getProjectByPath(namespace: $namespace, project: $project);
            $projectId   = $projectData['id'];

            $this->logger->info(
                    'Importing configuration from GitLab',
                    [
                        'namespace'  => $namespace,
                        'project'    => $project,
                        'project_id' => $projectId,
                        'path'       => $path,
                        'ref'        => $ref,
                    ]
                    );

            // Step 1: Get file content from GitLab.
            $configData = $this->gitlabHandler->getFileContent(projectId: $projectId, path: $path, ref: $ref);

            // Build GitLab URL for sourceUrl.
            $gitlabBase = $this->gitlabHandler->getApiBase();
            $webBase    = str_replace('/api/v4', '', $gitlabBase);
            $sourceUrl  = "{$webBase}/{$namespace}/{$project}/-/blob/{$ref}/{$path}";

            // Extract metadata from config.
            $info          = $configData['info'] ?? [];
            $xOpenregister = $configData['x-openregister'] ?? [];
            $appId         = $xOpenregister['app'] ?? 'imported';
            $version       = $info['version'] ?? $xOpenregister['version'] ?? '1.0.0';
            $title         = $info['title'] ?? $xOpenregister['title'] ?? "Configuration from {$namespace}/{$project}";
            $description   = $info['description'] ?? $xOpenregister['description'] ?? "Imported from GitLab: {$namespace}/{$project}/{$path}";

            // Check if configuration already exists for this app.
            $existingConfigurations = $this->configurationMapper->findByApp($appId);
            if (count($existingConfigurations) > 0) {
                return new JSONResponse(
                    data: [
                        'error'                   => $this->getExistingConfigErrorMessage($appId),
                        'existingConfigurationId' => $existingConfigurations[0]->getId(),
                    ],
                    statusCode: 409
                );
            }

            // Step 2: Create Configuration entity.
            $configuration = new Configuration();
            $configuration->setTitle($title);
            $configuration->setDescription($description);
            $configuration->setType($xOpenregister['type'] ?? 'gitlab');
            $configuration->setSourceType('gitlab');
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

            $configuration = $this->configurationMapper->insert($configuration);

            $this->logger->info("Created configuration entity with ID {$configuration->getId()} for app {$appId}");

            // Step 3: Import using the standard flow with the configuration entity.
            $result = $this->configurationService->importFromJson(
                data: $configData,
                configuration: $configuration,
                owner: $appId,
                appId: $appId,
                version: $version,
                force: false
            );

            // Step 4: Update configuration with sync status and imported entity IDs.
            $configuration->setLocalVersion($version);
            $configuration->setSyncStatus('success');
            $configuration->setLastSyncDate(new DateTime());

            // The importFromJson already updates the configuration with entity IDs via createOrUpdateConfiguration.
            // But we need to save the sync status.
            $this->configurationMapper->update($configuration);

            $this->logger->info("Successfully imported configuration {$configuration->getTitle()} from GitLab");

            return new JSONResponse(
                data: [
                    'success'         => true,
                    'message'         => 'Configuration imported successfully from GitLab',
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
            $this->logger->error('Failed to import from GitLab: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to import configuration: '.$e->getMessage()], statusCode: 500);
        }//end try

    }//end importFromGitLab()

    /**
     * Import configuration from URL
     *
     * This method creates a Configuration entity and then imports it using the standard import flow.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @since 0.2.10
     *
     * @psalm-return JSONResponse<int, array{error?: string, existingConfigurationId?: int, success?: true, message?: 'Configuration imported successfully from URL', configurationId?: int, result?: array{registersCount: int<0, max>, schemasCount: int<0, max>, objectsCount: int<0, max>}}, array<never, never>>
     */
    public function importFromUrl(): JSONResponse
    {
        try {
            $data         = $this->request->getParams();
            $url          = $data['url'] ?? '';
            $syncEnabled  = ($data['syncEnabled'] ?? true) === true;
            $syncInterval = (int) ($data['syncInterval'] ?? 24);

            if (empty($url) === true) {
                return new JSONResponse(data: ['error' => 'URL parameter is required'], statusCode: 400);
            }

            // Validate URL.
            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                return new JSONResponse(data: ['error' => 'Invalid URL provided'], statusCode: 400);
            }

            $this->logger->info(
                    'Importing configuration from URL',
                    [
                        'url' => $url,
                    ]
                    );

            // Step 1: Fetch content from URL.
            $client   = new Client();
            $response = $client->request('GET', $url);
            $content  = $response->getBody()->getContents();

            $configData = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON in URL response: '.json_last_error_msg());
            }

            // Extract metadata from config.
            $info          = $configData['info'] ?? [];
            $xOpenregister = $configData['x-openregister'] ?? [];
            $appId         = $xOpenregister['app'] ?? 'imported';
            $version       = $info['version'] ?? $xOpenregister['version'] ?? '1.0.0';
            $title         = $info['title'] ?? $xOpenregister['title'] ?? "Configuration from URL";
            $description   = $info['description'] ?? $xOpenregister['description'] ?? "Imported from URL: {$url}";

            // Check if configuration already exists for this app.
            $existingConfigurations = $this->configurationMapper->findByApp($appId);
            if (count($existingConfigurations) > 0) {
                return new JSONResponse(
                    data: [
                        'error'                   => $this->getExistingConfigErrorMessage($appId),
                        'existingConfigurationId' => $existingConfigurations[0]->getId(),
                    ],
                    statusCode: 409
                );
            }

            // Step 2: Create Configuration entity.
            $configuration = new Configuration();
            $configuration->setTitle($title);
            $configuration->setDescription($description);
            $configuration->setType($xOpenregister['type'] ?? 'url');
            $configuration->setSourceType('url');
            $configuration->setSourceUrl($url);
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

            $configuration = $this->configurationMapper->insert($configuration);

            $this->logger->info("Created configuration entity with ID {$configuration->getId()} for app {$appId}");

            // Step 3: Import using the standard flow with the configuration entity.
            $result = $this->configurationService->importFromJson(
                data: $configData,
                configuration: $configuration,
                owner: $appId,
                appId: $appId,
                version: $version,
                force: false
            );

            // Step 4: Update configuration with sync status and imported entity IDs.
            $configuration->setLocalVersion($version);
            $configuration->setSyncStatus('success');
            $configuration->setLastSyncDate(new DateTime());

            // The importFromJson already updates the configuration with entity IDs via createOrUpdateConfiguration.
            // But we need to save the sync status.
            $this->configurationMapper->update($configuration);

            $this->logger->info("Successfully imported configuration {$configuration->getTitle()} from URL");

            return new JSONResponse(
                data: [
                    'success'         => true,
                    'message'         => 'Configuration imported successfully from URL',
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
            $this->logger->error('Failed to import from URL: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to import configuration: '.$e->getMessage()], statusCode: 500);
        }//end try

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
     * @psalm-return JSONResponse<200|400|500, array{error?: string, success?: true, message?: string, configurationId?: int, commit_sha?: mixed|null, commit_url?: mixed|null, file_url?: mixed|null, branch?: string, default_branch?: 'main'|mixed|null, indexing_note?: string}, array<never, never>>
     */
    public function publishToGitHub(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);

            // Only allow publishing local configurations.
            if ($configuration->getIsLocal() !== true) {
                return new JSONResponse(data: ['error' => 'Only local configurations can be published'], statusCode: 400);
            }

            $data          = $this->request->getParams();
            $owner         = $data['owner'] ?? '';
            $repo          = $data['repo'] ?? '';
            $path          = $data['path'] ?? '';
            $branch        = $data['branch'] ?? 'main';
            $commitMessage = $data['commitMessage'] ?? "Update configuration: {$configuration->getTitle()}";

            if (empty($owner) === true || empty($repo) === true) {
                return new JSONResponse(data: ['error' => 'Owner and repo parameters are required'], statusCode: 400);
            }

            // Strip leading slash from path (GitHub API doesn't allow paths starting with /).
            // Allow / for root, which becomes empty string.
            $path = ltrim($path, '/');

            // If path is empty after stripping (user entered just "/"), use a default filename.
            // Generate filename from configuration title in snake_case format.
            if (empty($path) === true) {
                $title          = $configuration->getTitle();
                $snakeCaseTitle = $this->toSnakeCase($title ?? 'configuration');
                $path           = $snakeCaseTitle.'_openregister.json';
            }

            $this->logger->info(
                    'Publishing configuration to GitHub',
                    [
                        'configuration_id' => $id,
                        'owner'            => $owner,
                        'repo'             => $repo,
                        'path'             => $path,
                        'branch'           => $branch,
                    ]
                    );

            // Export configuration to JSON.
            $configData = $this->configurationService->exportConfig(input: $configuration, includeObjects: false);

            // Update x-openregister section with GitHub publishing information.
            // When publishing online, we don't set sourceType or sourceUrl.
            // Instead, we set the openregister version and GitHub info.
            $githubRepo = "{$owner}/{$repo}";

            if (isset($configData['x-openregister']) === false) {
                $configData['x-openregister'] = [];
            }

            // Get current OpenRegister app version.
            $openregisterVersion = $this->appManager->getAppVersion('openregister');

            // Remove sourceType and sourceUrl (not set when publishing online).
            unset($configData['x-openregister']['sourceType']);
            unset($configData['x-openregister']['sourceUrl']);

            // Set openregister version and GitHub info.
            $configData['x-openregister']['openregister'] = $openregisterVersion;
            $configData['x-openregister']['github']       = [
                'repo'   => $githubRepo,
                'branch' => $branch,
                'path'   => $path,
            ];

            $jsonContent = json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // Check if file already exists (for updates).
            $fileSha = null;
            try {
                $fileSha = $this->githubHandler->getFileSha(owner: $owner, repo: $repo, path: $path, branch: $branch);
            } catch (Exception $e) {
                // File doesn't exist, which is fine for new files.
                $this->logger->debug('File does not exist, will create new file', ['path' => $path]);
            }

            // Publish to GitHub.
            $result = $this->githubHandler->publishConfiguration(
                owner: $owner,
                repo: $repo,
                path: $path,
                branch: $branch,
                content: $jsonContent,
                commitMessage: $commitMessage,
                fileSha: $fileSha
            );

            // Update configuration with GitHub source information.
            // Keep it as local but add GitHub publishing info.
            $configuration->setGithubRepo("{$owner}/{$repo}");
            $configuration->setGithubBranch($branch);
            $configuration->setGithubPath($path);
            $configuration->setSourceUrl("https://github.com/{$owner}/{$repo}/blob/{$branch}/{$path}");
            // Don't change isLocal - it stays local, but now has a published source.
            $this->configurationMapper->update($configuration);

            $this->logger->info(
                    "Successfully published configuration {$configuration->getTitle()} to GitHub",
                    [
                        'owner'    => $owner,
                        'repo'     => $repo,
                        'branch'   => $branch,
                        'path'     => $path,
                        'file_url' => $result['file_url'] ?? null,
                    ]
                    );

            // Check if published to default branch (required for Code Search indexing).
            $defaultBranch = null;
            try {
                $repoInfo      = $this->githubHandler->getRepositoryInfo(owner: $owner, repo: $repo);
                $defaultBranch = $repoInfo['default_branch'] ?? 'main';
            } catch (Exception $e) {
                $this->logger->warning(
                        'Could not fetch repository default branch',
                        [
                            'owner' => $owner,
                            'repo'  => $repo,
                            'error' => $e->getMessage(),
                        ]
                        );
            }

            $message = 'Configuration published successfully to GitHub';
            if ($defaultBranch !== null && $branch !== $defaultBranch) {
                $message .= ". Note: Published to branch '{$branch}' (default is '{$defaultBranch}'). ";
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
                    'branch'          => $branch,
                    'default_branch'  => $defaultBranch,
                    'indexing_note'   => $this->getIndexingNote(defaultBranch: $defaultBranch, branch: $branch),
                ],
                statusCode: 200
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to publish to GitHub: '.$e->getMessage());

            return new JSONResponse(data: ['error' => 'Failed to publish configuration: '.$e->getMessage()], statusCode: 500);
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
