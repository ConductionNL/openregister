<?php
/**
 * IndexService
 *
 * Main service for search indexing operations.
 * Acts as a facade coordinating FileHandler, ObjectHandler, and SchemaHandler.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Index\FileHandler;
use OCA\OpenRegister\Service\Index\ObjectHandler;
use OCA\OpenRegister\Service\Index\SchemaHandler;
use OCA\OpenRegister\Service\Index\SearchBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * IndexService
 *
 * Coordinates indexing operations across different entity types.
 *
 * ARCHITECTURE:
 * - Acts as main entry point for indexing operations.
 * - Delegates to specialized handlers (FileHandler, ObjectHandler, SchemaHandler).
 * - Provides unified API for controllers and other services.
 * - Does NOT extract text or vectorize - only indexes existing database data.
 *
 * RESPONSIBILITIES:
 * - Coordinate file chunk indexing (via FileHandler).
 * - Coordinate object indexing and search (via ObjectHandler).
 * - Coordinate schema management (via SchemaHandler).
 * - Provide unified statistics and health checks.
 * - Listen to database events and trigger indexing.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class IndexService
{


    /**
     * Constructor
     *
     * @param FileHandler            $fileHandler   Handler for file/chunk operations
     * @param ObjectHandler          $objectHandler Handler for object operations
     * @param SchemaHandler          $schemaHandler Handler for schema operations
     * @param SearchBackendInterface $searchBackend Search backend implementation
     * @param LoggerInterface        $logger        Logger
     */
    public function __construct(
        private readonly FileHandler $fileHandler,
        private readonly ObjectHandler $objectHandler,
        private readonly SchemaHandler $schemaHandler,
        private readonly SearchBackendInterface $searchBackend,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    // ========================================================================
    // FILE OPERATIONS
    // ========================================================================


    /**
     * Index file chunks to search backend.
     *
     * Delegates to FileHandler.
     *
     * @param int   $fileId   File ID
     * @param array $chunks   Array of chunk entities from database
     * @param array $metadata File metadata
     *
     * @return array Indexing result
     */
    public function indexFileChunks(int $fileId, array $chunks, array $metadata): array
    {
        return $this->fileHandler->indexFileChunks(fileId: $fileId, chunks: $chunks, metadata: $metadata);

    }//end indexFileChunks()


    /**
     * Process and index unindexed file chunks.
     *
     * Delegates to FileHandler.
     *
     * @param int|null $limit Maximum number of files to process
     *
     * @return array Processing result
     */
    public function processUnindexedChunks(?int $limit=null): array
    {
        return $this->fileHandler->processUnindexedChunks(limit: $limit);

    }//end processUnindexedChunks()


    /**
     * Get file indexing statistics.
     *
     * Delegates to FileHandler.
     *
     * @return array Statistics
     */
    public function getFileStats(): array
    {
        return $this->fileHandler->getFileStats();

    }//end getFileStats()


    /**
     * Get chunking statistics.
     *
     * Delegates to FileHandler.
     *
     * @return array Statistics
     */
    public function getChunkingStats(): array
    {
        return $this->fileHandler->getChunkingStats();

    }//end getChunkingStats()


    // ========================================================================
    // OBJECT OPERATIONS
    // ========================================================================


    /**
     * Search objects in search backend.
     *
     * Delegates to ObjectHandler.
     *
     * @param array $query        Search query
     * @param bool  $rbac         Apply RBAC filters
     * @param bool  $multitenancy Apply multitenancy filters
     * @param bool  $published    Filter published objects
     * @param bool  $deleted      Include deleted objects
     *
     * @return array Search results
     */
    public function searchObjects(
        array $query=[],
        bool $rbac=true,
        bool $multitenancy=true,
        bool $published=false,
        bool $deleted=false
    ): array {
        return $this->objectHandler->searchObjects(
            query: $query,
            rbac: $rbac,
            multitenancy: $multitenancy,
            published: $published,
            deleted: $deleted
        );

    }//end searchObjects()


    /**
     * Commit pending changes to search backend.
     *
     * Delegates to ObjectHandler.
     *
     * @return bool Success status
     */
    public function commit(): bool
    {
        return $this->objectHandler->commit();

    }//end commit()


    // ========================================================================
    // SCHEMA OPERATIONS
    // ========================================================================


    /**
     * Ensure vector field type exists in a collection.
     *
     * Delegates to SchemaHandler.
     *
     * @param string $collection Collection name
     * @param int    $dimensions Vector dimensions
     * @param string $similarity Similarity function
     *
     * @return bool Success status
     */
    public function ensureVectorFieldType(
        string $collection,
        int $dimensions=4096,
        string $similarity='cosine'
    ): bool {
        return $this->schemaHandler->ensureVectorFieldType(
            collection: $collection,
            dimensions: $dimensions,
            similarity: $similarity
        );

    }//end ensureVectorFieldType()


    /**
     * Mirror OpenRegister schemas to search backend.
     *
     * Delegates to SchemaHandler.
     *
     * @param bool $force Force recreation of existing fields
     *
     * @return array Result with statistics
     */
    public function mirrorSchemas(bool $force=false): array
    {
        return $this->schemaHandler->mirrorSchemas(force: $force);

    }//end mirrorSchemas()


    /**
     * Get collection field status.
     *
     * Delegates to SchemaHandler.
     *
     * @param string $collection Collection name
     *
     * @return array Field status information
     */
    public function getCollectionFieldStatus(string $collection): array
    {
        return $this->schemaHandler->getCollectionFieldStatus(collection: $collection);

    }//end getCollectionFieldStatus()


    /**
     * Create missing fields in a collection.
     *
     * Delegates to SchemaHandler.
     *
     * @param string $collection    Collection name
     * @param array  $missingFields Missing field definitions
     * @param bool   $dryRun        Preview without making changes
     *
     * @return array Result
     */
    public function createMissingFields(string $collection, array $missingFields, bool $dryRun=false): array
    {
        return $this->schemaHandler->createMissingFields(
            collection: $collection,
            missingFields: $missingFields,
            dryRun: $dryRun
        );

    }//end createMissingFields()


    // ========================================================================
    // GENERAL OPERATIONS
    // ========================================================================


    /**
     * Check if search backend is available.
     *
     * @param bool $forceRefresh Force fresh check
     *
     * @return bool Availability status
     */
    public function isAvailable(bool $forceRefresh=false): bool
    {
        try {
            return $this->searchBackend->isAvailable(forceRefresh: $forceRefresh);
        } catch (Exception $e) {
            $this->logger->error(
                '[IndexService] Failed to check availability',
                [
                        'error' => $e->getMessage(),
                    ]
            );
            return false;
        }//end try

    }//end isAvailable()


    /**
     * Test connection to search backend.
     *
     * @param bool $includeCollectionTests Include collection tests
     *
     * @return array Test results
     */
    public function testConnection(bool $includeCollectionTests=true): array
    {
        try {
            return $this->searchBackend->testConnection(includeCollectionTests: $includeCollectionTests);
        } catch (Exception $e) {
            $this->logger->error(
                '[IndexService] Connection test failed',
                [
                        'error' => $e->getMessage(),
                    ]
            );

            return [
                'available' => false,
                'error'     => $e->getMessage(),
            ];
        }//end try

    }//end testConnection()


    /**
     * Get search backend statistics.
     *
     * @return array Statistics
     */
    public function getStats(): array
    {
        try {
            return $this->searchBackend->getStats();
        } catch (Exception $e) {
            $this->logger->error(
                '[IndexService] Failed to get stats',
                [
                        'error' => $e->getMessage(),
                    ]
            );

            return [
                'available' => false,
                'error'     => $e->getMessage(),
            ];
        }//end try

    }//end getStats()


    /**
     * Get comprehensive dashboard statistics.
     *
     * Combines statistics from all handlers.
     *
     * @return array Dashboard statistics
     */
    public function getDashboardStats(): array
    {
        try {
            $backendStats = $this->searchBackend->getStats();
            $fileStats    = $this->fileHandler->getFileStats();
            $chunkStats   = $this->fileHandler->getChunkingStats();

            return [
                'available' => $this->isAvailable(),
                'backend'   => $backendStats,
                'files'     => $fileStats,
                'chunks'    => $chunkStats,
            ];
        } catch (Exception $e) {
            $this->logger->error(
                '[IndexService] Failed to get dashboard stats',
                [
                        'error' => $e->getMessage(),
                    ]
            );

            return [
                'available' => false,
                'error'     => $e->getMessage(),
            ];
        }//end try

    }//end getDashboardStats()


    /**
     * Optimize the search backend.
     *
     * @return bool Success status
     */
    public function optimize(): bool
    {
        try {
            return $this->searchBackend->optimize();
        } catch (Exception $e) {
            $this->logger->error(
                '[IndexService] Optimization failed',
                [
                        'error' => $e->getMessage(),
                    ]
            );
            return false;
        }//end try

    }//end optimize()


    /**
     * Clear all documents from a collection.
     *
     * @param string|null $collectionName Collection to clear
     *
     * @return array Result
     */
    public function clearIndex(?string $collectionName=null): array
    {
        try {
            return $this->searchBackend->clearIndex(collectionName: $collectionName);
        } catch (Exception $e) {
            $this->logger->error(
                '[IndexService] Failed to clear index',
                [
                        'collection' => $collectionName,
                        'error'      => $e->getMessage(),
                    ]
            );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try

    }//end clearIndex()


    /**
     * Get search backend configuration.
     *
     * @return array Configuration
     */
    public function getConfig(): array
    {
        try {
            return $this->searchBackend->getConfig();
        } catch (Exception $e) {
            $this->logger->error(
                '[IndexService] Failed to get config',
                [
                        'error' => $e->getMessage(),
                    ]
            );

            return [];
        }//end try

    }//end getConfig()


    /**
     * Reindex all objects in the system.
     *
     * Delegates to ObjectHandler for full reindexing operation.
     *
     * @param int         $maxObjects     Maximum objects to reindex (0 = all).
     * @param int         $batchSize      Batch size for reindexing.
     * @param string|null $collectionName Optional collection name.
     *
     * @return array Reindexing results with statistics.
     */
    public function reindexAll(int $maxObjects=0, int $batchSize=1000, ?string $collectionName=null): array
    {
        return $this->objectHandler->reindexAll(
            maxObjects: $maxObjects,
            batchSize: $batchSize,
            collectionName: $collectionName
        );

    }//end reindexAll()


    /**
     * Fix mismatched fields in the schema.
     *
     * Delegates to SchemaHandler for field type corrections.
     *
     * @param array $mismatchedFields Fields to fix.
     * @param bool  $dryRun           Whether to only simulate (not apply).
     *
     * @return array Results with fixed/failed fields.
     */
    public function fixMismatchedFields(array $mismatchedFields, bool $dryRun=false): array
    {
        return $this->schemaHandler->fixMismatchedFields(
            mismatchedFields: $mismatchedFields,
            dryRun: $dryRun
        );

    }//end fixMismatchedFields()


    /**
     * Index files by their IDs.
     *
     * Delegates to FileHandler for file indexing operations.
     *
     * @param array       $fileIds        Array of file IDs to index.
     * @param string|null $collectionName Optional collection name.
     *
     * @return array Indexing results.
     */
    public function indexFiles(array $fileIds, ?string $collectionName=null): array
    {
        return $this->fileHandler->indexFiles(
            fileIds: $fileIds,
            collectionName: $collectionName
        );

    }//end indexFiles()


    /**
     * Get file indexing statistics.
     *
     * Delegates to FileHandler for file index statistics.
     *
     * @return array File indexing stats.
     */
    public function getFileIndexStats(): array
    {
        return $this->fileHandler->getFileIndexStats();

    }//end getFileIndexStats()


    /**
     * Warm up the search index.
     *
     * Pre-loads data into cache and performs initial indexing operations.
     *
     * @param array  $schemas       Array of schema IDs to warm up.
     * @param int    $maxObjects    Maximum number of objects to warm up.
     * @param string $mode          Warmup mode (serial, parallel, hyper).
     * @param bool   $collectErrors Whether to collect detailed error information.
     * @param int    $batchSize     Batch size for warmup operations.
     * @param array  $schemaIds     Schema IDs to filter warmup.
     *
     * @return array Warmup results with statistics and errors.
     */
    public function warmupIndex(
        array $schemas=[],
        int $maxObjects=0,
        string $mode='serial',
        bool $collectErrors=false,
        int $batchSize=1000,
        array $schemaIds=[]
    ): array {
        return $this->searchBackend->warmupIndex(
            schemas: $schemas,
            maxObjects: $maxObjects,
            mode: $mode,
            collectErrors: $collectErrors,
            batchSize: $batchSize,
            schemaIds: $schemaIds
        );

    }//end warmupIndex()


}//end class
