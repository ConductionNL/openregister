<?php

declare(strict_types=1);

/**
 * OpenRegister Cache Invalidation Service
 *
 * **CRITICAL SYSTEM COMPONENT**: Handles cache invalidation across all caching layers
 * when objects are created, updated, or deleted. Ensures data consistency and prevents
 * stale cache issues that cause new items to not appear immediately.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git-id>
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCP\ICacheFactory;
use OCP\IMemcache;
use Psr\Log\LoggerInterface;

/**
 * Cache Invalidation Service
 *
 * **ARCHITECTURAL SOLUTION**: Centralizes all cache invalidation logic to ensure
 * data consistency across the entire caching system. Prevents the critical issue
 * where new objects don't appear in collection calls due to stale cache.
 *
 * **INVALIDATION STRATEGY**:
 * - Pattern-based cache clearing for collections
 * - User-aware invalidation for RBAC compliance  
 * - Register/schema-specific invalidation for performance
 * - Fallback to global clearing if pattern matching fails
 */
class CacheInvalidationService
{
    
    /** @var IMemcache|null Search response cache */
    private ?IMemcache $searchCache = null;
    
    /** @var IMemcache|null Facet response cache */
    private ?IMemcache $facetCache = null;
    
    /** @var IMemcache|null Entity cache for schemas/registers */
    private ?IMemcache $entityCache = null;


    /**
     * Constructor for CacheInvalidationService
     *
     * @param ICacheFactory             $cacheFactory             Cache factory for accessing distributed caches
     * @param SchemaCacheService        $schemaCacheService       Schema cache service for schema entity invalidation  
     * @param SchemaFacetCacheService   $schemaFacetCacheService  Schema facet cache service for facet invalidation
     * @param LoggerInterface           $logger                   Logger for debugging and monitoring
     */
    public function __construct(
        private readonly ICacheFactory $cacheFactory,
        private readonly SchemaCacheService $schemaCacheService,
        private readonly SchemaFacetCacheService $schemaFacetCacheService,
        private readonly LoggerInterface $logger
    ) {
        // Initialize cache connections
        try {
            $this->searchCache = $this->cacheFactory->createDistributed('openregister_search');
            $this->facetCache = $this->cacheFactory->createDistributed('openregister_facets');
            $this->entityCache = $this->cacheFactory->createDistributed('openregister_entities');
        } catch (\Exception $e) {
            // Try local cache as fallback
            try {
                $this->searchCache = $this->cacheFactory->createLocal('openregister_search');
                $this->facetCache = $this->cacheFactory->createLocal('openregister_facets');
                $this->entityCache = $this->cacheFactory->createLocal('openregister_entities');
            } catch (\Exception $e) {
                $this->logger->warning('Cache invalidation service initialized without cache access', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }


    /**
     * Invalidate All Caches for Object CRUD Operations
     *
     * **MAIN ENTRY POINT**: Called when objects are created, updated, or deleted.
     * Ensures that collection calls immediately reflect the changes.
     *
     * @param ObjectEntity|null $object     The object that was modified (null for bulk operations)
     * @param string           $operation  The operation performed (create/update/delete)
     * @param int|null         $registerId Register ID for targeted invalidation
     * @param int|null         $schemaId   Schema ID for targeted invalidation
     * 
     * @return void
     */
    public function invalidateObjectCaches(
        ?ObjectEntity $object = null, 
        string $operation = 'unknown',
        ?int $registerId = null,
        ?int $schemaId = null
    ): void {
        $startTime = microtime(true);
        
        // Extract context from object if provided
        if ($object !== null) {
            $registerId = $registerId ?? $object->getRegister();
            $schemaId = $schemaId ?? $object->getSchema();
        }
        
        $invalidatedCaches = [];
        
        // **COLLECTION CACHE INVALIDATION**: Clear search result caches
        $searchInvalidated = $this->invalidateSearchCaches($registerId, $schemaId);
        if ($searchInvalidated > 0) {
            $invalidatedCaches['search'] = $searchInvalidated;
        }
        
        // **FACET CACHE INVALIDATION**: Clear facet calculation caches
        $facetInvalidated = $this->invalidateFacetCaches($registerId, $schemaId);
        if ($facetInvalidated > 0) {
            $invalidatedCaches['facets'] = $facetInvalidated;
        }
        
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        $this->logger->info('Cache invalidation completed for object CRUD operation', [
            'operation' => $operation,
            'registerId' => $registerId,
            'schemaId' => $schemaId,
            'objectId' => $object?->getId(),
            'executionTime' => $executionTime . 'ms',
            'invalidatedCaches' => $invalidatedCaches,
            'totalInvalidated' => array_sum($invalidatedCaches)
        ]);
        
    }//end invalidateObjectCaches()


    /**
     * Invalidate Search Response Caches
     *
     * **COLLECTION CACHE CLEARING**: Removes cached search results that would
     * prevent new/updated objects from appearing in collection calls.
     *
     * @param int|null $registerId Register ID for targeted invalidation
     * @param int|null $schemaId   Schema ID for targeted invalidation
     * 
     * @return int Number of cache entries invalidated
     */
    private function invalidateSearchCaches(?int $registerId = null, ?int $schemaId = null): int
    {
        if ($this->searchCache === null) {
            return 0;
        }
        
        $invalidated = 0;
        
        try {
            // **PATTERN-BASED INVALIDATION**: Clear caches containing register/schema
            // Since we can't list cache keys in distributed cache, we'll clear by patterns
            
            if ($registerId && $schemaId) {
                // Clear specific register+schema combination caches
                $patterns = [
                    'obj_search_', // All search caches (contains register+schema in hash)
                    'facet_rbac_'  // All RBAC facet caches
                ];
                
                foreach ($patterns as $pattern) {
                    // Note: In production, this would need a more sophisticated approach
                    // like storing cache key patterns or using tagged caching
                    $this->searchCache->clear(); // For now, clear all to ensure consistency
                    $invalidated++;
                    break; // Only count once
                }
            } else {
                // Clear all search caches for safety
                $this->searchCache->clear();
                $invalidated = 1;
            }
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to invalidate search caches', [
                'error' => $e->getMessage(),
                'registerId' => $registerId,
                'schemaId' => $schemaId
            ]);
        }
        
        return $invalidated;
        
    }//end invalidateSearchCaches()


    /**
     * Invalidate Facet Response Caches
     *
     * **FACET CACHE CLEARING**: Removes cached facet calculations that would
     * show incorrect facet counts after object changes.
     *
     * @param int|null $registerId Register ID for targeted invalidation
     * @param int|null $schemaId   Schema ID for targeted invalidation
     * 
     * @return int Number of cache entries invalidated
     */
    private function invalidateFacetCaches(?int $registerId = null, ?int $schemaId = null): int
    {
        if ($this->facetCache === null) {
            return 0;
        }
        
        $invalidated = 0;
        
        try {
            // Clear all facet caches since they depend on object counts
            $this->facetCache->clear();
            $invalidated = 1;
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to invalidate facet caches', [
                'error' => $e->getMessage(),
                'registerId' => $registerId,
                'schemaId' => $schemaId
            ]);
        }
        
        return $invalidated;
        
    }//end invalidateFacetCaches()


    /**
     * Invalidate Schema/Register Entity Caches
     *
     * **METADATA CACHE CLEARING**: Called when schemas or registers are updated.
     * This is separate from object operations since entity changes affect
     * different cache layers.
     *
     * @param string $entityType Type of entity (schema/register)
     * @param int    $entityId   ID of the entity that changed
     * 
     * @return void
     */
    public function invalidateEntityCaches(string $entityType, int $entityId): void
    {
        if ($this->entityCache === null) {
            return;
        }
        
        try {
            // Clear specific entity from cache
            $cacheKey = "entity_{$entityType}_{$entityId}";
            $this->entityCache->remove($cacheKey);
            
            // Also clear bulk entity caches that might contain this entity
            $bulkKeys = [
                "entity_{$entityType}_all",
                "entity_{$entityType}_multiple"
            ];
            
            foreach ($bulkKeys as $key) {
                $this->entityCache->remove($key);
            }
            
            $this->logger->debug('Entity cache invalidated', [
                'entityType' => $entityType,
                'entityId' => $entityId,
                'specificKey' => $cacheKey,
                'bulkKeysCleared' => count($bulkKeys)
            ]);
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to invalidate entity caches', [
                'error' => $e->getMessage(),
                'entityType' => $entityType,
                'entityId' => $entityId
            ]);
        }
        
    }//end invalidateEntityCaches()


    /**
     * Clear All Caches (Administrative Operation)
     *
     * **NUCLEAR OPTION**: Clears all OpenRegister caches. Use sparingly,
     * typically for administrative operations or major system changes.
     * 
     * @return array Statistics about cleared caches
     */
    public function clearAllCaches(): array
    {
        $startTime = microtime(true);
        $cleared = [];
        
        $caches = [
            'search' => $this->searchCache,
            'facets' => $this->facetCache,
            'entities' => $this->entityCache
        ];
        
        foreach ($caches as $name => $cache) {
            if ($cache !== null) {
                try {
                    $cache->clear();
                    $cleared[$name] = true;
                } catch (\Exception $e) {
                    $cleared[$name] = false;
                    $this->logger->error("Failed to clear {$name} cache", [
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                $cleared[$name] = false;
            }
        }
        
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        $this->logger->info('All caches cleared', [
            'executionTime' => $executionTime . 'ms',
            'clearedCaches' => $cleared,
            'successCount' => count(array_filter($cleared))
        ]);
        
        return [
            'cleared' => $cleared,
            'executionTime' => $executionTime,
            'timestamp' => time()
        ];
        
    }//end clearAllCaches()


    /**
     * Invalidate All Schema-Related Caches
     *
     * **SCHEMA CACHE COORDINATION**: Called when schemas are created, updated, or deleted.
     * Coordinates invalidation across multiple cache layers that depend on schema structure.
     *
     * **AFFECTED CACHES**:
     * - Schema entity cache (entity data)
     * - Schema facet cache (pre-computed facets)  
     * - Collection caches (depend on schema validation)
     * - Facet response cache (facet calculations depend on schema structure)
     *
     * @param Schema|int $schema    The schema entity or schema ID that changed
     * @param string     $operation The operation performed (create/update/delete)
     * 
     * @return void
     */
    public function invalidateSchemaRelatedCaches(Schema|int $schema, string $operation = 'update'): void
    {
        $startTime = microtime(true);
        
        // Extract schema ID
        $schemaId = $schema instanceof Schema ? $schema->getId() : $schema;
        
        $invalidatedCaches = [];
        
        try {
            // **SCHEMA ENTITY CACHE**: Clear schema-specific entity caches
            $this->invalidateEntityCaches('schema', $schemaId);
            $invalidatedCaches['entity'] = 'schema_' . $schemaId;
            
            // **SCHEMA CACHE SERVICE**: Clear schema configuration caches
            $this->schemaCacheService->invalidateSchema($schemaId);
            $invalidatedCaches['schema_config'] = 'schema_' . $schemaId;
            
            // **SCHEMA FACET CACHE**: Clear pre-computed facet configurations
            $this->schemaFacetCacheService->invalidateSchemaFacets($schemaId);
            $invalidatedCaches['schema_facets'] = 'schema_' . $schemaId;
            
            // **COLLECTION CACHE IMPACT**: Clear related collection caches since schema structure affects validation/queries
            $collectionInvalidated = $this->invalidateSchemaRelatedCollections($schemaId);
            if ($collectionInvalidated > 0) {
                $invalidatedCaches['collections'] = $collectionInvalidated;
            }
            
            // **FACET RESPONSE CACHE**: Clear facet calculations since facets depend on schema structure
            $facetInvalidated = $this->invalidateFacetCaches(null, $schemaId);
            if ($facetInvalidated > 0) {
                $invalidatedCaches['facet_responses'] = $facetInvalidated;
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate schema-related caches', [
                'error' => $e->getMessage(),
                'schemaId' => $schemaId,
                'operation' => $operation
            ]);
        }
        
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        $this->logger->info('Schema cache invalidation completed', [
            'schemaId' => $schemaId,
            'operation' => $operation,
            'executionTime' => $executionTime . 'ms',
            'invalidatedCaches' => $invalidatedCaches,
            'totalInvalidated' => count($invalidatedCaches)
        ]);
        
    }//end invalidateSchemaRelatedCaches()


    /**
     * Invalidate Collection Caches Related to Schema Changes
     *
     * **SCHEMA-COLLECTION DEPENDENCY**: When schemas change, collections that use
     * those schemas for validation/structure need cache invalidation.
     *
     * @param int $schemaId Schema ID that changed
     * 
     * @return int Number of cache entries invalidated
     */
    private function invalidateSchemaRelatedCollections(int $schemaId): int
    {
        if ($this->searchCache === null) {
            return 0;
        }
        
        $invalidated = 0;
        
        try {
            // For now, clear all search caches since we can't efficiently target schema-specific collections
            // In a more sophisticated implementation, we could:
            // 1. Store reverse mappings of schema->collection caches
            // 2. Use tagged caching with schema IDs as tags
            // 3. Implement selective invalidation patterns
            
            $this->searchCache->clear();
            $invalidated = 1;
            
            $this->logger->debug('Schema-related collection caches cleared', [
                'schemaId' => $schemaId,
                'strategy' => 'global_clear'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to invalidate schema-related collection caches', [
                'error' => $e->getMessage(),
                'schemaId' => $schemaId
            ]);
        }
        
        return $invalidated;
        
    }//end invalidateSchemaRelatedCollections()


}//end class
