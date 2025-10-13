<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\FileTextService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
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
     * @param string             $appName         App name
     * @param IRequest           $request         Request object
     * @param FileTextService    $fileTextService File text service
     * @param LoggerInterface    $logger          Logger
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly FileTextService $fileTextService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get extracted text for a file
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $fileId Nextcloud file ID
     * 
     * @return JSONResponse File text data
     */
    public function getFileText(int $fileId): JSONResponse
    {
        try {
            $fileText = $this->fileTextService->getFileText($fileId);
            
            if (!$fileText) {
                return new JSONResponse([
                    'success' => false,
                    'message' => 'No text found for this file',
                    'file_id' => $fileId
                ], 404);
            }
            
            return new JSONResponse([
                'success' => true,
                'file_text' => $fileText->jsonSerialize()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[FileTextController] Failed to get file text', [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to retrieve file text: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extract text from a file (force re-extraction)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $fileId Nextcloud file ID
     * 
     * @return JSONResponse Extraction result
     */
    public function extractFileText(int $fileId): JSONResponse
    {
        try {
            $result = $this->fileTextService->extractAndStoreFileText($fileId);
            
            if ($result['success']) {
                return new JSONResponse([
                    'success' => true,
                    'message' => 'Text extracted successfully',
                    'file_text' => $result['fileText']->jsonSerialize()
                ]);
            } else {
                return new JSONResponse([
                    'success' => false,
                    'message' => $result['error'] ?? 'Extraction failed'
                ], 422);
            }
            
        } catch (\Exception $e) {
            $this->logger->error('[FileTextController] Failed to extract file text', [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to extract file text: ' . $e->getMessage()
            ], 500);
        }
    }

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
            $limit = min($limit, 500); // Max 500 files at once
            
            $result = $this->fileTextService->processPendingFiles($limit);
            
            return new JSONResponse([
                'success' => true,
                'processed' => $result['processed'],
                'succeeded' => $result['succeeded'],
                'failed' => $result['failed'],
                'errors' => $result['errors']
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[FileTextController] Failed bulk extraction', [
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'success' => false,
                'message' => 'Bulk extraction failed: ' . $e->getMessage()
            ], 500);
        }
    }

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
            
            return new JSONResponse([
                'success' => true,
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[FileTextController] Failed to get stats', [
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to retrieve statistics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete file text by file ID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $fileId Nextcloud file ID
     * 
     * @return JSONResponse Deletion result
     */
    public function deleteFileText(int $fileId): JSONResponse
    {
        try {
            $this->fileTextService->deleteFileText($fileId);
            
            return new JSONResponse([
                'success' => true,
                'message' => 'File text deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[FileTextController] Failed to delete file text', [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);
            
            return new JSONResponse([
                'success' => false,
                'message' => 'Failed to delete file text: ' . $e->getMessage()
            ], 500);
        }
    }
}

