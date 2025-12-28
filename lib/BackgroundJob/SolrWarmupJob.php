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

use OCA\OpenRegister\Service\IndexService;
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
     *
     * Limits the number of objects indexed per warmup to prevent
     * excessive resource usage and long execution times.
     *
     * @var int Maximum objects to index (default: 5000)
     */
    private const DEFAULT_MAX_OBJECTS = 5000;

    /**
     * Default warmup mode
     *
     * Serial mode processes objects one at a time, which is safer
     * but slower than parallel or hyper modes.
     *
     * @var string Warmup mode: 'serial', 'parallel', or 'hyper' (default: 'serial')
     */
    private const DEFAULT_MODE = 'serial';

    /**
     * Execute the SOLR warmup job
     *
     * Runs SOLR index warmup in the background to optimize search performance.
     * Processes schemas and indexes objects up to the specified maximum.
     * Logs comprehensive metrics and handles errors gracefully.
     *
     * @param array<string, mixed> $argument Job arguments containing warmup parameters:
     *                                       - maxObjects: Maximum number of objects to index (default: 5000)
     *                                       - mode: Warmup mode - 'serial', 'parallel', or 'hyper' (default: 'serial')
     *                                       - collectErrors: Whether to collect detailed errors (default: false)
     *                                       - triggeredBy: What triggered this warmup (default: 'unknown')
     *
     * @return void
     *
     * @throws \Exception If warmup fails critically (job will be marked as failed)
     */
    protected function run($argument): void
    {
        // Record start time for performance metrics.
        $startTime = microtime(true);

        // Parse job arguments with defaults.
        // These parameters control warmup behavior and resource usage.
        $maxObjects    = $argument['maxObjects'] ?? self::DEFAULT_MAX_OBJECTS;
        $mode          = $argument['mode'] ?? self::DEFAULT_MODE;
        $collectErrors = $argument['collectErrors'] ?? false;
        $triggeredBy   = $argument['triggeredBy'] ?? 'unknown';

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
             * @var IndexService $solrService
             * @var SchemaMapper $schemaMapper
             */

            $solrService  = \OC::$server->get(IndexService::class);
            $schemaMapper = \OC::$server->get(SchemaMapper::class);

            // Check if SOLR is available before proceeding.
            if ($this->isSolrAvailable(solrService: $solrService, logger: $logger) === false) {
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
            $schemas = $schemaMapper->findAll();

            $logger->info(
                message: 'Starting SOLR index warmup',
                context: [
                        'schemas_found' => count($schemas),
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
                                'objects_per_second' => $this->calculateObjectsPerSecond(result: $result, executionTime: $executionTime),
                            ],
                        ]
                );
            }//end if

            if (($result['success'] ?? false) === false) {
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
     * Check if SOLR is available
     *
     * Verifies that SOLR service is configured and accessible before
     * attempting warmup operations. Prevents errors from running warmup
     * when SOLR is not configured or unavailable.
     *
     * @param IndexService    $solrService SOLR service instance to check
     * @param LoggerInterface $logger      Logger instance for debug messages
     *
     * @return bool True if SOLR is available and ready, false otherwise
     */
    private function isSolrAvailable(IndexService $solrService, LoggerInterface $logger): bool
    {
        // Check if SOLR service is available and configured.
        // Returns false if SOLR is not configured or connection fails.
        if ($solrService->isAvailable() === false) {
            $logger->debug(message: 'SOLR Warmup Job skipped - SOLR service not available');
            return false;
        }

        // SOLR is available and ready for warmup operations.
        return true;
    }//end isSolrAvailable()

    /**
     * Calculate objects indexed per second
     *
     * Calculates indexing throughput rate for performance metrics.
     * Used to measure warmup efficiency and identify performance bottlenecks.
     *
     * @param array<string, mixed> $result        Warmup result containing operations data
     * @param float                $executionTime Total execution time in seconds
     *
     * @return float Objects indexed per second (rounded to 2 decimal places), or 0.0 if calculation not possible
     */
    private function calculateObjectsPerSecond(array $result, float $executionTime): float
    {
        // Extract number of objects indexed from result.
        $objectsIndexed = $result['operations']['objects_indexed'] ?? 0;

        // Calculate throughput rate: objects indexed / execution time.
        // Only calculate if both values are positive to avoid division by zero.
        if ($executionTime > 0 && $objectsIndexed > 0) {
            return round($objectsIndexed / $executionTime, 2);
        }

        // Return 0.0 if calculation not possible (no objects indexed or zero execution time).
        return 0.0;
    }//end calculateObjectsPerSecond()
}//end class
