<?php

declare(strict_types=1);

/*
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

namespace OCA\OpenRegister\Service\Index;

use OCA\OpenRegister\Service\GuzzleSolrService;
use Psr\Log\LoggerInterface;

/**
 * WarmupHandler for Solr warmup operations
 *
 * PRAGMATIC APPROACH: Initially delegates to GuzzleSolrService.
 * Methods will be migrated incrementally.
 *
 * @package OCA\OpenRegister\Service\Index
 */
class WarmupHandler
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
     * WarmupHandler constructor
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
        $this->logger            = $logger;

    }//end __construct()


    /**
     * Warm up the index
     *
     * @param array  $schemas       Schemas to warm up
     * @param int    $maxObjects    Max objects
     * @param string $mode          Warmup mode
     * @param bool   $collectErrors Collect errors
     * @param int    $batchSize     Batch size
     * @param array  $schemaIds     Schema IDs
     *
     * @return array Results
     */
    public function warmupIndex(
        array $schemas=[],
        int $maxObjects=0,
        string $mode='serial',
        bool $collectErrors=false,
        int $batchSize=1000,
        array $schemaIds=[]
    ): array {
        $this->logger->debug(
                'WarmupHandler: Delegating to GuzzleSolrService',
                [
                    'max_objects' => $maxObjects,
                    'mode'        => $mode,
                ]
                );

        return $this->guzzleSolrService->warmupIndex(
            $schemas,
            $maxObjects,
            $mode,
            $collectErrors,
            $batchSize,
            $schemaIds
        );

    }//end warmupIndex()


}//end class
