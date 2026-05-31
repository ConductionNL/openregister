<?php

/**
 * OpenRegister SOLR Nightly Warmup Background Job
 *
 * Recurring background job that runs every night at 00:00 to warm up the SOLR index.
 * This ensures optimal search performance by keeping the index warm and ready for queries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
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
 *
 * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
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
     * @param ITimeFactory    $time            Time factory for parent class
     * @param IndexService    $indexService    SOLR index service
     * @param SettingsService $settingsService Settings service
     * @param SchemaMapper    $schemaMapper    Schema mapper
     * @param LoggerInterface $logger          Logger
     * @param IConfig         $config          App config
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    public function __construct(
        ITimeFactory $time,
        private readonly IndexService $indexService,
        private readonly SettingsService $settingsService,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger,
        private readonly IConfig $config,
    ) {
        parent::__construct(time: $time);
        $this->setInterval(seconds: self::DEFAULT_INTERVAL);
    }//end __construct()

    /**
     * Execute the nightly SOLR warmup job
     *
     * @param mixed $argument Job arguments (unused for recurring jobs)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    protected function run($argument): void
    {
        $startTime = microtime(true);

        $this->logger->info(
            message: '[SolrNightlyWarmupJob] 🌙 SOLR Nightly Warmup Job Started',
            context: [
                'file'           => __FILE__,
                'line'           => __LINE__,
                'job_id'         => $this->getId(),
                'scheduled_time' => date('Y-m-d H:i:s'),
                'timezone'       => date_default_timezone_get(),
            ]
        );

        try {
            // Check if SOLR is enabled and available.
            $isSolrAvailable = $this->isSolrEnabledAndAvailable(
                solrService: $this->indexService,
                settingsService: $this->settingsService,
                logger: $this->logger
            );
            if ($isSolrAvailable === false) {
                // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                $this->logger->info(message: '[SolrNightlyWarmupJob] SOLR Nightly Warmup Job skipped - SOLR not enabled or available', context: ['file' => __FILE__, 'line' => __LINE__]);
                return;
            }

            // Get warmup configuration from settings.
            $config = $this->getWarmupConfiguration();

            // Get all schemas for comprehensive warmup.
            $schemas = $this->schemaMapper->findAll();

            $this->logger->info(
                message: '[SolrNightlyWarmupJob] Starting nightly SOLR index warmup',
                context: [
                    'file'           => __FILE__,
                    'line'           => __LINE__,
                    'schemas_found'  => count($schemas),
                    'max_objects'    => $config['maxObjects'],
                    'mode'           => $config['mode'],
                    'collect_errors' => $config['collectErrors'],
                ]
            );

            // Execute the comprehensive nightly warmup.
            $result = $this->indexService->warmupIndex(
                schemas: $schemas,
                maxObjects: $config['maxObjects'],
                mode: $config['mode'],
                collectErrors: $config['collectErrors']
            );

            $executionTime = microtime(true) - $startTime;

            if ($result['success'] ?? false) {
                $this->logger->info(
                    message: '[SolrNightlyWarmupJob] ✅ SOLR Nightly Warmup Job Completed Successfully',
                    context: [
                        'file'                   => __FILE__,
                        'line'                   => __LINE__,
                        'job_id'                 => $this->getId(),
                        'execution_time_seconds' => round($executionTime, 2),
                        'objects_indexed'        => $result['operations']['objects_indexed'] ?? 0,
                        'schemas_processed'      => $result['operations']['schemas_processed'] ?? 0,
                        'fields_created'         => $result['operations']['fields_created'] ?? 0,
                        'conflicts_resolved'     => $result['operations']['conflicts_resolved'] ?? 0,
                        'performance_metrics'    => [
                            'total_time_ms'      => $result['execution_time_ms'] ?? 0,
                            'objects_per_second' => $this->calculateObjectsPerSecond(
                                result: $result,
                                executionTime: $executionTime
                            ),
                            'next_run'           => date('Y-m-d H:i:s', time() + self::DEFAULT_INTERVAL),
                        ],
                        'operations_summary'     => $this->summarizeOperations(operations: $result['operations'] ?? []),
                    ]
                );

                // Log performance statistics for monitoring.
                $this->logPerformanceStats(result: $result, executionTime: $executionTime, logger: $this->logger);
            }//end if

            if (($result['success'] ?? false) === false) {
                $this->logger->error(
                    message: '[SolrNightlyWarmupJob] ❌ SOLR Nightly Warmup Job Failed',
                    context: [
                        'file'                   => __FILE__,
                        'line'                   => __LINE__,
                        'job_id'                 => $this->getId(),
                        'execution_time_seconds' => round($executionTime, 2),
                        'error'                  => $result['error'] ?? 'Unknown error',
                        'next_retry'             => date('Y-m-d H:i:s', time() + self::DEFAULT_INTERVAL),
                    ]
                );
            }//end if
        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;

            $this->logger->error(
                message: '[SolrNightlyWarmupJob] 🚨 SOLR Nightly Warmup Job Exception',
                context: [
                    'file'                   => __FILE__,
                    'line'                   => __LINE__,
                    'job_id'                 => $this->getId(),
                    'execution_time_seconds' => round($executionTime, 2),
                    'exception'              => $e->getMessage(),
                    'exception_file'         => $e->getFile(),
                    'exception_line'         => $e->getLine(),
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
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
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
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
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
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
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
                function (bool $op): int {
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
     * @param IndexService    $solrService     SOLR service instance
     * @param SettingsService $settingsService Settings service instance
     * @param LoggerInterface $logger          Logger instance
     *
     * @return bool True if SOLR is enabled and available, false otherwise
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    private function isSolrEnabledAndAvailable(
        IndexService $solrService,
        SettingsService $settingsService,
        LoggerInterface $logger
    ): bool {
        // Check if SOLR is enabled in settings.
        $solrSettings = $settingsService->getSolrSettings();
        if (($solrSettings['enabled'] ?? false) === false) {
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            $logger->debug(message: '[SolrNightlyWarmupJob] SOLR Nightly Warmup Job skipped - SOLR not enabled in settings', context: ['file' => __FILE__, 'line' => __LINE__]);
            return false;
        }

        // Check if SOLR service is available.
        if ($solrService->isAvailable() === false) {
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            $logger->debug(message: '[SolrNightlyWarmupJob] SOLR Nightly Warmup Job skipped - SOLR service not available', context: ['file' => __FILE__, 'line' => __LINE__]);
            return false;
        }

        return true;
    }//end isSolrEnabledAndAvailable()

    /**
     * Get warmup configuration from app config.
     *
     * @return array Warmup configuration array
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    private function getWarmupConfiguration(): array
    {
        $defaultMaxObjects = (string) self::DEFAULT_NIGHTLY_MAX_OBJECTS;
        $maxObjects        = $this->config->getAppValue('openregister', 'solr_nightly_max_objects', $defaultMaxObjects);
        $mode          = $this->config->getAppValue('openregister', 'solr_nightly_mode', self::DEFAULT_NIGHTLY_MODE);
        $collectErrors = $this->config->getAppValue('openregister', 'solr_nightly_collect_errors', 'false') === 'true';

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
     * @return (float|int)[]
     *
     * @psalm-return array{total: int<0, max>, successful: int<0, max>, efficiency: float}
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    private function summarizeOperations(array $operations): array
    {
        return [
            'total'      => count($operations),
            'successful' => $this->countSuccessfulWarmupQueries(operations: $operations),
            'efficiency' => $this->calculateWarmupEfficiency(result: ['operations' => $operations]),
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
     *
     * @spec openspec/changes/retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-10
     */
    private function logPerformanceStats(array $result, float $executionTime, LoggerInterface $logger): void
    {
        $logger->info(
            message: '[SolrNightlyWarmupJob] SOLR Nightly Warmup Performance Stats',
            context: [
                'file'                   => __FILE__,
                'line'                   => __LINE__,
                'execution_time_seconds' => round($executionTime, 2),
                'objects_per_second'     => $this->calculateObjectsPerSecond(result: $result, executionTime: $executionTime),
                'efficiency_percentage'  => $this->calculateWarmupEfficiency(result: $result),
            ]
        );
    }//end logPerformanceStats()
}//end class
