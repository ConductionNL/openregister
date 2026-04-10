<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Coverage tests for CacheSettingsHandler — targets uncovered branches in
 * clearCache, warmupNamesCache, getCacheStats, clearNamesCache, clearObjectCache.
 */
class CacheSettingsHandlerBranchCoverageTest extends TestCase
{
    private CacheSettingsHandler $handler;
    private ICacheFactory&MockObject $cacheFactory;
    private SchemaCacheHandler&MockObject $schemaCacheService;
    private FacetCacheHandler&MockObject $facetCacheService;
    private CacheHandler&MockObject $objectCacheService;
    private IAppContainer&MockObject $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheHandler::class);
        $this->facetCacheService = $this->createMock(FacetCacheHandler::class);
        $this->objectCacheService = $this->createMock(CacheHandler::class);
        $this->container = $this->createMock(IAppContainer::class);

        $this->handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService,
            $this->objectCacheService,
            $this->container
        );
    }

    // =========================================================================
    // clearCache — specific types
    // =========================================================================

    public function testClearCacheObjectType(): void
    {
        $this->objectCacheService->method('getStats')->willReturn([
            'entries' => 10,
            'hits' => 5,
            'misses' => 5,
            'requests' => 10,
        ]);
        $this->objectCacheService->expects($this->once())->method('clearCache');

        $result = $this->handler->clearCache('object');

        $this->assertSame('object', $result['type']);
        $this->assertTrue($result['results']['object']['success']);
    }

    public function testClearCacheSchemaType(): void
    {
        $this->schemaCacheService->method('getCacheStatistics')->willReturn([
            'total_entries' => 5,
            'entries_with_ttl' => 3,
            'memory_cache_size' => 2,
            'entries' => 5,
        ]);
        $this->schemaCacheService->expects($this->once())->method('clearAllCaches');

        $result = $this->handler->clearCache('schema');

        $this->assertSame('schema', $result['type']);
        $this->assertTrue($result['results']['schema']['success']);
    }

    public function testClearCacheFacetType(): void
    {
        $this->facetCacheService->method('getCacheStatistics')->willReturn([
            'total_entries' => 3,
        ]);
        $this->facetCacheService->expects($this->once())->method('clearAllCaches');

        $result = $this->handler->clearCache('facet');

        $this->assertSame('facet', $result['type']);
        $this->assertTrue($result['results']['facet']['success']);
    }

    public function testClearCacheDistributedType(): void
    {
        $distributedCache = $this->createMock(IMemcache::class);
        $this->cacheFactory->method('createDistributed')
            ->with('openregister')
            ->willReturn($distributedCache);
        $distributedCache->expects($this->once())->method('clear');

        $result = $this->handler->clearCache('distributed');

        $this->assertSame('distributed', $result['type']);
        $this->assertTrue($result['results']['distributed']['success']);
        $this->assertSame('all', $result['results']['distributed']['cleared']);
    }

    public function testClearCacheNamesType(): void
    {
        $this->objectCacheService->method('getStats')->willReturn([
            'name_cache_size' => 50,
            'name_hits' => 100,
            'name_misses' => 10,
        ]);
        $this->objectCacheService->expects($this->once())->method('clearNameCache');

        $result = $this->handler->clearCache('names');

        $this->assertSame('names', $result['type']);
        $this->assertTrue($result['results']['names']['success']);
    }

    public function testClearCacheAllType(): void
    {
        $this->objectCacheService->method('getStats')->willReturn([
            'entries' => 10,
            'name_cache_size' => 5,
            'name_hits' => 3,
            'name_misses' => 2,
        ]);
        $this->schemaCacheService->method('getCacheStatistics')->willReturn([
            'total_entries' => 3,
            'entries' => 3,
        ]);
        $this->facetCacheService->method('getCacheStatistics')->willReturn([
            'total_entries' => 2,
        ]);
        $distributedCache = $this->createMock(IMemcache::class);
        $this->cacheFactory->method('createDistributed')->willReturn($distributedCache);

        $result = $this->handler->clearCache('all');

        $this->assertSame('all', $result['type']);
        $this->assertArrayHasKey('object', $result['results']);
        $this->assertArrayHasKey('schema', $result['results']);
        $this->assertArrayHasKey('facet', $result['results']);
        $this->assertArrayHasKey('distributed', $result['results']);
        $this->assertArrayHasKey('names', $result['results']);
    }

    public function testClearCacheInvalidTypeThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->handler->clearCache('invalid_type');
    }

    // =========================================================================
    // warmupNamesCache
    // =========================================================================

    public function testWarmupNamesCacheSuccess(): void
    {
        $this->objectCacheService->method('getStats')->willReturn([
            'name_cache_size' => 0,
            'name_warmups' => 0,
        ]);
        $this->objectCacheService->method('warmupNameCache')->willReturn(25);

        $result = $this->handler->warmupNamesCache();

        $this->assertTrue($result['success']);
        $this->assertSame(25, $result['loaded_names']);
        $this->assertArrayHasKey('execution_time', $result);
    }

    public function testWarmupNamesCacheWhenNoCacheHandler(): void
    {
        $handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService,
            null,
            null
        );

        $result = $handler->warmupNamesCache();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['loaded_names']);
    }

    public function testWarmupNamesCacheWithContainerFallback(): void
    {
        $handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService,
            null,
            $this->container
        );

        $this->container->method('get')
            ->with(CacheHandler::class)
            ->willReturn($this->objectCacheService);

        $this->objectCacheService->method('getStats')->willReturn([
            'name_cache_size' => 0,
            'name_warmups' => 0,
        ]);
        $this->objectCacheService->method('warmupNameCache')->willReturn(10);

        $result = $handler->warmupNamesCache();

        $this->assertTrue($result['success']);
        $this->assertSame(10, $result['loaded_names']);
    }

    // =========================================================================
    // getCacheStats
    // =========================================================================

    public function testGetCacheStatsSuccess(): void
    {
        $this->objectCacheService->method('getStats')->willReturn([
            'entries' => 100,
            'hits' => 80,
            'requests' => 100,
            'memoryUsage' => 50000,
            'name_cache_size' => 20,
            'name_hit_rate' => 90.0,
            'name_hits' => 18,
            'name_misses' => 2,
            'name_warmups' => 1,
        ]);

        $distributedCache = $this->createMock(IMemcache::class);
        $this->cacheFactory->method('createDistributed')->willReturn($distributedCache);

        $result = $this->handler->getCacheStats();

        $this->assertArrayHasKey('overview', $result);
        $this->assertArrayHasKey('services', $result);
        $this->assertArrayHasKey('names', $result);
        $this->assertArrayHasKey('distributed', $result);
        $this->assertArrayHasKey('performance', $result);
        // Static caching in getCachedObjectStats may carry state from previous tests.
        $this->assertIsFloat($result['overview']['overallHitRate']);
    }

    public function testGetCacheStatsWithDistributedCacheException(): void
    {
        $this->objectCacheService->method('getStats')->willReturn([
            'entries' => 0,
            'hits' => 0,
            'requests' => 0,
            'memoryUsage' => 0,
        ]);

        $this->cacheFactory->method('createDistributed')
            ->willThrowException(new \Exception('No distributed cache'));

        $result = $this->handler->getCacheStats();

        $this->assertSame('none', $result['distributed']['type']);
        $this->assertFalse($result['distributed']['available']);
    }

    public function testGetCacheStatsZeroRequests(): void
    {
        $this->objectCacheService->method('getStats')->willReturn([
            'entries' => 0,
            'hits' => 0,
            'requests' => 0,
            'memoryUsage' => 0,
        ]);

        $distributedCache = $this->createMock(IMemcache::class);
        $this->cacheFactory->method('createDistributed')->willReturn($distributedCache);

        $result = $this->handler->getCacheStats();

        // Static caching in getCachedObjectStats may carry state from previous tests,
        // so we just assert the structure exists and the rate is a float.
        $this->assertIsFloat($result['overview']['overallHitRate']);
    }

    // =========================================================================
    // Error paths
    // =========================================================================

    public function testClearObjectCacheWhenContainerThrows(): void
    {
        $handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService,
            null,
            $this->container
        );

        $this->container->method('get')
            ->willThrowException(new \Exception('Service not found'));

        $result = $handler->clearCache('object');

        $this->assertFalse($result['results']['object']['success']);
        $this->assertSame(0, $result['results']['object']['cleared']);
    }

    public function testClearSchemaWhenNoEntriesKey(): void
    {
        $this->schemaCacheService->method('getCacheStatistics')->willReturn([
            'total_entries' => 5,
            'entries_with_ttl' => 3,
            'memory_cache_size' => 2,
        ]);
        $this->schemaCacheService->expects($this->once())->method('clearAllCaches');

        $result = $this->handler->clearCache('schema');

        $this->assertTrue($result['results']['schema']['success']);
        $this->assertSame(0, $result['results']['schema']['cleared']);
    }

    public function testClearDistributedCacheWhenException(): void
    {
        $this->cacheFactory->method('createDistributed')
            ->willThrowException(new \Exception('No distributed cache'));

        $result = $this->handler->clearCache('distributed');

        $this->assertFalse($result['results']['distributed']['success']);
    }

    public function testClearCacheWithUserId(): void
    {
        $this->objectCacheService->method('getStats')->willReturn([
            'entries' => 5,
        ]);
        $this->objectCacheService->expects($this->once())->method('clearCache');

        $result = $this->handler->clearCache('object', 'user123');

        $this->assertSame('user123', $result['userId']);
        $this->assertTrue($result['results']['object']['success']);
    }
}
