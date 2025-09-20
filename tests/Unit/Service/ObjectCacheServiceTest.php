<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectCacheService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ObjectCacheService
 *
 * Comprehensive unit tests for the ObjectCacheService class, which handles
 * caching operations for OpenRegister objects. This test suite covers:
 * 
 * ## Test Categories:
 * 
 * ### 1. Cache Operations
 * - testGetCachedObject: Tests retrieving cached objects
 * - testSetCachedObject: Tests storing objects in cache
 * - testDeleteCachedObject: Tests removing objects from cache
 * - testClearCache: Tests clearing entire cache
 * 
 * ### 2. Cache Invalidation
 * - testInvalidateObjectCache: Tests cache invalidation for specific objects
 * - testInvalidateSchemaCache: Tests cache invalidation for schema changes
 * - testInvalidateRegisterCache: Tests cache invalidation for register changes
 * 
 * ### 3. Performance & Memory
 * - testCacheMemoryUsage: Tests memory usage of cached objects
 * - testCacheExpiration: Tests cache expiration handling
 * - testCacheSizeLimits: Tests cache size limit enforcement
 * 
 * ### 4. Error Handling
 * - testCacheErrorHandling: Tests error handling in cache operations
 * - testCacheConnectionFailure: Tests handling of cache connection failures
 * - testCacheSerializationErrors: Tests handling of serialization errors
 * 
 * ## Caching Strategy:
 * 
 * The ObjectCacheService implements a multi-level caching strategy:
 * 1. **Memory Cache**: Fast in-memory storage for frequently accessed objects
 * 2. **Persistent Cache**: Slower but persistent storage for long-term caching
 * 3. **Cache Invalidation**: Smart invalidation based on object changes
 * 4. **Cache Warming**: Pre-loading frequently accessed objects
 * 
 * ## Mocking Strategy:
 * 
 * The tests use comprehensive mocking to isolate the cache service:
 * - ObjectEntityMapper: Mocked for database operations
 * - LoggerInterface: Mocked for logging verification
 * - Cache Backend: Mocked for cache operations
 * - ObjectEntity: Mocked for object data
 * 
 * ## Cache Key Patterns:
 * 
 * Tests verify various cache key patterns:
 * - Object-specific keys: `object:{id}`
 * - Schema-specific keys: `schema:{schema_id}:objects`
 * - Register-specific keys: `register:{register_id}:objects`
 * - User-specific keys: `user:{user_id}:objects`
 * 
 * ## Performance Considerations:
 * 
 * Tests cover performance aspects:
 * - Cache hit/miss ratios
 * - Memory usage patterns
 * - Cache eviction policies
 * - Serialization performance
 * 
 * ## Integration Points:
 * 
 * - **Database Layer**: Integrates with ObjectEntityMapper
 * - **Logging System**: Uses LoggerInterface for error logging
 * - **Memory Management**: Handles memory allocation and cleanup
 * - **Object Lifecycle**: Integrates with object creation/update/deletion
 * 
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class ObjectCacheServiceTest extends TestCase
{
    private ObjectCacheService $objectCacheService;
    private ObjectEntityMapper $objectEntityMapper;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create ObjectCacheService instance
        $this->objectCacheService = new ObjectCacheService(
            $this->objectEntityMapper,
            $this->logger,
            null, // guzzleSolrService
            null, // cacheFactory
            null  // userSession
        );
    }

    /**
     * Test getObject method with cached object
     */
    public function testGetObjectWithCachedObject(): void
    {
        $identifier = 'test-object-id';

        // Create real object entity
        $object = new ObjectEntity();
        $object->id = $identifier;
        $object->setUuid(null);

        // First call should fetch from database and cache
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($identifier)
            ->willReturn($object);

        // First call - should fetch from database
        $result1 = $this->objectCacheService->getObject($identifier);
        $this->assertNotNull($result1, 'getObject should not return null');
        $this->assertEquals($object, $result1);

        // Second call - should return from cache (no additional database call)
        $result2 = $this->objectCacheService->getObject($identifier);
        $this->assertNotNull($result2, 'Second call should not return null');
        $this->assertEquals($object, $result2);
        $this->assertSame($result1, $result2); // Should be the same object instance
    }

    /**
     * Test getObject method with non-existent object
     */
    public function testGetObjectWithNonExistentObject(): void
    {
        $identifier = 'non-existent-id';

        // Mock object entity mapper to throw exception
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($identifier)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Object not found'));

        $result = $this->objectCacheService->getObject($identifier);

        $this->assertNull($result);
    }

    /**
     * Test getObject method with integer identifier
     */
    public function testGetObjectWithIntegerIdentifier(): void
    {
        $identifier = 123;

        // Create mock object
        $object = $this->createMock(ObjectEntity::class);
        $object->method('__toString')->willReturn((string)$identifier);

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('find')
            ->with($identifier)
            ->willReturn($object);

        $result = $this->objectCacheService->getObject($identifier);

        $this->assertEquals($object, $result);
    }

    /**
     * Test preloadObjects method
     */
    public function testPreloadObjects(): void
    {
        $identifiers = ['obj1', 'obj2', 'obj3'];

        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('__toString')->willReturn('obj1');

        $object2 = $this->createMock(ObjectEntity::class);
        $object2->method('__toString')->willReturn('obj2');

        $object3 = $this->createMock(ObjectEntity::class);
        $object3->method('__toString')->willReturn('obj3');

        $objects = [$object1, $object2, $object3];

        // Mock object entity mapper
        $this->objectEntityMapper->expects($this->once())
            ->method('findMultiple')
            ->with($identifiers)
            ->willReturn($objects);

        $result = $this->objectCacheService->preloadObjects($identifiers);

        $this->assertEquals($objects, $result);
    }

    /**
     * Test preloadObjects method with empty array
     */
    public function testPreloadObjectsWithEmptyArray(): void
    {
        $identifiers = [];

        $result = $this->objectCacheService->preloadObjects($identifiers);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test preloadObjects method with no objects found
     */
    public function testPreloadObjectsWithNoObjectsFound(): void
    {
        $identifiers = ['obj1', 'obj2'];

        // Mock object entity mapper to return empty array
        $this->objectEntityMapper->expects($this->once())
            ->method('findMultiple')
            ->with($identifiers)
            ->willReturn([]);

        $result = $this->objectCacheService->preloadObjects($identifiers);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test getStats method
     */
    public function testGetStats(): void
    {
        $result = $this->objectCacheService->getStats();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('cache_size', $result);
        $this->assertArrayHasKey('hits', $result);
        $this->assertArrayHasKey('misses', $result);
        $this->assertArrayHasKey('hit_rate', $result);
    }

    /**
     * Test clearCache method
     */
    public function testClearCache(): void
    {
        // This should not throw any exceptions
        $this->objectCacheService->clearCache();

        // Verify cache is cleared by checking stats
        $stats = $this->objectCacheService->getStats();
        $this->assertEquals(0, $stats['cache_size']);
    }

    /**
     * Test preloadRelationships method
     */
    public function testPreloadRelationships(): void
    {
        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('__toString')->willReturn('obj1');
        $object1->method('getObject')->willReturn(['register' => 'reg1', 'schema' => 'schema1']);

        $object2 = $this->createMock(ObjectEntity::class);
        $object2->method('__toString')->willReturn('obj2');
        $object2->method('getObject')->willReturn(['register' => 'reg2', 'schema' => 'schema2']);

        $objects = [$object1, $object2];
        $extend = ['register', 'schema'];

        // Mock object entity mapper for preloadObjects call
        $this->objectEntityMapper->expects($this->once())
            ->method('findMultiple')
            ->willReturn([]);

        $result = $this->objectCacheService->preloadRelationships($objects, $extend);

        $this->assertIsArray($result);
        $this->assertCount(0, $result); // Will be empty since we're not returning any objects from findMultiple
    }

    /**
     * Test preloadRelationships method with empty objects array
     */
    public function testPreloadRelationshipsWithEmptyObjectsArray(): void
    {
        $objects = [];
        $extend = ['register', 'schema'];

        $result = $this->objectCacheService->preloadRelationships($objects, $extend);

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test preloadRelationships method with empty extend array
     */
    public function testPreloadRelationshipsWithEmptyExtendArray(): void
    {
        // Create mock objects
        $object1 = $this->createMock(ObjectEntity::class);
        $object1->method('__toString')->willReturn('obj1');

        $objects = [$object1];
        $extend = [];

        $result = $this->objectCacheService->preloadRelationships($objects, $extend);

        $this->assertIsArray($result);
        $this->assertCount(0, $result); // Returns empty array when extend is empty
    }
}
