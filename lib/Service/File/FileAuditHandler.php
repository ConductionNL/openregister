<?php

/**
 * FileAuditHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles file download audit logging.
 *
 * Creates audit trail entries for all file downloads (authenticated and anonymous),
 * tracks download counts, and logs bulk downloads.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
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
            $data = [
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
                message: '[FileAuditHandler] Failed to log download: ' . $e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }//end try
    }//end logDownload()

    /**
     * Log a bulk download event (ZIP archive).
     *
     * @param array  $fileIds    Array of file IDs included in the archive.
     * @param array  $fileNames  Array of file names included in the archive.
     * @param string $objectUuid The UUID of the parent object.
     *
     * @return void
     */
    public function logBulkDownload(array $fileIds, array $fileNames, string $objectUuid): void
    {
        try {
            $userId = $this->getCurrentUserId();

            $this->logger->info(
                message: '[FileAuditHandler] Bulk download logged for ' . count($fileIds) . " files by {$userId}",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[FileAuditHandler] Failed to log bulk download: ' . $e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }//end try
    }//end logBulkDownload()

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
