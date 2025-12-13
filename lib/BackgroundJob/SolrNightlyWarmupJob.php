<?php
/**
 * OpenRegister SOLR Nightly Warmup Background Job
 *
 * Recurring background job that runs every night at 00:00 to warm up the SOLR index.
 * This ensures optimal search performance by keeping the index warm and ready for queries.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\GuzzleSolrService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\BackgroundJob\TimedJob;
use OCP\ILogger;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Recurring nightly background job for SOLR index warmup
 *
 * This job runs automatically every night at 00:00 to ensure the SOLR index
 * is warm and optimized for search performance. It performs comprehensive
 * index warmup including schema mirroring and cache warming.
 *
 * Features:
 * - Runs daily at 00:00 (configurable)
 * - Comprehensive SOLR index warmup
 * - Performance optimizations and cache warming
 * - Detailed logging and monitoring
 * - Configurable via OpenRegister settings
 * - Automatic error handling and recovery
 */
class SolrNightlyWarmupJob extends TimedJob
{
    /**
     * Default interval: 24 hours (daily)
     */
    private const DEFAULT_INTERVAL = 24 * 60 * 60;

    /**
     * Default maximum objects for nightly warmup
     */
    private const DEFAULT_NIGHTLY_MAX_OBJECTS = 10000;

    /**
     * Default warmup mode for nightly runs.
     */
    private const DEFAULT_NIGHTLY_MODE = 'parallel';


    /**
     * Execute the nightly SOLR warmup job
     *
     * @param array $argument Job arguments (unused for recurring jobs)
     *
     * @return void
     */
    protected function run($_argument): void
    {
        $startTime = microtime(true);

        /*
         * @var LoggerInterface $logger
         */

        $logger = \OC::$server->get(LoggerInterface::class);

        $logger->info(
                message: '🌙 SOLR Nightly Warmup Job Started',
                context: [
                    'job_id'         => $this->getId(),
                    'scheduled_time' => date('Y-m-d H:i:s'),
                    'timezone'       => date_default_timezone_get(),
                ]
                );

        try {
            /*
             * Get required services.
             *
             * @var GuzzleSolrService $solrService
             */

            $solrService = \OC::$server->get(GuzzleSolrService::class);

            /*
             * @var SettingsService $settingsService
             */

            $settingsService = \OC::$server->get(SettingsService::class);

            /*
             * @var SchemaMapper $schemaMapper
             */

            $schemaMapper = \OC::$server->get(SchemaMapper::class);

            // Check if SOLR is enabled and available.
            if ($this->isSolrEnabledAndAvailable(solrService: $solrService, settingsService: $settingsService, logger: $logger) === false) {
                $logger->info(message: 'SOLR Nightly Warmup Job skipped - SOLR not enabled or available');
                return;
            }

            // Get warmup configuration from settings.
            $config = $this->getWarmupConfiguration(_settingsService: $settingsService, _logger: $logger);

            // Get all schemas for comprehensive warmup.
            $schemas = $schemaMapper->findAll();

            $logger->info(
                    'Starting nightly SOLR index warmup',
                    context: [
                        'schemas_found'  => count($schemas),
                        'max_objects'    => $config['maxObjects'],
                        'mode'           => $config['mode'],
                        'collect_errors' => $config['collectErrors'],
                    ]
                    );

            // Execute the comprehensive nightly warmup.
            $result = $solrService->warmupIndex(
                schemas: $schemas,
                maxObjects: $config['maxObjects'],
                mode: $config['mode'],
                collectErrors: $config['collectErrors']
            );

            $executionTime = microtime(true) - $startTime;

            if ($result['success'] ?? false) {
                $logger->info(
                        '✅ SOLR Nightly Warmup Job Completed Successfully',
                        [
                            'job_id'                 => $this->getId(),
                            'execution_time_seconds' => round($executionTime, 2),
                            'objects_indexed'        => $result['operations']['objects_indexed'] ?? 0,
                            'schemas_processed'      => $result['operations']['schemas_processed'] ?? 0,
                            'fields_created'         => $result['operations']['fields_created'] ?? 0,
                            'conflicts_resolved'     => $result['operations']['conflicts_resolved'] ?? 0,
                            'performance_metrics'    => [
                                'total_time_ms'      => $result['execution_time_ms'] ?? 0,
                                'objects_per_second' => $this->calculateObjectsPerSecond(result: $result, executionTime: $executionTime),
                                'next_run'           => date('Y-m-d H:i:s', time() + self::DEFAULT_INTERVAL),
                            ],
                            'operations_summary'     => $this->summarizeOperations($result['operations'] ?? []),
                        ]
                        );

                // Log performance statistics for monitoring.
                $this->logPerformanceStats(result: $result, executionTime: $executionTime, logger: $logger);
            } else {
                $logger->error(
                        '❌ SOLR Nightly Warmup Job Failed',
                        [
                            'job_id'                 => $this->getId(),
                            'execution_time_seconds' => round($executionTime, 2),
                            'error'                  => $result['error'] ?? 'Unknown error',
                            'next_retry'             => date('Y-m-d H:i:s', time() + self::DEFAULT_INTERVAL),
                        ]
                        );
            }//end if
        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;

            $logger->error(
                    message: '🚨 SOLR Nightly Warmup Job Exception',
                    context: [
                        'job_id'                 => $this->getId(),
                        'execution_time_seconds' => round($executionTime, 2),
                        'exception'              => $e->getMessage(),
                        'file'                   => $e->getFile(),
                        'line'                   => $e->getLine(),
                        'next_retry'             => date('Y-m-d H:i:s', time() + self::DEFAULT_INTERVAL),
                        'trace'                  => $e->getTraceAsString(),
                    ]
                    );

            // Don't re-throw for recurring jobs - let them retry next time.
        }//end try

    }//end run()


    /**
     * Calculate objects per second performance metric
     *
     * @param array $result        Warmup result
     * @param float $executionTime Total execution time in seconds
     *
     * @return float Objects indexed per second
     *
     * @psalm-suppress UnusedMethod
     */
    private function calculateObjectsPerSecond(array $result, float $executionTime): float
    {
        $objectsIndexed = $result['operations']['objects_indexed'] ?? 0;

        if ($executionTime > 0 && $objectsIndexed > 0) {
            return round($objectsIndexed / $executionTime, 2);
        }

        return 0.0;

    }//end calculateObjectsPerSecond()


    /**
     * Count successful warmup queries
     *
     * @param array $operations Operations array
     *
     * @return int Number of successful warmup queries
     *
     * @psalm-suppress UnusedMethod
     *
     * @psalm-return int<0, max>
     */
    private function countSuccessfulWarmupQueries(array $operations): int
    {
        $count = 0;

        foreach ($operations as $key => $value) {
            if (str_starts_with($key, 'warmup_query_') === true && $value === true) {
                $count++;
            }
        }

        return $count;

    }//end countSuccessfulWarmupQueries()


    /**
     * Calculate warmup efficiency percentage
     *
     * @param array $result Warmup result
     *
     * @return float Efficiency percentage
     *
     * @psalm-suppress UnusedMethod
     */
    private function calculateWarmupEfficiency(array $result): float
    {
        $operations      = $result['operations'] ?? [];
        $totalOperations = count($operations);

        if ($totalOperations === 0) {
            return 0.0;
        }

        $successfulOperations = array_sum(
            array_map(
                function ($op) {
                    if ($op === true) {
                        return 1;
                    }

                    return 0;
                },
                $operations
            )
        );

        return round(($successfulOperations / $totalOperations) * 100, 1);

    }//end calculateWarmupEfficiency()


    /**
     * Check if SOLR is enabled and available.
     *
     * @param GuzzleSolrService $solrService     SOLR service instance
     * @param SettingsService   $settingsService Settings service instance
     * @param LoggerInterface   $logger          Logger instance
     *
     * @return bool True if SOLR is enabled and available, false otherwise
     */
    private function isSolrEnabledAndAvailable(
        GuzzleSolrService $solrService,
        SettingsService $settingsService,
        LoggerInterface $logger
    ): bool {
        // Check if SOLR is enabled in settings.
        $solrSettings = $settingsService->getSolrSettings();
        if (($solrSettings['enabled'] ?? false) === false) {
            $logger->debug(message: 'SOLR Nightly Warmup Job skipped - SOLR not enabled in settings');
            return false;
        }

        // Check if SOLR service is available.
        if ($solrService->isAvailable() === false) {
            $logger->debug(message: 'SOLR Nightly Warmup Job skipped - SOLR service not available');
            return false;
        }

        return true;

    }//end isSolrEnabledAndAvailable()


    /**
     * Get warmup configuration from settings.
     *
     * @param SettingsService $_settingsService Settings service instance (unused, kept for API compatibility)
     * @param LoggerInterface $_logger          Logger instance (unused, kept for API compatibility)
     *
     * @return array Warmup configuration array
     */
    private function getWarmupConfiguration(SettingsService $_settingsService, LoggerInterface $_logger): array
    {
        /*
         * @var \OCP\IConfig $config
         */

        $config = \OC::$server->get(\OCP\IConfig::class);

        $maxObjects    = $config->getAppValue('openregister', 'solr_nightly_max_objects', (string) self::DEFAULT_NIGHTLY_MAX_OBJECTS);
        $mode          = $config->getAppValue('openregister', 'solr_nightly_mode', self::DEFAULT_NIGHTLY_MODE);
        $collectErrors = $config->getAppValue('openregister', 'solr_nightly_collect_errors', 'false') === 'true';

        return [
            'maxObjects'    => (int) $maxObjects,
            'mode'          => $mode,
            'collectErrors' => $collectErrors,
        ];

    }//end getWarmupConfiguration()


    /**
     * Summarize operations for logging.
     *
     * @param array $operations Operations array
     *
     * @return (float|int)[] Summary array
     *
     * @psalm-return array{total: int<0, max>, successful: int, efficiency: float}
     */
    private function summarizeOperations(array $operations): array
    {
        return [
            'total'      => count($operations),
            'successful' => $this->countSuccessfulWarmupQueries($operations),
            'efficiency' => $this->calculateWarmupEfficiency(['operations' => $operations]),
        ];

    }//end summarizeOperations()


    /**
     * Log performance statistics.
     *
     * @param array           $result        Warmup result
     * @param float           $executionTime Total execution time in seconds
     * @param LoggerInterface $logger        Logger instance
     *
     * @return void
     */
    private function logPerformanceStats(array $result, float $executionTime, LoggerInterface $logger): void
    {
        $logger->info(
            message: 'SOLR Nightly Warmup Performance Stats',
            context: [
                'execution_time_seconds' => round($executionTime, 2),
                'objects_per_second'     => $this->calculateObjectsPerSecond(result: $result, executionTime: $executionTime),
                'efficiency_percentage'  => $this->calculateWarmupEfficiency($result),
            ]
        );

    }//end logPerformanceStats()


}//end class
