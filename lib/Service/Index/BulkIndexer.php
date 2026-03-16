<?php

/**
 * BulkIndexer
 *
 * Handles bulk indexing business logic (batching, parallel processing, optimization).
 * Does NOT contain backend-specific I/O - delegates to SearchBackendInterface.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-12
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * BulkIndexer - Business logic for bulk indexing operations
 *
 * RESPONSIBILITIES:
 * - Fetch objects from database in batches
 * - Orchestrate parallel processing
 * - Optimize memory usage
 * - Call backend.index() for actual indexing
 *
 * DOES NOT:
 * - Make direct Solr/Elastic API calls (uses SearchBackendInterface)
 * - Extract text (TextExtractionService handles that)
 *
 * @package OCA\OpenRegister\Service\Index
 */
class BulkIndexer
{

    /**
     * Object entity mapper for DB queries.
     *
     * @var MagicMapper
     */
    private readonly MagicMapper $objectMapper;

    /**
     * Schema mapper for schema validation.
     *
     * @var SchemaMapper
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Document builder for creating Solr documents.
     *
     * @var DocumentBuilder
     */
    private readonly DocumentBuilder $documentBuilder;

    /**
     * Search backend interface (Solr/Elastic abstraction).
     *
     * @var SearchBackendInterface
     */
    private readonly SearchBackendInterface $searchBackend;

    /**
     * Database connection for direct queries.
     *
     * @var IDBConnection
     */
    private readonly IDBConnection $db;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * BulkIndexer constructor
     *
     * @param MagicMapper     $objectMapper    DB mapper for objects
     * @param SchemaMapper           $schemaMapper    DB mapper for schemas
     * @param DocumentBuilder        $documentBuilder Document builder
     * @param SearchBackendInterface $searchBackend   Search backend (Solr/Elastic)
     * @param IDBConnection          $db              Database connection
     * @param LoggerInterface        $logger          Logger
     *
     * @return void
     */
    public function __construct(
        MagicMapper $objectMapper,
        SchemaMapper $schemaMapper,
        DocumentBuilder $documentBuilder,
        SearchBackendInterface $searchBackend,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->objectMapper    = $objectMapper;
        $this->schemaMapper    = $schemaMapper;
        $this->documentBuilder = $documentBuilder;
        $this->searchBackend   = $searchBackend;
        $this->db     = $db;
        $this->logger = $logger;
    }//end __construct()

    /**
     * Bulk index objects (simple batch operation)
     *
     * This is a TEMPORARY wrapper that will be replaced with proper implementation.
     * Currently just logs a warning that this method needs proper extraction.
     *
     * @param array $_objects Objects to index
     * @param bool  $_commit  Whether to commit
     *
     * @return (false|string)[] Results
     *
     * @todo Extract implementation from SolrBackend
     *
     * @psalm-return array{success: false, message: 'Method not yet extracted to BulkIndexer'}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function bulkIndexObjects(array $_objects, bool $_commit=true): array
    {
        $this->logger->warning(
            message: '[BulkIndexer] bulkIndexObjects not yet fully extracted - needs implementation',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        return [
            'success' => false,
            'message' => 'Method not yet extracted to BulkIndexer',
        ];
    }//end bulkIndexObjects()

    /**
     * Bulk index objects from database in batches
     *
     * BUSINESS LOGIC: Fetches objects from DB, creates documents, sends to backend.
     * This is the core bulk indexing implementation extracted from SolrBackend.
     *
     * @param int   $batchSize      Batch size (default: 1000)
     * @param int   $maxObjects     Max objects to process (0 = all)
     * @param array $solrFieldTypes Field types for validation
     * @param array $schemaIds      Schema IDs to filter (empty = all searchable)
     *
     * @return array Result with success status, indexed count, and batch information.
     *
     * @throws \RuntimeException If indexing fails.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function bulkIndexFromDatabase(
        int $batchSize=1000,
        int $maxObjects=0,
        array $solrFieldTypes=[],
        array $schemaIds=[]
    ): array {
        // $schemaIds is guaranteed to be an array from function signature
        // Check backend availability.
        if ($this->searchBackend->isAvailable() === false) {
            return [
                'success' => false,
                'error'   => 'Search backend is not available',
                'indexed' => 0,
                'batches' => 0,
            ];
        }

        try {
            $totalIndexed = 0;
            $batchCount   = 0;
            $offset       = 0;
            $results      = ['skipped_non_searchable' => 0];

            $this->logger->info(
                message: '[BulkIndexer] Starting bulk index from database',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Get count of searchable objects for planning.
            $totalObjects     = $this->countSearchableObjects(schemaIds: $schemaIds);
            $estimatedBatches = ceil($totalObjects / $batchSize);
            $willProcess      = $totalObjects;
            if ($maxObjects > 0) {
                $estimatedBatches = ceil(min($totalObjects, $maxObjects) / $batchSize);
                $willProcess      = min($totalObjects, $maxObjects);
            }

            $this->logger->info(
                message: '[BulkIndexer] Planning bulk index',
                context: [
                    'file'                   => __FILE__,
                    'line'                   => __LINE__,
                    'totalSearchableObjects' => $totalObjects,
                    'maxObjects'             => $maxObjects,
                    'batchSize'              => $batchSize,
                    'estimatedBatches'       => $estimatedBatches,
                    'willProcess'            => $willProcess,
                ]
            );

            do {
                // Calculate current batch size (respect maxObjects limit).
                $currentBatchSize = $batchSize;
                if ($maxObjects > 0) {
                    $remaining = $maxObjects - $totalIndexed;
                    if ($remaining <= 0) {
                        break;
                    }

                    $currentBatchSize = min($batchSize, $remaining);
                }

                // Fetch batch of searchable objects from DB.
                $fetchStart   = microtime(true);
                $objects      = $this->fetchSearchableObjects(
                    limit: $currentBatchSize,
                    offset: $offset,
                    schemaIds: $schemaIds
                );
                $objectsCount = count($objects);

                $fetchDuration = round((microtime(true) - $fetchStart) * 1000, 2);
                $this->logger->info(
                    message: '[BulkIndexer] Batch fetched',
                    context: [
                        'file'         => __FILE__,
                        'line'         => __LINE__,
                        'batch'        => $batchCount + 1,
                        'objectsFound' => $objectsCount,
                        'fetchTime'    => $fetchDuration.'ms',
                    ]
                );

                if (empty($objects) === true) {
                    break;
                }

                // Create documents from objects.
                $documents = [];
                foreach ($objects as $object) {
                    try {
                        $document    = $this->documentBuilder->createDocument(
                            object: $object,
                            _solrFieldTypes: $solrFieldTypes
                        );
                        $documents[] = $document;
                    } catch (\RuntimeException $e) {
                        if (str_contains($e->getMessage(), 'Schema is not searchable') === true) {
                            $results['skipped_non_searchable']++;
                            $this->logger->warning(
                                message: '[BulkIndexer] Unexpected non-searchable schema',
                                context: [
                                    'file'     => __FILE__,
                                    'line'     => __LINE__,
                                    'objectId' => $object->getId(),
                                    'error'    => $e->getMessage(),
                                ]
                            );
                            continue;
                        }

                        throw $e;
                    } catch (\Exception $e) {
                        $this->logger->warning(
                            message: '[BulkIndexer] Failed to create document',
                            context: [
                                'file'     => __FILE__,
                                'line'     => __LINE__,
                                'error'    => $e->getMessage(),
                                'objectId' => $object->getId(),
                            ]
                        );
                    }//end try
                }//end foreach

                // Send documents to backend.
                $indexed = 0;
                if (empty($documents) === false) {
                    $indexStart = microtime(true);
                    $this->searchBackend->index($documents);
                    $indexed = count($documents);

                    $indexDuration = round((microtime(true) - $indexStart) * 1000, 2);
                    $this->logger->debug(
                        message: '[BulkIndexer] Batch indexed',
                        context: [
                            'file'      => __FILE__,
                            'line'      => __LINE__,
                            'documents' => $indexed,
                            'indexTime' => $indexDuration.'ms',
                        ]
                    );
                }

                $batchCount++;
                $totalIndexed += $indexed;
                $offset       += $currentBatchSize;
            } while ($objectsCount === $currentBatchSize && ($maxObjects === 0 || $totalIndexed < $maxObjects));

            $this->logger->info(
                message: '[BulkIndexer] Bulk indexing completed',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'totalIndexed' => $totalIndexed,
                    'totalBatches' => $batchCount,
                    'batchSize'    => $batchSize,
                ]
            );

            return [
                'success'                => true,
                'indexed'                => $totalIndexed,
                'batches'                => $batchCount,
                'batch_size'             => $batchSize,
                'skipped_non_searchable' => $results['skipped_non_searchable'] ?? 0,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[BulkIndexer] Bulk indexing failed',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            $indexed = ($totalIndexed ?? 0);
            $batches = ($batchCount ?? 0);
            $msg     = 'Bulk indexing failed: '.$e->getMessage().' (Indexed: '.$indexed.', Batches: '.$batches.')';
            throw new RuntimeException($msg, 0, $e);
        }//end try
    }//end bulkIndexFromDatabase()

    /**
     * Count searchable objects in database
     *
     * @param array $schemaIds Schema IDs to filter
     *
     * @return int Count of searchable objects
     */
    private function countSearchableObjects(array $schemaIds=[]): int
    {
        // Get searchable schema IDs.
        $searchableSchemaIds = $this->getSearchableSchemaIds(schemaIds: $schemaIds);

        if (empty($searchableSchemaIds) === true) {
            return 0;
        }

        // Count objects with searchable schemas.
        return $this->objectMapper->countBySchemas($searchableSchemaIds);
    }//end countSearchableObjects()

    /**
     * Fetch searchable objects from database
     *
     * @param int   $limit     Number of objects to fetch
     * @param int   $offset    Offset for pagination
     * @param array $schemaIds Schema IDs to filter
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    private function fetchSearchableObjects(int $limit, int $offset, array $schemaIds=[]): array
    {
        // Get searchable schema IDs.
        $searchableSchemaIds = $this->getSearchableSchemaIds(schemaIds: $schemaIds);

        if (empty($searchableSchemaIds) === true) {
            return [];
        }

        // Fetch objects with searchable schemas.
        return $this->objectMapper->findBySchemas(schemaIds: $searchableSchemaIds, limit: $limit, offset: $offset);
    }//end fetchSearchableObjects()

    /**
     * Get IDs of searchable schemas
     *
     * @param array $schemaIds Schema IDs to filter (empty = all searchable)
     *
     * @return (int|string)[] Array of searchable schema IDs
     *
     * @psalm-return list<int|string>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Schema filtering requires handling multiple scenarios
     */
    private function getSearchableSchemaIds(array $schemaIds=[]): array
    {
        // If specific schemas requested, filter for searchable ones.
        if (empty($schemaIds) === false) {
            $searchableIds = [];
            foreach ($schemaIds as $schemaId) {
                try {
                    $schema = $this->schemaMapper->find($schemaId);
                    if ($schema->getSearchable() === true) {
                        $searchableIds[] = $schemaId;
                    }
                } catch (\Exception $e) {
                    $this->logger->warning(
                        message: '[BulkIndexer] Schema not found',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schemaId]
                    );
                }
            }

            return $searchableIds;
        }

        // Get all searchable schemas.
        $allSchemas    = $this->schemaMapper->findAll();
        $searchableIds = [];
        foreach ($allSchemas as $schema) {
            if ($schema->getSearchable() === true) {
                $searchableIds[] = $schema->getId();
            }
        }

        return $searchableIds;
    }//end getSearchableSchemaIds()
}//end class
