<?php
/**
 * OpenRegister Configuration Sync Job
 *
 * This file contains the background job class for synchronizing external configurations
 * from their source repositories (GitHub, GitLab, URL).
 *
 * @category Cron
 * @package  OCA\OpenRegister\Cron
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Cron;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\GitHubService;
use OCA\OpenRegister\Service\GitLabService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Class SyncConfigurationsJob
 *
 * Background job for synchronizing external configurations with their sources.
 * Runs periodically to check and sync configurations that have sync enabled.
 *
 * @package OCA\OpenRegister\Cron
 */
class SyncConfigurationsJob extends TimedJob
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
     * HTTP client instance.
     *
     * @var Client The HTTP client instance.
     */
    private Client $httpClient;

    /**
     * Logger instance.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param ITimeFactory         $time                 Time factory for job scheduling
     * @param ConfigurationMapper  $configurationMapper  Configuration mapper
     * @param ConfigurationService $configurationService Configuration service
     * @param GitHubService        $githubService        GitHub service
     * @param GitLabService        $gitlabService        GitLab service
     * @param Client               $httpClient           HTTP client
     * @param LoggerInterface      $logger               Logger
     */
    public function __construct(
        ITimeFactory $time,
        ConfigurationMapper $configurationMapper,
        ConfigurationService $configurationService,
        GitHubService $githubService,
        GitLabService $gitlabService,
        Client $httpClient,
        LoggerInterface $logger
    ) {
        parent::__construct($time);

        $this->configurationMapper  = $configurationMapper;
        $this->configurationService = $configurationService;
        $this->githubService        = $githubService;
        $this->gitlabService        = $gitlabService;
        $this->httpClient           = $httpClient;
        $this->logger = $logger;

        // Run every hour (3600 seconds).
        $this->setInterval(3600);

    }//end __construct()


    /**
     * Run the background job
     *
     * Synchronizes all external configurations that have sync enabled and are due for sync.
     *
     * @param array $argument Job arguments (not used)
     *
     * @return void
     */
    protected function run($argument): void
    {
        $this->logger->info('Starting configuration sync job');

        try {
            // Get all configurations with sync enabled.
            $configurations = $this->configurationMapper->findBySyncEnabled();
            $this->logger->info('Found '.count($configurations).' configurations with sync enabled');

            $synced  = 0;
            $skipped = 0;
            $failed  = 0;

            foreach ($configurations as $configuration) {
                try {
                    // Check if this configuration is due for sync.
                    if ($this->isDueForSync($configuration) === false) {
                        $skipped++;
                        continue;
                    }

                    $this->logger->info("Syncing configuration: {$configuration->getTitle()} (ID: {$configuration->getId()})");

                    // Sync the configuration based on source type.
                    $this->syncConfiguration($configuration);

                    $synced++;
                    $this->logger->info("Successfully synced configuration {$configuration->getTitle()}");
                } catch (Exception $e) {
                    $failed++;
                    $this->logger->error("Error syncing configuration {$configuration->getId()}: ".$e->getMessage());

                    // Update sync status to failed.
                    try {
                        $this->configurationMapper->updateSyncStatus(
                            id: $configuration->getId(),
                            status: 'failed',
                            syncedAt: new DateTime(),
                            errorMessage: $e->getMessage()
                        );
                    } catch (Exception $statusError) {
                        $this->logger->error("Failed to update sync status: ".$statusError->getMessage());
                    }

                    continue;
                }//end try
            }//end foreach

            $this->logger->info(
                "Configuration sync job completed: {$synced} synced, {$skipped} skipped, {$failed} failed"
            );
        } catch (Exception $e) {
            $this->logger->error('Configuration sync job failed: '.$e->getMessage());
        }//end try

    }//end run()


    /**
     * Check if a configuration is due for synchronization
     *
     * @param Configuration $configuration Configuration to check
     *
     * @return bool True if sync is due
     */
    private function isDueForSync(Configuration $configuration): bool
    {
        // If never synced, it's due.
        if ($configuration->getLastSyncDate() === null) {
            return true;
        }

        // Calculate time since last sync.
        $now      = new DateTime();
        $lastSync = $configuration->getLastSyncDate();
        $interval = $configuration->getSyncInterval();
        // In hours.
        $diff        = $now->getTimestamp() - $lastSync->getTimestamp();
        $hoursPassed = $diff / 3600;

        return $hoursPassed >= $interval;

    }//end isDueForSync()


    /**
     * Synchronize a configuration from its source
     *
     * @param Configuration $configuration Configuration to sync
     *
     * @return void
     * @throws Exception If sync fails
     */
    private function syncConfiguration(Configuration $configuration): void
    {
        $sourceType = $configuration->getSourceType();

        switch ($sourceType) {
            case 'github':
                $this->syncFromGitHub($configuration);
                break;

            case 'gitlab':
                $this->syncFromGitLab($configuration);
                break;

            case 'url':
                $this->syncFromUrl($configuration);
                break;

            case 'local':
                $this->syncFromLocal($configuration);
                break;

            default:
                throw new Exception("Unsupported source type: {$sourceType}");
        }

    }//end syncConfiguration()


    /**
     * Sync configuration from GitHub
     *
     * @param Configuration $configuration Configuration to sync
     *
     * @return void
     * @throws Exception If sync fails
     */
    private function syncFromGitHub(Configuration $configuration): void
    {
        $githubRepo = $configuration->getGithubRepo();
        // Format: owner/repo.
        $githubBranch = $configuration->getGithubBranch() ?? 'main';
        $githubPath   = $configuration->getGithubPath();

        if (empty($githubRepo) === true || empty($githubPath) === true) {
            throw new Exception('GitHub repository and path are required');
        }

        // Split owner/repo.
        list($owner, $repo) = explode('/', $githubRepo);

        // Fetch file content.
        $configData = $this->githubService->getFileContent(owner: $owner, repo: $repo, path: $githubPath, branch: $githubBranch);

        // Get app ID and version.
        $appId   = $configData['x-openregister']['app'] ?? $configuration->getApp();
        $version = $configData['info']['version'] ?? $configData['x-openregister']['version'] ?? '1.0.0';

        // Import the configuration (force update).
        $this->configurationService->importFromApp(
            appId: $appId,
            data: $configData,
            version: $version,
            force: true
        );

        // Update sync status.
        $this->configurationMapper->updateSyncStatus(
            id: $configuration->getId(),
            status: 'success',
            syncedAt: new DateTime()
        );

    }//end syncFromGitHub()


    /**
     * Sync configuration from GitLab
     *
     * @param Configuration $configuration Configuration to sync
     *
     * @return void
     * @throws Exception If sync fails
     */
    private function syncFromGitLab(Configuration $configuration): void
    {
        $sourceUrl = $configuration->getSourceUrl();

        if (empty($sourceUrl) === true) {
            throw new Exception('Source URL is required for GitLab sync');
        }

        // Parse GitLab URL to extract namespace, project, ref, and path.
        // Format: https://gitlab.com/namespace/project/-/blob/branch/path/to/file.json.
        if (preg_match('#gitlab\.com/([^/]+)/([^/]+)/-/blob/([^/]+)/(.+)$#', $sourceUrl, $matches) === 1) {
            $namespace = $matches[1];
            $project   = $matches[2];
            $ref       = $matches[3];
            $path      = $matches[4];
        } else {
            throw new Exception('Invalid GitLab URL format');
        }

        // Get project info.
        $projectData = $this->gitlabService->getProjectByPath(namespace: $namespace, project: $project);
        $projectId   = $projectData['id'];

        // Fetch file content.
        $configData = $this->gitlabService->getFileContent(projectId: $projectId, path: $path, ref: $ref);

        // Get app ID and version.
        $appId   = $configData['x-openregister']['app'] ?? $configuration->getApp();
        $version = $configData['info']['version'] ?? $configData['x-openregister']['version'] ?? '1.0.0';

        // Import the configuration (force update).
        $this->configurationService->importFromApp(
            appId: $appId,
            data: $configData,
            version: $version,
            force: true
        );

        // Update sync status.
        $this->configurationMapper->updateSyncStatus(
            id: $configuration->getId(),
            status: 'success',
            syncedAt: new DateTime()
        );

    }//end syncFromGitLab()


    /**
     * Sync configuration from URL
     *
     * @param Configuration $configuration Configuration to sync
     *
     * @return void
     * @throws Exception If sync fails
     */
    private function syncFromUrl(Configuration $configuration): void
    {
        $sourceUrl = $configuration->getSourceUrl();

        if (empty($sourceUrl) === true) {
            throw new Exception('Source URL is required');
        }

        // Fetch content from URL.
        $response = $this->httpClient->request('GET', $sourceUrl);
        $content  = $response->getBody()->getContents();

        $configData = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in URL response: '.json_last_error_msg());
        }

        // Get app ID and version.
        $appId   = $configData['x-openregister']['app'] ?? $configuration->getApp();
        $version = $configData['info']['version'] ?? $configData['x-openregister']['version'] ?? '1.0.0';

        // Import the configuration (force update).
        $this->configurationService->importFromApp(
            appId: $appId,
            data: $configData,
            version: $version,
            force: true
        );

        // Update sync status.
        $this->configurationMapper->updateSyncStatus(
            id: $configuration->getId(),
            status: 'success',
            syncedAt: new DateTime()
        );

    }//end syncFromUrl()


    /**
     * Sync configuration from local file
     *
     * @param Configuration $configuration Configuration to sync
     *
     * @return void
     * @throws Exception If sync fails
     */
    private function syncFromLocal(Configuration $configuration): void
    {
        $sourceUrl = $configuration->getSourceUrl();

        if (empty($sourceUrl) === true) {
            throw new Exception('Source URL (file path) is required for local sync');
        }

        // Get app ID and version.
        $appId   = $configuration->getApp();
        $version = $configuration->getVersion();

        // Use importFromFilePath to reload from file.
        $this->configurationService->importFromFilePath(
            appId: $appId,
            filePath: $sourceUrl,
            version: $version,
            force: true
        );

        // Update sync status.
        $this->configurationMapper->updateSyncStatus(
            id: $configuration->getId(),
            status: 'success',
            syncedAt: new DateTime()
        );

    }//end syncFromLocal()


}//end class
