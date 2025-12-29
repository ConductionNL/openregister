<?php

/**
 * OpenRegister Solr Settings Handler
 *
 * This file contains the handler class for managing SOLR configuration and operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Settings;

use Exception;
use RuntimeException;
use InvalidArgumentException;
use OCP\IConfig;
use OCP\AppFramework\IAppContainer;
use OCA\OpenRegister\Service\Object\CacheHandler;
use Psr\Log\LoggerInterface;

/**
 * Handler for SOLR settings and operations.
 *
 * This handler is responsible for managing SOLR configuration,
 * dashboard statistics, and facet configuration.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */
class SolrSettingsHandler
{

    /**
     * Configuration service
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * Object cache service (lazy-loaded when needed)
     *
     * @var CacheHandler|null
     */
    private ?CacheHandler $objectCacheService = null;

    /**
     * Container for lazy loading services
     *
     * @var IAppContainer|null
     */
    private ?IAppContainer $container = null;

    /**
     * Application name
     *
     * @var string
     */
    private string $appName;

    /**
     * Logger instance
     *
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger = null;

    /**
     * Constructor for SolrSettingsHandler
     *
     * @param IConfig            $config             Configuration service.
     * @param CacheHandler|null  $objectCacheService Object cache service (optional, lazy-loaded).
     * @param IAppContainer|null $container          Container for lazy loading (optional).
     * @param LoggerInterface    $logger             Logger for logging operations.
     * @param string             $appName            Application name.
     *
     * @return void
     */
    public function __construct(
        IConfig $config,
        ?CacheHandler $objectCacheService=null,
        ?IAppContainer $container=null,
        ?LoggerInterface $logger=null,
        string $appName='openregister'
    ) {
        $this->config = $config;
        $this->objectCacheService = $objectCacheService;
        $this->container          = $container;
        $this->logger  = $logger;
        $this->appName = $appName;
    }//end __construct()

    /**
     * Get SOLR configuration settings
     *
     * @return array SOLR configuration array
     *
     * @throws \RuntimeException if SOLR settings retrieval fails
     */
    public function getSolrSettings(): array
    {
        try {
            $solrConfig = $this->config->getAppValue($this->appName, 'solr', '');
            if (empty($solrConfig) === true) {
                return [
                    'enabled'        => false,
                    'host'           => 'solr',
                    'port'           => 8983,
                    'path'           => '/solr',
                    'core'           => 'openregister',
                    'configSet'      => '_default',
                    'scheme'         => 'http',
                    'username'       => '',
                    'password'       => '',
                    'timeout'        => 30,
                    'autoCommit'     => true,
                    'commitWithin'   => 1000,
                    'enableLogging'  => true,
                    'zookeeperHosts' => 'zookeeper:2181',
                    'collection'     => 'openregister',
                    'useCloud'       => true,
                ];
            }

            return json_decode($solrConfig, true);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve SOLR settings: '.$e->getMessage());
        }//end try
    }//end getSolrSettings()

    /**
     * Complete SOLR warmup: mirror schemas and index objects from the database
     *
     * This method performs comprehensive SOLR index warmup by:
     * 1. Mirroring all OpenRegister schemas to SOLR for proper field typing
     * 2. Bulk indexing objects from the database using schema-aware mapping
     * 3. Performing cache warmup queries
     * 4. Committing and optimizing the index
     *
     * @param  int $batchSize  Number of objects to process per batch (default 1000, parameter kept for API compatibility)
     * @param  int $maxObjects Maximum number of objects to index (0 = all)
     * @return array Warmup operation results with statistics and status
     * @throws \RuntimeException If SOLR warmup fails
     */

    /**
     * Complete search index warmup: mirror schemas and index objects from the database
     *
     * @deprecated This method is deprecated. Use IndexService->warmupIndex() directly via controller.
     * This method is kept for backward compatibility but should not be used.
     * The controller now uses IndexService directly to avoid circular dependencies.
     *
     * @param int    $_batchSize    Number of objects to process per batch (unused, kept for API compatibility)
     * @param int    $maxObjects    Maximum number of objects to index (unused, kept for API compatibility)
     * @param string $mode          Processing mode (unused, kept for API compatibility)
     * @param bool   $collectErrors Whether to collect errors (unused, kept for API compatibility)
     *
     * @return never Warmup operation results with statistics and status
     *
     * @throws \RuntimeException Always throws exception indicating method is deprecated
     */
    public function warmupSolrIndex()
    {
        // NOTE: This method is deprecated. Use IndexService->warmupIndex() directly via controller.
        // This method is kept for backward compatibility but should not be used.
        // The controller now uses IndexService directly to avoid circular dependencies.
        throw new RuntimeException(
            'SettingsService::warmupSolrIndex() is deprecated. Use IndexService->warmupIndex() directly via controller.'
        );
    }//end warmupSolrIndex()

    /**
     * Get comprehensive SOLR dashboard statistics
     *
     * Provides detailed metrics for the SOLR Search Management dashboard
     * including core statistics, performance metrics, and health indicators.
     *
     * @return (((bool|int|mixed|null|string)[]|bool|float|int|mixed|null|string)[]|mixed|string)[] SOLR dashboard metrics and statistics
     *
     * @throws \RuntimeException If SOLR statistics retrieval fails
     *
     * @psalm-return array{overview: array{available: bool, connection_status: 'unavailable'|'unknown'|mixed, response_time_ms: 0, total_documents: 0|mixed, index_size: string, last_commit: mixed|null}, cores: array{active_core: 'unknown'|mixed, core_status: 'active'|'inactive', endpoint_url: 'N/A'}, performance: array{total_searches: 0|mixed, total_indexes: 0|mixed, total_deletes: 0|mixed, avg_search_time_ms: 0|float, avg_index_time_ms: 0|float, total_search_time: 0|mixed, total_index_time: 0|mixed, operations_per_sec: 0|float, error_rate: 0|float}, health: array{status: 'unavailable'|'unknown'|mixed, uptime: 'N/A', memory_usage: array{used: 'N/A', max: 'N/A', percentage: 0}, disk_usage: array{used: 'N/A', available: 'N/A', percentage: 0}, warnings: list{0?: 'SOLR service is not available or not configured'|mixed}, last_optimization: null}, operations: array{recent_activity: array<never, never>, queue_status: array{pending_operations: 0, processing: false, last_processed: null}, commit_frequency: array{auto_commit: bool, commit_within: 0|1000, last_commit: mixed|null}, optimization_needed: false}, generated_at: string, error?: mixed|string}
     */
    public function getSolrDashboardStats(): array
    {
        try {
            $objectCacheService = $this->objectCacheService;
            if ($objectCacheService === null && $this->container !== null) {
                try {
                    $objectCacheService = $this->container->get(CacheHandler::class);
                } catch (Exception $e) {
                    throw new Exception('CacheHandler not available');
                }
            }

            if ($objectCacheService === null) {
                throw new Exception('CacheHandler not available');
            }

            $rawStats = $objectCacheService->getSolrDashboardStats();

            // Transform the raw stats into the expected dashboard structure.
            return $this->transformSolrStatsToDashboard($rawStats);
        } catch (Exception $e) {
            // Return default dashboard structure if SOLR is not available.
            return [
                'overview'     => [
                    'available'         => false,
                    'connection_status' => 'unavailable',
                    'response_time_ms'  => 0,
                    'total_documents'   => 0,
                    'index_size'        => '0 B',
                    'last_commit'       => null,
                ],
                'cores'        => [
                    'active_core'  => 'unknown',
                    'core_status'  => 'inactive',
                    'endpoint_url' => 'N/A',
                ],
                'performance'  => [
                    'total_searches'     => 0,
                    'total_indexes'      => 0,
                    'total_deletes'      => 0,
                    'avg_search_time_ms' => 0,
                    'avg_index_time_ms'  => 0,
                    'total_search_time'  => 0,
                    'total_index_time'   => 0,
                    'operations_per_sec' => 0,
                    'error_rate'         => 0,
                ],
                'health'       => [
                    'status'            => 'unavailable',
                    'uptime'            => 'N/A',
                    'memory_usage'      => ['used' => 'N/A', 'max' => 'N/A', 'percentage' => 0],
                    'disk_usage'        => ['used' => 'N/A', 'available' => 'N/A', 'percentage' => 0],
                    'warnings'          => ['SOLR service is not available or not configured'],
                    'last_optimization' => null,
                ],
                'operations'   => [
                    'recent_activity'     => [],
                    'queue_status'        => ['pending_operations' => 0, 'processing' => false, 'last_processed' => null],
                    'commit_frequency'    => ['auto_commit' => false, 'commit_within' => 0, 'last_commit' => null],
                    'optimization_needed' => false,
                ],
                'generated_at' => date('c'),
                'error'        => $e->getMessage(),
            ];
        }//end try
    }//end getSolrDashboardStats()

    /**
     * Transform raw SOLR stats into dashboard structure
     *
     * @param array $rawStats Raw statistics from SOLR service
     *
     * @return (((bool|int|mixed|null|string)[]|bool|float|int|mixed|null|string)[]|mixed|string)[] Transformed dashboard statistics
     *
     * @psalm-return array{overview: array{available: bool, connection_status: 'unavailable'|'unknown'|mixed, response_time_ms: 0, total_documents: 0|mixed, index_size: string, last_commit: mixed|null}, cores: array{active_core: 'unknown'|mixed, core_status: 'active'|'inactive', endpoint_url: 'N/A'}, performance: array{total_searches: 0|mixed, total_indexes: 0|mixed, total_deletes: 0|mixed, avg_search_time_ms: 0|float, avg_index_time_ms: 0|float, total_search_time: 0|mixed, total_index_time: 0|mixed, operations_per_sec: 0|float, error_rate: 0|float}, health: array{status: 'unavailable'|'unknown'|mixed, uptime: 'N/A', memory_usage: array{used: 'N/A', max: 'N/A', percentage: 0}, disk_usage: array{used: 'N/A', available: 'N/A', percentage: 0}, warnings: list{0?: 'SOLR service is not available or not configured'|mixed}, last_optimization: null}, operations: array{recent_activity: array<never, never>, queue_status: array{pending_operations: 0, processing: false, last_processed: null}, commit_frequency: array{auto_commit: bool, commit_within: 0|1000, last_commit: mixed|null}, optimization_needed: false}, generated_at: string, error?: 'SOLR service unavailable'|mixed}
     */
    private function transformSolrStatsToDashboard(array $rawStats): array
    {
        // If SOLR is not available, return error structure.
        if (($rawStats['available'] ?? false) === false) {
            return [
                'overview'     => [
                    'available'         => false,
                    'connection_status' => 'unavailable',
                    'response_time_ms'  => 0,
                    'total_documents'   => 0,
                    'index_size'        => '0 B',
                    'last_commit'       => null,
                ],
                'cores'        => [
                    'active_core'  => 'unknown',
                    'core_status'  => 'inactive',
                    'endpoint_url' => 'N/A',
                ],
                'performance'  => [
                    'total_searches'     => 0,
                    'total_indexes'      => 0,
                    'total_deletes'      => 0,
                    'avg_search_time_ms' => 0,
                    'avg_index_time_ms'  => 0,
                    'total_search_time'  => 0,
                    'total_index_time'   => 0,
                    'operations_per_sec' => 0,
                    'error_rate'         => 0,
                ],
                'health'       => [
                    'status'            => 'unavailable',
                    'uptime'            => 'N/A',
                    'memory_usage'      => ['used' => 'N/A', 'max' => 'N/A', 'percentage' => 0],
                    'disk_usage'        => ['used' => 'N/A', 'available' => 'N/A', 'percentage' => 0],
                    'warnings'          => [$rawStats['error'] ?? 'SOLR service is not available or not configured'],
                    'last_optimization' => null,
                ],
                'operations'   => [
                    'recent_activity'     => [],
                    'queue_status'        => ['pending_operations' => 0, 'processing' => false, 'last_processed' => null],
                    'commit_frequency'    => ['auto_commit' => false, 'commit_within' => 0, 'last_commit' => null],
                    'optimization_needed' => false,
                ],
                'generated_at' => date('c'),
                'error'        => $rawStats['error'] ?? 'SOLR service unavailable',
            ];
        }//end if

        // Transform available SOLR stats into dashboard structure.
        $serviceStats = $rawStats['service_stats'] ?? [];
        $totalOps     = ($serviceStats['searches'] ?? 0) + ($serviceStats['indexes'] ?? 0) + ($serviceStats['deletes'] ?? 0);
        $totalTime    = ($serviceStats['search_time'] ?? 0) + ($serviceStats['index_time'] ?? 0);

        // Calculate operations per second.
        if ($totalTime > 0) {
            $opsPerSec = round($totalOps / ($totalTime / 1000), 2);
        } else {
            $opsPerSec = 0;
        }

        // Calculate error rate.
        if ($totalOps > 0) {
            $errorRate = round(($serviceStats['errors'] ?? 0) / $totalOps * 100, 2);
        } else {
            $errorRate = 0;
        }

        // Determine core status.
        if ($rawStats['available'] === true) {
            $coreStatus = 'active';
        } else {
            $coreStatus = 'inactive';
        }

        // Calculate average search time.
        if (($serviceStats['searches'] ?? 0) > 0) {
            $avgSearchTimeMs = round(($serviceStats['search_time'] ?? 0) / ($serviceStats['searches'] ?? 1), 2);
        } else {
            $avgSearchTimeMs = 0;
        }

        // Calculate average index time.
        if (($serviceStats['indexes'] ?? 0) > 0) {
            $avgIndexTimeMs = round(($serviceStats['index_time'] ?? 0) / ($serviceStats['indexes'] ?? 1), 2);
        } else {
            $avgIndexTimeMs = 0;
        }

        return [
            'overview'     => [
                'available'         => true,
                'connection_status' => $rawStats['health'] ?? 'unknown',
                'response_time_ms'  => 0,
        // Not available in raw stats.
                'total_documents'   => $rawStats['document_count'] ?? 0,
                'index_size'        => $this->formatBytesForDashboard(($rawStats['index_size'] ?? 0) * 1024),
        // Assuming KB.
                'last_commit'       => $rawStats['last_modified'] ?? null,
            ],
            'cores'        => [
                'active_core'  => $rawStats['collection'] ?? 'unknown',
                'core_status'  => $coreStatus,
                'endpoint_url' => 'N/A',
            // Endpoint URL no longer available in SettingsService (use IndexService directly).
            ],
            'performance'  => [
                'total_searches'     => $serviceStats['searches'] ?? 0,
                'total_indexes'      => $serviceStats['indexes'] ?? 0,
                'total_deletes'      => $serviceStats['deletes'] ?? 0,
                'avg_search_time_ms' => $avgSearchTimeMs,
                'avg_index_time_ms'  => $avgIndexTimeMs,
                'total_search_time'  => $serviceStats['search_time'] ?? 0,
                'total_index_time'   => $serviceStats['index_time'] ?? 0,
                'operations_per_sec' => $opsPerSec,
                'error_rate'         => $errorRate,
            ],
            'health'       => [
                'status'            => $rawStats['health'] ?? 'unknown',
                'uptime'            => 'N/A',
            // Not available in raw stats.
                'memory_usage'      => ['used' => 'N/A', 'max' => 'N/A', 'percentage' => 0],
                'disk_usage'        => ['used' => 'N/A', 'available' => 'N/A', 'percentage' => 0],
                'warnings'          => [],
                'last_optimization' => null,
            ],
            'operations'   => [
                'recent_activity'     => [],
                'queue_status'        => ['pending_operations' => 0, 'processing' => false, 'last_processed' => null],
                'commit_frequency'    => ['auto_commit' => true, 'commit_within' => 1000, 'last_commit' => $rawStats['last_modified'] ?? null],
                'optimization_needed' => false,
            ],
            'generated_at' => date('c'),
        ];
    }//end transformSolrStatsToDashboard()

    /**
     * Format bytes to human readable format for dashboard
     *
     * @param int $bytes Number of bytes
     *
     * @return string Formatted byte string
     */
    private function formatBytesForDashboard(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units  = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor(log($bytes, 1024));
        $factor = min($factor, count($units) - 1);

        return round($bytes / pow(1024, $factor), 2).' '.$units[$factor];
    }//end formatBytesForDashboard()

    /**
     * Get focused SOLR settings only
     *
     * @return (bool|int|mixed|null|string)[] SOLR configuration with tenant information
     *
     * @throws \RuntimeException If SOLR settings retrieval fails
     *
     * @psalm-return array{enabled: false|mixed, host: 'solr'|mixed, port: 8983|mixed, path: '/solr'|mixed, core: 'openregister'|mixed, configSet: '_default'|mixed, scheme: 'http'|mixed, username: 'solr'|mixed, password: 'SolrRocks'|mixed, timeout: 30|mixed, autoCommit: mixed|true, commitWithin: 1000|mixed, enableLogging: mixed|true, zookeeperHosts: 'zookeeper:2181'|mixed, zookeeperUsername: ''|mixed, zookeeperPassword: ''|mixed, collection: 'openregister'|mixed, useCloud: mixed|true, objectCollection: mixed|null, fileCollection: mixed|null}
     */
    public function getSolrSettingsOnly(): array
    {
        try {
            $solrConfig = $this->config->getAppValue($this->appName, 'solr', '');

            if (empty($solrConfig) === true) {
                return [
                    'enabled'           => false,
                    'host'              => 'solr',
                    'port'              => 8983,
                    'path'              => '/solr',
                    'core'              => 'openregister',
                    'configSet'         => '_default',
                    'scheme'            => 'http',
                    'username'          => 'solr',
                    'password'          => 'SolrRocks',
                    'timeout'           => 30,
                    'autoCommit'        => true,
                    'commitWithin'      => 1000,
                    'enableLogging'     => true,
                    'zookeeperHosts'    => 'zookeeper:2181',
                    'zookeeperUsername' => '',
                    'zookeeperPassword' => '',
                    'collection'        => 'openregister',
                    'useCloud'          => true,
                    'objectCollection'  => null,
                    'fileCollection'    => null,
                ];
            }//end if

            $solrData = json_decode($solrConfig, true);
            return [
                'enabled'           => $solrData['enabled'] ?? false,
                'host'              => $solrData['host'] ?? 'solr',
                'port'              => $solrData['port'] ?? 8983,
                'path'              => $solrData['path'] ?? '/solr',
                'core'              => $solrData['core'] ?? 'openregister',
                'configSet'         => $solrData['configSet'] ?? '_default',
                'scheme'            => $solrData['scheme'] ?? 'http',
                'username'          => $solrData['username'] ?? 'solr',
                'password'          => $solrData['password'] ?? 'SolrRocks',
                'timeout'           => $solrData['timeout'] ?? 30,
                'autoCommit'        => $solrData['autoCommit'] ?? true,
                'commitWithin'      => $solrData['commitWithin'] ?? 1000,
                'enableLogging'     => $solrData['enableLogging'] ?? true,
                'zookeeperHosts'    => $solrData['zookeeperHosts'] ?? 'zookeeper:2181',
                'zookeeperUsername' => $solrData['zookeeperUsername'] ?? '',
                'zookeeperPassword' => $solrData['zookeeperPassword'] ?? '',
                'collection'        => $solrData['collection'] ?? 'openregister',
                'useCloud'          => $solrData['useCloud'] ?? true,
                'objectCollection'  => $solrData['objectCollection'] ?? null,
                'fileCollection'    => $solrData['fileCollection'] ?? null,
            ];
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve SOLR settings: '.$e->getMessage());
        }//end try
    }//end getSolrSettingsOnly()

    /**
     * Update SOLR settings only
     *
     * @param array $solrData SOLR configuration data
     *
     * @return (bool|int|mixed|null|string)[] Updated SOLR configuration
     *
     * @throws \RuntimeException If SOLR settings update fails
     *
     * @psalm-return array{enabled: false|mixed, host: 'solr'|mixed, port: int, path: '/solr'|mixed, core: 'openregister'|mixed, configSet: '_default'|mixed, scheme: 'http'|mixed, username: 'solr'|mixed, password: 'SolrRocks'|mixed, timeout: int, autoCommit: mixed|true, commitWithin: int, enableLogging: mixed|true, zookeeperHosts: 'zookeeper:2181'|mixed, zookeeperUsername: ''|mixed, zookeeperPassword: ''|mixed, collection: 'openregister'|mixed, useCloud: mixed|true, objectCollection: mixed|null, fileCollection: mixed|null}
     */
    public function updateSolrSettingsOnly(array $solrData): array
    {
        try {
            $solrConfig = [
                'enabled'           => $solrData['enabled'] ?? false,
                'host'              => $solrData['host'] ?? 'solr',
                'port'              => (int) ($solrData['port'] ?? 8983),
                'path'              => $solrData['path'] ?? '/solr',
                'core'              => $solrData['core'] ?? 'openregister',
                'configSet'         => $solrData['configSet'] ?? '_default',
                'scheme'            => $solrData['scheme'] ?? 'http',
                'username'          => $solrData['username'] ?? 'solr',
                'password'          => $solrData['password'] ?? 'SolrRocks',
                'timeout'           => (int) ($solrData['timeout'] ?? 30),
                'autoCommit'        => $solrData['autoCommit'] ?? true,
                'commitWithin'      => (int) ($solrData['commitWithin'] ?? 1000),
                'enableLogging'     => $solrData['enableLogging'] ?? true,
                'zookeeperHosts'    => $solrData['zookeeperHosts'] ?? 'zookeeper:2181',
                'zookeeperUsername' => $solrData['zookeeperUsername'] ?? '',
                'zookeeperPassword' => $solrData['zookeeperPassword'] ?? '',
                'collection'        => $solrData['collection'] ?? 'openregister',
                'useCloud'          => $solrData['useCloud'] ?? true,
            // Collection assignments for objects and files.
                'objectCollection'  => $solrData['objectCollection'] ?? null,
                'fileCollection'    => $solrData['fileCollection'] ?? null,
            ];

            $this->config->setAppValue($this->appName, 'solr', json_encode($solrConfig));
            return $solrConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update SOLR settings: '.$e->getMessage());
        }//end try
    }//end updateSolrSettingsOnly()

    /**
     * Get search backend configuration.
     *
     * Returns which search backend is currently active (solr, elasticsearch, etc).
     *
     * @return array Backend configuration with 'active' key
     *
     * @throws \RuntimeException If backend configuration retrieval fails
     */
    public function getSearchBackendConfig(): array
    {
        try {
            $backendConfig = $this->config->getAppValue($this->appName, 'search_backend', '');

            if (empty($backendConfig) === true) {
                return [
                    'active'    => 'solr',
                // Default to Solr for backward compatibility.
                    'available' => ['solr', 'elasticsearch'],
                ];
            }

            return json_decode($backendConfig, true);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve search backend configuration: '.$e->getMessage());
        }
    }//end getSearchBackendConfig()

    /**
     * Update search backend configuration.
     *
     * Sets which search backend should be active.
     *
     * @param string $backend Backend name ('solr', 'elasticsearch', etc)
     *
     * @return (int|string[])[] Updated backend configuration
     *
     * @throws \RuntimeException If backend configuration update fails
     *
     * @psalm-return array{active: string, available: list{'solr', 'elasticsearch'}, updated: int<1, max>}
     */
    public function updateSearchBackendConfig(string $backend): array
    {
        try {
            $availableBackends = ['solr', 'elasticsearch'];

            if (in_array($backend, $availableBackends) === false) {
                throw new InvalidArgumentException(
                    "Invalid backend '$backend'. Must be one of: ".implode(', ', $availableBackends)
                );
            }

            $backendConfig = [
                'active'    => $backend,
                'available' => $availableBackends,
                'updated'   => time(),
            ];

            $this->config->setAppValue($this->appName, 'search_backend', json_encode($backendConfig));

            $this->logger->info(
                'Search backend changed to: '.$backend,
                [
                    'app'     => 'openregister',
                    'backend' => $backend,
                ]
            );

            return $backendConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update search backend configuration: '.$e->getMessage());
        }//end try
    }//end updateSearchBackendConfig()

    /**
     * Get SOLR facet configuration
     *
     * Returns the configuration for customizing SOLR facets including
     * custom titles, ordering, and descriptions.
     *
     * @return array Facet configuration array
     *
     * @throws \RuntimeException If facet configuration retrieval fails
     */
    public function getSolrFacetConfiguration(): array
    {
        try {
            $facetConfig = $this->config->getAppValue($this->appName, 'solr_facet_config', '');
            if (empty($facetConfig) === true) {
                return [
                    'facets'           => [],
                    'global_order'     => [],
                    'default_settings' => [
                        'show_count' => true,
                        'show_empty' => false,
                        'max_items'  => 10,
                    ],
                ];
            }

            return json_decode($facetConfig, true);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to retrieve SOLR facet configuration: '.$e->getMessage());
        }
    }//end getSolrFacetConfiguration()

    /**
     * Update SOLR facet configuration
     *
     * Updates the configuration for customizing SOLR facets including
     * custom titles, ordering, and descriptions.
     *
     * Expected structure:
     * [
     * 'facets' => [
     * 'field_name' => [
     * 'title' => 'Custom Title',
     * 'description' => 'Custom description',
     * 'order' => 1,
     * 'enabled' => true,
     * 'show_count' => true,
     * 'max_items' => 10
     * ]
     * ],
     * 'global_order' => ['field1', 'field2', 'field3'],
     * 'default_settings' => [
     * 'show_count' => true,
     * 'show_empty' => false,
     * 'max_items' => 10
     * ]
     * ]
     *
     * @param array $facetConfig Facet configuration data
     *
     * @return ((bool|int|mixed|string)[]|bool|int|string)[][] Updated facet configuration
     *
     * @throws \RuntimeException If facet configuration update fails
     *
     * @psalm-return array{facets: array<string, array{title: mixed|string, description: ''|mixed, order: int, enabled: bool, show_count: bool, max_items: int}>, global_order: array<string>, default_settings: array{show_count: bool, show_empty: bool, max_items: int}}
     */
    public function updateSolrFacetConfiguration(array $facetConfig): array
    {
        try {
            // Validate the configuration structure.
            $validatedConfig = $this->validateFacetConfiguration($facetConfig);

            $this->config->setAppValue($this->appName, 'solr_facet_config', json_encode($validatedConfig));
            return $validatedConfig;
        } catch (Exception $e) {
            throw new RuntimeException('Failed to update SOLR facet configuration: '.$e->getMessage());
        }
    }//end updateSolrFacetConfiguration()

    /**
     * Validate facet configuration structure
     *
     * @param array $config Configuration to validate
     *
     * @return ((bool|int|mixed|string)[]|bool|int|string)[][]
     *
     * @throws \InvalidArgumentException If configuration is invalid
     *
     * @psalm-return array{facets: array<string, array{title: mixed|string, description: ''|mixed, order: int, enabled: bool, show_count: bool, max_items: int}>, global_order: array<string>, default_settings: array{show_count: bool, show_empty: bool, max_items: int}}
     */
    private function validateFacetConfiguration(array $config): array
    {
        $validatedConfig = [
            'facets'           => [],
            'global_order'     => [],
            'default_settings' => [
                'show_count' => true,
                'show_empty' => false,
                'max_items'  => 10,
            ],
        ];

        // Validate facets configuration.
        if (($config['facets'] ?? null) !== null && is_array($config['facets']) === true) {
            foreach ($config['facets'] as $fieldName => $facetConfig) {
                if (is_string($fieldName) === false || empty($fieldName) === true) {
                    continue;
                }

                $validatedFacet = [
                    'title'       => $facetConfig['title'] ?? $fieldName,
                    'description' => $facetConfig['description'] ?? '',
                    'order'       => (int) ($facetConfig['order'] ?? 0),
                    'enabled'     => (bool) ($facetConfig['enabled'] ?? true),
                    'show_count'  => (bool) ($facetConfig['show_count'] ?? true),
                    'max_items'   => (int) ($facetConfig['max_items'] ?? 10),
                ];

                $validatedConfig['facets'][$fieldName] = $validatedFacet;
            }
        }

        // Validate global order.
        if (($config['global_order'] ?? null) !== null && is_array($config['global_order']) === true) {
            $validatedConfig['global_order'] = array_filter($config['global_order'], 'is_string');
        }

        // Validate default settings.
        if (($config['default_settings'] ?? null) !== null && is_array($config['default_settings']) === true) {
            $defaults = $config['default_settings'];
            $validatedConfig['default_settings'] = [
                'show_count' => (bool) ($defaults['show_count'] ?? true),
                'show_empty' => (bool) ($defaults['show_empty'] ?? false),
                'max_items'  => (int) ($defaults['max_items'] ?? 10),
            ];
        }

        return $validatedConfig;
    }//end validateFacetConfiguration()
}//end class
