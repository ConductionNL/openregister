<?php

/**
 * SaveObject Unit Tests
 *
 * Comprehensive tests for UUID handling and object relation scenarios
 * in the SaveObject service.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectHandlers;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectHandlers\SaveObject;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Opis\JsonSchema\Loaders\ArrayLoader;
use Symfony\Component\Uid\Uuid;

/**
 * Unit tests for SaveObject service
 *
 * Tests cover:
 * - UUID handling scenarios (create, update, generate)
 * - Cascading with inversedBy (relational cascading)
 * - Cascading without inversedBy (ID storage cascading)
 * - Error handling and edge cases
 */
class SaveObjectTest extends TestCase
{
    /** @var SaveObject */
    private SaveObject $saveObject;

    /** @var MockObject|ObjectEntityMapper */
    private $objectEntityMapper;

    /** @var MockObject|FileService */
    private $fileService;

    /** @var MockObject|IUserSession */
    private $userSession;

    /** @var MockObject|AuditTrailMapper */
    private $auditTrailMapper;

    /** @var MockObject|SchemaMapper */
    private $schemaMapper;

    /** @var MockObject|RegisterMapper */
    private $registerMapper;

    /** @var MockObject|IURLGenerator */
    private $urlGenerator;

    /** @var MockObject|ArrayLoader */
    private $arrayLoader;

    /** @var MockObject|Register */
    private $mockRegister;

    /** @var MockObject|Schema */
    private $mockSchema;

    /** @var MockObject|IUser */
    private $mockUser;

    /**
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks for all dependencies
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->arrayLoader = $this->createMock(ArrayLoader::class);

        // Create mock entities
        $this->mockRegister = $this->createMock(Register::class);
        $this->mockSchema = $this->createMock(Schema::class);
        $this->mockUser = $this->createMock(IUser::class);

        // Set up basic mock returns
        $this->mockRegister->method('getId')->willReturn(1);
        $this->mockRegister->method('getSlug')->willReturn('test-register');
        
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getSlug')->willReturn('test-schema');
        $this->mockSchema->method('getSchemaObject')->willReturn((object)[
            'properties' => []
        ]);

        $this->mockUser->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Create SaveObject instance
        $this->saveObject = new SaveObject(
            $this->objectEntityMapper,
            $this->fileService,
            $this->userSession,
            $this->auditTrailMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->urlGenerator,
            $this->arrayLoader
        );
    }

    /**
     * Test UUID handling: Create new object when UUID doesn't exist
     *
     * @return void
     */
    public function testSaveObjectWithNonExistentUuidCreatesNewObject(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = ['name' => 'Test Object'];

        // Mock that UUID doesn't exist in database
        $this->objectEntityMapper
            ->method('find')
            ->with($uuid)
            ->willThrowException(new DoesNotExistException('Object not found'));

        // Mock successful creation
        $newObject = new ObjectEntity();
        $newObject->setId(1);
        $newObject->setUuid($uuid);
        $newObject->setRegister(1);
        $newObject->setSchema(1);
        $newObject->setObject($data);

        $this->objectEntityMapper
            ->method('insert')
            ->willReturn($newObject);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/' . $uuid);

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/' . $uuid);

        // Execute test
        $result = $this->saveObject->saveObject($this->mockRegister, $this->mockSchema, $data, $uuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($uuid, $result->getUuid());
        $this->assertEquals($data, $result->getObject());
    }

    /**
     * Test UUID handling: Update existing object when UUID exists
     *
     * @return void
     */
    public function testSaveObjectWithExistingUuidUpdatesObject(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = ['name' => 'Updated Object'];

        // Mock existing object
        $existingObject = new ObjectEntity();
        $existingObject->setId(1);
        $existingObject->setUuid($uuid);
        $existingObject->setRegister(1);
        $existingObject->setSchema(1);
        $existingObject->setObject(['name' => 'Original Object']);

        $this->objectEntityMapper
            ->method('find')
            ->with($uuid)
            ->willReturn($existingObject);

        // Mock successful update
        $updatedObject = clone $existingObject;
        $updatedObject->setObject($data);

        $this->objectEntityMapper
            ->method('update')
            ->willReturn($updatedObject);

        // Execute test
        $result = $this->saveObject->saveObject($this->mockRegister, $this->mockSchema, $data, $uuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($uuid, $result->getUuid());
        $this->assertEquals($data, $result->getObject());
    }

    /**
     * Test UUID handling: Generate new UUID when none provided
     *
     * @return void
     */
    public function testSaveObjectWithoutUuidGeneratesNewUuid(): void
    {
        $data = ['name' => 'New Object'];

        // Mock successful creation
        $newObject = new ObjectEntity();
        $newObject->setId(1);
        $newObject->setRegister(1);
        $newObject->setSchema(1);
        $newObject->setObject($data);

        $this->objectEntityMapper
            ->method('insert')
            ->willReturn($newObject);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/generated-uuid');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/generated-uuid');

        // Execute test
        $result = $this->saveObject->saveObject($this->mockRegister, $this->mockSchema, $data);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotNull($result->getUuid());
        $this->assertEquals($data, $result->getObject());
    }

    /**
     * Test cascading with inversedBy: Single object relation
     *
     * @return void
     */
    public function testCascadingWithInversedBySingleObject(): void
    {
        $parentUuid = Uuid::v4()->toRfc4122();
        $childUuid = Uuid::v4()->toRfc4122();
        
        $data = [
            'name' => 'Parent Object',
            'child' => [
                'id' => $childUuid,
                'name' => 'Child Object'
            ]
        ];

        // Mock schema with cascading property
        $schemaProperties = [
            'child' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/ChildSchema',
                'inversedBy' => 'parent',
                'objectConfiguration' => [
                    'handling' => 'cascade',
                    'schema' => '2'
                ]
            ]
        ];

        $this->mockSchema
            ->method('getSchemaObject')
            ->willReturn((object)[
                'properties' => $schemaProperties
            ]);

        // Mock parent object
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->with($parentUuid)
            ->willReturn($parentObject);

        // Mock child object creation/update
        $childObject = new ObjectEntity();
        $childObject->setId(2);
        $childObject->setUuid($childUuid);
        $childObject->setRegister(1);
        $childObject->setSchema(2);
        $childObject->setObject([
            'name' => 'Child Object',
            'parent' => $parentUuid
        ]);

        // Mock schema resolution
        $this->schemaMapper
            ->method('findBySlug')
            ->with('ChildSchema')
            ->willReturn($this->mockSchema);

        // Mock successful operations
        $this->objectEntityMapper
            ->method('insert')
            ->willReturn($childObject);

        $this->objectEntityMapper
            ->method('update')
            ->willReturn($parentObject);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test
        $result = $this->saveObject->saveObject($this->mockRegister, $this->mockSchema, $data, $parentUuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
        
        // Child should be empty in parent (cascaded)
        $resultData = $result->getObject();
        $this->assertEmpty($resultData['child']);
    }

    /**
     * Test cascading with inversedBy: Array of objects relation
     *
     * @return void
     */
    public function testCascadingWithInversedByArrayObjects(): void
    {
        $parentUuid = Uuid::v4()->toRfc4122();
        $child1Uuid = Uuid::v4()->toRfc4122();
        $child2Uuid = Uuid::v4()->toRfc4122();
        
        $data = [
            'name' => 'Parent Object',
            'children' => [
                [
                    'id' => $child1Uuid,
                    'name' => 'Child 1'
                ],
                [
                    'id' => $child2Uuid,
                    'name' => 'Child 2'
                ]
            ]
        ];

        // Mock schema with cascading array property
        $schemaProperties = [
            'children' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    '$ref' => '#/components/schemas/ChildSchema',
                    'inversedBy' => 'parent'
                ],
                'objectConfiguration' => [
                    'handling' => 'cascade',
                    'schema' => '2'
                ]
            ]
        ];

        $this->mockSchema
            ->method('getSchemaObject')
            ->willReturn((object)[
                'properties' => $schemaProperties
            ]);

        // Mock parent object
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->with($parentUuid)
            ->willReturn($parentObject);

        // Mock child objects
        $child1Object = new ObjectEntity();
        $child1Object->setId(2);
        $child1Object->setUuid($child1Uuid);

        $child2Object = new ObjectEntity();
        $child2Object->setId(3);
        $child2Object->setUuid($child2Uuid);

        // Mock schema resolution
        $this->schemaMapper
            ->method('findBySlug')
            ->with('ChildSchema')
            ->willReturn($this->mockSchema);

        // Mock successful operations
        $this->objectEntityMapper
            ->method('insert')
            ->willReturnOnConsecutiveCalls($child1Object, $child2Object);

        $this->objectEntityMapper
            ->method('update')
            ->willReturn($parentObject);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test
        $result = $this->saveObject->saveObject($this->mockRegister, $this->mockSchema, $data, $parentUuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
        
        // Children should be empty array in parent (cascaded)
        $resultData = $result->getObject();
        $this->assertEmpty($resultData['children']);
    }

    /**
     * Test cascading without inversedBy: ID storage cascading
     *
     * @return void
     */
    public function testCascadingWithoutInversedByStoresIds(): void
    {
        $parentUuid = Uuid::v4()->toRfc4122();
        $childUuid = Uuid::v4()->toRfc4122();
        
        $data = [
            'name' => 'Parent Object',
            'child' => [
                'name' => 'Child Object'
            ]
        ];

        // Mock schema with cascading property WITHOUT inversedBy
        $schemaProperties = [
            'child' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/ChildSchema',
                'objectConfiguration' => [
                    'handling' => 'cascade',
                    'schema' => '2'
                ]
            ]
        ];

        $this->mockSchema
            ->method('getSchemaObject')
            ->willReturn((object)[
                'properties' => $schemaProperties
            ]);

        // Mock parent object
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->with($parentUuid)
            ->willReturn($parentObject);

        // Mock child object creation (no UUID provided, new object)
        $childObject = new ObjectEntity();
        $childObject->setId(2);
        $childObject->setUuid($childUuid);
        $childObject->setRegister(1);
        $childObject->setSchema(2);
        $childObject->setObject(['name' => 'Child Object']);

        // Mock schema resolution
        $this->schemaMapper
            ->method('findBySlug')
            ->with('ChildSchema')
            ->willReturn($this->mockSchema);

        // Mock successful operations
        $this->objectEntityMapper
            ->method('insert')
            ->willReturn($childObject);

        $this->objectEntityMapper
            ->method('update')
            ->willReturn($parentObject);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test
        $result = $this->saveObject->saveObject($this->mockRegister, $this->mockSchema, $data, $parentUuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
        
        // Child should contain the UUID of the created object
        $resultData = $result->getObject();
        $this->assertEquals($childUuid, $resultData['child']);
    }

    /**
     * Test cascading without inversedBy: Array of objects stores array of UUIDs
     *
     * @return void
     */
    public function testCascadingWithoutInversedByArrayStoresUuids(): void
    {
        $parentUuid = Uuid::v4()->toRfc4122();
        $child1Uuid = Uuid::v4()->toRfc4122();
        $child2Uuid = Uuid::v4()->toRfc4122();
        
        $data = [
            'name' => 'Parent Object',
            'children' => [
                ['name' => 'Child 1'],
                ['name' => 'Child 2']
            ]
        ];

        // Mock schema with cascading array property WITHOUT inversedBy
        $schemaProperties = [
            'children' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    '$ref' => '#/components/schemas/ChildSchema'
                ],
                'objectConfiguration' => [
                    'handling' => 'cascade',
                    'schema' => '2'
                ]
            ]
        ];

        $this->mockSchema
            ->method('getSchemaObject')
            ->willReturn((object)[
                'properties' => $schemaProperties
            ]);

        // Mock parent object
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->with($parentUuid)
            ->willReturn($parentObject);

        // Mock child objects
        $child1Object = new ObjectEntity();
        $child1Object->setId(2);
        $child1Object->setUuid($child1Uuid);

        $child2Object = new ObjectEntity();
        $child2Object->setId(3);
        $child2Object->setUuid($child2Uuid);

        // Mock schema resolution
        $this->schemaMapper
            ->method('findBySlug')
            ->with('ChildSchema')
            ->willReturn($this->mockSchema);

        // Mock successful operations
        $this->objectEntityMapper
            ->method('insert')
            ->willReturnOnConsecutiveCalls($child1Object, $child2Object);

        $this->objectEntityMapper
            ->method('update')
            ->willReturn($parentObject);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test
        $result = $this->saveObject->saveObject($this->mockRegister, $this->mockSchema, $data, $parentUuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
        
        // Children should contain array of UUIDs
        $resultData = $result->getObject();
        $this->assertIsArray($resultData['children']);
        $this->assertContains($child1Uuid, $resultData['children']);
        $this->assertContains($child2Uuid, $resultData['children']);
    }

    /**
     * Test mixed cascading: Some with inversedBy, some without
     *
     * @return void
     */
    public function testMixedCascadingScenarios(): void
    {
        $parentUuid = Uuid::v4()->toRfc4122();
        $relatedUuid = Uuid::v4()->toRfc4122();
        $ownedUuid = Uuid::v4()->toRfc4122();
        
        $data = [
            'name' => 'Parent Object',
            'related' => [
                'id' => $relatedUuid,
                'name' => 'Related Object'
            ],
            'owned' => [
                'name' => 'Owned Object'
            ]
        ];

        // Mock schema with mixed cascading properties
        $schemaProperties = [
            'related' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/RelatedSchema',
                'inversedBy' => 'parent',
                'objectConfiguration' => [
                    'handling' => 'cascade',
                    'schema' => '2'
                ]
            ],
            'owned' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/OwnedSchema',
                'objectConfiguration' => [
                    'handling' => 'cascade',
                    'schema' => '3'
                ]
            ]
        ];

        $this->mockSchema
            ->method('getSchemaObject')
            ->willReturn((object)[
                'properties' => $schemaProperties
            ]);

        // Mock parent object
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->with($parentUuid)
            ->willReturn($parentObject);

        // Mock related object (with inversedBy)
        $relatedObject = new ObjectEntity();
        $relatedObject->setId(2);
        $relatedObject->setUuid($relatedUuid);

        // Mock owned object (without inversedBy)
        $ownedObject = new ObjectEntity();
        $ownedObject->setId(3);
        $ownedObject->setUuid($ownedUuid);

        // Mock schema resolution
        $this->schemaMapper
            ->method('findBySlug')
            ->willReturnMap([
                ['RelatedSchema', $this->mockSchema],
                ['OwnedSchema', $this->mockSchema]
            ]);

        // Mock successful operations
        $this->objectEntityMapper
            ->method('insert')
            ->willReturnOnConsecutiveCalls($relatedObject, $ownedObject);

        $this->objectEntityMapper
            ->method('update')
            ->willReturn($parentObject);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test
        $result = $this->saveObject->saveObject($this->mockRegister, $this->mockSchema, $data, $parentUuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
        
        $resultData = $result->getObject();
        
        // Related should be empty (cascaded with inversedBy)
        $this->assertEmpty($resultData['related']);
        
        // Owned should contain UUID (cascaded without inversedBy)
        $this->assertEquals($ownedUuid, $resultData['owned']);
    }

    /**
     * Test error handling: Invalid schema reference
     *
     * @return void
     */
    public function testCascadingWithInvalidSchemaReference(): void
    {
        $parentUuid = Uuid::v4()->toRfc4122();
        
        $data = [
            'name' => 'Parent Object',
            'invalid' => [
                'name' => 'Invalid Child'
            ]
        ];

        // Mock schema with invalid reference
        $schemaProperties = [
            'invalid' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/NonExistentSchema',
                'objectConfiguration' => [
                    'handling' => 'cascade',
                    'schema' => '999'
                ]
            ]
        ];

        $this->mockSchema
            ->method('getSchemaObject')
            ->willReturn((object)[
                'properties' => $schemaProperties
            ]);

        // Mock parent object
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);

        $this->objectEntityMapper
            ->method('find')
            ->with($parentUuid)
            ->willReturn($parentObject);

        // Mock schema resolution failure
        $this->schemaMapper
            ->method('findBySlug')
            ->with('NonExistentSchema')
            ->willThrowException(new DoesNotExistException('Schema not found'));

        // Execute test and expect exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid schema reference');

        $this->saveObject->saveObject($this->mockRegister, $this->mockSchema, $data, $parentUuid);
    }

    /**
     * Test edge case: Empty cascading objects are skipped
     *
     * @return void
     */
    public function testEmptyCascadingObjectsAreSkipped(): void
    {
        $parentUuid = Uuid::v4()->toRfc4122();
        
        $data = [
            'name' => 'Parent Object',
            'empty_child' => [],
            'null_child' => null,
            'id_only_child' => ['id' => '']
        ];

        // Mock schema with cascading properties
        $schemaProperties = [
            'empty_child' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/ChildSchema',
                'objectConfiguration' => [
                    'handling' => 'cascade',
                    'schema' => '2'
                ]
            ],
            'null_child' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/ChildSchema',
                'objectConfiguration' => [
                    'handling' => 'cascade',
                    'schema' => '2'
                ]
            ],
            'id_only_child' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/ChildSchema',
                'objectConfiguration' => [
                    'handling' => 'cascade',
                    'schema' => '2'
                ]
            ]
        ];

        $this->mockSchema
            ->method('getSchemaObject')
            ->willReturn((object)[
                'properties' => $schemaProperties
            ]);

        // Mock parent object
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->with($parentUuid)
            ->willReturn($parentObject);

        $this->objectEntityMapper
            ->method('update')
            ->willReturn($parentObject);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test
        $result = $this->saveObject->saveObject($this->mockRegister, $this->mockSchema, $data, $parentUuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
        
        $resultData = $result->getObject();
        
        // All empty objects should remain as they were (not cascaded)
        $this->assertEquals([], $resultData['empty_child']);
        $this->assertNull($resultData['null_child']);
        $this->assertEquals(['id' => ''], $resultData['id_only_child']);
    }

    /**
     * Test inversedBy with array property: Adding to existing array
     *
     * @return void
     */
    public function testInversedByWithArrayPropertyAddsToExistingArray(): void
    {
        $parentUuid = Uuid::v4()->toRfc4122();
        $childUuid = Uuid::v4()->toRfc4122();
        $existingParentUuid = Uuid::v4()->toRfc4122();
        
        $data = [
            'name' => 'Parent Object',
            'child' => [
                'id' => $childUuid,
                'name' => 'Child Object',
                'parents' => [$existingParentUuid] // Existing array
            ]
        ];

        // Mock schema with cascading property
        $schemaProperties = [
            'child' => [
                'type' => 'object',
                '$ref' => '#/components/schemas/ChildSchema',
                'inversedBy' => 'parents', // Array property
                'objectConfiguration' => [
                    'handling' => 'cascade',
                    'schema' => '2'
                ]
            ]
        ];

        $this->mockSchema
            ->method('getSchemaObject')
            ->willReturn((object)[
                'properties' => $schemaProperties
            ]);

        // Mock parent object
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->with($parentUuid)
            ->willReturn($parentObject);

        // Mock child object with existing array
        $childObject = new ObjectEntity();
        $childObject->setId(2);
        $childObject->setUuid($childUuid);
        $childObject->setRegister(1);
        $childObject->setSchema(2);
        $childObject->setObject([
            'name' => 'Child Object',
            'parents' => [$existingParentUuid, $parentUuid] // Should add parent UUID to array
        ]);

        // Mock schema resolution
        $this->schemaMapper
            ->method('findBySlug')
            ->with('ChildSchema')
            ->willReturn($this->mockSchema);

        // Mock successful operations
        $this->objectEntityMapper
            ->method('insert')
            ->willReturn($childObject);

        $this->objectEntityMapper
            ->method('update')
            ->willReturn($parentObject);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test
        $result = $this->saveObject->saveObject($this->mockRegister, $this->mockSchema, $data, $parentUuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
        
        // Child should be empty in parent (cascaded)
        $resultData = $result->getObject();
        $this->assertEmpty($resultData['child']);
        
        // The child object should have both parent UUIDs in its parents array
        $childData = $childObject->getObject();
        $this->assertIsArray($childData['parents']);
        $this->assertContains($existingParentUuid, $childData['parents']);
        $this->assertContains($parentUuid, $childData['parents']);
    }

    /**
     * Test that prepareObject method works correctly without persisting
     *
     * @return void
     */
    public function testPrepareObjectWithoutPersistence(): void
    {
        $data = [
            'name' => 'Test Object',
            'description' => 'Test Description'
        ];

        // Mock schema with configuration
        $schemaProperties = [
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string']
        ];

        $this->mockSchema
            ->method('getSchemaObject')
            ->willReturn((object)[
                'properties' => $schemaProperties
            ]);

        $this->mockSchema
            ->method('getConfiguration')
            ->willReturn([
                'objectNameField' => 'name',
                'objectDescriptionField' => 'description'
            ]);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Mock user session
        $this->userSession
            ->method('getUser')
            ->willReturn($this->mockUser);

        $this->mockUser
            ->method('getUID')
            ->willReturn('testuser');

        // Execute test - should not persist to database
        $result = $this->saveObject->prepareObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data
        );

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotEmpty($result->getUuid());
        $this->assertEquals('Test Object', $result->getName());
        $this->assertEquals('Test Description', $result->getDescription());
        $this->assertEquals('testuser', $result->getOwner());
        
        // Verify that the object was not saved to database
        $this->objectEntityMapper->expects($this->never())->method('insert');
        $this->objectEntityMapper->expects($this->never())->method('update');
    }

    /**
     * Test that prepareObject method handles slug generation correctly
     *
     * @return void
     */
    public function testPrepareObjectWithSlugGeneration(): void
    {
        $data = [
            'title' => 'Test Object Title'
        ];

        // Mock schema with slug configuration
        $schemaProperties = [
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string']
        ];

        $this->mockSchema
            ->method('getSchemaObject')
            ->willReturn((object)[
                'properties' => $schemaProperties
            ]);

        $this->mockSchema
            ->method('getConfiguration')
            ->willReturn([
                'objectSlugField' => 'title'
            ]);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Mock user session
        $this->userSession
            ->method('getUser')
            ->willReturn($this->mockUser);

        $this->mockUser
            ->method('getUID')
            ->willReturn('testuser');

        // Execute test
        $result = $this->saveObject->prepareObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data
        );

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotEmpty($result->getSlug());
        $this->assertStringContainsString('test-object-title', $result->getSlug());
        
        // Verify that the object was not saved to database
        $this->objectEntityMapper->expects($this->never())->method('insert');
        $this->objectEntityMapper->expects($this->never())->method('update');
    }
} 