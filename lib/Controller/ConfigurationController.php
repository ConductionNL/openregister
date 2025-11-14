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
use OCA\OpenRegister\Service\GitHubService;
use OCA\OpenRegister\Service\GitLabService;
use OCA\OpenRegister\Service\NotificationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Class ConfigurationController
 *
 * Controller for managing configurations (CRUD and management operations).
 *
 * @package OCA\OpenRegister\Controller
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
     * GitHub service instance.
     *
     * @var GitHubService The GitHub service instance.
     */
    private GitHubService $githubService;

    /**
     * GitLab service instance.
     *
     * @var GitLabService The GitLab service instance.
     */
    private GitLabService $gitlabService;

    /**
     * Logger instance.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param string               $appName              The app name
     * @param IRequest             $request              The request object
     * @param ConfigurationMapper  $configurationMapper  Configuration mapper
     * @param ConfigurationService $configurationService Configuration service
     * @param NotificationService  $notificationService  Notification service
     * @param GitHubService        $githubService        GitHub service
     * @param GitLabService        $gitlabService        GitLab service
     * @param LoggerInterface      $logger               Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ConfigurationMapper $configurationMapper,
        ConfigurationService $configurationService,
        NotificationService $notificationService,
        GitHubService $githubService,
        GitLabService $gitlabService,
        LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
        
        $this->configurationMapper  = $configurationMapper;
        $this->configurationService = $configurationService;
        $this->notificationService  = $notificationService;
        $this->githubService        = $githubService;
        $this->gitlabService        = $gitlabService;
        $this->logger               = $logger;

    }//end __construct()


    /**
     * Get all configurations.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of configurations
     */
    public function index(): JSONResponse
    {
        try {
            $configurations = $this->configurationMapper->findAll();
            
            return new JSONResponse($configurations, 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch configurations: '.$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to fetch configurations'],
                500
            );
        }//end try

    }//end index()


    /**
     * Get a single configuration by ID.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse The configuration details
     */
    public function show(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            
            return new JSONResponse($configuration, 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to fetch configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to fetch configuration'],
                500
            );
        }//end try

    }//end show()


    /**
     * Create a new configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The created configuration
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
            $configuration->setVersion($data['version'] ?? '1.0.0');
            $configuration->setLocalVersion($data['localVersion'] ?? null);
            $configuration->setRegisters($data['registers'] ?? []);
            $configuration->setSchemas($data['schemas'] ?? []);
            $configuration->setObjects($data['objects'] ?? []);
            $configuration->setAutoUpdate($data['autoUpdate'] ?? false);
            $configuration->setNotificationGroups($data['notificationGroups'] ?? []);
            $configuration->setGithubRepo($data['githubRepo'] ?? null);
            $configuration->setGithubBranch($data['githubBranch'] ?? null);
            $configuration->setGithubPath($data['githubPath'] ?? null);
            
            $created = $this->configurationMapper->insert($configuration);
            
            $this->logger->info("Created configuration: {$created->getTitle()} (ID: {$created->getId()})");
            
            return new JSONResponse($created, 201);
        } catch (Exception $e) {
            $this->logger->error('Failed to create configuration: '.$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to create configuration: '.$e->getMessage()],
                500
            );
        }//end try

    }//end create()


    /**
     * Update an existing configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse The updated configuration
     */
    public function update(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $data          = $this->request->getParams();
            
            // Update fields if provided
            if (isset($data['title']) === true) {
                $configuration->setTitle($data['title']);
            }

            if (isset($data['description']) === true) {
                $configuration->setDescription($data['description']);
            }

            if (isset($data['type']) === true) {
                $configuration->setType($data['type']);
            }

            if (isset($data['sourceType']) === true) {
                $configuration->setSourceType($data['sourceType']);
            }

            if (isset($data['sourceUrl']) === true) {
                $configuration->setSourceUrl($data['sourceUrl']);
            }

            if (isset($data['app']) === true) {
                $configuration->setApp($data['app']);
            }

            if (isset($data['version']) === true) {
                $configuration->setVersion($data['version']);
            }

            if (isset($data['localVersion']) === true) {
                $configuration->setLocalVersion($data['localVersion']);
            }

            if (isset($data['registers']) === true) {
                $configuration->setRegisters($data['registers']);
            }

            if (isset($data['schemas']) === true) {
                $configuration->setSchemas($data['schemas']);
            }

            if (isset($data['objects']) === true) {
                $configuration->setObjects($data['objects']);
            }

            if (isset($data['autoUpdate']) === true) {
                $configuration->setAutoUpdate($data['autoUpdate']);
            }

            if (isset($data['notificationGroups']) === true) {
                $configuration->setNotificationGroups($data['notificationGroups']);
            }

            if (isset($data['githubRepo']) === true) {
                $configuration->setGithubRepo($data['githubRepo']);
            }

            if (isset($data['githubBranch']) === true) {
                $configuration->setGithubBranch($data['githubBranch']);
            }

            if (isset($data['githubPath']) === true) {
                $configuration->setGithubPath($data['githubPath']);
            }

            $updated = $this->configurationMapper->update($configuration);
            
            $this->logger->info("Updated configuration: {$updated->getTitle()} (ID: {$updated->getId()})");
            
            return new JSONResponse($updated, 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to update configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to update configuration: '.$e->getMessage()],
                500
            );
        }//end try

    }//end update()


    /**
     * Delete a configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse Success response
     */
    public function destroy(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $this->configurationMapper->delete($configuration);
            
            $this->logger->info("Deleted configuration: {$configuration->getTitle()} (ID: {$id})");
            
            return new JSONResponse(['success' => true], 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to delete configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to delete configuration'],
                500
            );
        }//end try

    }//end destroy()


    /**
     * Check remote version of a configuration.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse Version information
     */
    public function checkVersion(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            
            // Check remote version
            $remoteVersion = $this->configurationService->checkRemoteVersion($configuration);
            
            if ($remoteVersion === null) {
                return new JSONResponse(
                    ['error' => 'Could not check remote version'],
                    500
                );
            }

            // Get version comparison
            $comparison = $this->configurationService->compareVersions($configuration);
            
            return new JSONResponse($comparison, 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (GuzzleException $e) {
            $this->logger->error("Failed to check version for configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to fetch remote version: '.$e->getMessage()],
                500
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to check version for configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to check version'],
                500
            );
        }//end try

    }//end checkVersion()


    /**
     * Preview configuration changes.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse Preview of changes
     */
    public function preview(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            
            $preview = $this->configurationService->previewConfigurationChanges($configuration);
            
            if ($preview instanceof JSONResponse) {
                return $preview;
            }

            return new JSONResponse($preview, 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to preview configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to preview configuration changes'],
                500
            );
        }//end try

    }//end preview()


    /**
     * Import configuration with user selection.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse Import results
     */
    public function import(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $data          = $this->request->getParams();
            $selection     = $data['selection'] ?? [];
            
            $result = $this->configurationService->importConfigurationWithSelection(
                $configuration,
                $selection
            );
            
            // Mark notifications as processed
            $this->notificationService->markConfigurationUpdated($configuration);
            
            $this->logger->info("Imported configuration {$configuration->getTitle()}: ".json_encode([
                'registers' => count($result['registers']),
                'schemas'   => count($result['schemas']),
                'objects'   => count($result['objects']),
            ]));
            
            return new JSONResponse([
                'success'         => true,
                'registersCount'  => count($result['registers']),
                'schemasCount'    => count($result['schemas']),
                'objectsCount'    => count($result['objects']),
            ], 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to import configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to import configuration: '.$e->getMessage()],
                500
            );
        }//end try

    }//end import()


    /**
     * Export configuration to download or GitHub.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id The configuration ID
     *
     * @return JSONResponse Export result with download URL or success message
     */
    public function export(int $id): JSONResponse
    {
        try {
            $configuration = $this->configurationMapper->find($id);
            $data          = $this->request->getParams();
            $format        = $data['format'] ?? 'json';
            $includeObjects = ($data['includeObjects'] ?? false) === true;
            
            // Export the configuration
            $exportData = $this->configurationService->exportConfig(
                $configuration,
                $includeObjects
            );
            
            // Return the export data directly for download
            return new JSONResponse($exportData, 200);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => 'Configuration not found'],
                404
            );
        } catch (Exception $e) {
            $this->logger->error("Failed to export configuration {$id}: ".$e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to export configuration: '.$e->getMessage()],
                500
            );
        }//end try

    }//end export()


    /**
     * Discover OpenRegister configurations on GitHub
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Search results from GitHub
     *
     * @since 0.2.10
     */
    public function discoverGitHub(): JSONResponse
    {
        try {
            $data  = $this->request->getParams();
            $query = $data['query'] ?? '';
            $page  = (int) ($data['page'] ?? 1);
            
            $this->logger->info('Discovering configurations on GitHub', [
                'query' => $query,
                'page'  => $page,
            ]);
            
            $results = $this->githubService->searchConfigurations($query, $page);
            
            return new JSONResponse($results, 200);
        } catch (Exception $e) {
            $this->logger->error('GitHub discovery failed: ' . $e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to discover configurations: ' . $e->getMessage()],
                500
            );
        }
    }//end discoverGitHub()


    /**
     * Discover OpenRegister configurations on GitLab
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Search results from GitLab
     *
     * @since 0.2.10
     */
    public function discoverGitLab(): JSONResponse
    {
        try {
            $data  = $this->request->getParams();
            $query = $data['query'] ?? '';
            $page  = (int) ($data['page'] ?? 1);
            
            $this->logger->info('Discovering configurations on GitLab', [
                'query' => $query,
                'page'  => $page,
            ]);
            
            $results = $this->gitlabService->searchConfigurations($query, $page);
            
            return new JSONResponse($results, 200);
        } catch (Exception $e) {
            $this->logger->error('GitLab discovery failed: ' . $e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to discover configurations: ' . $e->getMessage()],
                500
            );
        }
    }//end discoverGitLab()


    /**
     * Get branches from a GitHub repository
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of branches
     *
     * @since 0.2.10
     */
    public function getGitHubBranches(): JSONResponse
    {
        try {
            $data  = $this->request->getParams();
            $owner = $data['owner'] ?? '';
            $repo  = $data['repo'] ?? '';
            
            if (empty($owner) || empty($repo)) {
                return new JSONResponse(
                    ['error' => 'Owner and repo parameters are required'],
                    400
                );
            }
            
            $this->logger->info('Fetching GitHub branches', [
                'owner' => $owner,
                'repo'  => $repo,
            ]);
            
            $branches = $this->githubService->getBranches($owner, $repo);
            
            return new JSONResponse(['branches' => $branches], 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to get GitHub branches: ' . $e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to fetch branches: ' . $e->getMessage()],
                500
            );
        }
    }//end getGitHubBranches()


    /**
     * Get configuration files from a GitHub repository
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of configuration files
     *
     * @since 0.2.10
     */
    public function getGitHubConfigurations(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $owner  = $data['owner'] ?? '';
            $repo   = $data['repo'] ?? '';
            $branch = $data['branch'] ?? 'main';
            
            if (empty($owner) || empty($repo)) {
                return new JSONResponse(
                    ['error' => 'Owner and repo parameters are required'],
                    400
                );
            }
            
            $this->logger->info('Fetching GitHub configurations', [
                'owner'  => $owner,
                'repo'   => $repo,
                'branch' => $branch,
            ]);
            
            $files = $this->githubService->listConfigurationFiles($owner, $repo, $branch);
            
            return new JSONResponse(['files' => $files], 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to get GitHub configurations: ' . $e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to fetch configurations: ' . $e->getMessage()],
                500
            );
        }
    }//end getGitHubConfigurations()


    /**
     * Get branches from a GitLab project
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of branches
     *
     * @since 0.2.10
     */
    public function getGitLabBranches(): JSONResponse
    {
        try {
            $data      = $this->request->getParams();
            $namespace = $data['namespace'] ?? '';
            $project   = $data['project'] ?? '';
            
            if (empty($namespace) || empty($project)) {
                return new JSONResponse(
                    ['error' => 'Namespace and project parameters are required'],
                    400
                );
            }
            
            // Get project ID from namespace/project path
            $projectData = $this->gitlabService->getProjectByPath($namespace, $project);
            $projectId   = $projectData['id'];
            
            $this->logger->info('Fetching GitLab branches', [
                'namespace'  => $namespace,
                'project'    => $project,
                'project_id' => $projectId,
            ]);
            
            $branches = $this->gitlabService->getBranches($projectId);
            
            return new JSONResponse(['branches' => $branches], 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to get GitLab branches: ' . $e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to fetch branches: ' . $e->getMessage()],
                500
            );
        }
    }//end getGitLabBranches()


    /**
     * Get configuration files from a GitLab project
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse List of configuration files
     *
     * @since 0.2.10
     */
    public function getGitLabConfigurations(): JSONResponse
    {
        try {
            $data      = $this->request->getParams();
            $namespace = $data['namespace'] ?? '';
            $project   = $data['project'] ?? '';
            $ref       = $data['ref'] ?? 'main';
            
            if (empty($namespace) || empty($project)) {
                return new JSONResponse(
                    ['error' => 'Namespace and project parameters are required'],
                    400
                );
            }
            
            // Get project ID from namespace/project path
            $projectData = $this->gitlabService->getProjectByPath($namespace, $project);
            $projectId   = $projectData['id'];
            
            $this->logger->info('Fetching GitLab configurations', [
                'namespace'  => $namespace,
                'project'    => $project,
                'project_id' => $projectId,
                'ref'        => $ref,
            ]);
            
            $files = $this->gitlabService->listConfigurationFiles($projectId, $ref);
            
            return new JSONResponse(['files' => $files], 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to get GitLab configurations: ' . $e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to fetch configurations: ' . $e->getMessage()],
                500
            );
        }
    }//end getGitLabConfigurations()


    /**
     * Import configuration from GitHub
     *
     * @NoAdminRequired
     *
     * @return JSONResponse Import result
     *
     * @since 0.2.10
     */
    public function importFromGitHub(): JSONResponse
    {
        try {
            $data       = $this->request->getParams();
            $owner      = $data['owner'] ?? '';
            $repo       = $data['repo'] ?? '';
            $path       = $data['path'] ?? '';
            $branch     = $data['branch'] ?? 'main';
            $syncEnabled = ($data['syncEnabled'] ?? true) === true;
            $syncInterval = (int) ($data['syncInterval'] ?? 24);
            
            if (empty($owner) || empty($repo) || empty($path)) {
                return new JSONResponse(
                    ['error' => 'Owner, repo, and path parameters are required'],
                    400
                );
            }
            
            $this->logger->info('Importing configuration from GitHub', [
                'owner'  => $owner,
                'repo'   => $repo,
                'path'   => $path,
                'branch' => $branch,
            ]);
            
            // Get file content from GitHub
            $configData = $this->githubService->getFileContent($owner, $repo, $path, $branch);
            
            // Build GitHub URL for sourceUrl
            $sourceUrl = "https://github.com/{$owner}/{$repo}/blob/{$branch}/{$path}";
            
            // Set x-openregister properties for external configuration
            if (!isset($configData['x-openregister'])) {
                $configData['x-openregister'] = [];
            }
            $configData['x-openregister']['sourceType'] = 'github';
            $configData['x-openregister']['sourceUrl']  = $sourceUrl;
            $configData['x-openregister']['github']     = [
                'repo'   => "{$owner}/{$repo}",
                'branch' => $branch,
                'path'   => $path,
            ];
            
            // Get app ID and version from config
            $appId   = $configData['x-openregister']['app'] ?? 'imported';
            $version = $configData['info']['version'] ?? $configData['x-openregister']['version'] ?? '1.0.0';
            
            // Import the configuration
            $result = $this->configurationService->importFromApp(
                appId: $appId,
                data: $configData,
                version: $version,
                force: false
            );
            
            // Update the configuration to mark it as external and configure sync
            if (isset($result['configuration'])) {
                $configuration = $result['configuration'];
                $configuration->setIsLocal(false);
                $configuration->setSyncEnabled($syncEnabled);
                $configuration->setSyncInterval($syncInterval);
                $configuration->setSyncStatus('success');
                $configuration->setLastSyncDate(new \DateTime());
                $this->configurationMapper->update($configuration);
            }
            
            return new JSONResponse([
                'success' => true,
                'message' => 'Configuration imported successfully from GitHub',
                'result'  => $result,
            ], 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to import from GitHub: ' . $e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to import configuration: ' . $e->getMessage()],
                500
            );
        }
    }//end importFromGitHub()


    /**
     * Import configuration from GitLab
     *
     * @NoAdminRequired
     *
     * @return JSONResponse Import result
     *
     * @since 0.2.10
     */
    public function importFromGitLab(): JSONResponse
    {
        try {
            $data       = $this->request->getParams();
            $namespace  = $data['namespace'] ?? '';
            $project    = $data['project'] ?? '';
            $path       = $data['path'] ?? '';
            $ref        = $data['ref'] ?? 'main';
            $syncEnabled = ($data['syncEnabled'] ?? true) === true;
            $syncInterval = (int) ($data['syncInterval'] ?? 24);
            
            if (empty($namespace) || empty($project) || empty($path)) {
                return new JSONResponse(
                    ['error' => 'Namespace, project, and path parameters are required'],
                    400
                );
            }
            
            // Get project ID from namespace/project path
            $projectData = $this->gitlabService->getProjectByPath($namespace, $project);
            $projectId   = $projectData['id'];
            
            $this->logger->info('Importing configuration from GitLab', [
                'namespace'  => $namespace,
                'project'    => $project,
                'project_id' => $projectId,
                'path'       => $path,
                'ref'        => $ref,
            ]);
            
            // Get file content from GitLab
            $configData = $this->gitlabService->getFileContent($projectId, $path, $ref);
            
            // Build GitLab URL for sourceUrl
            $gitlabBase = $this->gitlabService->getApiBase();
            $webBase    = str_replace('/api/v4', '', $gitlabBase);
            $sourceUrl  = "{$webBase}/{$namespace}/{$project}/-/blob/{$ref}/{$path}";
            
            // Set x-openregister properties for external configuration
            if (!isset($configData['x-openregister'])) {
                $configData['x-openregister'] = [];
            }
            $configData['x-openregister']['sourceType'] = 'gitlab';
            $configData['x-openregister']['sourceUrl']  = $sourceUrl;
            
            // Get app ID and version from config
            $appId   = $configData['x-openregister']['app'] ?? 'imported';
            $version = $configData['info']['version'] ?? $configData['x-openregister']['version'] ?? '1.0.0';
            
            // Import the configuration
            $result = $this->configurationService->importFromApp(
                appId: $appId,
                data: $configData,
                version: $version,
                force: false
            );
            
            // Update the configuration to mark it as external and configure sync
            if (isset($result['configuration'])) {
                $configuration = $result['configuration'];
                $configuration->setIsLocal(false);
                $configuration->setSyncEnabled($syncEnabled);
                $configuration->setSyncInterval($syncInterval);
                $configuration->setSyncStatus('success');
                $configuration->setLastSyncDate(new \DateTime());
                $this->configurationMapper->update($configuration);
            }
            
            return new JSONResponse([
                'success' => true,
                'message' => 'Configuration imported successfully from GitLab',
                'result'  => $result,
            ], 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to import from GitLab: ' . $e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to import configuration: ' . $e->getMessage()],
                500
            );
        }
    }//end importFromGitLab()


    /**
     * Import configuration from URL
     *
     * @NoAdminRequired
     *
     * @return JSONResponse Import result
     *
     * @since 0.2.10
     */
    public function importFromUrl(): JSONResponse
    {
        try {
            $data       = $this->request->getParams();
            $url        = $data['url'] ?? '';
            $syncEnabled = ($data['syncEnabled'] ?? true) === true;
            $syncInterval = (int) ($data['syncInterval'] ?? 24);
            
            if (empty($url)) {
                return new JSONResponse(
                    ['error' => 'URL parameter is required'],
                    400
                );
            }
            
            // Validate URL
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                return new JSONResponse(
                    ['error' => 'Invalid URL provided'],
                    400
                );
            }
            
            $this->logger->info('Importing configuration from URL', [
                'url' => $url,
            ]);
            
            // Fetch content from URL
            $client  = new \GuzzleHttp\Client();
            $response = $client->request('GET', $url);
            $content = $response->getBody()->getContents();
            
            $configData = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON in URL response: ' . json_last_error_msg());
            }
            
            // Set x-openregister properties for external configuration
            if (!isset($configData['x-openregister'])) {
                $configData['x-openregister'] = [];
            }
            $configData['x-openregister']['sourceType'] = 'url';
            $configData['x-openregister']['sourceUrl']  = $url;
            
            // Get app ID and version from config
            $appId   = $configData['x-openregister']['app'] ?? 'imported';
            $version = $configData['info']['version'] ?? $configData['x-openregister']['version'] ?? '1.0.0';
            
            // Import the configuration
            $result = $this->configurationService->importFromApp(
                appId: $appId,
                data: $configData,
                version: $version,
                force: false
            );
            
            // Update the configuration to mark it as external and configure sync
            if (isset($result['configuration'])) {
                $configuration = $result['configuration'];
                $configuration->setIsLocal(false);
                $configuration->setSyncEnabled($syncEnabled);
                $configuration->setSyncInterval($syncInterval);
                $configuration->setSyncStatus('success');
                $configuration->setLastSyncDate(new \DateTime());
                $this->configurationMapper->update($configuration);
            }
            
            return new JSONResponse([
                'success' => true,
                'message' => 'Configuration imported successfully from URL',
                'result'  => $result,
            ], 200);
        } catch (Exception $e) {
            $this->logger->error('Failed to import from URL: ' . $e->getMessage());
            
            return new JSONResponse(
                ['error' => 'Failed to import configuration: ' . $e->getMessage()],
                500
            );
        }
    }//end importFromUrl()


}//end class


