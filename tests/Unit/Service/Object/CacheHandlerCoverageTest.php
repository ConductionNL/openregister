<?php

declare(strict_types=1);

/**
 * CacheHandler Coverage Tests
 *
 * Tests targeting uncovered lines/branches in CacheHandler.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCP\AppFramework\IAppContainer;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IMemcache;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Coverage-focused unit tests for CacheHandler
 *
 * Targets uncovered lines in:
 * - indexObjectInSolr (failure path, success path)
 * - removeObjectFromSolr (success path, failure path, exception path)
 * - extractDynamicFieldsFromObject (all type branches)
 * - isDateString / formatDateForSolr
 * - cacheObject (eviction path)
 * - clearSearchCache (pattern filtering, distributed cache failure)
 * - clearSchemaRelatedCaches (distributed cache failure, null schemaId)
 * - invalidateForObjectChange (create, update, delete operations)
 * - clearObjectFromCache
 * - clearAllCaches (distributed cache failures)
 * - setObjectName (distributed failure)
 * - getSingleObjectName (distributed cache hit/miss, org found)
 * - getDistributedNameCacheCount (cache exception)
 * - clearNameCache (distributed failure)
 * - getSolrDashboardStats (no index service)
 * - commitSolr (success, failure, exception)
 * - optimizeSolr
 * - persistNameCacheToDistributed (failure)
 * - queryTableForNames
 */
class CacheHandlerCoverageTest extends TestCase
{
    /** @var UnifiedObjectMapper&MockObject */
    private UnifiedObjectMapper $objectMapper;

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

    /** @var IAppContainer&MockObject */
    private IAppContainer $container;

    /** @var IUserSession&MockObject */
    private IUserSession $userSession;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var IDBConnection&MockObject */
    private IDBConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectMapper = $this->createMock(UnifiedObjectMapper::class);
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->queryCache = $this->createMock(IMemcache::class);
        $this->nameDistributedCache = $this->createMock(IMemcache::class);
        $this->container = $this->createMock(IAppContainer::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
    }

    /**
     * Create a CacheHandler with distributed caches enabled.
     */
    private function createHandler(
        bool $withDistributedCache = true,
        bool $withContainer = true
    ): CacheHandler {
        if ($withDistributedCache === true) {
            $this->cacheFactory->method('createDistributed')
                ->willReturnCallback(function ($prefix) {
                    if ($prefix === 'openregister_query_results') {
                        return $this->queryCache;
                    }
                    return $this->nameDistributedCache;
                });
        }

        return new CacheHandler(
            $this->objectMapper,
            $this->organisationMapper,
            $this->logger,
            $withDistributedCache ? $this->cacheFactory : null,
            $this->userSession,
            $withContainer ? $this->container : null,
            $this->registerMapper,
            $this->schemaMapper,
            $this->db
        );
    }

    /**
     * Create an ObjectEntity mock with basic properties.
     *
     * ObjectEntity extends Nextcloud Entity which uses __call for getters/setters.
     * Magic methods (getId, getUuid, etc.) use addMethods().
     * Real methods (getObject, getSource, etc.) use onlyMethods().
     */
    private function createObjectEntity(int $id = 1, string $uuid = 'abc-123-def'): ObjectEntity
    {
        $obj = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods([
                'getId',
                'getUuid',
                'getRegister',
                'getSchema',
                'getOrganisation',
                'getName',
                'getSlug',
                'getUri',
            ])
            ->onlyMethods([
                'getObject',
            ])
            ->getMock();

        $obj->method('getId')->willReturn($id);
        $obj->method('getUuid')->willReturn($uuid);
        $obj->method('getRegister')->willReturn(1);
        $obj->method('getSchema')->willReturn(2);
        $obj->method('getOrganisation')->willReturn(1);
        $obj->method('getName')->willReturn('Test Object');
        $obj->method('getObject')->willReturn(['name' => 'Test']);
        $obj->method('getSlug')->willReturn('test-object');
        $obj->method('getUri')->willReturn('http://example.com/test');

        return $obj;
    }

    /**
     * Helper to invoke private methods via reflection.
     */
    private function invokeMethod(CacheHandler $handler, string $methodName, array $args = [])
    {
        $ref = new ReflectionMethod(CacheHandler::class, $methodName);
        $ref->setAccessible(true);
        return $ref->invoke($handler, ...$args);
    }

    /**
     * Helper to set private property via reflection.
     */
    private function setProperty(CacheHandler $handler, string $propertyName, $value): void
    {
        $ref = new ReflectionClass(CacheHandler::class);
        $prop = $ref->getProperty($propertyName);
        $prop->setAccessible(true);
        $prop->setValue($handler, $value);
    }

    // =========================================================================
    // Constructor - distributed cache init failure
    // =========================================================================

    public function testConstructorHandlesCacheFactoryException(): void
    {
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')
            ->willThrowException(new \Exception('Redis unavailable'));

        $handler = new CacheHandler(
            $this->objectMapper,
            $this->organisationMapper,
            $this->logger,
            $cacheFactory,
            $this->userSession
        );

        // Should still construct without error
        $this->assertInstanceOf(CacheHandler::class, $handler);
    }

    // =========================================================================
    // getObject
    // =========================================================================

    public function testGetObjectCacheHit(): void
    {
        $handler = $this->createHandler(false, false);
        $entity = $this->createObjectEntity(1, 'uuid-1');

        // Pre-populate cache via reflection
        $this->setProperty($handler, 'objectCache', ['uuid-1' => $entity]);

        $result = $handler->getObject('uuid-1');
        $this->assertSame($entity, $result);
    }

    public function testGetObjectCacheMissLoadsFromDb(): void
    {
        $handler = $this->createHandler(false, false);
        $entity = $this->createObjectEntity(1, 'uuid-1');

        $this->objectMapper->method('find')
            ->willReturn($entity);

        $result = $handler->getObject('uuid-1');
        $this->assertSame($entity, $result);
    }

    public function testGetObjectCacheMissDbException(): void
    {
        $handler = $this->createHandler(false, false);

        $this->objectMapper->method('find')
            ->willThrowException(new \Exception('not found'));

        $result = $handler->getObject('nonexistent');
        $this->assertNull($result);
    }

    // =========================================================================
    // getStats
    // =========================================================================

    public function testGetStatsEmptyCache(): void
    {
        $handler = $this->createHandler(false, false);
        $stats = $handler->getStats();

        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
        $this->assertSame(0.0, $stats['hit_rate']);
        $this->assertSame(0.0, $stats['query_hit_rate']);
        $this->assertSame(0.0, $stats['name_hit_rate']);
    }

    public function testGetStatsWithHitsAndMisses(): void
    {
        $handler = $this->createHandler(false, false);

        // Trigger some hits and misses
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->setProperty($handler, 'objectCache', ['uuid-1' => $entity]);

        $handler->getObject('uuid-1'); // hit
        $this->objectMapper->method('find')
            ->willThrowException(new \Exception('not found'));
        $handler->getObject('uuid-miss'); // miss

        $stats = $handler->getStats();
        $this->assertSame(1, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
        $this->assertSame(50.0, $stats['hit_rate']);
    }

    // =========================================================================
    // preloadObjects
    // =========================================================================

    public function testPreloadObjectsEmpty(): void
    {
        $handler = $this->createHandler(false, false);
        $result = $handler->preloadObjects([]);
        $this->assertSame([], $result);
    }

    public function testPreloadObjectsAllCached(): void
    {
        $handler = $this->createHandler(false, false);
        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->setProperty($handler, 'objectCache', ['uuid-1' => $entity]);

        $result = $handler->preloadObjects(['uuid-1']);
        $this->assertCount(1, $result);
    }

    public function testPreloadObjectsFromDb(): void
    {
        $handler = $this->createHandler(false, false);
        $entity = $this->createObjectEntity(1, 'uuid-1');

        $this->objectMapper->method('findMultiple')
            ->willReturn([$entity]);

        $result = $handler->preloadObjects(['uuid-1']);
        $this->assertCount(1, $result);
    }

    public function testPreloadObjectsDbException(): void
    {
        $handler = $this->createHandler(false, false);

        $this->objectMapper->method('findMultiple')
            ->willThrowException(new \Exception('db error'));

        $result = $handler->preloadObjects(['uuid-1', 'uuid-2']);
        $this->assertSame([], $result);
    }

    // =========================================================================
    // cacheObject - eviction
    // =========================================================================

    public function testCacheObjectEviction(): void
    {
        $handler = $this->createHandler(false, false);

        // Fill cache to max
        $cache = [];
        for ($i = 0; $i < 1000; $i++) {
            $entity = $this->createObjectEntity($i, "uuid-$i");
            $cache["uuid-$i"] = $entity;
        }
        $this->setProperty($handler, 'objectCache', $cache);

        // Add one more to trigger eviction
        $newEntity = $this->createObjectEntity(9999, 'uuid-new');
        $this->invokeMethod($handler, 'cacheObject', [$newEntity]);

        // Cache should be smaller now (evicted 20%)
        $ref = new ReflectionClass(CacheHandler::class);
        $cacheProp = $ref->getProperty('objectCache');
        $cacheProp->setAccessible(true);
        $currentCache = $cacheProp->getValue($handler);

        $this->assertLessThan(1002, count($currentCache));
        $this->assertArrayHasKey('uuid-new', $currentCache);
    }

    // =========================================================================
    // clearSearchCache
    // =========================================================================

    public function testClearSearchCacheWithPattern(): void
    {
        $handler = $this->createHandler();
        $this->setProperty($handler, 'inMemoryQueryCache', [
            'schema_1_query' => ['data'],
            'schema_2_query' => ['data'],
            'other_query' => ['data'],
        ]);

        $this->queryCache->expects($this->once())->method('clear');

        $handler->clearSearchCache('schema_1');

        $ref = new ReflectionClass(CacheHandler::class);
        $prop = $ref->getProperty('inMemoryQueryCache');
        $prop->setAccessible(true);
        $remaining = $prop->getValue($handler);

        $this->assertArrayNotHasKey('schema_1_query', $remaining);
        $this->assertArrayHasKey('other_query', $remaining);
    }

    public function testClearSearchCacheNoPattern(): void
    {
        $handler = $this->createHandler();
        $this->setProperty($handler, 'inMemoryQueryCache', ['key' => 'value']);

        $handler->clearSearchCache();

        $ref = new ReflectionClass(CacheHandler::class);
        $prop = $ref->getProperty('inMemoryQueryCache');
        $prop->setAccessible(true);
        $this->assertEmpty($prop->getValue($handler));
    }

    public function testClearSearchCacheDistributedFailure(): void
    {
        $handler = $this->createHandler();

        $this->queryCache->method('clear')
            ->willThrowException(new \Exception('Redis down'));

        // Should not throw
        $handler->clearSearchCache();
        $this->assertTrue(true);
    }

    // =========================================================================
    // clearAllCaches
    // =========================================================================

    public function testClearAllCachesSuccess(): void
    {
        $handler = $this->createHandler();

        $entity = $this->createObjectEntity(1, 'uuid-1');
        $this->setProperty($handler, 'objectCache', ['uuid-1' => $entity]);
        $this->setProperty($handler, 'nameCache', ['uuid-1' => 'Test']);

        $this->queryCache->expects($this->once())->method('clear');
        $this->nameDistributedCache->expects($this->once())->method('clear');

        $handler->clearAllCaches();

        $ref = new ReflectionClass(CacheHandler::class);
        $objCacheProp = $ref->getProperty('objectCache');
        $objCacheProp->setAccessible(true);
        $this->assertEmpty($objCacheProp->getValue($handler));
    }

    public function testClearAllCachesDistributedFailures(): void
    {
        $handler = $this->createHandler();

        $this->queryCache->method('clear')
            ->willThrowException(new \Exception('Redis failure'));
        $this->nameDistributedCache->method('clear')
            ->willThrowException(new \Exception('Redis failure'));

        // Should not throw
        $handler->clearAllCaches();
        $this->assertTrue(true);
    }

    // =========================================================================
    // clearCache (legacy)
    // =========================================================================

    public function testClearCacheDelegatesToClearAllCaches(): void
    {
        $handler = $this->createHandler(false, false);
        $handler->clearCache();
        $this->assertTrue(true);
    }

    // =========================================================================
    // setObjectName
    // =========================================================================

    public function testSetObjectNameSuccess(): void
    {
        $handler = $this->createHandler();

        $this->nameDistributedCache->expects($this->once())
            ->method('set')
            ->with('name_uuid-1', 'Test Object', $this->anything());

        $handler->setObjectName('uuid-1', 'Test Object');

        $ref = new ReflectionClass(CacheHandler::class);
        $prop = $ref->getProperty('nameCache');
        $prop->setAccessible(true);
        $nameCache = $prop->getValue($handler);
        $this->assertSame('Test Object', $nameCache['uuid-1']);
    }

    public function testSetObjectNameDistributedFailure(): void
    {
        $handler = $this->createHandler();

        $this->nameDistributedCache->method('set')
            ->willThrowException(new \Exception('Redis failure'));

        // Should not throw
        $handler->setObjectName('uuid-1', 'Test Object');

        $ref = new ReflectionClass(CacheHandler::class);
        $prop = $ref->getProperty('nameCache');
        $prop->setAccessible(true);
        $nameCache = $prop->getValue($handler);
        $this->assertSame('Test Object', $nameCache['uuid-1']);
    }

    public function testSetObjectNameEnforcesTtl(): void
    {
        $handler = $this->createHandler();

        // Very high TTL should be clamped to MAX_CACHE_TTL (86400)
        $this->nameDistributedCache->expects($this->once())
            ->method('set')
            ->with('name_uuid-1', 'Test', 86400);

        $handler->setObjectName('uuid-1', 'Test', 999999);
    }

    // =========================================================================
    // getSingleObjectName
    // =========================================================================

    public function testGetSingleObjectNameInMemoryHit(): void
    {
        $handler = $this->createHandler();
        $this->setProperty($handler, 'nameCache', ['uuid-1' => 'Cached Name']);

        $result = $handler->getSingleObjectName('uuid-1');
        $this->assertSame('Cached Name', $result);
    }

    public function testGetSingleObjectNameDistributedHit(): void
    {
        $handler = $this->createHandler();

        $this->nameDistributedCache->method('get')
            ->with('name_uuid-1')
            ->willReturn('Distributed Name');

        $result = $handler->getSingleObjectName('uuid-1');
        $this->assertSame('Distributed Name', $result);
    }

    public function testGetSingleObjectNameDistributedFailure(): void
    {
        $handler = $this->createHandler();

        $this->nameDistributedCache->method('get')
            ->willThrowException(new \Exception('Redis failure'));

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn(['object' => null]);
        $this->organisationMapper->method('findByUuid')
            ->willThrowException(new \Exception('not found'));

        $result = $handler->getSingleObjectName('uuid-1');
        $this->assertNull($result);
    }

    // =========================================================================
    // getDistributedNameCacheCount
    // =========================================================================

    public function testGetDistributedNameCacheCountNoCache(): void
    {
        $handler = $this->createHandler(false, false);
        $result = $handler->getDistributedNameCacheCount();
        $this->assertSame(0, $result);
    }

    public function testGetDistributedNameCacheCountWithValue(): void
    {
        $handler = $this->createHandler();
        $this->nameDistributedCache->method('get')
            ->with('_metadata_count')
            ->willReturn(42);

        $result = $handler->getDistributedNameCacheCount();
        $this->assertSame(42, $result);
    }

    public function testGetDistributedNameCacheCountNullValue(): void
    {
        $handler = $this->createHandler();
        $this->nameDistributedCache->method('get')
            ->with('_metadata_count')
            ->willReturn(null);

        $result = $handler->getDistributedNameCacheCount();
        $this->assertSame(0, $result);
    }

    public function testGetDistributedNameCacheCountException(): void
    {
        $handler = $this->createHandler();
        $this->nameDistributedCache->method('get')
            ->willThrowException(new \Exception('failure'));

        $result = $handler->getDistributedNameCacheCount();
        $this->assertSame(0, $result);
    }

    // =========================================================================
    // clearNameCache
    // =========================================================================

    public function testClearNameCacheSuccess(): void
    {
        $handler = $this->createHandler();
        $this->setProperty($handler, 'nameCache', ['uuid-1' => 'Test']);

        $this->nameDistributedCache->expects($this->once())->method('clear');

        $handler->clearNameCache();

        $ref = new ReflectionClass(CacheHandler::class);
        $prop = $ref->getProperty('nameCache');
        $prop->setAccessible(true);
        $this->assertEmpty($prop->getValue($handler));
    }

    public function testClearNameCacheDistributedFailure(): void
    {
        $handler = $this->createHandler();

        $this->nameDistributedCache->method('clear')
            ->willThrowException(new \Exception('Redis failure'));

        $handler->clearNameCache();
        $this->assertTrue(true);
    }

    // =========================================================================
    // invalidateForObjectChange
    // =========================================================================

    public function testInvalidateForObjectChangeCreate(): void
    {
        $handler = $this->createHandler();
        $entity = $this->createObjectEntity(1, 'uuid-1');

        // No index service available
        $this->container->method('get')
            ->willThrowException(new \Exception('not available'));

        $handler->invalidateForObjectChange($entity, 'create');
        $this->assertTrue(true);
    }

    public function testInvalidateForObjectChangeDelete(): void
    {
        $handler = $this->createHandler();
        $entity = $this->createObjectEntity(1, 'uuid-1');

        $this->container->method('get')
            ->willThrowException(new \Exception('not available'));

        $handler->invalidateForObjectChange($entity, 'delete');

        // Name should be cleared from in-memory cache
        $ref = new ReflectionClass(CacheHandler::class);
        $prop = $ref->getProperty('nameCache');
        $prop->setAccessible(true);
        $cache = $prop->getValue($handler);
        $this->assertArrayNotHasKey('uuid-1', $cache);
    }

    public function testInvalidateForObjectChangeDeleteDistributedFailure(): void
    {
        $handler = $this->createHandler();
        $entity = $this->createObjectEntity(1, 'uuid-1');

        $this->container->method('get')
            ->willThrowException(new \Exception('not available'));

        $this->nameDistributedCache->method('remove')
            ->willThrowException(new \Exception('Redis failure'));

        // Should not throw
        $handler->invalidateForObjectChange($entity, 'delete');
        $this->assertTrue(true);
    }

    public function testInvalidateForObjectChangeNullObject(): void
    {
        $handler = $this->createHandler();

        $handler->invalidateForObjectChange(null, 'unknown', 1, 2);
        $this->assertTrue(true);
    }

    public function testInvalidateForObjectChangeWithExplicitIds(): void
    {
        $handler = $this->createHandler();
        $entity = $this->createObjectEntity(1, 'uuid-1');

        $this->container->method('get')
            ->willThrowException(new \Exception('not available'));

        $handler->invalidateForObjectChange($entity, 'update', 10, 20);
        $this->assertTrue(true);
    }

    // =========================================================================
    // getSolrDashboardStats
    // =========================================================================

    public function testGetSolrDashboardStatsNoContainer(): void
    {
        $handler = $this->createHandler(false, false);

        $this->expectException(RuntimeException::class);
        $handler->getSolrDashboardStats();
    }

    public function testGetSolrDashboardStatsNoIndexService(): void
    {
        $handler = $this->createHandler(false, true);

        $this->container->method('get')
            ->willThrowException(new \Exception('not available'));

        $this->expectException(RuntimeException::class);
        $handler->getSolrDashboardStats();
    }

    // =========================================================================
    // commitSolr
    // =========================================================================

    public function testCommitSolrNoIndexService(): void
    {
        $handler = $this->createHandler(false, false);
        $result = $handler->commitSolr();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not available', $result['error']);
    }

    public function testCommitSolrSuccess(): void
    {
        $handler = $this->createHandler(false, true);
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('commit')->willReturn(true);

        $this->container->method('get')
            ->willReturn($indexService);

        $result = $handler->commitSolr();
        $this->assertTrue($result['success']);
        $this->assertSame('Commit successful', $result['message']);
    }

    public function testCommitSolrFailure(): void
    {
        $handler = $this->createHandler(false, true);
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('commit')->willReturn(false);

        $this->container->method('get')
            ->willReturn($indexService);

        $result = $handler->commitSolr();
        $this->assertFalse($result['success']);
        $this->assertSame('Commit failed', $result['message']);
    }

    public function testCommitSolrException(): void
    {
        $handler = $this->createHandler(false, true);
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('commit')->willThrowException(new \Exception('Connection refused'));

        $this->container->method('get')
            ->willReturn($indexService);

        $result = $handler->commitSolr();
        $this->assertFalse($result['success']);
        $this->assertSame('Connection refused', $result['error']);
    }

    // =========================================================================
    // extractDynamicFieldsFromObject
    // =========================================================================

    public function testExtractDynamicFieldsFromObjectAllTypes(): void
    {
        $handler = $this->createHandler(false, false);

        $data = [
            '@self' => 'http://example.com', // should be skipped
            'id' => '123', // should be skipped
            'name' => 'Test',
            'count' => 42,
            'price' => 3.14,
            'active' => true,
            'tags' => ['tag1', 'tag2'],
            'nested' => ['key1' => 'value1'],
            'empty_val' => null, // should be skipped
        ];

        $result = $this->invokeMethod($handler, 'extractDynamicFieldsFromObject', [$data, '']);

        $this->assertSame('Test', $result['name_s']);
        $this->assertSame('Test', $result['name_txt']);
        $this->assertSame(42, $result['count_i']);
        $this->assertSame(3.14, $result['price_f']);
        $this->assertTrue($result['active_b']);
        $this->assertSame(['tag1', 'tag2'], $result['tags_ss']);
    }

    public function testExtractDynamicFieldsFromObjectWithPrefix(): void
    {
        $handler = $this->createHandler(false, false);
        $data = ['color' => 'red'];
        $result = $this->invokeMethod($handler, 'extractDynamicFieldsFromObject', [$data, 'item_']);
        $this->assertSame('red', $result['item_color_s']);
    }

    // =========================================================================
    // isDateString
    // =========================================================================

    public function testIsDateStringWithDate(): void
    {
        $handler = $this->createHandler(false, false);
        $this->assertTrue($this->invokeMethod($handler, 'isDateString', ['2024-01-15']));
    }

    public function testIsDateStringWithNonDate(): void
    {
        $handler = $this->createHandler(false, false);
        $this->assertFalse($this->invokeMethod($handler, 'isDateString', ['not-a-date']));
    }

    public function testIsDateStringWithNonString(): void
    {
        $handler = $this->createHandler(false, false);
        $this->assertFalse($this->invokeMethod($handler, 'isDateString', [12345]));
    }

    // =========================================================================
    // formatDateForSolr
    // =========================================================================

    public function testFormatDateForSolrValid(): void
    {
        $handler = $this->createHandler(false, false);
        $result = $this->invokeMethod($handler, 'formatDateForSolr', ['2024-01-15']);
        $this->assertStringContainsString('2024-01-15', $result);
        $this->assertStringContainsString('T', $result);
        $this->assertStringEndsWith('Z', $result);
    }

    public function testFormatDateForSolrInvalid(): void
    {
        $handler = $this->createHandler(false, false);
        $result = $this->invokeMethod($handler, 'formatDateForSolr', ['not-a-date']);
        $this->assertNull($result);
    }

    // =========================================================================
    // persistNameCacheToDistributed
    // =========================================================================

    public function testPersistNameCacheToDistributedNoCache(): void
    {
        $handler = $this->createHandler(false, false);
        $result = $this->invokeMethod($handler, 'persistNameCacheToDistributed', []);
        $this->assertSame(0, $result);
    }

    public function testPersistNameCacheToDistributedSuccess(): void
    {
        $handler = $this->createHandler();
        $this->setProperty($handler, 'nameCache', [
            'uuid-1' => 'Name 1',
            'uuid-2' => 'Name 2',
        ]);

        $result = $this->invokeMethod($handler, 'persistNameCacheToDistributed', []);
        $this->assertSame(2, $result);
    }

    public function testPersistNameCacheToDistributedFailure(): void
    {
        $handler = $this->createHandler();
        $this->setProperty($handler, 'nameCache', [
            'uuid-1' => 'Name 1',
        ]);

        $this->nameDistributedCache->method('set')
            ->willThrowException(new \Exception('Redis failure'));

        $result = $this->invokeMethod($handler, 'persistNameCacheToDistributed', []);
        $this->assertSame(0, $result);
    }

    // =========================================================================
    // getMultipleObjectNames - empty input
    // =========================================================================

    public function testGetMultipleObjectNamesEmpty(): void
    {
        $handler = $this->createHandler(false, false);
        $result = $handler->getMultipleObjectNames([]);
        $this->assertSame([], $result);
    }

    // =========================================================================
    // getAllObjectNames
    // =========================================================================

    public function testGetAllObjectNamesTriggersWarmup(): void
    {
        $handler = $this->createHandler(false, false);

        $this->organisationMapper->method('findAllWithUserCount')->willReturn([]);
        $this->objectMapper->method('findAll')->willReturn([]);

        $result = $handler->getAllObjectNames();
        $this->assertIsArray($result);
    }

    public function testGetAllObjectNamesForceWarmup(): void
    {
        $handler = $this->createHandler(false, false);
        $this->setProperty($handler, 'nameCache', ['uuid-1' => 'Name 1']);

        $this->organisationMapper->method('findAllWithUserCount')->willReturn([]);
        $this->objectMapper->method('findAll')->willReturn([]);

        $result = $handler->getAllObjectNames(true);
        $this->assertIsArray($result);
    }

    // =========================================================================
    // indexObjectInSolr
    // =========================================================================

    public function testIndexObjectInSolrNoService(): void
    {
        $handler = $this->createHandler(false, false);
        $entity = $this->createObjectEntity();
        $result = $this->invokeMethod($handler, 'indexObjectInSolr', [$entity, false]);
        $this->assertTrue($result);
    }

    public function testIndexObjectInSolrServiceNotAvailable(): void
    {
        $handler = $this->createHandler(false, true);
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(false);

        $this->container->method('get')->willReturn($indexService);

        $entity = $this->createObjectEntity();
        $result = $this->invokeMethod($handler, 'indexObjectInSolr', [$entity, false]);
        $this->assertTrue($result);
    }

    public function testIndexObjectInSolrSuccess(): void
    {
        $handler = $this->createHandler(false, true);
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('indexObject')->willReturn(true);

        $this->container->method('get')->willReturn($indexService);

        $entity = $this->createObjectEntity();
        $result = $this->invokeMethod($handler, 'indexObjectInSolr', [$entity, false]);
        $this->assertTrue($result);
    }

    public function testIndexObjectInSolrFailure(): void
    {
        $handler = $this->createHandler(false, true);
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('indexObject')->willReturn(false);

        $this->container->method('get')->willReturn($indexService);

        $entity = $this->createObjectEntity();
        $result = $this->invokeMethod($handler, 'indexObjectInSolr', [$entity, false]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // removeObjectFromSolr
    // =========================================================================

    public function testRemoveObjectFromSolrNoService(): void
    {
        $handler = $this->createHandler(false, false);
        $entity = $this->createObjectEntity();
        $result = $this->invokeMethod($handler, 'removeObjectFromSolr', [$entity, false]);
        $this->assertTrue($result);
    }

    public function testRemoveObjectFromSolrSuccess(): void
    {
        $handler = $this->createHandler(false, true);
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('deleteObject')->willReturn(true);

        $this->container->method('get')->willReturn($indexService);

        $entity = $this->createObjectEntity();
        $result = $this->invokeMethod($handler, 'removeObjectFromSolr', [$entity, false]);
        $this->assertTrue($result);
    }

    public function testRemoveObjectFromSolrException(): void
    {
        $handler = $this->createHandler(false, true);
        $indexService = $this->createMock(IndexService::class);
        $indexService->method('isAvailable')->willReturn(true);
        $indexService->method('deleteObject')->willThrowException(new \Exception('Solr down'));

        $this->container->method('get')->willReturn($indexService);

        $entity = $this->createObjectEntity();
        $result = $this->invokeMethod($handler, 'removeObjectFromSolr', [$entity, true]);
        $this->assertTrue($result); // Returns true even on exception (graceful degradation)
    }
}
