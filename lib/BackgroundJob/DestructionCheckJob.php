<?php

/**
 * OpenRegister Destruction Check Background Job
 *
 * Periodic background job that scans for objects eligible for destruction
 * and generates destruction lists for archivist review.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use Exception;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Db\MagicMapper;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Periodic destruction check job.
 *
 * Scans for objects eligible for destruction and generates destruction lists.
 * Sends pre-destruction notifications for objects approaching their deadline.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DestructionCheckJob extends TimedJob
{

    /**
     * Default interval: 24 hours (daily).
     */
    private const DEFAULT_INTERVAL = 86400;

    /**
     * App config key for tracking notified objects.
     */
    private const NOTIFIED_KEY = 'retention_notified_objects';

    /**
     * Constructor.
     *
     * @param ITimeFactory $time Time factory for parent class
     */
    public function __construct(ITimeFactory $time)
    {
        parent::__construct(time: $time);

        try {
            $handler  = \OC::$server->get(ObjectRetentionHandler::class);
            $settings = $handler->getArchivalSettingsOnly();
            $interval = (int) ($settings['destructionCheckInterval'] ?? self::DEFAULT_INTERVAL);
        } catch (Exception $e) {
            $interval = self::DEFAULT_INTERVAL;
        }

        $this->setInterval(seconds: $interval);
    }//end __construct()

    /**
     * Execute the destruction check job.
     *
     * @param mixed $argument Job arguments (unused for timed jobs)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function run($argument): void
    {
        $logger = \OC::$server->get(LoggerInterface::class);
        $logger->info('[DestructionCheckJob] Starting destruction check');

        try {
            $retentionService = \OC::$server->get(RetentionService::class);
            $settingsHandler  = \OC::$server->get(ObjectRetentionHandler::class);
            $settings         = $settingsHandler->getArchivalSettingsOnly();

            if (empty($settings['destructionListRegister']) === true
                || empty($settings['destructionListSchema']) === true
            ) {
                $logger->info('[DestructionCheckJob] Destruction list register/schema not configured, skipping');
                return;
            }

            // Step 1: Send pre-destruction notifications.
            $this->sendPreDestructionNotifications(retentionService: $retentionService, settings: $settings, logger: $logger);

            // Step 2: Find eligible objects and create destruction list.
            $excludeUuids = $retentionService->getObjectsOnPendingDestructionLists();
            $eligible     = $retentionService->findEligibleForDestruction($excludeUuids);

            if (empty($eligible) === true) {
                $logger->info('[DestructionCheckJob] No objects eligible for destruction');
                return;
            }

            $logger->info('[DestructionCheckJob] Found '.count($eligible).' objects eligible');

            // Step 3: Create destruction list as register object.
            $listData = $retentionService->createDestructionList($eligible);

            if ($listData === null) {
                $logger->warning('[DestructionCheckJob] Failed to create destruction list');
                return;
            }

            $saveObject = \OC::$server->get(\OCA\OpenRegister\Service\Object\SaveObject::class);
            $savedList  = $saveObject->saveObject(
                $settings['destructionListRegister'],
                $settings['destructionListSchema'],
                $listData,
                null,
                null,
                false,
                false,
                true,
                true
            );

            $logger->info(
                '[DestructionCheckJob] Created destruction list, UUID: '.$savedList->getUuid()
            );

            // Step 4: Notify archivaris group.
            $this->sendReviewNotification(listUuid: $savedList->getUuid(), objectCount: count($eligible), logger: $logger);
        } catch (Exception $e) {
            $logger->error('[DestructionCheckJob] Error: '.$e->getMessage(), ['exception' => $e]);
        }//end try
    }//end run()

    /**
     * Send pre-destruction notifications for approaching objects.
     *
     * @param RetentionService $retentionService The retention service
     * @param array            $settings         Archival settings
     * @param LoggerInterface  $logger           Logger
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function sendPreDestructionNotifications(
        RetentionService $retentionService,
        array $settings,
        LoggerInterface $logger
    ): void {
        $leadDays  = (int) ($settings['notificationLeadDays'] ?? 30);
        $threshold = (new DateTime())->modify("+{$leadDays} days")->format('Y-m-d');
        $today     = (new DateTime())->format('Y-m-d');

        try {
            $objectMapper = \OC::$server->get(MagicMapper::class);
            $connection   = \OC::$server->getDatabaseConnection();
            $qb           = $connection->getQueryBuilder();

            $qb->select('id')->from('openregister_objects')
                ->where($qb->expr()->isNotNull('retention'));

            $result = $qb->executeQuery();
            $rows   = $result->fetchAllAssociative();
            $result->free();

            $appConfig    = \OC::$server->get(\OCP\IAppConfig::class);
            $notifiedJson = $appConfig->getValueString('openregister', self::NOTIFIED_KEY, '[]');
            $notified     = json_decode($notifiedJson, true) ?? [];
            $newCount     = 0;

            foreach ($rows as $row) {
                try {
                    $object = $objectMapper->find(intval($row['id']), null, null, false, false, false);
                } catch (Exception $e) {
                    continue;
                }

                $retention = $object->getRetention() ?? [];
                $status    = $retention['archiefstatus'] ?? null;
                $actieDate = $retention['archiefactiedatum'] ?? null;
                $nominatie = $retention['archiefnominatie'] ?? null;

                if ($actieDate === null || $status !== 'nog_te_archiveren') {
                    continue;
                }

                if ($actieDate <= $today || $actieDate > $threshold) {
                    continue;
                }

                $uuid = $object->getUuid();
                if (in_array($uuid, $notified, true) === true) {
                    continue;
                }

                if (($retention['legalHold']['active'] ?? false) === true) {
                    continue;
                }

                $subject = $nominatie === 'bewaren' ? 'Object requires e-Depot transfer' : 'Object approaching destruction date';

                $this->sendObjectNotification(
                    uuid: $uuid,
                    subject: $subject,
                    title: $object->getTitle() ?? $uuid,
                    actieDate: $actieDate,
                    classificatie: $retention['classificatie'] ?? null,
                    logger: $logger
                );

                $notified[] = $uuid;
                $newCount++;
            }//end foreach

            if ($newCount > 0) {
                $appConfig->setValueString('openregister', self::NOTIFIED_KEY, json_encode($notified));
                $logger->info('[DestructionCheckJob] Sent '.$newCount.' pre-destruction notifications');
            }
        } catch (Exception $e) {
            $logger->warning('[DestructionCheckJob] Notification error: '.$e->getMessage());
        }//end try
    }//end sendPreDestructionNotifications()

    /**
     * Send a notification about a specific object.
     *
     * @param string          $uuid          Object UUID
     * @param string          $subject       Notification subject
     * @param string          $title         Object title
     * @param string          $actieDate     Archiefactiedatum
     * @param string|null     $classificatie Selectielijst category
     * @param LoggerInterface $logger        Logger
     *
     * @return void
     */
    private function sendObjectNotification(
        string $uuid,
        string $subject,
        string $title,
        string $actieDate,
        ?string $classificatie,
        LoggerInterface $logger
    ): void {
        try {
            $notificationManager = \OC::$server->get(INotificationManager::class);
            $groupManager        = \OC::$server->get(IGroupManager::class);

            $group = $groupManager->get('archivaris');
            if ($group === null) {
                return;
            }

            foreach ($group->getUsers() as $user) {
                $notification = $notificationManager->createNotification();
                $notification->setApp('openregister')
                    ->setUser($user->getUID())
                    ->setDateTime(new DateTime())
                    ->setObject('retention', $uuid)
                    ->setSubject(
                            'retention_approaching',
                            [
                                'subject'       => $subject,
                                'title'         => $title,
                                'actieDate'     => $actieDate,
                                'classificatie' => $classificatie ?? '',
                            ]
                            );
                $notificationManager->notify($notification);
            }
        } catch (Exception $e) {
            $logger->warning('[DestructionCheckJob] Notification send error: '.$e->getMessage());
        }//end try
    }//end sendObjectNotification()

    /**
     * Send review notification for new destruction list.
     *
     * @param string          $listUuid    Destruction list UUID
     * @param int             $objectCount Number of objects
     * @param LoggerInterface $logger      Logger
     *
     * @return void
     */
    private function sendReviewNotification(
        string $listUuid,
        int $objectCount,
        LoggerInterface $logger
    ): void {
        try {
            $notificationManager = \OC::$server->get(INotificationManager::class);
            $groupManager        = \OC::$server->get(IGroupManager::class);

            $group = $groupManager->get('archivaris');
            if ($group === null) {
                return;
            }

            foreach ($group->getUsers() as $user) {
                $notification = $notificationManager->createNotification();
                $notification->setApp('openregister')
                    ->setUser($user->getUID())
                    ->setDateTime(new DateTime())
                    ->setObject('destruction_list', $listUuid)
                    ->setSubject(
                            'destruction_list_review',
                            [
                                'listUuid'    => $listUuid,
                                'objectCount' => $objectCount,
                            ]
                            );
                $notificationManager->notify($notification);
            }
        } catch (Exception $e) {
            $logger->warning('[DestructionCheckJob] Review notification error: '.$e->getMessage());
        }//end try
    }//end sendReviewNotification()
}//end class
