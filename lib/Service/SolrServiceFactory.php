<?php

/**
 * SolrServiceFactory - Performance-optimized SOLR service factory
 *
 * This factory provides SOLR service instances without DI registration performance issues.
 * Uses lazy instantiation to avoid expensive dependency resolution on every request.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 */

namespace OCA\OpenRegister\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\AppFramework\IAppContainer;
use Psr\Log\LoggerInterface;

/**
 * Factory for creating SolrService instances without DI performance issues
 *
 * This factory solves the performance problem by:
 * 1. Avoiding DI registration of SolrService
 * 2. Creating instances only when actually needed
 * 3. Caching instances for reuse within a request
 */
class SolrServiceFactory
{
    /**
     * Cached GuzzleSolrService instance for current request
     *
     * @var GuzzleSolrService|null
     */
    private static ?GuzzleSolrService $cachedInstance = null;

    /**
     * Flag to track if SOLR is enabled (cached for performance)
     *
     * @var bool|null
     */
    private static ?bool $solrEnabled = null;

    /**
     * Create or retrieve GuzzleSolrService instance
     *
     * This method provides high-performance access to SOLR using lightweight Guzzle HTTP client
     * instead of the memory-intensive Solarium library.
     *
     * @param IAppContainer $container Application container for service resolution
     *
     * @return GuzzleSolrService|null GuzzleSolrService instance or null if disabled/unavailable
     */
    public static function createSolrService(IAppContainer $container): ?GuzzleSolrService
    {
        // Return cached instance if available
        if (self::$cachedInstance !== null) {
            return self::$cachedInstance;
        }

        // Quick check if SOLR is enabled (cached)
        if (!self::isSolrEnabled($container)) {
            return null;
        }

        try {
            // Manual service instantiation (avoiding DI registration performance issues)
            $settingsService = $container->get(SettingsService::class);
            $logger = $container->get(LoggerInterface::class);
            $clientService = $container->get(IClientService::class);
            $config = $container->get(IConfig::class);

            // Create GuzzleSolrService with lightweight HTTP client
            self::$cachedInstance = new GuzzleSolrService(
                $settingsService,  // Settings service for configuration
                $logger,           // Logger for debugging and monitoring
                $clientService,    // HTTP client service for SOLR requests
                $config           // Nextcloud configuration
            );

            return self::$cachedInstance;
        } catch (\Exception $e) {
            // Log error but don't break the application
            $logger = $container->get(LoggerInterface::class);
            $logger->warning('SolrServiceFactory: Failed to create GuzzleSolrService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return null;
        }
    }

    /**
     * Check if SOLR is enabled (cached for performance)
     *
     * @param IAppContainer $container Application container
     *
     * @return bool True if SOLR is enabled
     */
    private static function isSolrEnabled(IAppContainer $container): bool
    {
        if (self::$solrEnabled !== null) {
            return self::$solrEnabled;
        }

        try {
            $settingsService = $container->get(SettingsService::class);
            $solrSettings = $settingsService->getSolrSettings();
            self::$solrEnabled = $solrSettings['enabled'] ?? false;
            
            return self::$solrEnabled;
        } catch (\Exception $e) {
            self::$solrEnabled = false;
            return false;
        }
    }

    /**
     * Clear cached instances (for testing or configuration changes)
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cachedInstance = null;
        self::$solrEnabled = null;
    }

    /**
     * Get performance-optimized SOLR service for ObjectCacheService
     *
     * This method provides a safe way to get GuzzleSolrService without the
     * performance issues that occur with DI registration.
     *
     * @param IAppContainer $container Application container
     *
     * @return GuzzleSolrService|null GuzzleSolrService instance or null for graceful degradation
     */
    public static function getSolrServiceForCaching(IAppContainer $container): ?GuzzleSolrService
    {
        return self::createSolrService($container);
    }
}
