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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\TmloService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Loader\ArrayLoader;

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

    /** @var MockObject|MagicMapper */
    private $objectEntityMapper;

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

    /** @var MockObject|OrganisationService */
    private $organisationService;

    /** @var MockObject|CacheHandler */
    private $cacheHandler;

    /** @var MockObject|SettingsService */
    private $settingsService;

    /** @var MockObject|PropertyRbacHandler */
    private $propertyRbacHandler;

    /** @var MockObject|LoggerInterface */
    private $logger;

    /** @var MockObject|MagicMapper */
    private $unifiedObjectMapper;

    /** @var MockObject|MetadataHydrationHandler */
    private $metaHydrationHandler;

    /** @var MockObject|FilePropertyHandler */
    private $filePropertyHandler;

    /** @var Register */
    private Register $mockRegister;

    /** @var Schema|MockObject */
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

        // Create mocks for all dependencies.
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->unifiedObjectMapper = $this->createMock(MagicMapper::class);
        $this->metaHydrationHandler = $this->createMock(MetadataHydrationHandler::class);
        $this->filePropertyHandler = $this->createMock(FilePropertyHandler::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->cacheHandler = $this->createMock(CacheHandler::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->propertyRbacHandler = $this->createMock(PropertyRbacHandler::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create real Register (getId is a magic method via __call).
        $this->mockRegister = new Register();
        $this->mockRegister->setId(1);

        // Create partial mock for Schema: magic methods via addMethods, real methods via onlyMethods.
        $this->mockSchema = $this->getMockBuilder(Schema::class)
            ->addMethods(['getId'])
            ->onlyMethods(['getSchemaObject', 'getProperties', 'getConfiguration', 'hasPropertyAuthorization'])
            ->getMock();
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('hasPropertyAuthorization')->willReturn(false);

        $this->mockUser = $this->createMock(IUser::class);

        // Set up basic mock returns.
        $this->mockUser->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // MagicMapper update returns its first argument (pass-through).
        $this->unifiedObjectMapper->method('update')
            ->willReturnCallback(function ($entity) {
                return $entity;
            });

        // MagicMapper update returns its first argument (pass-through).
        $this->objectEntityMapper->method('update')
            ->willReturnCallback(function ($entity) {
                return $entity;
            });

        // RegisterMapper find returns the mock register (for cascading tests).
        $this->registerMapper->method('find')->willReturn($this->mockRegister);
        $this->registerMapper->method('findAll')->willReturn([$this->mockRegister]);

        // Create TranslationHandler mock that passes through data.
        $translationHandler = $this->createMock(TranslationHandler::class);
        $translationHandler->method('normalizeTranslationsForSave')
            ->willReturnCallback(function (array $objectData) {
                return $objectData;
            });

        // Create SaveObject instance.
        $this->saveObject = new SaveObject(
            objectEntityMapper: $this->objectEntityMapper,
            unifiedObjectMapper: $this->unifiedObjectMapper,
            metaHydrationHandler: $this->metaHydrationHandler,
            filePropertyHandler: $this->filePropertyHandler,
            linkedEntityHandler: $this->createMock(LinkedEntityPropertyHandler::class),
            userSession: $this->userSession,
            auditTrailMapper: $this->auditTrailMapper,
            schemaMapper: $this->schemaMapper,
            registerMapper: $this->registerMapper,
            urlGenerator: $this->urlGenerator,
            organisationService: $this->organisationService,
            cacheHandler: $this->cacheHandler,
            settingsService: $this->settingsService,
            propertyRbacHandler: $this->propertyRbacHandler,
            computedFieldHandler: $this->createMock(ComputedFieldHandler::class),
            translationHandler: $translationHandler,
            logger: $this->logger,
            tmloService: $this->createMock(TmloService::class),
            arrayLoader: new ArrayLoader(),
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

        // Configure schema mock for saveObject flow.
        $this->mockSchema->method('getSchemaObject')->willReturn((object)['properties' => new \stdClass()]);
        $this->mockSchema->method('getProperties')->willReturn([]);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        // Mock that UUID doesn't exist in database.
        $this->objectEntityMapper
            ->method('find')
            ->willThrowException(new DoesNotExistException('Object not found'));

        // Mock successful creation.
        $newObject = new ObjectEntity();
        $newObject->setId(1);
        $newObject->setUuid($uuid);
        $newObject->setRegister(1);
        $newObject->setSchema(1);
        $newObject->setObject($data);

        $this->unifiedObjectMapper
            ->method('insert')
            ->willReturn($newObject);

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/' . $uuid);

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/' . $uuid);

        // Execute test.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data,
            uuid: $uuid
        );

        // Assertions.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($uuid, $result->getUuid());
        $resultObject = $result->getObject();
        unset($resultObject['id']);
        $this->assertEquals($data, $resultObject);
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

        // Configure schema mock for saveObject flow.
        $this->mockSchema->method('getSchemaObject')->willReturn((object)['properties' => new \stdClass()]);
        $this->mockSchema->method('getProperties')->willReturn([]);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        // Mock existing object.
        $existingObject = new ObjectEntity();
        $existingObject->setId(1);
        $existingObject->setUuid($uuid);
        $existingObject->setRegister(1);
        $existingObject->setSchema(1);
        $existingObject->setObject(['name' => 'Original Object']);

        $this->objectEntityMapper
            ->method('find')
            ->willReturn($existingObject);

        // Mock successful update.
        $updatedObject = clone $existingObject;
        $updatedObject->setObject($data);

        // objectEntityMapper update already mocked in setUp (pass-through).

        // Execute test.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data,
            uuid: $uuid
        );

        // Assertions.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($uuid, $result->getUuid());
        $resultObject = $result->getObject();
        unset($resultObject['id']);
        $this->assertEquals($data, $resultObject);
    }

    /**
     * Test UUID handling: Generate new UUID when none provided
     *
     * @return void
     */
    public function testSaveObjectWithoutUuidGeneratesNewUuid(): void
    {
        $data = ['name' => 'New Object'];

        // Configure schema mock for saveObject flow.
        $this->mockSchema->method('getSchemaObject')->willReturn((object)['properties' => new \stdClass()]);
        $this->mockSchema->method('getProperties')->willReturn([]);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        // Mock insert to return entity as-is (UUID is set before insert).
        $this->unifiedObjectMapper
            ->method('insert')
            ->willReturnCallback(function ($entity) {
                $entity->setId(1);
                return $entity;
            });

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/generated-uuid');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/generated-uuid');

        // Execute test.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data
        );

        // Assertions.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertNotNull($result->getUuid(), 'UUID should be auto-generated');
        $resultObject = $result->getObject();
        unset($resultObject['id']);
        $this->assertEquals($data, $resultObject);
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

        // Configure schema mock with cascading property.
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

        $schemaObj = new \stdClass();
        $schemaObj->properties = new \stdClass();
        $childProp = new \stdClass();
        foreach ($schemaProperties['child'] as $k => $v) {
            $childProp->{$k} = $v;
        }
        $schemaObj->properties->child = $childProp;

        $this->mockSchema->method('getSchemaObject')->willReturn($schemaObj);
        $this->mockSchema->method('getProperties')->willReturn($schemaProperties);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        // Mock parent object.
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->willReturn($parentObject);

        // Mock child object creation/update.
        $childObject = new ObjectEntity();
        $childObject->setId(2);
        $childObject->setUuid($childUuid);
        $childObject->setRegister(1);
        $childObject->setSchema(2);
        $childObject->setObject([
            'name' => 'Child Object',
            'parent' => $parentUuid
        ]);

        // Mock schema resolution for child schema.
        $this->schemaMapper
            ->method('find')
            ->willReturn($this->mockSchema);
        $this->schemaMapper
            ->method('findAll')
            ->willReturn([$this->mockSchema]);

        // Mock successful operations.
        $this->unifiedObjectMapper
            ->method('insert')
            ->willReturn($childObject);

        // objectEntityMapper update already mocked in setUp (pass-through).

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data,
            uuid: $parentUuid
        );

        // Assertions.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
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

        // Configure schema mock with cascading array property.
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

        $schemaObj = new \stdClass();
        $schemaObj->properties = new \stdClass();
        $childrenProp = new \stdClass();
        foreach ($schemaProperties['children'] as $k => $v) {
            $childrenProp->{$k} = $v;
        }
        $schemaObj->properties->children = $childrenProp;

        $this->mockSchema->method('getSchemaObject')->willReturn($schemaObj);
        $this->mockSchema->method('getProperties')->willReturn($schemaProperties);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        // Mock parent object.
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->willReturn($parentObject);

        // Mock child objects.
        $child1Object = new ObjectEntity();
        $child1Object->setId(2);
        $child1Object->setUuid($child1Uuid);

        $child2Object = new ObjectEntity();
        $child2Object->setId(3);
        $child2Object->setUuid($child2Uuid);

        // Mock schema resolution for child schema.
        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->schemaMapper->method('findAll')->willReturn([$this->mockSchema]);

        // Mock successful operations.
        $this->unifiedObjectMapper
            ->method('insert')
            ->willReturnOnConsecutiveCalls($child1Object, $child2Object);

        // objectEntityMapper update already mocked in setUp (pass-through).

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data,
            uuid: $parentUuid
        );

        // Assertions.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
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

        // Configure schema mock with cascading property WITHOUT inversedBy.
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

        $schemaObj = new \stdClass();
        $schemaObj->properties = new \stdClass();
        $childProp = new \stdClass();
        foreach ($schemaProperties['child'] as $k => $v) {
            $childProp->{$k} = $v;
        }
        $schemaObj->properties->child = $childProp;

        $this->mockSchema->method('getSchemaObject')->willReturn($schemaObj);
        $this->mockSchema->method('getProperties')->willReturn($schemaProperties);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        // Mock parent object.
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->willReturn($parentObject);

        // Mock child object creation (no UUID provided, new object).
        $childObject = new ObjectEntity();
        $childObject->setId(2);
        $childObject->setUuid($childUuid);
        $childObject->setRegister(1);
        $childObject->setSchema(2);
        $childObject->setObject(['name' => 'Child Object']);

        // Mock schema resolution for child schema.
        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->schemaMapper->method('findAll')->willReturn([$this->mockSchema]);

        // Mock successful operations.
        $this->unifiedObjectMapper
            ->method('insert')
            ->willReturn($childObject);

        // objectEntityMapper update already mocked in setUp (pass-through).

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data,
            uuid: $parentUuid
        );

        // Assertions.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
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

        // Configure schema mock with cascading array property WITHOUT inversedBy.
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

        $schemaObj = new \stdClass();
        $schemaObj->properties = new \stdClass();
        $childrenProp = new \stdClass();
        foreach ($schemaProperties['children'] as $k => $v) {
            $childrenProp->{$k} = $v;
        }
        $schemaObj->properties->children = $childrenProp;

        $this->mockSchema->method('getSchemaObject')->willReturn($schemaObj);
        $this->mockSchema->method('getProperties')->willReturn($schemaProperties);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        // Mock parent object.
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->willReturn($parentObject);

        // Mock child objects.
        $child1Object = new ObjectEntity();
        $child1Object->setId(2);
        $child1Object->setUuid($child1Uuid);

        $child2Object = new ObjectEntity();
        $child2Object->setId(3);
        $child2Object->setUuid($child2Uuid);

        // Mock schema resolution for child schema.
        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->schemaMapper->method('findAll')->willReturn([$this->mockSchema]);

        // Mock successful operations.
        $this->unifiedObjectMapper
            ->method('insert')
            ->willReturnOnConsecutiveCalls($child1Object, $child2Object);

        // objectEntityMapper update already mocked in setUp (pass-through).

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data,
            uuid: $parentUuid
        );

        // Assertions.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
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

        // Configure schema mock with mixed cascading properties.
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

        $schemaObj = new \stdClass();
        $schemaObj->properties = new \stdClass();
        foreach ($schemaProperties as $propName => $propDef) {
            $prop = new \stdClass();
            foreach ($propDef as $k => $v) {
                $prop->{$k} = $v;
            }
            $schemaObj->properties->{$propName} = $prop;
        }

        $this->mockSchema->method('getSchemaObject')->willReturn($schemaObj);
        $this->mockSchema->method('getProperties')->willReturn($schemaProperties);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        // Mock parent object.
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->willReturn($parentObject);

        // Mock related object (with inversedBy).
        $relatedObject = new ObjectEntity();
        $relatedObject->setId(2);
        $relatedObject->setUuid($relatedUuid);

        // Mock owned object (without inversedBy).
        $ownedObject = new ObjectEntity();
        $ownedObject->setId(3);
        $ownedObject->setUuid($ownedUuid);

        // Mock schema resolution for child schemas.
        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->schemaMapper->method('findAll')->willReturn([$this->mockSchema]);

        // Mock successful operations.
        $this->unifiedObjectMapper
            ->method('insert')
            ->willReturnOnConsecutiveCalls($relatedObject, $ownedObject);

        // objectEntityMapper update already mocked in setUp (pass-through).

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data,
            uuid: $parentUuid
        );

        // Assertions.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
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

        // Configure schema mock with invalid reference.
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

        $schemaObj = new \stdClass();
        $schemaObj->properties = new \stdClass();
        $invalidProp = new \stdClass();
        foreach ($schemaProperties['invalid'] as $k => $v) {
            $invalidProp->{$k} = $v;
        }
        $schemaObj->properties->invalid = $invalidProp;

        $this->mockSchema->method('getSchemaObject')->willReturn($schemaObj);
        $this->mockSchema->method('getProperties')->willReturn($schemaProperties);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        // Mock parent object.
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->willReturn($parentObject);

        // Mock schema resolution failure - schema 999 not found.
        $this->schemaMapper
            ->method('find')
            ->willThrowException(new DoesNotExistException('Schema not found'));
        $this->schemaMapper
            ->method('findAll')
            ->willReturn([]);

        // Execute test - cascading silently skips invalid schema references.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data,
            uuid: $parentUuid
        );

        // Should still return an ObjectEntity, with the invalid ref stored as-is.
        $this->assertInstanceOf(ObjectEntity::class, $result);
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

        // Configure schema mock with cascading properties.
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

        $schemaObj = new \stdClass();
        $schemaObj->properties = new \stdClass();
        foreach ($schemaProperties as $propName => $propDef) {
            $prop = new \stdClass();
            foreach ($propDef as $k => $v) {
                $prop->{$k} = $v;
            }
            $schemaObj->properties->{$propName} = $prop;
        }

        $this->mockSchema->method('getSchemaObject')->willReturn($schemaObj);
        $this->mockSchema->method('getProperties')->willReturn($schemaProperties);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        // Mock parent object.
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->willReturn($parentObject);

        // objectEntityMapper update already mocked in setUp (pass-through).

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data,
            uuid: $parentUuid
        );

        // Assertions.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
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

        // Configure schema mock with cascading property.
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

        $schemaObj = new \stdClass();
        $schemaObj->properties = new \stdClass();
        $childProp = new \stdClass();
        foreach ($schemaProperties['child'] as $k => $v) {
            $childProp->{$k} = $v;
        }
        $schemaObj->properties->child = $childProp;

        $this->mockSchema->method('getSchemaObject')->willReturn($schemaObj);
        $this->mockSchema->method('getProperties')->willReturn($schemaProperties);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        // Mock parent object.
        $parentObject = new ObjectEntity();
        $parentObject->setId(1);
        $parentObject->setUuid($parentUuid);
        $parentObject->setRegister(1);
        $parentObject->setSchema(1);

        $this->objectEntityMapper
            ->method('find')
            ->willReturn($parentObject);

        // Mock child object with existing array.
        $childObject = new ObjectEntity();
        $childObject->setId(2);
        $childObject->setUuid($childUuid);
        $childObject->setRegister(1);
        $childObject->setSchema(2);
        $childObject->setObject([
            'name' => 'Child Object',
            'parents' => [$existingParentUuid, $parentUuid]
        ]);

        // Mock schema resolution for child schema.
        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->schemaMapper->method('findAll')->willReturn([$this->mockSchema]);

        // Mock successful operations.
        $this->unifiedObjectMapper
            ->method('insert')
            ->willReturn($childObject);

        // objectEntityMapper update already mocked in setUp (pass-through).

        $this->urlGenerator
            ->method('getAbsoluteURL')
            ->willReturn('http://test.com/object/test');

        $this->urlGenerator
            ->method('linkToRoute')
            ->willReturn('/object/test');

        // Execute test.
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $data,
            uuid: $parentUuid
        );

        // Assertions.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($parentUuid, $result->getUuid());
    }

    /**
     * Test applyPropertyDefaults applies default values from schema
     *
     * @return void
     */
    public function testApplyPropertyDefaultsAppliesDefaults(): void
    {
        // Configure schema mock with default values.
        $schemaObj = new \stdClass();
        $schemaObj->properties = new \stdClass();
        $titleProp = new \stdClass();
        $titleProp->type = 'string';
        $statusProp = new \stdClass();
        $statusProp->type = 'string';
        $statusProp->default = 'draft';
        $schemaObj->properties->title = $titleProp;
        $schemaObj->properties->status = $statusProp;

        $this->mockSchema->method('getSchemaObject')->willReturn($schemaObj);
        $this->mockSchema->method('getProperties')->willReturn([
            'title' => ['type' => 'string'],
            'status' => ['type' => 'string', 'default' => 'draft']
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        $data = [
            'title' => 'Test Object Title'
        ];

        $result = $this->saveObject->applyPropertyDefaults(
            schema: $this->mockSchema,
            data: $data
        );

        $this->assertIsArray($result, 'applyPropertyDefaults should return an array.');
        $this->assertEquals('Test Object Title', $result['title'], 'Existing values should be preserved.');
    }

    /**
     * Test scanForRelations detects no relations in simple data
     *
     * @return void
     */
    public function testScanForRelationsWithSimpleData(): void
    {
        // Configure schema mock.
        $this->mockSchema->method('getSchemaObject')->willReturn((object)['properties' => new \stdClass()]);
        $this->mockSchema->method('getProperties')->willReturn([
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string']
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn(null);

        $data = [
            'name' => 'Test Object',
            'description' => 'Test Description'
        ];

        $relations = $this->saveObject->scanForRelations(
            data: $data,
            prefix: '',
            schema: $this->mockSchema
        );

        $this->assertIsArray($relations, 'scanForRelations should return an array.');
    }

} 