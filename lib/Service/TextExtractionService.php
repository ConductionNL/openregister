<?php

declare(strict_types=1);

/**
 * TextExtractionService
 *
 * This service handles all text extraction logic for files in the system.
 * It consolidates extraction workflows, file tracking, and re-extraction detection.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git-id>
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\FileText;
use OCA\OpenRegister\Db\FileTextMapper;
use OCA\OpenRegister\Service\GuzzleSolrService;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * TextExtractionService
 *
 * Handles text extraction from files with intelligent re-extraction detection.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class TextExtractionService
{
    /**
     * Constructor
     *
     * @param FileMapper         $fileMapper       Mapper for Nextcloud files
     * @param FileTextMapper     $fileTextMapper   Mapper for extracted text records
     * @param IRootFolder        $rootFolder       Nextcloud root folder
     * @param LoggerInterface    $logger           Logger
     * @param GuzzleSolrService  $solrService      SOLR service for indexing
     */
    public function __construct(
        private readonly FileMapper $fileMapper,
        private readonly FileTextMapper $fileTextMapper,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger,
        private readonly GuzzleSolrService $solrService
    ) {
    }

    /**
     * Extract text from a file by Nextcloud file ID
     *
     * This method:
     * 1. Checks if file exists in OpenRegister file_texts table
     * 2. If not, looks it up in Nextcloud's oc_filecache
     * 3. Checks if re-extraction is needed (file modified since last extraction)
     * 4. Performs extraction if needed
     *
     * @param int  $fileId          Nextcloud file ID from oc_filecache
     * @param bool $forceReExtract  Force re-extraction even if file hasn't changed
     *
     * @return FileText The FileText entity with extraction results
     *
     * @throws NotFoundException If file doesn't exist in Nextcloud
     * @throws Exception If extraction fails
     */
    public function extractFile(int $fileId, bool $forceReExtract = false): FileText
    {
        $this->logger->info('[TextExtractionService] Starting extraction for file', ['fileId' => $fileId]);

        // Check if file already tracked in our system
        $existingFileText = null;
        try {
            $existingFileText = $this->fileTextMapper->findByFileId($fileId);
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // File not tracked yet, this is OK
            $this->logger->debug('[TextExtractionService] File not tracked yet', ['fileId' => $fileId]);
        }

        // Get file info from Nextcloud
        $ncFile = $this->fileMapper->getFile($fileId);
        if ($ncFile === null) {
            throw new NotFoundException("File with ID {$fileId} not found in Nextcloud");
        }

        // Determine if extraction is needed
        $needsExtraction = $this->needsExtraction($existingFileText, $ncFile, $forceReExtract);

        if ($needsExtraction === false && $existingFileText !== null) {
            $this->logger->info('[TextExtractionService] File already extracted and up-to-date', ['fileId' => $fileId]);
            return $existingFileText;
        }

        // Create or update FileText entity
        $fileText = $existingFileText ?? new FileText();
        $fileText->setFileId($fileId);
        $fileText->setFilePath($ncFile['path']);
        $fileText->setFileName($ncFile['name']);
        $fileText->setMimeType($ncFile['mimetype']);
        $fileText->setFileSize($ncFile['size']);
        $fileText->setFileChecksum($ncFile['checksum'] ?? null);
        $fileText->setExtractionStatus('processing');
        $fileText->setUpdatedAt(new DateTime());

        if ($existingFileText === null) {
            $fileText->setCreatedAt(new DateTime());
            $fileText = $this->fileTextMapper->insert($fileText);
        } else {
            $fileText = $this->fileTextMapper->update($fileText);
        }

        // Perform actual text extraction
        try {
            $extractedText = $this->performTextExtraction($fileId, $ncFile);
            
            if ($extractedText !== null) {
                // Successfully extracted text
                $fileText->setTextContent($extractedText);
                $fileText->setTextLength(strlen($extractedText));
                $fileText->setExtractionStatus('completed');
                $fileText->setExtractedAt(new DateTime());
                $fileText->setExtractionError(null);
                $fileText->setExtractionMethod('simple'); // Will be updated when using LLPhant/Dolphin
                $fileText = $this->fileTextMapper->update($fileText);
                
                $this->logger->info('[TextExtractionService] Text extraction completed', [
                    'fileId' => $fileId,
                    'textLength' => strlen($extractedText)
                ]);
                
                // Automatically index in SOLR to create chunks
                try {
                    $indexResult = $this->solrService->indexFiles([$fileId]);
                    
                    if ($indexResult['indexed'] > 0) {
                        $this->logger->info('[TextExtractionService] File indexed in SOLR', [
                            'fileId' => $fileId,
                            'indexed' => $indexResult['indexed']
                        ]);
                    } else {
                        $this->logger->warning('[TextExtractionService] Failed to index file in SOLR', [
                            'fileId' => $fileId,
                            'errors' => $indexResult['errors'] ?? []
                        ]);
                    }
                } catch (Exception $indexError) {
                    // Log but don't fail the extraction if SOLR indexing fails
                    $this->logger->error('[TextExtractionService] SOLR indexing error', [
                        'fileId' => $fileId,
                        'error' => $indexError->getMessage()
                    ]);
                }
            } else {
                // Extraction returned null (unsupported file type or empty)
                $fileText->setExtractionStatus('failed');
                $fileText->setExtractionError('Unsupported file type or empty file');
                
                $this->logger->warning('[TextExtractionService] Text extraction failed', [
                    'fileId' => $fileId,
                    'reason' => 'Unsupported file type or empty file'
                ]);
                
                $fileText = $this->fileTextMapper->update($fileText);
            }
            
        } catch (Exception $e) {
            // Extraction failed with exception
            $fileText->setExtractionStatus('failed');
            $fileText->setExtractionError($e->getMessage());
            $fileText = $this->fileTextMapper->update($fileText);
            
            $this->logger->error('[TextExtractionService] Text extraction error', [
                'fileId' => $fileId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }

        return $fileText;
    }

    /**
     * Perform actual text extraction from a file
     *
     * This method handles the actual text extraction from files based on their type.
     * Currently supports simple text-based files. Will be extended to support
     * PDF, DOCX, and other formats via LLPhant or Dolphin extractors.
     *
     * @param int   $fileId Nextcloud file ID
     * @param array $ncFile Nextcloud file metadata
     *
     * @return string|null Extracted text content, or null if extraction not possible
     *
     * @throws Exception If file cannot be read
     */
    private function performTextExtraction(int $fileId, array $ncFile): ?string
    {
        $mimeType = $ncFile['mimetype'] ?? '';
        $filePath = $ncFile['path'] ?? '';
        
        $this->logger->debug('[TextExtractionService] Attempting extraction', [
            'fileId' => $fileId,
            'mimeType' => $mimeType,
            'filePath' => $filePath
        ]);
        
        // Get the file node from Nextcloud
        try {
            // Get file by ID using Nextcloud's file system
            $nodes = $this->rootFolder->getById($fileId);
            
            if (empty($nodes) === true) {
                throw new Exception("File not found in Nextcloud file system");
            }
            
            $file = $nodes[0];
            
            if ($file instanceof \OCP\Files\File === false) {
                throw new Exception("Node is not a file");
            }
            
            // Extract text based on mime type
            $extractedText = null;
            
            // Text-based files that can be read directly
            $textMimeTypes = [
                'text/plain',
                'text/markdown',
                'text/html',
                'text/xml',
                'application/json',
                'application/xml',
                'text/csv',
                'text/x-yaml',
                'text/yaml',
                'application/x-yaml',
            ];
            
            if (in_array($mimeType, $textMimeTypes) === true || strpos($mimeType, 'text/') === 0) {
                // Read text file directly
                $extractedText = $file->getContent();
                
                $this->logger->debug('[TextExtractionService] Text file extracted', [
                    'fileId' => $fileId,
                    'length' => strlen($extractedText)
                ]);
            } else {
                // Unsupported file type for now
                // TODO: Implement PDF, DOCX, etc. extraction via LLPhant/Dolphin
                $this->logger->info('[TextExtractionService] Unsupported file type', [
                    'fileId' => $fileId,
                    'mimeType' => $mimeType
                ]);
                
                return null;
            }
            
            return $extractedText;
            
        } catch (Exception $e) {
            $this->logger->error('[TextExtractionService] Failed to read file', [
                'fileId' => $fileId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Determine if a file needs extraction or re-extraction
     *
     * Extraction is needed if:
     * 1. File has never been extracted ($existingFileText is null)
     * 2. Force re-extraction is requested
     * 3. File status is 'pending' (discovered but not yet extracted)
     * 4. Previous extraction failed (status='failed')
     * 5. File has been modified since last extraction (NC file mtime > extractedAt)
     *
     * Extraction is NOT needed if:
     * - File is currently being processed (status='processing' - to avoid conflicts)
     * - File status is 'completed' and file hasn't been modified
     *
     * @param FileText|null $existingFileText Existing extraction record, if any
     * @param array         $ncFile           Nextcloud file info from oc_filecache
     * @param bool          $forceReExtract   Force re-extraction flag
     *
     * @return bool True if extraction is needed
     */
    private function needsExtraction(?FileText $existingFileText, array $ncFile, bool $forceReExtract): bool
    {
        // Never extracted before
        if ($existingFileText === null) {
            return true;
        }

        // Force re-extraction requested
        if ($forceReExtract === true) {
            $this->logger->info('[TextExtractionService] Force re-extraction requested', ['fileId' => $ncFile['fileid']]);
            return true;
        }

        // File is pending extraction
        if ($existingFileText->getExtractionStatus() === 'pending') {
            $this->logger->info('[TextExtractionService] File is pending extraction', ['fileId' => $ncFile['fileid']]);
            return true;
        }

        // Previous extraction failed
        if ($existingFileText->getExtractionStatus() === 'failed') {
            $this->logger->info('[TextExtractionService] Previous extraction failed, retrying', ['fileId' => $ncFile['fileid']]);
            return true;
        }

        // File is currently processing (should not re-extract to avoid conflicts)
        if ($existingFileText->getExtractionStatus() === 'processing') {
            $this->logger->info('[TextExtractionService] File is currently being processed, skipping', ['fileId' => $ncFile['fileid']]);
            return false;
        }

        // Check if file was modified since extraction
        $extractedAt = $existingFileText->getExtractedAt();
        if ($extractedAt !== null) {
            $fileMtime = $ncFile['mtime']; // Unix timestamp from oc_filecache
            $extractedTimestamp = $extractedAt->getTimestamp();

            if ($fileMtime > $extractedTimestamp) {
                $this->logger->info('[TextExtractionService] File modified since extraction', [
                    'fileId' => $ncFile['fileid'],
                    'fileMtime' => $fileMtime,
                    'extractedAt' => $extractedTimestamp
                ]);
                return true;
            }
        }

        // File is up-to-date
        return false;
    }

    /**
     * Discover files in Nextcloud that aren't tracked in the extraction system yet
     *
     * This finds files in oc_filecache that don't have a corresponding record
     * in oc_openregister_file_texts and creates tracking records with status='pending'.
     *
     * This is a separate action from extraction - it only stages files for processing.
     *
     * @param int $limit Maximum number of files to discover
     *
     * @return array Statistics about discovery: {discovered, failed, total}
     */
    public function discoverUntrackedFiles(int $limit = 100): array
    {
        $this->logger->info('[TextExtractionService] Discovering untracked files', ['limit' => $limit]);

        try {
            // Get untracked files from Nextcloud
            $untrackedFiles = $this->fileMapper->findUntrackedFiles($limit);
            $discovered = 0;
            $failed = 0;

            foreach ($untrackedFiles as $ncFile) {
                try {
                    // Create a new FileText record for this untracked file
                    $fileText = new FileText();
                    $fileText->setFileId($ncFile['fileid']);
                    $fileText->setFilePath($ncFile['path'] ?? '');
                    $fileText->setFileName($ncFile['name'] ?? 'unknown');
                    $fileText->setMimeType($ncFile['mimetype'] ?? 'application/octet-stream');
                    $fileText->setFileSize($ncFile['size'] ?? 0);
                    $fileText->setFileChecksum($ncFile['checksum'] ?? null);
                    $fileText->setExtractionStatus('pending');
                    $fileText->setCreatedAt(new DateTime());
                    $fileText->setUpdatedAt(new DateTime());

                    $this->fileTextMapper->insert($fileText);
                    $discovered++;

                    $this->logger->debug('[TextExtractionService] Discovered untracked file', [
                        'fileId' => $ncFile['fileid'],
                        'path' => $ncFile['path'] ?? 'unknown'
                    ]);
                } catch (Exception $e) {
                    $failed++;
                    $this->logger->error('[TextExtractionService] Failed to track file', [
                        'fileId' => $ncFile['fileid'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $this->logger->info('[TextExtractionService] Discovery complete', [
                'discovered' => $discovered,
                'failed' => $failed
            ]);

            return [
                'discovered' => $discovered,
                'failed' => $failed,
                'total' => count($untrackedFiles)
            ];
        } catch (Exception $e) {
            $this->logger->error('[TextExtractionService] Discovery failed', ['error' => $e->getMessage()]);
            return [
                'discovered' => 0,
                'failed' => 0,
                'total' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Extract text from pending files that are already tracked in the system
     *
     * This only processes files with status='pending'. It does NOT discover new files.
     * Use discoverUntrackedFiles() first to stage files for extraction.
     *
     * @param int $limit Maximum number of files to process
     *
     * @return array Statistics about the extraction process: {processed, failed, total}
     */
    public function extractPendingFiles(int $limit = 100): array
    {
        $this->logger->info('[TextExtractionService] Extracting pending files', ['limit' => $limit]);

        // Get files already marked as pending
        $pendingFiles = $this->fileTextMapper->findByStatus('pending', $limit);
        
        $this->logger->info('[TextExtractionService] Found pending files', [
            'count' => count($pendingFiles),
            'limit' => $limit
        ]);
        
        $processed = 0;
        $failed = 0;

        foreach ($pendingFiles as $fileText) {
            try {
                $this->logger->debug('[TextExtractionService] Processing file', [
                    'fileId' => $fileText->getFileId(),
                    'fileName' => $fileText->getFileName()
                ]);
                
                // Trigger extraction for this file
                $this->extractFile($fileText->getFileId(), false);
                $processed++;
            } catch (Exception $e) {
                $failed++;
                $this->logger->error('[TextExtractionService] Failed to extract file', [
                    'fileId' => $fileText->getFileId(),
                    'error' => $e->getMessage()
                ]);

                // Mark as failed
                $fileText->setExtractionStatus('failed');
                $fileText->setExtractionError($e->getMessage());
                $fileText->setUpdatedAt(new DateTime());
                $this->fileTextMapper->update($fileText);
            }
        }

        $this->logger->info('[TextExtractionService] Extraction complete', [
            'processed' => $processed,
            'failed' => $failed,
            'foundPending' => count($pendingFiles)
        ]);

        return [
            'processed' => $processed,
            'failed' => $failed,
            'total' => count($pendingFiles)
        ];
    }

    /**
     * Retry failed file extractions
     *
     * @param int $limit Maximum number of files to retry
     *
     * @return array Statistics about the retry process
     */
    public function retryFailedExtractions(int $limit = 50): array
    {
        $this->logger->info('[TextExtractionService] Retrying failed extractions', ['limit' => $limit]);

        $failedFiles = $this->fileTextMapper->findByStatus('failed', $limit);
        $retried = 0;
        $failed = 0;

        foreach ($failedFiles as $fileText) {
            try {
                $this->extractFile($fileText->getFileId(), true);
                $retried++;
            } catch (Exception $e) {
                $failed++;
                $this->logger->error('[TextExtractionService] Retry failed for file', [
                    'fileId' => $fileText->getFileId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'retried' => $retried,
            'failed' => $failed,
            'total' => count($failedFiles)
        ];
    }

    /**
     * Get extraction statistics
     *
     * @return array Statistics about file extraction
     */
    public function getStats(): array
    {
        return $this->fileTextMapper->getStats();
    }
}

