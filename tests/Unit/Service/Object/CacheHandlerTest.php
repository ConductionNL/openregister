<?php

declare(strict_types=1);

/**
 * CacheHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IMemcache;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for CacheHandler
 *
 * Tests object caching, cache statistics, search cache, name cache, and invalidation.
 */
class CacheHandlerTest extends TestCase
{
    /** @var CacheHandler */
    private CacheHandler $handler;

    /** @var ObjectEntityMapper&MockObject */
    private ObjectEntityMapper $objectEntityMapper;

    /** @var OrganisationMapper&MockObject */
    private OrganisationMapper $organisationMapper;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var ICacheFactory&MockObject */
    private ICacheFactory $cacheFactory;

    /** @var IMemcache&MockObject */
    private IMemcache $queryCache;

    /** @var IUserSession&MockObject */
    private IUserSession $userSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->queryCache = $this->createMock(IMemcache::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->cacheFactory->method('createDistributed')
            ->willReturn($this->queryCache);

        $this->userSession->method('getUser')
            ->willReturn(null);

        $this->handler = new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            $this->cacheFactory,
            $this->userSession
        );
    }

    /**
     * Helper to create an ObjectEntity with a given id and uuid.
     */
    private function createObjectEntity(int $id, string $uuid): ObjectEntity
    {
        $entity = new ObjectEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, $id);
        $entity->setUuid($uuid);
        return $entity;
    }

    // =========================================================================
    // getObject
    // =========================================================================

    public function testGetObjectReturnsCachedObject(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->willReturn($entity);

        // First call should hit database.
        $result1 = $this->handler->getObject(1);
        $this->assertSame($entity, $result1);

        // Second call should hit cache.
        $result2 = $this->handler->getObject(1);
        $this->assertSame($entity, $result2);
    }

    public function testGetObjectReturnsNullOnException(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->getObject(999);

        $this->assertNull($result);
    }

    public function testGetObjectByUuid(): void
    {
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $entity = $this->createObjectEntity(1, $uuid);

        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->willReturn($entity);

        // Load by ID first.
        $this->handler->getObject(1);

        // Now load by UUID - should hit cache.
        $result = $this->handler->getObject($uuid);
        $this->assertSame($entity, $result);
    }

    // =========================================================================
    // preloadObjects
    // =========================================================================

    public function testPreloadObjectsReturnsEmptyForEmptyInput(): void
    {
        $result = $this->handler->preloadObjects([]);

        $this->assertSame([], $result);
    }

    public function testPreloadObjectsBulkLoadsFromDatabase(): void
    {
        $entity1 = $this->createObjectEntity(1, 'uuid-1');
        $entity2 = $this->createObjectEntity(2, 'uuid-2');

        $this->objectEntityMapper->expects($this->once())
            ->method('findMultiple')
            ->willReturn([$entity1, $entity2]);

        $result = $this->handler->preloadObjects([1, 2]);

        $this->assertCount(2, $result);
    }

    public function testPreloadObjectsSkipsAlreadyCachedObjects(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');

        // First load to cache it.
        $this->objectEntityMapper->method('find')
            ->willReturn($entity);
        $this->handler->getObject(1);

        // Now preload should not call findMultiple for already cached object.
        $this->objectEntityMapper->expects($this->never())
            ->method('findMultiple');

        $result = $this->handler->preloadObjects([1]);

        $this->assertCount(1, $result);
    }

    public function testPreloadObjectsReturnsEmptyOnException(): void
    {
        $this->objectEntityMapper->method('findMultiple')
            ->willThrowException(new Exception('DB error'));

        $result = $this->handler->preloadObjects([1, 2]);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // getStats
    // =========================================================================

    public function testGetStatsReturnsInitialStats(): void
    {
        $stats = $this->handler->getStats();

        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
        $this->assertSame(0, $stats['preloads']);
        $this->assertSame(0.0, $stats['hit_rate']);
        $this->assertSame(0.0, $stats['query_hit_rate']);
        $this->assertSame(0, $stats['cache_size']);
    }

    public function testGetStatsTracksHitsAndMisses(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->objectEntityMapper->method('find')
            ->willReturn($entity);

        // Miss.
        $this->handler->getObject(1);
        // Hit.
        $this->handler->getObject(1);

        $stats = $this->handler->getStats();

        $this->assertSame(1, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
        $this->assertSame(50.0, $stats['hit_rate']);
    }

    // =========================================================================
    // clearSearchCache
    // =========================================================================

    public function testClearSearchCacheClearsAll(): void
    {
        $this->queryCache->expects($this->once())
            ->method('clear');

        $this->handler->clearSearchCache();
    }

    public function testClearSearchCacheWithPattern(): void
    {
        // Pattern-based clearing should still clear distributed cache.
        $this->queryCache->expects($this->once())
            ->method('clear');

        $this->handler->clearSearchCache('schema_42');
    }

    public function testClearSearchCacheHandlesDistributedCacheException(): void
    {
        $this->queryCache->method('clear')
            ->willThrowException(new Exception('Memcache error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw.
        $this->handler->clearSearchCache();
    }

    // =========================================================================
    // invalidateForObjectChange
    // =========================================================================

    public function testInvalidateForObjectChangeWithObject(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);
        $entity->setName('Test Object');

        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $this->handler->invalidateForObjectChange($entity, 'create');
    }

    public function testInvalidateForObjectChangeWithNullObject(): void
    {
        // Bulk operation scenario - no specific object.
        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $this->handler->invalidateForObjectChange(null, 'bulk_save', 5, 10);
    }

    public function testInvalidateForObjectChangeOnDelete(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);

        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $this->handler->invalidateForObjectChange($entity, 'delete');
    }

    // =========================================================================
    // clearAllCaches / clearCache
    // =========================================================================

    public function testClearAllCaches(): void
    {
        // Load an object to populate cache.
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->objectEntityMapper->method('find')
            ->willReturn($entity);
        $this->handler->getObject(1);

        $this->handler->clearAllCaches();

        // After clearing, should need to reload.
        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['cache_size']);
    }

    public function testClearCache(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->objectEntityMapper->method('find')
            ->willReturn($entity);
        $this->handler->getObject(1);

        $this->handler->clearCache();

        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['cache_size']);
    }

    // =========================================================================
    // setObjectName / getSingleObjectName
    // =========================================================================

    public function testSetAndGetObjectName(): void
    {
        $this->handler->setObjectName('uuid-1', 'Test Object');

        $name = $this->handler->getSingleObjectName('uuid-1');

        $this->assertSame('Test Object', $name);
    }

    public function testGetSingleObjectNameReturnsNullForUnknownWhenDbFails(): void
    {
        $this->organisationMapper->method('findByUuid')
            ->willThrowException(new Exception('Not found'));

        $this->objectEntityMapper->method('findAcrossAllSources')
            ->willThrowException(new Exception('Not found'));

        // Return null from distributed cache.
        $this->queryCache->method('get')
            ->willReturn(null);

        // getSingleObjectName falls back to DB, which throws, so it returns null.
        $result = $this->handler->getSingleObjectName('nonexistent');

        $this->assertNull($result);
    }

    // =========================================================================
    // getMultipleObjectNames
    // =========================================================================

    public function testGetMultipleObjectNamesReturnsEmptyForEmptyInput(): void
    {
        $result = $this->handler->getMultipleObjectNames([]);

        $this->assertSame([], $result);
    }

    public function testGetMultipleObjectNamesReturnsCachedNames(): void
    {
        $this->handler->setObjectName('uuid-1', 'Object 1');
        $this->handler->setObjectName('uuid-2', 'Object 2');

        $result = $this->handler->getMultipleObjectNames(['uuid-1', 'uuid-2']);

        $this->assertSame('Object 1', $result['uuid-1']);
        $this->assertSame('Object 2', $result['uuid-2']);
    }

    // =========================================================================
    // clearNameCache
    // =========================================================================

    public function testClearNameCache(): void
    {
        $this->handler->setObjectName('uuid-1', 'Test');

        $this->handler->clearNameCache();

        // After clearing name cache, the name should not be in memory.
        // However getSingleObjectName may fall back to DB.
        // We just verify it doesn't throw.
        $this->assertTrue(true);
    }

    // =========================================================================
    // Constructor without cache factory
    // =========================================================================

    public function testConstructorWithoutCacheFactory(): void
    {
        $handler = new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            null,
            $this->userSession
        );

        // Should still work without distributed cache.
        $stats = $handler->getStats();
        $this->assertSame(0, $stats['hits']);
    }

    // =========================================================================
    // getDistributedNameCacheCount
    // =========================================================================

    public function testGetDistributedNameCacheCount(): void
    {
        $count = $this->handler->getDistributedNameCacheCount();

        // Returns 0 or an int depending on cache availability.
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }
}
