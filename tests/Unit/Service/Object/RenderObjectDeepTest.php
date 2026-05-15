<?php

declare(strict_types=1);

/**
 * RenderObject Deep Coverage Tests
 *
 * Tests targeting uncovered lines in RenderObject.
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
use OCA\OpenRegister\Db\MagicMapper;
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
 * Deep coverage tests for RenderObject
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RenderObjectDeepTest extends TestCase
{
    /** @var RenderObject */
    private RenderObject $handler;

    /** @var FileMapper&MockObject */
    private FileMapper $fileMapper;

    /** @var MagicMapper&MockObject */
    private MagicMapper $objectMapper;

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
        $this->objectMapper = $this->createMock(MagicMapper::class);
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
            $this->objectMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->systemTagManager,
            $this->systemTagMapper,
            $this->cacheHandler,
            $this->objectCacheService,
            $this->propertyRbacHandler,
            $this->logger,
            $this->fileService,
            $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class),
            $this->createMock(\OCA\OpenRegister\Service\Object\LinkedEntityEnricher::class),
            $this->createMock(\OCA\OpenRegister\Service\Calculation\CalculationEvaluator::class),
            $this->createMock(\OCA\OpenRegister\Service\UrnService::class),
            $this->createMock(\OCA\OpenRegister\Service\TranslationStatusService::class)
        );
    }

    /**
     * Helper to create an ObjectEntity with given properties.
     */
    private function createObjectEntity(
        int $id,
        string $uuid,
        array $objectData = [],
        string $register = '1',
        string $schema = '1'
    ): ObjectEntity {
        $entity = new ObjectEntity();
        $ref = new ReflectionClass($entity);
        $idProp = $ref->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entity, $id);
        $entity->setUuid($uuid);
        $entity->setObject($objectData);
        $entity->setRegister($register);
        $entity->setSchema($schema);
        return $entity;
    }

    /**
     * Helper to create a mock Schema using getMockBuilder for __call magic.
     */
    private function createMockSchema(
        int $id = 1,
        string $slug = 'test-schema',
        array $properties = []
    ): Schema {
        $schema = $this->getMockBuilder(Schema::class)
            ->onlyMethods(['getConfiguration', 'getProperties', 'getSchemaObject', 'hasPropertyAuthorization'])
            ->getMock();
        $schema->setId($id);
        $schema->setSlug($slug);
        $schema->method('getConfiguration')->willReturn([]);
        $schema->method('getProperties')->willReturn($properties);
        $schema->method('hasPropertyAuthorization')->willReturn(false);
        return $schema;
    }

    /**
     * Helper to create a mock Register using getMockBuilder for __call magic.
     */
    private function createMockRegister(int $id = 1, string $slug = 'test-register'): Register
    {
        $register = $this->getMockBuilder(Register::class)
            ->onlyMethods([])
            ->getMock();
        $register->setId($id);
        $register->setSlug($slug);
        return $register;
    }

    /**
     * Helper to invoke private methods via reflection.
     */
    private function invokePrivateMethod(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(RenderObject::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->handler, ...$args);
    }

    /**
     * Helper to set private property via reflection.
     */
    private function setPrivateProperty(string $property, $value): void
    {
        $ref = new ReflectionClass(RenderObject::class);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($this->handler, $value);
    }

    /**
     * Helper to get private property via reflection.
     */
    private function getPrivateProperty(string $property)
    {
        $ref = new ReflectionClass(RenderObject::class);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($this->handler);
    }

    // ── resolveSchemaReference tests ────────────────────────────────────

    /**
     * Test resolveSchemaReference returns numeric ID directly.
     */
    public function testResolveSchemaReferenceNumericId(): void
    {
        $result = $this->invokePrivateMethod('resolveSchemaReference', ['42']);
        $this->assertSame('42', $result);
    }

    /**
     * Test resolveSchemaReference with UUID falls through to slug resolution
     * because preg_match === true check fails (returns 1, not true).
     */
    public function testResolveSchemaReferenceWithUuidFallsThrough(): void
    {
        $uuid = 'dec9ac6e-a4fd-40fc-be5f-e7ef6e5defb4';

        // UUID is not numeric and not a path, so it goes to slug-based findAll
        $this->schemaMapper->method('findAll')
            ->willReturn([]);

        $result = $this->invokePrivateMethod('resolveSchemaReference', [$uuid]);
        // Falls through to returning as-is since no schema found
        $this->assertSame($uuid, $result);
    }

    /**
     * Test resolveSchemaReference with path reference finding schema by slug.
     */
    public function testResolveSchemaReferenceWithPathReference(): void
    {
        $ref = '#/components/schemas/Organisatie';
        $schema = $this->createMockSchema(5, 'organisatie');

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema]);

        $result = $this->invokePrivateMethod('resolveSchemaReference', [$ref]);
        $this->assertSame('5', $result);
    }

    /**
     * Test resolveSchemaReference with path reference where first findAll throws
     * but second findAll (slug filter) returns empty, hitting the catch branch.
     */
    public function testResolveSchemaReferenceWithPathReferenceException(): void
    {
        $ref = '#/components/schemas/NonExistent';

        $callCount = 0;
        $this->schemaMapper->method('findAll')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // First call: path-based slug lookup - throws (caught by catch block)
                    throw new Exception('DB error');
                }
                // Second call: slug filter lookup - returns empty
                return [];
            });

        $result = $this->invokePrivateMethod('resolveSchemaReference', [$ref]);
        // No schema found by slug either, returns reference as-is
        $this->assertSame($ref, $result);
    }

    /**
     * Test resolveSchemaReference with slug resolving to single schema.
     */
    public function testResolveSchemaReferenceWithSlug(): void
    {
        $slug = 'organisatie';
        $schema = $this->createMockSchema(7, 'organisatie');

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema]);

        $result = $this->invokePrivateMethod('resolveSchemaReference', [$slug]);
        $this->assertSame('7', $result);
    }

    /**
     * Test resolveSchemaReference with query parameters.
     */
    public function testResolveSchemaReferenceWithQueryParams(): void
    {
        $ref = '42?key=value';
        $result = $this->invokePrivateMethod('resolveSchemaReference', [$ref]);
        $this->assertSame('42', $result);
    }

    /**
     * Test resolveSchemaReference with slug that returns multiple schemas falls through.
     */
    public function testResolveSchemaReferenceSlugMultipleResults(): void
    {
        $slug = 'ambiguous';
        $schema1 = $this->createMockSchema(1, 'ambiguous');
        $schema2 = $this->createMockSchema(2, 'ambiguous');

        $this->schemaMapper->method('findAll')
            ->willReturn([$schema1, $schema2]);

        $result = $this->invokePrivateMethod('resolveSchemaReference', [$slug]);
        // Multiple results, falls through to returning as-is
        $this->assertSame($slug, $result);
    }

    // ── removeQueryParameters tests ─────────────────────────────────────

    /**
     * Test removeQueryParameters with no query params returns string as-is.
     */
    public function testRemoveQueryParametersNoParams(): void
    {
        $result = $this->invokePrivateMethod('removeQueryParameters', ['simple-reference']);
        $this->assertSame('simple-reference', $result);
    }

    /**
     * Test removeQueryParameters with query parameters strips them.
     */
    public function testRemoveQueryParametersWithParams(): void
    {
        $result = $this->invokePrivateMethod('removeQueryParameters', ['schema?key=value&other=1']);
        $this->assertSame('schema', $result);
    }

    // ── initializeInverseCacheEntries tests ──────────────────────────────

    /**
     * Test initializeInverseCacheEntries sets empty arrays for each UUID.
     */
    public function testInitializeInverseCacheEntries(): void
    {
        $uuids = ['uuid-1', 'uuid-2', 'uuid-3'];
        $propName = 'deelnemers';

        $this->invokePrivateMethod('initializeInverseCacheEntries', [$uuids, $propName]);

        $cache = $this->getPrivateProperty('inverseRelationCache');
        $this->assertSame([], $cache['uuid-1_deelnemers']);
        $this->assertSame([], $cache['uuid-2_deelnemers']);
        $this->assertSame([], $cache['uuid-3_deelnemers']);
    }

    /**
     * Test initializeInverseCacheEntries does not overwrite existing entries.
     */
    public function testInitializeInverseCacheEntriesPreservesExisting(): void
    {
        $entity = $this->createObjectEntity(1, 'existing-uuid', ['name' => 'test']);

        $this->setPrivateProperty('inverseRelationCache', [
            'uuid-1_prop' => [$entity],
        ]);

        $this->invokePrivateMethod('initializeInverseCacheEntries', [['uuid-1', 'uuid-2'], 'prop']);

        $cache = $this->getPrivateProperty('inverseRelationCache');
        $this->assertCount(1, $cache['uuid-1_prop']);
        $this->assertSame([], $cache['uuid-2_prop']);
    }

    // ── indexReferencingObjects tests ────────────────────────────────────

    /**
     * Test indexReferencingObjects indexes objects by field reference.
     */
    public function testIndexReferencingObjectsBasic(): void
    {
        $entityUuids = ['parent-uuid-1'];
        $propName = 'children';

        $this->setPrivateProperty('inverseRelationCache', [
            'parent-uuid-1_children' => [],
        ]);

        $refEntity = $this->createObjectEntity(10, 'child-uuid-1', [
            'parentRef' => 'parent-uuid-1',
        ]);

        $this->invokePrivateMethod('indexReferencingObjects', [
            [$refEntity],
            ['parentRef'],
            $entityUuids,
            $propName,
        ]);

        $cache = $this->getPrivateProperty('inverseRelationCache');
        $this->assertCount(1, $cache['parent-uuid-1_children']);
        $this->assertSame('child-uuid-1', $cache['parent-uuid-1_children'][0]->getUuid());
    }

    /**
     * Test indexReferencingObjects with object format {"value": "uuid"}.
     */
    public function testIndexReferencingObjectsWithValueFormat(): void
    {
        $entityUuids = ['parent-uuid-1'];
        $propName = 'linked';

        $this->setPrivateProperty('inverseRelationCache', [
            'parent-uuid-1_linked' => [],
        ]);

        $refEntity = $this->createObjectEntity(11, 'ref-uuid-1', [
            'module' => ['value' => 'parent-uuid-1'],
        ]);

        $this->invokePrivateMethod('indexReferencingObjects', [
            [$refEntity],
            ['module'],
            $entityUuids,
            $propName,
        ]);

        $cache = $this->getPrivateProperty('inverseRelationCache');
        $this->assertCount(1, $cache['parent-uuid-1_linked']);
    }

    /**
     * Test indexReferencingObjects avoids duplicate entries.
     */
    public function testIndexReferencingObjectsNoDuplicates(): void
    {
        $entityUuids = ['parent-uuid-1'];
        $propName = 'items';

        $refEntity = $this->createObjectEntity(12, 'dup-uuid', [
            'fieldA' => 'parent-uuid-1',
            'fieldB' => 'parent-uuid-1',
        ]);

        $this->setPrivateProperty('inverseRelationCache', [
            'parent-uuid-1_items' => [],
        ]);

        $this->invokePrivateMethod('indexReferencingObjects', [
            [$refEntity],
            ['fieldA', 'fieldB'],
            $entityUuids,
            $propName,
        ]);

        $cache = $this->getPrivateProperty('inverseRelationCache');
        $this->assertCount(1, $cache['parent-uuid-1_items']);
    }

    /**
     * Test indexReferencingObjects with array reference values.
     */
    public function testIndexReferencingObjectsWithArrayReference(): void
    {
        $entityUuids = ['parent-uuid-1'];
        $propName = 'refs';

        $this->setPrivateProperty('inverseRelationCache', [
            'parent-uuid-1_refs' => [],
        ]);

        $refEntity = $this->createObjectEntity(13, 'arr-ref-uuid', [
            'parents' => ['parent-uuid-1', 'other-uuid'],
        ]);

        $this->invokePrivateMethod('indexReferencingObjects', [
            [$refEntity],
            ['parents'],
            $entityUuids,
            $propName,
        ]);

        $cache = $this->getPrivateProperty('inverseRelationCache');
        $this->assertCount(1, $cache['parent-uuid-1_refs']);
    }

    /**
     * Test indexReferencingObjects with non-matching UUID skips.
     */
    public function testIndexReferencingObjectsNonMatchingUuid(): void
    {
        $entityUuids = ['parent-uuid-1'];
        $propName = 'items';

        $this->setPrivateProperty('inverseRelationCache', [
            'parent-uuid-1_items' => [],
        ]);

        $refEntity = $this->createObjectEntity(14, 'other-ref', [
            'field' => 'different-uuid',
        ]);

        $this->invokePrivateMethod('indexReferencingObjects', [
            [$refEntity],
            ['field'],
            $entityUuids,
            $propName,
        ]);

        $cache = $this->getPrivateProperty('inverseRelationCache');
        $this->assertCount(0, $cache['parent-uuid-1_items']);
    }

    // ── collectEntityUuids tests ────────────────────────────────────────

    /**
     * Test collectEntityUuids extracts UUIDs from entity array.
     */
    public function testCollectEntityUuids(): void
    {
        $entities = [
            $this->createObjectEntity(1, 'uuid-a'),
            $this->createObjectEntity(2, 'uuid-b'),
            $this->createObjectEntity(3, 'uuid-c'),
        ];

        $result = $this->invokePrivateMethod('collectEntityUuids', [$entities]);
        $this->assertSame(['uuid-a', 'uuid-b', 'uuid-c'], $result);
    }

    /**
     * Test collectEntityUuids with empty array returns empty.
     */
    public function testCollectEntityUuidsEmpty(): void
    {
        $result = $this->invokePrivateMethod('collectEntityUuids', [[]]);
        $this->assertSame([], $result);
    }

    // ── handleInversedPropertiesFromCache tests ─────────────────────────

    /**
     * Test handleInversedPropertiesFromCache with cache entries.
     */
    public function testHandleInversedPropertiesFromCacheWithData(): void
    {
        $entityUuid = 'main-entity-uuid';
        $entity = $this->createObjectEntity(1, $entityUuid, ['name' => 'Main'], '1', '1');

        $childEntity = $this->createObjectEntity(2, 'child-uuid', ['title' => 'Child'], '1', '2');

        $this->setPrivateProperty('inverseRelationCache', [
            $entityUuid . '_children' => [$childEntity],
        ]);

        $inversedProperties = [
            'children' => [
                'type' => 'array',
                'items' => [
                    'inversedBy' => 'parent',
                    '$ref' => '#/components/schemas/child',
                ],
            ],
        ];

        $result = $this->invokePrivateMethod('handleInversedPropertiesFromCache', [
            $entity,
            ['name' => 'Main'],
            $inversedProperties,
        ]);

        $this->assertArrayHasKey('children', $result);
    }

    /**
     * Test handleInversedPropertiesFromCache with empty cache returns empty arrays.
     */
    public function testHandleInversedPropertiesFromCacheEmpty(): void
    {
        $entityUuid = 'entity-uuid-empty';
        $entity = $this->createObjectEntity(1, $entityUuid, []);

        $this->setPrivateProperty('inverseRelationCache', [
            $entityUuid . '_items' => [],
        ]);

        $inversedProperties = [
            'items' => [
                'type' => 'array',
                'items' => [
                    'inversedBy' => 'parent',
                    '$ref' => '#/components/schemas/item',
                ],
            ],
        ];

        $result = $this->invokePrivateMethod('handleInversedPropertiesFromCache', [
            $entity,
            [],
            $inversedProperties,
        ]);

        $this->assertArrayHasKey('items', $result);
        $this->assertSame([], $result['items']);
    }

    /**
     * Test handleInversedPropertiesFromCache with single (non-array) inverse property.
     */
    public function testHandleInversedPropertiesFromCacheSingleProperty(): void
    {
        $entityUuid = 'entity-single-inv';
        $entity = $this->createObjectEntity(1, $entityUuid, []);

        $childEntity = $this->createObjectEntity(2, 'child-single', ['val' => 1], '1', '2');

        $this->setPrivateProperty('inverseRelationCache', [
            $entityUuid . '_singleProp' => [$childEntity],
        ]);

        $inversedProperties = [
            'singleProp' => [
                'type' => 'object',
                'inversedBy' => 'parent',
                '$ref' => '#/components/schemas/child',
            ],
        ];

        $result = $this->invokePrivateMethod('handleInversedPropertiesFromCache', [
            $entity,
            [],
            $inversedProperties,
        ]);

        $this->assertArrayHasKey('singleProp', $result);
    }

    // ── resolveReferencedUuids tests ────────────────────────────────────

    /**
     * Test resolveReferencedUuids with simple string value.
     */
    public function testResolveReferencedUuidsSimpleString(): void
    {
        $refData = ['field' => 'some-uuid'];
        $result = $this->invokePrivateMethod('resolveReferencedUuids', [$refData, 'field']);
        $this->assertSame(['some-uuid'], $result);
    }

    /**
     * Test resolveReferencedUuids with object format {"value": "uuid"}.
     */
    public function testResolveReferencedUuidsObjectFormat(): void
    {
        $refData = ['field' => ['value' => 'obj-uuid']];
        $result = $this->invokePrivateMethod('resolveReferencedUuids', [$refData, 'field']);
        $this->assertContains('obj-uuid', $result);
    }

    /**
     * Test resolveReferencedUuids with array of values.
     */
    public function testResolveReferencedUuidsArrayValues(): void
    {
        $refData = ['field' => ['uuid-1', 'uuid-2']];
        $result = $this->invokePrivateMethod('resolveReferencedUuids', [$refData, 'field']);
        $this->assertContains('uuid-1', $result);
        $this->assertContains('uuid-2', $result);
    }

    /**
     * Test resolveReferencedUuids with missing field returns [null].
     */
    public function testResolveReferencedUuidsMissingField(): void
    {
        $refData = ['other' => 'value'];
        $result = $this->invokePrivateMethod('resolveReferencedUuids', [$refData, 'missing']);
        $this->assertSame([null], $result);
    }

    // ── renderEntities tests ────────────────────────────────────────────

    /**
     * Test renderEntities with empty entity list returns empty.
     */
    public function testRenderEntitiesEmptyList(): void
    {
        $result = $this->handler->renderEntities(entities: []);
        $this->assertSame([], $result);
    }

    /**
     * Test renderEntities converts string _extend to array.
     */
    public function testRenderEntitiesConvertsStringExtend(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['name' => 'Test']);

        $this->propertyRbacHandler->method('filterReadableProperties')
            ->willReturnArgument(1);

        $result = $this->handler->renderEntities(
            entities: [$entity],
            _extend: 'field1,field2'
        );

        $this->assertCount(1, $result);
    }

    /**
     * Test renderEntities converts string _fields to array.
     */
    public function testRenderEntitiesConvertsStringFields(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['name' => 'Test', 'age' => 30]);

        $this->propertyRbacHandler->method('filterReadableProperties')
            ->willReturnArgument(1);

        $result = $this->handler->renderEntities(
            entities: [$entity],
            _extend: [],
            _fields: 'name,age'
        );

        $this->assertCount(1, $result);
    }

    /**
     * Test renderEntities converts string _filter to array.
     */
    public function testRenderEntitiesConvertsStringFilter(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['status' => 'active']);

        $this->propertyRbacHandler->method('filterReadableProperties')
            ->willReturnArgument(1);

        $result = $this->handler->renderEntities(
            entities: [$entity],
            _filter: 'status=active'
        );

        $this->assertCount(1, $result);
    }

    /**
     * Test renderEntities converts string _unset to array.
     */
    public function testRenderEntitiesConvertsStringUnset(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['name' => 'Test', 'secret' => 'hidden']);

        $this->propertyRbacHandler->method('filterReadableProperties')
            ->willReturnArgument(1);

        $result = $this->handler->renderEntities(
            entities: [$entity],
            _unset: 'secret'
        );

        $this->assertCount(1, $result);
    }

    /**
     * Test renderEntities with null _extend parameter.
     */
    public function testRenderEntitiesNullExtend(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['name' => 'Test']);

        $this->propertyRbacHandler->method('filterReadableProperties')
            ->willReturnArgument(1);

        $result = $this->handler->renderEntities(
            entities: [$entity],
            _extend: null
        );

        $this->assertCount(1, $result);
    }

    /**
     * Test renderEntities with only valid ObjectEntity instances processes all.
     */
    public function testRenderEntitiesProcessesMultipleEntities(): void
    {
        $entity1 = $this->createObjectEntity(1, 'uuid-1', ['name' => 'A']);
        $entity2 = $this->createObjectEntity(2, 'uuid-2', ['name' => 'B']);

        $this->propertyRbacHandler->method('filterReadableProperties')
            ->willReturnArgument(1);

        $result = $this->handler->renderEntities(
            entities: [$entity1, $entity2],
            _extend: []
        );

        $this->assertCount(2, $result);
    }

    // ── getSchema / getRegister helper tests ────────────────────────────

    /**
     * Test getSchema returns cached schema on second call.
     */
    public function testGetSchemaCaching(): void
    {
        $schema = $this->createMockSchema(1, 'cached');

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->willReturn($schema);

        $result1 = $this->invokePrivateMethod('getSchema', [1]);
        $result2 = $this->invokePrivateMethod('getSchema', [1]);

        $this->assertSame($result1, $result2);
    }

    /**
     * Test getSchema returns null when schema not found.
     */
    public function testGetSchemaNotFound(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->invokePrivateMethod('getSchema', [999]);
        $this->assertNull($result);
    }

    /**
     * Test getRegister returns cached register.
     */
    public function testGetRegisterCaching(): void
    {
        $register = $this->createMockRegister(1, 'cached-reg');

        $this->registerMapper->expects($this->once())
            ->method('find')
            ->willReturn($register);

        $result1 = $this->invokePrivateMethod('getRegister', [1]);
        $result2 = $this->invokePrivateMethod('getRegister', [1]);

        $this->assertSame($result1, $result2);
    }

    /**
     * Test getRegister returns null when not found.
     */
    public function testGetRegisterNotFound(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->invokePrivateMethod('getRegister', [999]);
        $this->assertNull($result);
    }

    // ── extractInverseConfig tests ──────────────────────────────────────

    /**
     * Test extractInverseConfig with items.$ref and items.inversedBy.
     */
    public function testExtractInverseConfigFromItems(): void
    {
        $propConfig = [
            'items' => [
                '$ref' => '#/components/schemas/child',
                'inversedBy' => 'parentField',
            ],
        ];

        $result = $this->invokePrivateMethod('extractInverseConfig', [$propConfig]);
        $this->assertNotNull($result);
        $this->assertSame('#/components/schemas/child', $result['targetSchemaRef']);
        $this->assertSame(['parentField'], $result['inversedByFields']);
    }

    /**
     * Test extractInverseConfig with items.inversedBy as array.
     */
    public function testExtractInverseConfigArrayInversedBy(): void
    {
        $propConfig = [
            'items' => [
                '$ref' => '#/components/schemas/link',
                'inversedBy' => ['moduleA', 'moduleB'],
            ],
        ];

        $result = $this->invokePrivateMethod('extractInverseConfig', [$propConfig]);
        $this->assertNotNull($result);
        $this->assertSame(['moduleA', 'moduleB'], $result['inversedByFields']);
    }

    /**
     * Test extractInverseConfig with top-level $ref and inversedBy.
     */
    public function testExtractInverseConfigFromTopLevel(): void
    {
        $propConfig = [
            '$ref' => '#/components/schemas/org',
            'inversedBy' => 'topField',
        ];

        $result = $this->invokePrivateMethod('extractInverseConfig', [$propConfig]);
        $this->assertNotNull($result);
        $this->assertSame(['topField'], $result['inversedByFields']);
    }

    /**
     * Test extractInverseConfig returns null when no config found.
     */
    public function testExtractInverseConfigReturnsNull(): void
    {
        $result = $this->invokePrivateMethod('extractInverseConfig', [['type' => 'string']]);
        $this->assertNull($result);
    }

    /**
     * Test extractInverseConfig returns null when $ref missing.
     */
    public function testExtractInverseConfigNoRef(): void
    {
        $result = $this->invokePrivateMethod('extractInverseConfig', [
            ['items' => ['inversedBy' => 'field']],
        ]);
        $this->assertNull($result);
    }

    // ── preloadInverseRelationships tests ────────────────────────────────

    /**
     * Test preloadInverseRelationships with empty entities does nothing.
     */
    public function testPreloadInverseRelationshipsEmptyEntities(): void
    {
        $this->invokePrivateMethod('preloadInverseRelationships', [
            [],
            ['prop' => ['type' => 'array', 'items' => ['inversedBy' => 'x', '$ref' => '#/y']]],
            ['prop'],
        ]);

        $this->assertTrue(true);
    }

    /**
     * Test preloadInverseRelationships with no matching extend returns early.
     */
    public function testPreloadInverseRelationshipsNoMatchingExtend(): void
    {
        $entity = $this->createObjectEntity(1, 'uuid-1', ['name' => 'test']);

        $this->invokePrivateMethod('preloadInverseRelationships', [
            [$entity],
            ['prop' => ['type' => 'array', 'items' => ['inversedBy' => 'x', '$ref' => '#/y']]],
            ['nonExistentProp'],
        ]);

        $cache = $this->getPrivateProperty('inverseRelationCache');
        $this->assertEmpty($cache);
    }

    // ── getInversedProperties tests ─────────────────────────────────────

    /**
     * Test getInversedProperties returns properties with inversedBy.
     */
    public function testGetInversedProperties(): void
    {
        $schema = $this->createMockSchema(1, 'test', [
            'children' => [
                'type' => 'array',
                'items' => ['inversedBy' => 'parent', '$ref' => '#/x'],
            ],
            'name' => [
                'type' => 'string',
            ],
            'singleInverse' => [
                'type' => 'object',
                'inversedBy' => 'ref',
                '$ref' => '#/y',
            ],
        ]);

        $result = $this->invokePrivateMethod('getInversedProperties', [$schema]);
        $this->assertArrayHasKey('children', $result);
        $this->assertArrayHasKey('singleInverse', $result);
        $this->assertArrayNotHasKey('name', $result);
    }

    /**
     * Test getInversedProperties with no inversed properties.
     */
    public function testGetInversedPropertiesEmpty(): void
    {
        $schema = $this->createMockSchema(1, 'test', [
            'name' => ['type' => 'string'],
        ]);

        $result = $this->invokePrivateMethod('getInversedProperties', [$schema]);
        $this->assertEmpty($result);
    }
}
