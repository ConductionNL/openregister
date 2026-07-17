<?php

/**
 * OpenRegister e-Depot Transfer Service
 *
 * Orchestrates the full e-Depot transfer pipeline: SIP package building,
 * transport, object status tracking, and audit trail logging.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-transfer-list-management
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
 * @spec openspec/specs/edepot-transfer/spec.md
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
 * Coordinates SIP package building, transport execution, per-object status
 * tracking, audit trail logging, and notifications. One run performs a single
 * transport attempt per outstanding package; durable retry/backoff is owned by
 * TransferExecutionJob (no in-process wait).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class EdepotTransferService
{

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
     * @param SipPackageBuilder     $sipBuilder            The SIP package builder.
     * @param TransferListService   $transferListService   The transfer list service.
     * @param TransferRecordService $transferRecordService Durable transfer + proof persistence.
     * @param MagicMapper           $objectMapper          The object mapper.
     * @param AuditTrailMapper      $auditTrailMapper      The audit trail mapper.
     * @param IAppConfig            $appConfig             The app configuration.
     * @param INotificationManager  $notificationManager   The notification manager.
     * @param LoggerInterface       $logger                Logger.
     */
    public function __construct(
        private readonly SipPackageBuilder $sipBuilder,
        private readonly TransferListService $transferListService,
        private readonly TransferRecordService $transferRecordService,
        private readonly MagicMapper $objectMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IAppConfig $appConfig,
        private readonly INotificationManager $notificationManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Execute a transfer for an approved transfer list (single attempt).
     *
     * Backward-compatible entry point: runs one transport attempt (attempt 1).
     * Long-horizon cross-request retry is orchestrated by
     * {@see \OCA\OpenRegister\BackgroundJob\TransferExecutionJob}, which calls
     * {@see self::executeAttempt()} directly with the current attempt number.
     *
     * @param array<string,mixed> $transferList The approved transfer list data.
     * @param TransportInterface  $transport    The transport to use.
     *
     * @return array<string,mixed> The updated transfer list with results.
     *
     * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
     *   (Requirement: One job run performs one transport attempt per package)
     */
    public function executeTransfer(array $transferList, TransportInterface $transport): array
    {
        return $this->executeAttempt(transferList: $transferList, transport: $transport, attempt: 1);
    }//end executeTransfer()

    /**
     * Run ONE transport attempt for a transfer list.
     *
     * No in-process `sleep()`: exactly one transport send per outstanding
     * package. Objects already confirmed on a prior attempt
     * (`retention.archiefstatus === 'overgebracht'`) are excluded from the
     * rebuild/resend so a retry never re-ingests them (partial-success
     * awareness). The attempt (number, timestamp, transport, per-package
     * outcome, error) is appended to the list's append-only `attempts[]`; the
     * caller (the job) decides whether to re-enqueue or escalate.
     *
     * @param array<string,mixed> $transferList The transfer list data.
     * @param TransportInterface  $transport    The transport to use.
     * @param int                 $attempt      The 1-based attempt number.
     *
     * @return array<string,mixed> The updated transfer list (status, attempts[], transferResult).
     *
     * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
     *   (Requirement: One job run performs one transport attempt per package)
     */
    public function executeAttempt(array $transferList, TransportInterface $transport, int $attempt): array
    {
        $this->logger->info(
            message: '[EdepotTransferService] Transfer attempt',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'transferId' => ($transferList['uuid'] ?? ''),
                'transport'  => $transport->getName(),
                'attempt'    => $attempt,
            ]
        );

        $transferList['status'] = TransferListService::STATUS_IN_PROGRESS;

        if ($attempt === 1) {
            $this->logTransferInitiated(transferList: $transferList, transport: $transport->getName());
        }

        // Only objects NOT yet confirmed are (re)built/sent — no double ingest.
        $outstandingRefs  = $this->outstandingObjectRefs(transferList: $transferList);
        $objectsWithFiles = $this->gatherObjectsWithFiles(objectRefs: $outstandingRefs);

        if (empty($objectsWithFiles) === true) {
            // Nothing outstanding: either everything already confirmed
            // (completed) or no loadable objects (failed).
            $transferList['status'] = $this->terminalStatusForNoOutstanding(transferList: $transferList);
            return $this->appendAttempt(
                transferList: $transferList,
                attempt: $attempt,
                transport: $transport->getName(),
                outcome: $transferList['status'],
                error: null
            );
        }

        // Build SIP package(s) in the list's chosen format (zip default | bagit).
        $format = (string) ($transferList['packageFormat'] ?? 'zip');
        try {
            $sipFiles = $this->sipBuilder->build(
                transferId: (string) $transferList['uuid'],
                objectsWithFiles: $objectsWithFiles,
                maxPackageSize: 0,
                format: $format
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[EdepotTransferService] SIP package build failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $transferList['status']         = TransferListService::STATUS_FAILED;
            $transferList['transferResult'] = [
                'error'     => 'SIP build failed: '.$e->getMessage(),
                'timestamp' => (new DateTime())->format('c'),
            ];
            $this->logTransferFailed(transferList: $transferList, error: $e->getMessage(), transport: $transport->getName());
            return $this->appendAttempt(
                transferList: $transferList,
                attempt: $attempt,
                transport: $transport->getName(),
                outcome: TransferListService::STATUS_FAILED,
                error: 'SIP build failed: '.$e->getMessage()
            );
        }//end try

        // One transport send per package — no retry loop, no sleep.
        $config     = $this->getTransportConfig();
        $allResults = [];
        foreach ($sipFiles as $sipFile) {
            $allResults[] = $this->sendOnce(transport: $transport, sipFilePath: $sipFile, config: $config);
            if (file_exists($sipFile) === true) {
                unlink($sipFile);
            }
        }

        // Process results: mark confirmed objects + create proofs + set status.
        $transferList = $this->processResults(
            transferList: $transferList,
            results: $allResults,
            objectsWithFiles: $objectsWithFiles,
            packageFormat: $format,
            transportName: $transport->getName()
        );

        $errorSummary = null;
        foreach ($allResults as $result) {
            if ($result->getErrorMessage() !== null) {
                $errorSummary = $result->getErrorMessage();
                break;
            }
        }

        $transferList = $this->appendAttempt(
            transferList: $transferList,
            attempt: $attempt,
            transport: $transport->getName(),
            outcome: (string) $transferList['status'],
            error: $errorSummary
        );

        $this->notifyTransferCompletion(transferList: $transferList);

        return $transferList;
    }//end executeAttempt()

    /**
     * The object references not yet confirmed transferred (partial-success
     * awareness): objects whose `retention.archiefstatus` is already
     * `overgebracht` are excluded so a retry never re-ingests them.
     *
     * @param array<string,mixed> $transferList The transfer list data.
     *
     * @return array<int, array<string,mixed>> The outstanding object references.
     *
     * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
     *   (Scenario: Retry excludes already-confirmed objects)
     */
    private function outstandingObjectRefs(array $transferList): array
    {
        $refs = ($transferList['objectReferences'] ?? []);
        if (is_array($refs) === false) {
            return [];
        }

        $outstanding = [];
        foreach ($refs as $ref) {
            if (is_array($ref) === false || empty($ref['uuid']) === true) {
                continue;
            }

            try {
                $object    = $this->objectMapper->find($ref['uuid']);
                $retention = ($object->getRetention() ?? []);
                if (($retention['archiefstatus'] ?? '') === 'overgebracht') {
                    // Already ingested on a prior attempt — never resend.
                    continue;
                }
            } catch (\Throwable $e) {
                // Unloadable object: keep it in the outstanding set so its
                // failure is recorded rather than silently dropped.
                $this->logger->debug(
                    message: '[EdepotTransferService] outstanding-check could not load object '.((string) $ref['uuid']),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }

            $outstanding[] = $ref;
        }//end foreach

        return $outstanding;

    }//end outstandingObjectRefs()

    /**
     * Terminal status when no objects are outstanding: `completed` when every
     * declared object is already confirmed, else `failed`.
     *
     * @param array<string,mixed> $transferList The transfer list data.
     *
     * @return string The terminal status.
     */
    private function terminalStatusForNoOutstanding(array $transferList): string
    {
        $refs = ($transferList['objectReferences'] ?? []);
        if (is_array($refs) === false || $refs === []) {
            return TransferListService::STATUS_FAILED;
        }

        foreach ($refs as $ref) {
            if (is_array($ref) === false || empty($ref['uuid']) === true) {
                continue;
            }

            try {
                $object    = $this->objectMapper->find($ref['uuid']);
                $retention = ($object->getRetention() ?? []);
                if (($retention['archiefstatus'] ?? '') !== 'overgebracht') {
                    return TransferListService::STATUS_FAILED;
                }
            } catch (\Throwable $e) {
                return TransferListService::STATUS_FAILED;
            }
        }

        return TransferListService::STATUS_COMPLETED;

    }//end terminalStatusForNoOutstanding()

    /**
     * Append one delivery attempt to the list's append-only `attempts[]`.
     *
     * @param array<string,mixed> $transferList The transfer list data.
     * @param int                 $attempt      The attempt number.
     * @param string              $transport    The transport name.
     * @param string              $outcome      The resulting status.
     * @param string|null         $error        The error message, when any.
     *
     * @return array<string,mixed> The transfer list with the appended attempt.
     *
     * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
     *   (Requirement: Append-only attempt records)
     */
    private function appendAttempt(
        array $transferList,
        int $attempt,
        string $transport,
        string $outcome,
        ?string $error
    ): array {
        $attempts = ($transferList['attempts'] ?? []);
        if (is_array($attempts) === false) {
            $attempts = [];
        }

        $attempts[] = [
            'attempt'   => $attempt,
            'timestamp' => (new DateTime())->format('c'),
            'transport' => $transport,
            'outcome'   => $outcome,
            'error'     => $error,
        ];

        $transferList['attempts'] = $attempts;

        return $transferList;

    }//end appendAttempt()

    /**
     * Send a SIP file once (no in-process retry).
     *
     * Retained for callers/tests that send a single package; the attempt
     * orchestration lives in {@see self::executeAttempt()} and the job.
     *
     * @param TransportInterface  $transport   The transport to use.
     * @param string              $sipFilePath The SIP file path.
     * @param array<string,mixed> $config      Transport configuration.
     *
     * @return TransportResult The transport result.
     *
     * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
     *   (Requirement: One job run performs one transport attempt per package)
     */
    private function sendOnce(
        TransportInterface $transport,
        string $sipFilePath,
        array $config
    ): TransportResult {
        return $transport->send($sipFilePath, $config);
    }//end sendOnce()

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
     *
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-multiple-transport-protocols-for-sip-delivery
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
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-assemble-sip-packages-for-e-depot-transfer
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-multiple-transport-protocols-for-sip-delivery
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
     * @param string                         $packageFormat    The SIP format used (zip|bagit) — recorded on proofs.
     * @param string                         $transportName    The transport name — recorded on proofs.
     *
     * @return array<string,mixed> Updated transfer list.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
     *   (Scenario: Proof created on confirmed transfer)
     */
    private function processResults(
        array $transferList,
        array $results,
        array $objectsWithFiles,
        string $packageFormat='zip',
        string $transportName=''
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
                $uuid           = $item['object']->getUuid();
                $ref            = $results[0]->getTransferReference() ?? '';
                $referenceValue = null;
                if ($overallSuccess === true) {
                    $referenceValue = $ref;
                }

                $errorValue = null;
                if ($overallSuccess !== true) {
                    $errorValue = $results[0]->getErrorMessage() ?? 'Transfer failed';
                }

                $mergedObjectResults[$uuid] = [
                    'accepted'  => $overallSuccess,
                    'reference' => $referenceValue,
                    'error'     => $errorValue,
                ];
            }//end foreach
        }//end if

        // Update each object's retention status.
        foreach ($objectsWithFiles as $item) {
            $object    = $item['object'];
            $uuid      = $object->getUuid();
            $objResult = ($mergedObjectResults[$uuid] ?? ['accepted' => false, 'reference' => null, 'error' => 'No result']);

            if ($objResult['accepted'] === true) {
                $anySuccess = true;
                $reference  = ($objResult['reference'] ?? '');
                $this->markObjectTransferred(object: $object, reference: $reference, timestamp: $now);
                $this->logObjectTransferred(object: $object, transferUuid: $transferList['uuid'], reference: $reference);
                // Durable, immutable proof-of-transfer for the confirmed object
                // (OR-AD-3) — one per confirmed object, write-once, no proof for
                // failed objects.
                $this->createProofRecord(
                    object: $object,
                    files: ($item['files'] ?? []),
                    transferList: $transferList,
                    reference: (string) $reference,
                    objResult: $objResult,
                    packageFormat: $packageFormat,
                    transportName: $transportName,
                    confirmedAt: $now
                );
                continue;
            }

            $allSuccess = false;
            $this->markObjectTransferFailed(object: $object, error: ($objResult['error'] ?? 'Unknown error'), timestamp: $now);
        }//end foreach

        // Set final transfer list status.
        $transferList['status'] = match (true) {
            $allSuccess === true => TransferListService::STATUS_COMPLETED,
            $anySuccess === true => TransferListService::STATUS_PARTIALLY_FAILED,
            default              => TransferListService::STATUS_FAILED,
        };

        if ($transferList['status'] === TransferListService::STATUS_FAILED) {
            $errorMessages = [];
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
     *
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-multiple-transport-protocols-for-sip-delivery
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
     * Create the immutable proof-of-transfer record for one confirmed object
     * (OR-AD-3) and link its UUID onto the object's retention metadata (next
     * to the existing `eDepotReferentie`, additive). Write-once + best-effort:
     * a proof-creation failure never unwinds the confirmed transfer.
     *
     * @param ObjectEntity         $object        The confirmed object.
     * @param array<int, mixed>    $files         The object's file metadata (name + checksum).
     * @param array<string, mixed> $transferList  The transfer list.
     * @param string               $reference     The e-Depot ingest reference.
     * @param array<string, mixed> $objResult     The per-object transport result.
     * @param string               $packageFormat The SIP format (zip|bagit).
     * @param string               $transportName The transport used.
     * @param string               $confirmedAt   The confirmation timestamp.
     *
     * @return void
     *
     * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
     *   (Scenario: Proof created on confirmed transfer)
     */
    private function createProofRecord(
        ObjectEntity $object,
        array $files,
        array $transferList,
        string $reference,
        array $objResult,
        string $packageFormat,
        string $transportName,
        string $confirmedAt
    ): void {
        try {
            $fileChecksums = [];
            foreach ($files as $file) {
                if (is_array($file) === true && empty($file['name']) === false) {
                    $fileChecksums[] = [
                        'name'   => (string) $file['name'],
                        'sha256' => (string) ($file['checksum'] ?? ''),
                    ];
                }
            }

            $manifestHash = hash('sha256', (string) json_encode($fileChecksums));

            $proof = $this->transferRecordService->createProof(
                proof: [
                    'objectUuid'            => (string) $object->getUuid(),
                    'transferUuid'          => (string) ($transferList['uuid'] ?? ''),
                    'eDepotReference'       => $reference,
                    'transportReceipt'      => (string) ($objResult['receipt'] ?? ($objResult['reference'] ?? '')),
                    'transportName'         => $transportName,
                    'packageId'             => (string) ($transferList['uuid'] ?? ''),
                    'packageFormat'         => $packageFormat,
                    'packageManifestSha256' => $manifestHash,
                    'fileChecksums'         => $fileChecksums,
                    'confirmedAt'           => $confirmedAt,
                ]
            );

            // Link the proof UUID onto the object's retention (additive —
            // existing eDepotReferentie/transferDate readers keep working).
            $proofUuid = (string) ($proof['uuid'] ?? '');
            if ($proofUuid !== '') {
                $retention = ($object->getRetention() ?? []);
                $retention['transferProof'] = $proofUuid;
                $object->setRetention($retention);
                $this->objectMapper->update($object);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[EdepotTransferService] proof-of-transfer creation failed for '.((string) $object->getUuid()).': '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }//end try

    }//end createProofRecord()

    /**
     * Mark an object's transfer as failed.
     *
     * @param ObjectEntity $object    The object to update.
     * @param string       $error     The error message.
     * @param string       $timestamp The failure timestamp.
     *
     * @return void
     *
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-multiple-transport-protocols-for-sip-delivery
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
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
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
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
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
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
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
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
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
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
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
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
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
     *
     * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-multiple-transport-protocols-for-sip-delivery
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
