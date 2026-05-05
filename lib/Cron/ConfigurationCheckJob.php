<?php

/**
 * OpenRegister Configuration Check Job
 *
 * This file contains the background job class for checking remote configurations
 * for updates in the OpenRegister application.
 *
 * @category  Cron
 * @package   OCA\OpenRegister\Cron
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Cron;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * Class ConfigurationCheckJob
 *
 * Background job for checking remote configurations for updates.
 * Runs at configurable intervals to check if remote configurations have newer versions.
 * Can automatically import updates if configured.
 *
 * @package OCA\OpenRegister\Cron
 *
 * @psalm-suppress UnusedClass
 */
class ConfigurationCheckJob extends TimedJob
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
     * App configuration instance.
     *
     * @var IAppConfig The app configuration instance.
     */
    private IAppConfig $appConfig;

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
     * @param NotificationService  $notificationService  Notification service
     * @param IAppConfig           $appConfig            App configuration
     * @param LoggerInterface      $logger               Logger
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-18
     */
    public function __construct(
        ITimeFactory $time,
        ConfigurationMapper $configurationMapper,
        ConfigurationService $configurationService,
        NotificationService $notificationService,
        IAppConfig $appConfig,
        LoggerInterface $logger
    ) {
        parent::__construct(time: $time);

        $this->configurationMapper  = $configurationMapper;
        $this->configurationService = $configurationService;
        $this->notificationService  = $notificationService;
        $this->appConfig            = $appConfig;
        $this->logger = $logger;

        // Set interval based on app configuration (default 3600 seconds = 1 hour).
        $interval = (int) $this->appConfig->getValueString('openregister', 'configuration_check_interval', '3600');

        // If interval is 0, disable the job by setting a very long interval.
        if ($interval === 0) {
            $this->setInterval(seconds: 86400 * 365);
            // 1 year.
            $this->logger->info(
                message: '[ConfigurationCheckJob] Configuration check job is disabled (interval set to 0)',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        $this->setInterval(seconds: $interval);
        $this->logger->info(
            message: "[ConfigurationCheckJob] Configuration check job interval set to {$interval} seconds",
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
    }//end __construct()

    /**
     * Run the background job
     *
     * Checks all remote configurations for updates.
     * If auto-update is enabled for a configuration, automatically imports the updates.
     *
     * @param mixed $argument Job arguments (not used)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-18
     */
    protected function run($argument): void
    {
        $this->logger->info(
            message: '[ConfigurationCheckJob] Starting configuration check job',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Check if the job is disabled.
        if ($this->isJobDisabled() === true) {
            return;
        }

        try {
            // Get all configurations.
            $configurations = $this->configurationMapper->findAll();
            $this->logger->info(
                message: '[ConfigurationCheckJob] Found '.count($configurations).' configurations to check',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            $stats = ['checked' => 0, 'updated' => 0, 'failed' => 0];

            foreach ($configurations as $configuration) {
                $this->checkSingleConfiguration(configuration: $configuration, stats: $stats);
            }

            $checked = $stats['checked'];
            $updated = $stats['updated'];
            $failed  = $stats['failed'];
            $msg     = "[ConfigurationCheckJob] Completed: {$checked} checked, {$updated} updated, {$failed} failed"
            ;
            $this->logger->info(
                message: $msg,
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[ConfigurationCheckJob] Configuration check job failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }//end try
    }//end run()

    /**
     * Check if the job is currently disabled via configuration
     *
     * @return bool True if job is disabled, false otherwise.
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-18
     */
    private function isJobDisabled(): bool
    {
        $interval = (int) $this->appConfig->getValueString('openregister', 'configuration_check_interval', '3600');
        if ($interval === 0) {
            $this->logger->info(
                message: '[ConfigurationCheckJob] Configuration check job is disabled, skipping',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return true;
        }

        return false;
    }//end isJobDisabled()

    /**
     * Check a single configuration for updates
     *
     * @param \OCA\OpenRegister\Db\Configuration $configuration Configuration to check.
     * @param array                              $stats         Statistics array (passed by reference).
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-18
     */
    private function checkSingleConfiguration($configuration, array &$stats): void
    {
        try {
            // Only check remote configurations.
            if ($configuration->isRemoteSource() === false) {
                return;
            }

            $checkMsg = "[ConfigurationCheckJob] Checking {$configuration->getTitle()} (ID: {$configuration->getId()})";
            $this->logger->info(
                message: $checkMsg,
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Check remote version.
            $remoteVersion = $this->configurationService->checkRemoteVersion(configuration: $configuration);
            $stats['checked']++;

            if ($remoteVersion === null) {
                $noVersionMsg = "[ConfigurationCheckJob] No remote version for config {$configuration->getId()}";
                $this->logger->warning(
                    message: $noVersionMsg,
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return;
            }

            // Check if update is available.
            if ($configuration->hasUpdateAvailable() === false) {
                $this->logger->info(
                    message: "[ConfigurationCheckJob] Configuration {$configuration->getTitle()} is up to date",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return;
            }

            $title        = $configuration->getTitle();
            $localVersion = $configuration->getLocalVersion();
            $this->logger->info(
                message: "[ConfigurationCheckJob] Update available for {$title}: {$localVersion} → {$remoteVersion}",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Handle the update based on auto-update setting.
            if ($configuration->getAutoUpdate() === true) {
                $this->handleAutoUpdate(configuration: $configuration, stats: $stats);
                return;
            }

            $this->sendUpdateNotification(configuration: $configuration);
        } catch (Exception $e) {
            $stats['failed']++;
            $this->logger->error(
                message: "[ConfigurationCheckJob] Error checking configuration {$configuration->getId()}: ".$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }//end try
    }//end checkSingleConfiguration()

    /**
     * Handle automatic update of a configuration
     *
     * @param \OCA\OpenRegister\Db\Configuration $configuration Configuration to update.
     * @param array                              $stats         Statistics array (passed by reference).
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-18
     */
    private function handleAutoUpdate($configuration, array &$stats): void
    {
        $this->logger->info(
            message: "[ConfigurationCheckJob] Auto-update enabled, importing updates for {$configuration->getTitle()}",
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        try {
            // Import all changes (no selection, import everything).
            $this->configurationService->importConfigurationWithSelection(
                configuration: $configuration,
                selection: []
                // Empty selection means import all.
            );

            $stats['updated']++;
            $this->logger->info(
                message: "[ConfigurationCheckJob] Successfully auto-updated configuration {$configuration->getTitle()}",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: "[ConfigurationCheckJob] Auto-update failed: ".$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $stats['failed']++;
        }
    }//end handleAutoUpdate()

    /**
     * Send update notification for a configuration
     *
     * @param \OCA\OpenRegister\Db\Configuration $configuration Configuration to notify about.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-b2b-crossrefs-2026-04-28/tasks.md#task-18
     */
    private function sendUpdateNotification($configuration): void
    {
        $this->logger->info(
            message: "[ConfigurationCheckJob] Auto-update disabled for {$configuration->getTitle()}, sending notification",
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        try {
            // Send notification to configured groups.
            $notificationCount = $this->notificationService->notifyConfigurationUpdate(configuration: $configuration);
            $this->logger->info(
                message: "[ConfigurationCheckJob] Sent {$notificationCount} notifications",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        } catch (Exception $e) {
            $title = $configuration->getTitle();
            $this->logger->error(
                message: "[ConfigurationCheckJob] Failed to send notifications for {$title}: ".$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }
    }//end sendUpdateNotification()
}//end class
