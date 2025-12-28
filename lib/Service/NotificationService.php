<?php

/**
 * OpenRegister Notification Service
 *
 * This file contains the service class for sending notifications
 * about configuration updates in the OpenRegister application.
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
class NotificationService
{
    /**
     * Notification manager instance
     *
     * Handles Nextcloud notification system integration.
     *
     * @var IManager Notification manager instance
     */
    private readonly IManager $notificationManager;

    /**
     * Group manager instance
     *
     * Used to retrieve users from notification groups.
     *
     * @var IGroupManager Group manager instance
     */
    private readonly IGroupManager $groupManager;

    /**
     * Logger instance
     *
     * Used for logging notification operations and errors.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;

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
     */
    public function notifyConfigurationUpdate(Configuration $configuration): int
    {
        // Log start of notification process for monitoring.
        $this->logger->info(message: "Sending configuration update notification for: {$configuration->getTitle()}");

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
                $this->logger->warning(message: "Group {$groupId} not found, skipping");
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
                $this->logger->error(message: "Failed to send notification to user {$userId}: " . $e->getMessage());
            }
        }

        // Log completion with notification count.
        $this->logger->info(message: "Sent {$notificationCount} notifications for configuration update");

        return $notificationCount;
    }//end notifyConfigurationUpdate()

    /**
     * Send update notification to a specific user
     *
     * Creates and sends a Nextcloud notification to a specific user about
     * an available configuration update. Includes version information and
     * configuration details.
     *
     * @param string      $userId             The user ID to notify
     * @param string      $configurationTitle The configuration title
     * @param int         $configurationId    The configuration ID
     * @param string|null $currentVersion     The current/local version (optional)
     * @param string|null $newVersion         The new/remote version (optional)
     *
     * @return void
     *
     * @throws \Exception If notification creation or sending fails
     */
    private function sendUpdateNotification(
        string $userId,
        string $configurationTitle,
        int $configurationId,
        ?string $currentVersion,
        ?string $newVersion
    ): void {
        // Step 1: Create new notification instance.
        $notification = $this->notificationManager->createNotification();

        $notification->setApp('openregister')
            ->setUser($userId)
            ->setDateTime(new DateTime())
            ->setObject(type: 'configuration', id: (string) $configurationId)
            ->setSubject(
                subject: 'configuration_update_available',
                parameters: [
                        'configurationTitle' => $configurationTitle,
                        'configurationId'    => $configurationId,
                        'currentVersion'     => $currentVersion ?? 'unknown',
                        'newVersion'         => $newVersion ?? 'unknown',
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
     */
    public function markConfigurationUpdated(Configuration $configuration): void
    {
        $notification = $this->notificationManager->createNotification();

        $notification->setApp('openregister')
            ->setObject(type: 'configuration', id: (string) $configuration->getId());

        // This will remove all notifications for this configuration.
        $this->notificationManager->markProcessed($notification);

        $this->logger->info(message: "Marked configuration {$configuration->getTitle()} notifications as processed");
    }//end markConfigurationUpdated()
}//end class
