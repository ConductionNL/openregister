<?php

/**
 * FileBatchHandler
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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use Psr\Log\LoggerInterface;

/**
 * Handles batch file operations.
 *
 * Provides a single endpoint for performing publish, depublish, delete, and label
 * operations on multiple files at once, replacing N sequential HTTP calls.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileBatchHandler
{

    /**
     * Maximum number of files per batch request.
     *
     * @var int
     */
    private const MAX_BATCH_SIZE = 100;

    /**
     * Allowed batch actions.
     *
     * @var array<string>
     */
    private const ALLOWED_ACTIONS = ['publish', 'depublish', 'delete', 'label'];

    /**
     * Reference to FileService for cross-handler coordination.
     *
     * @var FileService|null
     */
    private ?FileService $fileService = null;

    /**
     * Constructor for FileBatchHandler.
     *
     * @param FilePublishingHandler $publishingHandler Publishing handler for publish/depublish.
     * @param DeleteFileHandler     $deleteHandler     Delete handler for file deletion.
     * @param TaggingHandler        $taggingHandler    Tagging handler for label operations.
     * @param LoggerInterface       $logger            Logger for logging operations.
     */
    public function __construct(
        private readonly FilePublishingHandler $publishingHandler,
        private readonly DeleteFileHandler $deleteHandler,
        private readonly TaggingHandler $taggingHandler,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Set the FileService instance for cross-handler coordination.
     *
     * @param FileService $fileService The file service instance.
     *
     * @return void
     */
    public function setFileService(FileService $fileService): void
    {
        $this->fileService = $fileService;
    }//end setFileService()

    /**
     * Execute a batch operation on multiple files.
     *
     * @param ObjectEntity $object  The object entity owning the files.
     * @param string       $action  The batch action (publish|depublish|delete|label).
     * @param array<int>   $fileIds Array of file IDs to operate on.
     * @param array        $params  Additional parameters (e.g., labels for label action).
     *
     * @return array{results: array, summary: array{total: int, succeeded: int, failed: int}} Batch results.
     *
     * @throws Exception If validation fails.
     */
    public function executeBatch(
        ObjectEntity $object,
        string $action,
        array $fileIds,
        array $params = []
    ): array {
        // Validate action.
        if (in_array($action, self::ALLOWED_ACTIONS, true) === false) {
            throw new Exception(
                'Invalid batch action. Allowed: ' . implode(', ', self::ALLOWED_ACTIONS)
            );
        }

        // Validate batch size.
        if (count($fileIds) > self::MAX_BATCH_SIZE) {
            throw new Exception(
                'Batch operations are limited to ' . self::MAX_BATCH_SIZE . ' files per request'
            );
        }

        if (empty($fileIds) === true) {
            throw new Exception('No file IDs provided');
        }

        $results   = [];
        $succeeded = 0;
        $failed    = 0;

        foreach ($fileIds as $fileId) {
            try {
                $this->executeAction($object, $action, (int) $fileId, $params);
                $results[] = ['fileId' => $fileId, 'success' => true];
                $succeeded++;
            } catch (Exception $e) {
                $results[] = ['fileId' => $fileId, 'success' => false, 'error' => $e->getMessage()];
                $failed++;
                $this->logger->warning(
                    message: "[FileBatchHandler] Batch {$action} failed for file {$fileId}: " . $e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }//end try
        }//end foreach

        return [
            'results' => $results,
            'summary' => [
                'total'     => count($fileIds),
                'succeeded' => $succeeded,
                'failed'    => $failed,
            ],
        ];
    }//end executeBatch()

    /**
     * Execute a single batch action on one file.
     *
     * @param ObjectEntity $object The object entity.
     * @param string       $action The action to execute.
     * @param int          $fileId The file ID.
     * @param array        $params Additional parameters.
     *
     * @return void
     *
     * @throws Exception If the action fails.
     */
    private function executeAction(
        ObjectEntity $object,
        string $action,
        int $fileId,
        array $params
    ): void {
        if ($this->fileService === null) {
            throw new Exception('FileService not initialized in FileBatchHandler');
        }

        switch ($action) {
            case 'publish':
                $this->fileService->publishFile(object: $object, file: $fileId);
                break;
            case 'depublish':
                $this->fileService->unpublishFile(object: $object, filePath: $fileId);
                break;
            case 'delete':
                $this->fileService->deleteFile(file: $fileId, object: $object);
                break;
            case 'label':
                $labels = $params['labels'] ?? [];
                $this->fileService->updateFile(
                    filePath: $fileId,
                    content: null,
                    tags: $labels,
                    object: $object
                );
                break;
            default:
                throw new Exception("Unknown batch action: {$action}");
        }//end switch
    }//end executeAction()
}//end class
