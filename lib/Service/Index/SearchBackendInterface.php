<?php
/**
 * OpenRegister SearchBackendInterface
 *
 * Interface for search backend implementations (Solr, Elasticsearch, PostgreSQL, etc.).
 *
 * This interface defines the contract that all search backends must implement,
 * allowing the IndexService to be backend-agnostic and support multiple search
 * technologies without code changes.
 *
 * @category Interface
 * @package  OCA\OpenRegister\Service\Index
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Search Backend Interface
 *
 * Defines the contract for search backend implementations. All search backends
 * (Solr, Elasticsearch, PostgreSQL with pg_trgm, etc.) must implement this interface.
 *
 * DESIGN PRINCIPLES:
 * - Backend-agnostic: Methods should work regardless of underlying technology.
 * - Performance-focused: Support bulk operations and batch processing.
 * - Flexible querying: Support complex queries with filters, facets, and sorting.
 * - Health monitoring: Provide connection testing and health check capabilities.
 *
 * IMPLEMENTATION NOTES:
 * - Implementations should handle backend-specific query syntax internally.
 * - Error handling should be consistent across all backends.
 * - Return formats should be normalized to OpenRegister format.
 *
 * @category Interface
 * @package  OCA\OpenRegister\Service\Index
 */
interface SearchBackendInterface
{


    /**
     * Test if the backend is available and configured.
     *
     * @param bool $forceRefresh Whether to bypass cache and test fresh connection.
     *
     * @return bool True if backend is available, false otherwise.
     */
    public function isAvailable(bool $forceRefresh=false): bool;


    /**
     * Test the connection to the backend with detailed diagnostics.
     *
     * @param bool $includeCollectionTests Whether to include collection-level tests.
     *
     * @return array Test results with status, timing, and error information.
     */
    public function testConnection(bool $includeCollectionTests=true): array;


    /**
     * Index a single object in the search backend.
     *
     * @param ObjectEntity $object The object to index.
     * @param bool         $commit Whether to commit immediately (may impact performance).
     *
     * @return bool True if indexing succeeded, false otherwise.
     */
    public function indexObject(ObjectEntity $object, bool $commit=false): bool;


    /**
     * Index multiple objects in bulk.
     *
     * @param array $objects Array of ObjectEntity instances to index.
     * @param bool  $commit  Whether to commit immediately after bulk index.
     *
     * @return array Result with success count, failure count, and errors.
     */
    public function bulkIndexObjects(array $objects, bool $commit=true): array;


    /**
     * Delete an object from the search index.
     *
     * @param string|int $objectId The ID of the object to delete.
     * @param bool       $commit   Whether to commit immediately.
     *
     * @return bool True if deletion succeeded, false otherwise.
     */
    public function deleteObject(string|int $objectId, bool $commit=false): bool;


    /**
     * Delete multiple objects by query.
     *
     * @param string $query         Backend-specific query string.
     * @param bool   $commit        Whether to commit immediately.
     * @param bool   $returnDetails Whether to return detailed results.
     *
     * @return array|bool Results array if returnDetails=true, bool otherwise.
     */
    public function deleteByQuery(string $query, bool $commit=false, bool $returnDetails=false): array|bool;


    /**
     * Search objects with pagination support.
     *
     * @param array $query         Query parameters (filters, pagination, facets, etc.).
     * @param bool  $_rbac         Whether to apply RBAC filtering.
     * @param bool  $_multitenancy Whether to apply multitenancy filtering.
     * @param bool  $published     Whether to filter for published objects only.
     * @param bool  $deleted       Whether to include deleted objects.
     *
     * @return array Search results with objects, pagination, and facets.
     */
    public function searchObjectsPaginated(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $published=false,
        bool $deleted=false
    ): array;


    /**
     * Get the total count of indexed documents.
     *
     * @return int Total document count.
     */
    public function getDocumentCount(): int;


    /**
     * Commit pending changes to the index.
     *
     * @return bool True if commit succeeded, false otherwise.
     */
    public function commit(): bool;


    /**
     * Optimize the search index for better performance.
     *
     * @return bool True if optimization succeeded, false otherwise.
     */
    public function optimize(): bool;


    /**
     * Clear all documents from the index.
     *
     * @param string|null $collectionName Optional collection/index name to clear.
     *
     * @return array Results with count of deleted documents.
     */
    public function clearIndex(?string $collectionName=null): array;


    /**
     * Warm up the index by pre-loading data into cache.
     *
     * @param array  $schemas       Array of schema IDs to warm up.
     * @param int    $maxObjects    Maximum number of objects to warm up.
     * @param string $mode          Warmup mode (serial, parallel, etc.).
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
    ): array;


    /**
     * Get backend configuration.
     *
     * @return array Backend configuration array.
     */
    public function getConfig(): array;


    /**
     * Get statistics about the search backend.
     *
     * @return array Backend statistics (doc count, size, performance metrics, etc.).
     */
    public function getStats(): array;


    /**
     * Create a collection/index in the backend.
     *
     * @param string $name   Name of the collection/index to create.
     * @param array  $config Configuration for the collection.
     *
     * @return array Creation results.
     */
    public function createCollection(string $name, array $config=[]): array;


    /**
     * Delete a collection/index from the backend.
     *
     * @param string|null $collectionName Name of collection to delete, null for default.
     *
     * @return array Deletion results.
     */
    public function deleteCollection(?string $collectionName=null): array;


    /**
     * Check if a collection/index exists.
     *
     * @param string $collectionName Name of the collection to check.
     *
     * @return bool True if collection exists, false otherwise.
     */
    public function collectionExists(string $collectionName): bool;


    /**
     * List all collections/indices in the backend.
     *
     * @return array Array of collection names.
     */
    public function listCollections(): array;


    /**
     * Index generic documents (not ObjectEntity).
     *
     * Used by FileHandler for indexing file chunks.
     *
     * @param array $documents Array of documents to index
     *
     * @return bool True if successful
     */
    public function index(array $documents): bool;


    /**
     * Perform a generic search query.
     *
     * Used by handlers for custom search queries.
     *
     * @param array $params Search parameters
     *
     * @return array Search results
     */
    public function search(array $params): array;


    /**
     * Get field types for a collection.
     *
     * Used by SchemaHandler for schema management.
     *
     * @param string $collection Collection name
     *
     * @return array Field types indexed by name
     */
    public function getFieldTypes(string $collection): array;


    /**
     * Add a new field type to a collection.
     *
     * Used by SchemaHandler for creating custom field types like knn_vector.
     *
     * @param string $collection Collection name
     * @param array  $fieldType  Field type definition
     *
     * @return bool True if successful
     */
    public function addFieldType(string $collection, array $fieldType): bool;


    /**
     * Get fields for a collection.
     *
     * Used by SchemaHandler for checking existing fields.
     *
     * @param string $collection Collection name
     *
     * @return array Fields indexed by name
     */
    public function getFields(string $collection): array;


    /**
     * Add or update a field in a collection.
     *
     * Used by SchemaHandler for managing collection schema.
     *
     * @param array $fieldConfig Field configuration
     * @param bool  $force       Force update if exists
     *
     * @return string Action taken ('created', 'updated', 'skipped')
     */
    public function addOrUpdateField(array $fieldConfig, bool $force): string;


    /**
     * Reindex all objects in the system.
     *
     * Clears the index and reindexes all searchable objects from the database.
     *
     * @param int         $maxObjects     Maximum objects to reindex (0 = all).
     * @param int         $batchSize      Batch size for reindexing.
     * @param string|null $collectionName Optional collection name.
     *
     * @return array Reindexing results with statistics.
     */
    public function reindexAll(int $maxObjects=0, int $batchSize=1000, ?string $collectionName=null): array;


}//end interface
