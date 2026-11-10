<?php

/**
 * OpenRegister Configuration Sync Job
 *
 * This file contains the background job class for synchronizing external configurations
 * from their source repositories (GitHub, GitLab, URL).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Cron
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2025 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Service\Configuration\GitLabHandlerInterface;
use OCA\OpenRegister\Service\AppConfigurationImporterInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Class SyncConfigurationsJob
 *
 * Background job for synchronizing external configurations with their sources.
 * Runs periodically to check and sync configurations that have sync enabled.
 *
 * @package OCA\OpenRegister\BackgroundJob
 *
 * @psalm-suppress UnusedClass
 */
class SyncConfigurationsJob extends TimedJob {
	/**
	 * How often, in seconds, the job checks which configurations are due.
	 *
	 * @var int
	 */
	private const SYNC_CHECK_INTERVAL = 3600;

	/**
	 * Constructor
	 *
	 * @param ITimeFactory                  $time                 Time factory for job scheduling
	 * @param ConfigurationMapperInterface           $configurationMapper  Configuration mapper
	 * @param AppConfigurationImporterInterface $configurationService Configuration service
	 * @param GitHubClientInterface         $githubService        GitHub service
	 * @param GitLabHandlerInterface        $gitlabService        GitLab service
	 * @param Client                        $httpClient           HTTP client
	 * @param LoggerInterface               $logger               Logger
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ConfigurationMapperInterface $configurationMapper,
		private readonly AppConfigurationImporterInterface $configurationService,
		private readonly GitHubClientInterface $githubService,
		private readonly GitLabHandlerInterface $gitlabService,
		private readonly Client $httpClient,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::SYNC_CHECK_INTERVAL);
	}//end __construct()

	/**
	 * Run the background job
	 *
	 * Synchronizes all external configurations that have sync enabled and are due for sync.
	 *
	 * @param mixed $argument Job arguments (not used)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	protected function run($argument): void {
		$this->logInfo(message: 'Starting configuration sync job');

		try {
			$configurations = $this->configurationMapper->findBySyncEnabled();
			$this->logInfo(message: 'Found ' . count($configurations) . ' configurations with sync enabled');

			$tally = ['synced' => 0, 'skipped' => 0, 'failed' => 0];
			foreach ($configurations as $configuration) {
				$tally[$this->syncOne(configuration: $configuration)]++;
			}

			$this->logInfo(message: "Completed: {$tally['synced']} synced, {$tally['skipped']} skipped, {$tally['failed']} failed");
		} catch (Exception $e) {
			$this->logError(message: 'Configuration sync job failed: ' . $e->getMessage());
		}//end try
	}//end run()

	/**
	 * Sync one configuration when it is due, and say what happened.
	 *
	 * @param Configuration $configuration Configuration to sync
	 *
	 * @return string synced, skipped or failed
	 */
	private function syncOne(Configuration $configuration): string {
		try {
			if ($this->isDueForSync(configuration: $configuration) === false) {
				return 'skipped';
			}

			$this->syncAndLog(configuration: $configuration);
			return 'synced';
		} catch (Exception $e) {
			$this->recordFailure(configuration: $configuration, error: $e);
			return 'failed';
		}
	}//end syncOne()

	/**
	 * Sync one configuration, logging the start and the success.
	 *
	 * @param Configuration $configuration Configuration to sync
	 *
	 * @return void
	 * @throws Exception If sync fails
	 */
	private function syncAndLog(Configuration $configuration): void {
		$this->logInfo(message: "Syncing configuration: {$configuration->getTitle()} (ID: {$configuration->getId()})");
		$this->syncConfiguration(configuration: $configuration);
		$this->logInfo(message: "Successfully synced configuration {$configuration->getTitle()}");
	}//end syncAndLog()

	/**
	 * Log a failed sync and record it on the configuration.
	 *
	 * @param Configuration $configuration Configuration whose sync failed
	 * @param Exception $error Why it failed
	 *
	 * @return void
	 */
	private function recordFailure(Configuration $configuration, Exception $error): void {
		$this->logError(message: 'Sync error: ' . $error->getMessage());

		try {
			$this->configurationMapper->updateSyncStatus(
				id: $configuration->getId(),
				status: 'failed',
				syncDate: new DateTime(),
				_message: $error->getMessage()
			);
		} catch (Exception $statusError) {
			$this->logError(message: 'Failed to update sync status: ' . $statusError->getMessage());
		}
	}//end recordFailure()

	/**
	 * Check if a configuration is due for synchronization
	 *
	 * @param Configuration $configuration Configuration to check
	 *
	 * @return bool True if sync is due
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function isDueForSync(Configuration $configuration): bool {
		// If never synced, it's due.
		if ($configuration->getLastSyncDate() === null) {
			return true;
		}

		// Calculate time since last sync.
		$now = new DateTime();
		$lastSync = $configuration->getLastSyncDate();
		$interval = $configuration->getSyncInterval();
		// In hours.
		$diff = $now->getTimestamp() - $lastSync->getTimestamp();
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
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function syncConfiguration(Configuration $configuration): void {
		$sourceType = $configuration->getSourceType();
		$sync = match ($sourceType) {
			'github' => $this->syncFromGitHub(...),
			'gitlab' => $this->syncFromGitLab(...),
			'url' => $this->syncFromUrl(...),
			'local' => $this->syncFromLocal(...),
			default => throw new Exception("Unsupported source type: {$sourceType}"),
		};
		$sync(configuration: $configuration);
	}//end syncConfiguration()

	/**
	 * Sync configuration from GitHub
	 *
	 * @param Configuration $configuration Configuration to sync
	 *
	 * @return void
	 * @throws Exception If sync fails
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function syncFromGitHub(Configuration $configuration): void {
		$githubRepo = $configuration->getGithubRepo();
		$githubPath = $configuration->getGithubPath();
		if (empty($githubRepo) === true || empty($githubPath) === true) {
			throw new Exception('GitHub repository and path are required');
		}

		// The repository is stored as owner/repo.
		[$owner, $repo] = explode('/', $githubRepo);
		$configData = $this->githubService->getFileContent(
			owner: $owner,
			repo: $repo,
			path: $githubPath,
			branch: $configuration->getGithubBranch() ?? 'main'
		);

		$this->importFetched(configuration: $configuration, configData: $configData);
	}//end syncFromGitHub()

	/**
	 * Sync configuration from GitLab
	 *
	 * @param Configuration $configuration Configuration to sync
	 *
	 * @return void
	 * @throws Exception If sync fails
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function syncFromGitLab(Configuration $configuration): void {
		$sourceUrl = $configuration->getSourceUrl();

		if (empty($sourceUrl) === true) {
			throw new Exception('Source URL is required for GitLab sync');
		}

		[, $namespace, $project, $ref, $path] = $this->gitlabFileLocation(sourceUrl: $sourceUrl);
		$projectData = $this->gitlabService->getProjectByPath(namespace: $namespace, project: $project);
		$configData = $this->gitlabService->getFileContent(projectId: $projectData['id'], path: $path, ref: $ref);

		$this->importFetched(configuration: $configuration, configData: $configData);
	}//end syncFromGitLab()

	/**
	 * Sync configuration from URL
	 *
	 * @param Configuration $configuration Configuration to sync
	 *
	 * @return void
	 * @throws Exception If sync fails
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function syncFromUrl(Configuration $configuration): void {
		$sourceUrl = $configuration->getSourceUrl();

		if (empty($sourceUrl) === true) {
			throw new Exception('Source URL is required');
		}

		// Fetch content from URL.
		$response = $this->httpClient->request('GET', $sourceUrl);
		$content = $response->getBody()->getContents();

		$configData = json_decode($content, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON in URL response: ' . json_last_error_msg());
		}

		$this->importFetched(configuration: $configuration, configData: $configData);
	}//end syncFromUrl()

	/**
	 * Sync configuration from local file
	 *
	 * @param Configuration $configuration Configuration to sync
	 *
	 * @return void
	 * @throws Exception If sync fails
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md
	 */
	private function syncFromLocal(Configuration $configuration): void {
		$sourceUrl = $configuration->getSourceUrl();

		if (empty($sourceUrl) === true) {
			throw new Exception('Source URL (file path) is required for local sync');
		}

		// Get app ID and version.
		$appId = $configuration->getApp() ?? 'unknown';
		$version = $configuration->getVersion() ?? '1.0.0';

		// Use importFromFilePath to reload from file.
		$this->configurationService->importFromFilePath(
			appId: $appId,
			filePath: $sourceUrl,
			version: $version,
			force: true
		);

		$this->markSynced(configuration: $configuration);
	}//end syncFromLocal()

	/**
	 * The namespace, project, ref and path of a GitLab file URL.
	 *
	 * Format: https://gitlab.com/namespace/project/-/blob/branch/path/to/file.json.
	 *
	 * @param string $sourceUrl GitLab blob URL
	 *
	 * @return array<int, string> The full match, then namespace, project, ref and path
	 * @throws Exception When the URL is not a GitLab blob URL
	 */
	private function gitlabFileLocation(string $sourceUrl): array {
		if (preg_match('#gitlab\.com/([^/]+)/([^/]+)/-/blob/([^/]+)/(.+)$#', $sourceUrl, $matches) !== 1) {
			throw new Exception('Invalid GitLab URL format');
		}

		return $matches;
	}//end gitlabFileLocation()

	/**
	 * Import fetched configuration data with a forced update and record the sync.
	 *
	 * The app id and version come from the data's `x-openregister` and `info`
	 * blocks, falling back to the configuration's app and to 1.0.0.
	 *
	 * @param Configuration $configuration Configuration being synced
	 * @param array $configData Decoded configuration document
	 *
	 * @return void
	 */
	private function importFetched(Configuration $configuration, array $configData): void {
		$appId = $configData['x-openregister']['app'] ?? $configuration->getApp() ?? 'unknown';
		$version = $configData['info']['version'] ?? $configData['x-openregister']['version'] ?? '1.0.0';

		$this->configurationService->importFromApp(
			appId: $appId,
			data: $configData,
			version: $version,
			force: true
		);
		$this->markSynced(configuration: $configuration);
	}//end importFetched()

	/**
	 * Record a successful sync of a configuration now.
	 *
	 * @param Configuration $configuration Configuration that was synced
	 *
	 * @return void
	 */
	private function markSynced(Configuration $configuration): void {
		$this->configurationMapper->updateSyncStatus(
			id: $configuration->getId(),
			status: 'success',
			syncDate: new DateTime()
		);
	}//end markSynced()

	/**
	 * Log an info line under this job's prefix.
	 *
	 * @param string $message The line, without prefix
	 *
	 * @return void
	 */
	private function logInfo(string $message): void {
		$this->logger->info(message: '[SyncConfigurationsJob] ' . $message, context: ['file' => __FILE__, 'line' => __LINE__]);
	}//end logInfo()

	/**
	 * Log an error line under this job's prefix.
	 *
	 * @param string $message The line, without prefix
	 *
	 * @return void
	 */
	private function logError(string $message): void {
		$this->logger->error(message: '[SyncConfigurationsJob] ' . $message, context: ['file' => __FILE__, 'line' => __LINE__]);
	}//end logError()
}//end class
