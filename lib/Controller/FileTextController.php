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
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\SolrFileService;
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
 * @license  AGPL-3.0-or-later
 */
class FileTextController extends Controller
{


    /**
     * Constructor
     *
     * @param string                $appName               App name
     * @param IRequest              $request               Request object
     * @param TextExtractionService $textExtractionService Text extraction service
     * @param SolrFileService       $solrFileService       SOLR file service
     * @param LoggerInterface       $logger                Logger
     * @param IAppConfig            $config                Application configuration
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TextExtractionService $textExtractionService,
        private readonly SolrFileService $solrFileService,
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
     * @NoCSRFRequired
     *
     * @return JSONResponse File text data
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
     * @NoCSRFRequired
     *
     * @return JSONResponse Extraction result
     */
    public function extractFileText(int $fileId): JSONResponse
    {
        if ($this->config->hasKey(app: 'openregister', key: 'fileManagement') === false
            || json_decode($this->config->getValueString(app: 'openregister', key: 'fileManagement'), true)['extractionScope'] === 'none'
        ) {
            $this->logger->info(message: '[FileTextController] File extraction is disabled. Not extracting text from files.');
            return new JSONResponse(data: ['success' => false, 'message' => 'Text extraction disabled'], statusCode: Http::STATUS_NOT_IMPLEMENTED);
        }

        try {
            // Force re-extraction.
            $this->textExtractionService->extractFile(fileId: $fileId, forceReExtract: true);

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
     * @NoCSRFRequired
     *
     * @return JSONResponse Bulk extraction results
     */
    public function bulkExtract(): JSONResponse
    {
        try {
            $limit = (int) $this->request->getParam('limit', 100);
            $limit = min($limit, 500);
            // Max 500 files at once.
            $result = $this->textExtractionService->extractPendingFiles($limit);

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
     * @NoCSRFRequired
     *
     * @return JSONResponse Statistics
     */
    public function getStats(): JSONResponse
    {
        try {
            $stats = $this->textExtractionService->getStats();

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'stats'   => $stats,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[FileTextController] Failed to get stats',
                    [
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
     * @NoCSRFRequired
     *
     * @return JSONResponse Deletion result
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
     * @NoCSRFRequired
     *
     * @return JSONResponse Processing result with statistics
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

            $result = $this->solrFileService->processExtractedFiles(limit: $limit, options: $options);

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            $this->logger->error(
                '[FileTextController] Failed to process extracted files',
                [
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
     * @param int      $fileId       File ID
     * @param int|null $chunkSize    Chunk size in characters
     * @param int|null $chunkOverlap Overlap between chunks in characters
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Processing result
     */
    public function processAndIndexFile(int $fileId, ?int $chunkSize=null, ?int $chunkOverlap=null): JSONResponse
    {
        try {
            $options = [];
            if ($chunkSize !== null) {
                $options['chunk_size'] = $chunkSize;
            }

            if ($chunkOverlap !== null) {
                $options['chunk_overlap'] = $chunkOverlap;
            }

            $result = $this->solrFileService->processExtractedFile(fileId: $fileId, options: $options);

            return new JSONResponse(data: $result);
        } catch (\Exception $e) {
            $this->logger->error(
                '[FileTextController] Failed to process file',
                [
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
     * @NoCSRFRequired
     *
     * @return JSONResponse Chunking statistics
     */
    public function getChunkingStats(): JSONResponse
    {
        try {
            $stats = $this->solrFileService->getChunkingStats();

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'stats'   => $stats,
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[FileTextController] Failed to get chunking stats',
                    [
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


}//end class
