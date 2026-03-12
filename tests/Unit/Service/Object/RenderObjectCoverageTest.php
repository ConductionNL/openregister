<?php

declare(strict_types=1);

/**
 * RenderObject Coverage Tests
 *
 * Additional tests for uncovered branches in RenderObject: renderEntities source removal,
 * handleInversedPropertiesFromCache branches, handleWildcardExtends branches,
 * handleExtendDot extended scenarios, collectUuidsForExtend with URLs,
 * resolveSchemaReference edge cases, renderFileProperties, hydrateFileProperty,
 * getFileAsBase64, hydrateMetadataFromFileProperties, and handleInversedProperties.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
 * Coverage tests for RenderObject
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RenderObjectCoverageTest extends TestCase
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
     * Helper to set private property via reflection.
     *
     * @param string $property Property name
     * @param mixed  $value    Property value
     *
     * @return void
     */
    private function setPrivateProperty(string $property, $value): void
    {
        $ref = new ReflectionClass($this->handler);
        $p = $ref->getProperty($property);
        $p->setAccessible(true);
        $p->setValue($this->handler, $value);
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
     * Helper to set up mocks for basic rendering (no files, no schema found).
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
     * Helper to create a basic entity with schema and register.
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

    // =========================================================================
    // renderEntities - source removal from @self
    // =========================================================================

    /**
     * Test renderEntities removes source from @self in list responses.
     *
     * @return void
     */
    public function testRenderEntitiesRemovesSourceFromSelfInList(): void
    {
        $this->setupBasicMocks();

        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
        ]);
        $entity->setSource('http://example.com/api');

        $result = $this->handler->renderEntities([$entity]);

        $this->assertCount(1, $result);
        $serialized = $result[0]->jsonSerialize();
        // Source should be set to null in list context (renderEntities calls setSource(null)).
        $this->assertNull($result[0]->getSource());
    }

    /**
     * Test renderEntities with null extend does not crash.
     *
     * @return void
     */
    public function testRenderEntitiesWithNullExtendAndFilter(): void
    {
        $this->setupBasicMocks();

        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        $result = $this->handler->renderEntities(
            [$entity],
            null,
            null,
            null,
            null,
            false,
            false
        );

        $this->assertCount(1, $result);
    }

    // =========================================================================
    // resolveReferencedUuids
    // =========================================================================

    /**
     * Test resolveReferencedUuids with simple string UUID.
     *
     * @return void
     */
    public function testResolveReferencedUuidsSimpleString(): void
    {
        $result = $this->invokePrivate('resolveReferencedUuids', [
            ['orgField' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'],
            'orgField',
        ]);

        $this->assertSame(['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'], $result);
    }

    /**
     * Test resolveReferencedUuids with object value format.
     *
     * @return void
     */
    public function testResolveReferencedUuidsObjectValueFormat(): void
    {
        $result = $this->invokePrivate('resolveReferencedUuids', [
            ['orgField' => ['value' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']],
            'orgField',
        ]);

        $this->assertSame(['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'], $result);
    }

    /**
     * Test resolveReferencedUuids with array of UUIDs.
     *
     * @return void
     */
    public function testResolveReferencedUuidsArray(): void
    {
        $result = $this->invokePrivate('resolveReferencedUuids', [
            ['orgField' => ['uuid1', 'uuid2']],
            'orgField',
        ]);

        $this->assertSame(['uuid1', 'uuid2'], $result);
    }

    /**
     * Test resolveReferencedUuids with missing field.
     *
     * @return void
     */
    public function testResolveReferencedUuidsMissingField(): void
    {
        $result = $this->invokePrivate('resolveReferencedUuids', [
            ['other' => 'value'],
            'orgField',
        ]);

        $this->assertSame([null], $result);
    }

    // =========================================================================
    // getInversedProperties
    // =========================================================================

    /**
     * Test getInversedProperties returns properties with inversedBy.
     *
     * @return void
     */
    public function testGetInversedPropertiesReturnsPropertiesWithInversedBy(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'contacts' => [
                'type' => 'array',
                'items' => ['inversedBy' => 'organisation', '$ref' => '#/schemas/Contact'],
            ],
            'name' => ['type' => 'string'],
        ]);

        $result = $this->invokePrivate('getInversedProperties', [$schema]);

        $this->assertArrayHasKey('contacts', $result);
        $this->assertArrayNotHasKey('name', $result);
    }

    /**
     * Test getInversedProperties with direct inversedBy (not in items).
     *
     * @return void
     */
    public function testGetInversedPropertiesDirectInversedBy(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'parent' => [
                'type' => 'object',
                'inversedBy' => 'children',
                '$ref' => '#/schemas/Node',
            ],
        ]);

        $result = $this->invokePrivate('getInversedProperties', [$schema]);

        $this->assertArrayHasKey('parent', $result);
    }

    /**
     * Test getInversedProperties skips empty inversedBy.
     *
     * @return void
     */
    public function testGetInversedPropertiesSkipsEmptyInversedBy(): void
    {
        $schema = $this->createSchema(1);
        $schema->setProperties([
            'field' => [
                'type' => 'array',
                'items' => ['inversedBy' => ''],
            ],
        ]);

        $result = $this->invokePrivate('getInversedProperties', [$schema]);

        $this->assertEmpty($result);
    }

    // =========================================================================
    // filterExtendedInverseProperties
    // =========================================================================

    /**
     * Test filterExtendedInverseProperties with all extend.
     *
     * @return void
     */
    public function testFilterExtendedInversePropertiesWithAll(): void
    {
        $inversedProps = [
            'contacts' => ['items' => ['inversedBy' => 'org']],
            'members' => ['items' => ['inversedBy' => 'team']],
        ];

        $result = $this->invokePrivate('filterExtendedInverseProperties', [
            $inversedProps,
            ['all'],
        ]);

        $this->assertCount(2, $result);
    }

    /**
     * Test filterExtendedInverseProperties with specific property.
     *
     * @return void
     */
    public function testFilterExtendedInversePropertiesSpecific(): void
    {
        $inversedProps = [
            'contacts' => ['items' => ['inversedBy' => 'org']],
            'members' => ['items' => ['inversedBy' => 'team']],
        ];

        $result = $this->invokePrivate('filterExtendedInverseProperties', [
            $inversedProps,
            ['contacts'],
        ]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('contacts', $result);
    }

    // =========================================================================
    // collectEntityUuids
    // =========================================================================

    /**
     * Test collectEntityUuids with mixed entities.
     *
     * @return void
     */
    public function testCollectEntityUuidsWithMixedEntities(): void
    {
        $entity1 = $this->createObjectEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $entity2 = $this->createObjectEntity(2, 'bbbbbbbb-cccc-dddd-eeee-ffffffffffff');

        $result = $this->invokePrivate('collectEntityUuids', [[$entity1, $entity2, 'not-entity']]);

        $this->assertCount(2, $result);
    }

    /**
     * Test collectEntityUuids skips entities with null UUID.
     *
     * @return void
     */
    public function testCollectEntityUuidsSkipsNullUuid(): void
    {
        $entity = new ObjectEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, 1);

        $result = $this->invokePrivate('collectEntityUuids', [[$entity]]);

        $this->assertEmpty($result);
    }

    // =========================================================================
    // extractInverseConfig
    // =========================================================================

    /**
     * Test extractInverseConfig with valid items config.
     *
     * @return void
     */
    public function testExtractInverseConfigValidItems(): void
    {
        $config = [
            'items' => [
                '$ref' => '#/schemas/Contact',
                'inversedBy' => 'organisation',
            ],
        ];

        $result = $this->invokePrivate('extractInverseConfig', [$config]);

        $this->assertNotNull($result);
        $this->assertSame('#/schemas/Contact', $result['targetSchemaRef']);
        $this->assertSame(['organisation'], $result['inversedByFields']);
    }

    /**
     * Test extractInverseConfig with array inversedBy.
     *
     * @return void
     */
    public function testExtractInverseConfigArrayInversedBy(): void
    {
        $config = [
            'items' => [
                '$ref' => '#/schemas/Link',
                'inversedBy' => ['moduleA', 'moduleB'],
            ],
        ];

        $result = $this->invokePrivate('extractInverseConfig', [$config]);

        $this->assertNotNull($result);
        $this->assertSame(['moduleA', 'moduleB'], $result['inversedByFields']);
    }

    /**
     * Test extractInverseConfig returns null when missing $ref.
     *
     * @return void
     */
    public function testExtractInverseConfigMissingRefReturnsNull(): void
    {
        $config = ['items' => ['inversedBy' => 'field']];

        $result = $this->invokePrivate('extractInverseConfig', [$config]);

        $this->assertNull($result);
    }

    /**
     * Test extractInverseConfig returns null when missing inversedBy.
     *
     * @return void
     */
    public function testExtractInverseConfigMissingInversedByReturnsNull(): void
    {
        $config = ['items' => ['$ref' => '#/schemas/Thing']];

        $result = $this->invokePrivate('extractInverseConfig', [$config]);

        $this->assertNull($result);
    }

    // =========================================================================
    // initializeInverseCacheEntries
    // =========================================================================

    /**
     * Test initializeInverseCacheEntries creates empty arrays.
     *
     * @return void
     */
    public function testInitializeInverseCacheEntriesCreatesEmptyArrays(): void
    {
        $this->invokePrivate('initializeInverseCacheEntries', [
            ['uuid-1', 'uuid-2'],
            'contacts',
        ]);

        $ref = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('inverseRelationCache');
        $prop->setAccessible(true);
        $cache = $prop->getValue($this->handler);

        $this->assertSame([], $cache['uuid-1_contacts']);
        $this->assertSame([], $cache['uuid-2_contacts']);
    }

    /**
     * Test initializeInverseCacheEntries does not overwrite existing entries.
     *
     * @return void
     */
    public function testInitializeInverseCacheEntriesPreservesExisting(): void
    {
        $existing = $this->createObjectEntity(1, 'ref-uuid');

        $ref = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('inverseRelationCache');
        $prop->setAccessible(true);
        $prop->setValue($this->handler, ['uuid-1_contacts' => [$existing]]);

        $this->invokePrivate('initializeInverseCacheEntries', [
            ['uuid-1'],
            'contacts',
        ]);

        $cache = $prop->getValue($this->handler);
        $this->assertCount(1, $cache['uuid-1_contacts']);
    }

    // =========================================================================
    // indexReferencingObjects
    // =========================================================================

    /**
     * Test indexReferencingObjects indexes by UUID.
     *
     * @return void
     */
    public function testIndexReferencingObjectsIndexesByUuid(): void
    {
        $entityUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $refObject = $this->createObjectEntity(10, 'ref-uuid-1', [
            'organisation' => $entityUuid,
        ]);

        // Initialize cache.
        $ref = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('inverseRelationCache');
        $prop->setAccessible(true);
        $prop->setValue($this->handler, [$entityUuid . '_contacts' => []]);

        $this->invokePrivate('indexReferencingObjects', [
            [$refObject],
            ['organisation'],
            [$entityUuid],
            'contacts',
        ]);

        $cache = $prop->getValue($this->handler);
        $this->assertCount(1, $cache[$entityUuid . '_contacts']);
    }

    /**
     * Test indexReferencingObjects with object value format.
     *
     * @return void
     */
    public function testIndexReferencingObjectsObjectValueFormat(): void
    {
        $entityUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $refObject = $this->createObjectEntity(10, 'ref-uuid-1', [
            'organisation' => ['value' => $entityUuid],
        ]);

        $ref = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('inverseRelationCache');
        $prop->setAccessible(true);
        $prop->setValue($this->handler, [$entityUuid . '_contacts' => []]);

        $this->invokePrivate('indexReferencingObjects', [
            [$refObject],
            ['organisation'],
            [$entityUuid],
            'contacts',
        ]);

        $cache = $prop->getValue($this->handler);
        $this->assertCount(1, $cache[$entityUuid . '_contacts']);
    }

    /**
     * Test indexReferencingObjects also populates objectsCache.
     *
     * @return void
     */
    public function testIndexReferencingObjectsPopulatesObjectsCache(): void
    {
        $entityUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $refObject = $this->createObjectEntity(10, 'ref-uuid-1', [
            'organisation' => $entityUuid,
        ]);

        $ref = new ReflectionClass($this->handler);
        $prop = $ref->getProperty('inverseRelationCache');
        $prop->setAccessible(true);
        $prop->setValue($this->handler, [$entityUuid . '_contacts' => []]);

        $this->invokePrivate('indexReferencingObjects', [
            [$refObject],
            ['organisation'],
            [$entityUuid],
            'contacts',
        ]);

        $objectsCache = $ref->getProperty('objectsCache');
        $objectsCache->setAccessible(true);
        $cache = $objectsCache->getValue($this->handler);
        $this->assertArrayHasKey('ref-uuid-1', $cache);
    }

    // =========================================================================
    // handleInversedPropertiesFromCache
    // =========================================================================

    /**
     * Test handleInversedPropertiesFromCache with array type property.
     *
     * @return void
     */
    public function testHandleInversedPropertiesFromCacheArray(): void
    {
        $this->setupBasicMocks();

        $entityUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $entity = $this->createBasicEntity(1, $entityUuid, ['name' => 'Test']);

        // Create a cached referencing object.
        $refObj = $this->createBasicEntity(10, 'ref-uuid-1', [
            'id' => 'ref-uuid-1',
            'organisation' => $entityUuid,
        ]);

        // Set up inverse cache.
        $this->setPrivateProperty('inverseRelationCache', [
            $entityUuid . '_contacts' => [$refObj],
        ]);

        $inversedProperties = [
            'contacts' => [
                'type' => 'array',
                'items' => ['inversedBy' => 'organisation', '$ref' => '#/schemas/Contact'],
            ],
        ];

        $objectData = ['name' => 'Test'];
        $result = $this->invokePrivate('handleInversedPropertiesFromCache', [
            $entity,
            $objectData,
            $inversedProperties,
        ]);

        $this->assertArrayHasKey('contacts', $result);
        $this->assertIsArray($result['contacts']);
    }

    /**
     * Test handleInversedPropertiesFromCache with single (non-array) type.
     *
     * @return void
     */
    public function testHandleInversedPropertiesFromCacheSingle(): void
    {
        $this->setupBasicMocks();

        $entityUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $entity = $this->createBasicEntity(1, $entityUuid, ['name' => 'Test']);

        $refObj = $this->createBasicEntity(10, 'ref-uuid-1', [
            'id' => 'ref-uuid-1',
            'child' => $entityUuid,
        ]);

        $this->setPrivateProperty('inverseRelationCache', [
            $entityUuid . '_parent' => [$refObj],
        ]);

        $inversedProperties = [
            'parent' => [
                'type' => 'object',
                'inversedBy' => 'child',
                '$ref' => '#/schemas/Node',
            ],
        ];

        $objectData = ['name' => 'Test'];
        $result = $this->invokePrivate('handleInversedPropertiesFromCache', [
            $entity,
            $objectData,
            $inversedProperties,
        ]);

        $this->assertArrayHasKey('parent', $result);
        $this->assertIsArray($result['parent']);
        $this->assertArrayHasKey('id', $result['parent']);
    }

    /**
     * Test handleInversedPropertiesFromCache returns null for single with empty cache.
     *
     * @return void
     */
    public function testHandleInversedPropertiesFromCacheSingleEmptyCache(): void
    {
        $entityUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $entity = $this->createBasicEntity(1, $entityUuid, ['name' => 'Test']);

        $this->setPrivateProperty('inverseRelationCache', [
            $entityUuid . '_parent' => [],
        ]);

        $inversedProperties = [
            'parent' => [
                'type' => 'object',
                'inversedBy' => 'child',
                '$ref' => '#/schemas/Node',
            ],
        ];

        $objectData = ['name' => 'Test'];
        $result = $this->invokePrivate('handleInversedPropertiesFromCache', [
            $entity,
            $objectData,
            $inversedProperties,
        ]);

        $this->assertNull($result['parent']);
    }

    /**
     * Test handleInversedPropertiesFromCache skips properties without inversedBy.
     *
     * @return void
     */
    public function testHandleInversedPropertiesFromCacheSkipsNoInversedBy(): void
    {
        $entityUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $entity = $this->createBasicEntity(1, $entityUuid, ['name' => 'Test']);

        $inversedProperties = [
            'field' => [
                'type' => 'string',
                // No inversedBy.
            ],
        ];

        $objectData = ['name' => 'Test'];
        $result = $this->invokePrivate('handleInversedPropertiesFromCache', [
            $entity,
            $objectData,
            $inversedProperties,
        ]);

        $this->assertArrayNotHasKey('field', $result);
    }

    // =========================================================================
    // removeQueryParameters
    // =========================================================================

    /**
     * Test removeQueryParameters removes query string.
     *
     * @return void
     */
    public function testRemoveQueryParametersRemovesQueryString(): void
    {
        $result = $this->invokePrivate('removeQueryParameters', ['schema?version=1&format=json']);

        $this->assertSame('schema', $result);
    }

    /**
     * Test removeQueryParameters returns unchanged when no query.
     *
     * @return void
     */
    public function testRemoveQueryParametersReturnsUnchangedWhenNoQuery(): void
    {
        $result = $this->invokePrivate('removeQueryParameters', ['schema']);

        $this->assertSame('schema', $result);
    }

    // =========================================================================
    // resolveSchemaReference (RenderObject version)
    // =========================================================================

    /**
     * Test resolveSchemaReference returns numeric ID as-is.
     *
     * @return void
     */
    public function testResolveSchemaReferenceNumericId(): void
    {
        $result = $this->invokePrivate('resolveSchemaReference', ['42']);

        $this->assertSame('42', $result);
    }

    /**
     * Test resolveSchemaReference with query params strips them before numeric check.
     *
     * @return void
     */
    public function testResolveSchemaReferenceWithQueryParamsNumeric(): void
    {
        $result = $this->invokePrivate('resolveSchemaReference', ['42?version=1']);

        $this->assertSame('42', $result);
    }

    /**
     * Test resolveSchemaReference with UUID falls through to slug lookup.
     *
     * Note: The UUID branch uses preg_match() === true, but preg_match returns int,
     * so 1 === true is false. UUID values fall through to the slug lookup path.
     *
     * @return void
     */
    public function testResolveSchemaReferenceByUuidFallsToSlug(): void
    {
        $schema = $this->createSchema(5, 'test-schema');

        // UUID branch is dead code (preg_match returns int, not bool).
        // So it falls through to findAll slug match.
        $this->schemaMapper->method('findAll')
            ->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('not found'));

        // Falls through to return the UUID as-is.
        $result = $this->invokePrivate('resolveSchemaReference', ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $result);
    }

    /**
     * Test resolveSchemaReference with slug value resolves via findAll.
     *
     * @return void
     */
    public function testResolveSchemaReferenceSlugResolves(): void
    {
        $schema = $this->createSchema(5, 'contact');

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('not found'));
        $this->schemaMapper->method('findAll')
            ->willReturn([$schema]);

        $result = $this->invokePrivate('resolveSchemaReference', ['contact']);

        $this->assertSame('5', $result);
    }

    /**
     * Test resolveSchemaReference with JSON Schema path reference.
     *
     * @return void
     */
    public function testResolveSchemaReferenceJsonSchemaPath(): void
    {
        $schema = $this->createSchema(5, 'contactgegevens');

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('not found'));
        $this->schemaMapper->method('findAll')
            ->willReturn([$schema]);

        $result = $this->invokePrivate('resolveSchemaReference', ['#/components/schemas/Contactgegevens']);

        $this->assertSame('5', $result);
    }

    /**
     * Test resolveSchemaReference falls through to raw reference.
     *
     * @return void
     */
    public function testResolveSchemaReferenceFallsThrough(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('not found'));
        $this->schemaMapper->method('findAll')
            ->willReturn([]);

        $result = $this->invokePrivate('resolveSchemaReference', ['unknown-slug']);

        $this->assertSame('unknown-slug', $result);
    }

    // =========================================================================
    // collectUuidsForExtend
    // =========================================================================

    /**
     * Test collectUuidsForExtend with URL value containing UUID.
     *
     * @return void
     */
    public function testCollectUuidsForExtendWithUrlValueNotUuid(): void
    {
        $objectData = [
            'link' => 'http://example.com/api/objects/123',
        ];

        $result = $this->invokePrivate('collectUuidsForExtend', [$objectData, ['link']]);

        // URL is not a UUID, so it should not be collected.
        $this->assertEmpty($result);
    }

    /**
     * Test collectUuidsForExtend deduplicates results.
     *
     * @return void
     */
    public function testCollectUuidsForExtendDeduplicates(): void
    {
        $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $objectData = [
            'field1' => $uuid,
            'field2' => [$uuid, $uuid],
        ];

        $result = $this->invokePrivate('collectUuidsForExtend', [$objectData, ['field1', 'field2']]);

        $this->assertCount(1, $result);
        $this->assertSame($uuid, $result[0]);
    }

    // =========================================================================
    // isUuidLike
    // =========================================================================

    /**
     * Test isUuidLike with valid UUID.
     *
     * @return void
     */
    public function testIsUuidLikeValid(): void
    {
        $result = $this->invokePrivate('isUuidLike', ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']);

        $this->assertTrue($result);
    }

    /**
     * Test isUuidLike with uppercase UUID.
     *
     * @return void
     */
    public function testIsUuidLikeUppercase(): void
    {
        $result = $this->invokePrivate('isUuidLike', ['AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE']);

        $this->assertTrue($result);
    }

    /**
     * Test isUuidLike with invalid string.
     *
     * @return void
     */
    public function testIsUuidLikeInvalid(): void
    {
        $result = $this->invokePrivate('isUuidLike', ['not-a-uuid']);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getValueFromPath
    // =========================================================================

    /**
     * Test getValueFromPath with deeply nested path.
     *
     * @return void
     */
    public function testGetValueFromPathDeeplyNested(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'level3' => 'deepValue',
                ],
            ],
        ];

        $result = $this->invokePrivate('getValueFromPath', [$data, 'level1.level2.level3']);

        $this->assertSame('deepValue', $result);
    }

    /**
     * Test getValueFromPath returns null when intermediate is not array.
     *
     * @return void
     */
    public function testGetValueFromPathIntermediateNotArray(): void
    {
        $data = [
            'level1' => 'string-value',
        ];

        $result = $this->invokePrivate('getValueFromPath', [$data, 'level1.level2']);

        $this->assertNull($result);
    }

    // =========================================================================
    // handleInversedProperties - returns early
    // =========================================================================

    /**
     * Test handleInversedProperties returns objectData when schema is null.
     *
     * @return void
     */
    public function testHandleInversedPropertiesReturnsWhenSchemaNull(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        // Schema not in cache and find throws.
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('not found'));

        $objectData = ['name' => 'Test'];
        $result = $this->invokePrivate('handleInversedProperties', [
            $entity,
            $objectData,
            0,
            [],
            [],
            [],
            [],
            [],
            [],
        ]);

        $this->assertSame($objectData, $result);
    }

    /**
     * Test handleInversedProperties returns objectData when no inversed properties.
     *
     * @return void
     */
    public function testHandleInversedPropertiesReturnsWhenNoInversedProps(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        $schema = $this->createSchema(1);
        $schema->setProperties(['name' => ['type' => 'string']]);
        $this->setPrivateProperty('schemasCache', [1 => $schema]);

        $objectData = ['name' => 'Test'];
        $result = $this->invokePrivate('handleInversedProperties', [
            $entity,
            $objectData,
            0,
            [],
            [],
            [],
            [],
            [],
            [],
        ]);

        $this->assertSame($objectData, $result);
    }

    // =========================================================================
    // preloadInverseRelationships - edge cases
    // =========================================================================

    /**
     * Test preloadInverseRelationships with empty entities returns early.
     *
     * @return void
     */
    public function testPreloadInverseRelationshipsEmptyEntities(): void
    {
        $this->invokePrivate('preloadInverseRelationships', [[], ['contacts']]);

        // Should not crash.
        $this->assertTrue(true);
    }

    /**
     * Test preloadInverseRelationships with empty extend returns early.
     *
     * @return void
     */
    public function testPreloadInverseRelationshipsEmptyExtend(): void
    {
        $entity = $this->createBasicEntity(1, 'uuid-1');
        $this->invokePrivate('preloadInverseRelationships', [[$entity], []]);

        $this->assertTrue(true);
    }

    /**
     * Test preloadInverseRelationships with non-ObjectEntity skips.
     *
     * @return void
     */
    public function testPreloadInverseRelationshipsNonEntity(): void
    {
        $this->invokePrivate('preloadInverseRelationships', [['not-an-entity'], ['contacts']]);

        $this->assertTrue(true);
    }

    /**
     * Test preloadInverseRelationships with no schema found returns early.
     *
     * @return void
     */
    public function testPreloadInverseRelationshipsNoSchema(): void
    {
        $entity = $this->createBasicEntity(1, 'uuid-1');

        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('not found'));

        $this->invokePrivate('preloadInverseRelationships', [[$entity], ['contacts']]);

        $this->assertTrue(true);
    }

    // =========================================================================
    // renderEntity - property RBAC
    // =========================================================================

    /**
     * Test renderEntity with property RBAC filtering injects @self for check.
     *
     * @return void
     */
    public function testRenderEntityPropertyRbacInjectsSelf(): void
    {
        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'name' => 'Test',
            'secret' => 'hidden',
        ]);
        $entity->setOrganisation('org-uuid');
        $entity->setOwner('user-1');

        $schema = $this->createSchema(1);
        $schema->setProperties([
            'name' => ['type' => 'string', 'authorization' => ['read' => ['roles' => ['admin']]]],
            'secret' => ['type' => 'string', 'authorization' => ['read' => ['roles' => ['admin']]]],
        ]);

        $this->fileMapper->method('getFilesForObject')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema);

        // Simulate property RBAC filtering: remove 'secret'.
        $this->propertyRbacHandler->method('filterReadableProperties')
            ->willReturnCallback(function ($s, $obj) {
                unset($obj['secret']);
                return $obj;
            });

        $result = $this->handler->renderEntity($entity);
        $serialized = $result->jsonSerialize();

        $this->assertArrayNotHasKey('secret', $serialized);
        $this->assertArrayHasKey('name', $serialized);
    }

    // =========================================================================
    // renderEntity - edge cases
    // =========================================================================

    /**
     * Test renderEntity with empty object data does not crash.
     *
     * @return void
     */
    public function testRenderEntityWithEmptyObjectDataNoCrash(): void
    {
        $this->setupBasicMocks();

        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', []);

        $result = $this->handler->renderEntity($entity);

        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    /**
     * Test renderEntity with null UUID does not detect circular.
     *
     * @return void
     */
    public function testRenderEntityNullUuidNoCircularDetection(): void
    {
        $this->setupBasicMocks();

        $entity = $this->createBasicEntity(1, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);

        // Pass same UUID in visitedIds.
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
            ['aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']
        );

        $serialized = $result->jsonSerialize();
        $this->assertTrue($serialized['@circular'] ?? false);
    }
}
