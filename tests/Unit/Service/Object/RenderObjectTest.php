<?php

declare(strict_types=1);

/**
 * RenderObject Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for RenderObject
 *
 * Tests entity rendering, cache management, field filtering, and extensions.
 */
class RenderObjectTest extends TestCase
{
    /** @var RenderObject */
    private RenderObject $handler;

    /** @var FileMapper&MockObject */
    private FileMapper $fileMapper;

    /** @var ObjectEntityMapper&MockObject */
    private ObjectEntityMapper $objectEntityMapper;

    /** @var RegisterMapper&MockObject */
    private RegisterMapper $registerMapper;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var ISystemTagManager&MockObject */
    private ISystemTagManager $systemTagManager;

    /** @var ISystemTagObjectMapper&MockObject */
    private ISystemTagObjectMapper $systemTagMapper;

    /** @var CacheHandler&MockObject */
    private CacheHandler $cacheHandler;

    /** @var CacheHandler&MockObject */
    private CacheHandler $objectCacheService;

    /** @var PropertyRbacHandler&MockObject */
    private PropertyRbacHandler $propertyRbacHandler;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var FileService&MockObject */
    private FileService $fileService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileMapper = $this->createMock(FileMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->systemTagManager = $this->createMock(ISystemTagManager::class);
        $this->systemTagMapper = $this->createMock(ISystemTagObjectMapper::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->objectCacheService = $this->createMock(CacheHandler::class);
        $this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->fileService = $this->createMock(FileService::class);

        $this->handler = new RenderObject(
            $this->fileMapper,
            $this->objectEntityMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->systemTagManager,
            $this->systemTagMapper,
            $this->cacheHandler,
            $this->objectCacheService,
            $this->propertyRbacHandler,
            $this->logger,
            $this->fileService
        );
    }

    /**
     * Helper to create an ObjectEntity with a given id, uuid, and object data.
     */
    private function createObjectEntity(int $id, string $uuid, array $objectData = []): ObjectEntity
    {
        $entity = new ObjectEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, $id);
        $entity->setUuid($uuid);
        $entity->setObject($objectData);
        return $entity;
    }

    // =========================================================================
    // setUltraPreloadCache / getUltraCacheSize
    // =========================================================================

    public function testSetUltraPreloadCacheAndGetSize(): void
    {
        $this->assertSame(0, $this->handler->getUltraCacheSize());

        $entity1 = $this->createObjectEntity(1, 'uuid-1');
        $entity2 = $this->createObjectEntity(2, 'uuid-2');

        $this->handler->setUltraPreloadCache([
            'uuid-1' => $entity1,
            'uuid-2' => $entity2,
        ]);

        $this->assertSame(2, $this->handler->getUltraCacheSize());
    }

    public function testSetUltraPreloadCacheReplacesExistingCache(): void
    {
        $entity1 = $this->createObjectEntity(1, 'uuid-1');

        $this->handler->setUltraPreloadCache(['uuid-1' => $entity1]);
        $this->assertSame(1, $this->handler->getUltraCacheSize());

        $this->handler->setUltraPreloadCache([]);
        $this->assertSame(0, $this->handler->getUltraCacheSize());
    }

    // =========================================================================
    // clearCache
    // =========================================================================

    public function testClearCacheResetsAllInternalCaches(): void
    {
        $this->handler->clearCache();

        // After clearing, getObjectsCache should return empty.
        $result = $this->handler->getObjectsCache();
        $this->assertSame([], $result);
    }

    // =========================================================================
    // getObjectsCache
    // =========================================================================

    public function testGetObjectsCacheReturnsEmptyByDefault(): void
    {
        $result = $this->handler->getObjectsCache();

        $this->assertSame([], $result);
    }

    // =========================================================================
    // renderEntity - basic rendering
    // =========================================================================

    public function testRenderEntityReturnsEntityWithNoFiles(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test Object',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        // No files for this object.
        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);

        // Schema lookup for file property hydration.
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity($entity);

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $result->getUuid());
    }

    public function testRenderEntityWithFieldFiltering(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'description' => 'Some description',
            'status' => 'active',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            [],
            ['name'],
            []
        );

        $objectData = $result->getObject();
        // Should include 'name', '@self', and 'id'.
        $this->assertArrayHasKey('name', $objectData);
        $this->assertArrayNotHasKey('description', $objectData);
        $this->assertArrayNotHasKey('status', $objectData);
    }

    public function testRenderEntityWithFilterMatching(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'status' => 'active',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        // Filter matches - entity should be returned normally.
        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            ['status' => 'active']
        );

        $objectData = $result->getObject();
        $this->assertNotEmpty($objectData);
    }

    public function testRenderEntityWithFilterNotMatching(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'status' => 'inactive',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        // Filter doesn't match - entity should have empty object data (only id remains from getObject merge).
        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            ['status' => 'active']
        );

        $objectData = $result->getObject();
        // getObject() always merges ['id' => uuid] even on empty objects.
        $this->assertSame(['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'], $objectData);
    }

    public function testRenderEntityWithUnsetProperties(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'secret' => 'should-be-removed',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            [],
            [],
            ['secret']
        );

        $objectData = $result->getObject();
        $this->assertArrayNotHasKey('secret', $objectData);
        $this->assertArrayHasKey('name', $objectData);
    }

    public function testRenderEntityDetectsCircularReference(): void
    {
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $entity = $this->createObjectEntity(1, $uuid, [
            'id' => $uuid,
            'name' => 'Test',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        // Pass the UUID as already visited - circular detection happens before renderFiles.
        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            [],
            [],
            [],
            [],
            [],
            [],
            [$uuid]
        );

        $objectData = $result->getObject();
        // When circular reference is detected, entity data is replaced.
        $this->assertArrayHasKey('@circular', $objectData);
        $this->assertTrue($objectData['@circular']);
        $this->assertSame($uuid, $objectData['id']);
    }

    public function testRenderEntityWithPreloadedRegistersAndSchemas(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $register = new Register();
        $schema = new Schema();

        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            [],
            [],
            [],
            [1 => $register],
            [1 => $schema]
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // renderEntity with string extend parameter
    // =========================================================================

    public function testRenderEntityWithStringExtend(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'relatedField' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity(
            $entity,
            'relatedField'
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // renderEntities
    // =========================================================================

    public function testRenderEntitiesWithEmptyArray(): void
    {
        $result = $this->handler->renderEntities([]);

        $this->assertSame([], $result);
    }

    public function testRenderEntitiesWithMultipleEntities(): void
    {
        $entity1 = $this->createObjectEntity(1, 'aaaaaaaa-0001-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-0001-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Entity 1',
        ]);
        $entity1->setSchema(1);
        $entity1->setRegister(1);

        $entity2 = $this->createObjectEntity(2, 'aaaaaaaa-0002-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-0002-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Entity 2',
        ]);
        $entity2->setSchema(1);
        $entity2->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntities([$entity1, $entity2]);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(ObjectEntity::class, $result[0]);
        $this->assertInstanceOf(ObjectEntity::class, $result[1]);
    }

    /**
     * Helper to invoke private methods via reflection.
     */
    private function invokePrivate(string $method, array $args = [])
    {
        $ref = new ReflectionClass($this->handler);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->handler, $args);
    }

    /**
     * Helper to create a Schema entity with reflection for id.
     */
    private function createSchema(int $id, string $slug = 'test-schema'): Schema
    {
        $schema = new Schema();
        $ref = new ReflectionClass($schema);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($schema, $id);
        $schema->setSlug($slug);
        $schema->setTitle('Test Schema');
        return $schema;
    }

    /**
     * Helper to create a Register entity with reflection for id.
     */
    private function createRegister(int $id, string $title = 'Test Register'): Register
    {
        $register = new Register();
        $ref = new ReflectionClass($register);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($register, $id);
        $register->setTitle($title);
        return $register;
    }

    // =========================================================================
    // getRegister - private method
    // =========================================================================

    public function testGetRegisterFromCache(): void
    {
        $register = $this->createRegister(1);

        // Pre-populate cache via renderEntity with preloaded registers.
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        // Pass preloaded registers to populate cache.
        $this->handler->renderEntity(
            $entity,
            [],
            0,
            [],
            [],
            [],
            [1 => $register]
        );

        // Now getRegister should return from cache.
        $result = $this->invokePrivate('getRegister', [1]);
        $this->assertSame($register, $result);
    }

    public function testGetRegisterFromDb(): void
    {
        $register = $this->createRegister(1);

        $this->registerMapper->method('find')
            ->with(1)
            ->willReturn($register);

        $result = $this->invokePrivate('getRegister', [1]);
        $this->assertSame($register, $result);
    }

    public function testGetRegisterNotFound(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->invokePrivate('getRegister', [999]);
        $this->assertNull($result);
    }

    // =========================================================================
    // getSchema - private method
    // =========================================================================

    public function testGetSchemaFromDb(): void
    {
        $schema = $this->createSchema(1);

        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $result = $this->invokePrivate('getSchema', [1]);
        $this->assertSame($schema, $result);
    }

    public function testGetSchemaNotFound(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->invokePrivate('getSchema', [999]);
        $this->assertNull($result);
    }

    public function testGetSchemaFromCache(): void
    {
        $schema = $this->createSchema(1);

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->willReturn($schema);

        // First call loads from db.
        $result1 = $this->invokePrivate('getSchema', [1]);
        // Second call should use cache.
        $result2 = $this->invokePrivate('getSchema', [1]);

        $this->assertSame($result1, $result2);
    }

    // =========================================================================
    // isUuidLike - private method
    // =========================================================================

    public function testIsUuidLikeValidUuid(): void
    {
        $this->assertTrue($this->invokePrivate('isUuidLike', ['12345678-1234-1234-1234-123456789012']));
    }

    public function testIsUuidLikeUpperCase(): void
    {
        $this->assertTrue($this->invokePrivate('isUuidLike', ['12345678-1234-1234-1234-123456789ABC']));
    }

    public function testIsUuidLikeInvalid(): void
    {
        $this->assertFalse($this->invokePrivate('isUuidLike', ['not-a-uuid']));
        $this->assertFalse($this->invokePrivate('isUuidLike', ['12345678-1234-1234-1234-12345678']));
        $this->assertFalse($this->invokePrivate('isUuidLike', ['']));
    }

    // =========================================================================
    // getObject - private method (uses ultra preload cache)
    // =========================================================================

    public function testGetObjectFromUltraPreloadCache(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['name' => 'Test']);

        $this->handler->setUltraPreloadCache(['uuid-1' => $entity]);

        $result = $this->invokePrivate('getObject', ['uuid-1']);
        $this->assertSame($entity, $result);
    }

    public function testGetObjectFromObjectCacheService(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['name' => 'Test']);

        $this->objectCacheService->method('getObject')
            ->with('uuid-1')
            ->willReturn($entity);

        $result = $this->invokePrivate('getObject', ['uuid-1']);
        $this->assertSame($entity, $result);
    }

    public function testGetObjectNotFound(): void
    {
        $this->objectCacheService->method('getObject')
            ->willReturn(null);

        $result = $this->invokePrivate('getObject', ['nonexistent-uuid']);
        $this->assertNull($result);
    }

    // =========================================================================
    // getObjectsCache - returns UUID-keyed entries only
    // =========================================================================

    public function testGetObjectsCacheReturnsOnlyUuidKeys(): void
    {
        $entity = $this->createObjectEntity(1, '12345678-1234-1234-1234-123456789012', ['name' => 'Test']);
        $entity->setSchema(1);
        $entity->setRegister(1);

        // Populate objects cache by rendering an entity that gets extended.
        // Simpler: use reflection to directly set the objectsCache.
        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('objectsCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->handler, [
            '12345678-1234-1234-1234-123456789012' => $entity,
            42                                      => $entity, // numeric ID entry.
        ]);

        $result = $this->handler->getObjectsCache();

        // Only the UUID key should be in the result.
        $this->assertArrayHasKey('12345678-1234-1234-1234-123456789012', $result);
        $this->assertArrayNotHasKey(42, $result);
    }

    public function testGetObjectsCacheWithArrayEntry(): void
    {
        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('objectsCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->handler, [
            '12345678-1234-1234-1234-123456789012' => ['name' => 'Array entry'],
        ]);

        $result = $this->handler->getObjectsCache();

        $this->assertArrayHasKey('12345678-1234-1234-1234-123456789012', $result);
        $this->assertSame(['name' => 'Array entry'], $result['12345678-1234-1234-1234-123456789012']);
    }

    // =========================================================================
    // clearCache
    // =========================================================================

    public function testClearCacheResetsRegistersAndSchemasAndObjects(): void
    {
        // Populate caches.
        $ref = new ReflectionClass($this->handler);
        $regCache = $ref->getProperty('registersCache');
        $regCache->setAccessible(true);
        $regCache->setValue($this->handler, [1 => $this->createRegister(1)]);

        $schemaCache = $ref->getProperty('schemasCache');
        $schemaCache->setAccessible(true);
        $schemaCache->setValue($this->handler, [1 => $this->createSchema(1)]);

        $objCache = $ref->getProperty('objectsCache');
        $objCache->setAccessible(true);
        $objCache->setValue($this->handler, ['uuid' => $this->createObjectEntity(1, 'uuid')]);

        $this->handler->clearCache();

        $this->assertSame([], $this->handler->getObjectsCache());
        $this->assertSame([], $regCache->getValue($this->handler));
        $this->assertSame([], $schemaCache->getValue($this->handler));
    }

    // =========================================================================
    // isFilePropertyConfig - private method
    // =========================================================================

    public function testIsFilePropertyConfigDirectFile(): void
    {
        $config = ['type' => 'file'];
        $result = $this->invokePrivate('isFilePropertyConfig', [$config]);
        $this->assertTrue($result);
    }

    public function testIsFilePropertyConfigArrayOfFiles(): void
    {
        $config = ['type' => 'array', 'items' => ['type' => 'file']];
        $result = $this->invokePrivate('isFilePropertyConfig', [$config]);
        $this->assertTrue($result);
    }

    public function testIsFilePropertyConfigString(): void
    {
        $config = ['type' => 'string'];
        $result = $this->invokePrivate('isFilePropertyConfig', [$config]);
        $this->assertFalse($result);
    }

    public function testIsFilePropertyConfigArrayOfStrings(): void
    {
        $config = ['type' => 'array', 'items' => ['type' => 'string']];
        $result = $this->invokePrivate('isFilePropertyConfig', [$config]);
        $this->assertFalse($result);
    }

    public function testIsFilePropertyConfigNoType(): void
    {
        $config = [];
        $result = $this->invokePrivate('isFilePropertyConfig', [$config]);
        $this->assertFalse($result);
    }

    // =========================================================================
    // getValueFromPath - private method
    // =========================================================================

    public function testGetValueFromPathSimple(): void
    {
        $data = ['name' => 'Test'];
        $result = $this->invokePrivate('getValueFromPath', [$data, 'name']);
        $this->assertSame('Test', $result);
    }

    public function testGetValueFromPathNested(): void
    {
        $data = ['address' => ['street' => 'Main St']];
        $result = $this->invokePrivate('getValueFromPath', [$data, 'address.street']);
        $this->assertSame('Main St', $result);
    }

    public function testGetValueFromPathNotFound(): void
    {
        $data = ['name' => 'Test'];
        $result = $this->invokePrivate('getValueFromPath', [$data, 'nonexistent']);
        $this->assertNull($result);
    }

    public function testGetValueFromPathDeepNested(): void
    {
        $data = ['a' => ['b' => ['c' => 'deep']]];
        $result = $this->invokePrivate('getValueFromPath', [$data, 'a.b.c']);
        $this->assertSame('deep', $result);
    }

    public function testGetValueFromPathPartialNotFound(): void
    {
        $data = ['a' => ['b' => 'value']];
        $result = $this->invokePrivate('getValueFromPath', [$data, 'a.b.c']);
        $this->assertNull($result);
    }

    // =========================================================================
    // collectUuidsForExtend - private method
    // =========================================================================

    public function testCollectUuidsForExtendSingleUuid(): void
    {
        $objectData = [
            'related' => '12345678-1234-1234-1234-123456789012',
        ];
        $extend = ['related'];

        $result = $this->invokePrivate('collectUuidsForExtend', [$objectData, $extend]);

        $this->assertContains('12345678-1234-1234-1234-123456789012', $result);
    }

    public function testCollectUuidsForExtendArrayOfUuids(): void
    {
        $objectData = [
            'items' => [
                '12345678-1234-1234-1234-123456789012',
                'abcdefab-cdef-abcd-efab-cdefabcdefab',
            ],
        ];
        $extend = ['items'];

        $result = $this->invokePrivate('collectUuidsForExtend', [$objectData, $extend]);

        $this->assertCount(2, $result);
    }

    public function testCollectUuidsForExtendSkipsSpecialKeys(): void
    {
        $objectData = ['@self' => '12345678-1234-1234-1234-123456789012'];
        $extend = ['@self'];

        $result = $this->invokePrivate('collectUuidsForExtend', [$objectData, $extend]);

        $this->assertEmpty($result);
    }

    public function testCollectUuidsForExtendMissingProperty(): void
    {
        $objectData = ['name' => 'Test'];
        $extend = ['nonexistent'];

        $result = $this->invokePrivate('collectUuidsForExtend', [$objectData, $extend]);

        $this->assertEmpty($result);
    }

    public function testCollectUuidsForExtendSkipsNonUuids(): void
    {
        $objectData = ['related' => 'not-a-uuid'];
        $extend = ['related'];

        $result = $this->invokePrivate('collectUuidsForExtend', [$objectData, $extend]);

        $this->assertEmpty($result);
    }

    public function testCollectUuidsForExtendDeduplicates(): void
    {
        $uuid = '12345678-1234-1234-1234-123456789012';
        $objectData = [
            'relA' => $uuid,
            'relB' => $uuid,
        ];
        $extend = ['relA', 'relB'];

        $result = $this->invokePrivate('collectUuidsForExtend', [$objectData, $extend]);

        $this->assertCount(1, $result);
    }

    // =========================================================================
    // getInversedProperties - private method
    // =========================================================================

    public function testGetInversedPropertiesWithInversedBy(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'children' => [
                'type'       => 'array',
                'inversedBy' => 'parent',
                'items'      => ['type' => 'object'],
            ],
            'name' => ['type' => 'string'],
        ]);

        $result = $this->invokePrivate('getInversedProperties', [$schema]);

        $this->assertArrayHasKey('children', $result);
        $this->assertArrayNotHasKey('name', $result);
    }

    public function testGetInversedPropertiesWithItemsInversedBy(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'contacts' => [
                'type'  => 'array',
                'items' => ['type' => 'object', 'inversedBy' => 'organisations'],
            ],
        ]);

        $result = $this->invokePrivate('getInversedProperties', [$schema]);

        $this->assertArrayHasKey('contacts', $result);
    }

    public function testGetInversedPropertiesNone(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'name' => ['type' => 'string'],
            'age'  => ['type' => 'integer'],
        ]);

        $result = $this->invokePrivate('getInversedProperties', [$schema]);

        $this->assertEmpty($result);
    }

    // =========================================================================
    // filterExtendedInverseProperties - private method
    // =========================================================================

    public function testFilterExtendedInversePropertiesMatchSpecific(): void
    {
        $inversedProperties = [
            'children' => ['inversedBy' => 'parent'],
            'contacts' => ['inversedBy' => 'org'],
        ];
        $extend = ['children'];

        $result = $this->invokePrivate('filterExtendedInverseProperties', [$inversedProperties, $extend]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('children', $result);
    }

    public function testFilterExtendedInversePropertiesMatchAll(): void
    {
        $inversedProperties = [
            'children' => ['inversedBy' => 'parent'],
            'contacts' => ['inversedBy' => 'org'],
        ];
        $extend = ['all'];

        $result = $this->invokePrivate('filterExtendedInverseProperties', [$inversedProperties, $extend]);

        $this->assertCount(2, $result);
    }

    public function testFilterExtendedInversePropertiesNoMatch(): void
    {
        $inversedProperties = [
            'children' => ['inversedBy' => 'parent'],
        ];
        $extend = ['name'];

        $result = $this->invokePrivate('filterExtendedInverseProperties', [$inversedProperties, $extend]);

        $this->assertEmpty($result);
    }

    // =========================================================================
    // collectEntityUuids - private method
    // =========================================================================

    public function testCollectEntityUuids(): void
    {
        $entity1 = $this->createObjectEntity(1, 'uuid-1');
        $entity2 = $this->createObjectEntity(2, 'uuid-2');

        $result = $this->invokePrivate('collectEntityUuids', [[$entity1, $entity2]]);

        $this->assertCount(2, $result);
        $this->assertContains('uuid-1', $result);
        $this->assertContains('uuid-2', $result);
    }

    public function testCollectEntityUuidsEmpty(): void
    {
        $result = $this->invokePrivate('collectEntityUuids', [[]]);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // extractInverseConfig - private method
    // =========================================================================

    public function testExtractInverseConfigWithItems(): void
    {
        $propConfig = [
            'items' => [
                '$ref'       => '#/components/schemas/contact',
                'inversedBy' => 'organisations',
            ],
        ];

        $result = $this->invokePrivate('extractInverseConfig', [$propConfig]);

        $this->assertNotNull($result);
        $this->assertSame('#/components/schemas/contact', $result['targetSchemaRef']);
        $this->assertSame(['organisations'], $result['inversedByFields']);
    }

    public function testExtractInverseConfigWithDirectRef(): void
    {
        $propConfig = [
            '$ref'       => '#/components/schemas/parent',
            'inversedBy' => 'children',
        ];

        $result = $this->invokePrivate('extractInverseConfig', [$propConfig]);

        $this->assertNotNull($result);
        $this->assertSame('#/components/schemas/parent', $result['targetSchemaRef']);
    }

    public function testExtractInverseConfigMissingRef(): void
    {
        $propConfig = [
            'inversedBy' => 'children',
        ];

        $result = $this->invokePrivate('extractInverseConfig', [$propConfig]);

        $this->assertNull($result);
    }

    public function testExtractInverseConfigMissingInversedBy(): void
    {
        $propConfig = [
            '$ref' => '#/components/schemas/parent',
        ];

        $result = $this->invokePrivate('extractInverseConfig', [$propConfig]);

        $this->assertNull($result);
    }

    public function testExtractInverseConfigMultipleInversedByFields(): void
    {
        $propConfig = [
            '$ref'       => '#/components/schemas/parent',
            'inversedBy' => ['fieldA', 'fieldB'],
        ];

        $result = $this->invokePrivate('extractInverseConfig', [$propConfig]);

        $this->assertNotNull($result);
        $this->assertSame(['fieldA', 'fieldB'], $result['inversedByFields']);
    }

    // =========================================================================
    // resolveReferencedUuids - private method
    // =========================================================================

    public function testResolveReferencedUuidsSimpleString(): void
    {
        $refData = ['field' => 'uuid-123'];

        $result = $this->invokePrivate('resolveReferencedUuids', [$refData, 'field']);

        $this->assertSame(['uuid-123'], $result);
    }

    public function testResolveReferencedUuidsObjectWithValue(): void
    {
        $refData = ['field' => ['value' => 'uuid-123']];

        $result = $this->invokePrivate('resolveReferencedUuids', [$refData, 'field']);

        $this->assertSame(['uuid-123'], $result);
    }

    public function testResolveReferencedUuidsArrayOfUuids(): void
    {
        $refData = ['field' => ['uuid-1', 'uuid-2']];

        $result = $this->invokePrivate('resolveReferencedUuids', [$refData, 'field']);

        $this->assertSame(['uuid-1', 'uuid-2'], $result);
    }

    public function testResolveReferencedUuidsMissingField(): void
    {
        $refData = ['other' => 'value'];

        $result = $this->invokePrivate('resolveReferencedUuids', [$refData, 'field']);

        $this->assertSame([null], $result);
    }

    // =========================================================================
    // initializeInverseCacheEntries - private method
    // =========================================================================

    public function testInitializeInverseCacheEntries(): void
    {
        $entityUuids = ['uuid-1', 'uuid-2'];

        $this->invokePrivate('initializeInverseCacheEntries', [$entityUuids, 'contacts']);

        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('inverseRelationCache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue($this->handler);

        $this->assertArrayHasKey('uuid-1_contacts', $cache);
        $this->assertArrayHasKey('uuid-2_contacts', $cache);
        $this->assertSame([], $cache['uuid-1_contacts']);
    }

    // =========================================================================
    // renderEntity — extend shorthand normalization
    // =========================================================================

    public function testRenderEntityNormalizesExtendShorthands(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        // _schema should be normalized to @self.schema.
        $result = $this->handler->renderEntity(
            $entity,
            ['_schema']
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // renderEntity — extend with 'all' keyword
    // =========================================================================

    public function testRenderEntityWithExtendAll(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'related' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));
        $this->objectCacheService->method('preloadObjects')
            ->willReturn([]);

        $result = $this->handler->renderEntity(
            $entity,
            ['all']
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // renderEntity — extend @self.register and @self.schema
    // =========================================================================

    public function testRenderEntityExtendSelfRegister(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $register = $this->createRegister(1);
        $this->registerMapper->method('find')
            ->willReturn($register);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));
        $this->objectCacheService->method('preloadObjects')
            ->willReturn([]);

        $result = $this->handler->renderEntity(
            $entity,
            ['@self.register']
        );

        $objectData = $result->getObject();
        // @self should have register data.
        $this->assertArrayHasKey('@self', $objectData);
    }

    // =========================================================================
    // renderEntity — depth limit
    // =========================================================================

    public function testRenderEntityRespectsDepthLimit(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'related' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        // At depth 10, extensions should not be applied.
        $result = $this->handler->renderEntity(
            $entity,
            ['related'],
            10
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // renderEntity — with RBAC (schema has property authorization)
    // =========================================================================

    public function testRenderEntityWithPropertyRbac(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'secret' => 'hidden',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'name'   => ['type' => 'string'],
            'secret' => ['type' => 'string', 'authorization' => ['read' => ['admin']]],
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        // Return schema that has property authorization.
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $this->propertyRbacHandler->method('filterReadableProperties')
            ->willReturnCallback(function ($schema, $object) {
                // Simulate filtering out the 'secret' property.
                unset($object['secret']);
                return $object;
            });

        $result = $this->handler->renderEntity($entity);

        $objectData = $result->getObject();
        $this->assertArrayHasKey('name', $objectData);
        $this->assertArrayNotHasKey('secret', $objectData);
    }

    // =========================================================================
    // renderEntity — multiple unset properties
    // =========================================================================

    public function testRenderEntityWithMultipleUnset(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'       => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name'     => 'Test',
            'fieldA'   => 'remove-me',
            'fieldB'   => 'also-remove',
            'keepMe'   => 'stay',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            [],
            [],
            ['fieldA', 'fieldB']
        );

        $objectData = $result->getObject();
        $this->assertArrayNotHasKey('fieldA', $objectData);
        $this->assertArrayNotHasKey('fieldB', $objectData);
        $this->assertArrayHasKey('keepMe', $objectData);
        $this->assertArrayHasKey('name', $objectData);
    }
}
