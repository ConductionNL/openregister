<?php

/**
 * OpenRegister File Settings Controller
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Exception;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\IndexService;
use Psr\Log\LoggerInterface;

/**
 * Controller for file processing settings.
 *
 * Handles:
 * - File extraction configuration
 * - Text extraction services (Dolphin, etc.)
 * - File indexing operations
 * - File processing statistics
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class FileSettingsController extends Controller
{


    /**
     * Constructor.
     *
     * @param string             $appName         The app name.
     * @param IRequest           $request         The request.
     * @param ContainerInterface $container       DI container.
     * @param SettingsService    $settingsService Settings service.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()


}//end class


    /**
     * Get File Management settings
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse File settings
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getFileSettings(): JSONResponse
    {
    try {
        $data = $this->settingsService->getFileSettingsOnly();
        return new JSONResponse(data: $data);
    } catch (Exception $e) {
        return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
    }

    }//end getFileSettings()


    /**
     * Update File Management settings
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated file settings
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, message?: 'File settings updated successfully', data?: array}, array<never, never>>
     */
    public function updateFileSettings(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            // Extract IDs from objects sent by frontend.
            if (($data['provider'] ?? null) !== null && is_array($data['provider']) === true) {
                $data['provider'] = $data['provider']['id'] ?? null;
            }

            if (($data['chunkingStrategy'] ?? null) !== null && is_array($data['chunkingStrategy']) === true) {
                $data['chunkingStrategy'] = $data['chunkingStrategy']['id'] ?? null;
            }

            $result = $this->settingsService->updateFileSettingsOnly($data);
            return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'File settings updated successfully',
                        'data'    => $result,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end updateFileSettings()


    /**
     * Test Dolphin API connection
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @param string $apiEndpoint Dolphin API endpoint URL
     * @param string $apiKey      Dolphin API key
     *
     * @return JSONResponse
     *
     * @psalm-return JSONResponse<200|400|500, array{success: bool, error?: string, message?: 'Dolphin connection successful'}, array<never, never>>
     */
    public function testDolphinConnection(string $apiEndpoint, string $apiKey): JSONResponse
    {
        try {
            // Validate inputs.
            if (empty($apiEndpoint) === true || empty($apiKey) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'API endpoint and API key are required',
                        ],
                        statusCode: 400
                    );
            }

            // Test the connection by making a simple request.
            $ch = curl_init($apiEndpoint.'/health');
            curl_setopt_array(
                    $ch,
                    [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER     => [
                            'Authorization: Bearer '.$apiKey,
                            'Content-Type: application/json',
                        ],
                        CURLOPT_TIMEOUT        => 10,
                        CURLOPT_SSL_VERIFYPEER => true,
                    ]
                    );

            curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Connection failed: '.$curlError,
                        ]
                        );
            }

            if ($httpCode === 200 || $httpCode === 201) {
                return new JSONResponse(
                        data: [
                            'success' => true,
                            'message' => 'Dolphin connection successful',
                        ]
                        );
            } else {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'error'   => 'Dolphin API returned HTTP '.$httpCode,
                        ]
                        );
            }
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end testDolphinConnection()


    /**
     * Get file collection field status
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Field status for file collection
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, message?: string, collection?: 'files', status?: mixed}, array<never, never>>
     */
    public function getFileCollectionFields(): JSONResponse
    {
        try {
            $solrSchemaService = $this->container->get(IndexService::class);
            $status            = $solrSchemaService->getFileCollectionFieldStatus();

            return new JSONResponse(
                    data: [
                        'success'    => true,
                        'collection' => 'files',
                        'status'     => $status,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to get file collection field status: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }

    }//end getFileCollectionFields()


    /**
     * Create missing fields in file collection
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Creation results
     *
     * @psalm-return JSONResponse<200|400|500, array{success: false|mixed, message: string, collection?: 'files'}, array<never, never>>
     */
    public function createMissingFileFields(): JSONResponse
    {
        try {
            $solrSchemaService = $this->container->get(IndexService::class);
            $guzzleSolrService = $this->container->get(IndexService::class);

            // Switch to file collection.
            $fileCollection = $this->settingsService->getSolrSettingsOnly()['fileCollection'] ?? null;
            if ($fileCollection === null || $fileCollection === '') {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'File collection not configured',
                        ],
                        statusCode: 400
                    );
            }

            // Set active collection to file collection temporarily.
            $originalCollection = $guzzleSolrService->getActiveCollectionName();
            $guzzleSolrService->setActiveCollection($fileCollection);

            // Create missing file metadata fields using reflection to call private method.
            $reflection = new ReflectionClass($solrSchemaService);
            $method     = $reflection->getMethod('ensureFileMetadataFields');
            $result     = $method->invoke($solrSchemaService, true);

            // Restore original collection.
            $guzzleSolrService->setActiveCollection($originalCollection);

            // Determine message based on result.
            if ($result === true) {
                $message = 'File metadata fields ensured successfully';
            } else {
                $message = 'Failed to ensure file metadata fields';
            }

            return new JSONResponse(
                    data: [
                        'success'    => $result,
                        'collection' => 'files',
                        'message'    => $message,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to create missing file fields: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end createMissingFileFields()


    /**
     * Warmup files - Extract text and index in SOLR file collection
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Warmup results
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, message: string, files_processed?: int<0, max>, indexed?: 0|mixed, failed?: 0|mixed, errors?: array, mode?: mixed}, array<never, never>>
     */
    public function warmupFiles(): JSONResponse
    {
        try {
            // Get request parameters.
            $maxFiles  = (int) $this->request->getParam('max_files', 100);
            $batchSize = (int) $this->request->getParam('batch_size', 50);
            // Note: file_types parameter not currently used.
            $skipIndexed = $this->request->getParam('skip_indexed', true);
            $mode        = $this->request->getParam('mode', 'parallel');

            // Validate parameters.
            $maxFiles = min($maxFiles, 5000);
            // Max 5000 files.
            $batchSize = min($batchSize, 500);
            // Max 500 per batch.
            $this->logger->info(
                    '[SettingsController] Starting file warmup',
                    [
                        'max_files'    => $maxFiles,
                        'batch_size'   => $batchSize,
                        'skip_indexed' => $skipIndexed,
                    ]
                    );

            // Get IndexService and TextExtractionService.
            $guzzleSolrService     = $this->container->get(IndexService::class);
            $textExtractionService = $this->container->get(\OCA\OpenRegister\Service\TextExtractionService::class);

            // Get files that need processing.
            $filesToProcess = [];
            if ($skipIndexed === true) {
                $notIndexed = $textExtractionService->findNotIndexedInSolr('file', $maxFiles);
                foreach ($notIndexed as $fileId) {
                    $filesToProcess[] = $fileId;
                }
            } else {
                $completed = $textExtractionService->findByStatus('file', 'completed', $maxFiles, 0);
                foreach ($completed as $fileId) {
                    $filesToProcess[] = $fileId;
                }
            }

            // If no files to process, return early.
            if (empty($filesToProcess) === true) {
                return new JSONResponse(
                        data: [
                            'success'         => true,
                            'message'         => 'No files to process',
                            'files_processed' => 0,
                            'indexed'         => 0,
                            'failed'          => 0,
                        ]
                        );
            }

            // Process files in batches.
            $totalIndexed = 0;
            $totalFailed  = 0;
            $allErrors    = [];

            $batches = array_chunk($filesToProcess, $batchSize);
            foreach ($batches as $batch) {
                $result        = $guzzleSolrService->indexFiles($batch);
                $totalIndexed += $result['indexed'];
                $totalFailed  += $result['failed'];
                $allErrors     = array_merge($allErrors, $result['errors']);
            }

            return new JSONResponse(
                    data: [
                        'success'         => true,
                        'message'         => 'File warmup completed',
                        'files_processed' => count($filesToProcess),
                        'indexed'         => $totalIndexed,
                        'failed'          => $totalFailed,
                        'errors'          => array_slice($allErrors, 0, 20),
            // First 20 errors.
                        'mode'            => $mode,
                    ]
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    '[SettingsController] File warmup failed',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'File warmup failed: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end warmupFiles()


    /**
     * Index a specific file in SOLR
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @param int $fileId File ID to index
     *
     * @return JSONResponse Indexing result
     *
     * @psalm-return JSONResponse<200|422|500, array{success: bool, message: mixed|string, file_id?: int}, array<never, never>>
     */
    public function indexFile(int $fileId): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(IndexService::class);

            $result = $guzzleSolrService->indexFiles([$fileId]);

            if ($result['indexed'] > 0) {
                return new JSONResponse(
                        data: [
                            'success' => true,
                            'message' => 'File indexed successfully',
                            'file_id' => $fileId,
                        ]
                        );
            } else {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => $result['errors'][0] ?? 'Failed to index file',
                            'file_id' => $fileId,
                        ],
                        statusCode: 422
                        );
            }
        } catch (Exception $e) {
            $this->logger->error(
                    '[SettingsController] Failed to index file',
                    [
                        'file_id' => $fileId,
                        'error'   => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to index file: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end indexFile()


    /**
     * Reindex all files
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Reindex results
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, message: string, indexed?: 0|mixed, files_processed?: int<0, max>, failed?: mixed, errors?: array}, array<never, never>>
     */
    public function reindexFiles(): JSONResponse
    {
        try {
            // Get all completed file texts.
            $textExtractionService = $this->container->get(\OCA\OpenRegister\Service\TextExtractionService::class);
            $guzzleSolrService     = $this->container->get(IndexService::class);

            $maxFiles  = (int) $this->request->getParam('max_files', 1000);
            $batchSize = (int) $this->request->getParam('batch_size', 100);

            // Get all completed extractions.
            $fileIds = $textExtractionService->findByStatus('file', 'completed', $maxFiles, 0);

            if (empty($fileIds) === true) {
                return new JSONResponse(
                        data: [
                            'success' => true,
                            'message' => 'No files to reindex',
                            'indexed' => 0,
                        ]
                        );
            }

            // Process in batches.
            $totalIndexed = 0;
            $totalFailed  = 0;
            $allErrors    = [];

            $batches = array_chunk($fileIds, $batchSize);
            foreach ($batches as $batch) {
                $result        = $guzzleSolrService->indexFiles($batch);
                $totalIndexed += $result['indexed'];
                $totalFailed  += $result['failed'];
                $allErrors     = array_merge($allErrors, $result['errors']);
            }

            return new JSONResponse(
                    data: [
                        'success'         => true,
                        'message'         => 'Reindex completed',
                        'files_processed' => count($fileIds),
                        'indexed'         => $totalIndexed,
                        'failed'          => $totalFailed,
                        'errors'          => array_slice($allErrors, 0, 20),
                    ]
                    );
        } catch (Exception $e) {
            $this->logger->error(
                    '[SettingsController] Reindex files failed',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Reindex failed: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end reindexFiles()


    /**
     * Get file index statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse File index statistics
     *
     * @psalm-return JSONResponse<200, array<array-key, mixed>, array<never, never>>|JSONResponse<500, array{success: false, message: string}, array<never, never>>
     */
    public function getFileIndexStats(): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(IndexService::class);
            $stats = $guzzleSolrService->getFileIndexStats();

            return new JSONResponse(data: $stats);
        } catch (Exception $e) {
            $this->logger->error(
                    '[SettingsController] Failed to get file index stats',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to get statistics: '.$e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end getFileIndexStats()


    /**
     * Get file extraction statistics
     *
     * Combines multiple data sources for comprehensive file statistics:
     * - FileMapper: Total files in Nextcloud (from oc_filecache, bypasses rights logic)
     * - FileTextMapper: Extraction status (from oc_openregister_file_texts)
     * - IndexService: Chunk statistics (from SOLR index)
     *
     * This provides accurate statistics without dealing with Nextcloud's extensive rights logic.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse File extraction statistics including: - totalFiles: All files in Nextcloud (from oc_filecache) - processedFiles: Files tracked in extraction system (from oc_openregister_file_texts) - pendingFiles: Files discovered and waiting for extraction (status='pending') - untrackedFiles: Files in Nextcloud not yet discovered - totalChunks: Number of text chunks in SOLR (one file = multiple chunks) - completed, failed, indexed, processing, vectorized: Detailed processing status counts
     *
     * @psalm-return JSONResponse<200, array{success: true, totalFiles: 0|mixed, processedFiles: 0|mixed, pendingFiles: 0|mixed, untrackedFiles: 0|mixed, totalChunks: 0|mixed, extractedTextStorageMB: string, totalFilesStorageMB: string, completed: 0|mixed, failed: 0|mixed, indexed: 0|mixed, processing: 0|mixed, vectorized: 0|mixed, error?: string}, array<never, never>>
     */
    public function getFileExtractionStats(): JSONResponse
    {
        try {
            // Get total files from Nextcloud filecache (bypasses rights logic).
            $fileMapper            = $this->container->get(\OCA\OpenRegister\Db\FileMapper::class);
            $totalFilesInNextcloud = $fileMapper->countAllFiles();
            $totalFilesSize        = $fileMapper->getTotalFilesSize();

            // Get extraction statistics from our file_texts table.
            $textExtractionService = $this->container->get(\OCA\OpenRegister\Service\TextExtractionService::class);
            $dbStats = $textExtractionService->getExtractionStats('file');

            // Get SOLR statistics.
            $guzzleSolrService = $this->container->get(IndexService::class);
            $solrStats         = $guzzleSolrService->getFileIndexStats();

            // Calculate storage in MB.
            $extractedTextStorageMB = round($dbStats['total_text_size'] / 1024 / 1024, 2);
            $totalFilesStorageMB    = round($totalFilesSize / 1024 / 1024, 2);

            // Calculate untracked files (files in Nextcloud not yet discovered).
            $untrackedFiles = $totalFilesInNextcloud - $dbStats['total'];

            return new JSONResponse(
                    data: [
                        'success'                => true,
                        'totalFiles'             => $totalFilesInNextcloud,
                        'processedFiles'         => $dbStats['completed'],
            // Files successfully extracted (status='completed').
                        'pendingFiles'           => $dbStats['pending'],
            // Files discovered and waiting for extraction.
                        'untrackedFiles'         => max(0, $untrackedFiles),
            // Files not yet discovered.
                        'totalChunks'            => $solrStats['total_chunks'] ?? 0,
                        'extractedTextStorageMB' => number_format($extractedTextStorageMB, 2),
                        'totalFilesStorageMB'    => number_format($totalFilesStorageMB, 2),
                        'completed'              => $dbStats['completed'],
                        'failed'                 => $dbStats['failed'],
                        'indexed'                => $dbStats['indexed'],
                        'processing'             => $dbStats['processing'],
                        'vectorized'             => $dbStats['vectorized'],
                    ]
                    );
        } catch (Exception $e) {
            // Return zeros instead of error to avoid breaking UI.
            return new JSONResponse(
                    data: [
                        'success'                => true,
                        'totalFiles'             => 0,
                        'processedFiles'         => 0,
                        'pendingFiles'           => 0,
                        'untrackedFiles'         => 0,
                        'totalChunks'            => 0,
                        'extractedTextStorageMB' => '0.00',
                        'totalFilesStorageMB'    => '0.00',
                        'completed'              => 0,
                        'failed'                 => 0,
                        'indexed'                => 0,
                        'processing'             => 0,
                        'vectorized'             => 0,
                        'error'                  => $e->getMessage(),
                    ]
                    );
        }//end try

    }//end getFileExtractionStats()


    }//end class
