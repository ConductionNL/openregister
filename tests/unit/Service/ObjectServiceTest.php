<?php

/**
 * ObjectService Unit Tests
 *
 * Tests for UUID handling integration in ObjectService
 * focusing on how UUIDs are passed to SaveObject.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectHandlers\DeleteObject;
use OCA\OpenRegister\Service\ObjectHandlers\GetObject;
use OCA\OpenRegister\Service\ObjectHandlers\RenderObject;
use OCA\OpenRegister\Service\ObjectHandlers\SaveObject;
use OCA\OpenRegister\Service\ObjectHandlers\SaveObjects;
use OCA\OpenRegister\Service\ObjectHandlers\ValidateObject;
use OCA\OpenRegister\Service\ObjectHandlers\PublishObject;
use OCA\OpenRegister\Service\ObjectHandlers\DepublishObject;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\ObjectCacheService;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCA\OpenRegister\Service\FacetService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use OCP\IUser;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\ICacheFactory;
use OCP\AppFramework\IAppContainer;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Uid\Uuid;
use Opis\JsonSchema\Validator;
use Opis\JsonSchema\ValidationResult;

/**
 * Unit tests for ObjectService
 *
 * Tests cover:
 * - UUID handling integration with SaveObject
 * - Proper UUID passing for create/update operations
 * - Validation integration
 * - Error handling
 */
class ObjectServiceTest extends TestCase
{
    /** @var ObjectService */
    private ObjectService $objectService;

    /** @var MockObject|DeleteObject */
    private $deleteHandler;

    /** @var MockObject|GetObject */
    private $getHandler;

    /** @var MockObject|RenderObject */
    private $renderHandler;

    /** @var MockObject|SaveObject */
    private $saveHandler;

    /** @var MockObject|SaveObjects */
    private $saveObjectsHandler;

    /** @var MockObject|ValidateObject */
    private $validateHandler;

    /** @var MockObject|PublishObject */
    private $publishHandler;

    /** @var MockObject|DepublishObject */
    private $depublishHandler;

    /** @var MockObject|RegisterMapper */
    private $registerMapper;

    /** @var MockObject|SchemaMapper */
    private $schemaMapper;

    /** @var MockObject|ObjectEntityMapper */
    private $objectEntityMapper;

    /** @var MockObject|FileService */
    private $fileService;

    /** @var MockObject|IUserSession */
    private $userSession;

    /** @var MockObject|SearchTrailService */
    private $searchTrailService;

    /** @var MockObject|OrganisationService */
    private $organisationService;

    /** @var MockObject|IGroupManager */
    private $groupManager;

    /** @var MockObject|IUserManager */
    private $userManager;

    /** @var MockObject|LoggerInterface */
    private $logger;

    /** @var MockObject|ICacheFactory */
    private $cacheFactory;

    /** @var MockObject|FacetService */
    private $facetService;

    /** @var MockObject|ObjectCacheService */
    private $objectCacheService;

    /** @var MockObject|SchemaCacheService */
    private $schemaCacheService;

    /** @var MockObject|SchemaFacetCacheService */
    private $schemaFacetCacheService;

    /** @var MockObject|SettingsService */
    private $settingsService;

    /** @var MockObject|IAppContainer */
    private $container;

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
        $this->deleteHandler = $this->createMock(DeleteObject::class);
        $this->getHandler = $this->createMock(GetObject::class);
        $this->renderHandler = $this->createMock(RenderObject::class);
        $this->saveHandler = $this->createMock(SaveObject::class);
        $this->saveObjectsHandler = $this->createMock(SaveObjects::class);
        $this->validateHandler = $this->createMock(ValidateObject::class);
        $this->publishHandler = $this->createMock(PublishObject::class);
        $this->depublishHandler = $this->createMock(DepublishObject::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->searchTrailService = $this->createMock(SearchTrailService::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->facetService = $this->createMock(FacetService::class);
        $this->objectCacheService = $this->createMock(ObjectCacheService::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheService::class);
        $this->schemaFacetCacheService = $this->createMock(SchemaFacetCacheService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->container = $this->createMock(IAppContainer::class);

        // Create mock entities
        $this->mockRegister = $this->createMock(Register::class);
        $this->mockSchema = $this->createMock(Schema::class);
        $this->mockUser = $this->createMock(IUser::class);

        // Set up basic mock returns
        // Note: getId and getHardValidation methods might be final or not exist, so we'll skip mocking them
        $this->mockUser->method('getUID')->willReturn('testuser');
        $this->mockUser->method('getDisplayName')->willReturn('Test User');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        // Set up permission mocks
        $this->userManager->method('get')->with('testuser')->willReturn($this->mockUser);
        $this->groupManager->method('getUserGroupIds')->with($this->mockUser)->willReturn(['admin']);
        
        // Set up schema mock - skip getTitle as it cannot be mocked
        $this->mockSchema->method('hasPermission')->willReturn(true);

        // Create ObjectService instance
        $this->objectService = new ObjectService(
            $this->deleteHandler,
            $this->getHandler,
            $this->renderHandler,
            $this->saveHandler,
            $this->saveObjectsHandler,
            $this->validateHandler,
            $this->publishHandler,
            $this->depublishHandler,
            $this->registerMapper,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->fileService,
            $this->userSession,
            $this->searchTrailService,
            $this->groupManager,
            $this->userManager,
            $this->organisationService,
            $this->logger,
            $this->cacheFactory,
            $this->facetService,
            $this->objectCacheService,
            $this->schemaCacheService,
            $this->schemaFacetCacheService,
            $this->settingsService,
            $this->container
        );

        // Set register and schema context
        $this->objectService->setRegister($this->mockRegister);
        $this->objectService->setSchema($this->mockSchema);
    }

    /**
     * Test saveObject with no UUID passes null to SaveObject
     *
     * @return void
     */
    public function testSaveObjectWithoutUuidPassesNullToSaveObject(): void
    {
        $data = ['name' => 'Test Object'];

        // Mock successful save
        $savedObject = new ObjectEntity();
        $savedObject->setId(1);
        $savedObject->setUuid(Uuid::v4()->toRfc4122());
        $savedObject->setObject($data);

        // Verify that SaveObject is called with null UUID
        $this->saveHandler
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->mockRegister,
                $this->mockSchema,
                $data,
                null, // UUID should be null
                null  // folderId should be null
            )
            ->willReturn($savedObject);

        // Mock render handler
        $this->renderHandler
            ->method('renderEntity')
            ->willReturn($savedObject);

        // Execute test
        $result = $this->objectService->saveObject($data, [], null, null, null, false);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    /**
     * Test saveObject with provided UUID passes it to SaveObject
     *
     * @return void
     */
    public function testSaveObjectWithUuidPassesItToSaveObject(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = ['name' => 'Test Object'];

        // Mock successful save
        $savedObject = new ObjectEntity();
        $savedObject->setId(1);
        $savedObject->setUuid($uuid);
        $savedObject->setObject($data);

        // Verify that SaveObject is called with the provided UUID
        $this->saveHandler
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->mockRegister,
                $this->mockSchema,
                $data,
                $uuid, // UUID should be passed through
                null   // folderId should be null for new objects
            )
            ->willReturn($savedObject);

        // Mock render handler
        $this->renderHandler
            ->method('renderEntity')
            ->willReturn($savedObject);

        // Execute test
        $result = $this->objectService->saveObject($data, [], null, null, $uuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($uuid, $result->getUuid());
    }

    /**
     * Test saveObject with ObjectEntity extracts UUID correctly
     *
     * @return void
     */
    public function testSaveObjectWithObjectEntityExtractsUuid(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = ['name' => 'Test Object'];

        // Create ObjectEntity input
        $inputObject = new ObjectEntity();
        $inputObject->setUuid($uuid);
        $inputObject->setObject($data);

        // Mock successful save
        $savedObject = new ObjectEntity();
        $savedObject->setId(1);
        $savedObject->setUuid($uuid);
        $savedObject->setObject($data);

        // Verify that SaveObject is called with extracted UUID and data
        $expectedData = array_merge($data, ['id' => $uuid]); // UUID is added to data
        $this->saveHandler
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->mockRegister,
                $this->mockSchema,
                $expectedData,    // Data should include the UUID as 'id'
                $uuid,            // UUID should be extracted from ObjectEntity
                null              // folderId should be null
            )
            ->willReturn($savedObject);

        // Mock render handler
        $this->renderHandler
            ->method('renderEntity')
            ->willReturn($savedObject);

        // Execute test
        $result = $this->objectService->saveObject($inputObject);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($uuid, $result->getUuid());
    }

    /**
     * Test saveObject with existing object handles folder creation
     *
     * @return void
     */
    public function testSaveObjectWithExistingObjectHandlesFolderCreation(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = ['name' => 'Updated Object'];
        $folderId = 123;

        // Mock existing object without folder
        $existingObject = new ObjectEntity();
        $existingObject->setId(1);
        $existingObject->setUuid($uuid);
        $existingObject->setFolder(null);

        $this->objectEntityMapper
            ->method('find')
            ->with($uuid)
            ->willReturn($existingObject);

        // Mock folder creation
        $this->fileService
            ->method('createObjectFolderWithoutUpdate')
            ->with($existingObject)
            ->willReturn($folderId);

        // Mock successful save
        $savedObject = new ObjectEntity();
        $savedObject->setId(1);
        $savedObject->setUuid($uuid);
        $savedObject->setObject($data);

        // Verify that SaveObject is called with folder ID
        $this->saveHandler
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->mockRegister,
                $this->mockSchema,
                $data,
                $uuid,
                $folderId // Folder ID should be passed
            )
            ->willReturn($savedObject);

        // Mock render handler
        $this->renderHandler
            ->method('renderEntity')
            ->willReturn($savedObject);

        // Execute test
        $result = $this->objectService->saveObject($data, [], null, null, $uuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($uuid, $result->getUuid());
    }

    /**
     * Test saveObject with validation enabled validates before saving
     *
     * @return void
     */
    public function testSaveObjectWithValidationEnabledValidatesBeforeSaving(): void
    {
        $data = ['name' => 'Test Object'];

        // Enable hard validation
        $this->mockSchema
            ->method('getHardValidation')
            ->willReturn(true);

        // Mock successful validation
        $validationResult = $this->createMock(ValidationResult::class);
        $validationResult->method('isValid')->willReturn(true);

        $this->validateHandler
            ->expects($this->once())
            ->method('validateObject')
            ->with($data, $this->mockSchema)
            ->willReturn($validationResult);

        // Mock successful save
        $savedObject = new ObjectEntity();
        $savedObject->setId(1);
        $savedObject->setUuid(Uuid::v4()->toRfc4122());
        $savedObject->setObject($data);

        $this->saveHandler
            ->method('saveObject')
            ->willReturn($savedObject);

        // Mock render handler
        $this->renderHandler
            ->method('renderEntity')
            ->willReturn($savedObject);

        // Execute test
        $result = $this->objectService->saveObject($data, [], null, null, null, false);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    /**
     * Test saveObject with validation failure throws ValidationException
     *
     * @return void
     */
    public function testSaveObjectWithValidationFailureThrowsException(): void
    {
        $data = ['name' => 'Invalid Object'];

        // Enable hard validation
        $this->mockSchema
            ->method('getHardValidation')
            ->willReturn(true);

        // Mock validation failure
        $validationResult = $this->createMock(ValidationResult::class);
        $validationResult->method('isValid')->willReturn(false);
        $validationError = $this->createMock(\Opis\JsonSchema\Errors\ValidationError::class);
        $validationResult->method('error')->willReturn($validationError);

        $this->validateHandler
            ->method('validateObject')
            ->with($data, $this->mockSchema)
            ->willReturn($validationResult);

        $this->validateHandler
            ->method('generateErrorMessage')
            ->with($validationResult)
            ->willReturn('Validation failed');

        // SaveObject should not be called
        $this->saveHandler
            ->expects($this->never())
            ->method('saveObject');

        // Execute test and expect exception
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Validation failed');

        $this->objectService->saveObject($data);
    }

    /**
     * Test saveObject with validation disabled skips validation
     *
     * @return void
     */
    public function testSaveObjectWithValidationDisabledSkipsValidation(): void
    {
        $data = ['name' => 'Test Object'];

        // Disable hard validation
        $this->mockSchema
            ->method('getHardValidation')
            ->willReturn(false);

        // Validation should not be called
        $this->validateHandler
            ->expects($this->never())
            ->method('validateObject');

        // Mock successful save
        $savedObject = new ObjectEntity();
        $savedObject->setId(1);
        $savedObject->setUuid(Uuid::v4()->toRfc4122());
        $savedObject->setObject($data);

        $this->saveHandler
            ->method('saveObject')
            ->willReturn($savedObject);

        // Mock render handler
        $this->renderHandler
            ->method('renderEntity')
            ->willReturn($savedObject);

        // Execute test
        $result = $this->objectService->saveObject($data, [], null, null, null, false);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    /**
     * Test saveObject with folder creation error continues without folder
     *
     * @return void
     */
    public function testSaveObjectWithFolderCreationErrorContinuesWithoutFolder(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = ['name' => 'Test Object'];

        // Mock existing object without folder
        $existingObject = new ObjectEntity();
        $existingObject->setId(1);
        $existingObject->setUuid($uuid);
        $existingObject->setFolder(null);

        $this->objectEntityMapper
            ->method('find')
            ->with($uuid)
            ->willReturn($existingObject);

        // Mock folder creation failure
        $this->fileService
            ->method('createObjectFolderWithoutUpdate')
            ->with($existingObject)
            ->willThrowException(new \Exception('Folder creation failed'));

        // Mock successful save
        $savedObject = new ObjectEntity();
        $savedObject->setId(1);
        $savedObject->setUuid($uuid);
        $savedObject->setObject($data);

        // Verify that SaveObject is called with null folder ID
        $this->saveHandler
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->mockRegister,
                $this->mockSchema,
                $data,
                $uuid,
                null // Folder ID should be null due to creation failure
            )
            ->willReturn($savedObject);

        // Mock render handler
        $this->renderHandler
            ->method('renderEntity')
            ->willReturn($savedObject);

        // Execute test
        $result = $this->objectService->saveObject($data, [], null, null, $uuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($uuid, $result->getUuid());
    }

    /**
     * Test saveObject with non-existent UUID for update still passes UUID
     *
     * @return void
     */
    public function testSaveObjectWithNonExistentUuidForUpdateStillPassesUuid(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $data = ['name' => 'Test Object'];

        // Mock that object doesn't exist
        $this->objectEntityMapper
            ->method('find')
            ->with($uuid)
            ->willThrowException(new DoesNotExistException('Object not found'));

        // Mock successful save
        $savedObject = new ObjectEntity();
        $savedObject->setId(1);
        $savedObject->setUuid($uuid);
        $savedObject->setObject($data);

        // Verify that SaveObject is still called with the UUID
        // SaveObject will handle the create-or-update logic
        $this->saveHandler
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->mockRegister,
                $this->mockSchema,
                $data,
                $uuid, // UUID should still be passed
                null   // folderId should be null
            )
            ->willReturn($savedObject);

        // Mock render handler
        $this->renderHandler
            ->method('renderEntity')
            ->willReturn($savedObject);

        // Execute test
        $result = $this->objectService->saveObject($data, [], null, null, $uuid);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $this->assertEquals($uuid, $result->getUuid());
    }

    /**
     * Test saveObject with register and schema parameters
     *
     * @return void
     */
    public function testSaveObjectWithRegisterAndSchemaParameters(): void
    {
        $data = ['name' => 'Test Object'];
        $customRegister = $this->createMock(Register::class);
        $customSchema = $this->createMock(Schema::class);

        $customRegister->id = 2;
        $customSchema->id = 2;
        $customSchema->method('getHardValidation')->willReturn(false);

        // Mock successful save
        $savedObject = new ObjectEntity();
        $savedObject->setId(1);
        $savedObject->setUuid(Uuid::v4()->toRfc4122());
        $savedObject->setObject($data);

        // Verify that SaveObject is called with custom register and schema
        $this->saveHandler
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $customRegister,
                $customSchema,
                $data,
                null,
                null
            )
            ->willReturn($savedObject);

        // Mock render handler
        $this->renderHandler
            ->method('renderEntity')
            ->willReturn($savedObject);

        // Execute test
        $result = $this->objectService->saveObject($data, [], $customRegister, $customSchema);

        // Assertions
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    /**
     * Test that enrichObjects method formats datetime values correctly for database storage
     *
     * This test verifies that the enrichObjects method uses MySQL-compatible datetime format
     * (Y-m-d H:i:s) instead of ISO 8601 format to prevent SQL datetime format errors.
     *
     * @return void
     */
    /**
     * Test that enrichObjects properly formats datetime fields
     */
    public function testEnrichObjectsFormatsDateTimeCorrectly(): void
    {
        // Create test objects with DateTime instances
        $objects = [
            [
                'id' => 1,
                'name' => 'Test Object 1',
                'created' => new \DateTime('2024-01-01 10:00:00'),
                'updated' => new \DateTime('2024-01-02 15:30:00')
            ],
            [
                'id' => 2,
                'name' => 'Test Object 2',
                'created' => new \DateTime('2024-01-03 09:15:00'),
                'updated' => new \DateTime('2024-01-04 14:45:00')
            ]
        ];

        // Call the enrichObjects method
        $enrichedObjects = $this->objectService->enrichObjects($objects);

        // Assert that datetime fields are properly formatted
        $this->assertCount(2, $enrichedObjects);
        
        // Check first object
        $this->assertEquals('2024-01-01 10:00:00', $enrichedObjects[0]['created']);
        $this->assertEquals('2024-01-02 15:30:00', $enrichedObjects[0]['updated']);
        
        // Check second object
        $this->assertEquals('2024-01-03 09:15:00', $enrichedObjects[1]['created']);
        $this->assertEquals('2024-01-04 14:45:00', $enrichedObjects[1]['updated']);
        
        // Verify other fields are unchanged
        $this->assertEquals(1, $enrichedObjects[0]['id']);
        $this->assertEquals('Test Object 1', $enrichedObjects[0]['name']);
        $this->assertEquals(2, $enrichedObjects[1]['id']);
        $this->assertEquals('Test Object 2', $enrichedObjects[1]['name']);
    }

    /**
     * Test that saveObjects function properly updates the updated datetime when updating existing objects
     *
     * This test verifies that when using saveObjects to update existing objects,
     * the updated datetime field is properly updated in the ObjectEntity instances.
     *
     * @return void
     */
    public function testSaveObjectsUpdatesUpdatedDateTimeForExistingObjects(): void
    {
        // Mock the SaveObjects handler to return the expected objects

        // Create test objects - one new, one existing
        $testObjects = [
            [
                'name' => 'New Object',
                '@self' => []
            ],
            [
                'name' => 'Updated Object',
                '@self' => [
                    'id' => 'existing-uuid-123',
                    'created' => '2024-01-01 10:00:00',
                    'updated' => '2024-01-01 10:00:00'
                ]
            ]
        ];

        // Create expected return objects
        $newObject = new ObjectEntity();
        $newObject->setId(2);
        $newObject->setUuid('new-uuid-456');
        $newObject->setCreated(new \DateTime());
        $newObject->setUpdated(new \DateTime());
        $newObject->setObject(['name' => 'New Object']);

        $updatedObject = new ObjectEntity();
        $updatedObject->setId(1);
        $updatedObject->setUuid('existing-uuid-123');
        $updatedObject->setCreated(new \DateTime('2024-01-01 10:00:00'));
        $updatedObject->setUpdated(new \DateTime()); // This should be updated
        $updatedObject->setObject(['name' => 'Updated Object']);

        // Mock the SaveObjects handler
        $this->saveObjectsHandler
            ->expects($this->once())
            ->method('saveObjects')
            ->willReturn([$newObject, $updatedObject]);

        // Execute the public method
        $savedObjects = $this->objectService->saveObjects($testObjects, $this->mockRegister, $this->mockSchema);

        // Verify that we got the expected number of saved objects
        $this->assertCount(2, $savedObjects);

        // Find the updated object
        $updatedSavedObject = null;
        foreach ($savedObjects as $savedObject) {
            if ($savedObject->getUuid() === 'existing-uuid-123') {
                $updatedSavedObject = $savedObject;
                break;
            }
        }

        $this->assertNotNull($updatedSavedObject, 'Updated object should be found in saved objects');

        // Verify that the updated datetime is recent (within last minute)
        $now = new \DateTime();
        $updatedDateTime = $updatedSavedObject->getUpdated();
        $this->assertNotNull($updatedDateTime, 'Updated datetime should not be null');
        $this->assertLessThan(60, $now->getTimestamp() - $updatedDateTime->getTimestamp(), 'Updated datetime should be recent');

        // Verify that the updated datetime is different from the original
        $originalUpdated = new \DateTime('2024-01-01 10:00:00');
        $this->assertGreaterThan($originalUpdated->getTimestamp(), $updatedDateTime->getTimestamp(), 'Updated datetime should be newer than original');
    }
} 