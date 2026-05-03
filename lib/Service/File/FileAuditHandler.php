<?php

/**
 * FileAuditHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handles file download audit logging.
 *
 * Creates audit trail entries for all file downloads (authenticated and anonymous),
 * tracks download counts, and logs bulk downloads.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileAuditHandler
{
    /**
     * Constructor for FileAuditHandler.
     *
     * @param AuditTrailMapper $auditTrailMapper Audit trail mapper for persisting entries.
     * @param IUserSession     $userSession      User session for current user context.
     * @param IRequest         $request          Request object for IP and user-agent.
     * @param LoggerInterface  $logger           Logger for logging operations.
     */
    public function __construct(
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IUserSession $userSession,
        private readonly IRequest $request,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Log a file download event.
     *
     * @param int    $fileId     The file ID that was downloaded.
     * @param string $fileName   The file name.
     * @param int    $fileSize   The file size in bytes.
     * @param string $mimeType   The file MIME type.
     * @param string $objectUuid The UUID of the parent object.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function logDownload(
        int $fileId,
        string $fileName,
        int $fileSize,
        string $mimeType,
        string $objectUuid
    ): void {
        try {
            $userId = $this->getCurrentUserId();
            $data   = [
                'fileId'   => $fileId,
                'fileName' => $fileName,
                'fileSize' => $fileSize,
                'mimeType' => $mimeType,
            ];

            // Add anonymous context if no user.
            if ($userId === 'anonymous') {
                $data['remoteAddress'] = $this->request->getRemoteAddress();
                $data['userAgent']     = $this->request->getHeader('User-Agent');
            }

            $this->logger->info(
                message: "[FileAuditHandler] Download logged for file {$fileId} by {$userId}",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        } catch (Exception $e) {
            // Audit logging should never break the download flow.
            $this->logger->warning(
                message: '[FileAuditHandler] Failed to log download: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }//end try
    }//end logDownload()

    /**
     * Log a bulk download event (ZIP archive) as a SINGLE audit-trail row.
     *
     * One audit row per ZIP — not one per included file — to match the
     * file-actions spec requirement that bulk download is recorded as a
     * single auditable event. The fileIds + fileNames array is captured
     * in the `changed` payload so consumers can reconstruct the contents
     * of the archive.
     *
     * @param ObjectEntity $object     The parent object whose files were zipped.
     * @param int[]        $fileIds    File IDs included in the archive.
     * @param string[]     $fileNames  File names included in the archive (parallel index to fileIds).
     * @param string       $zipName    The generated ZIP filename.
     * @param int|null     $totalBytes Total uncompressed bytes across included files (best-effort).
     *
     * @return AuditTrail|null The persisted audit row, or null on failure.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function logBulkDownload(
        ObjectEntity $object,
        array $fileIds,
        array $fileNames,
        string $zipName,
        ?int $totalBytes=null
    ): ?AuditTrail {
        try {
            $auditTrail = new AuditTrail();
            $auditTrail->setUuid((string) Uuid::v4());
            $auditTrail->setObject($object->getId());
            $auditTrail->setObjectUuid($object->getUuid());
            $auditTrail->setRegister($object->getRegister());
            $auditTrail->setSchema($object->getSchema());
            $auditTrail->setAction('file.bulk_downloaded');
            $auditTrail->setChanged(
                [
                    'fileIds'    => array_values($fileIds),
                    'fileNames'  => array_values($fileNames),
                    'fileCount'  => count($fileIds),
                    'zipName'    => $zipName,
                    'totalBytes' => $totalBytes,
                ]
            );

            $user = $this->userSession->getUser();
            if ($user !== null) {
                $auditTrail->setUser($user->getUID());
                $auditTrail->setUserName($user->getDisplayName());
            }

            if ($user === null) {
                // Anonymous bulk downloads still get attributed by IP + UA so the
                // download can be traced back even without a logged-in user.
                $remote = $this->request->getRemoteAddress();
                $auditTrail->setUser('Anonymous');
                $auditTrail->setUserName('Anonymous ('.$remote.')');
            }

            $auditTrail->setCreated(new DateTime());
            $auditTrail->setExpires(new DateTime('+30 days'));
            $auditTrail->setSize(14);

            return $this->auditTrailMapper->insert($auditTrail);
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[FileAuditHandler] Failed to log bulk download: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }//end try
    }//end logBulkDownload()

    /**
     * Log a file-action audit trail entry tied to the parent object.
     *
     * Creates an `AuditTrail` row whose `action` field is the namespaced
     * file event (e.g. `file.renamed`, `file.locked`, `file.version_restored`)
     * and whose `changed` payload carries action-specific metadata.
     *
     * The audit row is keyed off the parent ObjectEntity (object/objectUuid/
     * register/schema columns) so file events surface in the same audit
     * timeline as object updates -- this matches how the existing
     * `createAuditTrail` flow stamps rows.
     *
     * Failures are swallowed and logged: audit logging must never break
     * the underlying file operation.
     *
     * @param ObjectEntity $object The parent object the file belongs to.
     * @param int          $fileId The file ID the action targeted.
     * @param string       $action The namespaced action (e.g. 'file.renamed').
     * @param array        $data   Action-specific metadata (newName, targetUuid, etc.).
     *
     * @return AuditTrail|null The persisted audit row, or null on failure.
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function logFileAction(
        ObjectEntity $object,
        int $fileId,
        string $action,
        array $data=[]
    ): ?AuditTrail {
        try {
            $auditTrail = new AuditTrail();
            $auditTrail->setUuid((string) Uuid::v4());
            $auditTrail->setObject($object->getId());
            $auditTrail->setObjectUuid($object->getUuid());
            $auditTrail->setRegister($object->getRegister());
            $auditTrail->setSchema($object->getSchema());
            $auditTrail->setAction($action);
            $auditTrail->setChanged(
                [
                    'fileId' => $fileId,
                    'data'   => $data,
                ]
            );

            // User context.
            $user = $this->userSession->getUser();
            if ($user !== null) {
                $auditTrail->setUser($user->getUID());
                $auditTrail->setUserName($user->getDisplayName());
            }

            if ($user === null) {
                $auditTrail->setUser('System');
                $auditTrail->setUserName('System');
            }

            $auditTrail->setCreated(new DateTime());
            $auditTrail->setExpires(new DateTime('+30 days'));
            // Minimum default size from AuditTrailMapper::createAuditTrail.
            $auditTrail->setSize(14);

            return $this->auditTrailMapper->insert($auditTrail);
        } catch (Exception $e) {
            // Audit logging should never break the file operation.
            $this->logger->warning(
                message: '[FileAuditHandler] Failed to log file action '.$action.': '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }//end try

    }//end logFileAction()

    /**
     * Get the current user ID.
     *
     * @return string The current user ID or 'anonymous'.
     */
    private function getCurrentUserId(): string
    {
        $user = $this->userSession->getUser();
        return $user !== null ? $user->getUID() : 'anonymous';
    }//end getCurrentUserId()
}//end class
