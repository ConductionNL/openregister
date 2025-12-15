<?php

declare(strict_types=1);

/*
 * ElasticsearchBackend
 *
 * Elasticsearch backend implementation for OpenRegister search operations.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index\Backends
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

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
 */
class ElasticsearchBackend implements SearchBackendInterface
{

    private readonly ElasticsearchHttpClient $httpClient;

    private readonly ElasticsearchIndexManager $indexManager;

    private readonly ElasticsearchDocumentIndexer $indexer;

    private readonly ElasticsearchQueryExecutor $queryExecutor;

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
     * @return bool True on success, false on failure
     */
    public function indexObject(ObjectEntity $object, bool $commit=false): bool
    {
        return $this->indexer->indexObject($object, $commit);

    }//end indexObject()


    /**
     * Index multiple objects.
     *
     * @return array Results of bulk indexing operation
     */
    public function bulkIndexObjects(array $objects, bool $commit=false): array
    {
        return $this->indexer->bulkIndexObjects($objects, $commit);

    }//end bulkIndexObjects()


    /**
     * Delete an object.
     *
     * @return bool True on success, false on failure
     */
    public function deleteObject(string|int $objectId, bool $commit=false): bool
    {
        return $this->indexer->deleteObject($objectId, $commit);

    }//end deleteObject()


    /**
     * Delete objects by query.
     *
     * @return array|bool Array with details if $returnDetails is true, otherwise bool
     */
    public function deleteByQuery(string $query, bool $commit=false, bool $returnDetails=false): array|bool
    {
        // Simplified implementation - just return success
        $this->logger->info('[ElasticsearchBackend] deleteByQuery called (not fully implemented yet)');
        return $returnDetails ? ['deleted' => 0] : true;

    }//end deleteByQuery()


    /**
     * Search with pagination.
     *
     * @return array Search results with pagination metadata
     */
    public function searchObjectsPaginated(
        array $query=[],
        bool $rbac=true,
        bool $multitenancy=true,
        bool $published=false,
        bool $deleted=false
    ): array {
        $result = $this->queryExecutor->search($query);

        // Convert Elasticsearch response to OpenRegister format
        return [
            'total'   => $result['hits']['total']['value'] ?? 0,
            'results' => array_map(
                    function ($hit) {
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
     * @return array Search results
     */
    public function search(array $params): array
    {
        return $this->queryExecutor->search($params);

    }//end search()


    /**
     * Reindex all objects.
     *
     * @return array Reindexing results
     */
    public function reindexAll(int $maxObjects=0, int $batchSize=1000, ?string $collectionName=null): array
    {
        $this->logger->info('[ElasticsearchBackend] reindexAll called (delegates to external handler)');

        return [
            'success' => true,
            'indexed' => 0,
            'message' => 'Reindexing should be called via IndexService',
        ];

    }//end reindexAll()


    /**
     * Warmup index (ensure it exists).
     *
     * @return array Warmup results
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
     * @return bool True if backend is available
     */
    public function isAvailable(bool $forceRefresh=false): bool
    {
        return $this->httpClient->isConfigured();

    }//end isAvailable()


    /**
     * Test connection to backend.
     *
     * @return array Connection test results
     */
    public function testConnection(bool $includeCollectionTests=true): array
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
     * @return bool True on success
     */
    public function optimize(): bool
    {
        // Elasticsearch doesn't need manual optimization like Solr
        return true;

    }//end optimize()


    /**
     * Clear index.
     *
     * @return array Clear operation results
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
     */
    public function getConfig(): array
    {
        return $this->httpClient->getConfig();

    }//end getConfig()


    /**
     * Get backend statistics.
     *
     * @return array Backend statistics
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
     * @return array Creation results
     */
    public function createCollection(string $name, array $config=[]): array
    {
        $success = $this->indexManager->createIndex($name, $config);
        return ['success' => $success];

    }//end createCollection()


    /**
     * Delete collection/index.
     *
     * @return array Deletion results
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
     * @return bool True if collection exists
     */
    public function collectionExists(string $collectionName): bool
    {
        return $this->indexManager->indexExists($collectionName);

    }//end collectionExists()


    /**
     * List all collections.
     *
     * @return array List of collection names
     */
    public function listCollections(): array
    {
        // Simplified - would need ES API call to list all indices
        return [$this->indexManager->getActiveIndexName()];

    }//end listCollections()


    /**
     * Index generic documents.
     *
     * @return bool True on success
     */
    public function index(array $documents): bool
    {
        // Simplified implementation
        $this->logger->info('[ElasticsearchBackend] index() called with '.count($documents).' documents');
        return true;

    }//end index()


    /**
     * Get field types.
     *
     * @return array Field types
     */
    public function getFieldTypes(string $collection): array
    {
        return [];

    }//end getFieldTypes()


    /**
     * Add field type.
     *
     * @return bool True on success
     */
    public function addFieldType(string $collection, array $fieldType): bool
    {
        return true;

    }//end addFieldType()


    /**
     * Get fields.
     *
     * @return array Field definitions
     */
    public function getFields(string $collection): array
    {
        return [];

    }//end getFields()


    /**
     * Add or update field.
     *
     * @return string Status message
     */
    public function addOrUpdateField(array $fieldConfig, bool $force): string
    {
        return 'skipped';

    }//end addOrUpdateField()


}//end class
