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

		$notificationCount = $this->notifyRecipients(configuration: $configuration);
		$this->logger->info(
			message: "[NotificationService] Sent {$notificationCount} notifications for configuration update",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $notificationCount;
	}//end notifyConfigurationUpdate()

	/**
	 * Notify every recipient of a configuration's update.
	 *
	 * @param Configuration $configuration The configuration entity with available update
	 *
	 * @return int Number of notifications successfully sent
	 */
	private function notifyRecipients(Configuration $configuration): int {
		$notificationCount = 0;
		foreach ($this->recipientsOf(configuration: $configuration) as $userId) {
			$notificationCount += $this->trySendUpdateNotification(userId: $userId, configuration: $configuration);
		}

		return $notificationCount;
	}//end notifyRecipients()

	/**
	 * The unique ids of every user to notify: members of the configured groups plus the admin group.
	 *
	 * The admin group is always included so critical updates always reach administrators.
	 * Users in several groups are notified once, in order of first appearance.
	 *
	 * @param Configuration $configuration The configuration entity with available update
	 *
	 * @return array<int,int|string> User ids (numeric ids come back as int array keys)
	 */
	private function recipientsOf(Configuration $configuration): array {
		$notificationGroups = $configuration->getNotificationGroups() ?? [];
		if (in_array('admin', $notificationGroups, true) === false) {
			$notificationGroups[] = 'admin';
		}

		$usersToNotify = [];
		foreach ($notificationGroups as $groupId) {
			foreach ($this->memberIdsOf(groupId: $groupId) as $userId) {
				$usersToNotify[$userId] = true;
			}
		}

		return array_keys($usersToNotify);
	}//end recipientsOf()

	/**
	 * The user ids of a group's members; a missing group is logged and has none.
	 *
	 * @param mixed $groupId The group id as configured
	 *
	 * @return array<int|string,string>
	 */
	private function memberIdsOf(mixed $groupId): array {
		$group = $this->groupManager->get($groupId);
		if ($group === null) {
			// Log warning if group doesn't exist but continue with other groups.
			$this->logger->warning(
				message: "[NotificationService] Group {$groupId} not found, skipping",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}

		return array_map(static fn ($user) => $user->getUID(), $group->getUsers());
	}//end memberIdsOf()

	/**
	 * Notify one user, logging a failure instead of throwing it.
	 *
	 * @param string        $userId        The user ID to notify
	 * @param Configuration $configuration The configuration entity with available update
	 *
	 * @return int 1 when sent, 0 when sending failed
	 */
	private function trySendUpdateNotification(string $userId, Configuration $configuration): int {
		try {
			$this->sendUpdateNotification(userId: $userId, notice: $this->noticeFor(configuration: $configuration));
			return 1;
		} catch (\Exception $e) {
			// Log error but continue sending to other users.
			$this->logger->error(
				message: "[NotificationService] Failed to send notification to user {$userId}: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return 0;
		}
	}//end trySendUpdateNotification()

	/**
	 * The notice for a configuration's available update.
	 *
	 * Built in this (non-strict) file so title, id and versions are coerced to the
	 * notice's types exactly as the former scalar parameters were.
	 *
	 * @param Configuration $configuration The configuration entity with available update
	 *
	 * @return ConfigurationUpdateNotice
	 */
	private function noticeFor(Configuration $configuration): ConfigurationUpdateNotice {
		return new ConfigurationUpdateNotice(
			configurationTitle: $configuration->getTitle(),
			configurationId: $configuration->getId(),
			currentVersion: $configuration->getLocalVersion(),
			newVersion: $configuration->getRemoteVersion()
		);
	}//end noticeFor()

	/**
	 * Send update notification to a specific user
	 *
	 * Creates and sends a Nextcloud notification to a specific user about
	 * an available configuration update. Includes version information and
	 * configuration details.
	 *
	 * @param string                    $userId The user ID to notify
	 * @param ConfigurationUpdateNotice $notice The configuration and its versions
	 *
	 * @return void
	 *
	 * @throws \Exception If notification creation or sending fails
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function sendUpdateNotification(string $userId, ConfigurationUpdateNotice $notice): void {
		$notification = $this->notificationManager->createNotification();

		$notification->setApp('openregister')
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject(type: 'configuration', id: (string)$notice->configurationId)
			->setSubject(subject: 'configuration_update_available', parameters: $notice->subjectParameters());

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
