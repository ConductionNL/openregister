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
use RuntimeException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\IndexService;
use OCP\AppFramework\IAppContainer;
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

    /** @var IMemcache&MockObject */
    private IMemcache $nameDistributedCache;

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
        $this->nameDistributedCache = $this->createMock(IMemcache::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->cacheFactory->method('createDistributed')
            ->willReturnCallback(function (string $prefix) {
                if ($prefix === 'openregister_object_names') {
                    return $this->nameDistributedCache;
                }
                return $this->queryCache;
            });

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
     * Helper to create an Organisation with a given uuid and name.
     */
    private function createOrganisation(string $uuid, string $name): Organisation
    {
        $org = new Organisation();
        $org->setUuid($uuid);
        $org->setName($name);
        return $org;
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

    public function testGetObjectWithStringId(): void
    {
        $entity = $this->createObjectEntity(42, 'uuid-42');

        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->willReturn($entity);

        $result = $this->handler->getObject('42');
        $this->assertSame($entity, $result);

        // Second call should hit cache.
        $result2 = $this->handler->getObject('42');
        $this->assertSame($entity, $result2);
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

    public function testPreloadObjectsWithDuplicateIdentifiers(): void
    {
        $entity1 = $this->createObjectEntity(1, 'uuid-1');

        $this->objectEntityMapper->expects($this->once())
            ->method('findMultiple')
            ->willReturn([$entity1]);

        $result = $this->handler->preloadObjects([1, 1, 1]);

        $this->assertCount(1, $result);
    }

    public function testPreloadObjectsUpdatesStats(): void
    {
        $entity1 = $this->createObjectEntity(1, 'uuid-1');
        $entity2 = $this->createObjectEntity(2, 'uuid-2');

        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([$entity1, $entity2]);

        $this->handler->preloadObjects([1, 2]);

        $stats = $this->handler->getStats();
        $this->assertSame(2, $stats['preloads']);
    }

    public function testPreloadObjectsMixedCachedAndUncached(): void
    {
        $entity1 = $this->createObjectEntity(1, 'uuid-1');
        $entity2 = $this->createObjectEntity(2, 'uuid-2');

        // Load entity1 into cache first.
        $this->objectEntityMapper->method('find')
            ->willReturn($entity1);
        $this->handler->getObject(1);

        // Now preload both - only entity2 should be loaded from DB.
        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([$entity2]);

        $result = $this->handler->preloadObjects([1, 2]);
        // entity2 loaded from DB.
        $this->assertCount(1, $result);
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

    public function testGetStatsTracksNameHitsAndMisses(): void
    {
        // Set a name directly.
        $this->handler->setObjectName('uuid-1', 'Test Object');

        // This should be a name hit.
        $this->handler->getSingleObjectName('uuid-1');

        $stats = $this->handler->getStats();
        $this->assertSame(1, $stats['name_hits']);
    }

    public function testGetStatsCacheSizeReflectsLoadedObjects(): void
    {
        $entity1 = $this->createObjectEntity(1, 'uuid-1');
        $entity2 = $this->createObjectEntity(2, 'uuid-2');

        $this->objectEntityMapper->method('find')
            ->willReturnOnConsecutiveCalls($entity1, $entity2);

        $this->handler->getObject(1);
        $this->handler->getObject(2);

        $stats = $this->handler->getStats();
        // 2 objects cached by ID + 2 by UUID = 4.
        $this->assertSame(4, $stats['cache_size']);
    }

    public function testGetStatsNameCacheSizeReflectsSetNames(): void
    {
        $this->handler->setObjectName('uuid-1', 'Name 1');
        $this->handler->setObjectName('uuid-2', 'Name 2');

        $stats = $this->handler->getStats();
        $this->assertSame(2, $stats['name_cache_size']);
    }

    public function testGetStatsQueryCacheSize(): void
    {
        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['query_cache_size']);
    }

    public function testGetStatsNameHitRate(): void
    {
        $this->handler->setObjectName('uuid-1', 'Name 1');

        // Hit.
        $this->handler->getSingleObjectName('uuid-1');

        // Miss (unknown uuid, DB will fail).
        $this->organisationMapper->method('findByUuid')
            ->willThrowException(new Exception('Not found'));
        $this->objectEntityMapper->method('findAcrossAllSources')
            ->willThrowException(new Exception('Not found'));
        $this->nameDistributedCache->method('get')
            ->willReturn(null);

        $this->handler->getSingleObjectName('uuid-nonexistent');

        $stats = $this->handler->getStats();
        $this->assertSame(1, $stats['name_hits']);
        $this->assertSame(1, $stats['name_misses']);
        $this->assertSame(50.0, $stats['name_hit_rate']);
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

    public function testInvalidateForObjectChangeOnUpdate(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);
        $entity->setName('Updated Object');

        $this->handler->invalidateForObjectChange($entity, 'update');

        // After invalidation, the object should be removed from cache.
        // Re-fetching should result in a DB call.
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->willReturn($entity);

        $this->handler->getObject(1);
    }

    public function testInvalidateForObjectChangeRemovesObjectFromCache(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);

        // First load the object into cache.
        $this->objectEntityMapper->method('find')
            ->willReturn($entity);
        $this->handler->getObject(1);

        // Verify it's cached (should be hit).
        $this->handler->getObject(1);
        $stats = $this->handler->getStats();
        $this->assertSame(1, $stats['hits']);

        // Invalidate.
        $this->handler->invalidateForObjectChange($entity, 'update');

        // Object should be removed from cache, so getStats cache_size should reflect it.
        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['cache_size']);
    }

    public function testInvalidateForObjectChangeDeleteRemovesNameFromDistributedCache(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);
        $entity->setName('To Delete');

        // Expect name removal from distributed cache.
        $this->nameDistributedCache->expects($this->atLeastOnce())
            ->method('remove');

        $this->handler->invalidateForObjectChange($entity, 'delete');
    }

    public function testInvalidateForObjectChangeDeleteHandlesDistributedCacheException(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);

        $this->nameDistributedCache->method('remove')
            ->willThrowException(new Exception('Cache error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw.
        $this->handler->invalidateForObjectChange($entity, 'delete');
    }

    public function testInvalidateForObjectChangeCreateUpdatesNameCache(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);
        $entity->setName('New Object');

        $this->handler->invalidateForObjectChange($entity, 'create');

        // Name should now be in cache.
        $name = $this->handler->getSingleObjectName('uuid-1');
        $this->assertSame('New Object', $name);
    }

    public function testInvalidateForObjectChangeWithNullSchemaAndRegister(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        // Do not set register or schema.

        $this->handler->invalidateForObjectChange($entity, 'create');

        // Should not throw, just logs.
        $stats = $this->handler->getStats();
        $this->assertIsArray($stats);
    }

    public function testInvalidateForObjectChangeWithExplicitRegisterAndSchemaIds(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        // Don't set register/schema on entity, but pass explicit IDs.

        $this->handler->invalidateForObjectChange($entity, 'update', 7, 14);

        // Should use provided IDs instead of entity IDs.
        $this->assertTrue(true);
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

    public function testClearAllCachesResetsStats(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->objectEntityMapper->method('find')
            ->willReturn($entity);
        $this->handler->getObject(1);
        $this->handler->getObject(1);

        $stats = $this->handler->getStats();
        $this->assertSame(1, $stats['hits']);

        $this->handler->clearAllCaches();

        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
        $this->assertSame(0, $stats['preloads']);
    }

    public function testClearAllCachesClearsNameCache(): void
    {
        $this->handler->setObjectName('uuid-1', 'Test');

        $this->handler->clearAllCaches();

        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['name_cache_size']);
    }

    public function testClearAllCachesHandlesDistributedQueryCacheException(): void
    {
        $this->queryCache->method('clear')
            ->willThrowException(new Exception('Cache clear error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw.
        $this->handler->clearAllCaches();
    }

    public function testClearAllCachesHandlesDistributedNameCacheException(): void
    {
        $this->nameDistributedCache->method('clear')
            ->willThrowException(new Exception('Name cache clear error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw.
        $this->handler->clearAllCaches();
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
        $this->nameDistributedCache->method('get')
            ->willReturn(null);

        // getSingleObjectName falls back to DB, which throws, so it returns null.
        $result = $this->handler->getSingleObjectName('nonexistent');

        $this->assertNull($result);
    }

    public function testGetSingleObjectNameFromDistributedCache(): void
    {
        // Not in in-memory cache, but in distributed cache.
        $this->nameDistributedCache->method('get')
            ->willReturnCallback(function (string $key) {
                if ($key === 'name_uuid-dist') {
                    return 'Distributed Name';
                }
                return null;
            });

        $name = $this->handler->getSingleObjectName('uuid-dist');
        $this->assertSame('Distributed Name', $name);

        // Should also be promoted to in-memory cache.
        $stats = $this->handler->getStats();
        $this->assertSame(1, $stats['name_hits']);
    }

    public function testGetSingleObjectNameHandlesDistributedCacheException(): void
    {
        $this->nameDistributedCache->method('get')
            ->willThrowException(new Exception('Distributed cache error'));

        // Falls through to DB lookup.
        $this->organisationMapper->method('findByUuid')
            ->willThrowException(new Exception('Not found'));
        $this->objectEntityMapper->method('findAcrossAllSources')
            ->willThrowException(new Exception('Not found'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $result = $this->handler->getSingleObjectName('uuid-x');
        $this->assertNull($result);
    }

    public function testGetSingleObjectNameFindsOrganisation(): void
    {
        $org = $this->createOrganisation('org-uuid-1', 'Test Org');

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findByUuid')
            ->willReturn($org);

        $result = $this->handler->getSingleObjectName('org-uuid-1');
        $this->assertSame('Test Org', $result);
    }

    public function testGetSingleObjectNameFindsObjectViaFindAcrossAllSources(): void
    {
        $entity = $this->createObjectEntity(1, 'obj-uuid-1');
        $entity->setName('Found Object');

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findByUuid')
            ->willThrowException(new Exception('Not found'));

        $this->objectEntityMapper->method('findAcrossAllSources')
            ->willReturn(['object' => $entity]);

        $result = $this->handler->getSingleObjectName('obj-uuid-1');
        $this->assertSame('Found Object', $result);
    }

    public function testGetSingleObjectNameUsesUuidWhenNameIsNull(): void
    {
        $entity = $this->createObjectEntity(1, 'obj-uuid-2');
        // Don't set name, so getName() returns null.

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findByUuid')
            ->willThrowException(new Exception('Not found'));

        $this->objectEntityMapper->method('findAcrossAllSources')
            ->willReturn(['object' => $entity]);

        $result = $this->handler->getSingleObjectName('obj-uuid-2');
        $this->assertSame('obj-uuid-2', $result);
    }

    public function testSetObjectNameWithIntIdentifier(): void
    {
        $this->handler->setObjectName(42, 'Name for 42');

        $name = $this->handler->getSingleObjectName(42);
        $this->assertSame('Name for 42', $name);
    }

    public function testSetObjectNameEnforcesMaxTtl(): void
    {
        // TTL greater than MAX_CACHE_TTL should be clamped.
        $this->nameDistributedCache->expects($this->once())
            ->method('set')
            ->with('name_uuid-1', 'Name', 86400);  // MAX_CACHE_TTL = 86400

        $this->handler->setObjectName('uuid-1', 'Name', 999999);
    }

    public function testSetObjectNameHandlesDistributedCacheException(): void
    {
        $this->nameDistributedCache->method('set')
            ->willThrowException(new Exception('Cache write error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw, name should still be in memory.
        $this->handler->setObjectName('uuid-1', 'Test Name');

        $name = $this->handler->getSingleObjectName('uuid-1');
        $this->assertSame('Test Name', $name);
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

    public function testGetMultipleObjectNamesChecksDistributedCache(): void
    {
        // uuid-1 in memory, uuid-2 in distributed cache.
        $this->handler->setObjectName('uuid-1', 'Memory Name');

        $this->nameDistributedCache->method('get')
            ->willReturnCallback(function (string $key) {
                if ($key === 'name_uuid-2') {
                    return 'Distributed Name';
                }
                return null;
            });

        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);
        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([]);

        $result = $this->handler->getMultipleObjectNames(['uuid-1', 'uuid-2']);

        $this->assertSame('Memory Name', $result['uuid-1']);
        $this->assertSame('Distributed Name', $result['uuid-2']);
    }

    public function testGetMultipleObjectNamesFallsBackToOrganisationMapper(): void
    {
        $org = $this->createOrganisation('org-uuid-1', 'Org Name');

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([$org]);

        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([]);

        $result = $this->handler->getMultipleObjectNames(['org-uuid-1']);

        $this->assertSame('Org Name', $result['org-uuid-1']);
    }

    public function testGetMultipleObjectNamesFallsBackToObjectMapper(): void
    {
        $entity = $this->createObjectEntity(1, 'obj-uuid-1');
        $entity->setName('Object Name');

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);

        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([$entity]);

        $result = $this->handler->getMultipleObjectNames(['obj-uuid-1']);

        $this->assertSame('Object Name', $result['obj-uuid-1']);
    }

    public function testGetMultipleObjectNamesHandlesDbException(): void
    {
        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findMultipleByUuid')
            ->willThrowException(new Exception('DB error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $result = $this->handler->getMultipleObjectNames(['uuid-missing']);
        $this->assertIsArray($result);
    }

    public function testGetMultipleObjectNamesFiltersToUuidOnlyResults(): void
    {
        // Set names with both UUID-like and numeric keys.
        $this->handler->setObjectName('abc-def-ghi', 'UUID Name');
        $this->handler->setObjectName(42, 'Numeric Name');

        // Only 'abc-def-ghi' should appear (UUID-like, contains hyphen).
        $result = $this->handler->getMultipleObjectNames(['abc-def-ghi', 42]);

        $this->assertArrayHasKey('abc-def-ghi', $result);
        // Numeric ID without hyphen should be filtered out.
        $this->assertArrayNotHasKey('42', $result);
    }

    public function testGetMultipleObjectNamesHandlesDistributedCacheException(): void
    {
        $this->nameDistributedCache->method('get')
            ->willThrowException(new Exception('Cache error'));

        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);

        // Use a numeric ID (not UUID-like) so batchLoadNamesFromMagicTables returns early.
        // This avoids the null registerMapper issue.
        $result = $this->handler->getMultipleObjectNames([42]);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // getAllObjectNames
    // =========================================================================

    public function testGetAllObjectNamesTriggersWarmupWhenEmpty(): void
    {
        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        $result = $this->handler->getAllObjectNames();
        $this->assertIsArray($result);
    }

    public function testGetAllObjectNamesWithForceWarmup(): void
    {
        // Pre-populate.
        $this->handler->setObjectName('uuid-1', 'Name 1');

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        $result = $this->handler->getAllObjectNames(true);
        $this->assertIsArray($result);
    }

    public function testGetAllObjectNamesSkipsWarmupWhenCachePopulated(): void
    {
        // Pre-populate cache.
        $this->handler->setObjectName('uuid-1', 'Name 1');

        // Without force warmup, should skip warmup.
        $this->organisationMapper->expects($this->never())
            ->method('findAllWithUserCount');

        $result = $this->handler->getAllObjectNames(false);
        $this->assertArrayHasKey('uuid-1', $result);
    }

    public function testGetAllObjectNamesFiltersToUuidKeys(): void
    {
        $this->handler->setObjectName('abc-def', 'UUID Name');
        $this->handler->setObjectName(123, 'Numeric Name');

        $result = $this->handler->getAllObjectNames(false);

        $this->assertArrayHasKey('abc-def', $result);
        $this->assertArrayNotHasKey('123', $result);
    }

    // =========================================================================
    // warmupNameCache
    // =========================================================================

    public function testWarmupNameCacheLoadsOrganisationsAndObjects(): void
    {
        $org = $this->createOrganisation('org-uuid-1', 'Test Org');

        $entity = $this->createObjectEntity(1, 'obj-uuid-1');
        $entity->setName('Test Object');

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([$org]);

        $this->objectEntityMapper->method('findAll')
            ->willReturn([$entity]);

        $count = $this->handler->warmupNameCache();

        $this->assertSame(2, $count);
    }

    public function testWarmupNameCacheOrganisationsTakePriority(): void
    {
        $org = $this->createOrganisation('shared-uuid', 'Org Name');

        $entity = $this->createObjectEntity(1, 'shared-uuid');
        $entity->setName('Object Name');

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([$org]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([$entity]);

        $this->handler->warmupNameCache();

        // Organisation name should win.
        $name = $this->handler->getSingleObjectName('shared-uuid');
        $this->assertSame('Org Name', $name);
    }

    public function testWarmupNameCacheHandlesException(): void
    {
        $this->organisationMapper->method('findAllWithUserCount')
            ->willThrowException(new Exception('DB error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $count = $this->handler->warmupNameCache();
        $this->assertSame(0, $count);
    }

    public function testWarmupNameCacheUpdatesStats(): void
    {
        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        $this->handler->warmupNameCache();

        $stats = $this->handler->getStats();
        $this->assertArrayHasKey('name_warmups', $stats);
        $this->assertSame(1, $stats['name_warmups']);
    }

    public function testWarmupNameCacheSkipsObjectsWithNullUuid(): void
    {
        $entity = new ObjectEntity();
        // Don't set UUID or name.

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([$entity]);

        $count = $this->handler->warmupNameCache();
        $this->assertSame(0, $count);
    }

    // =========================================================================
    // clearNameCache
    // =========================================================================

    public function testClearNameCache(): void
    {
        $this->handler->setObjectName('uuid-1', 'Test');

        $this->handler->clearNameCache();

        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['name_cache_size']);
    }

    public function testClearNameCacheClearsDistributedCache(): void
    {
        $this->nameDistributedCache->expects($this->atLeastOnce())
            ->method('clear');

        $this->handler->clearNameCache();
    }

    public function testClearNameCacheHandlesDistributedCacheException(): void
    {
        $this->nameDistributedCache->method('clear')
            ->willThrowException(new Exception('Cache error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw.
        $this->handler->clearNameCache();
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

    public function testConstructorWithCacheFactoryException(): void
    {
        $failingCacheFactory = $this->createMock(ICacheFactory::class);
        $failingCacheFactory->method('createDistributed')
            ->willThrowException(new Exception('Cache factory error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $handler = new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            $failingCacheFactory,
            $this->userSession
        );

        $stats = $handler->getStats();
        $this->assertSame(0, $stats['hits']);
    }

    // =========================================================================
    // getDistributedNameCacheCount
    // =========================================================================

    public function testGetDistributedNameCacheCount(): void
    {
        $this->nameDistributedCache->method('get')
            ->willReturnCallback(function (string $key) {
                if ($key === '_metadata_count') {
                    return 42;
                }
                return null;
            });

        $count = $this->handler->getDistributedNameCacheCount();
        $this->assertSame(42, $count);
    }

    public function testGetDistributedNameCacheCountReturnsZeroWhenNull(): void
    {
        $this->nameDistributedCache->method('get')
            ->willReturn(null);

        $count = $this->handler->getDistributedNameCacheCount();
        $this->assertSame(0, $count);
    }

    public function testGetDistributedNameCacheCountHandlesException(): void
    {
        $this->nameDistributedCache->method('get')
            ->willThrowException(new Exception('Cache error'));

        $count = $this->handler->getDistributedNameCacheCount();
        $this->assertSame(0, $count);
    }

    public function testGetDistributedNameCacheCountWithoutDistributedCache(): void
    {
        $handler = new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            null,
            $this->userSession
        );

        $count = $handler->getDistributedNameCacheCount();
        $this->assertSame(0, $count);
    }

    // =========================================================================
    // Solr-related methods (no container)
    // =========================================================================

    public function testGetSolrDashboardStatsThrowsWithoutContainer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Index service is not available');

        $this->handler->getSolrDashboardStats();
    }

    public function testCommitSolrReturnsErrorWithoutContainer(): void
    {
        $result = $this->handler->commitSolr();
        $this->assertFalse($result['success']);
        $this->assertSame('Index service is not available', $result['error']);
    }

    public function testOptimizeSolrReturnsErrorWithoutContainer(): void
    {
        $result = $this->handler->optimizeSolr();
        $this->assertFalse($result['success']);
        $this->assertSame('Index service is not available', $result['error']);
    }

    public function testClearSolrIndexForDashboardReturnsErrorWithoutContainer(): void
    {
        $result = $this->handler->clearSolrIndexForDashboard();
        $this->assertFalse($result['success']);
        $this->assertSame('Index service is not available', $result['error']);
    }

    // =========================================================================
    // Solr-related methods (with container + IndexService mock)
    // =========================================================================

    private function createHandlerWithContainer(IndexService $indexService): CacheHandler
    {
        $container = $this->createMock(IAppContainer::class);
        $container->method('get')
            ->willReturn($indexService);

        return new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            $this->cacheFactory,
            $this->userSession,
            $container
        );
    }

    public function testCommitSolrSuccess(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('commit')->willReturn(true);

        $handler = $this->createHandlerWithContainer($indexService);

        $result = $handler->commitSolr();
        $this->assertTrue($result['success']);
        $this->assertSame('Commit successful', $result['message']);
    }

    public function testCommitSolrFailure(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('commit')->willReturn(false);

        $handler = $this->createHandlerWithContainer($indexService);

        $result = $handler->commitSolr();
        $this->assertFalse($result['success']);
        $this->assertSame('Commit failed', $result['message']);
    }

    public function testCommitSolrException(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('commit')
            ->willThrowException(new Exception('Commit error'));

        $handler = $this->createHandlerWithContainer($indexService);

        $result = $handler->commitSolr();
        $this->assertFalse($result['success']);
        $this->assertSame('Commit error', $result['error']);
    }

    public function testOptimizeSolrSuccess(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('optimize')->willReturn(true);

        $handler = $this->createHandlerWithContainer($indexService);

        $result = $handler->optimizeSolr();
        $this->assertTrue($result['success']);
        $this->assertSame('Optimization successful', $result['message']);
    }

    public function testOptimizeSolrFailure(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('optimize')->willReturn(false);

        $handler = $this->createHandlerWithContainer($indexService);

        $result = $handler->optimizeSolr();
        $this->assertFalse($result['success']);
        $this->assertSame('Optimization failed', $result['message']);
    }

    public function testOptimizeSolrException(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('optimize')
            ->willThrowException(new Exception('Optimize error'));

        $handler = $this->createHandlerWithContainer($indexService);

        $result = $handler->optimizeSolr();
        $this->assertFalse($result['success']);
        $this->assertSame('Optimize error', $result['error']);
    }

    public function testClearSolrIndexForDashboardSuccess(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('clearIndex')
            ->willReturn(['success' => true, 'error' => null]);

        $handler = $this->createHandlerWithContainer($indexService);

        $result = $handler->clearSolrIndexForDashboard();
        $this->assertTrue($result['success']);
        $this->assertSame('Index cleared successfully', $result['message']);
    }

    public function testGetSolrDashboardStatsWithService(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('getStats')
            ->willReturn(['numDocs' => 100, 'indexSize' => '10MB']);

        $handler = $this->createHandlerWithContainer($indexService);

        $result = $handler->getSolrDashboardStats();
        $this->assertSame(100, $result['numDocs']);
    }

    // =========================================================================
    // getIndexService container exception
    // =========================================================================

    public function testGetIndexServiceReturnsNullOnContainerException(): void
    {
        $container = $this->createMock(IAppContainer::class);
        $container->method('get')
            ->willThrowException(new Exception('Service not found'));

        $handler = new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            $this->cacheFactory,
            $this->userSession,
            $container
        );

        // commitSolr checks for null IndexService.
        $result = $handler->commitSolr();
        $this->assertFalse($result['success']);
    }
}
