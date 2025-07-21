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
use OCA\OpenRegister\Service\ObjectHandlers\ValidateObject;
use OCA\OpenRegister\Service\ObjectHandlers\PublishObject;
use OCA\OpenRegister\Service\ObjectHandlers\DepublishObject;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use OCP\IUser;
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
        $this->validateHandler = $this->createMock(ValidateObject::class);
        $this->publishHandler = $this->createMock(PublishObject::class);
        $this->depublishHandler = $this->createMock(DepublishObject::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->searchTrailService = $this->createMock(SearchTrailService::class);

        // Create mock entities
        $this->mockRegister = $this->createMock(Register::class);
        $this->mockSchema = $this->createMock(Schema::class);
        $this->mockUser = $this->createMock(IUser::class);

        // Set up basic mock returns
        $this->mockRegister->method('getId')->willReturn(1);
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getHardValidation')->willReturn(false);
        $this->mockUser->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        // Create ObjectService instance
        $this->objectService = new ObjectService(
            $this->deleteHandler,
            $this->getHandler,
            $this->renderHandler,
            $this->saveHandler,
            $this->validateHandler,
            $this->publishHandler,
            $this->depublishHandler,
            $this->registerMapper,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->fileService,
            $this->userSession,
            $this->searchTrailService
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
        $result = $this->objectService->saveObject($data);

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
        $this->saveHandler
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->mockRegister,
                $this->mockSchema,
                $data,    // Data should be extracted from ObjectEntity
                $uuid,    // UUID should be extracted from ObjectEntity
                null      // folderId should be null
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
        $result = $this->objectService->saveObject($data);

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
        $validationResult->method('error')->willReturn(['error' => 'Invalid data']);

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
        $result = $this->objectService->saveObject($data);

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

        $customRegister->method('getId')->willReturn(2);
        $customSchema->method('getId')->willReturn(2);
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
} 