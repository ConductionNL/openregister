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
}
