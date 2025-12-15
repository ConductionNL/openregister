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


    // ========================================================================
    // BACKEND ACCESS & DELEGATION METHODS (Restored for compatibility)
    // ========================================================================


    /**
     * Get the search backend instance.
     *
     * Provides direct access to the backend for advanced operations.
     *
     * @return SearchBackendInterface Search backend instance
     */
    public function getBackend(): SearchBackendInterface
    {
        return $this->searchBackend;

    }//end getBackend()


    /**
     * Search objects with pagination.
     *
     * Delegates to search backend.
     *
     * @param array       $query        Search query parameters
     * @param int         $limit        Maximum results per page
     * @param int         $offset       Offset for pagination
     * @param array       $facets       Facet configuration
     * @param string|null $collection   Collection name
     * @param bool        $includeTotal Whether to include total count
     *
     * @return array Search results with pagination info
     */
    public function searchObjectsPaginated(
        array $query=[],
        int $limit=30,
        int $offset=0,
        array $facets=[],
        ?string $collection=null,
        bool $includeTotal=true
    ): array {
        return $this->searchBackend->searchObjectsPaginated(
            query: $query,
            limit: $limit,
            offset: $offset,
            facets: $facets,
            collection: $collection,
            includeTotal: $includeTotal
        );

    }//end searchObjectsPaginated()


    /**
     * Get document count in the index.
     *
     * Returns the total number of documents currently indexed.
     *
     * @return int Document count
     */
    public function getDocumentCount(): int
    {
        return $this->searchBackend->getDocumentCount();

    }//end getDocumentCount()


    /**
     * Check if a collection exists.
     *
     * @param string $collectionName Collection name to check
     *
     * @return bool True if collection exists
     */
    public function collectionExists(string $collectionName): bool
    {
        return $this->searchBackend->collectionExists($collectionName);

    }//end collectionExists()


    /**
     * Create a new collection.
     *
     * @param string $name   Collection name
     * @param array  $config Configuration options
     *
     * @return array Creation result
     */
    public function createCollection(string $name, array $config=[]): array
    {
        return $this->searchBackend->createCollection($name, $config);

    }//end createCollection()


    /**
     * Test connectivity only (without collection tests).
     *
     * Quick connectivity check without full collection validation.
     *
     * @return array Connection test results
     */
    public function testConnectivityOnly(): array
    {
        return $this->testConnection(includeCollectionTests: false);

    }//end testConnectivityOnly()


    // ========================================================================
    // SOLR-SPECIFIC METHODS (Restored for compatibility)
    // ========================================================================


    /**
     * Ensure tenant-specific collection exists.
     *
     * Creates collection if it doesn't exist, for multi-tenancy support.
     * Only works with Solr backend.
     *
     * @param string|null $tenant Tenant identifier
     *
     * @return array Collection info
     *
     * @throws Exception If backend is not Solr
     */
    public function ensureTenantCollection(?string $tenant=null): array
    {
        $collectionName = $this->getTenantSpecificCollectionName($tenant);

        if ($this->collectionExists($collectionName) === false) {
            $this->createCollection($collectionName);
        }

        return [
            'collection' => $collectionName,
            'exists'     => true,
            'tenant'     => $tenant,
        ];

    }//end ensureTenantCollection()


    /**
     * Get tenant-specific collection name.
     *
     * Generates collection name based on tenant for multi-tenancy.
     *
     * @param string|null $tenant Tenant identifier (null for default)
     *
     * @return string Collection name
     */
    public function getTenantSpecificCollectionName(?string $tenant=null): string
    {
        $baseName = $this->getConfig()['collection'] ?? 'openregister';

        if ($tenant !== null && empty($tenant) === false) {
            return $baseName.'_'.$tenant;
        }

        return $baseName;

    }//end getTenantSpecificCollectionName()


    /**
     * Get Solr endpoint URL.
     *
     * Returns the base URL for Solr API endpoints.
     * Only works with Solr backend.
     *
     * @return string Endpoint URL
     *
     * @throws Exception If backend is not Solr
     */
    public function getEndpointUrl(): string
    {
        $config = $this->getSolrConfig();
        return $config['endpoint'] ?? '';

    }//end getEndpointUrl()


    /**
     * Build Solr base URL.
     *
     * Constructs the base URL for Solr operations.
     * Only works with Solr backend.
     *
     * @param string|null $collection Optional collection name
     *
     * @return string Solr base URL
     *
     * @throws Exception If backend is not Solr
     */
    public function buildSolrBaseUrl(?string $collection=null): string
    {
        $config  = $this->getSolrConfig();
        $baseUrl = rtrim($config['endpoint'] ?? '', '/');

        if ($collection !== null) {
            return $baseUrl.'/solr/'.$collection;
        }

        return $baseUrl.'/solr';

    }//end buildSolrBaseUrl()


    /**
     * Get Solr-specific configuration.
     *
     * Returns configuration specific to Solr backend.
     * Only works with Solr backend.
     *
     * @return array Solr configuration
     *
     * @throws Exception If backend is not Solr
     */
    public function getSolrConfig(): array
    {
        $config = $this->getConfig();

        // Extract Solr-specific config.
        return [
            'endpoint'   => $config['endpoint'] ?? '',
            'collection' => $config['collection'] ?? 'openregister',
            'username'   => $config['username'] ?? '',
            'password'   => $config['password'] ?? '',
            'timeout'    => $config['timeout'] ?? 30,
        ];

    }//end getSolrConfig()


    /**
     * Get HTTP client for Solr operations.
     *
     * Returns the HTTP client used for Solr communication.
     * Only works with Solr backend.
     *
     * @return object HTTP client instance
     *
     * @throws Exception If backend is not Solr or client not available
     */
    public function getHttpClient(): object
    {
        // Check if backend is Solr and has getHttpClient method.
        if (method_exists($this->searchBackend, 'getHttpClient') === true) {
            return $this->searchBackend->getHttpClient();
        }

        throw new Exception('HTTP client not available for current backend');

    }//end getHttpClient()


}//end class
