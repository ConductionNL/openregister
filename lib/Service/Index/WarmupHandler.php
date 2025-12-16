<?php

/**
 * WarmupHandler
 *
 * Handles Solr index warmup operations.
 * Extracted from GuzzleSolrService to separate warmup logic.
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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\SolrSchemaService;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * WarmupHandler for Solr warmup operations
 *
 * Handles index warmup logic including:
 * - Schema mirroring
 * - Bulk indexing with various modes
 * - Memory prediction and tracking
 * - Cache warming queries
 *
 * @package OCA\OpenRegister\Service\Index
 */
class WarmupHandler
{

    /**
     * Search backend interface.
     *
     * @var SearchBackendInterface
     */
    private readonly SearchBackendInterface $searchBackend;

    /**
     * Bulk indexer.
     *
     * @var BulkIndexer
     */
    private readonly BulkIndexer $bulkIndexer;

    /**
     * Object mapper.
     *
     * @var ObjectEntityMapper
     */
    private readonly ObjectEntityMapper $objectMapper;

    /**
     * Database connection.
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
     * WarmupHandler constructor
     *
     * @param SearchBackendInterface $searchBackend Search backend
     * @param BulkIndexer            $bulkIndexer   Bulk indexer
     * @param ObjectEntityMapper     $objectMapper  Object mapper
     * @param IDBConnection          $db            Database connection
     * @param LoggerInterface        $logger        Logger
     *
     * @return void
     */
    public function __construct(
        SearchBackendInterface $searchBackend,
        BulkIndexer $bulkIndexer,
        ObjectEntityMapper $objectMapper,
        IDBConnection $db,
        LoggerInterface $logger
    ) {
        $this->searchBackend = $searchBackend;
        $this->bulkIndexer   = $bulkIndexer;
        $this->objectMapper  = $objectMapper;
        $this->db            = $db;
        $this->logger        = $logger;

    }//end __construct()


    /**
     * Warm up the index
     *
     * Delegates to the search backend for index warmup operations.
     *
     * @param array  $schemas       Schemas to warm up.
     * @param int    $maxObjects    Max objects to process.
     * @param string $mode          Warmup mode (serial, parallel, hyper).
     * @param bool   $collectErrors Whether to collect detailed errors.
     * @param int    $batchSize     Batch size for processing.
     * @param array  $schemaIds     Schema IDs to filter.
     *
     * @return array Results with statistics and errors.
     */
    public function warmupIndex(
        array $schemas=[],
        int $maxObjects=0,
        string $mode='serial',
        bool $collectErrors=false,
        int $batchSize=1000,
        array $schemaIds=[]
    ): array {
        $this->logger->info(
            '[WarmupHandler] Starting index warmup',
            [
                'max_objects' => $maxObjects,
                'mode'        => $mode,
                'batch_size'  => $batchSize,
            ]
        );

        try {
            // Delegate to search backend for warmup operation.
            $result = $this->searchBackend->warmupIndex(
                $schemas,
                $maxObjects,
                $mode,
                $collectErrors,
                $batchSize,
                $schemaIds
            );

            $this->logger->info(
                '[WarmupHandler] Index warmup completed',
                [
                    'success'         => $result['success'] ?? false,
                    'objects_indexed' => $result['operations']['objects_indexed'] ?? 0,
                ]
            );

            return $result;
        } catch (Exception $e) {
            $this->logger->error(
                '[WarmupHandler] Index warmup failed',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }//end try

    }//end warmupIndex()


}//end class
