<?php

/**
 * OpenRegister Notification Service
 *
 * This file contains the service class for sending notifications
 * about configuration updates in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use DateTime;
use OCA\OpenRegister\Db\Configuration;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * NotificationService sends notifications about configuration updates
 *
 * Service for sending notifications about configuration updates.
 * Handles notification delivery to configured user groups and administrators.
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
class NotificationService {

	/**
	 * Constructor.
	 *
	 * WHY THIS EXISTS. The three properties below were declared `readonly` and
	 * never assigned — this class had NO constructor at all. Every method that
	 * touched one therefore died with
	 *
	 *   Typed property NotificationService::$notificationManager must not be
	 *   accessed before initialization
	 *
	 * which is a fatal, not a degraded path. So the whole configuration-update
	 * notification route was dead: `notifyConfigurationUpdate()`,
	 * `sendUpdateNotification()` and `markConfigurationUpdated()` could not run,
	 * and neither could ConfigurationController's import or ConfigurationCheckJob's
	 * cron tick, both of which inject this service.
	 *
	 * Surfaced 2026-08-26 in learniq's e2e server log, on
	 * `POST /apps/openregister/api/configurations/8/import` — a repository whose
	 * own suite never reached the call.
	 *
	 * @param IManager        $notificationManager Nextcloud notification manager.
	 * @param IGroupManager   $groupManager        Group manager, to expand notification groups.
	 * @param LoggerInterface $logger              Logger.
	 */
	public function __construct(
		private readonly IManager $notificationManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send notification about configuration update availability
	 *
	 * Notifies all users in configured notification groups about available
	 * configuration updates. Always includes admin group regardless of configuration.
	 * Deduplicates users across multiple groups to avoid duplicate notifications.
	 *
	 * @param Configuration $configuration The configuration entity with available update
	 *
	 * @return int Number of notifications successfully sent (0 or positive integer)
	 *
	 * @psalm-return int<0, max>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function notifyConfigurationUpdate(Configuration $configuration): int {
		// Log start of notification process for monitoring.
		$this->logger->info(
			message: "[NotificationService] Sending configuration update notification for: {$configuration->getTitle()}",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		// Step 1: Get notification groups from configuration.
		// These are groups that should be notified about updates.
		$notificationGroups = $configuration->getNotificationGroups() ?? [];

		// Step 2: Always include admin group to ensure administrators are notified.
		// This ensures critical updates are always communicated to admins.
		if (in_array('admin', $notificationGroups, true) === false) {
			$notificationGroups[] = 'admin';
		}

		// Step 3: Collect all unique users to notify from all groups.
		// Uses array keys to automatically deduplicate users across groups.
		$usersToNotify = [];
		foreach ($notificationGroups as $groupId) {
			// Get group entity from group manager.
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				// Log warning if group doesn't exist but continue with other groups.
				$this->logger->warning(
					message: "[NotificationService] Group {$groupId} not found, skipping",
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
				continue;
			}

			// Get all users in this group.
			$users = $group->getUsers();
			foreach ($users as $user) {
				// Use user ID as array key to automatically deduplicate.
				$usersToNotify[$user->getUID()] = true;
			}
		}

		// Step 4: Send notifications to all unique users.
		$notificationCount = 0;
		foreach (array_keys($usersToNotify) as $userId) {
			try {
				// Send individual notification to user.
				$this->sendUpdateNotification(
					userId: $userId,
					configurationTitle: $configuration->getTitle(),
					configurationId: $configuration->getId(),
					currentVersion: $configuration->getLocalVersion(),
					newVersion: $configuration->getRemoteVersion()
				);
				$notificationCount++;
			} catch (\Exception $e) {
				// Log error but continue sending to other users.
				$this->logger->error(
					message: "[NotificationService] Failed to send notification to user {$userId}: " . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}
		}

		// Log completion with notification count.
		$this->logger->info(
			message: "[NotificationService] Sent {$notificationCount} notifications for configuration update",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $notificationCount;
	}//end notifyConfigurationUpdate()

	/**
	 * Send update notification to a specific user
	 *
	 * Creates and sends a Nextcloud notification to a specific user about
	 * an available configuration update. Includes version information and
	 * configuration details.
	 *
	 * @param string $userId The user ID to notify
	 * @param string $configurationTitle The configuration title
	 * @param int $configurationId The configuration ID
	 * @param string|null $currentVersion The current/local version (optional)
	 * @param string|null $newVersion The new/remote version (optional)
	 *
	 * @return void
	 *
	 * @throws \Exception If notification creation or sending fails
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function sendUpdateNotification(
		string $userId,
		string $configurationTitle,
		int $configurationId,
		?string $currentVersion,
		?string $newVersion,
	): void {
		// Step 1: Create new notification instance.
		$notification = $this->notificationManager->createNotification();

		$notification->setApp('openregister')
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject(type: 'configuration', id: (string)$configurationId)
			->setSubject(
				subject: 'configuration_update_available',
				parameters: [
					'configurationTitle' => $configurationTitle,
					'configurationId' => $configurationId,
					'currentVersion' => $currentVersion ?? 'unknown',
					'newVersion' => $newVersion ?? 'unknown',
				]
			);

		$this->notificationManager->notify($notification);
	}//end sendUpdateNotification()

	/**
	 * Mark configuration update notification as processed.
	 *
	 * Removes notifications for a specific configuration after update is applied.
	 *
	 * @param Configuration $configuration The configuration that was updated
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function markConfigurationUpdated(Configuration $configuration): void {
		$notification = $this->notificationManager->createNotification();

		$notification->setApp('openregister')
			->setObject(type: 'configuration', id: (string)$configuration->getId());

		// This will remove all notifications for this configuration.
		$this->notificationManager->markProcessed($notification);

		$this->logger->info(
			message: "[NotificationService] Marked configuration {$configuration->getTitle()} notifications as processed",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end markConfigurationUpdated()
}//end class
