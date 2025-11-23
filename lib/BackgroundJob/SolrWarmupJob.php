<?php

/**
 * OpenRegister SOLR Index Warmup Background Job
 *
 * One-time background job that warms up the SOLR index after data imports.
 * This job is scheduled automatically after import operations complete to
 * ensure optimal search performance without slowing down the import process.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\BackgroundJob\QueuedJob;
use OCP\ILogger;
use Psr\Log\LoggerInterface;

/**
 * One-time background job for SOLR index warmup
 *
 * This job is automatically scheduled after import operations to warm up
 * the SOLR index in the background, ensuring optimal search performance
 * without impacting import speed.
 *
 * Features:
 * - Runs once after being queued
 * - Configurable warmup parameters via job arguments
 * - Comprehensive logging and error handling
 * - Performance metrics tracking
 * - Automatic cleanup after execution
 */
class SolrWarmupJob extends QueuedJob
{
    /**
     * Default maximum objects to index during warmup
     */
    private const DEFAULT_MAX_OBJECTS = 5000;

    /**
     * Default warmup mode
     */
    private const DEFAULT_MODE = 'serial';


    /**
     * Execute the SOLR warmup job.
     *
     * @param array $arguments Job arguments containing warmup parameters
     *                         - maxObjects: Maximum number of objects to index (default: 5000)
     *                         - mode: Warmup mode - 'serial', 'parallel', or 'hyper' (default: 'serial')
     *                         - collectErrors: Whether to collect detailed errors (default: false)
     *                         - triggeredBy: What triggered this warmup (default: 'unknown')
     *
     * @return void
     */
    protected function run($arguments): void
    {
        $startTime = microtime(true);

        // Parse job arguments with defaults.
        $maxObjects    = $arguments['maxObjects'] ?? self::DEFAULT_MAX_OBJECTS;
        $mode          = $arguments['mode'] ?? self::DEFAULT_MODE;
        $collectErrors = $arguments['collectErrors'] ?? false;
        $triggeredBy   = $arguments['triggeredBy'] ?? 'unknown';

        // @var LoggerInterface $logger
        $logger = \OC::$server->get(LoggerInterface::class);

        $logger->info(
                message: '🔥 SOLR Warmup Job Started',
                context: [
                    'job_id'         => $this->getId(),
                    'max_objects'    => $maxObjects,
                    'mode'           => $mode,
                    'triggered_by'   => $triggeredBy,
                    'collect_errors' => $collectErrors,
                ]
                );

        try {
            /*
             * Get required services.
             *
             * @var GuzzleSolrService $solrService
             * @var SchemaMapper $schemaMapper
             */

            $solrService  = \OC::$server->get(GuzzleSolrService::class);
            $schemaMapper = \OC::$server->get(SchemaMapper::class);

            // Check if SOLR is available before proceeding.
            if ($this->isSolrAvailable($solrService, $logger) === false) {
                $logger->warning(
                        message: 'SOLR Warmup Job skipped - SOLR not available',
                        context: [
                            'job_id'       => $this->getId(),
                            'triggered_by' => $triggeredBy,
                        ]
                        );
                return;
            }

            // Get all schemas for comprehensive warmup.
            $schemas = $schemaMapper->findAll(config:);

            $logger->info(
                    message: 'Starting SOLR index warmup',
                    rbac: context: [
                        'schemas_found' => count($schemas), multi:
            'max_objects'   => $maxObjects,
                        'mode'          => $mode,
                    ]
                    );

            // Execute the warmup.
            $result = $solrService->warmupIndex(
                schemas: $schemas,
                maxObjects: $maxObjects,
                mode: $mode,
                collectErrors: $collectErrors
            );

            $executionTime = microtime(true) - $startTime;

            if (($result['success'] ?? false) === true) {
                $logger->info(
                        '✅ SOLR Warmup Job Completed Successfully',
                        [
                            'job_id'                 => $this->getId(),
                            'execution_time_seconds' => round($executionTime, 2),
                            'objects_indexed'        => $result['operations']['objects_indexed'] ?? 0,
                            'schemas_processed'      => $result['operations']['schemas_processed'] ?? 0,
                            'fields_created'         => $result['operations']['fields_created'] ?? 0,
                            'triggered_by'           => $triggeredBy,
                            'performance_metrics'    => [
                                'total_time_ms'      => $result['execution_time_ms'] ?? 0,
                                'objects_per_second' => $this->calculateObjectsPerSecond($result, $executionTime),
                            ],
                        ]
                        );
            } else {
                $logger->error(
                        '❌ SOLR Warmup Job Failed',
                        [
                            'job_id'                 => $this->getId(),
                            'execution_time_seconds' => round($executionTime, 2),
                            'error'                  => $result['error'] ?? 'Unknown error',
                            'triggered_by'           => $triggeredBy,
                        ]
                        );
            }//end if
        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;

            $logger->error(
                    message: '🚨 SOLR Warmup Job Exception',
                    context: [
                        'job_id'                 => $this->getId(),
                        'execution_time_seconds' => round($executionTime, 2),
                        'exception'              => $e->getMessage(),
                        'file'                   => $e->getFile(),
                        'line'                   => $e->getLine(),
                        'triggered_by'           => $triggeredBy,
                        'trace'                  => $e->getTraceAsString(),
                    ]
                    );

            // Re-throw to mark job as failed.
            throw $e;
        }//end try

    }//end run()


    /**
     * Check if SOLR is available for warmup.
     *
     * @param GuzzleSolrService $solrService The SOLR service
     * @param LoggerInterface   $logger      The logger
     *
     * @return bool True if SOLR is available
     */
    private function isSolrAvailable(GuzzleSolrService $solrService, LoggerInterface $logger): bool
    {
        try {
            $connectionTest = $solrService->testConnection();

            if (($connectionTest['success'] ?? false) === true) {
                return true;
            }

            $logger->info(
                    message: 'SOLR connection test failed during warmup job',
                    context: [
                        'test_result' => $connectionTest,
                    ]
                    );

            return false;
        } catch (\Exception $e) {
            $logger->warning(
                    message: 'SOLR availability check failed during warmup job',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );

            return false;
        }//end try

    }//end isSolrAvailable()


    /**
     * Calculate objects per second performance metric.
     *
     * @param array $result        Warmup result
     * @param float $executionTime Total execution time in seconds
     *
     * @return float Objects indexed per second
     */
    private function calculateObjectsPerSecond(array $result, float $executionTime): float
    {
        $objectsIndexed = $result['operations']['objects_indexed'] ?? 0;

        if ($executionTime > 0 && $objectsIndexed > 0) {
            return round($objectsIndexed / $executionTime, 2);
        }

        return 0.0;

    }//end calculateObjectsPerSecond()


}//end class
