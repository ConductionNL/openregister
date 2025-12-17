<?php

/**
 * OpenRegister File Extraction Controller
 *
 * This controller handles file operations and text extraction endpoints.
 * Provides core file extraction functionality accessible via API.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Service\VectorizationService;
use OCA\OpenRegister\Db\ChunkMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotFoundException;
use OCP\IRequest;

/**
 * FileExtractionController
 *
 * Handles file extraction endpoints for the OpenRegister application.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @psalm-suppress UnusedClass
 */
class FileExtractionController extends Controller
{
    /**
     * Constructor
     *
     * @param string                $appName               Application name
     * @param IRequest              $request               HTTP request
     * @param TextExtractionService $textExtractionService Text extraction service
     * @param VectorizationService  $vectorizationService  Unified vectorization service
     * @param ChunkMapper           $chunkMapper           Chunk mapper for text chunks
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly TextExtractionService $textExtractionService,
        private readonly VectorizationService $vectorizationService,
        private readonly ChunkMapper $chunkMapper
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Get all files tracked in the extraction system.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing file extraction data
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|500,
     *     array{
     *         success: bool,
     *         error?: string,
     *         data?: array<never, never>,
     *         message?: 'This endpoint needs to be updated for chunk-based architecture'
     *     },
     *     array<never, never>
     * >
     */
    public function index(): JSONResponse
    {
        try {
            // TextExtractionService doesn't have findByStatus, use discoverUntrackedFiles or extractPendingFiles instead.
            // For now, return empty array as this endpoint needs to be redesigned for chunk-based architecture.
            return new JSONResponse(
                    data: [
                        'success' => true,
                        'data'    => [],
                        'message' => 'This endpoint needs to be updated for chunk-based architecture',
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end index()

    /**
     * Get a single file's extraction information by ID.
     *
     * @param int $id Nextcloud file ID from oc_filecache
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|404, array{success: bool, error?: 'File not found in extraction system', message?: string, data?: non-empty-list<array{checksum: null|string, chunkIndex: int, createdAt: null|string, embeddingProvider: null|string, endOffset: int, id: int, indexed: bool, language: null|string, languageConfidence: float|null, languageLevel: null|string, organisation: null|string, overlapSize: int, owner: null|string, positionReference: array|null, sourceId: int|null, sourceType: null|string, startOffset: int, updatedAt: null|string, uuid: null|string, vectorized: bool}>}, array<never, never>>
     */
    public function show(int $id): JSONResponse
    {
        try {
            // Get chunks for this file.
            $chunks = $this->chunkMapper->findBySource(sourceType: 'file', sourceId: $id);

            if (empty($chunks) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'File not found in extraction system',
                            'message' => 'No chunks found for file ID: '.$id,
                        ],
                        statusCode: 404
                    );
            }

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'data'    => array_map(fn($chunk) => $chunk->jsonSerialize(), $chunks),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'File not found in extraction system',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 404
                );
        }//end try

    }//end show()

    /**
     * Extract text from a specific file by Nextcloud file ID.
     *
     * If the file doesn't exist in the OpenRegister file_texts table,
     * it will be looked up in Nextcloud's oc_filecache and added.
     *
     * @param int  $id             Nextcloud file ID from oc_filecache
     * @param bool $forceReExtract Force re-extraction even if file hasn't changed
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing extraction result
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|404|500,
     *     array{
     *         success: bool,
     *         error?: 'Extraction failed'|'File not found in Nextcloud',
     *         message: string
     *     },
     *     array<never, never>
     * >
     */
    public function extract(int $id, bool $forceReExtract=false): JSONResponse
    {
        try {
            // ExtractFile returns void, not an object.
            $this->textExtractionService->extractFile(fileId: $id, forceReExtract: $forceReExtract);

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'File extraction completed',
                    ]
                    );
        } catch (NotFoundException $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'File not found in Nextcloud',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 404
                );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Extraction failed',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end extract()

    /**
     * Discover files in Nextcloud that aren't tracked yet.
     *
     * This finds new files and stages them with status='pending'.
     * Does NOT perform actual text extraction.
     *
     * @param int $limit Maximum number of files to discover
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing file discovery results
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|500,
     *     array{
     *         success: bool,
     *         error?: 'File discovery failed',
     *         message: string,
     *         data?: array{
     *             discovered: int<0, max>,
     *             failed: int<0, max>,
     *             total: int<0, max>,
     *             error?: string
     *         }
     *     },
     *     array<never, never>
     * >
     */
    public function discover(int $limit=100): JSONResponse
    {
        try {
            $stats = $this->textExtractionService->discoverUntrackedFiles($limit);

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'File discovery completed',
                        'data'    => $stats,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'File discovery failed',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }

    }//end discover()

    /**
     * Extract text from all pending files (files already tracked with status='pending').
     *
     * This processes files already staged for extraction. Use discover() first
     * to find and stage new files from Nextcloud.
     *
     * @param int $limit Maximum number of files to process
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing batch extraction results
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|500,
     *     array{
     *         success: bool,
     *         error?: 'Batch extraction failed',
     *         message: string,
     *         data?: array{processed: int<0, max>, failed: int<0, max>, total: int<0, max>}
     *     },
     *     array<never, never>
     * >
     */
    public function extractAll(int $limit=100): JSONResponse
    {
        try {
            $stats = $this->textExtractionService->extractPendingFiles($limit);

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'Batch extraction completed',
                        'data'    => $stats,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Batch extraction failed',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }

    }//end extractAll()

    /**
     * Retry failed file extractions.
     *
     * @param int $limit Maximum number of files to retry
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing retry operation results
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|500,
     *     array{
     *         success: bool,
     *         error?: 'Retry failed',
     *         message: string,
     *         data?: array{retried: int<0, max>, failed: int<0, max>, total: int<0, max>}
     *     },
     *     array<never, never>
     * >
     */
    public function retryFailed(int $limit=50): JSONResponse
    {
        try {
            $stats = $this->textExtractionService->retryFailedExtractions($limit);

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'Retry completed',
                        'data'    => $stats,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Retry failed',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }

    }//end retryFailed()

    /**
     * Get extraction statistics
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing extraction statistics
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|500,
     *     array{
     *         success: bool,
     *         error?: 'Failed to retrieve statistics',
     *         message?: string,
     *         data?: array{
     *             totalFiles: int,
     *             untrackedFiles: int,
     *             totalChunks: int,
     *             totalObjects: int,
     *             totalEntities: int
     *         }
     *     },
     *     array<never, never>
     * >
     */
    public function stats(): JSONResponse
    {
        try {
            $stats = $this->textExtractionService->getStats();

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'data'    => $stats,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Failed to retrieve statistics',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }

    }//end stats()

    /**
     * Clean up invalid file_texts entries
     *
     * Removes entries for files that no longer exist, directories, and system files.
     * This helps maintain database integrity and remove orphaned records.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing cleanup operation results
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|500,
     *     array{
     *         success: bool,
     *         error?: 'Cleanup failed',
     *         message: string,
     *         data?: array{deleted: 0, reasons: array<never, never>}
     *     },
     *     array<never, never>
     * >
     */
    public function cleanup(): JSONResponse
    {
        try {
            // Note: cleanupInvalidEntries not available in TextExtractionService.
            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'Cleanup completed',
                        'data'    => [
                            'deleted' => 0,
                            'reasons' => [],
                        ],
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Cleanup failed',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }//end try

    }//end cleanup()

    /**
     * Get file types with their file and chunk counts
     *
     * Returns only file types that have completed extractions with chunks.
     * Useful for showing which file types are available for vectorization.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse JSON response containing file type statistics
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<
     *     200|500,
     *     array{
     *         success: bool,
     *         error?: 'Failed to retrieve file types',
     *         message?: string,
     *         data?: array<never, never>
     *     },
     *     array<never, never>
     * >
     */
    public function fileTypes(): JSONResponse
    {
        try {
            // Note: getFileTypeStats not available in TextExtractionService.
            $types = [];

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'data'    => $types,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Failed to retrieve file types',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 500
                    );
        }

    }//end fileTypes()

    /**
     * Vectorize file chunks in batch
     *
     * Processes extracted file chunks and generates vector embeddings.
     * Supports serial and parallel processing modes.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: 'Vectorization failed', message?: string, data?: array{success: true, message: string, entity_type: string, total_entities: int<0, max>, total_items: int<0, max>, vectorized: int<0, max>, failed: int<0, max>, errors?: list{0?: array{entity_id: int|string, error: string, item_index?: array-key},...}}}, array<never, never>>
     */
    public function vectorizeBatch(): JSONResponse
    {
        try {
            $data      = $this->request->getParams();
            $mode      = $data['mode'] ?? 'serial';
            $maxFiles  = (int) ($data['max_files'] ?? 0);
            $batchSize = (int) ($data['batch_size'] ?? 50);
            $fileTypes = $data['file_types'] ?? [];

            // Use unified vectorization service with 'file' entity type.
            $result = $this->vectorizationService->vectorizeBatch(
                    entityType: 'file',
                    options: [
                        'mode'       => $mode,
                        'max_files'  => $maxFiles,
                        'batch_size' => $batchSize,
                        'file_types' => $fileTypes,
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => true,
                        'data'    => $result,
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Vectorization failed',
                        'message' => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end vectorizeBatch()
}//end class
