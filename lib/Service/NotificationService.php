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
 * Class NotificationService
 *
 * Service for sending notifications about configuration updates.
 *
 * @package OCA\OpenRegister\Service
 */
class NotificationService
{

    /**
     * Notification manager instance.
     *
     * @var IManager The notification manager instance.
     */
    private IManager $notificationManager;

    /**
     * Group manager instance.
     *
     * @var IGroupManager The group manager instance.
     */
    private IGroupManager $groupManager;

    /**
     * Logger instance.
     *
     * @var LoggerInterface The logger instance.
     */
    private LoggerInterface $logger;


    /**
     * Send notification about configuration update availability.
     *
     * Notifies configured groups and always includes the admin group.
     *
     * @param Configuration $configuration The configuration with available update
     *
     * @return int Number of notifications sent
     *
     * @psalm-return int<0, max>
     */
    public function notifyConfigurationUpdate(Configuration $configuration): int
    {
        $this->logger->info(message: "Sending configuration update notification for: {$configuration->getTitle()}");

        // Get notification groups from configuration.
        $notificationGroups = $configuration->getNotificationGroups() ?? [];

        // Always include admin group.
        if (in_array('admin', $notificationGroups, true) === false) {
            $notificationGroups[] = 'admin';
        }

        // Collect all users to notify.
        $usersToNotify = [];
        foreach ($notificationGroups as $groupId) {
            $group = $this->groupManager->get($groupId);
            if ($group === null) {
                $this->logger->warning(message: "Group {$groupId} not found, skipping");
                continue;
            }

            $users = $group->getUsers();
            foreach ($users as $user) {
                $usersToNotify[$user->getUID()] = true;
                // Use array key to avoid duplicates.
            }
        }

        // Send notifications to all users.
        $notificationCount = 0;
        foreach (array_keys($usersToNotify) as $userId) {
            try {
                $this->sendUpdateNotification(
                    $userId,
                    $configuration->getTitle(),
                    $configuration->getId(),
                    $configuration->getLocalVersion(),
                    $configuration->getRemoteVersion()
                );
                $notificationCount++;
            } catch (\Exception $e) {
                $this->logger->error(message: "Failed to send notification to user {$userId}: ".$e->getMessage());
            }
        }

        $this->logger->info(message: "Sent {$notificationCount} notifications for configuration update");

        return $notificationCount;

    }//end notifyConfigurationUpdate()


    /**
     * Send update notification to a specific user.
     *
     * @param string      $userId             The user ID to notify
     * @param string      $configurationTitle The configuration title
     * @param int         $configurationId    The configuration ID
     * @param string|null $currentVersion     The current/local version
     * @param string|null $newVersion         The new/remote version
     *
     * @return void
     */
    private function sendUpdateNotification(
        string $userId,
        string $configurationTitle,
        int $configurationId,
        ?string $currentVersion,
        ?string $newVersion
    ): void {
        $notification = $this->notificationManager->createNotification();

        $notification->setApp('openregister')
            ->setUser($userId)
            ->setDateTime(new DateTime())
            ->setObject('configuration', (string) $configurationId)
            ->setSubject(
                    'configuration_update_available',
                    [
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
            ->setObject('configuration', (string) $configuration->getId());

        // This will remove all notifications for this configuration.
        $this->notificationManager->markProcessed($notification);

        $this->logger->info(message: "Marked configuration {$configuration->getTitle()} notifications as processed");

    }//end markConfigurationUpdated()


}//end class
