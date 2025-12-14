<?php

declare(strict_types=1);

/**
 * BulkIndexer
 *
 * Handles bulk indexing operations for Solr.
 * Extracted from GuzzleSolrService to separate bulk operation logic.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-12
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Index;

use OCA\OpenRegister\Service\GuzzleSolrService;
use Psr\Log\LoggerInterface;

/**
 * BulkIndexer for bulk Solr operations
 *
 * PRAGMATIC APPROACH: Initially delegates to GuzzleSolrService.
 * Methods will be migrated incrementally.
 *
 * @package OCA\OpenRegister\Service\Index
 */
class BulkIndexer
{
    /**
     * Guzzle Solr service (temporary delegation).
     *
     * @var GuzzleSolrService
     */
    private readonly GuzzleSolrService $guzzleSolrService;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;


    /**
     * BulkIndexer constructor
     *
     * @param GuzzleSolrService $guzzleSolrService Backend implementation
     * @param LoggerInterface   $logger            Logger
     *
     * @return void
     */
    public function __construct(
        GuzzleSolrService $guzzleSolrService,
        LoggerInterface $logger
    ) {
        $this->guzzleSolrService = $guzzleSolrService;
        $this->logger = $logger;
    }


    /**
     * Bulk index objects
     *
     * @param array $objects Objects to index
     * @param bool  $commit  Whether to commit
     *
     * @return array Results
     */
    public function bulkIndexObjects(array $objects, bool $commit = true): array
    {
        $this->logger->debug('BulkIndexer: Delegating to GuzzleSolrService', [
            'object_count' => count($objects),
            'commit' => $commit
        ]);

        return $this->guzzleSolrService->bulkIndexObjects($objects, $commit);
    }


    /**
     * Bulk index from database
     *
     * @param int   $batchSize      Batch size
     * @param int   $maxObjects     Max objects
     * @param array $solrFieldTypes Field types
     * @param array $schemaIds      Schema IDs to filter
     *
     * @return array Results
     */
    public function bulkIndexFromDatabase(
        int $batchSize = 1000,
        int $maxObjects = 0,
        array $solrFieldTypes = [],
        array $schemaIds = []
    ): array {
        $this->logger->debug('BulkIndexer: Delegating bulkIndexFromDatabase', [
            'batch_size' => $batchSize,
            'max_objects' => $maxObjects
        ]);

        return $this->guzzleSolrService->bulkIndexFromDatabase(
            $batchSize,
            $maxObjects,
            $solrFieldTypes,
            $schemaIds
        );
    }


}//end class
