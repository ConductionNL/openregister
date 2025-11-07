<?php

declare(strict_types=1);

/**
 * FilesController
 *
 * This controller handles file operations and text extraction endpoints.
 * Provides core file extraction functionality accessible via API.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git-id>
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\FileTextMapper;
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\VectorizationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;

/**
 * FileExtractionController
 *
 * Handles file extraction endpoints for the OpenRegister application.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */
class FileExtractionController extends Controller
{
    /**
     * Constructor
     *
     * @param string                      $appName                Application name
     * @param IRequest                    $request                HTTP request
     * @param TextExtractionService       $extractionService      Text extraction service
     * @param FileTextMapper              $fileTextMapper         File text mapper
     * @param VectorizationService        $vectorizationService   Unified vectorization service
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TextExtractionService $extractionService,
        private readonly FileTextMapper $fileTextMapper,
        private readonly VectorizationService $vectorizationService
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Get all files tracked in the extraction system
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int|null    $limit   Maximum number of files to return
     * @param int|null    $offset  Offset for pagination
     * @param string|null $status  Filter by extraction status
     * @param string|null $search  Search by file name or path
     *
     * @return JSONResponse List of files with extraction information
     */
    public function index(?int $limit = 100, ?int $offset = 0, ?string $status = null, ?string $search = null): JSONResponse
    {
        try {
            // Apply filters based on parameters
            if ($status !== null) {
                $files = $this->fileTextMapper->findByStatus($status, $limit ?? 100, $offset ?? 0);
            } else {
                $files = $this->fileTextMapper->findAll($limit, $offset);
            }

            // Apply search filter if provided (post-query filtering for simplicity)
            if ($search !== null && trim($search) !== '') {
                $searchLower = strtolower(trim($search));
                $files = array_filter($files, function($file) use ($searchLower) {
                    $fileNameLower = strtolower($file->getFileName() ?? '');
                    $filePathLower = strtolower($file->getFilePath() ?? '');
                    return strpos($fileNameLower, $searchLower) !== false 
                        || strpos($filePathLower, $searchLower) !== false;
                });
                // Re-index array after filtering
                $files = array_values($files);
            }

            return new JSONResponse([
                'success' => true,
                'data' => array_map(fn($file) => $file->jsonSerialize(), $files),
                'count' => count($files)
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single file's extraction information by ID
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $id Nextcloud file ID from oc_filecache
     *
     * @return JSONResponse File extraction information
     */
    public function show(int $id): JSONResponse
    {
        try {
            $fileText = $this->fileTextMapper->findByFileId($id);

            return new JSONResponse([
                'success' => true,
                'data' => $fileText->jsonSerialize()
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'File not found in extraction system',
                'message' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Extract text from a specific file by Nextcloud file ID
     *
     * If the file doesn't exist in the OpenRegister file_texts table,
     * it will be looked up in Nextcloud's oc_filecache and added.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int  $id             Nextcloud file ID from oc_filecache
     * @param bool $forceReExtract Force re-extraction even if file hasn't changed
     *
     * @return JSONResponse Extraction result
     */
    public function extract(int $id, bool $forceReExtract = false): JSONResponse
    {
        try {
            $fileText = $this->extractionService->extractFile($id, $forceReExtract);

            return new JSONResponse([
                'success' => true,
                'message' => 'File queued for extraction',
                'data' => $fileText->jsonSerialize()
            ]);
        } catch (NotFoundException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'File not found in Nextcloud',
                'message' => $e->getMessage()
            ], 404);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Extraction failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Discover files in Nextcloud that aren't tracked yet
     *
     * This finds new files and stages them with status='pending'.
     * Does NOT perform actual text extraction.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $limit Maximum number of files to discover
     *
     * @return JSONResponse Discovery statistics
     */
    public function discover(int $limit = 100): JSONResponse
    {
        try {
            $stats = $this->extractionService->discoverUntrackedFiles($limit);

            return new JSONResponse([
                'success' => true,
                'message' => 'File discovery completed',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'File discovery failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extract text from all pending files (files already tracked with status='pending')
     *
     * This processes files already staged for extraction. Use discover() first
     * to find and stage new files from Nextcloud.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $limit Maximum number of files to process
     *
     * @return JSONResponse Extraction statistics
     */
    public function extractAll(int $limit = 100): JSONResponse
    {
        try {
            $stats = $this->extractionService->extractPendingFiles($limit);

            return new JSONResponse([
                'success' => true,
                'message' => 'Batch extraction completed',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Batch extraction failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retry failed file extractions
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param int $limit Maximum number of files to retry
     *
     * @return JSONResponse Retry statistics
     */
    public function retryFailed(int $limit = 50): JSONResponse
    {
        try {
            $stats = $this->extractionService->retryFailedExtractions($limit);

            return new JSONResponse([
                'success' => true,
                'message' => 'Retry completed',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Retry failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get extraction statistics
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Extraction statistics
     */
    public function stats(): JSONResponse
    {
        try {
            $stats = $this->extractionService->getStats();

            return new JSONResponse([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Failed to retrieve statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clean up invalid file_texts entries
     *
     * Removes entries for files that no longer exist, directories, and system files.
     * This helps maintain database integrity and remove orphaned records.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Cleanup statistics
     */
    public function cleanup(): JSONResponse
    {
        try {
            $result = $this->fileTextMapper->cleanupInvalidEntries();

            return new JSONResponse([
                'success' => true,
                'message' => 'Cleanup completed',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Cleanup failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get file types with their file and chunk counts
     *
     * Returns only file types that have completed extractions with chunks.
     * Useful for showing which file types are available for vectorization.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse File types with counts
     */
    public function fileTypes(): JSONResponse
    {
        try {
            $types = $this->fileTextMapper->getFileTypeStats();

            return new JSONResponse([
                'success' => true,
                'data' => $types
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Failed to retrieve file types',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vectorize file chunks in batch
     *
     * Processes extracted file chunks and generates vector embeddings.
     * Supports serial and parallel processing modes.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse Vectorization results
     */
    public function vectorizeBatch(): JSONResponse
    {
        try {
            $data = $this->request->getParams();
            $mode = $data['mode'] ?? 'serial';
            $maxFiles = (int) ($data['max_files'] ?? 0);
            $batchSize = (int) ($data['batch_size'] ?? 50);
            $fileTypes = $data['file_types'] ?? [];

            // Use unified vectorization service with 'file' entity type
            $result = $this->vectorizationService->vectorizeBatch('file', [
                'mode' => $mode,
                'max_files' => $maxFiles,
                'batch_size' => $batchSize,
                'file_types' => $fileTypes,
            ]);
            
            return new JSONResponse([
                'success' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => 'Vectorization failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
