<?php

declare(strict_types=1);

/**
 * CacheSettingsHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * NOTE: getCachedObjectStats() uses `static` local variables with 30s TTL.
 * This means getCacheStats() results for object data may be cached across tests
 * in the same process. Tests that need specific object stats values must account
 * for this.
 *
 * NOTE: clearDistributedCache() returns 'cleared' => 'all' (string), which causes
 * a TypeError when clearCache() tries to sum it in totalCleared. Tests for 'all'
 * and 'distributed' types expect this TypeError.
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\Settings\CacheSettingsHandler;
use OCP\AppFramework\IAppContainer;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

/**
 * Unit tests for CacheSettingsHandler
 *
 * Tests cache stats, clearing, warmup, and error handling.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)  Comprehensive coverage requires many test methods
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)   Full coverage of large handler class
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Test class must reference all dependencies
 */
class CacheSettingsHandlerTest extends TestCase
{
    /** @var CacheSettingsHandler */
    private CacheSettingsHandler $handler;

    /** @var ICacheFactory&MockObject */
    private ICacheFactory $cacheFactory;

    /** @var SchemaCacheHandler&MockObject */
    private SchemaCacheHandler $schemaCacheService;

    /** @var FacetCacheHandler&MockObject */
    private FacetCacheHandler $facetCacheService;

    /** @var CacheHandler&MockObject */
    private CacheHandler $cacheHandler;

    /** @var IAppContainer&MockObject */
    private IAppContainer $container;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheHandler::class);
        $this->facetCacheService = $this->createMock(FacetCacheHandler::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->container = $this->createMock(IAppContainer::class);

        $this->handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService,
            $this->cacheHandler,
            $this->container
        );
    }

    // =========================================================================
    // getCacheStats tests
    // =========================================================================

    /**
     * Test getCacheStats returns comprehensive stats structure
     *
     * @return void
     */
    public function testGetCacheStatsReturnsFullStructure(): void
    {
        $objectStats = [
            'entries'         => 100,
            'hits'            => 80,
            'requests'        => 100,
            'memoryUsage'     => 5000,
            'name_cache_size' => 50,
            'name_hit_rate'   => 90.0,
            'name_hits'       => 45,
            'name_misses'     => 5,
            'name_warmups'    => 2,
        ];

        $this->cacheHandler->method('getStats')
            ->willReturn($objectStats);

        $distributedCache = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')
            ->with('openregister')
            ->willReturn($distributedCache);

        $result = $this->handler->getCacheStats();

        $this->assertArrayHasKey('overview', $result);
        $this->assertArrayHasKey('services', $result);
        $this->assertArrayHasKey('names', $result);
        $this->assertArrayHasKey('distributed', $result);
        $this->assertArrayHasKey('performance', $result);
        $this->assertArrayHasKey('lastUpdated', $result);

        // Overview
        $this->assertSame(5000, $result['overview']['totalCacheSize']);
        $this->assertSame(100, $result['overview']['totalCacheEntries']);
        $this->assertSame(80.0, $result['overview']['overallHitRate']);

        // Services
        $this->assertSame(100, $result['services']['object']['entries']);
        $this->assertSame(80, $result['services']['object']['hits']);

        // Names
        $this->assertSame(50, $result['names']['cache_size']);
        $this->assertSame(90.0, $result['names']['hit_rate']);
        $this->assertSame(45, $result['names']['hits']);
        $this->assertTrue($result['names']['enabled']);

        // Distributed
        $this->assertSame('distributed', $result['distributed']['type']);
        $this->assertTrue($result['distributed']['available']);
    }

    /**
     * Test getCacheStats returns valid structure even with exception path
     *
     * @return void
     */
    public function testGetCacheStatsStructureOnException(): void
    {
        $handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService,
            null,
            null
        );

        $this->cacheFactory->method('createDistributed')
            ->willThrowException(new Exception('no cache'));

        $result = $handler->getCacheStats();

        $this->assertArrayHasKey('overview', $result);
        $this->assertArrayHasKey('totalCacheSize', $result['overview']);
        $this->assertArrayHasKey('totalCacheEntries', $result['overview']);
        $this->assertArrayHasKey('overallHitRate', $result['overview']);
    }

    /**
     * Test getCacheStats performance metrics are always present
     *
     * @return void
     */
    public function testGetCacheStatsPerformanceMetrics(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn(['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0]);

        $distributedCache = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')
            ->willReturn($distributedCache);

        $result = $this->handler->getCacheStats();

        $this->assertSame(2.5, $result['performance']['averageHitTime']);
        $this->assertSame(850.0, $result['performance']['averageMissTime']);
        $this->assertSame(340.0, $result['performance']['performanceGain']);
        $this->assertSame(85.0, $result['performance']['optimalHitRate']);
        $this->assertSame('improving', $result['performance']['currentTrend']);
    }

    /**
     * Test getCacheStats distributed cache stats when createDistributed throws
     *
     * @return void
     */
    public function testGetCacheStatsDistributedCacheFallback(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn(['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0]);

        $this->cacheFactory->method('createDistributed')
            ->willThrowException(new Exception('no distributed'));

        $result = $this->handler->getCacheStats();

        $this->assertSame('none', $result['distributed']['type']);
        $this->assertFalse($result['distributed']['available']);
        $this->assertSame('fallback', $result['distributed']['backend']);
        $this->assertSame('no distributed', $result['distributed']['error']);
    }

    /**
     * Test getCacheStats distributed cache success shows backend class
     *
     * @return void
     */
    public function testGetCacheStatsDistributedCacheSuccess(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn(['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0]);

        $distributedCache = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')
            ->willReturn($distributedCache);

        $result = $this->handler->getCacheStats();

        $this->assertSame('distributed', $result['distributed']['type']);
        $this->assertTrue($result['distributed']['available']);
        $this->assertIsString($result['distributed']['backend']);
        $this->assertSame('Unknown', $result['distributed']['keyCount']);
        $this->assertSame('Unknown', $result['distributed']['size']);
    }

    /**
     * Test getCacheStats services section always has schema and facet defaults
     *
     * @return void
     */
    public function testGetCacheStatsServicesSchemaAndFacetDefaults(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn(['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0]);

        $distributedCache = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')
            ->willReturn($distributedCache);

        $result = $this->handler->getCacheStats();

        $this->assertSame(0, $result['services']['schema']['entries']);
        $this->assertSame(0, $result['services']['schema']['hits']);
        $this->assertSame(0, $result['services']['schema']['requests']);
        $this->assertSame(0, $result['services']['schema']['memoryUsage']);

        $this->assertSame(0, $result['services']['facet']['entries']);
        $this->assertSame(0, $result['services']['facet']['hits']);
        $this->assertSame(0, $result['services']['facet']['requests']);
        $this->assertSame(0, $result['services']['facet']['memoryUsage']);
    }

    /**
     * Test getCacheStats lastUpdated is a valid ISO8601 timestamp
     *
     * @return void
     */
    public function testGetCacheStatsLastUpdated(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn(['entries' => 0, 'hits' => 0, 'requests' => 0, 'memoryUsage' => 0]);

        $distributedCache = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')
            ->willReturn($distributedCache);

        $result = $this->handler->getCacheStats();

        $this->assertArrayHasKey('lastUpdated', $result);
        $date = \DateTime::createFromFormat(\DateTime::ATOM, $result['lastUpdated']);
        $this->assertNotFalse($date);
    }

    /**
     * Test hit rate calculation with positive requests
     *
     * @return void
     */
    public function testHitRateWithPositiveRequests(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn(['entries' => 50, 'hits' => 25, 'requests' => 50, 'memoryUsage' => 0]);

        $distributedCache = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')
            ->willReturn($distributedCache);

        $result = $this->handler->getCacheStats();

        $this->assertIsFloat($result['overview']['overallHitRate']);
        $this->assertIsFloat($result['overview']['cacheEfficiency']);
    }

    // =========================================================================
    // clearCache tests
    // =========================================================================

    /**
     * Test clearCache with type 'all' triggers TypeError due to distributed 'cleared' => 'all' string
     *
     * This is a known bug in the source: clearDistributedCache returns 'cleared' => 'all'
     * (string) but totalCleared calculation tries to += it with int.
     *
     * @return void
     */
    public function testClearCacheAllTriggersTypeError(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn([
                'entries'         => 10,
                'name_cache_size' => 3,
                'name_hits'       => 2,
                'name_misses'     => 1,
            ]);
        $this->cacheHandler->method('clearCache');
        $this->cacheHandler->method('clearNameCache');

        $this->schemaCacheService->method('getCacheStatistics')
            ->willReturn(['entries' => 5, 'total_entries' => 5]);
        $this->schemaCacheService->method('clearAllCaches');

        $this->facetCacheService->method('getCacheStatistics')
            ->willReturn(['total_entries' => 3]);
        $this->facetCacheService->method('clearAllCaches');

        $distributedCache = $this->createMock(ICache::class);
        $distributedCache->method('clear');
        $this->cacheFactory->method('createDistributed')
            ->willReturn($distributedCache);

        // Known bug: 'cleared' => 'all' (string) causes TypeError in += operation
        $this->expectException(TypeError::class);

        $this->handler->clearCache('all');
    }

    /**
     * Test clearCache with type 'all' calls all clear methods before TypeError
     *
     * Verify all clear methods are called even though totalCleared fails
     *
     * @return void
     */
    public function testClearCacheAllCallsAllClearMethods(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn([
                'entries'         => 10,
                'name_cache_size' => 3,
                'name_hits'       => 2,
                'name_misses'     => 1,
            ]);
        $this->cacheHandler->expects($this->atLeastOnce())->method('clearCache');
        $this->cacheHandler->expects($this->atLeastOnce())->method('clearNameCache');

        $this->schemaCacheService->method('getCacheStatistics')
            ->willReturn(['entries' => 5, 'total_entries' => 5]);
        $this->schemaCacheService->expects($this->once())->method('clearAllCaches');

        $this->facetCacheService->method('getCacheStatistics')
            ->willReturn(['total_entries' => 3]);
        $this->facetCacheService->expects($this->once())->method('clearAllCaches');

        $distributedCache = $this->createMock(ICache::class);
        $distributedCache->expects($this->once())->method('clear');
        $this->cacheFactory->method('createDistributed')
            ->willReturn($distributedCache);

        try {
            $this->handler->clearCache('all');
        } catch (TypeError $e) {
            // Expected - all clear methods were still called (verified by expects)
            return;
        }

        $this->fail('Expected TypeError was not thrown');
    }

    /**
     * Test clearCache with type 'object'
     *
     * @return void
     */
    public function testClearCacheObject(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn(['entries' => 10]);
        $this->cacheHandler->method('clearCache');

        $result = $this->handler->clearCache('object');

        $this->assertSame('object', $result['type']);
        $this->assertArrayHasKey('object', $result['results']);
        $this->assertSame('object', $result['results']['object']['service']);
        $this->assertTrue($result['results']['object']['success']);
    }

    /**
     * Test clearCache with type 'schema'
     *
     * @return void
     */
    public function testClearCacheSchema(): void
    {
        $this->schemaCacheService->method('getCacheStatistics')
            ->willReturn(['entries' => 8, 'total_entries' => 8]);
        $this->schemaCacheService->expects($this->once())->method('clearAllCaches');

        $result = $this->handler->clearCache('schema');

        $this->assertSame('schema', $result['type']);
        $this->assertArrayHasKey('schema', $result['results']);
        $this->assertSame('schema', $result['results']['schema']['service']);
        $this->assertTrue($result['results']['schema']['success']);
    }

    /**
     * Test clearCache with type 'facet'
     *
     * @return void
     */
    public function testClearCacheFacet(): void
    {
        $this->facetCacheService->method('getCacheStatistics')
            ->willReturn(['total_entries' => 5]);
        $this->facetCacheService->expects($this->once())->method('clearAllCaches');

        $result = $this->handler->clearCache('facet');

        $this->assertSame('facet', $result['type']);
        $this->assertArrayHasKey('facet', $result['results']);
        $this->assertSame('facet', $result['results']['facet']['service']);
        $this->assertTrue($result['results']['facet']['success']);
    }

    /**
     * Test clearCache with type 'distributed' triggers TypeError in totalCleared
     *
     * @return void
     */
    public function testClearCacheDistributedTriggersTypeError(): void
    {
        $distributedCache = $this->createMock(ICache::class);
        $distributedCache->expects($this->once())->method('clear');
        $this->cacheFactory->method('createDistributed')
            ->willReturn($distributedCache);

        // Known bug: 'cleared' => 'all' causes TypeError
        $this->expectException(TypeError::class);

        $this->handler->clearCache('distributed');
    }

    /**
     * Test clearCache with type 'names'
     *
     * @return void
     */
    public function testClearCacheNames(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn([
                'name_cache_size' => 10,
                'name_hits'       => 5,
                'name_misses'     => 3,
            ]);
        $this->cacheHandler->expects($this->once())->method('clearNameCache');

        $result = $this->handler->clearCache('names');

        $this->assertSame('names', $result['type']);
        $this->assertArrayHasKey('names', $result['results']);
        $this->assertSame('names', $result['results']['names']['service']);
        $this->assertTrue($result['results']['names']['success']);
    }

    /**
     * Test clearCache with invalid type throws
     *
     * @return void
     */
    public function testClearCacheInvalidType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid cache type: bogus');

        $this->handler->clearCache('bogus');
    }

    /**
     * Test clearCache with userId parameter
     *
     * @return void
     */
    public function testClearCacheWithUserId(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn(['entries' => 5]);
        $this->cacheHandler->method('clearCache');

        $result = $this->handler->clearCache('object', 'user123');

        $this->assertSame('user123', $result['userId']);
    }

    /**
     * Test clearCache object when CacheHandler not available
     *
     * @return void
     */
    public function testClearCacheObjectNoCacheHandler(): void
    {
        $handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService,
            null,
            null
        );

        $result = $handler->clearCache('object');

        $this->assertFalse($result['results']['object']['success']);
        $this->assertSame(0, $result['results']['object']['cleared']);
        $this->assertSame('CacheHandler not available', $result['results']['object']['error']);
    }

    /**
     * Test clearCache names when CacheHandler not available
     *
     * @return void
     */
    public function testClearCacheNamesNoCacheHandler(): void
    {
        $handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService,
            null,
            null
        );

        $result = $handler->clearCache('names');

        $this->assertFalse($result['results']['names']['success']);
        $this->assertSame(0, $result['results']['names']['cleared']);
    }

    /**
     * Test clearCache schema when schemaCacheService throws
     *
     * @return void
     */
    public function testClearCacheSchemaOnException(): void
    {
        $this->schemaCacheService->method('getCacheStatistics')
            ->willThrowException(new Exception('schema error'));

        $result = $this->handler->clearCache('schema');

        $this->assertFalse($result['results']['schema']['success']);
        $this->assertSame(0, $result['results']['schema']['cleared']);
        $this->assertSame('schema error', $result['results']['schema']['error']);
    }

    /**
     * Test clearCache facet when facetCacheService throws
     *
     * @return void
     */
    public function testClearCacheFacetOnException(): void
    {
        $this->facetCacheService->method('getCacheStatistics')
            ->willThrowException(new Exception('facet error'));

        $result = $this->handler->clearCache('facet');

        $this->assertFalse($result['results']['facet']['success']);
        $this->assertSame(0, $result['results']['facet']['cleared']);
        $this->assertSame('facet error', $result['results']['facet']['error']);
    }

    /**
     * Test clearCache distributed when cacheFactory throws returns failure result
     *
     * @return void
     */
    public function testClearCacheDistributedOnException(): void
    {
        $this->cacheFactory->method('createDistributed')
            ->willThrowException(new Exception('distributed error'));

        // When distributed cache fails, cleared=0 (int), so totalCleared works
        $result = $this->handler->clearCache('distributed');

        $this->assertFalse($result['results']['distributed']['success']);
        $this->assertSame(0, $result['results']['distributed']['cleared']);
    }

    /**
     * Test clearCache totalCleared calculation for non-distributed types
     *
     * @return void
     */
    public function testClearCacheTotalClearedNonDistributed(): void
    {
        $callCount = 0;
        $this->cacheHandler->method('getStats')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return ['entries' => 10];
                }
                return ['entries' => 0];
            });
        $this->cacheHandler->method('clearCache');

        $result = $this->handler->clearCache('object');

        $this->assertSame(10, $result['totalCleared']);
    }

    /**
     * Test clearCache default (no args) triggers TypeError due to distributed
     *
     * @return void
     */
    public function testClearCacheDefaultTypeTriggersTypeError(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn(['entries' => 0, 'name_cache_size' => 0, 'name_hits' => 0, 'name_misses' => 0]);
        $this->cacheHandler->method('clearCache');
        $this->cacheHandler->method('clearNameCache');

        $this->schemaCacheService->method('getCacheStatistics')
            ->willReturn(['entries' => 0, 'total_entries' => 0]);
        $this->schemaCacheService->method('clearAllCaches');

        $this->facetCacheService->method('getCacheStatistics')
            ->willReturn(['total_entries' => 0]);
        $this->facetCacheService->method('clearAllCaches');

        $distributedCache = $this->createMock(ICache::class);
        $distributedCache->method('clear');
        $this->cacheFactory->method('createDistributed')
            ->willReturn($distributedCache);

        $this->expectException(TypeError::class);

        $this->handler->clearCache();
    }

    // =========================================================================
    // clearCache with container fallback for object cache
    // =========================================================================

    /**
     * Test clearObjectCache uses container fallback
     *
     * @return void
     */
    public function testClearObjectCacheContainerFallback(): void
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
            ->willReturn($this->cacheHandler);

        $this->cacheHandler->method('getStats')
            ->willReturn(['entries' => 5]);
        $this->cacheHandler->method('clearCache');

        $result = $handler->clearCache('object');

        $this->assertTrue($result['results']['object']['success']);
    }

    /**
     * Test clearObjectCache container resolution fails
     *
     * @return void
     */
    public function testClearObjectCacheContainerFails(): void
    {
        $handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService,
            null,
            $this->container
        );

        $this->container->method('get')
            ->willThrowException(new Exception('nope'));

        $result = $handler->clearCache('object');

        $this->assertFalse($result['results']['object']['success']);
    }

    // =========================================================================
    // warmupNamesCache tests
    // =========================================================================

    /**
     * Test warmupNamesCache success
     *
     * @return void
     */
    public function testWarmupNamesCacheSuccess(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn([
                'name_cache_size' => 0,
                'name_warmups'    => 0,
            ]);

        $this->cacheHandler->method('warmupNameCache')
            ->willReturn(25);

        $result = $this->handler->warmupNamesCache();

        $this->assertTrue($result['success']);
        $this->assertSame(25, $result['loaded_names']);
        $this->assertArrayHasKey('execution_time', $result);
        $this->assertStringContainsString('ms', $result['execution_time']);
        $this->assertArrayHasKey('before', $result);
        $this->assertArrayHasKey('after', $result);
        $this->assertArrayHasKey('name_cache_size', $result['before']);
        $this->assertArrayHasKey('name_warmups', $result['before']);
        $this->assertArrayHasKey('name_cache_size', $result['after']);
        $this->assertArrayHasKey('name_warmups', $result['after']);
    }

    /**
     * Test warmupNamesCache when CacheHandler not available
     *
     * @return void
     */
    public function testWarmupNamesCacheNoCacheHandler(): void
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
        $this->assertStringContainsString('Cache warmup failed', $result['error']);
    }

    /**
     * Test warmupNamesCache uses container fallback
     *
     * @return void
     */
    public function testWarmupNamesCacheContainerFallback(): void
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
            ->willReturn($this->cacheHandler);

        $this->cacheHandler->method('getStats')
            ->willReturn(['name_cache_size' => 0, 'name_warmups' => 0]);
        $this->cacheHandler->method('warmupNameCache')
            ->willReturn(10);

        $result = $handler->warmupNamesCache();

        $this->assertTrue($result['success']);
        $this->assertSame(10, $result['loaded_names']);
    }

    /**
     * Test warmupNamesCache when container resolution fails
     *
     * @return void
     */
    public function testWarmupNamesCacheContainerFails(): void
    {
        $handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService,
            null,
            $this->container
        );

        $this->container->method('get')
            ->willThrowException(new Exception('not found'));

        $result = $handler->warmupNamesCache();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['loaded_names']);
    }

    /**
     * Test warmupNamesCache when warmupNameCache throws
     *
     * @return void
     */
    public function testWarmupNamesCacheWarmupThrows(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn(['name_cache_size' => 0, 'name_warmups' => 0]);
        $this->cacheHandler->method('warmupNameCache')
            ->willThrowException(new Exception('warmup failed'));

        $result = $this->handler->warmupNamesCache();

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('warmup failed', $result['error']);
    }

    // =========================================================================
    // clearSchemaCache tests (private, tested via clearCache)
    // =========================================================================

    /**
     * Test clearSchemaCache calculates cleared count correctly
     *
     * @return void
     */
    public function testClearSchemaCacheClearedCount(): void
    {
        $callCount = 0;
        $this->schemaCacheService->method('getCacheStatistics')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return ['entries' => 15, 'total_entries' => 15];
                }
                return ['entries' => 0, 'total_entries' => 0];
            });
        $this->schemaCacheService->method('clearAllCaches');

        $result = $this->handler->clearCache('schema');

        $this->assertSame(15, $result['results']['schema']['cleared']);
        $this->assertArrayHasKey('before', $result['results']['schema']);
        $this->assertArrayHasKey('after', $result['results']['schema']);
    }

    /**
     * Test clearSchemaCache when stats have no entries key
     *
     * @return void
     */
    public function testClearSchemaCacheNoEntriesKey(): void
    {
        $this->schemaCacheService->method('getCacheStatistics')
            ->willReturn(['total_entries' => 5]);
        $this->schemaCacheService->method('clearAllCaches');

        $result = $this->handler->clearCache('schema');

        $this->assertSame(0, $result['results']['schema']['cleared']);
    }

    // =========================================================================
    // clearFacetCache tests (private, tested via clearCache)
    // =========================================================================

    /**
     * Test clearFacetCache calculates cleared count from total_entries
     *
     * @return void
     */
    public function testClearFacetCacheClearedCount(): void
    {
        $callCount = 0;
        $this->facetCacheService->method('getCacheStatistics')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return ['total_entries' => 20];
                }
                return ['total_entries' => 0];
            });
        $this->facetCacheService->method('clearAllCaches');

        $result = $this->handler->clearCache('facet');

        $this->assertSame(20, $result['results']['facet']['cleared']);
    }

    // =========================================================================
    // clearNamesCache tests (private, tested via clearCache)
    // =========================================================================

    /**
     * Test clearNamesCache returns before/after stats
     *
     * @return void
     */
    public function testClearNamesCacheBeforeAfterStats(): void
    {
        $callCount = 0;
        $this->cacheHandler->method('getStats')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return [
                        'name_cache_size' => 20,
                        'name_hits'       => 15,
                        'name_misses'     => 5,
                    ];
                }
                return [
                    'name_cache_size' => 0,
                    'name_hits'       => 0,
                    'name_misses'     => 0,
                ];
            });
        $this->cacheHandler->method('clearNameCache');

        $result = $this->handler->clearCache('names');

        $this->assertSame(20, $result['results']['names']['cleared']);
        $this->assertSame(20, $result['results']['names']['before']['name_cache_size']);
        $this->assertSame(0, $result['results']['names']['after']['name_cache_size']);
    }

    // =========================================================================
    // Constructor test
    // =========================================================================

    /**
     * Test constructor with minimal parameters
     *
     * @return void
     */
    public function testConstructorMinimalParams(): void
    {
        $handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService
        );

        $this->assertInstanceOf(CacheSettingsHandler::class, $handler);
    }

    // =========================================================================
    // Edge case: clearNamesCache container fallback
    // =========================================================================

    /**
     * Test clearNamesCache uses container fallback
     *
     * @return void
     */
    public function testClearNamesCacheContainerFallback(): void
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
            ->willReturn($this->cacheHandler);

        $this->cacheHandler->method('getStats')
            ->willReturn(['name_cache_size' => 3, 'name_hits' => 2, 'name_misses' => 1]);
        $this->cacheHandler->method('clearNameCache');

        $result = $handler->clearCache('names');

        $this->assertTrue($result['results']['names']['success']);
    }

    /**
     * Test clearNamesCache container resolution fails
     *
     * @return void
     */
    public function testClearNamesCacheContainerFails(): void
    {
        $handler = new CacheSettingsHandler(
            $this->cacheFactory,
            $this->schemaCacheService,
            $this->facetCacheService,
            null,
            $this->container
        );

        $this->container->method('get')
            ->willThrowException(new Exception('unavailable'));

        $result = $handler->clearCache('names');

        $this->assertFalse($result['results']['names']['success']);
    }

    /**
     * Test clearCache timestamp is ISO8601 format
     *
     * @return void
     */
    public function testClearCacheTimestampFormat(): void
    {
        $this->schemaCacheService->method('getCacheStatistics')
            ->willReturn(['entries' => 0, 'total_entries' => 0]);
        $this->schemaCacheService->method('clearAllCaches');

        $result = $this->handler->clearCache('schema');

        $date = \DateTime::createFromFormat(\DateTime::ATOM, $result['timestamp']);
        $this->assertNotFalse($date);
    }

    /**
     * Test clearCache errors array is always empty on success
     *
     * @return void
     */
    public function testClearCacheErrorsArrayEmpty(): void
    {
        $this->cacheHandler->method('getStats')
            ->willReturn(['entries' => 0]);
        $this->cacheHandler->method('clearCache');

        $result = $this->handler->clearCache('object');

        $this->assertSame([], $result['errors']);
    }
}
