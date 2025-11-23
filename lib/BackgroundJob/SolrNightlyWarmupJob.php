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
     * Constructor
     *
     * @param ITimeFactory $time Time factory for job scheduling
     *
     * @return void
     */
    public function __construct(ITimeFactory $time)
    {
        parent::__construct($time);

        // Set interval to 24 hours (daily execution).
        $this->setInterval(self::DEFAULT_INTERVAL);

        // Set the job to run at 00:00 UTC by default.
        // Note: Nextcloud will handle timezone conversion based on server settings.
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_SENSITIVE);

    }//end __construct()


    /**
     * Execute the nightly SOLR warmup job
     *
     * @param array $arguments Job arguments (unused for recurring jobs)
     *
     * @return void
     */
    protected function run($arguments): void
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
            if ($this->isSolrEnabledAndAvailable($solrService, $settingsService, $logger) === false) {
                $logger->info(message: 'SOLR Nightly Warmup Job skipped - SOLR not enabled or available');
                return;
            }

            // Get warmup configuration from settings.
            $config = $this->getWarmupConfiguration($settingsService, $logger);

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
                                'objects_per_second' => $this->calculateObjectsPerSecond($result, $executionTime),
                                'next_run'           => date('Y-m-d H:i:s', time() + self::DEFAULT_INTERVAL),
                            ],
                            'operations_summary'     => $this->summarizeOperations($result['operations'] ?? []),
                        ]
                        );

                // Log performance statistics for monitoring.
                $this->logPerformanceStats($result, $executionTime, $logger);
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
     * Check if SOLR is enabled and available for nightly warmup
     *
     * @param GuzzleSolrService $solrService     The SOLR service
     * @param SettingsService   $settingsService The settings service
     * @param LoggerInterface   $logger          The logger
     *
     * @return bool True if SOLR is enabled and available
     */
    private function isSolrEnabledAndAvailable(
        GuzzleSolrService $solrService,
        SettingsService $settingsService,
        LoggerInterface $logger
    ): bool {
        try {
            // Check if SOLR is enabled in settings.
            $solrSettings = $settingsService->getSolrSettings();

            if (($solrSettings['enabled'] ?? false) === false) {
                $logger->info(message: 'SOLR is disabled in settings, skipping nightly warmup');
                return false;
            }

            // Test SOLR connection.
            $connectionTest = $solrService->testConnection();

            if ($connectionTest['success'] ?? false) {
                return true;
            }

            $logger->warning(
                    message: 'SOLR connection test failed during nightly warmup job',
                    context: [
                        'test_result' => $connectionTest,
                    ]
                    );

            return false;
        } catch (\Exception $e) {
            $logger->warning(
                    message: 'SOLR availability check failed during nightly warmup job',
                    context: [
                        'error' => $e->getMessage(),
                    ]
                    );

            return false;
        }//end try

    }//end isSolrEnabledAndAvailable()


    /**
     * Get warmup configuration from settings with defaults
     *
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger
     *
     * @return array Warmup configuration
     */
    private function getWarmupConfiguration(SettingsService $settingsService, LoggerInterface $logger): array
    {
        try {
            $solrSettings = $settingsService->getSolrSettings();

            // Get nightly warmup specific settings with defaults.
            $config = [
                'maxObjects'    => $solrSettings['nightly_warmup_max_objects'] ?? self::DEFAULT_NIGHTLY_MAX_OBJECTS,
                'mode'          => $solrSettings['nightly_warmup_mode'] ?? self::DEFAULT_NIGHTLY_MODE,
                'collectErrors' => $solrSettings['nightly_warmup_collect_errors'] ?? true,
            // More detailed logging for nightly runs.
            ];

            $logger->debug('Nightly warmup configuration loaded', $config);

            return $config;
        } catch (\Exception $e) {
            $logger->warning(
                    'Failed to load nightly warmup configuration, using defaults',
                    [
                        'error' => $e->getMessage(),
                    ]
                    );

            return [
                'maxObjects'    => self::DEFAULT_NIGHTLY_MAX_OBJECTS,
                'mode'          => self::DEFAULT_NIGHTLY_MODE,
                'collectErrors' => true,
            ];
        }//end try

    }//end getWarmupConfiguration()


    /**
     * Calculate objects per second performance metric
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


    /**
     * Summarize operations for logging
     *
     * @param array $operations Operations array from warmup result
     *
     * @return array Summarized operations
     */
    private function summarizeOperations(array $operations): array
    {
        return [
            'connection_test'           => $operations['connection_test'] ?? false,
            'schema_mirroring'          => $operations['schema_mirroring'] ?? false,
            'object_indexing'           => $operations['object_indexing'] ?? false,
            'commit'                    => $operations['commit'] ?? false,
            'warmup_queries_successful' => $this->countSuccessfulWarmupQueries($operations),
        ];

    }//end summarizeOperations()


    /**
     * Count successful warmup queries
     *
     * @param array $operations Operations array
     *
     * @return int Number of successful warmup queries
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
     * Log detailed performance statistics for monitoring
     *
     * @param array           $result        Warmup result
     * @param float           $executionTime Total execution time
     * @param LoggerInterface $logger        The logger
     *
     * @return void
     */
    private function logPerformanceStats(array $result, float $executionTime, LoggerInterface $logger): void
    {
        $stats = [
            'nightly_warmup_performance' => [
                'date'                   => date('Y-m-d'),
                'execution_time_seconds' => round($executionTime, 2),
                'objects_indexed'        => $result['operations']['objects_indexed'] ?? 0,
                'objects_per_second'     => $this->calculateObjectsPerSecond($result, $executionTime),
                'schemas_processed'      => $result['operations']['schemas_processed'] ?? 0,
                'fields_created'         => $result['operations']['fields_created'] ?? 0,
                'memory_usage_mb'        => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'warmup_efficiency'      => $this->calculateWarmupEfficiency($result),
            ],
        ];

        $logger->info('📊 Nightly SOLR Warmup Performance Statistics', $stats);

    }//end logPerformanceStats()


    /**
     * Calculate warmup efficiency percentage
     *
     * @param array $result Warmup result
     *
     * @return float Efficiency percentage
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


}//end class
