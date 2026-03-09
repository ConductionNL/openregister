<?php

/**
 * OpenRegister File Text Controller
 *
 * Controller for file text management operations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Http;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\IndexService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * FileTextController
 *
 * Controller for file text management operations.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 *
 * @psalm-suppress UnusedClass
 */
class FileTextController extends Controller
{
    /**
     * Constructor
     *
     * @param string                $appName              App name
     * @param IRequest              $request              Request object
     * @param TextExtractionService $textExtractor        Text extraction service
     * @param IndexService          $indexService         Index service for file operations
     * @param FileService           $fileService          File service for file operations
     * @param EntityRelationMapper  $entityRelationMapper Entity relation mapper
     * @param LoggerInterface       $logger               Logger
     * @param IAppConfig            $config               Application configuration
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TextExtractionService $textExtractor,
        private readonly IndexService $indexService,
        private readonly FileService $fileService,
        private readonly EntityRelationMapper $entityRelationMapper,
        private readonly LoggerInterface $logger,
        private readonly IAppConfig $config
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Get extracted text for a file
     *
     * @param int $fileId Nextcloud file ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with file text or error
     */
    public function getFileText(int $fileId): JSONResponse
    {
        try {
            // TextExtractionService works with chunks, not FileText entities.
            // For now, return a message indicating this endpoint needs to be updated.
            // TODO: Implement chunk retrieval for file text display.
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'This endpoint is deprecated. Use chunk-based endpoints instead.',
                    'file_id' => $fileId,
                ],
                statusCode: 404
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to get file text',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to retrieve file text: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end getFileText()

    /**
     * Extract text from a file (force re-extraction)
     *
     * @param int $fileId Nextcloud file ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with extraction result
     */
    public function extractFileText(int $fileId): JSONResponse
    {
        $hasFileManagement    = $this->config->hasKey(app: 'openregister', key: 'fileManagement');
        $fileManagementConfig = json_decode(
            $this->config->getValueString(app: 'openregister', key: 'fileManagement'),
            true
        );
        $extractionScope      = $fileManagementConfig['extractionScope'] ?? null;
        if ($hasFileManagement === false || $extractionScope === 'none') {
            $logMsg = '[FileTextController] File extraction is disabled. Not extracting text from files.';
            $this->logger->info(message: $logMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
            return new JSONResponse(
                data: ['success' => false, 'message' => 'Text extraction disabled'],
                statusCode: Http::STATUS_NOT_IMPLEMENTED
            );
        }

        try {
            // Force re-extraction.
            $this->textExtractor->extractFile(fileId: $fileId, forceReExtract: true);

            return new JSONResponse(
                data: [
                    'success' => true,
                    'message' => 'Text extracted successfully',
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to extract file text',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to extract file text: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end extractFileText()

    /**
     * Bulk extract text from multiple files
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with bulk extraction result
     */
    public function bulkExtract(): JSONResponse
    {
        try {
            $limit = (int) $this->request->getParam('limit', 100);
            $limit = min($limit, 500);
            // Max 500 files at once.
            $result = $this->textExtractor->extractPendingFiles($limit);

            return new JSONResponse(
                data: [
                    'success'   => true,
                    'processed' => $result['processed'],
                    'failed'    => $result['failed'],
                    'total'     => $result['total'],
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed bulk extraction',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Bulk extraction failed: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end bulkExtract()

    /**
     * Get file text extraction statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with extraction stats
     */
    public function getStats(): JSONResponse
    {
        try {
            $stats = $this->textExtractor->getStats();

            return new JSONResponse(
                data: [
                    'success' => true,
                    'stats'   => $stats,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to get stats',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to retrieve statistics: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end getStats()

    /**
     * Delete file text by file ID
     *
     * @param int $fileId Nextcloud file ID
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with deletion result
     */
    public function deleteFileText(int $fileId): JSONResponse
    {
        try {
            // TextExtractionService works with chunks.
            // TODO: Implement chunk deletion for file.
            // For now, return a message indicating this needs implementation.
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Chunk deletion not yet implemented. Use chunk-based endpoints.',
                ],
                statusCode: 501
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to delete file text',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to delete file text: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end deleteFileText()

    /**
     * Process extracted files and index their chunks to SOLR
     *
     * @param int|null $limit        Maximum number of files to process
     * @param int|null $chunkSize    Chunk size in characters
     * @param int|null $chunkOverlap Overlap between chunks in characters
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with indexing stats
     */
    public function processAndIndexExtracted(?int $limit=null, ?int $chunkSize=null, ?int $chunkOverlap=null): JSONResponse
    {
        try {
            $options = [];
            if ($chunkSize !== null) {
                $options['chunk_size'] = $chunkSize;
            }

            if ($chunkOverlap !== null) {
                $options['chunk_overlap'] = $chunkOverlap;
            }

            $result = $this->indexService->processUnindexedChunks(limit: $limit);

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to process extracted files',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to process extracted files: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end processAndIndexExtracted()

    /**
     * Process and index a single extracted file
     *
     * @param int      $fileId        File ID
     * @param int|null $chunkSize     Chunk size in characters
     * @param int|null $_chunkOverlap Overlap between chunks in characters (reserved for future use)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @SuppressWarnings (PHPMD.UnusedFormalParameter) $_chunkOverlap reserved for future implementation
     *
     * @return JSONResponse JSON response with indexing result
     */
    public function processAndIndexFile(int $fileId, ?int $chunkSize=null, ?int $_chunkOverlap=null): JSONResponse
    {
        try {
            $options = [];
            if ($chunkSize !== null) {
                $options['chunk_size'] = $chunkSize;
            }

            // Process unindexed chunks for all files (fileId and options are not supported by current API).
            // TODO: Implement file-specific chunk processing with chunk size/overlap options.
            $result = $this->indexService->processUnindexedChunks();

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to process file',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to process file: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end processAndIndexFile()

    /**
     * Get chunking statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with chunking stats
     */
    public function getChunkingStats(): JSONResponse
    {
        try {
            $stats = $this->indexService->getChunkingStats();

            return new JSONResponse(
                data: [
                    'success' => true,
                    'stats'   => $stats,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to get chunking stats',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to get chunking stats: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end getChunkingStats()

    /**
     * Anonymize a file by replacing detected entities with placeholders
     *
     * Creates a new anonymized copy of the file with all detected PII entities
     * replaced by placeholders in the format [ENTITY_TYPE: key].
     * The original file remains unchanged.
     *
     * @param int $fileId Nextcloud file ID to anonymize
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with anonymization result
     */
    public function anonymizeFile(int $fileId): JSONResponse
    {
        try {
            $this->logger->info(
                message: '[FileTextController] Anonymizing file',
                context: ['file' => __FILE__, 'line' => __LINE__, 'file_id' => $fileId]
            );

            // Get the file node.
            $fileNode = $this->fileService->getFileById($fileId);
            if ($fileNode === null) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'File not found',
                    ],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            // Check if the file is already anonymized.
            $fileName = $fileNode->getName();
            if (strpos($fileName, '_anonymized') !== false) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'File is already anonymized',
                    ],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Get detected entities for this file.
            $entityData = $this->entityRelationMapper->findEntitiesForFile($fileId);

            if (empty($entityData) === true) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'No entities detected in this file. Run text extraction first.',
                    ],
                    statusCode: Http::STATUS_BAD_REQUEST
                );
            }

            // Build entities array in the format expected by anonymizeDocument.
            // Format: [['text' => 'value', 'entityType' => 'TYPE', 'key' => 'unique_key'], ...].
            $entities        = [];
            $processedValues = [];
            // Track unique values to avoid duplicates.
            foreach ($entityData as $entity) {
                $value = $entity['entity_value'];

                // Skip if we've already processed this value.
                if (isset($processedValues[$value]) === true) {
                    continue;
                }

                $processedValues[$value] = true;
                $entities[] = [
                    'text'       => $value,
                    'entityType' => $entity['entity_type'],
                    'key'        => substr(md5($value.$entity['entity_type']), 0, 8),
                ];
            }

            $this->logger->debug(
                message: '[FileTextController] Found entities to anonymize',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'file_id'      => $fileId,
                    'entity_count' => count($entities),
                ]
            );

            // Perform anonymization.
            $anonymizedFile = $this->fileService->anonymizeDocument($fileNode, $entities);

            // Mark entity relations as anonymized.
            $this->entityRelationMapper->markAsAnonymized(
                fileId: $fileId,
                anonymizedValue: 'anonymized_'.date('Y-m-d_H-i-s')
            );

            $this->logger->info(
                message: '[FileTextController] File anonymized successfully',
                context: [
                    'file'               => __FILE__,
                    'line'               => __LINE__,
                    'original_file_id'   => $fileId,
                    'anonymized_file_id' => $anonymizedFile->getId(),
                    'anonymized_path'    => $anonymizedFile->getPath(),
                    'entities_replaced'  => count($entities),
                ]
            );

            return new JSONResponse(
                data: [
                    'success'            => true,
                    'message'            => 'File anonymized successfully',
                    'original_file_id'   => $fileId,
                    'anonymized_file_id' => $anonymizedFile->getId(),
                    'anonymized_path'    => $anonymizedFile->getPath(),
                    'entities_replaced'  => count($entities),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[FileTextController] Failed to anonymize file',
                context: [
                    'file'    => __FILE__,
                    'line'    => __LINE__,
                    'file_id' => $fileId,
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to anonymize file: '.$e->getMessage(),
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end anonymizeFile()
}//end class
