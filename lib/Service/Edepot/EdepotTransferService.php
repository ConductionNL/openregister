<?php

/**
 * OpenRegister e-Depot Transfer Service
 *
 * Orchestrates the full e-Depot transfer pipeline: SIP package building,
 * transport, object status tracking, and audit trail logging.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-22
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-24
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-38
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\TransportResult;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Orchestrator for e-Depot transfer operations.
 *
 * Coordinates SIP package building, transport execution with retry logic,
 * per-object status tracking, audit trail logging, and notifications.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class EdepotTransferService
{

    /**
     * Maximum number of transport retries.
     */
    private const MAX_RETRIES = 3;

    /**
     * Retry backoff intervals in seconds: 30s, 120s, 480s.
     *
     * @var array<int, int>
     */
    private const RETRY_BACKOFF = [30, 120, 480];

    /**
     * Available SIP profiles.
     *
     * @var array<string, string>
     */
    public const AVAILABLE_PROFILES = [
        'nationaal-archief-v2' => 'Nationaal Archief v2',
        'tresoar-v1'           => 'Tresoar v1',
        'default'              => 'Default MDTO Profile',
    ];

    /**
     * Constructor.
     *
     * @param SipPackageBuilder    $sipBuilder          The SIP package builder.
     * @param TransferListService  $transferListService The transfer list service.
     * @param MagicMapper          $objectMapper        The object mapper.
     * @param AuditTrailMapper     $auditTrailMapper    The audit trail mapper.
     * @param IAppConfig           $appConfig           The app configuration.
     * @param INotificationManager $notificationManager The notification manager.
     * @param LoggerInterface      $logger              Logger.
     */
    public function __construct(
        private readonly SipPackageBuilder $sipBuilder,
        private readonly TransferListService $transferListService,
        private readonly MagicMapper $objectMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IAppConfig $appConfig,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Execute a transfer for an approved transfer list.
     *
     * @param array<string,mixed> $transferList The approved transfer list data.
     * @param TransportInterface  $transport    The transport to use.
     *
     * @return array<string,mixed> The updated transfer list with results.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-22
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-30/tasks.md#task-38
     */
    public function executeTransfer(array $transferList, TransportInterface $transport): array
    {
        $this->logger->info(
            message: '[EdepotTransferService] Starting transfer execution',
            context: [
                'transferId' => $transferList['uuid'],
                'transport'  => $transport->getName(),
                'objects'    => count($transferList['objectReferences']),
            ]
        );

        $transferList['status'] = TransferListService::STATUS_IN_PROGRESS;

        // Log audit: transfer initiated.
        $this->logTransferInitiated(transferList: $transferList, transport: $transport->getName());

        // Gather objects and their files.
        $objectsWithFiles = $this->gatherObjectsWithFiles(objectRefs: $transferList['objectReferences']);

        if (empty($objectsWithFiles) === true) {
            $transferList['status']         = TransferListService::STATUS_FAILED;
            $transferList['transferResult'] = [
                'error'     => 'No valid objects found for transfer',
                'timestamp' => (new DateTime())->format('c'),
            ];
            return $transferList;
        }

        // Build SIP package(s).
        try {
            $sipFiles = $this->sipBuilder->build($transferList['uuid'], $objectsWithFiles);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[EdepotTransferService] SIP package build failed',
                context: ['error' => $e->getMessage()]
            );
            $transferList['status']         = TransferListService::STATUS_FAILED;
            $transferList['transferResult'] = [
                'error'     => 'SIP build failed: '.$e->getMessage(),
                'timestamp' => (new DateTime())->format('c'),
            ];
            $this->logTransferFailed(transferList: $transferList, error: $e->getMessage(), transport: $transport->getName());
            return $transferList;
        }

        // Send each SIP package with retry logic.
        $config     = $this->getTransportConfig();
        $allResults = [];

        foreach ($sipFiles as $sipFile) {
            $result       = $this->sendWithRetry(transport: $transport, sipFilePath: $sipFile, config: $config);
            $allResults[] = $result;

            // Clean up temp file.
            if (file_exists($sipFile) === true) {
                unlink($sipFile);
            }
        }

        // Process results and update object statuses.
        $transferList = $this->processResults(transferList: $transferList, results: $allResults, objectsWithFiles: $objectsWithFiles);

        // Send notification.
        $this->notifyTransferCompletion(transferList: $transferList);

        return $transferList;
    }//end executeTransfer()

    /**
     * Send a SIP file with retry logic.
     *
     * @param TransportInterface  $transport   The transport to use.
     * @param string              $sipFilePath The SIP file path.
     * @param array<string,mixed> $config      Transport configuration.
     *
     * @return TransportResult The transport result.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-21
     */
    private function sendWithRetry(
        TransportInterface $transport,
        string $sipFilePath,
        array $config
    ): TransportResult {
        $lastResult = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $backoff = (self::RETRY_BACKOFF[($attempt - 1)] ?? 480);
                $this->logger->info(
                    message: '[EdepotTransferService] Retrying transfer',
                    context: [
                        'attempt' => $attempt,
                        'backoff' => $backoff,
                    ]
                );
                sleep($backoff);
            }

            $lastResult = $transport->send($sipFilePath, $config);

            if ($lastResult->isSuccess() === true || $lastResult->isPartialSuccess() === true) {
                return $lastResult;
            }
        }

        return $lastResult ?? new TransportResult(
            success: false,
            errorMessage: 'All retry attempts exhausted'
        );
    }//end sendWithRetry()

    /**
     * Gather objects and their file metadata for SIP building.
     *
     * @param array<int, array{uuid: string, schema: int|null, register: int|null}> $objectRefs Object references.
     *
     * @return array<int, array{
     *     object: ObjectEntity,
     *     files: array<int, array{
     *         name: string,
     *         size: int,
     *         format: string,
     *         checksum: string,
     *         path: string,
     *         isRendition: bool
     *     }>
     * }> Objects with file metadata.
     */
    private function gatherObjectsWithFiles(array $objectRefs): array
    {
        $result = [];

        foreach ($objectRefs as $ref) {
            try {
                $object = $this->objectMapper->find($ref['uuid']);

                // Get files associated with this object.
                // In the current implementation, files are tracked in Nextcloud Files.
                // This is a simplified version that creates metadata from the object's file references.
                $files = $this->getObjectFiles(object: $object);

                $result[] = [
                    'object' => $object,
                    'files'  => $files,
                ];
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[EdepotTransferService] Could not load object for transfer',
                    context: [
                        'uuid'  => $ref['uuid'],
                        'error' => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        return $result;
    }//end gatherObjectsWithFiles()

    /**
     * Get file metadata for an object.
     *
     * @param ObjectEntity $object The object.
     *
     * @return array<int, array{
     *     name: string,
     *     size: int,
     *     format: string,
     *     checksum: string,
     *     path: string,
     *     isRendition: bool
     * }> File metadata array.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-21
     */
    private function getObjectFiles(ObjectEntity $object): array
    {
        $files      = [];
        $objectData = ($object->getObject() ?? []);
        $fileRefs   = ($objectData['_files'] ?? $objectData['bijlagen'] ?? []);

        if (is_array($fileRefs) === false) {
            return $files;
        }

        foreach ($fileRefs as $fileRef) {
            if (is_array($fileRef) === false) {
                continue;
            }

            $path = ($fileRef['path'] ?? '');
            if (empty($path) === true || file_exists($path) === false) {
                continue;
            }

            $files[] = [
                'name'        => ($fileRef['name'] ?? basename($path)),
                'size'        => (int) ($fileRef['size'] ?? filesize($path)),
                'format'      => ($fileRef['mimeType'] ?? ($fileRef['format'] ?? 'application/octet-stream')),
                'checksum'    => ($fileRef['checksum'] ?? hash_file('sha256', $path)),
                'path'        => $path,
                'isRendition' => (bool) ($fileRef['isRendition'] ?? false),
            ];
        }

        return $files;
    }//end getObjectFiles()

    /**
     * Process transport results and update object statuses.
     *
     * @param array<string,mixed>            $transferList     The transfer list.
     * @param array<int,TransportResult>     $results          Transport results.
     * @param array<int,array<string,mixed>> $objectsWithFiles The objects with files.
     *
     * @return array<string,mixed> Updated transfer list.
     */
    private function processResults(
        array $transferList,
        array $results,
        array $objectsWithFiles
    ): array {
        $allSuccess = true;
        $anySuccess = false;
        $now        = (new DateTime())->format('c');

        // Collect all object results.
        $mergedObjectResults = [];
        foreach ($results as $result) {
            foreach ($result->getObjectResults() as $uuid => $objResult) {
                $mergedObjectResults[$uuid] = $objResult;
            }
        }

        // If transport provides no per-object results, use overall success for all objects.
        if (empty($mergedObjectResults) === true) {
            $overallSuccess = true;
            foreach ($results as $result) {
                if ($result->isSuccess() === false) {
                    $overallSuccess = false;
                    break;
                }
            }

            foreach ($objectsWithFiles as $item) {
                $uuid = $item['object']->getUuid();
                $ref  = $results[0]->getTransferReference() ?? '';
                $mergedObjectResults[$uuid] = [
                    'accepted'  => $overallSuccess,
                    'reference' => ($overallSuccess === true) ? $ref : null,
                    'error'     => ($overallSuccess === true) ? null : ($results[0]->getErrorMessage() ?? 'Transfer failed'),
                ];
            }
        }

        // Update each object's retention status.
        foreach ($objectsWithFiles as $item) {
            $object    = $item['object'];
            $uuid      = $object->getUuid();
            $objResult = ($mergedObjectResults[$uuid] ?? ['accepted' => false, 'reference' => null, 'error' => 'No result']);

            if ($objResult['accepted'] === true) {
                $anySuccess = true;
                $this->markObjectTransferred(object: $object, reference: ($objResult['reference'] ?? ''), timestamp: $now);
                $this->logObjectTransferred(object: $object, transferUuid: $transferList['uuid'], reference: ($objResult['reference'] ?? ''));
            } else {
                $allSuccess = false;
                $this->markObjectTransferFailed(object: $object, error: ($objResult['error'] ?? 'Unknown error'), timestamp: $now);
            }
        }

        // Set final transfer list status.
        if ($allSuccess === true) {
            $transferList['status'] = TransferListService::STATUS_COMPLETED;
        } else if ($anySuccess === true) {
            $transferList['status'] = TransferListService::STATUS_PARTIALLY_FAILED;
        } else {
            $transferList['status'] = TransferListService::STATUS_FAILED;
            $errorMessages          = [];
            foreach ($results as $result) {
                if ($result->getErrorMessage() !== null) {
                    $errorMessages[] = $result->getErrorMessage();
                }
            }

            $this->logTransferFailed(
                transferList: $transferList,
                error: implode('; ', $errorMessages),
                transport: ''
            );
        }

        $transferList['transferResult'] = [
            'completedAt'   => $now,
            'objectResults' => $mergedObjectResults,
        ];

        return $transferList;
    }//end processResults()

    /**
     * Mark an object as successfully transferred.
     *
     * @param ObjectEntity $object    The object to update.
     * @param string       $reference The e-Depot reference identifier.
     * @param string       $timestamp The transfer timestamp.
     *
     * @return void
     */
    private function markObjectTransferred(ObjectEntity $object, string $reference, string $timestamp): void
    {
        $retention = ($object->getRetention() ?? []);
        $retention['archiefstatus']    = 'overgebracht';
        $retention['eDepotReferentie'] = $reference;
        $retention['transferDate']     = $timestamp;
        $object->setRetention($retention);

        try {
            $this->objectMapper->update($object);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[EdepotTransferService] Failed to update object status to overgebracht',
                context: [
                    'uuid'  => $object->getUuid(),
                    'error' => $e->getMessage(),
                ]
            );
        }
    }//end markObjectTransferred()

    /**
     * Mark an object's transfer as failed.
     *
     * @param ObjectEntity $object    The object to update.
     * @param string       $error     The error message.
     * @param string       $timestamp The failure timestamp.
     *
     * @return void
     */
    private function markObjectTransferFailed(ObjectEntity $object, string $error, string $timestamp): void
    {
        $retention = ($object->getRetention() ?? []);

        if (isset($retention['transferErrors']) === false || is_array($retention['transferErrors']) === false) {
            $retention['transferErrors'] = [];
        }

        $retention['transferErrors'][] = [
            'error'     => $error,
            'timestamp' => $timestamp,
        ];

        $object->setRetention($retention);

        try {
            $this->objectMapper->update($object);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[EdepotTransferService] Failed to update object transfer error',
                context: [
                    'uuid'  => $object->getUuid(),
                    'error' => $e->getMessage(),
                ]
            );
        }
    }//end markObjectTransferFailed()

    /**
     * Get the transport configuration from app settings.
     *
     * @return array<string,mixed> The transport configuration.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-23
     */
    public function getTransportConfig(): array
    {
        return [
            'endpointUrl'        => $this->appConfig->getValueString('openregister', 'edepot_endpoint_url', ''),
            'authenticationType' => $this->appConfig->getValueString('openregister', 'edepot_auth_type', ''),
            'apiKey'             => $this->appConfig->getValueString('openregister', 'edepot_api_key', ''),
            'bearerToken'        => $this->appConfig->getValueString('openregister', 'edepot_bearer_token', ''),
            'targetArchive'      => $this->appConfig->getValueString('openregister', 'edepot_target_archive', ''),
            'sipProfile'         => $this->appConfig->getValueString('openregister', 'edepot_sip_profile', 'default'),
            'transport'          => $this->appConfig->getValueString('openregister', 'edepot_transport', 'rest_api'),
            'host'               => $this->appConfig->getValueString('openregister', 'edepot_sftp_host', ''),
            'port'               => $this->appConfig->getValueString('openregister', 'edepot_sftp_port', '22'),
            'username'           => $this->appConfig->getValueString('openregister', 'edepot_sftp_username', ''),
            'password'           => $this->appConfig->getValueString('openregister', 'edepot_sftp_password', ''),
            'keyPath'            => $this->appConfig->getValueString('openregister', 'edepot_sftp_key_path', ''),
            'remotePath'         => $this->appConfig->getValueString('openregister', 'edepot_sftp_remote_path', '/'),
            'sourceId'           => $this->appConfig->getValueString('openregister', 'edepot_openconnector_source_id', ''),
            'baseUrl'            => $this->appConfig->getValueString('openregister', 'edepot_openconnector_base_url', ''),
        ];
    }//end getTransportConfig()

    /**
     * Get available SIP profile names.
     *
     * @return array<string, string> Map of profile ID to display name.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-23
     */
    public function getAvailableProfiles(): array
    {
        return self::AVAILABLE_PROFILES;
    }//end getAvailableProfiles()

    /**
     * Validate a SIP profile name.
     *
     * @param string $profileName The profile name to validate.
     *
     * @return bool True if valid.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-23
     */
    public function isValidProfile(string $profileName): bool
    {
        return isset(self::AVAILABLE_PROFILES[$profileName]);
    }//end isValidProfile()

    /**
     * Log audit trail: transfer initiated.
     *
     * @param array<string,mixed> $transferList The transfer list.
     * @param string              $transport    The transport protocol name.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-24
     */
    private function logTransferInitiated(array $transferList, string $transport): void
    {
        $this->logger->info(
            message: '[EdepotTransferService] Audit: archival.transfer_initiated',
            context: [
                'action'        => 'archival.transfer_initiated',
                'transferUuid'  => $transferList['uuid'],
                'objectCount'   => count($transferList['objectReferences']),
                'transport'     => $transport,
                'targetArchive' => $this->appConfig->getValueString('openregister', 'edepot_target_archive', ''),
            ]
        );
    }//end logTransferInitiated()

    /**
     * Log audit trail: object transferred.
     *
     * @param ObjectEntity $object       The transferred object.
     * @param string       $transferUuid The transfer list UUID.
     * @param string       $reference    The e-Depot reference.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-24
     */
    private function logObjectTransferred(ObjectEntity $object, string $transferUuid, string $reference): void
    {
        try {
            $this->auditTrailMapper->createAuditTrail(
                old: $object,
                new: $object,
                action: 'archival.transferred'
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[EdepotTransferService] Failed to create audit trail for transfer',
                context: [
                    'uuid'  => $object->getUuid(),
                    'error' => $e->getMessage(),
                ]
            );
        }

        $this->logger->info(
            message: '[EdepotTransferService] Audit: archival.transferred',
            context: [
                'action'          => 'archival.transferred',
                'objectUuid'      => $object->getUuid(),
                'transferUuid'    => $transferUuid,
                'eDepotReference' => $reference,
            ]
        );
    }//end logObjectTransferred()

    /**
     * Log audit trail: transfer failed.
     *
     * @param array<string,mixed> $transferList The transfer list.
     * @param string              $error        Error details.
     * @param string              $transport    Transport protocol.
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-24
     */
    private function logTransferFailed(array $transferList, string $error, string $transport): void
    {
        $this->logger->error(
            message: '[EdepotTransferService] Audit: archival.transfer_failed',
            context: [
                'action'       => 'archival.transfer_failed',
                'transferUuid' => $transferList['uuid'],
                'error'        => $error,
                'transport'    => $transport,
                'failedCount'  => count($transferList['objectReferences']),
            ]
        );
    }//end logTransferFailed()

    /**
     * Send notification on transfer completion.
     *
     * @param array<string,mixed> $transferList The completed transfer list.
     *
     * @return void
     */
    private function notifyTransferCompletion(array $transferList): void
    {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('openregister');
            $notification->setUser('admin');
            $notification->setDateTime(new DateTime());
            $notification->setObject('transfer_result', $transferList['uuid']);
            $notification->setSubject(
                    'edepot_transfer_completed',
                    [
                        'uuid'   => $transferList['uuid'],
                        'status' => $transferList['status'],
                    ]
                    );
            $this->notificationManager->notify($notification);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[EdepotTransferService] Failed to send completion notification',
                context: ['error' => $e->getMessage()]
            );
        }
    }//end notifyTransferCompletion()
}//end class
