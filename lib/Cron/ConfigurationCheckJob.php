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
     */
    public function __construct(
        ITimeFactory $time,
        ConfigurationMapper $configurationMapper,
        ConfigurationService $configurationService,
        NotificationService $notificationService,
        IAppConfig $appConfig,
        LoggerInterface $logger
    ) {
        parent::__construct($time);

        $this->configurationMapper  = $configurationMapper;
        $this->configurationService = $configurationService;
        $this->notificationService  = $notificationService;
        $this->appConfig            = $appConfig;
        $this->logger = $logger;

        // Set interval based on app configuration (default 3600 seconds = 1 hour).
        $interval = (int) $this->appConfig->getValueString('openregister', 'configuration_check_interval', '3600');

        // If interval is 0, disable the job by setting a very long interval.
        if ($interval === 0) {
            $this->setInterval(86400 * 365);
            // 1 year.
            $this->logger->info('Configuration check job is disabled (interval set to 0)');
        } else {
            $this->setInterval($interval);
            $this->logger->info("Configuration check job interval set to {$interval} seconds");
        }

    }//end __construct()

    /**
     * Run the background job
     *
     * Checks all remote configurations for updates.
     * If auto-update is enabled for a configuration, automatically imports the updates.
     *
     * @param mixed $_argument Job arguments (not used)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($_argument): void
    {
        $this->logger->info('Starting configuration check job');

        // Check if the job is disabled.
        if ($this->isJobDisabled() === true) {
            return;
        }

        try {
            // Get all configurations.
            $configurations = $this->configurationMapper->findAll();
            $this->logger->info('Found '.count($configurations).' configurations to check');

            $stats = ['checked' => 0, 'updated' => 0, 'failed' => 0];

            foreach ($configurations as $configuration) {
                $this->checkSingleConfiguration(configuration: $configuration, stats: $stats);
            }

            $this->logger->info(
                "Configuration check job completed: {$stats['checked']} checked, {$stats['updated']} updated, {$stats['failed']} failed"
            );
        } catch (Exception $e) {
            $this->logger->error('Configuration check job failed: '.$e->getMessage());
        }//end try

    }//end run()

    /**
     * Check if the job is currently disabled via configuration
     *
     * @return bool True if job is disabled, false otherwise.
     */
    private function isJobDisabled(): bool
    {
        $interval = (int) $this->appConfig->getValueString('openregister', 'configuration_check_interval', '3600');
        if ($interval === 0) {
            $this->logger->info('Configuration check job is disabled, skipping');
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
     */
    private function checkSingleConfiguration($configuration, array &$stats): void
    {
        try {
            // Only check remote configurations.
            if ($configuration->isRemoteSource() === false) {
                return;
            }

            $this->logger->info("Checking configuration: {$configuration->getTitle()} (ID: {$configuration->getId()})");

            // Check remote version.
            $remoteVersion = $this->configurationService->checkRemoteVersion(configuration: $configuration);
            $stats['checked']++;

            if ($remoteVersion === null) {
                $this->logger->warning("Could not determine remote version for configuration {$configuration->getId()}");
                return;
            }

            // Check if update is available.
            if ($configuration->hasUpdateAvailable() === false) {
                $this->logger->info("Configuration {$configuration->getTitle()} is up to date");
                return;
            }

            $this->logger->info("Update available for {$configuration->getTitle()}: {$configuration->getLocalVersion()} → {$remoteVersion}");

            // Handle the update based on auto-update setting.
            if ($configuration->getAutoUpdate() === true) {
                $this->handleAutoUpdate(configuration: $configuration, stats: $stats);
            } else {
                $this->sendUpdateNotification($configuration);
            }
        } catch (Exception $e) {
            $stats['failed']++;
            $this->logger->error("Error checking configuration {$configuration->getId()}: ".$e->getMessage());
        }//end try

    }//end checkSingleConfiguration()

    /**
     * Handle automatic update of a configuration
     *
     * @param \OCA\OpenRegister\Db\Configuration $configuration Configuration to update.
     * @param array                              $stats         Statistics array (passed by reference).
     *
     * @return void
     */
    private function handleAutoUpdate($configuration, array &$stats): void
    {
        $this->logger->info("Auto-update enabled, importing updates for {$configuration->getTitle()}");

        try {
            // Import all changes (no selection, import everything).
            $this->configurationService->importConfigurationWithSelection(
                configuration: $configuration,
                selection: []
            // Empty selection means import all.
            );

            $stats['updated']++;
            $this->logger->info("Successfully auto-updated configuration {$configuration->getTitle()}");
        } catch (Exception $e) {
            $this->logger->error("Failed to auto-update configuration {$configuration->getTitle()}: ".$e->getMessage());
            $stats['failed']++;
        }

    }//end handleAutoUpdate()

    /**
     * Send update notification for a configuration
     *
     * @param \OCA\OpenRegister\Db\Configuration $configuration Configuration to notify about.
     *
     * @return void
     */
    private function sendUpdateNotification($configuration): void
    {
        $this->logger->info("Auto-update disabled for {$configuration->getTitle()}, sending notification");

        try {
            // Send notification to configured groups.
            $notificationCount = $this->notificationService->notifyConfigurationUpdate(configuration: $configuration);
            $this->logger->info("Sent {$notificationCount} notifications for configuration {$configuration->getTitle()}");
        } catch (Exception $e) {
            $this->logger->error("Failed to send notifications for configuration {$configuration->getTitle()}: ".$e->getMessage());
        }

    }//end sendUpdateNotification()
}//end class
