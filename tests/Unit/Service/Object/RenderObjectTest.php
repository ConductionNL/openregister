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
use OCP\SystemTag\ISystemTag;
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
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
     *
     * @param int    $id         Entity ID
     * @param string $uuid       Entity UUID
     * @param array  $objectData Object data
     *
     * @return ObjectEntity
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

    /**
     * Helper to invoke private methods via reflection.
     *
     * @param string $method Method name
     * @param array  $args   Method arguments
     *
     * @return mixed
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
     *
     * @param int    $id   Schema ID
     * @param string $slug Schema slug
     *
     * @return Schema
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
     *
     * @param int    $id    Register ID
     * @param string $title Register title
     *
     * @return Register
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

    /**
     * Helper to set up a basic entity with no-op file/schema mocks.
     *
     * @param int    $id         Entity ID
     * @param string $uuid       Entity UUID
     * @param array  $objectData Object data
     *
     * @return ObjectEntity
     */
    private function createBasicEntity(int $id, string $uuid, array $objectData = []): ObjectEntity
    {
        $entity = $this->createObjectEntity($id, $uuid, $objectData);
        $entity->setSchema(1);
        $entity->setRegister(1);
        return $entity;
    }

    /**
     * Set up mocks for basic rendering (no files, no schema found).
     *
     * @return void
     */
    private function setupBasicMocks(): void
    {
        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));
    }

    /**
     * Helper to create a mock ISystemTag.
     *
     * @param string $id   Tag ID
     * @param string $name Tag name
     *
     * @return ISystemTag&MockObject
     */
    private function createMockTag(string $id, string $name): ISystemTag
    {
        $tag = $this->createMock(ISystemTag::class);
        $tag->method('getId')->willReturn($id);
        $tag->method('getName')->willReturn($name);
        return $tag;
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
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test Object',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntity($entity);

        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $result->getUuid());
    }

    public function testRenderEntityWithFieldFiltering(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'description' => 'Some description',
            'status' => 'active',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            [],
            ['name'],
            []
        );

        $objectData = $result->getObject();
        $this->assertArrayHasKey('name', $objectData);
        $this->assertArrayNotHasKey('description', $objectData);
        $this->assertArrayNotHasKey('status', $objectData);
    }

    public function testRenderEntityWithFilterMatching(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'status' => 'active',
        ]);

        $this->setupBasicMocks();

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
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'status' => 'inactive',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            ['status' => 'active']
        );

        $objectData = $result->getObject();
        $this->assertSame(['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'], $objectData);
    }

    public function testRenderEntityWithUnsetProperties(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'secret' => 'should-be-removed',
        ]);

        $this->setupBasicMocks();

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
        $entity = $this->createBasicEntity(1, $uuid, [
            'id' => $uuid,
            'name' => 'Test',
        ]);

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
        $this->assertArrayHasKey('@circular', $objectData);
        $this->assertTrue($objectData['@circular']);
        $this->assertSame($uuid, $objectData['id']);
    }

    public function testRenderEntityWithPreloadedRegistersAndSchemas(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

        $this->setupBasicMocks();

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
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'relatedField' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
        ]);

        $this->setupBasicMocks();

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
        $entity1 = $this->createBasicEntity(1, 'aaaaaaaa-0001-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-0001-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Entity 1',
        ]);

        $entity2 = $this->createBasicEntity(2, 'aaaaaaaa-0002-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-0002-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Entity 2',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntities([$entity1, $entity2]);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(ObjectEntity::class, $result[0]);
        $this->assertInstanceOf(ObjectEntity::class, $result[1]);
    }

    // =========================================================================
    // getRegister - private method
    // =========================================================================

    public function testGetRegisterFromCache(): void
    {
        $register = $this->createRegister(1);

        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

        $this->setupBasicMocks();

        $this->handler->renderEntity(
            $entity,
            [],
            0,
            [],
            [],
            [],
            [1 => $register]
        );

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

        $result1 = $this->invokePrivate('getSchema', [1]);
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

    public function testGetObjectFromLocalCache(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['name' => 'Test']);

        // Pre-populate local objectsCache via reflection.
        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('objectsCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->handler, ['uuid-1' => $entity]);

        $result = $this->invokePrivate('getObject', ['uuid-1']);
        $this->assertSame($entity, $result);
    }

    public function testGetObjectCachesResultByUuid(): void
    {
        $entity = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', ['name' => 'Test']);

        $this->objectCacheService->method('getObject')
            ->willReturn($entity);

        // First call caches it.
        $this->invokePrivate('getObject', ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        // Verify the object is also cached by UUID.
        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('objectsCache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue($this->handler);

        $this->assertArrayHasKey('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $cache);
    }

    // =========================================================================
    // getObjectsCache - returns UUID-keyed entries only
    // =========================================================================

    public function testGetObjectsCacheReturnsOnlyUuidKeys(): void
    {
        $entity = $this->createObjectEntity(1, '12345678-1234-1234-1234-123456789012', ['name' => 'Test']);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('objectsCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->handler, [
            '12345678-1234-1234-1234-123456789012' => $entity,
            42                                      => $entity,
        ]);

        $result = $this->handler->getObjectsCache();

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

    public function testGetObjectsCacheSkipsNonUuidStringKeys(): void
    {
        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('objectsCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->handler, [
            'not-a-uuid' => ['name' => 'Should be excluded'],
            '12345678-1234-1234-1234-123456789012' => ['name' => 'Included'],
        ]);

        $result = $this->handler->getObjectsCache();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('12345678-1234-1234-1234-123456789012', $result);
    }

    // =========================================================================
    // clearCache
    // =========================================================================

    public function testClearCacheResetsRegistersAndSchemasAndObjects(): void
    {
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

    public function testIsFilePropertyConfigArrayWithNoItems(): void
    {
        $config = ['type' => 'array'];
        $result = $this->invokePrivate('isFilePropertyConfig', [$config]);
        $this->assertFalse($result);
    }

    public function testIsFilePropertyConfigObjectType(): void
    {
        $config = ['type' => 'object'];
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

    public function testGetValueFromPathReturnsArray(): void
    {
        $data = ['items' => ['a', 'b', 'c']];
        $result = $this->invokePrivate('getValueFromPath', [$data, 'items']);
        $this->assertSame(['a', 'b', 'c'], $result);
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

    public function testCollectUuidsForExtendUsesBaseProperty(): void
    {
        $uuid = '12345678-1234-1234-1234-123456789012';
        $objectData = [
            'parent' => $uuid,
        ];
        // Nested extend like "parent.name" should still pick up parent UUID.
        $extend = ['parent.name'];

        $result = $this->invokePrivate('collectUuidsForExtend', [$objectData, $extend]);

        $this->assertContains($uuid, $result);
    }

    public function testCollectUuidsForExtendSkipsNonUuidArrayItems(): void
    {
        $objectData = [
            'tags' => ['not-uuid', 'also-not-uuid', '12345678-1234-1234-1234-123456789012'],
        ];
        $extend = ['tags'];

        $result = $this->invokePrivate('collectUuidsForExtend', [$objectData, $extend]);

        $this->assertCount(1, $result);
        $this->assertContains('12345678-1234-1234-1234-123456789012', $result);
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

    public function testGetInversedPropertiesEmptyInversedBy(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'children' => [
                'type'       => 'array',
                'inversedBy' => '',
                'items'      => ['type' => 'object'],
            ],
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

    public function testCollectEntityUuidsSkipsNonObjectEntities(): void
    {
        $entity1 = $this->createObjectEntity(1, 'uuid-1');
        $notAnEntity = 'just-a-string';

        $result = $this->invokePrivate('collectEntityUuids', [[$entity1, $notAnEntity]]);

        $this->assertCount(1, $result);
        $this->assertContains('uuid-1', $result);
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

    public function testExtractInverseConfigItemsRefFallback(): void
    {
        // Only has items.$ref, no direct $ref.
        $propConfig = [
            'items' => [
                '$ref'       => 'some-schema',
                'inversedBy' => 'field',
            ],
        ];

        $result = $this->invokePrivate('extractInverseConfig', [$propConfig]);

        $this->assertNotNull($result);
        $this->assertSame('some-schema', $result['targetSchemaRef']);
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

    public function testInitializeInverseCacheEntriesDoesNotOverwrite(): void
    {
        $entity = $this->createObjectEntity(1, 'obj-1');

        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('inverseRelationCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->handler, [
            'uuid-1_contacts' => [$entity],
        ]);

        $this->invokePrivate('initializeInverseCacheEntries', [['uuid-1', 'uuid-2'], 'contacts']);

        $cache = $cacheProp->getValue($this->handler);

        // Existing entry should NOT be overwritten.
        $this->assertCount(1, $cache['uuid-1_contacts']);
        // New entry should be initialized.
        $this->assertSame([], $cache['uuid-2_contacts']);
    }

    // =========================================================================
    // renderEntity — extend shorthand normalization
    // =========================================================================

    public function testRenderEntityNormalizesExtendShorthands(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntity(
            $entity,
            ['_schema']
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    public function testRenderEntityNormalizesRegisterShorthand(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

        $this->setupBasicMocks();
        $this->objectCacheService->method('preloadObjects')
            ->willReturn([]);

        $result = $this->handler->renderEntity(
            $entity,
            ['_register']
        );

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // renderEntity — extend with 'all' keyword
    // =========================================================================

    public function testRenderEntityWithExtendAll(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'related' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
        ]);

        $this->setupBasicMocks();
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
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

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
        $this->assertArrayHasKey('@self', $objectData);
    }

    public function testRenderEntityExtendSelfSchema(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

        $schema = $this->createSchema(1);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->objectCacheService->method('preloadObjects')
            ->willReturn([]);

        $result = $this->handler->renderEntity(
            $entity,
            ['@self.schema']
        );

        $objectData = $result->getObject();
        $this->assertArrayHasKey('@self', $objectData);
        $this->assertArrayHasKey('schema', $objectData['@self']);
    }

    // =========================================================================
    // renderEntity — depth limit
    // =========================================================================

    public function testRenderEntityRespectsDepthLimit(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'related' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
        ]);

        $this->setupBasicMocks();

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
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'secret' => 'hidden',
        ]);

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'name'   => ['type' => 'string'],
            'secret' => ['type' => 'string', 'authorization' => ['read' => ['admin']]],
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $this->propertyRbacHandler->method('filterReadableProperties')
            ->willReturnCallback(function ($schema, $object) {
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
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'       => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name'     => 'Test',
            'fieldA'   => 'remove-me',
            'fieldB'   => 'also-remove',
            'keepMe'   => 'stay',
        ]);

        $this->setupBasicMocks();

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

    // =========================================================================
    // removeQueryParameters - private method
    // =========================================================================

    public function testRemoveQueryParametersWithParams(): void
    {
        $result = $this->invokePrivate('removeQueryParameters', ['schema?key=value']);
        $this->assertSame('schema', $result);
    }

    public function testRemoveQueryParametersWithoutParams(): void
    {
        $result = $this->invokePrivate('removeQueryParameters', ['schema']);
        $this->assertSame('schema', $result);
    }

    public function testRemoveQueryParametersMultipleParams(): void
    {
        $result = $this->invokePrivate('removeQueryParameters', ['path/to/schema?a=1&b=2']);
        $this->assertSame('path/to/schema', $result);
    }

    public function testRemoveQueryParametersEmptyString(): void
    {
        $result = $this->invokePrivate('removeQueryParameters', ['']);
        $this->assertSame('', $result);
    }

    // =========================================================================
    // resolveSchemaReference - private method
    // =========================================================================

    public function testResolveSchemaReferenceNumericId(): void
    {
        $result = $this->invokePrivate('resolveSchemaReference', ['42']);
        $this->assertSame('42', $result);
    }

    public function testResolveSchemaReferenceNumericWithQueryParams(): void
    {
        $result = $this->invokePrivate('resolveSchemaReference', ['42?extend=all']);
        $this->assertSame('42', $result);
    }

    public function testResolveSchemaReferenceByUuidFallsToSlugLookup(): void
    {
        // NOTE: resolveSchemaReference line 2203 uses `=== true` with preg_match()
        // which returns int(1), not bool(true). This means the UUID branch is never
        // entered and UUIDs fall through to the slug lookup path.
        $schema = $this->createSchema(5, '12345678-1234-1234-1234-123456789012');

        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));
        $schemaMapper->method('findAll')
            ->willReturn([$schema]);

        $handler = new RenderObject(
            $this->fileMapper,
            $this->objectEntityMapper,
            $this->registerMapper,
            $schemaMapper,
            $this->systemTagManager,
            $this->systemTagMapper,
            $this->cacheHandler,
            $this->objectCacheService,
            $this->propertyRbacHandler,
            $this->logger,
            $this->fileService
        );

        $ref = new ReflectionClass($handler);
        $m = $ref->getMethod('resolveSchemaReference');
        $m->setAccessible(true);
        $result = $m->invokeArgs($handler, ['12345678-1234-1234-1234-123456789012']);
        $this->assertSame('5', $result);
    }

    public function testResolveSchemaReferenceByUuidNotFound(): void
    {
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));
        $schemaMapper->method('findAll')
            ->willReturn([]);

        $handler = new RenderObject(
            $this->fileMapper,
            $this->objectEntityMapper,
            $this->registerMapper,
            $schemaMapper,
            $this->systemTagManager,
            $this->systemTagMapper,
            $this->cacheHandler,
            $this->objectCacheService,
            $this->propertyRbacHandler,
            $this->logger,
            $this->fileService
        );

        $ref = new ReflectionClass($handler);
        $m = $ref->getMethod('resolveSchemaReference');
        $m->setAccessible(true);
        $result = $m->invokeArgs($handler, ['12345678-1234-1234-1234-123456789012']);
        $this->assertSame('12345678-1234-1234-1234-123456789012', $result);
    }

    public function testResolveSchemaReferenceBySlugPath(): void
    {
        $schema = $this->createSchema(7, 'organisatie');

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));
        $this->schemaMapper->method('findAll')
            ->willReturn([$schema]);

        $result = $this->invokePrivate('resolveSchemaReference', ['#/components/schemas/organisatie']);
        $this->assertSame('7', $result);
    }

    public function testResolveSchemaReferenceBySlugDirect(): void
    {
        $schema = $this->createSchema(3, 'my-schema');

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));
        $this->schemaMapper->method('findAll')
            ->willReturn([$schema]);

        $result = $this->invokePrivate('resolveSchemaReference', ['my-schema']);
        $this->assertSame('3', $result);
    }

    public function testResolveSchemaReferenceFallthrough(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));
        $this->schemaMapper->method('findAll')
            ->willReturn([]);

        $result = $this->invokePrivate('resolveSchemaReference', ['unknown-ref']);
        $this->assertSame('unknown-ref', $result);
    }

    // =========================================================================
    // renderFiles - private method (via renderEntity)
    // =========================================================================

    public function testRenderFilesWithFileRecords(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([
                [
                    'fileid'      => 100,
                    'path'        => '/files/test.pdf',
                    'name'        => 'test.pdf',
                    'accessUrl'   => 'http://localhost/access/100',
                    'downloadUrl' => 'http://localhost/download/100',
                    'mimetype'    => 'application/pdf',
                    'size'        => 12345,
                    'etag'        => 'abc123',
                    'published'   => '2024-01-01',
                    'mtime'       => 1704067200,
                ],
            ]);

        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn(['100' => ['1']]);

        $tag = $this->createMockTag('1', 'important');
        $this->systemTagManager->method('getTagsByIds')
            ->willReturn([$tag]);

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity($entity);

        $files = $result->getFiles();
        $this->assertCount(1, $files);
        $this->assertSame('100', $files[0]['id']);
        $this->assertSame('test.pdf', $files[0]['title']);
        $this->assertSame('pdf', $files[0]['extension']);
        $this->assertSame(12345, $files[0]['size']);
        $this->assertContains('important', $files[0]['labels']);
    }

    public function testRenderFilesFilterOutObjectTags(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([
                [
                    'fileid'    => 200,
                    'path'      => '/files/doc.txt',
                    'name'      => 'doc.txt',
                    'mimetype'  => 'text/plain',
                    'size'      => 100,
                    'etag'      => 'x',
                    'mtime'     => null,
                    'published' => null,
                ],
            ]);

        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn(['200' => ['1', '2']]);

        $objectTag = $this->createMockTag('1', 'object:aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $normalTag = $this->createMockTag('2', 'category-A');
        $this->systemTagManager->method('getTagsByIds')
            ->willReturn([$objectTag, $normalTag]);

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity($entity);

        $files = $result->getFiles();
        $this->assertCount(1, $files);
        // 'object:' tag should be filtered out, only 'category-A' remains.
        $this->assertSame(['category-A'], $files[0]['labels']);
    }

    public function testRenderFilesEmptyRecords(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity($entity);

        $files = $result->getFiles();
        $this->assertSame([], $files);
    }

    public function testRenderFilesNoTags(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([
                [
                    'fileid'   => 300,
                    'path'     => '/files/img.png',
                    'name'     => 'img.png',
                    'mimetype' => 'image/png',
                    'size'     => 5000,
                    'etag'     => 'e1',
                    'mtime'    => null,
                ],
            ]);

        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn(['300' => []]);

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity($entity);

        $files = $result->getFiles();
        $this->assertCount(1, $files);
        $this->assertSame([], $files[0]['labels']);
    }

    // =========================================================================
    // renderFileProperties - private method (via renderEntity)
    // =========================================================================

    public function testRenderFilePropertiesHydratesFileId(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'     => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'avatar' => '42',
        ]);

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'avatar' => ['type' => 'file'],
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $this->fileMapper->method('getFile')
            ->with(42)
            ->willReturn([
                'fileid'      => 42,
                'path'        => '/files/avatar.jpg',
                'name'        => 'avatar.jpg',
                'accessUrl'   => 'http://localhost/access/42',
                'downloadUrl' => 'http://localhost/download/42',
                'mimetype'    => 'image/jpeg',
                'size'        => 1024,
                'etag'        => 'a1',
                'published'   => null,
                'mtime'       => null,
            ]);

        // For getFileTags within getFileObject.
        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn([]);

        $result = $this->handler->renderEntity($entity);
        $objectData = $result->getObject();

        $this->assertIsArray($objectData['avatar']);
        $this->assertSame('42', $objectData['avatar']['id']);
        $this->assertSame('avatar.jpg', $objectData['avatar']['title']);
    }

    public function testRenderFilePropertiesArrayOfFiles(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'    => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'docs'  => ['10', '20'],
        ]);

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'docs' => ['type' => 'array', 'items' => ['type' => 'file']],
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $this->fileMapper->method('getFile')
            ->willReturnCallback(function (int $id) {
                return [
                    'fileid'      => $id,
                    'path'        => '/files/doc' . $id . '.pdf',
                    'name'        => 'doc' . $id . '.pdf',
                    'accessUrl'   => null,
                    'downloadUrl' => null,
                    'mimetype'    => 'application/pdf',
                    'size'        => 100,
                    'etag'        => 'e',
                    'published'   => null,
                    'mtime'       => null,
                ];
            });

        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn([]);

        $result = $this->handler->renderEntity($entity);
        $objectData = $result->getObject();

        $this->assertIsArray($objectData['docs']);
        $this->assertCount(2, $objectData['docs']);
    }

    public function testRenderFilePropertiesSkipsMetadataProperties(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'      => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            '@self'   => ['schema' => 1],
            'name'    => 'Test',
        ]);

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'name' => ['type' => 'string'],
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $result = $this->handler->renderEntity($entity);
        $objectData = $result->getObject();

        // @self should remain unchanged.
        $this->assertSame('Test', $objectData['name']);
    }

    public function testRenderFilePropertiesNoSchemaReturnsUnchanged(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'     => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'avatar' => '42',
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity($entity);
        $objectData = $result->getObject();

        // Without a schema, the file ID stays as-is.
        $this->assertSame('42', $objectData['avatar']);
    }

    public function testRenderFilePropertiesInitializesEmptyArrayProperty(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            // 'docs' is not set at all in object data.
        ]);

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'docs' => ['type' => 'array', 'items' => ['type' => 'file']],
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $result = $this->handler->renderEntity($entity);
        $objectData = $result->getObject();

        // Array file properties should be initialized to empty array.
        $this->assertSame([], $objectData['docs']);
    }

    // =========================================================================
    // hydrateFileProperty - private method
    // =========================================================================

    public function testHydrateFilePropertyNonArrayNonNumericReturnsUnchanged(): void
    {
        $result = $this->invokePrivate('hydrateFileProperty', [
            'some-string',
            ['type' => 'file'],
            'field',
        ]);

        $this->assertSame('some-string', $result);
    }

    public function testHydrateFilePropertyArrayPropertyNonArrayValueReturnsUnchanged(): void
    {
        $result = $this->invokePrivate('hydrateFileProperty', [
            'not-array',
            ['type' => 'array', 'items' => ['type' => 'file']],
            'field',
        ]);

        $this->assertSame('not-array', $result);
    }

    public function testHydrateFilePropertySingleFileNumeric(): void
    {
        $this->fileMapper->method('getFile')
            ->with(42)
            ->willReturn([
                'fileid'    => 42,
                'path'      => '/test.txt',
                'name'      => 'test.txt',
                'mimetype'  => 'text/plain',
                'size'      => 10,
                'etag'      => 'x',
                'mtime'     => null,
                'published' => null,
            ]);
        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn([]);

        $result = $this->invokePrivate('hydrateFileProperty', [
            42,
            ['type' => 'file'],
            'avatar',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('42', $result['id']);
    }

    public function testHydrateFilePropertySingleFileDigitString(): void
    {
        $this->fileMapper->method('getFile')
            ->with(99)
            ->willReturn([
                'fileid'    => 99,
                'path'      => '/doc.pdf',
                'name'      => 'doc.pdf',
                'mimetype'  => 'application/pdf',
                'size'      => 500,
                'etag'      => 'y',
                'mtime'     => null,
                'published' => null,
            ]);
        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn([]);

        $result = $this->invokePrivate('hydrateFileProperty', [
            '99',
            ['type' => 'file'],
            'document',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('99', $result['id']);
    }

    // =========================================================================
    // getFileAsBase64 - private method
    // =========================================================================

    public function testGetFileAsBase64ReturnsDataUri(): void
    {
        $mockFile = $this->createMock(\OCP\Files\File::class);
        $mockFile->method('getContent')->willReturn('hello world');
        $mockFile->method('getMimeType')->willReturn('text/plain');

        $this->fileService->method('getFileById')
            ->with(42)
            ->willReturn($mockFile);

        $result = $this->invokePrivate('getFileAsBase64', [42]);

        $expected = 'data:text/plain;base64,' . base64_encode('hello world');
        $this->assertSame($expected, $result);
    }

    public function testGetFileAsBase64ReturnsNullForZeroId(): void
    {
        $result = $this->invokePrivate('getFileAsBase64', [0]);
        $this->assertNull($result);
    }

    public function testGetFileAsBase64ReturnsNullForNegativeId(): void
    {
        $result = $this->invokePrivate('getFileAsBase64', [-1]);
        $this->assertNull($result);
    }

    public function testGetFileAsBase64ReturnsNullWhenFileNotFound(): void
    {
        $this->fileService->method('getFileById')
            ->willReturn(null);

        $result = $this->invokePrivate('getFileAsBase64', [999]);
        $this->assertNull($result);
    }

    public function testGetFileAsBase64ReturnsNullForEmptyContent(): void
    {
        $mockFile = $this->createMock(\OCP\Files\File::class);
        $mockFile->method('getContent')->willReturn('');
        $mockFile->method('getMimeType')->willReturn('text/plain');

        $this->fileService->method('getFileById')
            ->willReturn($mockFile);

        $result = $this->invokePrivate('getFileAsBase64', [42]);
        $this->assertNull($result);
    }

    public function testGetFileAsBase64ReturnsNullOnException(): void
    {
        $this->fileService->method('getFileById')
            ->willThrowException(new Exception('File error'));

        $result = $this->invokePrivate('getFileAsBase64', [42]);
        $this->assertNull($result);
    }

    public function testGetFileAsBase64WithGenericMimeType(): void
    {
        $mockFile = $this->createMock(\OCP\Files\File::class);
        $mockFile->method('getContent')->willReturn('binary-data');
        $mockFile->method('getMimeType')->willReturn('application/octet-stream');

        $this->fileService->method('getFileById')
            ->willReturn($mockFile);

        $result = $this->invokePrivate('getFileAsBase64', [42]);

        $this->assertStringStartsWith('data:application/octet-stream;base64,', $result);
    }

    // =========================================================================
    // getFileObject - private method
    // =========================================================================

    public function testGetFileObjectReturnsFormattedArray(): void
    {
        $this->fileMapper->method('getFile')
            ->with(50)
            ->willReturn([
                'fileid'      => 50,
                'path'        => '/files/report.xlsx',
                'name'        => 'report.xlsx',
                'accessUrl'   => 'http://localhost/access/50',
                'downloadUrl' => 'http://localhost/download/50',
                'mimetype'    => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'size'        => 8192,
                'etag'        => 'abc',
                'published'   => '2024-06-01',
                'mtime'       => 1717200000,
            ]);
        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn(['50' => ['1']]);
        $tag = $this->createMockTag('1', 'report');
        $this->systemTagManager->method('getTagsByIds')
            ->willReturn([$tag]);

        $result = $this->invokePrivate('getFileObject', [50]);

        $this->assertIsArray($result);
        $this->assertSame('50', $result['id']);
        $this->assertSame('/files/report.xlsx', $result['path']);
        $this->assertSame('report.xlsx', $result['title']);
        $this->assertSame('xlsx', $result['extension']);
        $this->assertSame(8192, $result['size']);
        $this->assertSame(['report'], $result['labels']);
    }

    public function testGetFileObjectReturnsNullForEmptyResult(): void
    {
        $this->fileMapper->method('getFile')
            ->willReturn([]);

        $result = $this->invokePrivate('getFileObject', [999]);
        $this->assertNull($result);
    }

    public function testGetFileObjectReturnsNullOnException(): void
    {
        $this->fileMapper->method('getFile')
            ->willThrowException(new Exception('DB error'));

        $result = $this->invokePrivate('getFileObject', [999]);
        $this->assertNull($result);
    }

    public function testGetFileObjectReturnsNullForNonNumericInput(): void
    {
        $result = $this->invokePrivate('getFileObject', [['array-input']]);
        $this->assertNull($result);
    }

    // =========================================================================
    // getFileTags - private method
    // =========================================================================

    public function testGetFileTagsReturnsTags(): void
    {
        $this->systemTagMapper->method('getTagIdsForObjects')
            ->with(['42'], 'files')
            ->willReturn(['42' => ['1', '2']]);

        $tag1 = $this->createMockTag('1', 'important');
        $tag2 = $this->createMockTag('2', 'reviewed');
        $this->systemTagManager->method('getTagsByIds')
            ->with(['1', '2'])
            ->willReturn([$tag1, $tag2]);

        $result = $this->invokePrivate('getFileTags', ['42']);

        $this->assertCount(2, $result);
        $this->assertContains('important', $result);
        $this->assertContains('reviewed', $result);
    }

    public function testGetFileTagsFiltersObjectTags(): void
    {
        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn(['42' => ['1', '2']]);

        $objectTag = $this->createMockTag('1', 'object:some-uuid');
        $normalTag = $this->createMockTag('2', 'normal-tag');
        $this->systemTagManager->method('getTagsByIds')
            ->willReturn([$objectTag, $normalTag]);

        $result = $this->invokePrivate('getFileTags', ['42']);

        $this->assertCount(1, $result);
        $this->assertContains('normal-tag', $result);
    }

    public function testGetFileTagsReturnsEmptyWhenNoTags(): void
    {
        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn(['42' => []]);

        $result = $this->invokePrivate('getFileTags', ['42']);

        $this->assertSame([], $result);
    }

    public function testGetFileTagsReturnsEmptyWhenFileNotFound(): void
    {
        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn([]);

        $result = $this->invokePrivate('getFileTags', ['99']);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // hydrateMetadataFromFileProperties - via renderEntity
    // =========================================================================

    public function testHydrateMetadataFromFilePropertiesSetsImage(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'   => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'logo' => [
                'id'          => '42',
                'downloadUrl' => 'http://localhost/download/42',
                'accessUrl'   => 'http://localhost/access/42',
            ],
        ]);

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'logo' => ['type' => 'file'],
        ]);
        $schema->setConfiguration(['objectImageField' => 'logo']);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $result = $this->handler->renderEntity($entity);

        // Image should be set to downloadUrl.
        $this->assertSame('http://localhost/download/42', $result->getImage());
    }

    public function testHydrateMetadataFromFilePropertiesFallsBackToAccessUrl(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'   => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'logo' => [
                'id'        => '42',
                'accessUrl' => 'http://localhost/access/42',
            ],
        ]);

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'logo' => ['type' => 'file'],
        ]);
        $schema->setConfiguration(['objectImageField' => 'logo']);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $result = $this->handler->renderEntity($entity);

        $this->assertSame('http://localhost/access/42', $result->getImage());
    }

    public function testHydrateMetadataFromFilePropertiesSetsNullForEmptyField(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'   => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'logo' => null,
        ]);

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'logo' => ['type' => 'file'],
        ]);
        $schema->setConfiguration(['objectImageField' => 'logo']);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $result = $this->handler->renderEntity($entity);

        $this->assertNull($result->getImage());
    }

    public function testHydrateMetadataNoObjectImageField(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'   => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'logo' => '42',
        ]);

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'logo' => ['type' => 'file'],
        ]);
        // No objectImageField in configuration.
        $schema->setConfiguration([]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        // getFileObject needs to return something since renderFileProperties runs first.
        $this->fileMapper->method('getFile')
            ->willReturn([]);
        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn([]);

        $result = $this->handler->renderEntity($entity);

        // Image should remain null (no objectImageField configured).
        $this->assertNull($result->getImage());
    }

    // =========================================================================
    // renderEntities - string parameter conversions
    // =========================================================================

    public function testRenderEntitiesConvertsStringExtend(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntities([$entity], 'fieldA,fieldB');

        $this->assertCount(1, $result);
    }

    public function testRenderEntitiesConvertsStringFields(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'status' => 'active',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntities([$entity], [], null, 'name');

        $this->assertCount(1, $result);
        $objectData = $result[0]->getObject();
        $this->assertArrayHasKey('name', $objectData);
        $this->assertArrayNotHasKey('status', $objectData);
    }

    public function testRenderEntitiesConvertsStringUnset(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'secret' => 'hidden',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntities([$entity], [], null, null, 'secret');

        $this->assertCount(1, $result);
        $objectData = $result[0]->getObject();
        $this->assertArrayNotHasKey('secret', $objectData);
    }

    public function testRenderEntitiesWithNullExtend(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntities([$entity], null);

        $this->assertCount(1, $result);
    }

    public function testRenderEntitiesClearsSourceFromSelf(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);
        $entity->setSource('http://example.com/original');

        $this->setupBasicMocks();

        $result = $this->handler->renderEntities([$entity]);

        // Source should be null in list responses.
        $this->assertNull($result[0]->getSource());
    }

    public function testRenderEntitiesBatchPreloadsObjects(): void
    {
        $relatedUuid = 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff';
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'      => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'related' => $relatedUuid,
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        // Expect preloadObjects to be called at least once with the related UUID.
        $this->objectCacheService->expects($this->atLeastOnce())
            ->method('preloadObjects')
            ->willReturn([]);

        $this->handler->renderEntities([$entity], ['related']);
    }

    public function testRenderEntitiesWithOnlyValidEntities(): void
    {
        $entity1 = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);
        $entity2 = $this->createBasicEntity(2, 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff', [
            'id' => 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntities([$entity1, $entity2]);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(ObjectEntity::class, $result[0]);
        $this->assertInstanceOf(ObjectEntity::class, $result[1]);
    }

    // =========================================================================
    // renderEntity — extend with both @self.register and @self.schema
    // =========================================================================

    public function testRenderEntityExtendBothSelfRegisterAndSchema(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'   => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

        $register = $this->createRegister(1);
        $schema = $this->createSchema(1);

        $this->registerMapper->method('find')
            ->willReturn($register);
        $this->schemaMapper->method('find')
            ->willReturn($schema);
        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->objectCacheService->method('preloadObjects')
            ->willReturn([]);

        $result = $this->handler->renderEntity(
            $entity,
            ['@self.register', '@self.schema']
        );

        $objectData = $result->getObject();
        $this->assertArrayHasKey('@self', $objectData);
        $this->assertArrayHasKey('register', $objectData['@self']);
        $this->assertArrayHasKey('schema', $objectData['@self']);
    }

    // =========================================================================
    // renderEntity — entity with null uuid
    // =========================================================================

    public function testRenderEntityWithNullUuidNoCircularDetection(): void
    {
        $entity = new ObjectEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, 1);
        $entity->setObject([
            'id' => null,
            'name' => 'No UUID entity',
        ]);
        $entity->setSchema(1);
        $entity->setRegister(1);

        $this->setupBasicMocks();

        // Should not crash even without UUID.
        $result = $this->handler->renderEntity($entity);
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    // =========================================================================
    // renderEntity — RBAC with @self already in data
    // =========================================================================

    public function testRenderEntityRbacWithExistingSelf(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'    => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name'  => 'Test',
            '@self' => ['organisation' => 'org-1', 'owner' => 'admin', 'extra' => 'data'],
        ]);

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'name' => ['type' => 'string', 'authorization' => ['read' => ['admin']]],
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willReturn($schema);

        $this->propertyRbacHandler->method('filterReadableProperties')
            ->willReturnArgument(1);

        $result = $this->handler->renderEntity($entity);
        $objectData = $result->getObject();

        // @self should be preserved since it has more than 2 keys.
        $this->assertArrayHasKey('@self', $objectData);
    }

    // =========================================================================
    // indexReferencingObjects - private method
    // =========================================================================

    public function testIndexReferencingObjects(): void
    {
        $refObject = $this->createObjectEntity(10, 'ref-uuid-1', [
            'parent' => 'entity-uuid-1',
        ]);

        $this->invokePrivate('initializeInverseCacheEntries', [['entity-uuid-1'], 'children']);
        $this->invokePrivate('indexReferencingObjects', [
            [$refObject],
            ['parent'],
            ['entity-uuid-1'],
            'children',
        ]);

        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('inverseRelationCache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue($this->handler);

        $this->assertCount(1, $cache['entity-uuid-1_children']);
        $this->assertSame($refObject, $cache['entity-uuid-1_children'][0]);
    }

    public function testIndexReferencingObjectsAvoidsDuplicates(): void
    {
        $refObject = $this->createObjectEntity(10, 'ref-uuid-1', [
            'fieldA' => 'entity-uuid-1',
            'fieldB' => 'entity-uuid-1',
        ]);

        $this->invokePrivate('initializeInverseCacheEntries', [['entity-uuid-1'], 'children']);
        $this->invokePrivate('indexReferencingObjects', [
            [$refObject],
            ['fieldA', 'fieldB'],
            ['entity-uuid-1'],
            'children',
        ]);

        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('inverseRelationCache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue($this->handler);

        // Same object matching on 2 fields should only appear once.
        $this->assertCount(1, $cache['entity-uuid-1_children']);
    }

    public function testIndexReferencingObjectsObjectValueFormat(): void
    {
        $refObject = $this->createObjectEntity(10, 'ref-uuid-1', [
            'parent' => ['value' => 'entity-uuid-1'],
        ]);

        $this->invokePrivate('initializeInverseCacheEntries', [['entity-uuid-1'], 'children']);
        $this->invokePrivate('indexReferencingObjects', [
            [$refObject],
            ['parent'],
            ['entity-uuid-1'],
            'children',
        ]);

        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('inverseRelationCache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue($this->handler);

        $this->assertCount(1, $cache['entity-uuid-1_children']);
    }

    public function testIndexReferencingObjectsArrayOfUuids(): void
    {
        $refObject = $this->createObjectEntity(10, 'ref-uuid-1', [
            'parents' => ['entity-uuid-1', 'entity-uuid-2'],
        ]);

        $this->invokePrivate('initializeInverseCacheEntries', [['entity-uuid-1', 'entity-uuid-2'], 'children']);
        $this->invokePrivate('indexReferencingObjects', [
            [$refObject],
            ['parents'],
            ['entity-uuid-1', 'entity-uuid-2'],
            'children',
        ]);

        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('inverseRelationCache');
        $cacheProp->setAccessible(true);
        $cache = $cacheProp->getValue($this->handler);

        $this->assertCount(1, $cache['entity-uuid-1_children']);
        $this->assertCount(1, $cache['entity-uuid-2_children']);
    }

    // =========================================================================
    // handleInversedPropertiesFromCache - private method
    // =========================================================================

    public function testHandleInversedPropertiesFromCacheArrayProperty(): void
    {
        $entity = $this->createBasicEntity(1, 'entity-uuid-1', [
            'id'   => 'entity-uuid-1',
            'name' => 'Test',
        ]);

        $childEntity = $this->createBasicEntity(10, 'child-uuid-1', [
            'id'     => 'child-uuid-1',
            'parent' => 'entity-uuid-1',
        ]);

        // Set up inverse relation cache.
        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('inverseRelationCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->handler, [
            'entity-uuid-1_children' => [$childEntity],
        ]);

        $inversedProperties = [
            'children' => [
                'type'  => 'array',
                'items' => ['inversedBy' => 'parent', '$ref' => '1'],
            ],
        ];

        // Need schema and file mocks for the recursive renderEntity call.
        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->invokePrivate('handleInversedPropertiesFromCache', [
            $entity,
            ['id' => 'entity-uuid-1', 'name' => 'Test'],
            $inversedProperties,
        ]);

        $this->assertArrayHasKey('children', $result);
        $this->assertIsArray($result['children']);
        $this->assertCount(1, $result['children']);
    }

    public function testHandleInversedPropertiesFromCacheEmptyCache(): void
    {
        $entity = $this->createBasicEntity(1, 'entity-uuid-1', [
            'id' => 'entity-uuid-1',
        ]);

        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('inverseRelationCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->handler, [
            'entity-uuid-1_children' => [],
        ]);

        $inversedProperties = [
            'children' => [
                'type'  => 'array',
                'items' => ['inversedBy' => 'parent', '$ref' => '1'],
            ],
        ];

        $result = $this->invokePrivate('handleInversedPropertiesFromCache', [
            $entity,
            ['id' => 'entity-uuid-1'],
            $inversedProperties,
        ]);

        $this->assertArrayHasKey('children', $result);
        $this->assertSame([], $result['children']);
    }

    public function testHandleInversedPropertiesFromCacheDirectInversedBy(): void
    {
        $entity = $this->createBasicEntity(1, 'entity-uuid-1', [
            'id' => 'entity-uuid-1',
        ]);

        $childEntity = $this->createBasicEntity(10, 'child-uuid-1', [
            'id' => 'child-uuid-1',
        ]);

        $ref = new ReflectionClass($this->handler);
        $cacheProp = $ref->getProperty('inverseRelationCache');
        $cacheProp->setAccessible(true);
        $cacheProp->setValue($this->handler, [
            'entity-uuid-1_child' => [$childEntity],
        ]);

        // Direct inversedBy (not in items).
        $inversedProperties = [
            'child' => [
                'type'       => 'object',
                'inversedBy' => 'parent',
                '$ref'       => '1',
            ],
        ];

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->invokePrivate('handleInversedPropertiesFromCache', [
            $entity,
            ['id' => 'entity-uuid-1'],
            $inversedProperties,
        ]);

        $this->assertArrayHasKey('child', $result);
        // Single value, not array.
        $this->assertIsArray($result['child']);
        $this->assertArrayHasKey('id', $result['child']);
    }

    public function testHandleInversedPropertiesFromCacheSkipsNoInversedBy(): void
    {
        $entity = $this->createBasicEntity(1, 'entity-uuid-1', [
            'id' => 'entity-uuid-1',
        ]);

        $inversedProperties = [
            'normal' => [
                'type' => 'string',
            ],
        ];

        $result = $this->invokePrivate('handleInversedPropertiesFromCache', [
            $entity,
            ['id' => 'entity-uuid-1'],
            $inversedProperties,
        ]);

        // Property without inversedBy is skipped.
        $this->assertArrayNotHasKey('normal', $result);
    }

    // =========================================================================
    // preloadInverseRelationships - private method
    // =========================================================================

    public function testPreloadInverseRelationshipsEmptyEntities(): void
    {
        // Should return early without error.
        $this->invokePrivate('preloadInverseRelationships', [[], ['all']]);

        // No crash = success.
        $this->assertTrue(true);
    }

    public function testPreloadInverseRelationshipsEmptyExtend(): void
    {
        $entity = $this->createBasicEntity(1, 'uuid-1', []);

        $this->invokePrivate('preloadInverseRelationships', [[$entity], []]);

        $this->assertTrue(true);
    }

    public function testPreloadInverseRelationshipsNonObjectEntity(): void
    {
        // Non-ObjectEntity should be skipped.
        $this->invokePrivate('preloadInverseRelationships', [['not-an-entity'], ['all']]);

        $this->assertTrue(true);
    }

    // =========================================================================
    // Regression / Edge cases
    // =========================================================================

    public function testRenderEntityWithEmptyObjectData(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', []);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntity($entity);
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    public function testRenderEntityFilterWithNonExistentKey(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'   => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

        $this->setupBasicMocks();

        // Filter on a key that doesn't exist in the data - should pass.
        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            ['nonexistent' => 'value']
        );

        $objectData = $result->getObject();
        $this->assertArrayHasKey('name', $objectData);
    }

    public function testRenderEntityFieldFilterAlwaysIncludesSelfAndId(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'    => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            '@self' => ['schema' => 1],
            'name'  => 'Test',
            'other' => 'excluded',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            [],
            ['name'],
            []
        );

        $objectData = $result->getObject();
        $this->assertArrayHasKey('name', $objectData);
        $this->assertArrayHasKey('id', $objectData);
        $this->assertArrayHasKey('@self', $objectData);
        $this->assertArrayNotHasKey('other', $objectData);
    }

    public function testRenderEntityUnsetNonExistentKeyNoError(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'   => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);

        $this->setupBasicMocks();

        $result = $this->handler->renderEntity(
            $entity,
            [],
            0,
            [],
            [],
            ['nonexistent']
        );

        $objectData = $result->getObject();
        $this->assertArrayHasKey('name', $objectData);
    }

    public function testRenderEntityPreloadedObjectsPopulateCache(): void
    {
        $preloadedObj = $this->createObjectEntity(99, 'pre-uuid', ['name' => 'Preloaded']);

        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        $this->setupBasicMocks();

        $this->handler->renderEntity(
            $entity,
            [],
            0,
            [],
            [],
            [],
            [],
            [],
            ['pre-uuid' => $preloadedObj]
        );

        // Preloaded object should be findable via getObject.
        $result = $this->invokePrivate('getObject', ['pre-uuid']);
        $this->assertSame($preloadedObj, $result);
    }

    public function testRenderFilesMultipleFilesMultipleTags(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([
                [
                    'fileid'   => 100,
                    'path'     => '/a.txt',
                    'name'     => 'a.txt',
                    'mimetype' => 'text/plain',
                    'size'     => 10,
                    'etag'     => 'e1',
                    'mtime'    => null,
                ],
                [
                    'fileid'   => 200,
                    'path'     => '/b.pdf',
                    'name'     => 'b.pdf',
                    'mimetype' => 'application/pdf',
                    'size'     => 20,
                    'etag'     => 'e2',
                    'mtime'    => null,
                ],
            ]);

        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn([
                '100' => ['1'],
                '200' => ['2', '3'],
            ]);

        $tag1 = $this->createMockTag('1', 'tag-a');
        $tag2 = $this->createMockTag('2', 'tag-b');
        $tag3 = $this->createMockTag('3', 'object:skip-me');
        $this->systemTagManager->method('getTagsByIds')
            ->willReturn([$tag1, $tag2, $tag3]);

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity($entity);
        $files = $result->getFiles();

        $this->assertCount(2, $files);
        $this->assertSame('txt', $files[0]['extension']);
        $this->assertSame('pdf', $files[1]['extension']);
    }

    public function testRenderFilesMissingMimetypeFallback(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        $this->fileMapper->method('getFilesForObject')
            ->willReturn([
                [
                    'fileid' => 100,
                    'path'   => '/file.bin',
                    'name'   => 'file.bin',
                    'size'   => 1,
                    'etag'   => 'x',
                    'mtime'  => null,
                    // No mimetype key.
                ],
            ]);

        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn(['100' => []]);

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->handler->renderEntity($entity);
        $files = $result->getFiles();

        $this->assertSame('application/octet-stream', $files[0]['type']);
    }

    // =========================================================================
    // hydrateFileProperty base64 format
    // =========================================================================

    public function testHydrateFilePropertyBase64Single(): void
    {
        $mockFile = $this->createMock(\OCP\Files\File::class);
        $mockFile->method('getContent')->willReturn('data');
        $mockFile->method('getMimeType')->willReturn('text/plain');

        $this->fileService->method('getFileById')
            ->with(10)
            ->willReturn($mockFile);

        $result = $this->invokePrivate('hydrateFileProperty', [
            10,
            ['type' => 'file', 'format' => 'base64'],
            'avatar',
        ]);

        $this->assertStringStartsWith('data:text/plain;base64,', $result);
    }

    public function testHydrateFilePropertyBase64Array(): void
    {
        $mockFile = $this->createMock(\OCP\Files\File::class);
        $mockFile->method('getContent')->willReturn('data');
        $mockFile->method('getMimeType')->willReturn('image/png');

        $this->fileService->method('getFileById')
            ->willReturn($mockFile);

        $result = $this->invokePrivate('hydrateFileProperty', [
            [10, 20],
            ['type' => 'array', 'items' => ['type' => 'file', 'format' => 'base64']],
            'images',
        ]);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertStringStartsWith('data:image/png;base64,', $result[0]);
    }

    public function testHydrateFilePropertyBase64ArraySkipsNulls(): void
    {
        $this->fileService->method('getFileById')
            ->willReturn(null);

        $result = $this->invokePrivate('hydrateFileProperty', [
            [10],
            ['type' => 'array', 'items' => ['type' => 'file', 'format' => 'base64']],
            'images',
        ]);

        // Null results are filtered out.
        $this->assertSame([], $result);
    }

    public function testHydrateFilePropertyArrayNonBase64(): void
    {
        $this->fileMapper->method('getFile')
            ->willReturn([
                'fileid'    => 10,
                'path'      => '/f.txt',
                'name'      => 'f.txt',
                'mimetype'  => 'text/plain',
                'size'      => 5,
                'etag'      => 'x',
                'mtime'     => null,
                'published' => null,
            ]);
        $this->systemTagMapper->method('getTagIdsForObjects')
            ->willReturn([]);

        $result = $this->invokePrivate('hydrateFileProperty', [
            [10],
            ['type' => 'array', 'items' => ['type' => 'file']],
            'docs',
        ]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertIsArray($result[0]);
        $this->assertSame('10', $result[0]['id']);
    }

    public function testHydrateFilePropertyArraySkipsNullFileObjects(): void
    {
        $this->fileMapper->method('getFile')
            ->willReturn([]);

        $result = $this->invokePrivate('hydrateFileProperty', [
            [999],
            ['type' => 'array', 'items' => ['type' => 'file']],
            'docs',
        ]);

        $this->assertSame([], $result);
    }

    // =========================================================================
    // renderEntities — filter as string
    // =========================================================================

    public function testRenderEntitiesConvertsStringFilter(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id'     => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'status' => 'active',
        ]);

        $this->setupBasicMocks();

        // String filter gets converted to array via explode.
        $result = $this->handler->renderEntities([$entity], [], 'status');

        $this->assertCount(1, $result);
    }

    // =========================================================================
    // collectUuidsForExtend — with URL values
    // =========================================================================

    public function testCollectUuidsForExtendWithUrlContainingUuid(): void
    {
        $uuid = '12345678-1234-1234-1234-123456789012';
        $objectData = [
            'ref' => 'http://example.com/api/objects/' . $uuid,
        ];
        $extend = ['ref'];

        $result = $this->invokePrivate('collectUuidsForExtend', [$objectData, $extend]);

        // URLs are not UUIDs, so not collected here.
        $this->assertEmpty($result);
    }
}
