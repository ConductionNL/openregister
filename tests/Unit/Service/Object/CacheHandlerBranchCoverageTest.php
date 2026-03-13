<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Branch coverage tests for CacheHandler — targets uncovered branches in
 * getObject, preloadObjects, getStats, setObjectName, clearSearchCache,
 * clearAllCaches, invalidateForObjectChange.
 */
class CacheHandlerBranchCoverageTest extends TestCase
{
    private CacheHandler $handler;
    private ObjectEntityMapper&MockObject $objectEntityMapper;
    private OrganisationMapper&MockObject $organisationMapper;
    private LoggerInterface&MockObject $logger;
    private ICacheFactory&MockObject $cacheFactory;
    private IUserSession&MockObject $userSession;
    private IMemcache&MockObject $queryCache;
    private IMemcache&MockObject $nameDistCache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->queryCache = $this->createMock(IMemcache::class);
        $this->nameDistCache = $this->createMock(IMemcache::class);

        $this->cacheFactory->method('createDistributed')
            ->willReturnCallback(function ($prefix) {
                if ($prefix === 'openregister_query_results') {
                    return $this->queryCache;
                }
                return $this->nameDistCache;
            });

        $this->handler = new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            $this->cacheFactory,
            $this->userSession
        );
    }

    /**
     * Helper: create ObjectEntity mock with addMethods for magic getters.
     */
    private function createObjectMock(
        int $id,
        string $uuid,
        ?int $register = null,
        ?int $schema = null,
        ?string $organisation = null,
        ?string $name = null,
        ?string $deleted = null
    ): ObjectEntity&MockObject {
        $mock = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getId', 'getUuid', 'getRegister', 'getSchema', 'getOrganisation', 'getName', 'getDeleted'])
            ->getMock();
        $mock->method('getId')->willReturn($id);
        $mock->method('getUuid')->willReturn($uuid);
        $mock->method('getRegister')->willReturn($register);
        $mock->method('getSchema')->willReturn($schema);
        $mock->method('getOrganisation')->willReturn($organisation);
        $mock->method('getName')->willReturn($name);
        $mock->method('getDeleted')->willReturn($deleted);
        return $mock;
    }

    // =========================================================================
    // getObject — cache hit + cache miss
    // =========================================================================

    public function testGetObjectCacheMissLoadsFromDb(): void
    {
        $object = $this->createObjectMock(42, 'uuid-123');

        $this->objectEntityMapper->method('find')
            ->with(42)
            ->willReturn($object);

        $result = $this->handler->getObject(42);
        $this->assertSame($object, $result);

        // Second call should hit the cache
        $result2 = $this->handler->getObject(42);
        $this->assertSame($object, $result2);
    }

    public function testGetObjectReturnsNullOnException(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->handler->getObject(999);
        $this->assertNull($result);
    }

    // =========================================================================
    // preloadObjects
    // =========================================================================

    public function testPreloadObjectsEmptyArray(): void
    {
        $result = $this->handler->preloadObjects([]);
        $this->assertSame([], $result);
    }

    public function testPreloadObjectsAllAlreadyCached(): void
    {
        $object = $this->createObjectMock(1, 'uuid-1');
        $this->objectEntityMapper->method('find')->willReturn($object);
        $this->handler->getObject(1);

        $result = $this->handler->preloadObjects([1]);
        $this->assertCount(1, $result);
    }

    public function testPreloadObjectsBulkLoadFailure(): void
    {
        $this->objectEntityMapper->method('findMultiple')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->handler->preloadObjects([1, 2, 3]);
        $this->assertSame([], $result);
    }

    public function testPreloadObjectsSuccess(): void
    {
        $obj1 = $this->createObjectMock(1, 'uuid-1');
        $obj2 = $this->createObjectMock(2, 'uuid-2');

        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([$obj1, $obj2]);

        $result = $this->handler->preloadObjects([1, 2]);
        $this->assertCount(2, $result);
    }

    // =========================================================================
    // getStats
    // =========================================================================

    public function testGetStatsInitial(): void
    {
        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
        $this->assertSame(0.0, $stats['hit_rate']);
        $this->assertSame(0, $stats['cache_size']);
    }

    public function testGetStatsAfterCacheMiss(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new \Exception('Not found'));
        $this->handler->getObject(1);

        $stats = $this->handler->getStats();
        $this->assertSame(1, $stats['misses']);
        $this->assertSame(0.0, $stats['hit_rate']);
    }

    // =========================================================================
    // setObjectName
    // =========================================================================

    public function testSetObjectNameStoresInBothCaches(): void
    {
        $this->nameDistCache->expects($this->once())
            ->method('set')
            ->with('name_uuid-123', 'Test Object', $this->anything());

        $this->handler->setObjectName('uuid-123', 'Test Object');
    }

    public function testSetObjectNameDistributedCacheFailure(): void
    {
        $this->nameDistCache->method('set')
            ->willThrowException(new \Exception('Cache write error'));

        $this->handler->setObjectName('uuid-456', 'Test');
        $this->assertTrue(true);
    }

    // =========================================================================
    // clearSearchCache
    // =========================================================================

    public function testClearSearchCacheWithoutPattern(): void
    {
        $this->queryCache->expects($this->once())->method('clear');
        $this->handler->clearSearchCache();
    }

    public function testClearSearchCacheWithPattern(): void
    {
        $this->queryCache->expects($this->once())->method('clear');
        $this->handler->clearSearchCache('schema_1');
    }

    public function testClearSearchCacheDistributedFailure(): void
    {
        $this->queryCache->method('clear')
            ->willThrowException(new \Exception('Cache clear failed'));

        $this->handler->clearSearchCache();
        $this->assertTrue(true);
    }

    // =========================================================================
    // clearAllCaches
    // =========================================================================

    public function testClearAllCaches(): void
    {
        $object = $this->createObjectMock(1, 'uuid-1');
        $this->objectEntityMapper->method('find')->willReturn($object);
        $this->handler->getObject(1);

        $this->queryCache->expects($this->once())->method('clear');
        $this->nameDistCache->expects($this->once())->method('clear');

        $this->handler->clearAllCaches();

        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['cache_size']);
    }

    public function testClearAllCachesDistributedFailures(): void
    {
        $this->queryCache->method('clear')
            ->willThrowException(new \Exception('Query cache error'));
        $this->nameDistCache->method('clear')
            ->willThrowException(new \Exception('Name cache error'));

        $this->handler->clearAllCaches();
        $this->assertTrue(true);
    }

    // =========================================================================
    // clearCache (legacy)
    // =========================================================================

    public function testClearCacheDelegatesToClearAllCaches(): void
    {
        $this->handler->clearCache();
        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['hits']);
    }

    // =========================================================================
    // invalidateForObjectChange
    // =========================================================================

    public function testInvalidateForObjectChangeCreate(): void
    {
        $object = $this->createObjectMock(1, 'uuid-1', 10, 20, 'org-1', 'Test Object');
        $this->handler->invalidateForObjectChange($object, 'create');
        $this->assertTrue(true);
    }

    public function testInvalidateForObjectChangeDelete(): void
    {
        $object = $this->createObjectMock(1, 'uuid-1', 10, 20, 'org-1', null);
        $this->nameDistCache->method('remove')
            ->willThrowException(new \Exception('Remove failed'));

        $this->handler->invalidateForObjectChange($object, 'delete');
        $this->assertTrue(true);
    }

    public function testInvalidateForObjectChangeNullObject(): void
    {
        $this->handler->invalidateForObjectChange(null, 'unknown', 10, 20);
        $this->assertTrue(true);
    }

    public function testInvalidateForObjectChangeNullSchemaId(): void
    {
        $this->handler->invalidateForObjectChange(null, 'update');
        $this->assertTrue(true);
    }

    // =========================================================================
    // Constructor — cache factory exception
    // =========================================================================

    public function testConstructorHandlesCacheFactoryException(): void
    {
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')
            ->willThrowException(new \Exception('Cache init failed'));

        $handler = new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            $cacheFactory,
            $this->userSession
        );

        $this->assertInstanceOf(CacheHandler::class, $handler);
    }
}
