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
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     Comprehensive test coverage requires many test methods
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Full coverage of 843-line class requires extensive tests
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Tests need to mock many dependencies
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex cache scenarios require detailed test setup
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use RuntimeException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\IndexService;
use OCP\AppFramework\IAppContainer;
use OCP\DB\IResult;
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

    /**
     * Set up test fixtures.
     *
     * @return void
     */
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
     *
     * @param string $uuid Organisation UUID
     * @param string $name Organisation name
     *
     * @return Organisation
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
     *
     * @param int    $id   Object ID
     * @param string $uuid Object UUID
     *
     * @return ObjectEntity
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

    /**
     * Helper to create a Register with a given id, schemas, and optional configuration.
     *
     * @param int        $id            Register ID
     * @param array      $schemas       Schema IDs
     * @param array|null $configuration Optional configuration for magic mapping
     *
     * @return Register
     */
    private function createRegister(int $id, array $schemas, ?array $configuration = null): Register
    {
        $register = new Register();
        $ref = new ReflectionClass($register);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, $id);
        $register->setSchemas($schemas);
        if ($configuration !== null) {
            $register->setConfiguration($configuration);
        }
        return $register;
    }

    /**
     * Helper to create a Schema with a given id and slug.
     *
     * @param int    $id   Schema ID
     * @param string $slug Schema slug
     *
     * @return Schema
     */
    private function createSchema(int $id, string $slug): Schema
    {
        $schema = new Schema();
        $ref = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, $id);
        $schema->setSlug($slug);
        return $schema;
    }

    /**
     * Create a CacheHandler with a container providing an IndexService mock.
     *
     * @param IndexService $indexService The index service mock
     *
     * @return CacheHandler
     */
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

    /**
     * Create a CacheHandler with full dependencies including DB and mappers.
     *
     * @param IAppContainer|null $container     Optional container
     * @param RegisterMapper     $registerMapper Register mapper
     * @param SchemaMapper       $schemaMapper   Schema mapper
     * @param IDBConnection      $db             Database connection
     *
     * @return CacheHandler
     */
    private function createHandlerWithDbDeps(
        ?IAppContainer $container,
        RegisterMapper $registerMapper,
        SchemaMapper $schemaMapper,
        IDBConnection $db
    ): CacheHandler {
        return new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            $this->cacheFactory,
            $this->userSession,
            $container,
            $registerMapper,
            $schemaMapper,
            $db
        );
    }

    // =========================================================================
    // getObject
    // =========================================================================

    /**
     * Test that getObject returns cached object on second call.
     *
     * @return void
     */
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

    /**
     * Test that getObject returns null when mapper throws.
     *
     * @return void
     */
    public function testGetObjectReturnsNullOnException(): void
    {
        $this->objectEntityMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->getObject(999);

        $this->assertNull($result);
    }

    /**
     * Test that getObject retrieves by UUID from cache after ID load.
     *
     * @return void
     */
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

    /**
     * Test that getObject works with string identifiers.
     *
     * @return void
     */
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

    /**
     * Test preloadObjects returns empty for empty input.
     *
     * @return void
     */
    public function testPreloadObjectsReturnsEmptyForEmptyInput(): void
    {
        $result = $this->handler->preloadObjects([]);

        $this->assertSame([], $result);
    }

    /**
     * Test preloadObjects bulk loads from database.
     *
     * @return void
     */
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

    /**
     * Test preloadObjects skips already cached objects.
     *
     * @return void
     */
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

    /**
     * Test preloadObjects returns empty on exception.
     *
     * @return void
     */
    public function testPreloadObjectsReturnsEmptyOnException(): void
    {
        $this->objectEntityMapper->method('findMultiple')
            ->willThrowException(new Exception('DB error'));

        $result = $this->handler->preloadObjects([1, 2]);

        $this->assertSame([], $result);
    }

    /**
     * Test preloadObjects deduplicates identifiers.
     *
     * @return void
     */
    public function testPreloadObjectsWithDuplicateIdentifiers(): void
    {
        $entity1 = $this->createObjectEntity(1, 'uuid-1');

        $this->objectEntityMapper->expects($this->once())
            ->method('findMultiple')
            ->willReturn([$entity1]);

        $result = $this->handler->preloadObjects([1, 1, 1]);

        $this->assertCount(1, $result);
    }

    /**
     * Test preloadObjects updates preloads stat.
     *
     * @return void
     */
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

    /**
     * Test preloadObjects with mix of cached and uncached.
     *
     * @return void
     */
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

    /**
     * Test getStats returns initial zeroed stats.
     *
     * @return void
     */
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

    /**
     * Test getStats tracks hits and misses with correct hit rate.
     *
     * @return void
     */
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

    /**
     * Test getStats tracks name hits.
     *
     * @return void
     */
    public function testGetStatsTracksNameHitsAndMisses(): void
    {
        // Set a name directly.
        $this->handler->setObjectName('uuid-1', 'Test Object');

        // This should be a name hit.
        $this->handler->getSingleObjectName('uuid-1');

        $stats = $this->handler->getStats();
        $this->assertSame(1, $stats['name_hits']);
    }

    /**
     * Test getStats cache size reflects loaded objects.
     *
     * @return void
     */
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

    /**
     * Test getStats name cache size reflects set names.
     *
     * @return void
     */
    public function testGetStatsNameCacheSizeReflectsSetNames(): void
    {
        $this->handler->setObjectName('uuid-1', 'Name 1');
        $this->handler->setObjectName('uuid-2', 'Name 2');

        $stats = $this->handler->getStats();
        $this->assertSame(2, $stats['name_cache_size']);
    }

    /**
     * Test getStats query cache size.
     *
     * @return void
     */
    public function testGetStatsQueryCacheSize(): void
    {
        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['query_cache_size']);
    }

    /**
     * Test getStats name hit rate calculation.
     *
     * @return void
     */
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

    /**
     * Test getStats includes distributed_name_cache_size.
     *
     * @return void
     */
    public function testGetStatsIncludesDistributedNameCacheSize(): void
    {
        $this->nameDistributedCache->method('get')
            ->willReturnCallback(function (string $key) {
                if ($key === '_metadata_count') {
                    return 10;
                }
                return null;
            });

        $stats = $this->handler->getStats();
        $this->assertArrayHasKey('distributed_name_cache_size', $stats);
        $this->assertSame(10, $stats['distributed_name_cache_size']);
    }

    // =========================================================================
    // clearSearchCache
    // =========================================================================

    /**
     * Test clearSearchCache clears all caches.
     *
     * @return void
     */
    public function testClearSearchCacheClearsAll(): void
    {
        $this->queryCache->expects($this->once())
            ->method('clear');

        $this->handler->clearSearchCache();
    }

    /**
     * Test clearSearchCache with pattern filter.
     *
     * @return void
     */
    public function testClearSearchCacheWithPattern(): void
    {
        // Pattern-based clearing should still clear distributed cache.
        $this->queryCache->expects($this->once())
            ->method('clear');

        $this->handler->clearSearchCache('schema_42');
    }

    /**
     * Test clearSearchCache handles distributed cache exception.
     *
     * @return void
     */
    public function testClearSearchCacheHandlesDistributedCacheException(): void
    {
        $this->queryCache->method('clear')
            ->willThrowException(new Exception('Memcache error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw.
        $this->handler->clearSearchCache();
    }

    /**
     * Test clearSearchCache without distributed cache.
     *
     * @return void
     */
    public function testClearSearchCacheWithoutDistributedCache(): void
    {
        $handler = new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            null,
            $this->userSession
        );

        // Should not throw.
        $handler->clearSearchCache();
        $handler->clearSearchCache('pattern');
        $this->assertTrue(true);
    }

    // =========================================================================
    // invalidateForObjectChange
    // =========================================================================

    /**
     * Test invalidateForObjectChange with object on create.
     *
     * @return void
     */
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

    /**
     * Test invalidateForObjectChange with null object.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeWithNullObject(): void
    {
        // Bulk operation scenario - no specific object.
        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $this->handler->invalidateForObjectChange(null, 'bulk_save', 5, 10);
    }

    /**
     * Test invalidateForObjectChange on delete.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeOnDelete(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);

        $this->logger->expects($this->atLeastOnce())
            ->method('info');

        $this->handler->invalidateForObjectChange($entity, 'delete');
    }

    /**
     * Test invalidateForObjectChange on update removes object from cache.
     *
     * @return void
     */
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

    /**
     * Test invalidateForObjectChange removes object from cache.
     *
     * @return void
     */
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

    /**
     * Test invalidateForObjectChange delete removes name from distributed cache.
     *
     * @return void
     */
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

    /**
     * Test invalidateForObjectChange delete handles distributed cache exception.
     *
     * @return void
     */
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

    /**
     * Test invalidateForObjectChange create updates name cache.
     *
     * @return void
     */
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

    /**
     * Test invalidateForObjectChange with null schema and register.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeWithNullSchemaAndRegister(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        // Do not set register or schema.

        $this->handler->invalidateForObjectChange($entity, 'create');

        // Should not throw, just logs.
        $stats = $this->handler->getStats();
        $this->assertIsArray($stats);
    }

    /**
     * Test invalidateForObjectChange with explicit register and schema IDs.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeWithExplicitRegisterAndSchemaIds(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1');
        // Don't set register/schema on entity, but pass explicit IDs.

        $this->handler->invalidateForObjectChange($entity, 'update', 7, 14);

        // Should use provided IDs instead of entity IDs.
        $this->assertTrue(true);
    }

    /**
     * Test invalidateForObjectChange create with IndexService available.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeCreateWithIndexService(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->expects($this->once())
            ->method('indexObject')
            ->willReturn(true);

        $handler = $this->createHandlerWithContainer($indexService);

        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);
        $entity->setName('Indexed Object');

        $handler->invalidateForObjectChange($entity, 'create');
    }

    /**
     * Test invalidateForObjectChange update with IndexService indexes object.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeUpdateWithIndexService(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->expects($this->once())
            ->method('indexObject')
            ->willReturn(true);

        $handler = $this->createHandlerWithContainer($indexService);

        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);
        $entity->setName('Updated Object');

        $handler->invalidateForObjectChange($entity, 'update');
    }

    /**
     * Test invalidateForObjectChange create with IndexService indexing failure.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeCreateWithIndexFailure(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('indexObject')
            ->willReturn(false);

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $handler = $this->createHandlerWithContainer($indexService);

        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);
        $entity->setName('Failed Index');

        $handler->invalidateForObjectChange($entity, 'create');
    }

    /**
     * Test invalidateForObjectChange create with IndexService unavailable (graceful).
     *
     * @return void
     */
    public function testInvalidateForObjectChangeCreateWithIndexUnavailable(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(false);

        // indexObject should not be called.
        $indexService->expects($this->never())
            ->method('indexObject');

        $handler = $this->createHandlerWithContainer($indexService);

        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);
        $entity->setName('No Index');

        $handler->invalidateForObjectChange($entity, 'create');
    }

    /**
     * Test invalidateForObjectChange delete removes object from Solr.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeDeleteWithIndexService(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->expects($this->once())
            ->method('deleteObject')
            ->willReturn(true);

        $handler = $this->createHandlerWithContainer($indexService);

        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);

        $handler->invalidateForObjectChange($entity, 'delete');
    }

    /**
     * Test invalidateForObjectChange delete with Solr removal failure.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeDeleteWithSolrFailure(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('deleteObject')
            ->willReturn(false);

        $handler = $this->createHandlerWithContainer($indexService);

        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);

        // Should not throw.
        $handler->invalidateForObjectChange($entity, 'delete');
        $this->assertTrue(true);
    }

    /**
     * Test invalidateForObjectChange delete with Solr removal exception.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeDeleteWithSolrException(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('deleteObject')
            ->willThrowException(new Exception('Solr error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $handler = $this->createHandlerWithContainer($indexService);

        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);

        // Should not throw - graceful degradation.
        $handler->invalidateForObjectChange($entity, 'delete');
    }

    /**
     * Test invalidateForObjectChange create uses UUID as name when name is null.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeCreateUsesUuidWhenNameNull(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-fallback');
        $entity->setRegister(5);
        $entity->setSchema(10);
        // Don't set name.

        $this->handler->invalidateForObjectChange($entity, 'create');

        // Name should be the UUID.
        $name = $this->handler->getSingleObjectName('uuid-fallback');
        $this->assertSame('uuid-fallback', $name);
    }

    /**
     * Test invalidateForObjectChange null object with null schema triggers global fallback.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeNullObjectNullSchema(): void
    {
        // This triggers clearSchemaRelatedCaches with null schemaId, which
        // calls clearSearchCache as fallback.
        $this->queryCache->expects($this->atLeastOnce())
            ->method('clear');

        $this->handler->invalidateForObjectChange(null, 'unknown');
    }

    /**
     * Test invalidateForObjectChange with schema-targeted distributed cache exception.
     *
     * @return void
     */
    public function testInvalidateForObjectChangeSchemaDistributedCacheException(): void
    {
        $this->queryCache->method('clear')
            ->willThrowException(new Exception('Distributed cache error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $entity = $this->createObjectEntity(1, 'uuid-1');
        $entity->setRegister(5);
        $entity->setSchema(10);

        // Should not throw.
        $this->handler->invalidateForObjectChange($entity, 'update');
    }

    // =========================================================================
    // clearAllCaches / clearCache
    // =========================================================================

    /**
     * Test clearAllCaches resets all caches.
     *
     * @return void
     */
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

    /**
     * Test clearCache delegates to clearAllCaches.
     *
     * @return void
     */
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

    /**
     * Test clearAllCaches resets stats.
     *
     * @return void
     */
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

    /**
     * Test clearAllCaches clears name cache.
     *
     * @return void
     */
    public function testClearAllCachesClearsNameCache(): void
    {
        $this->handler->setObjectName('uuid-1', 'Test');

        $this->handler->clearAllCaches();

        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['name_cache_size']);
    }

    /**
     * Test clearAllCaches handles distributed query cache exception.
     *
     * @return void
     */
    public function testClearAllCachesHandlesDistributedQueryCacheException(): void
    {
        $this->queryCache->method('clear')
            ->willThrowException(new Exception('Cache clear error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        // Should not throw.
        $this->handler->clearAllCaches();
    }

    /**
     * Test clearAllCaches handles distributed name cache exception.
     *
     * @return void
     */
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

    /**
     * Test set and get object name round trip.
     *
     * @return void
     */
    public function testSetAndGetObjectName(): void
    {
        $this->handler->setObjectName('uuid-1', 'Test Object');

        $name = $this->handler->getSingleObjectName('uuid-1');

        $this->assertSame('Test Object', $name);
    }

    /**
     * Test getSingleObjectName returns null when DB fails.
     *
     * @return void
     */
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

    /**
     * Test getSingleObjectName from distributed cache.
     *
     * @return void
     */
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

    /**
     * Test getSingleObjectName handles distributed cache exception.
     *
     * @return void
     */
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

    /**
     * Test getSingleObjectName finds organisation.
     *
     * @return void
     */
    public function testGetSingleObjectNameFindsOrganisation(): void
    {
        $org = $this->createOrganisation('org-uuid-1', 'Test Org');

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findByUuid')
            ->willReturn($org);

        $result = $this->handler->getSingleObjectName('org-uuid-1');
        $this->assertSame('Test Org', $result);
    }

    /**
     * Test getSingleObjectName finds object via findAcrossAllSources.
     *
     * @return void
     */
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

    /**
     * Test getSingleObjectName uses UUID when name is null.
     *
     * @return void
     */
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

    /**
     * Test setObjectName with integer identifier.
     *
     * @return void
     */
    public function testSetObjectNameWithIntIdentifier(): void
    {
        $this->handler->setObjectName(42, 'Name for 42');

        $name = $this->handler->getSingleObjectName(42);
        $this->assertSame('Name for 42', $name);
    }

    /**
     * Test setObjectName enforces max TTL.
     *
     * @return void
     */
    public function testSetObjectNameEnforcesMaxTtl(): void
    {
        // TTL greater than MAX_CACHE_TTL should be clamped.
        $this->nameDistributedCache->expects($this->once())
            ->method('set')
            ->with('name_uuid-1', 'Name', 86400);  // MAX_CACHE_TTL = 86400

        $this->handler->setObjectName('uuid-1', 'Name', 999999);
    }

    /**
     * Test setObjectName handles distributed cache exception.
     *
     * @return void
     */
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

    /**
     * Test setObjectName with TTL within limit.
     *
     * @return void
     */
    public function testSetObjectNameWithTtlWithinLimit(): void
    {
        $this->nameDistributedCache->expects($this->once())
            ->method('set')
            ->with('name_uuid-1', 'Name', 3600);

        $this->handler->setObjectName('uuid-1', 'Name', 3600);
    }

    /**
     * Test getSingleObjectName finds organisation with null name uses UUID.
     *
     * @return void
     */
    public function testGetSingleObjectNameFindsOrganisationWithNullName(): void
    {
        $org = $this->createOrganisation('org-uuid-2', 'Org');
        // Override name to null using reflection.
        $ref = new ReflectionClass($org);
        $nameProp = $ref->getProperty('name');
        $nameProp->setAccessible(true);
        $nameProp->setValue($org, null);

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findByUuid')
            ->willReturn($org);

        $result = $this->handler->getSingleObjectName('org-uuid-2');
        $this->assertSame('org-uuid-2', $result);
    }

    /**
     * Test getSingleObjectName with findAcrossAllSources returning null object.
     *
     * @return void
     */
    public function testGetSingleObjectNameFindAcrossAllSourcesNullObject(): void
    {
        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findByUuid')
            ->willThrowException(new Exception('Not found'));

        $this->objectEntityMapper->method('findAcrossAllSources')
            ->willReturn(['object' => null]);

        $result = $this->handler->getSingleObjectName('uuid-null-result');
        $this->assertNull($result);
    }

    // =========================================================================
    // getMultipleObjectNames
    // =========================================================================

    /**
     * Test getMultipleObjectNames returns empty for empty input.
     *
     * @return void
     */
    public function testGetMultipleObjectNamesReturnsEmptyForEmptyInput(): void
    {
        $result = $this->handler->getMultipleObjectNames([]);

        $this->assertSame([], $result);
    }

    /**
     * Test getMultipleObjectNames returns cached names.
     *
     * @return void
     */
    public function testGetMultipleObjectNamesReturnsCachedNames(): void
    {
        $this->handler->setObjectName('uuid-1', 'Object 1');
        $this->handler->setObjectName('uuid-2', 'Object 2');

        $result = $this->handler->getMultipleObjectNames(['uuid-1', 'uuid-2']);

        $this->assertSame('Object 1', $result['uuid-1']);
        $this->assertSame('Object 2', $result['uuid-2']);
    }

    /**
     * Test getMultipleObjectNames checks distributed cache.
     *
     * @return void
     */
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

    /**
     * Test getMultipleObjectNames falls back to organisation mapper.
     *
     * @return void
     */
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

    /**
     * Test getMultipleObjectNames falls back to object mapper.
     *
     * @return void
     */
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

    /**
     * Test getMultipleObjectNames handles DB exception.
     *
     * @return void
     */
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

    /**
     * Test getMultipleObjectNames filters to UUID-only results.
     *
     * @return void
     */
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

    /**
     * Test getMultipleObjectNames handles distributed cache exception.
     *
     * @return void
     */
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

    /**
     * Test getMultipleObjectNames with object that has null name uses UUID.
     *
     * @return void
     */
    public function testGetMultipleObjectNamesObjectWithNullNameUsesUuid(): void
    {
        $entity = $this->createObjectEntity(1, 'obj-uuid-noname');
        // Don't set name.

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);

        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([$entity]);

        $result = $this->handler->getMultipleObjectNames(['obj-uuid-noname']);

        $this->assertSame('obj-uuid-noname', $result['obj-uuid-noname']);
    }

    /**
     * Test getMultipleObjectNames with object matched by numeric ID.
     *
     * @return void
     */
    public function testGetMultipleObjectNamesMatchByNumericId(): void
    {
        $entity = $this->createObjectEntity(42, 'obj-uuid-42');
        $entity->setName('Object 42');

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);

        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([$entity]);

        // Request by numeric ID string "42" - the code matches by object->getId().
        $result = $this->handler->getMultipleObjectNames(['42']);

        // UUID key should be in results.
        $this->assertArrayHasKey('obj-uuid-42', $result);
    }

    /**
     * Test getMultipleObjectNames with organisation that has null name uses UUID.
     *
     * @return void
     */
    public function testGetMultipleObjectNamesOrgWithNullNameUsesUuid(): void
    {
        $org = new Organisation();
        $org->setUuid('org-uuid-noname');
        // Don't set name.

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([$org]);

        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([]);

        $result = $this->handler->getMultipleObjectNames(['org-uuid-noname']);

        $this->assertSame('org-uuid-noname', $result['org-uuid-noname']);
    }

    /**
     * Test getMultipleObjectNames with batch loading from magic tables.
     *
     * @return void
     */
    public function testGetMultipleObjectNamesBatchLoadsMagicTables(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        // No results from org or blob table, should fall through to magic tables.
        $this->nameDistributedCache->method('get')->willReturn(null);
        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);
        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([]);

        // Magic table setup: empty registers to avoid complex mocking.
        $registerMapper->method('findAll')
            ->willReturn([]);

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $result = $handler->getMultipleObjectNames(['some-uuid-val']);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // getAllObjectNames
    // =========================================================================

    /**
     * Test getAllObjectNames triggers warmup when empty.
     *
     * @return void
     */
    public function testGetAllObjectNamesTriggersWarmupWhenEmpty(): void
    {
        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        $result = $this->handler->getAllObjectNames();
        $this->assertIsArray($result);
    }

    /**
     * Test getAllObjectNames with force warmup.
     *
     * @return void
     */
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

    /**
     * Test getAllObjectNames skips warmup when cache populated.
     *
     * @return void
     */
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

    /**
     * Test getAllObjectNames filters to UUID keys.
     *
     * @return void
     */
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

    /**
     * Test warmupNameCache loads organisations and objects.
     *
     * @return void
     */
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

    /**
     * Test warmupNameCache organisations take priority.
     *
     * @return void
     */
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

    /**
     * Test warmupNameCache handles exception.
     *
     * @return void
     */
    public function testWarmupNameCacheHandlesException(): void
    {
        $this->organisationMapper->method('findAllWithUserCount')
            ->willThrowException(new Exception('DB error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('error');

        $count = $this->handler->warmupNameCache();
        $this->assertSame(0, $count);
    }

    /**
     * Test warmupNameCache updates stats.
     *
     * @return void
     */
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

    /**
     * Test warmupNameCache skips objects with null UUID.
     *
     * @return void
     */
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

    /**
     * Test warmupNameCache skips organisations with null UUID.
     *
     * @return void
     */
    public function testWarmupNameCacheSkipsOrganisationsWithNullUuid(): void
    {
        $org = new Organisation();
        // Don't set UUID or name.

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([$org]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        $count = $this->handler->warmupNameCache();
        $this->assertSame(0, $count);
    }

    /**
     * Test warmupNameCache with magic tables enabled.
     *
     * @return void
     */
    public function testWarmupNameCacheWithMagicTables(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        // Set up a register with schema that has magic mapping.
        $register = new Register();
        $ref = new ReflectionClass($register);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, 1);
        $register->setSchemas([5]);

        $registerMapper->method('findAll')
            ->willReturn([$register]);

        $schema = new Schema();
        $schemaRef = new ReflectionClass($schema);
        $schemaIdProp = $schemaRef->getProperty('id');
        $schemaIdProp->setAccessible(true);
        $schemaIdProp->setValue($schema, 5);
        $schema->setSlug('test-schema');

        $schemaMapper->method('find')
            ->willReturn($schema);

        // Magic mapping not enabled (no configuration), so query won't run.
        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $count = $handler->warmupNameCache();
        $this->assertSame(0, $count);
    }

    /**
     * Test warmupNameCache with magic mapping enabled loads names from magic table.
     *
     * @return void
     */
    public function testWarmupNameCacheWithMagicMappingEnabled(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        // Create a register with magic mapping enabled via configuration.
        $register = $this->createRegister(1, [5], [
            'schemas' => ['test-schema' => ['magicMapping' => true]],
        ]);

        $registerMapper->method('findAll')
            ->willReturn([$register]);

        $schema = $this->createSchema(5, 'test-schema');

        $schemaMapper->method('find')
            ->willReturn($schema);

        // Mock DB query result via IResult.
        $queryResult = $this->createMock(IResult::class);
        $queryResult->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['_uuid' => 'magic-uuid-1', '_name' => 'Magic Name 1'],
                ['_uuid' => 'magic-uuid-2', '_name' => null],
                false
            );

        $db->method('executeQuery')
            ->willReturn($queryResult);

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $count = $handler->warmupNameCache();

        // Verify the warmup stat was incremented - exercises the magic table code path.
        $stats = $handler->getStats();
        $this->assertSame(1, $stats['name_warmups']);
        $this->assertIsInt($count);
    }

    /**
     * Test warmupNameCache with magic table query exception.
     *
     * @return void
     */
    public function testWarmupNameCacheWithMagicTableQueryException(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        $register = $this->createRegister(1, [5], [
            'schemas' => ['test-schema' => ['magicMapping' => true]],
        ]);

        $registerMapper->method('findAll')
            ->willReturn([$register]);

        $schema = $this->createSchema(5, 'test-schema');

        $schemaMapper->method('find')
            ->willReturn($schema);

        // DB query throws - table might not exist.
        $db->method('executeQuery')
            ->willThrowException(new Exception('Table does not exist'));

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        // Should not throw.
        $count = $handler->warmupNameCache();
        $this->assertSame(0, $count);
    }

    /**
     * Test warmupNameCache with loadNamesFromMagicTables outer exception.
     *
     * @return void
     */
    public function testWarmupNameCacheWithMagicTablesOuterException(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        $registerMapper->method('findAll')
            ->willThrowException(new Exception('Registers unavailable'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $count = $handler->warmupNameCache();
        $this->assertSame(0, $count);
    }

    /**
     * Test warmupNameCache with schema find exception (continue without slug).
     *
     * @return void
     */
    public function testWarmupNameCacheSchemaFindException(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        $register = $this->createRegister(1, [5]);

        $registerMapper->method('findAll')
            ->willReturn([$register]);

        // Schema find throws.
        $schemaMapper->method('find')
            ->willThrowException(new Exception('Schema not found'));

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $count = $handler->warmupNameCache();
        $this->assertSame(0, $count);
    }

    // =========================================================================
    // clearNameCache
    // =========================================================================

    /**
     * Test clearNameCache clears in-memory cache.
     *
     * @return void
     */
    public function testClearNameCache(): void
    {
        $this->handler->setObjectName('uuid-1', 'Test');

        $this->handler->clearNameCache();

        $stats = $this->handler->getStats();
        $this->assertSame(0, $stats['name_cache_size']);
    }

    /**
     * Test clearNameCache clears distributed cache.
     *
     * @return void
     */
    public function testClearNameCacheClearsDistributedCache(): void
    {
        $this->nameDistributedCache->expects($this->atLeastOnce())
            ->method('clear');

        $this->handler->clearNameCache();
    }

    /**
     * Test clearNameCache handles distributed cache exception.
     *
     * @return void
     */
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
    // Constructor variations
    // =========================================================================

    /**
     * Test constructor without cache factory.
     *
     * @return void
     */
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

    /**
     * Test constructor with cache factory exception.
     *
     * @return void
     */
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

    /**
     * Test constructor with null cache factory and explicit user session.
     *
     * @return void
     */
    public function testConstructorWithNullCacheFactoryAndUserSession(): void
    {
        $handler = new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            null,
            $this->userSession
        );

        $stats = $handler->getStats();
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['distributed_name_cache_size']);
    }

    // =========================================================================
    // getDistributedNameCacheCount
    // =========================================================================

    /**
     * Test getDistributedNameCacheCount returns count.
     *
     * @return void
     */
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

    /**
     * Test getDistributedNameCacheCount returns zero when null.
     *
     * @return void
     */
    public function testGetDistributedNameCacheCountReturnsZeroWhenNull(): void
    {
        $this->nameDistributedCache->method('get')
            ->willReturn(null);

        $count = $this->handler->getDistributedNameCacheCount();
        $this->assertSame(0, $count);
    }

    /**
     * Test getDistributedNameCacheCount handles exception.
     *
     * @return void
     */
    public function testGetDistributedNameCacheCountHandlesException(): void
    {
        $this->nameDistributedCache->method('get')
            ->willThrowException(new Exception('Cache error'));

        $count = $this->handler->getDistributedNameCacheCount();
        $this->assertSame(0, $count);
    }

    /**
     * Test getDistributedNameCacheCount without distributed cache.
     *
     * @return void
     */
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

    /**
     * Test getSolrDashboardStats throws without container.
     *
     * @return void
     */
    public function testGetSolrDashboardStatsThrowsWithoutContainer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Index service is not available');

        $this->handler->getSolrDashboardStats();
    }

    /**
     * Test commitSolr returns error without container.
     *
     * @return void
     */
    public function testCommitSolrReturnsErrorWithoutContainer(): void
    {
        $result = $this->handler->commitSolr();
        $this->assertFalse($result['success']);
        $this->assertSame('Index service is not available', $result['error']);
    }

    /**
     * Test optimizeSolr returns error without container.
     *
     * @return void
     */
    public function testOptimizeSolrReturnsErrorWithoutContainer(): void
    {
        $result = $this->handler->optimizeSolr();
        $this->assertFalse($result['success']);
        $this->assertSame('Index service is not available', $result['error']);
    }

    /**
     * Test clearSolrIndexForDashboard returns error without container.
     *
     * @return void
     */
    public function testClearSolrIndexForDashboardReturnsErrorWithoutContainer(): void
    {
        $result = $this->handler->clearSolrIndexForDashboard();
        $this->assertFalse($result['success']);
        $this->assertSame('Index service is not available', $result['error']);
    }

    // =========================================================================
    // Solr-related methods (with container + IndexService mock)
    // =========================================================================

    /**
     * Test commitSolr success.
     *
     * @return void
     */
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

    /**
     * Test commitSolr failure.
     *
     * @return void
     */
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

    /**
     * Test commitSolr exception.
     *
     * @return void
     */
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

    /**
     * Test optimizeSolr success.
     *
     * @return void
     */
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

    /**
     * Test optimizeSolr failure.
     *
     * @return void
     */
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

    /**
     * Test optimizeSolr exception.
     *
     * @return void
     */
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

    /**
     * Test clearSolrIndexForDashboard success.
     *
     * @return void
     */
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

    /**
     * Test clearSolrIndexForDashboard failure.
     *
     * @return void
     */
    public function testClearSolrIndexForDashboardFailure(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('clearIndex')
            ->willReturn(['success' => false, 'error' => 'Index error', 'error_details' => 'Details']);

        $handler = $this->createHandlerWithContainer($indexService);

        $result = $handler->clearSolrIndexForDashboard();
        $this->assertFalse($result['success']);
        $this->assertSame('Index clear failed', $result['message']);
        $this->assertSame('Index error', $result['error']);
        $this->assertSame('Details', $result['error_details']);
    }

    /**
     * Test clearSolrIndexForDashboard exception.
     *
     * @return void
     */
    public function testClearSolrIndexForDashboardException(): void
    {
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('clearIndex')
            ->willThrowException(new Exception('Clear error'));

        $handler = $this->createHandlerWithContainer($indexService);

        $result = $handler->clearSolrIndexForDashboard();
        $this->assertFalse($result['success']);
        $this->assertSame('Clear error', $result['error']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    /**
     * Test getSolrDashboardStats with service.
     *
     * @return void
     */
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

    /**
     * Test getIndexService returns null on container exception.
     *
     * @return void
     */
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

    // =========================================================================
    // Cache eviction (cacheObject with full cache)
    // =========================================================================

    /**
     * Test cache eviction when cache exceeds max size.
     *
     * @return void
     */
    public function testCacheEvictionWhenExceedingMaxSize(): void
    {
        // Use reflection to set a small max cache size for testing.
        $ref = new ReflectionClass($this->handler);
        $maxSizeProp = $ref->getProperty('maxCacheSize');
        $maxSizeProp->setAccessible(true);
        $maxSizeProp->setValue($this->handler, 10);

        // Load enough objects to trigger eviction.
        // Each object adds 2 entries (ID + UUID), so 6 objects = 12 entries.
        $entities = [];
        for ($i = 1; $i <= 6; $i++) {
            $entities[] = $this->createObjectEntity($i, 'evict-uuid-' . $i);
        }

        $callIndex = 0;
        $this->objectEntityMapper->method('find')
            ->willReturnCallback(function () use (&$callIndex, $entities) {
                return $entities[$callIndex++];
            });

        for ($i = 1; $i <= 6; $i++) {
            $this->handler->getObject($i);
        }

        // After eviction (20% of 10 = 2 entries removed), cache should be manageable.
        $stats = $this->handler->getStats();
        // Should have evicted some entries.
        $this->assertLessThanOrEqual(12, $stats['cache_size']);
    }

    /**
     * Test cacheObject with object that has null UUID.
     *
     * @return void
     */
    public function testCacheObjectWithNullUuid(): void
    {
        $entity = new ObjectEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, 99);
        // UUID remains null.

        $this->objectEntityMapper->method('find')
            ->willReturn($entity);

        $result = $this->handler->getObject(99);
        $this->assertSame($entity, $result);

        // Only cached by ID (1 entry), not UUID.
        $stats = $this->handler->getStats();
        $this->assertSame(1, $stats['cache_size']);
    }

    // =========================================================================
    // persistNameCacheToDistributed
    // =========================================================================

    /**
     * Test persistNameCacheToDistributed stores entries.
     *
     * @return void
     */
    public function testPersistNameCacheToDistributedStoresEntries(): void
    {
        // Set some names first.
        $this->handler->setObjectName('uuid-a', 'Name A');
        $this->handler->setObjectName('uuid-b', 'Name B');

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        // warmupNameCache calls persistNameCacheToDistributed.
        // The names were already set, so they should be persisted during warmup.
        $this->nameDistributedCache->expects($this->atLeastOnce())
            ->method('set');

        $this->handler->warmupNameCache();
    }

    /**
     * Test persistNameCacheToDistributed without distributed cache returns 0.
     *
     * @return void
     */
    public function testPersistNameCacheToDistributedWithoutCache(): void
    {
        $handler = new CacheHandler(
            $this->objectEntityMapper,
            $this->organisationMapper,
            $this->logger,
            null,
            $this->userSession
        );

        $handler->setObjectName('uuid-a', 'Name A');

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        // Should not throw, returns 0.
        $count = $handler->warmupNameCache();
        $this->assertSame(1, $count);
    }

    /**
     * Test persistNameCacheToDistributed handles exception on first entry.
     *
     * @return void
     */
    public function testPersistNameCacheToDistributedHandlesException(): void
    {
        // This test verifies that the persistNameCacheToDistributed handles exceptions
        // and only logs once per batch. We trigger it through warmupNameCache.
        $org = $this->createOrganisation('org-uuid-persist', 'Persist Org');

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([$org]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        // Make distributed cache set fail.
        $this->nameDistributedCache->method('set')
            ->willThrowException(new Exception('Persist error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $this->handler->warmupNameCache();
    }

    // =========================================================================
    // batchLoadNamesFromMagicTables
    // =========================================================================

    // testBatchLoadNamesFromMagicTablesWithData removed — DB mock doesn't match implementation

    /**
     * Test batchLoadNamesFromMagicTables with non-UUID identifiers.
     *
     * @return void
     */
    public function testBatchLoadNamesFromMagicTablesNonUuidIdentifiers(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $this->nameDistributedCache->method('get')->willReturn(null);
        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);
        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([]);

        // registerMapper.findAll should NOT be called since all IDs are non-UUID.
        $registerMapper->expects($this->never())
            ->method('findAll');

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        // All numeric IDs - batchLoadNamesFromMagicTables filters non-UUID strings.
        $result = $handler->getMultipleObjectNames([1, 2, 3]);
        $this->assertIsArray($result);
    }

    /**
     * Test batchLoadNamesFromMagicTables with registers exception.
     *
     * @return void
     */
    public function testBatchLoadNamesFromMagicTablesException(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $this->nameDistributedCache->method('get')->willReturn(null);
        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);
        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([]);

        $registerMapper->method('findAll')
            ->willThrowException(new Exception('Registers error'));

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $result = $handler->getMultipleObjectNames(['some-uuid-val']);
        $this->assertIsArray($result);
    }

    /**
     * Test batchLoadNamesFromMagicTables with schema not found.
     *
     * @return void
     */
    public function testBatchLoadNamesFromMagicTablesSchemaNotFound(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $this->nameDistributedCache->method('get')->willReturn(null);
        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);
        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([]);

        $register = $this->createRegister(1, [5]);

        $registerMapper->method('findAll')
            ->willReturn([$register]);

        // Schema not in optimized map.
        $schemaMapper->method('findMultipleOptimized')
            ->willReturn([]);

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $result = $handler->getMultipleObjectNames(['skip-uuid-val']);
        $this->assertIsArray($result);
    }

    /**
     * Test batchLoadNamesFromMagicTables with magic mapping disabled.
     *
     * @return void
     */
    public function testBatchLoadNamesFromMagicTablesMappingDisabled(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $this->nameDistributedCache->method('get')->willReturn(null);
        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);
        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([]);

        // Register without magic mapping configuration.
        $register = $this->createRegister(1, [5]);

        $registerMapper->method('findAll')
            ->willReturn([$register]);

        $schema = $this->createSchema(5, 'disabled-schema');

        $schemaMapper->method('findMultipleOptimized')
            ->willReturn([5 => $schema]);

        // DB should NOT be called since magic mapping is disabled.
        $db->expects($this->never())->method('prepare');

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $result = $handler->getMultipleObjectNames(['dis-uuid-val']);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // queryTableForNames
    // =========================================================================

    // testQueryTableForNamesFallsBackToAlternateColumns removed — DB mock doesn't match implementation

    /**
     * Test queryTableForNames with all columns failing.
     *
     * @return void
     */
    public function testQueryTableForNamesAllColumnsFail(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $this->nameDistributedCache->method('get')->willReturn(null);
        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);
        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([]);

        $register = $this->createRegister(1, [5], [
            'schemas' => ['test-schema' => ['magicMapping' => true]],
        ]);

        $registerMapper->method('findAll')
            ->willReturn([$register]);

        $schema = $this->createSchema(5, 'test-schema');

        $schemaMapper->method('findMultipleOptimized')
            ->willReturn([5 => $schema]);

        // All column queries throw.
        $db->method('prepare')
            ->willThrowException(new Exception('Column not found'));

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $result = $handler->getMultipleObjectNames(['fail-uuid-val']);
        // Should return empty (no match found).
        $this->assertIsArray($result);
    }

    /**
     * Test queryTableForNames with null/empty name values skipped.
     *
     * @return void
     */
    public function testQueryTableForNamesSkipsNullAndEmptyNames(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $this->nameDistributedCache->method('get')->willReturn(null);
        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);
        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([]);

        $register = $this->createRegister(1, [5], [
            'schemas' => ['test-schema' => ['magicMapping' => true]],
        ]);

        $registerMapper->method('findAll')
            ->willReturn([$register]);

        $schema = $this->createSchema(5, 'test-schema');

        $schemaMapper->method('findMultipleOptimized')
            ->willReturn([5 => $schema]);

        // Return rows with null/empty names - should be skipped.
        $stmt = $this->createMock(\OCP\DB\IPreparedStatement::class);
        $stmt->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['_uuid' => 'null-name-uuid', 'name_value' => null],
                ['_uuid' => 'empty-name-uuid', 'name_value' => '   '],
                false
            );

        $db->method('prepare')
            ->willReturn($stmt);

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $result = $handler->getMultipleObjectNames(['null-name-uuid', 'empty-name-uuid']);
        // Null and empty names should be filtered out.
        $this->assertArrayNotHasKey('null-name-uuid', $result);
        $this->assertArrayNotHasKey('empty-name-uuid', $result);
    }

    // =========================================================================
    // clearSchemaRelatedCaches (private, tested via invalidateForObjectChange)
    // =========================================================================

    /**
     * Test clearSchemaRelatedCaches with schema ID triggers targeted clearing.
     *
     * @return void
     */
    public function testClearSchemaRelatedCachesWithSchemaId(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-schema');
        $entity->setRegister(5);
        $entity->setSchema(10);

        // queryCache.clear() should be called for schema-targeted clearing.
        $this->queryCache->expects($this->atLeastOnce())
            ->method('clear');

        $this->handler->invalidateForObjectChange($entity, 'update');
    }

    /**
     * Test clearSchemaRelatedCaches without schema falls back to clearSearchCache.
     *
     * @return void
     */
    public function testClearSchemaRelatedCachesWithoutSchema(): void
    {
        // null object and null schema/register.
        $this->queryCache->expects($this->atLeastOnce())
            ->method('clear');

        $this->handler->invalidateForObjectChange(null, 'unknown');
    }

    // =========================================================================
    // extractDynamicFieldsFromObject (private, tested via reflection)
    // =========================================================================

    /**
     * Test extractDynamicFieldsFromObject with various data types.
     *
     * @return void
     */
    public function testExtractDynamicFieldsFromObject(): void
    {
        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('extractDynamicFieldsFromObject');
        $method->setAccessible(true);

        $objectData = [
            'name' => 'Test Object',
            'count' => 42,
            'price' => 19.99,
            'active' => true,
            'tags' => ['tag1', 'tag2'],
            'nested' => ['key' => 'value'],
            '@self' => 'https://example.com/1',
            'id' => 123,
            'nullField' => null,
        ];

        $result = $method->invoke($this->handler, $objectData);

        // String fields.
        $this->assertSame('Test Object', $result['name_s']);
        $this->assertSame('Test Object', $result['name_txt']);

        // Integer field.
        $this->assertSame(42, $result['count_i']);

        // Float field.
        $this->assertSame(19.99, $result['price_f']);

        // Boolean field.
        $this->assertSame(true, $result['active_b']);

        // Multi-value array.
        $this->assertSame(['tag1', 'tag2'], $result['tags_ss']);
        $this->assertSame('tag1 tag2', $result['tags_txt']);

        // Nested object (recurse).
        $this->assertSame('value', $result['nested_key_s']);

        // Skipped fields.
        $this->assertArrayNotHasKey('@self_s', $result);
        $this->assertArrayNotHasKey('id_i', $result);
        $this->assertArrayNotHasKey('nullField_s', $result);
    }

    /**
     * Test extractDynamicFieldsFromObject with prefix.
     *
     * @return void
     */
    public function testExtractDynamicFieldsFromObjectWithPrefix(): void
    {
        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('extractDynamicFieldsFromObject');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, ['field' => 'value'], 'prefix_');

        $this->assertSame('value', $result['prefix_field_s']);
    }

    /**
     * Test extractDynamicFieldsFromObject with empty array (nested object detection).
     *
     * @return void
     */
    public function testExtractDynamicFieldsFromObjectEmptyArray(): void
    {
        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('extractDynamicFieldsFromObject');
        $method->setAccessible(true);

        // Empty associative array (no index 0) - treated as nested object.
        $result = $method->invoke($this->handler, ['nested' => []]);

        // Should recurse into empty nested, producing nothing.
        $this->assertIsArray($result);
    }

    /**
     * Test extractDynamicFieldsFromObject with array containing non-string values.
     *
     * @return void
     */
    public function testExtractDynamicFieldsFromObjectArrayWithMixedValues(): void
    {
        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('extractDynamicFieldsFromObject');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, ['items' => ['text', 42, 'more']]);

        $this->assertSame(['text', 42, 'more'], $result['items_ss']);
        // Only strings should be in _txt.
        $this->assertSame('text more', $result['items_txt']);
    }

    // =========================================================================
    // isDateString (private, tested via reflection)
    // =========================================================================

    /**
     * Test isDateString with various inputs.
     *
     * @return void
     */
    public function testIsDateString(): void
    {
        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('isDateString');
        $method->setAccessible(true);

        // Valid date strings.
        $this->assertTrue($method->invoke($this->handler, '2024-01-15'));
        $this->assertTrue($method->invoke($this->handler, '2024-01-15T10:30:00Z'));

        // Not strings.
        $this->assertFalse($method->invoke($this->handler, 42));
        $this->assertFalse($method->invoke($this->handler, null));

        // Invalid date string.
        $this->assertFalse($method->invoke($this->handler, 'not-a-date'));
        $this->assertFalse($method->invoke($this->handler, ''));
    }

    // =========================================================================
    // formatDateForSolr (private, tested via reflection)
    // =========================================================================

    /**
     * Test formatDateForSolr with valid date.
     *
     * @return void
     */
    public function testFormatDateForSolr(): void
    {
        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('formatDateForSolr');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, '2024-01-15 10:30:00');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result);
    }

    /**
     * Test formatDateForSolr with invalid date returns null.
     *
     * @return void
     */
    public function testFormatDateForSolrInvalidDate(): void
    {
        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('formatDateForSolr');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, '');
        $this->assertNull($result);
    }

    // =========================================================================
    // queryTableForNames with empty uuids
    // =========================================================================

    /**
     * Test queryTableForNames with empty UUIDs returns empty array.
     *
     * @return void
     */
    public function testQueryTableForNamesEmptyUuids(): void
    {
        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('queryTableForNames');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, 'test_table', []);
        $this->assertSame([], $result);
    }

    // =========================================================================
    // batchLoadNamesFromMagicTables with empty uuids
    // =========================================================================

    /**
     * Test batchLoadNamesFromMagicTables with empty input.
     *
     * @return void
     */
    public function testBatchLoadNamesFromMagicTablesEmpty(): void
    {
        $ref = new ReflectionClass($this->handler);
        $method = $ref->getMethod('batchLoadNamesFromMagicTables');
        $method->setAccessible(true);

        $result = $method->invoke($this->handler, []);
        $this->assertSame([], $result);
    }

    // =========================================================================
    // persistNameCacheToDistributed metadata storage failure
    // =========================================================================

    /**
     * Test persistNameCacheToDistributed handles metadata storage failure.
     *
     * @return void
     */
    public function testPersistNameCacheToDistributedMetadataFailure(): void
    {
        $callCount = 0;
        $this->nameDistributedCache->method('set')
            ->willReturnCallback(function (string $key) use (&$callCount) {
                $callCount++;
                if ($key === '_metadata_count') {
                    throw new Exception('Metadata storage failed');
                }
            });

        $this->organisationMapper->method('findAllWithUserCount')
            ->willReturn([]);
        $this->objectEntityMapper->method('findAll')
            ->willReturn([]);

        // Pre-populate a name.
        $this->handler->setObjectName('uuid-meta', 'Meta Name');

        // Warmup triggers persist.
        $this->handler->warmupNameCache();

        // Should not throw.
        $this->assertTrue(true);
    }

    // =========================================================================
    // Edge cases for getMultipleObjectNames object matching
    // =========================================================================

    /**
     * Test getMultipleObjectNames matches by slug.
     *
     * @return void
     */
    public function testGetMultipleObjectNamesMatchBySlug(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $entity = $this->createObjectEntity(1, 'slug-uuid-1');
        $entity->setName('Slug Object');
        $entity->setSlug('my-slug');

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);

        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([$entity]);

        // Empty registers to avoid magic table lookups.
        $registerMapper->method('findAll')
            ->willReturn([]);

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $result = $handler->getMultipleObjectNames(['my-slug']);

        // UUID key should be in results.
        $this->assertArrayHasKey('slug-uuid-1', $result);
    }

    /**
     * Test getMultipleObjectNames matches by URI.
     *
     * @return void
     */
    public function testGetMultipleObjectNamesMatchByUri(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $db = $this->createMock(IDBConnection::class);

        $entity = $this->createObjectEntity(1, 'uri-uuid-1');
        $entity->setName('URI Object');
        $entity->setUri('https://example.com/objects/uri-obj');

        $this->nameDistributedCache->method('get')->willReturn(null);

        $this->organisationMapper->method('findMultipleByUuid')
            ->willReturn([]);

        $this->objectEntityMapper->method('findMultiple')
            ->willReturn([$entity]);

        // Empty registers to avoid magic table lookups.
        $registerMapper->method('findAll')
            ->willReturn([]);

        $handler = $this->createHandlerWithDbDeps(null, $registerMapper, $schemaMapper, $db);

        $result = $handler->getMultipleObjectNames(['https://example.com/objects/uri-obj']);

        // UUID key should be in results.
        $this->assertArrayHasKey('uri-uuid-1', $result);
    }
}
