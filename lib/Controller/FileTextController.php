<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Controller;

use OC\AppFramework\Http;
use OCA\OpenRegister\Service\FileTextService;
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
     * @param string          $appName         App name
     * @param IRequest        $request         Request object
     * @param FileTextService $fileTextService File text service
     * @param SolrFileService $solrFileService SOLR file service
     * @param LoggerInterface $logger          Logger
     * @param IAppConfig      $config          Application configuration
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly FileTextService $fileTextService,
        private readonly SolrFileService $solrFileService,
        private readonly LoggerInterface $logger,
        private readonly IAppConfig $config
    ) {
        parent::__construct($appName, $request);

    }//end __construct()


    /**
     * Get extracted text for a file
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param  int $fileId Nextcloud file ID
     * @return JSONResponse File text data
     */
    public function getFileText(int $fileId): JSONResponse
    {
        try {
            $fileText = $this->fileTextService->getFileText($fileId);

            if ($fileText === null) {
                return new JSONResponse(
                        [
                            'success' => false,
                            'message' => 'No text found for this file',
                            'file_id' => $fileId,
                        ],
                        404
                        );
            }

            return new JSONResponse(
                    [
                        'success'   => true,
                        'file_text' => $fileText->jsonSerialize(),
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[FileTextController] Failed to get file text',
                    [
                        'file_id' => $fileId,
                        'error'   => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to retrieve file text: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end getFileText()


    /**
     * Extract text from a file (force re-extraction)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param  int $fileId Nextcloud file ID
     * @return JSONResponse Extraction result
     */
    public function extractFileText(int $fileId): JSONResponse
    {
        if ($this->config->hasKey(app: 'openregister', key: 'fileManagement') === false
            || json_decode($this->config->getValueString(app: 'openregister', key: 'fileManagement'), true)['extractionScope'] === 'none'
        ) {
            $this->logger->info('[FileTextController] File extraction is disabled. Not extracting text from files.');
            return new JSONResponse(data: ['success' => false, 'message' => 'Text extraction disabled'], statusCode: Http::STATUS_NOT_IMPLEMENTED);
        }

        try {
            $result = $this->fileTextService->extractAndStoreFileText($fileId);

            if ($result['success'] === true) {
                return new JSONResponse(
                        [
                            'success'   => true,
                            'message'   => 'Text extracted successfully',
                            'file_text' => $result['fileText']->jsonSerialize(),
                        ]
                        );
            } else {
                return new JSONResponse(
                        [
                            'success' => false,
                            'message' => $result['error'] ?? 'Extraction failed',
                        ],
                        422
                        );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                    '[FileTextController] Failed to extract file text',
                    [
                        'file_id' => $fileId,
                        'error'   => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to extract file text: '.$e->getMessage(),
                    ],
                    500
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
            $result = $this->fileTextService->processPendingFiles($limit);

            return new JSONResponse(
                    [
                        'success'   => true,
                        'processed' => $result['processed'],
                        'succeeded' => $result['succeeded'],
                        'failed'    => $result['failed'],
                        'errors'    => $result['errors'],
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[FileTextController] Failed bulk extraction',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Bulk extraction failed: '.$e->getMessage(),
                    ],
                    500
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
            $stats = $this->fileTextService->getStats();

            return new JSONResponse(
                    [
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
                    [
                        'success' => false,
                        'message' => 'Failed to retrieve statistics: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end getStats()


    /**
     * Delete file text by file ID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param  int $fileId Nextcloud file ID
     * @return JSONResponse Deletion result
     */
    public function deleteFileText(int $fileId): JSONResponse
    {
        try {
            $this->fileTextService->deleteFileText($fileId);

            return new JSONResponse(
                    [
                        'success' => true,
                        'message' => 'File text deleted successfully',
                    ]
                    );
        } catch (\Exception $e) {
            $this->logger->error(
                    '[FileTextController] Failed to delete file text',
                    [
                        'file_id' => $fileId,
                        'error'   => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to delete file text: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end deleteFileText()


    /**
     * Process extracted files and index their chunks to SOLR
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param  int|null $limit        Maximum number of files to process
     * @param  int|null $chunkSize    Chunk size in characters
     * @param  int|null $chunkOverlap Overlap between chunks in characters
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

            $result = $this->solrFileService->processExtractedFiles($limit, $options);

            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error(
                    '[FileTextController] Failed to process extracted files',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to process extracted files: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end processAndIndexExtracted()


    /**
     * Process and index a single extracted file
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param  int      $fileId       File ID
     * @param  int|null $chunkSize    Chunk size in characters
     * @param  int|null $chunkOverlap Overlap between chunks in characters
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

            $result = $this->solrFileService->processExtractedFile($fileId, $options);

            return new JSONResponse($result);
        } catch (\Exception $e) {
            $this->logger->error(
                    '[FileTextController] Failed to process file',
                    [
                        'file_id' => $fileId,
                        'error'   => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    [
                        'success' => false,
                        'message' => 'Failed to process file: '.$e->getMessage(),
                    ],
                    500
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
                    [
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
                    [
                        'success' => false,
                        'message' => 'Failed to get chunking stats: '.$e->getMessage(),
                    ],
                    500
                    );
        }//end try

    }//end getChunkingStats()


}//end class
