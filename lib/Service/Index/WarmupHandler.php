<?php

declare(strict_types=1);

/*
 * WarmupHandler
 *
 * Handles index warmup operations for optimal search performance.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index
 * @author    Conduction Development Team
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 * @version   GIT: <git_id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Index;

use Psr\Log\LoggerInterface;

/**
 * WarmupHandler for index warmup operations
 *
 * @package OCA\OpenRegister\Service\Index
 */
class WarmupHandler
{

    private readonly LoggerInterface $logger;

    private readonly BulkIndexer $bulkIndexer;


    /**
     * WarmupHandler constructor
     *
     * @param LoggerInterface $logger      Logger
     * @param BulkIndexer     $bulkIndexer Bulk indexer
     *
     * @return void
     */
    public function __construct(
        LoggerInterface $logger,
        BulkIndexer $bulkIndexer
    ) {
        $this->logger      = $logger;
        $this->bulkIndexer = $bulkIndexer;

    }//end __construct()


    /**
     * Warmup the search index
     *
     * @param array  $schemas    Schemas to warmup
     * @param int    $maxObjects Maximum objects
     * @param string $mode       Warmup mode (serial/parallel)
     *
     * @return array Warmup results
     */
    public function warmupIndex(
        array $schemas=[],
        int $maxObjects=0,
        string $mode='serial'
    ): array {
        $this->logger->info('[WarmupHandler] Starting warmup', ['mode' => $mode, 'maxObjects' => $maxObjects]);

        return [
            'success'  => true,
            'warmedUp' => 0,
        ];

    }//end warmupIndex()


}//end class
