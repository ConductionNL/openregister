<?php

/**
 * ElasticsearchBackend
 *
 * Elasticsearch backend implementation for OpenRegister search operations.
 *
<<<<<<< HEAD
=======
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
>>>>>>> origin/development
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index\Backends
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-88
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-88
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index\Backends;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Index\SearchBackendInterface;
use OCA\OpenRegister\Service\Index\Backends\Elasticsearch\ElasticsearchHttpClient;
use OCA\OpenRegister\Service\Index\Backends\Elasticsearch\ElasticsearchIndexManager;
use OCA\OpenRegister\Service\Index\Backends\Elasticsearch\ElasticsearchDocumentIndexer;
use OCA\OpenRegister\Service\Index\Backends\Elasticsearch\ElasticsearchQueryExecutor;
use Psr\Log\LoggerInterface;

/**
 * ElasticsearchBackend
 *
 * Thin coordinator that implements SearchBackendInterface by delegating
 * to specialized Elasticsearch service classes.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Implements SearchBackendInterface with many required methods
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 */
class ElasticsearchBackend implements SearchBackendInterface
{

    /**
     * Elasticsearch HTTP client for making requests
     *
     * @var ElasticsearchHttpClient
     */
    private readonly ElasticsearchHttpClient $httpClient;

    /**
     * Elasticsearch index manager for index operations
     *
     * @var ElasticsearchIndexManager
     */
    private readonly ElasticsearchIndexManager $indexManager;

    /**
     * Elasticsearch document indexer for indexing operations
     *
     * @var ElasticsearchDocumentIndexer
     */
    private readonly ElasticsearchDocumentIndexer $indexer;

    /**
     * Elasticsearch query executor for search operations
     *
     * @var ElasticsearchQueryExecutor
     */
    private readonly ElasticsearchQueryExecutor $queryExecutor;

    /**
     * PSR-3 logger instance
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param ElasticsearchHttpClient      $httpClient    HTTP client
     * @param ElasticsearchIndexManager    $indexManager  Index manager
     * @param ElasticsearchDocumentIndexer $indexer       Document indexer
     * @param ElasticsearchQueryExecutor   $queryExecutor Query executor
     * @param LoggerInterface              $logger        Logger
     */
    public function __construct(
        ElasticsearchHttpClient $httpClient,
        ElasticsearchIndexManager $indexManager,
        ElasticsearchDocumentIndexer $indexer,
        ElasticsearchQueryExecutor $queryExecutor,
        LoggerInterface $logger
    ) {
        $this->httpClient    = $httpClient;
        $this->indexManager  = $indexManager;
        $this->indexer       = $indexer;
        $this->queryExecutor = $queryExecutor;
        $this->logger        = $logger;
    }//end __construct()

    /**
     * Index an object.
     *
     * @param ObjectEntity $object The object to index
     * @param bool         $commit Whether to commit immediately
     *
     * @return bool True on success, false on failure
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
<<<<<<< HEAD
=======
     *
     * @spec exclude facade delegation to ElasticsearchDocumentIndexer::indexObject
>>>>>>> origin/development
     */
    public function indexObject(ObjectEntity $object, bool $commit=false): bool
    {
        return $this->indexer->indexObject(object: $object, refresh: $commit);
    }//end indexObject()

    /**
     * Index multiple objects.
     *
     * @param array $objects The objects to index
     * @param bool  $commit  Whether to commit immediately
     *
     * @return (bool|int|string)[] Results of bulk indexing operation
     *
     * @psalm-return array{success: bool, indexed: int<0, max>, failed: int, error?: string}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
<<<<<<< HEAD
=======
     *
     * @spec exclude facade delegation to ElasticsearchDocumentIndexer::bulkIndexObjects
>>>>>>> origin/development
     */
    public function bulkIndexObjects(array $objects, bool $commit=false): array
    {
        return $this->indexer->bulkIndexObjects(objects: $objects, refresh: $commit);
    }//end bulkIndexObjects()

    /**
     * Delete an object.
     *
     * @param string|int $objectId The object ID to delete
     * @param bool       $commit   Whether to commit immediately
     *
     * @return bool True on success, false on failure
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
<<<<<<< HEAD
=======
     *
     * @spec exclude facade delegation to ElasticsearchDocumentIndexer::deleteObject
>>>>>>> origin/development
     */
    public function deleteObject(string|int $objectId, bool $commit=false): bool
    {
        return $this->indexer->deleteObject(objectId: $objectId, refresh: $commit);
    }//end deleteObject()

    /**
     * Delete objects by query.
     *
     * @param string $query         The query string
     * @param bool   $commit        Whether to commit immediately
     * @param bool   $returnDetails Whether to return details
     *
     * @return int[]|true Array with details if $returnDetails is true, otherwise bool
     *
     * @psalm-return array{deleted: 0}|true
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
<<<<<<< HEAD
=======
     *
     * @spec exclude simplified stub — returns success without deleting (not yet implemented)
>>>>>>> origin/development
     */
    public function deleteByQuery(string $query, bool $commit=false, bool $returnDetails=false): array|bool
    {
        // Simplified implementation - just return success.
        $this->logger->info(
            message: '[ElasticsearchBackend] deleteByQuery called (not fully implemented yet)',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        if ($returnDetails === true) {
            return ['deleted' => 0];
        }

        return true;
    }//end deleteByQuery()

    /**
     * Search with pagination.
     *
     * @param array $query         The search query
     * @param bool  $_rbac         Whether to apply RBAC
     * @param bool  $_multitenancy Whether to apply multitenancy
     * @param bool  $deleted       Whether to include deleted objects
     *
     * @return ((array|mixed)[]|int|mixed)[] Search results with pagination metadata
     *
     * @psalm-return array{total: 0|mixed, results: array<never, array<never, never>|mixed>, page: 1, limit: 10|mixed}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-88
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-88
     */
    public function searchObjectsPaginated(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $deleted=false
    ): array {
        $result = $this->queryExecutor->search($query);

        // Convert Elasticsearch response to OpenRegister format.
        return [
            'total'   => $result['hits']['total']['value'] ?? 0,
            'results' => array_map(
                function (array $hit): array {
                    return $hit['_source'] ?? [];
                },
                $result['hits']['hits'] ?? []
            ),
            'page'    => 1,
            'limit'   => $query['_limit'] ?? 10,
        ];
    }//end searchObjectsPaginated()

    /**
     * Get document count.
     *
     * @return int Number of documents in the index
     */
    public function getDocumentCount(): int
    {
        return $this->queryExecutor->getDocumentCount();
    }//end getDocumentCount()

    /**
     * Commit changes (refresh index).
     *
     * @return bool True on success, false on failure
<<<<<<< HEAD
=======
     *
     * @spec exclude facade delegation to ElasticsearchIndexManager::refreshIndex
>>>>>>> origin/development
     */
    public function commit(): bool
    {
        return $this->indexManager->refreshIndex(
            $this->indexManager->getActiveIndexName()
        );
    }//end commit()

    /**
     * Search objects.
     *
     * @param array $params The search parameters
     *
     * @return array Search results
<<<<<<< HEAD
=======
     *
     * @spec exclude facade delegation to ElasticsearchQueryExecutor::search
>>>>>>> origin/development
     */
    public function search(array $params): array
    {
        return $this->queryExecutor->search($params);
    }//end search()

    /**
     * Reindex all objects.
     *
     * @param int         $maxObjects     Maximum number of objects to reindex
     * @param int         $batchSize      Batch size for reindexing
     * @param string|null $collectionName Collection name to reindex
     *
     * @return (int|string|true)[] Reindexing results
     *
     * @psalm-return array{success: true, indexed: 0, message: 'Reindexing should be called via IndexService'}
<<<<<<< HEAD
=======
     *
     * @spec exclude stub — defers reindex to IndexService; returns a static message
>>>>>>> origin/development
     */
    public function reindexAll(int $maxObjects=0, int $batchSize=1000, ?string $collectionName=null): array
    {
        $this->logger->info(
            message: '[ElasticsearchBackend] reindexAll called (delegates to external handler)',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        return [
            'success' => true,
            'indexed' => 0,
            'message' => 'Reindexing should be called via IndexService',
        ];
    }//end reindexAll()

    /**
     * Warmup index (ensure it exists).
     *
     * @param array  $schemas       Schemas to warmup
     * @param int    $maxObjects    Maximum objects to process
     * @param string $mode          Processing mode
     * @param bool   $collectErrors Whether to collect errors
     * @param int    $batchSize     Batch size for processing
     * @param array  $schemaIds     Specific schema IDs to process
     *
     * @return (string|true)[] Warmup results
     *
     * @psalm-return array{success: true, index: string, message: 'Index warmed up'}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
<<<<<<< HEAD
=======
     *
     * @spec exclude facade — ensures the active index exists via ElasticsearchIndexManager
>>>>>>> origin/development
     */
    public function warmupIndex(
        array $schemas=[],
        int $maxObjects=0,
        string $mode='serial',
        bool $collectErrors=false,
        int $batchSize=1000,
        array $schemaIds=[]
    ): array {
        $index = $this->indexManager->getActiveIndexName();
        $this->indexManager->ensureIndex($index);

        return [
            'success' => true,
            'index'   => $index,
            'message' => 'Index warmed up',
        ];
    }//end warmupIndex()

    /**
     * Check if backend is available.
     *
     * @param bool $forceRefresh Whether to force refresh availability check
     *
     * @return bool True if backend is available
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function isAvailable(bool $forceRefresh=false): bool
    {
        return $this->httpClient->isConfigured();
    }//end isAvailable()

    /**
     * Test connection to backend.
     *
     * @param bool $inclCollTests Whether to include collection tests
     *
     * @return (bool|int|string)[] Connection test results
     *
     * @psalm-return array{success: bool, error?: string, document_count?: int}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
<<<<<<< HEAD
=======
     *
     * @spec exclude facade — probes connectivity via a document-count request
>>>>>>> origin/development
     */
    public function testConnection(bool $inclCollTests=true): array
    {
        try {
            $count = $this->getDocumentCount();
            return [
                'success'        => true,
                'document_count' => $count,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }//end testConnection()

    /**
     * Optimize index.
     *
     * @return true True on success
<<<<<<< HEAD
=======
     *
     * @spec exclude no-op — Elasticsearch needs no manual optimization (returns true)
>>>>>>> origin/development
     */
    public function optimize(): bool
    {
        // Elasticsearch doesn't need manual optimization like Solr.
        return true;
    }//end optimize()

    /**
     * Clear index.
     *
     * @param string|null $collectionName Collection name to clear
     *
     * @return int[] Clear operation results
     *
     * @psalm-return array{deleted: 0}
<<<<<<< HEAD
=======
     *
     * @spec exclude facade delegation to ElasticsearchDocumentIndexer::clearIndex
>>>>>>> origin/development
     */
    public function clearIndex(?string $collectionName=null): array
    {
        $this->indexer->clearIndex();
        return ['deleted' => 0];
    }//end clearIndex()

    /**
     * Get backend configuration.
     *
     * @return array Backend configuration
     *
     * @psalm-return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->httpClient->getConfig();
    }//end getConfig()

    /**
     * Get backend statistics.
     *
     * @return (int|string)[] Backend statistics
     *
     * @psalm-return array{document_count: int, backend: 'elasticsearch'}
<<<<<<< HEAD
=======
     *
     * @spec exclude boilerplate stats — returns document count and backend label
>>>>>>> origin/development
     */
    public function getStats(): array
    {
        return [
            'document_count' => $this->getDocumentCount(),
            'backend'        => 'elasticsearch',
        ];
    }//end getStats()

    /**
     * Create collection/index.
     *
     * @param string $name   Collection name to create
     * @param array  $config Configuration for the collection
     *
     * @return bool[] Creation results
     *
     * @psalm-return array{success: bool}
<<<<<<< HEAD
=======
     *
     * @spec exclude facade delegation to ElasticsearchIndexManager::createIndex
>>>>>>> origin/development
     */
    public function createCollection(string $name, array $config=[]): array
    {
            $success = $this->indexManager->createIndex(indexName: $name, mapping: $config);
        return ['success' => $success];
    }//end createCollection()

    /**
     * Delete collection/index.
     *
     * @param string|null $collectionName Collection name to delete
     *
     * @return bool[] Deletion results
     *
     * @psalm-return array{success: bool}
<<<<<<< HEAD
=======
     *
     * @spec exclude facade delegation to ElasticsearchIndexManager::deleteIndex
>>>>>>> origin/development
     */
    public function deleteCollection(?string $collectionName=null): array
    {
        $name    = $collectionName ?? $this->indexManager->getActiveIndexName();
        $success = $this->indexManager->deleteIndex($name);
        return ['success' => $success];
    }//end deleteCollection()

    /**
     * Check if collection exists.
     *
     * @param string $collectionName Collection name to check
     *
     * @return bool True if collection exists
<<<<<<< HEAD
=======
     *
     * @spec exclude facade delegation to ElasticsearchIndexManager::indexExists
>>>>>>> origin/development
     */
    public function collectionExists(string $collectionName): bool
    {
        return $this->indexManager->indexExists($collectionName);
    }//end collectionExists()

    /**
     * List all collections.
     *
     * @return string[] List of collection names
     *
     * @psalm-return list{string}
<<<<<<< HEAD
=======
     *
     * @spec exclude simplified stub — returns only the active index name
>>>>>>> origin/development
     */
    public function listCollections(): array
    {
        // Simplified - would need ES API call to list all indices.
        return [$this->indexManager->getActiveIndexName()];
    }//end listCollections()

    /**
     * Index generic documents.
     *
     * @param array $documents Documents to index
     *
     * @return true True on success
<<<<<<< HEAD
=======
     *
     * @spec exclude simplified stub — logs document count and returns true (not yet implemented)
>>>>>>> origin/development
     */
    public function index(array $documents): bool
    {
        // Simplified implementation.
        $this->logger->info(
            message: '[ElasticsearchBackend] index() called with '.count($documents).' documents',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        return true;
    }//end index()

    /**
<<<<<<< HEAD
=======
     * Run an aggregation against this Elasticsearch backend.
     *
     * The translator already exists at
     * `Aggregation\ElasticsearchAggregationQueryBuilder` — this method
     * is the HTTP-client adapter on top. Returns null until the dev
     * container ships an ES instance the runtime can talk to, so the
     * caller falls back to the PHP path. Stub matches the interface
     * contract so the AggregationRunner can already start dispatching
     * by-backend.
     *
     * @param \OCA\OpenRegister\Service\Aggregation\AggregationQuery $query Portable aggregation request.
     *
     * @return array|null The aggregation result, or null when the backend cannot execute it.
     *
     * @spec exclude stub — returns null so caller falls back to PHP path (HTTP adapter not yet wired)
     */
    public function aggregate(\OCA\OpenRegister\Service\Aggregation\AggregationQuery $query): ?array
    {
        $this->logger->info(
            message: '[ElasticsearchBackend] aggregate() — HTTP client not yet wired; returning null so caller falls back to PHP path',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        return null;
    }//end aggregate()

    /**
>>>>>>> origin/development
     * Get field types.
     *
     * @param string $collection Collection name
     *
     * @return array Field types
     *
     * @psalm-return array<never, never>
     */
    public function getFieldTypes(string $collection): array
    {
        return [];
    }//end getFieldTypes()

    /**
     * Add field type.
     *
     * @param string $collection Collection name
     * @param array  $fieldType  Field type configuration
     *
     * @return true True on success
<<<<<<< HEAD
=======
     *
     * @spec exclude no-op stub — ES infers field types dynamically (returns true)
>>>>>>> origin/development
     */
    public function addFieldType(string $collection, array $fieldType): bool
    {
        return true;
    }//end addFieldType()

    /**
     * Get fields.
     *
     * @param string $collection Collection name
     *
     * @return array Field definitions
     *
     * @psalm-return array<never, never>
     */
    public function getFields(string $collection): array
    {
        return [];
    }//end getFields()

    /**
     * Add or update field.
     *
     * @param array $fieldConfig Field configuration
     * @param bool  $force       Whether to force update
     *
     * @return string Status message
     *
     * @psalm-return 'skipped'
<<<<<<< HEAD
=======
     *
     * @spec exclude no-op stub — ES infers field mappings dynamically (returns 'skipped')
>>>>>>> origin/development
     */
    public function addOrUpdateField(array $fieldConfig, bool $force): string
    {
        return 'skipped';
    }//end addOrUpdateField()

    /**
     * Index files by their IDs.
     *
     * @param array       $fileIds        Array of file IDs to index.
     * @param string|null $collectionName Optional collection name.
     *
     * @return array Indexing results.
<<<<<<< HEAD
=======
     *
     * @spec exclude stub — returns empty result shape; file indexing handled by FileHandler (REQ-8)
>>>>>>> origin/development
     */
    public function indexFiles(array $fileIds, ?string $collectionName=null): array
    {
        return ['indexed' => 0, 'failed' => 0, 'errors' => []];
    }//end indexFiles()

    /**
     * Get file indexing statistics.
     *
     * @return array File indexing statistics.
     */
    public function getFileIndexStats(): array
    {
        return [];
    }//end getFileIndexStats()

    /**
     * Fix mismatched fields in the search backend schema.
     *
     * @param array $mismatchedFields Array of mismatched fields.
     * @param bool  $dryRun           Whether to preview changes only.
     *
     * @return array Results of the fix operation.
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-88
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-88
     */
    public function fixMismatchedFields(array $mismatchedFields, bool $dryRun=false): array
    {
        return [];
    }//end fixMismatchedFields()
}//end class
