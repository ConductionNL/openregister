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

use OCA\OpenRegister\Db\ObjectEntityMapper;
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
     * Cached SolrService instance for current request
     *
     * @var SolrService|null
     */
    private static ?SolrService $cachedInstance = null;

    /**
     * Flag to track if SOLR is enabled (cached for performance)
     *
     * @var bool|null
     */
    private static ?bool $solrEnabled = null;

    /**
     * Create or retrieve SolrService instance
     *
     * This method provides high-performance access to SolrService without
     * the expensive DI registration overhead that causes Apache processes
     * to consume high CPU.
     *
     * @param IAppContainer $container Application container for service resolution
     *
     * @return SolrService|null SolrService instance or null if disabled/unavailable
     */
    public static function createSolrService(IAppContainer $container): ?SolrService
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
            $objectMapper = $container->get(ObjectEntityMapper::class);
            $config = $container->get(IConfig::class);

            // Create SolrService with correct parameter order
            self::$cachedInstance = new SolrService(
                $settingsService,  // First parameter: SettingsService
                $logger,           // Second parameter: LoggerInterface
                $objectMapper,     // Third parameter: ObjectEntityMapper
                $config           // Fourth parameter: IConfig
            );

            return self::$cachedInstance;
        } catch (\Exception $e) {
            // Log error but don't break the application
            $logger = $container->get(LoggerInterface::class);
            $logger->warning('SolrServiceFactory: Failed to create SolrService', [
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
     * This method provides a safe way to get SolrService without the
     * performance issues that occur with DI registration.
     *
     * @param IAppContainer $container Application container
     *
     * @return SolrService|null SolrService instance or null for graceful degradation
     */
    public static function getSolrServiceForCaching(IAppContainer $container): ?SolrService
    {
        return self::createSolrService($container);
    }
}
