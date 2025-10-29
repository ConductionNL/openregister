<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\FileText;
use OCA\OpenRegister\Db\FileTextMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * FileTextService
 * 
 * Service for managing file text extraction and storage.
 * 
 * @category Service
 * @package  OCA\OpenRegister\Service
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 */
class FileTextService
{
    /** @var array<string> Supported text extraction file types */
    private const SUPPORTED_MIME_TYPES = [
        'text/plain',
        'text/markdown',
        'text/html',
        'text/csv',
        'application/json',
        'application/xml',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
    ];

    /**
     * Constructor
     *
     * @param FileTextMapper     $fileTextMapper File text mapper
     * @param FileMapper         $fileMapper     File mapper
     * @param SolrFileService    $solrFileService SOLR file service
     * @param IRootFolder        $rootFolder     Root folder
     * @param LoggerInterface    $logger         Logger
     */
    public function __construct(
        private readonly FileTextMapper $fileTextMapper,
        private readonly FileMapper $fileMapper,
        private readonly SolrFileService $solrFileService,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Extract and store file text
     *
     * @param int $fileId Nextcloud file ID
     * 
     * @return array{success: bool, fileText?: FileText, error?: string}
     */
    public function extractAndStoreFileText(int $fileId): array
    {
        $this->logger->info('[FileTextService] Starting text extraction', ['file_id' => $fileId]);

        try {
            // Check if already exists
            $existingFileText = null;
            try {
                $existingFileText = $this->fileTextMapper->findByFileId($fileId);
                $this->logger->debug('[FileTextService] Found existing file text record', [
                    'file_id' => $fileId,
                    'status' => $existingFileText->getExtractionStatus(),
                ]);
            } catch (DoesNotExistException $e) {
                // No existing record, will create new one
            }

            // Get file from Nextcloud
            $file = $this->getFileNode($fileId);
            if (!$file) {
                throw new Exception("File not found: $fileId");
            }

            // Check MIME type
            $mimeType = $file->getMimeType();
            if (!$this->isSupportedMimeType($mimeType)) {
                $this->logger->info('[FileTextService] Unsupported MIME type', [
                    'file_id' => $fileId,
                    'mime_type' => $mimeType,
                ]);
                
                if ($existingFileText) {
                    $existingFileText->setExtractionStatus('skipped');
                    $existingFileText->setExtractionError("Unsupported MIME type: $mimeType");
                    $existingFileText->setUpdatedAt(new DateTime());
                    $this->fileTextMapper->update($existingFileText);
                    return ['success' => false, 'error' => 'Unsupported MIME type', 'fileText' => $existingFileText];
                }
                
                return ['success' => false, 'error' => 'Unsupported MIME type'];
            }

            // Calculate checksum
            $checksum = md5($file->getContent());

            // Check if extraction needed (file changed)
            if ($existingFileText) {
                if ($existingFileText->getFileChecksum() === $checksum && 
                    $existingFileText->getExtractionStatus() === 'completed') {
                    $this->logger->debug('[FileTextService] File unchanged, using cached text', [
                        'file_id' => $fileId,
                    ]);
                    return ['success' => true, 'fileText' => $existingFileText];
                }
            }

            // Create or update file text record
            if ($existingFileText) {
                $fileText = $existingFileText;
                $fileText->setUpdatedAt(new DateTime());
            } else {
                $fileText = new FileText();
                $fileText->setFileId($fileId);
                $fileText->setCreatedAt(new DateTime());
                $fileText->setUpdatedAt(new DateTime());
            }

            // Set file metadata
            $fileText->setFilePath($file->getPath());
            $fileText->setFileName($file->getName());
            $fileText->setMimeType($mimeType);
            $fileText->setFileSize($file->getSize());
            $fileText->setFileChecksum($checksum);
            $fileText->setExtractionStatus('processing');
            $fileText->setExtractionError(null);

            // Save initial status
            if ($existingFileText) {
                $this->fileTextMapper->update($fileText);
            } else {
                $this->fileTextMapper->insert($fileText);
            }

            // Extract text using SolrFileService
            $this->logger->debug('[FileTextService] Extracting text from file', ['file_id' => $fileId]);
            
            // Get local path for extraction
            // Note: This service is now called via background job, so the file is guaranteed to be available
            $storage = $file->getStorage();
            $internalPath = $file->getInternalPath();
            $localPath = $storage->getLocalFile($internalPath);
            
            if ($localPath === false || file_exists($localPath) === false) {
                throw new Exception("Could not get local file path for extraction. File: " . $file->getName());
            }

            $this->logger->debug('[FileTextService] File path resolved', [
                'file_id' => $fileId,
                'local_path' => basename($localPath)
            ]);

            $extractedText = $this->solrFileService->extractTextFromFile($localPath);
            $textLength = strlen($extractedText);

            $this->logger->info('[FileTextService] Text extracted successfully', [
                'file_id' => $fileId,
                'text_length' => $textLength,
            ]);

            // Update record with extracted text
            $fileText->setTextContent($extractedText);
            $fileText->setTextLength($textLength);
            $fileText->setExtractionStatus('completed');
            $fileText->setExtractionMethod('text_extract');
            $fileText->setExtractedAt(new DateTime());
            
            $this->fileTextMapper->update($fileText);

            return ['success' => true, 'fileText' => $fileText];

        } catch (Exception $e) {
            $this->logger->error('[FileTextService] Text extraction failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            // Update status to failed if we have a record
            if (isset($fileText)) {
                $fileText->setExtractionStatus('failed');
                $fileText->setExtractionError($e->getMessage());
                $fileText->setUpdatedAt(new DateTime());
                $this->fileTextMapper->update($fileText);
            }

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get file text by file ID
     *
     * @param int $fileId Nextcloud file ID
     * 
     * @return FileText|null File text or null if not found
     */
    public function getFileText(int $fileId): ?FileText
    {
        try {
            return $this->fileTextMapper->findByFileId($fileId);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Check if file needs extraction
     *
     * @param int $fileId Nextcloud file ID
     * 
     * @return bool True if extraction needed
     */
    public function needsExtraction(int $fileId): bool
    {
        $fileText = $this->getFileText($fileId);
        
        if (!$fileText) {
            return true; // No record, needs extraction
        }

        if ($fileText->getExtractionStatus() === 'pending') {
            return true;
        }

        if ($fileText->getExtractionStatus() === 'failed') {
            return true; // Retry failed extractions
        }

        // Check if file changed
        try {
            $file = $this->getFileNode($fileId);
            if ($file) {
                $currentChecksum = md5($file->getContent());
                if ($currentChecksum !== $fileText->getFileChecksum()) {
                    return true; // File changed
                }
            }
        } catch (Exception $e) {
            $this->logger->warning('[FileTextService] Could not check file checksum', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Update extraction status
     *
     * @param int         $fileId File ID
     * @param string      $status Status (pending, processing, completed, failed)
     * @param string|null $error  Error message
     * 
     * @return void
     */
    public function updateExtractionStatus(int $fileId, string $status, ?string $error = null): void
    {
        try {
            $fileText = $this->fileTextMapper->findByFileId($fileId);
            $fileText->setExtractionStatus($status);
            $fileText->setExtractionError($error);
            $fileText->setUpdatedAt(new DateTime());
            
            if ($status === 'completed') {
                $fileText->setExtractedAt(new DateTime());
            }
            
            $this->fileTextMapper->update($fileText);
        } catch (DoesNotExistException $e) {
            $this->logger->warning('[FileTextService] Cannot update status, file text not found', [
                'file_id' => $fileId,
            ]);
        }
    }

    /**
     * Process pending files (bulk extraction)
     *
     * @param int $limit Maximum number of files to process
     * 
     * @return array{processed: int, succeeded: int, failed: int, errors: array<string>}
     */
    public function processPendingFiles(int $limit = 100): array
    {
        $this->logger->info('[FileTextService] Processing pending files', ['limit' => $limit]);

        $pendingFiles = $this->fileTextMapper->findPendingExtractions($limit);
        
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $errors = [];

        foreach ($pendingFiles as $fileText) {
            $result = $this->extractAndStoreFileText($fileText->getFileId());
            $processed++;
            
            if ($result['success']) {
                $succeeded++;
            } else {
                $failed++;
                $errors[] = "File {$fileText->getFileId()}: " . ($result['error'] ?? 'Unknown error');
            }
        }

        $this->logger->info('[FileTextService] Finished processing pending files', [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ]);

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Get extraction statistics
     *
     * @return array{total: int, pending: int, processing: int, completed: int, failed: int, indexed: int, vectorized: int, total_text_size: int}
     */
    public function getStats(): array
    {
        return $this->fileTextMapper->getStats();
    }

    /**
     * Check if MIME type is supported
     *
     * @param string $mimeType MIME type to check
     * 
     * @return bool True if supported
     */
    private function isSupportedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_MIME_TYPES, true);
    }

    /**
     * Get file node from Nextcloud
     *
     * @param int $fileId File ID
     * 
     * @return \OCP\Files\File|null File node or null
     */
    private function getFileNode(int $fileId): ?\OCP\Files\File
    {
        try {
            $files = $this->rootFolder->getById($fileId);
            if (empty($files)) {
                return null;
            }
            
            $file = $files[0];
            if ($file instanceof \OCP\Files\File) {
                return $file;
            }
            
            return null;
        } catch (NotFoundException $e) {
            return null;
        }
    }

    /**
     * Delete file text by file ID
     *
     * @param int $fileId File ID
     * 
     * @return void
     */
    public function deleteFileText(int $fileId): void
    {
        $this->logger->info('[FileTextService] Deleting file text', ['file_id' => $fileId]);
        $this->fileTextMapper->deleteByFileId($fileId);
    }

    /**
     * Get completed text extractions
     * 
     * Retrieves file texts that have been successfully extracted.
     * 
     * @param int|null $limit Maximum number of records to return (null = no limit)
     * 
     * @return FileText[] Array of FileText entities
     */
    public function getCompletedExtractions(?int $limit = null): array
    {
        $this->logger->debug('[FileTextService] Getting completed extractions', ['limit' => $limit]);
        
        try {
            return $this->fileTextMapper->findCompleted($limit);
        } catch (Exception $e) {
            $this->logger->error('[FileTextService] Failed to get completed extractions', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}

