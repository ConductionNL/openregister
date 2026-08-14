<?php

/**
 * FileAuditHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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

/**
 * Handles audit logging for file actions.
 *
 * Creates audit trail entries for file downloads (authenticated and anonymous),
 * bulk downloads, and other file actions (rename, copy, move, lock, unlock,
 * version restore) via the shared AuditTrailMapper.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
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
     * @param ObjectEntity $object   The parent object entity.
     * @param int          $fileId   The file ID that was downloaded.
     * @param string       $fileName The file name.
     * @param int          $fileSize The file size in bytes.
     * @param string       $mimeType The file MIME type.
     *
     * @return void
     *
     * @spec openspec/changes/file-actions/tasks.md#phase-9-download-audit-logging
     */
    public function logDownload(
        ObjectEntity $object,
        int $fileId,
        string $fileName,
        int $fileSize,
        string $mimeType
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

            $this->persistEntry(object: $object, action: 'file.downloaded', data: $data);
        } catch (Exception $e) {
            // Audit logging should never break the download flow.
            $this->logger->warning(
                message: '[FileAuditHandler] Failed to log download: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }//end try
    }//end logDownload()

    /**
     * Log a bulk download event (ZIP archive).
     *
     * @param ObjectEntity $object    The parent object entity.
     * @param array        $fileIds   Array of file IDs included in the archive.
     * @param array        $fileNames Array of file names included in the archive.
     *
     * @return void
     *
     * @spec openspec/changes/file-actions/tasks.md#phase-9-download-audit-logging
     */
    public function logBulkDownload(ObjectEntity $object, array $fileIds, array $fileNames): void
    {
        $this->persistEntry(
            object: $object,
            action: 'file.bulk_downloaded',
            data: ['fileIds' => $fileIds, 'fileNames' => $fileNames]
        );
    }//end logBulkDownload()

    /**
     * Log a generic file action event (rename, copy, move, lock, unlock, version restore, ...).
     *
     * @param ObjectEntity $object The object the action relates to.
     * @param string       $action The audit action identifier (e.g. "file.renamed").
     * @param array        $data   Additional context data for the entry.
     *
     * @return void
     *
     * @spec openspec/changes/file-actions/tasks.md
     */
    public function logFileAction(ObjectEntity $object, string $action, array $data=[]): void
    {
        $this->persistEntry(object: $object, action: $action, data: $data);
    }//end logFileAction()

    /**
     * Persist an audit trail entry, never letting audit failures break the calling flow.
     *
     * @param ObjectEntity $object The object the entry relates to.
     * @param string       $action The audit action identifier.
     * @param array        $data   Additional context data for the entry.
     *
     * @return void
     */
    private function persistEntry(ObjectEntity $object, string $action, array $data): void
    {
        try {
            $this->auditTrailMapper->createAuditTrailEntry(object: $object, action: $action, context: $data);
        } catch (Exception $e) {
            // Audit logging should never break the calling file operation.
            $this->logger->warning(
                message: "[FileAuditHandler] Failed to log {$action}: ".$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }
    }//end persistEntry()

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
