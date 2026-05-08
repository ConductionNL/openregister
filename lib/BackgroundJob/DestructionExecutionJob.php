<?php

/**
 * OpenRegister Destruction Execution Background Job
 *
 * Queued background job that processes approved destruction lists by permanently
 * deleting objects in configurable batches, generating audit trails and
 * destruction certificates upon completion.
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
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-2
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-5
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use Exception;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\BackgroundJob\QueuedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Queued job for executing approved destruction lists.
 *
 * Processes destruction in batches, respects legal holds at execution time,
 * and generates destruction certificates.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class DestructionExecutionJob extends QueuedJob
{

    /**
     * Default batch size for destruction processing.
     */
    private const DEFAULT_BATCH_SIZE = 50;

    /**
     * Constructor.
     *
     * @param ITimeFactory $time Time factory for parent class
     */
    public function __construct(ITimeFactory $time)
    {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Execute the destruction of an approved destruction list.
     *
     * @param mixed $argument Job arguments containing 'destructionListUuid'
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-2
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-5
     */
    protected function run($argument): void
    {
        $logger = \OC::$server->get(LoggerInterface::class);

        $listUuid = $argument['destructionListUuid'] ?? null;
        if ($listUuid === null) {
            $logger->error('[DestructionExecutionJob] No destructionListUuid provided');
            return;
        }

        $logger->info('[DestructionExecutionJob] Processing destruction list: '.$listUuid);

        try {
            $retentionService = \OC::$server->get(RetentionService::class);
            $settingsHandler  = \OC::$server->get(ObjectRetentionHandler::class);
            $objectMapper     = \OC::$server->get(MagicMapper::class);
            $auditMapper      = \OC::$server->get(AuditTrailMapper::class);
            $deleteObject     = \OC::$server->get(DeleteObject::class);
            $saveObject       = \OC::$server->get(\OCA\OpenRegister\Service\Object\SaveObject::class);
            $settings         = $settingsHandler->getArchivalSettingsOnly();
            $batchSize        = (int) ($settings['destructionBatchSize'] ?? self::DEFAULT_BATCH_SIZE);

            // Load the destruction list object.
            $listObject = $objectMapper->find($listUuid, null, null, false, false, false);

            if ($listObject === null) {
                $logger->error('[DestructionExecutionJob] Destruction list not found: '.$listUuid);
                return;
            }

            $listData = $listObject->getObject();

            if (($listData['status'] ?? '') !== 'approved') {
                $logger->warning('[DestructionExecutionJob] List not approved: '.$listUuid);
                return;
            }

            $objects        = $listData['objects'] ?? [];
            $destroyedCount = 0;
            $skippedHolds   = 0;
            $skippedErrors  = 0;
            $batches        = array_chunk($objects, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                $logger->info(
                    '[DestructionExecutionJob] Batch '.($batchIndex + 1).'/'.count($batches)
                );

                foreach ($batch as $objRef) {
                    $uuid = $objRef['uuid'] ?? null;
                    if ($uuid === null) {
                        $skippedErrors++;
                        continue;
                    }

                    try {
                        $object = $objectMapper->find($uuid, null, null, false, false, false);

                        if ($object === null) {
                            $skippedErrors++;
                            continue;
                        }

                        // Re-check legal hold at execution time.
                        if ($retentionService->hasActiveLegalHold($object) === true) {
                            $skippedHolds++;
                            continue;
                        }

                        // Update archiefstatus before deletion.
                        $retention = $object->getRetention() ?? [];
                        $retention['archiefstatus'] = 'vernietigd';
                        $object->setRetention($retention);

                        // Create audit trail entry.
                        $auditMapper->createAuditTrailEntry(
                            $object,
                            'archival.destroyed',
                            [
                                'destructionListUuid' => $listUuid,
                                'classificatie'       => $objRef['classificatie'] ?? null,
                                'approvedBy'          => array_column(
                                    $listData['approvals'] ?? [],
                                    'userId'
                                ),
                            ]
                        );

                        // Permanently delete using register, schema, uuid.
                        $deleteObject->deleteObject(
                            $object->getRegister(),
                            $object->getSchema(),
                            $uuid,
                            null,
                            false,
                            false
                        );
                        $destroyedCount++;
                    } catch (Exception $e) {
                        $skippedErrors++;
                        $logger->warning(
                            '[DestructionExecutionJob] Failed: '.$uuid.': '.$e->getMessage()
                        );
                    }//end try
                }//end foreach
            }//end foreach

            // Update destruction list status.
            $listData['status']         = 'executed';
            $listData['executedAt']     = (new DateTime())->format('c');
            $listData['destroyedCount'] = $destroyedCount;
            $listData['skippedHolds']   = $skippedHolds;
            $listData['skippedErrors']  = $skippedErrors;

            // Generate destruction certificate.
            $certificate = $retentionService->generateDestructionCertificate(
                $listData,
                $destroyedCount,
                $listData['executedAt']
            );

            if (empty($settings['archivalRegister']) === false
                && empty($settings['destructionListSchema']) === false
            ) {
                try {
                    $savedCert = $saveObject->saveObject(
                        $settings['archivalRegister'],
                        $settings['destructionListSchema'],
                        $certificate,
                        null,
                            null,
                            false,
                            false,
                            true,
                            true
                    );
                    $listData['certificateUuid'] = $savedCert->getUuid();
                } catch (Exception $e) {
                    $logger->warning('[DestructionExecutionJob] Certificate save error: '.$e->getMessage());
                }
            }

            $listObject->setObject($listData);
            $objectMapper->update($listObject);

            if ($skippedHolds > 0) {
                $this->notifySkippedHolds(listUuid: $listUuid, skippedCount: $skippedHolds, logger: $logger);
            }

            $logger->info(
                '[DestructionExecutionJob] Done: '.$destroyedCount.' destroyed, '.$skippedHolds.' held, '.$skippedErrors.' errors'
            );
        } catch (Exception $e) {
            $logger->error('[DestructionExecutionJob] Fatal: '.$e->getMessage(), ['exception' => $e]);
        }//end try
    }//end run()

    /**
     * Notify archivaris group about objects skipped due to legal holds.
     *
     * @param string          $listUuid     Destruction list UUID
     * @param int             $skippedCount Number of skipped objects
     * @param LoggerInterface $logger       Logger
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-5
     */
    private function notifySkippedHolds(
        string $listUuid,
        int $skippedCount,
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
                            'destruction_holds_skipped',
                            [
                                'listUuid'     => $listUuid,
                                'skippedCount' => $skippedCount,
                            ]
                            );
                $notificationManager->notify($notification);
            }
        } catch (Exception $e) {
            $logger->warning('[DestructionExecutionJob] Hold notify error: '.$e->getMessage());
        }//end try
    }//end notifySkippedHolds()
}//end class
