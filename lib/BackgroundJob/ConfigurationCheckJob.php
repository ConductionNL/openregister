<?php

/**
 * OpenRegister Configuration Check Job
 *
 * This file contains the background job class for checking remote configurations
 * for updates in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Cron
 * @package   OCA\OpenRegister\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\BackgroundJob;

use Exception;
use OCA\OpenRegister\BackgroundJob\Support\ConfigurationCheckServices;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapperInterface;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCA\OpenRegister\Service\NotificationServiceInterface;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Class ConfigurationCheckJob
 *
 * Background job for checking remote configurations for updates.
 * Runs at configurable intervals to check if remote configurations have newer versions.
 * Can automatically import updates if configured.
 *
 * @package OCA\OpenRegister\BackgroundJob
 *
 * @psalm-suppress UnusedClass
 */
class ConfigurationCheckJob extends TimedJob {

	/**
	 * The prefix of every message this job logs.
	 */
	private const TAG = '[ConfigurationCheckJob] ';

	/**
	 * Where the configurations are.
	 *
	 * @var ConfigurationMapperInterface
	 */
	private readonly ConfigurationMapperInterface $configurationMapper;

	/**
	 * Checks remote versions and imports updates.
	 *
	 * @var ConfigurationServiceInterface
	 */
	private readonly ConfigurationServiceInterface $configurationService;

	/**
	 * Tells admins an update is available.
	 *
	 * @var NotificationServiceInterface
	 */
	private readonly NotificationServiceInterface $notificationService;

	/**
	 * AppConfig, taken from the ConfiguredJobRuntime.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * Logger, taken from the ConfiguredJobRuntime.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor
	 *
	 * @param ConfiguredJobRuntime $runtime The job runtime: scheduler clock, app settings and run log.
	 * @param ConfigurationCheckServices $services  Configuration mapper, configuration service and notification service
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function __construct(
		ConfiguredJobRuntime $runtime,
		ConfigurationCheckServices $services,
	) {
		$this->logger = $runtime->logger;
		$this->appConfig = $runtime->appConfig;
		parent::__construct(time: $runtime->time);
		$this->configurationMapper = $services->configurationMapper;
		$this->configurationService = $services->configurationService;
		$this->notificationService = $services->notificationService;
		$this->applyInterval(interval: $this->configuredInterval());
	}//end __construct()

	/**
	 * The configured check interval in seconds (default 3600 = 1 hour); 0 disables the job.
	 *
	 * @return int The interval.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function configuredInterval(): int {
		return (int)$this->appConfig->getValueString('openregister', 'configuration_check_interval', '3600');
	}//end configuredInterval()

	/**
	 * Schedule the job: a disabled job (interval 0) gets a very long interval.
	 *
	 * @param int $interval The configured interval.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function applyInterval(int $interval): void {
		if ($interval === 0) {
			// 1 year.
			$this->setInterval(seconds: 86400 * 365);
			$this->debug(message: 'Configuration check job is disabled (interval set to 0)', line: __LINE__);
			return;
		}

		$this->setInterval(seconds: $interval);
		$this->debug(message: "Configuration check job interval set to {$interval} seconds", line: __LINE__);
	}//end applyInterval()

	/**
	 * Log a debug message, prefixed with the job's tag.
	 *
	 * @param string $message The message without the tag.
	 * @param int    $line    The calling line.
	 *
	 * @return void
	 */
	private function debug(string $message, int $line): void {
		$this->logger->debug(message: self::TAG . $message, context: self::at(line: $line));
	}//end debug()

	/**
	 * Log an error message, prefixed with the job's tag.
	 *
	 * @param string $message The message without the tag.
	 * @param int    $line    The calling line.
	 *
	 * @return void
	 */
	private function error(string $message, int $line): void {
		$this->logger->error(message: self::TAG . $message, context: self::at(line: $line));
	}//end error()

	/**
	 * The log context naming where in this file a message was written.
	 *
	 * @param int $line The calling line.
	 *
	 * @return array{file: string, line: int} The context.
	 */
	private static function at(int $line): array {
		return ['file' => __FILE__, 'line' => $line];
	}//end at()

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
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	protected function run($argument): void {
		$this->debug(message: 'Starting configuration check job', line: __LINE__);
		if ($this->isJobDisabled() === true) {
			return;
		}

		try {
			$stats = $this->checkAll();
			$msg = "Completed: {$stats['checked']} checked, {$stats['updated']} updated, {$stats['failed']} failed";
			$this->debug(message: $msg, line: __LINE__);
		} catch (Exception $e) {
			$this->error(message: 'Configuration check job failed: ' . $e->getMessage(), line: __LINE__);
		}
	}//end run()

	/**
	 * Check every configuration and tally the outcome.
	 *
	 * @return array{checked: int, updated: int, failed: int} The statistics.
	 *
	 * @throws Exception When the configurations cannot be listed.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function checkAll(): array {
		$configurations = $this->configurationMapper->findAll();
		$this->debug(message: 'Found ' . count($configurations) . ' configurations to check', line: __LINE__);

		$stats = ['checked' => 0, 'updated' => 0, 'failed' => 0];
		foreach ($configurations as $configuration) {
			$this->checkSingleConfiguration(configuration: $configuration, stats: $stats);
		}

		return $stats;
	}//end checkAll()

	/**
	 * Check if the job is currently disabled via configuration
	 *
	 * @return bool True if job is disabled, false otherwise.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function isJobDisabled(): bool {
		if ($this->configuredInterval() === 0) {
			$this->debug(message: 'Configuration check job is disabled, skipping', line: __LINE__);
			return true;
		}

		return false;
	}//end isJobDisabled()

	/**
	 * Check a single configuration for updates; a failure counts and is logged.
	 *
	 * @param Configuration $configuration Configuration to check.
	 * @param array $stats Statistics array (passed by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function checkSingleConfiguration(Configuration $configuration, array &$stats): void {
		try {
			$this->checkRemoteConfiguration(configuration: $configuration, stats: $stats);
		} catch (Exception $e) {
			$stats['failed']++;
			$this->error(message: "Error checking configuration {$configuration->getId()}: " . $e->getMessage(), line: __LINE__);
		}
	}//end checkSingleConfiguration()

	/**
	 * Check a remote configuration's version, then import or notify.
	 *
	 * @param Configuration $configuration Configuration to check.
	 * @param array $stats Statistics array (passed by reference).
	 *
	 * @return void
	 *
	 * @throws Exception When the remote check or the notification step fails.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function checkRemoteConfiguration(Configuration $configuration, array &$stats): void {
		// Only check remote configurations.
		if ($configuration->isRemoteSource() === false) {
			return;
		}

		$this->debug(message: "Checking {$configuration->getTitle()} (ID: {$configuration->getId()})", line: __LINE__);
		$remoteVersion = $this->configurationService->checkRemoteVersion(configuration: $configuration);
		$stats['checked']++;
		if ($this->updatePending(configuration: $configuration, remoteVersion: $remoteVersion) === false) {
			return;
		}

		$this->applyUpdate(configuration: $configuration, stats: $stats);
	}//end checkRemoteConfiguration()

	/**
	 * Whether a checked configuration has an update to act on.
	 *
	 * @param Configuration $configuration The configuration.
	 * @param string|null $remoteVersion The remote version, or null when none was found.
	 *
	 * @return bool True when an update is available.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function updatePending(Configuration $configuration, ?string $remoteVersion): bool {
		if ($remoteVersion === null) {
			$this->logger->warning(message: self::TAG . "No remote version for config {$configuration->getId()}", context: self::at(line: __LINE__));
			return false;
		}

		$title = $configuration->getTitle();
		if ($configuration->hasUpdateAvailable() === false) {
			$this->debug(message: "Configuration {$title} is up to date", line: __LINE__);
			return false;
		}

		$this->debug(message: "Update available for {$title}: {$configuration->getLocalVersion()} → {$remoteVersion}", line: __LINE__);
		return true;
	}//end updatePending()

	/**
	 * Handle the update based on the configuration's auto-update setting.
	 *
	 * @param Configuration $configuration The configuration.
	 * @param array $stats Statistics array (passed by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function applyUpdate(Configuration $configuration, array &$stats): void {
		if ($configuration->getAutoUpdate() === true) {
			$this->handleAutoUpdate(configuration: $configuration, stats: $stats);
			return;
		}

		$this->sendUpdateNotification(configuration: $configuration);
	}//end applyUpdate()

	/**
	 * Handle automatic update of a configuration
	 *
	 * @param Configuration $configuration Configuration to update.
	 * @param array $stats Statistics array (passed by reference).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function handleAutoUpdate(Configuration $configuration, array &$stats): void {
		$title = $configuration->getTitle();
		$this->debug(message: "Auto-update enabled, importing updates for {$title}", line: __LINE__);

		try {
			// Import all changes: an empty selection means import everything.
			$this->configurationService->importConfigurationWithSelection(configuration: $configuration, selection: []);
			$stats['updated']++;
			$this->debug(message: "Successfully auto-updated configuration {$title}", line: __LINE__);
		} catch (Exception $e) {
			$this->error(message: 'Auto-update failed: ' . $e->getMessage(), line: __LINE__);
			$stats['failed']++;
		}
	}//end handleAutoUpdate()

	/**
	 * Send update notification for a configuration
	 *
	 * @param Configuration $configuration Configuration to notify about.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function sendUpdateNotification(Configuration $configuration): void {
		$title = $configuration->getTitle();
		$this->debug(message: "Auto-update disabled for {$title}, sending notification", line: __LINE__);

		try {
			// Send notification to configured groups.
			$notificationCount = $this->notificationService->notifyConfigurationUpdate(configuration: $configuration);
			// Info: notifications reached real people. That is an outcome
			// someone may need to account for later, unlike the steps that
			// decided to send them.
			$this->logger->info(message: self::TAG . "Sent {$notificationCount} notifications", context: self::at(line: __LINE__));
		} catch (Exception $e) {
			$this->error(message: "Failed to send notifications for {$title}: " . $e->getMessage(), line: __LINE__);
		}
	}//end sendUpdateNotification()
}//end class
