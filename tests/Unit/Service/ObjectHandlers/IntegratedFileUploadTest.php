<?php

/**
 * Integrated File Upload Tests
 *
 * Comprehensive tests for integrated file upload functionality in object POST/PUT operations.
 * Tests cover multipart/form-data, base64, URL references, and mixed scenarios.
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
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\ObjectCacheService;
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IUser;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Twig\Loader\ArrayLoader;
use Symfony\Component\Uid\Uuid;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for integrated file upload functionality
 *
 * Tests cover:
 * - Multipart/form-data file uploads
 * - Base64-encoded file uploads
 * - URL-based file references
 * - Mixed file upload scenarios
 * - Schema validation for files
 * - File metadata hydration
 */
class IntegratedFileUploadTest extends TestCase
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

    /** @var MockObject|OrganisationService */
    private $organisationService;

    /** @var MockObject|ObjectCacheService */
    private $objectCacheService;

    /** @var MockObject|SchemaCacheService */
    private $schemaCacheService;

    /** @var MockObject|SchemaFacetCacheService */
    private $schemaFacetCacheService;

    /** @var MockObject|SettingsService */
    private $settingsService;

    /** @var MockObject|LoggerInterface */
    private $logger;

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
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->objectCacheService = $this->createMock(ObjectCacheService::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheService::class);
        $this->schemaFacetCacheService = $this->createMock(SchemaFacetCacheService::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->arrayLoader = $this->createMock(ArrayLoader::class);

        // Create mock entities
        $this->mockRegister = $this->createMock(Register::class);
        $this->mockSchema = $this->createMock(Schema::class);
        $this->mockUser = $this->createMock(IUser::class);

        // Set up basic mock returns
        $this->mockRegister->method('getId')->willReturn(1);
        $this->mockRegister->method('getSlug')->willReturn('documents');
        
        $this->mockUser->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->mockUser);
        
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn('org-123');
        $this->settingsService->method('getSetting')->willReturn(false);

        // Create SaveObject instance
        $this->saveObject = new SaveObject(
            $this->objectEntityMapper,
            $this->fileService,
            $this->userSession,
            $this->auditTrailMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->urlGenerator,
            $this->organisationService,
            $this->objectCacheService,
            $this->schemaCacheService,
            $this->schemaFacetCacheService,
            $this->settingsService,
            $this->logger,
            $this->arrayLoader
        );
    }

    /**
     * Test multipart file upload: Single file property
     *
     * @return void
     */
    public function testMultipartFileUploadSingleFile(): void
    {
        // Arrange: Set up schema with file property
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getProperties')->willReturn([
            'title' => ['type' => 'string'],
            'attachment' => ['type' => 'file']
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn([]);
        $this->mockSchema->method('getSchemaObject')->willReturn((object)[
            'properties' => [
                'title' => ['type' => 'string'],
                'attachment' => ['type' => 'file']
            ]
        ]);

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Mock uploaded file (simulating $_FILES format)
        $uploadedFiles = [
            'attachment' => [
                'name' => 'document.pdf',
                'type' => 'application/pdf',
                'tmp_name' => '/tmp/phpABC123',
                'error' => UPLOAD_ERR_OK,
                'size' => 102400
            ]
        ];

        // Create temporary test file
        $testFileContent = 'PDF file content...';
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, $testFileContent);
        $uploadedFiles['attachment']['tmp_name'] = $tmpFile;

        // Mock file service to expect file creation
        $mockFile = $this->createMock(File::class);
        $mockFile->method('getId')->willReturn(123);
        
        $this->fileService->expects($this->once())
            ->method('addFile')
            ->willReturn($mockFile);

        // Mock object insertion
        $this->objectEntityMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function($entity) {
                $entity->setId(1);
                return $entity;
            });

        // Act: Save object with uploaded file
        $objectData = ['title' => 'Test Document'];
        
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $objectData,
            uuid: null,
            folderId: null,
            rbac: false,
            multi: false,
            persist: true,
            silent: true,
            validation: false,
            uploadedFiles: $uploadedFiles
        );

        // Assert: File was processed and ID stored
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $savedData = $result->getObject();
        $this->assertEquals(123, $savedData['attachment'], 'File ID should be stored in property');

        // Cleanup
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
    }

    /**
     * Test base64 file upload: Data URI format
     *
     * @return void
     */
    public function testBase64FileUploadWithDataURI(): void
    {
        // Arrange: Set up schema with file property
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getProperties')->willReturn([
            'title' => ['type' => 'string'],
            'image' => ['type' => 'file']
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn([]);
        $this->mockSchema->method('getSchemaObject')->willReturn((object)[
            'properties' => [
                'title' => ['type' => 'string'],
                'image' => ['type' => 'file']
            ]
        ]);

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Create base64-encoded image data URI
        $imageContent = 'fake-image-content';
        $base64Content = base64_encode($imageContent);
        $dataUri = "data:image/png;base64,{$base64Content}";

        // Mock file service
        $mockFile = $this->createMock(File::class);
        $mockFile->method('getId')->willReturn(456);
        
        $this->fileService->expects($this->once())
            ->method('addFile')
            ->with(
                $this->anything(),
                $this->stringContains('image'),
                $imageContent,
                false,
                []
            )
            ->willReturn($mockFile);

        // Mock object insertion
        $this->objectEntityMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function($entity) {
                $entity->setId(1);
                return $entity;
            });

        // Act: Save object with base64 file
        $objectData = [
            'title' => 'Test Image',
            'image' => $dataUri
        ];
        
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $objectData,
            uuid: null,
            folderId: null,
            rbac: false,
            multi: false,
            persist: true,
            silent: true,
            validation: false
        );

        // Assert: File was processed and ID stored
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $savedData = $result->getObject();
        $this->assertEquals(456, $savedData['image'], 'File ID should be stored in property');
    }

    /**
     * Test URL file reference: Download from external URL
     *
     * @return void
     */
    public function testURLFileReference(): void
    {
        // Arrange: Set up schema with file property
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getProperties')->willReturn([
            'title' => ['type' => 'string'],
            'document' => ['type' => 'file']
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn([]);
        $this->mockSchema->method('getSchemaObject')->willReturn((object)[
            'properties' => [
                'title' => ['type' => 'string'],
                'document' => ['type' => 'file']
            ]
        ]);

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Mock file service
        $mockFile = $this->createMock(File::class);
        $mockFile->method('getId')->willReturn(789);
        
        $this->fileService->expects($this->once())
            ->method('addFile')
            ->willReturn($mockFile);

        // Mock object insertion
        $this->objectEntityMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function($entity) {
                $entity->setId(1);
                return $entity;
            });

        // Act: Save object with URL file reference
        $objectData = [
            'title' => 'Remote Document',
            'document' => 'https://example.com/files/document.pdf'
        ];
        
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $objectData,
            uuid: null,
            folderId: null,
            rbac: false,
            multi: false,
            persist: true,
            silent: true,
            validation: false
        );

        // Assert: File was downloaded and ID stored
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $savedData = $result->getObject();
        $this->assertEquals(789, $savedData['document'], 'File ID should be stored in property');
    }

    /**
     * Test mixed file types: Multiple files with different upload methods
     *
     * @return void
     */
    public function testMixedFileTypes(): void
    {
        // Arrange: Set up schema with multiple file properties
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getProperties')->willReturn([
            'title' => ['type' => 'string'],
            'attachment' => ['type' => 'file'],
            'image' => ['type' => 'file'],
            'reference' => ['type' => 'file']
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn([]);
        $this->mockSchema->method('getSchemaObject')->willReturn((object)[
            'properties' => [
                'title' => ['type' => 'string'],
                'attachment' => ['type' => 'file'],
                'image' => ['type' => 'file'],
                'reference' => ['type' => 'file']
            ]
        ]);

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Mock uploaded file (multipart)
        $testFileContent = 'PDF content';
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, $testFileContent);
        
        $uploadedFiles = [
            'attachment' => [
                'name' => 'document.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($testFileContent)
            ]
        ];

        // Base64-encoded image
        $imageContent = 'fake-image';
        $dataUri = 'data:image/jpeg;base64,' . base64_encode($imageContent);

        // Mock file service to handle multiple files
        $mockFileIds = [123, 456, 789];
        $callCount = 0;
        
        $this->fileService->expects($this->exactly(3))
            ->method('addFile')
            ->willReturnCallback(function() use ($mockFileIds, &$callCount) {
                $mockFile = $this->createMock(File::class);
                $mockFile->method('getId')->willReturn($mockFileIds[$callCount++]);
                return $mockFile;
            });

        // Mock object insertion
        $this->objectEntityMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function($entity) {
                $entity->setId(1);
                return $entity;
            });

        // Act: Save object with mixed file types
        $objectData = [
            'title' => 'Multi-File Document',
            'image' => $dataUri,
            'reference' => 'https://example.com/file.doc'
        ];
        
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $objectData,
            uuid: null,
            folderId: null,
            rbac: false,
            multi: false,
            persist: true,
            silent: true,
            validation: false,
            uploadedFiles: $uploadedFiles
        );

        // Assert: All files were processed
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $savedData = $result->getObject();
        
        $this->assertIsInt($savedData['attachment'], 'Multipart file ID should be stored');
        $this->assertIsInt($savedData['image'], 'Base64 file ID should be stored');
        $this->assertIsInt($savedData['reference'], 'URL file ID should be stored');

        // Cleanup
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
    }

    /**
     * Test array of files: Multiple files in single property
     *
     * @return void
     */
    public function testArrayOfFiles(): void
    {
        // Arrange: Set up schema with array of files
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getProperties')->willReturn([
            'title' => ['type' => 'string'],
            'attachments' => [
                'type' => 'array',
                'items' => ['type' => 'file']
            ]
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn([]);
        $this->mockSchema->method('getSchemaObject')->willReturn((object)[
            'properties' => [
                'title' => ['type' => 'string'],
                'attachments' => [
                    'type' => 'array',
                    'items' => ['type' => 'file']
                ]
            ]
        ]);

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Create array of base64 files
        $file1 = 'data:application/pdf;base64,' . base64_encode('file1');
        $file2 = 'data:application/pdf;base64,' . base64_encode('file2');
        $file3 = 'https://example.com/file3.pdf';

        // Mock file service
        $mockFileIds = [111, 222, 333];
        $callCount = 0;
        
        $this->fileService->expects($this->exactly(3))
            ->method('addFile')
            ->willReturnCallback(function() use ($mockFileIds, &$callCount) {
                $mockFile = $this->createMock(File::class);
                $mockFile->method('getId')->willReturn($mockFileIds[$callCount++]);
                return $mockFile;
            });

        // Mock object insertion
        $this->objectEntityMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function($entity) {
                $entity->setId(1);
                return $entity;
            });

        // Act: Save object with array of files
        $objectData = [
            'title' => 'Multi-Attachment Document',
            'attachments' => [$file1, $file2, $file3]
        ];
        
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $objectData,
            uuid: null,
            folderId: null,
            rbac: false,
            multi: false,
            persist: true,
            silent: true,
            validation: false
        );

        // Assert: All files in array were processed
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $savedData = $result->getObject();
        
        $this->assertIsArray($savedData['attachments'], 'Attachments should be an array');
        $this->assertCount(3, $savedData['attachments'], 'Should have 3 file IDs');
        $this->assertEquals([111, 222, 333], $savedData['attachments'], 'All file IDs should be stored');
    }

    /**
     * Test file upload error handling: Invalid multipart file
     *
     * @return void
     */
    public function testMultipartFileUploadError(): void
    {
        // Arrange: Set up schema
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getProperties')->willReturn([
            'attachment' => ['type' => 'file']
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn([]);
        $this->mockSchema->method('getSchemaObject')->willReturn((object)[
            'properties' => ['attachment' => ['type' => 'file']]
        ]);

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Mock uploaded file with error
        $uploadedFiles = [
            'attachment' => [
                'name' => 'document.pdf',
                'type' => 'application/pdf',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_NO_FILE,
                'size' => 0
            ]
        ];

        // Logger should be called for the error
        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('File upload error'),
                $this->arrayHasKey('field')
            );

        // Mock object insertion (file error doesn't prevent object creation)
        $this->objectEntityMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function($entity) {
                $entity->setId(1);
                return $entity;
            });

        // Act: Save object with failed upload
        $objectData = ['title' => 'Test'];
        
        $result = $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: $objectData,
            uuid: null,
            folderId: null,
            rbac: false,
            multi: false,
            persist: true,
            silent: true,
            validation: false,
            uploadedFiles: $uploadedFiles
        );

        // Assert: Object created without file
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    /**
     * Test schema validation: Invalid MIME type
     *
     * @return void
     */
    public function testFileUploadWithInvalidMimeType(): void
    {
        // Arrange: Schema only allows PDF
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getProperties')->willReturn([
            'attachment' => [
                'type' => 'file',
                'allowedTypes' => ['application/pdf']
            ]
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn([]);
        $this->mockSchema->method('getSchemaObject')->willReturn((object)[
            'properties' => [
                'attachment' => [
                    'type' => 'file',
                    'allowedTypes' => ['application/pdf']
                ]
            ]
        ]);

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Try to upload a JPEG (not allowed!)
        $imageContent = 'fake-jpeg-content';
        $dataUri = 'data:image/jpeg;base64,' . base64_encode($imageContent);

        // Expect exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("has invalid type 'image/jpeg'");

        // Act: Should throw exception
        $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: ['attachment' => $dataUri],
            uuid: null,
            folderId: null,
            rbac: false,
            multi: false,
            persist: true,
            silent: true,
            validation: false
        );
    }

    /**
     * Test schema validation: File too large
     *
     * @return void
     */
    public function testFileUploadExceedsMaxSize(): void
    {
        // Arrange: Schema with 1MB limit
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getProperties')->willReturn([
            'attachment' => [
                'type' => 'file',
                'maxSize' => 1048576
            ]
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn([]);
        $this->mockSchema->method('getSchemaObject')->willReturn((object)[
            'properties' => [
                'attachment' => [
                    'type' => 'file',
                    'maxSize' => 1048576
                ]
            ]
        ]);

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Create a large file (2MB)
        $largeContent = str_repeat('A', 2 * 1024 * 1024);
        $dataUri = 'data:application/pdf;base64,' . base64_encode($largeContent);

        // Expect exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("exceeds maximum size (1048576 bytes)");

        // Act: Should throw exception
        $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: ['attachment' => $dataUri],
            uuid: null,
            folderId: null,
            rbac: false,
            multi: false,
            persist: true,
            silent: true,
            validation: false
        );
    }

    /**
     * Test invalid base64: Corrupted data
     *
     * @return void
     */
    public function testCorruptedBase64Upload(): void
    {
        // Arrange: Schema with file property
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getProperties')->willReturn([
            'attachment' => ['type' => 'file']
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn([]);
        $this->mockSchema->method('getSchemaObject')->willReturn((object)[
            'properties' => ['attachment' => ['type' => 'file']]
        ]);

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Invalid base64 string (not properly encoded)
        $corruptedData = 'data:application/pdf;base64,INVALID!!!BASE64@@@DATA';

        // Expect exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid base64 content");

        // Act: Should throw exception
        $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: ['attachment' => $corruptedData],
            uuid: null,
            folderId: null,
            rbac: false,
            multi: false,
            persist: true,
            silent: true,
            validation: false
        );
    }

    /**
     * Test multiple files with validation: one valid, one invalid
     *
     * @return void
     */
    public function testArrayWithValidationError(): void
    {
        // Arrange: Schema with strict validation
        $this->mockSchema->method('getId')->willReturn(1);
        $this->mockSchema->method('getProperties')->willReturn([
            'images' => [
                'type' => 'array',
                'items' => [
                    'type' => 'file',
                    'allowedTypes' => ['image/jpeg', 'image/png'],
                    'maxSize' => 1048576
                ]
            ]
        ]);
        $this->mockSchema->method('getConfiguration')->willReturn([]);
        $this->mockSchema->method('getSchemaObject')->willReturn((object)[
            'properties' => [
                'images' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'file',
                        'allowedTypes' => ['image/jpeg', 'image/png'],
                        'maxSize' => 1048576
                    ]
                ]
            ]
        ]);

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // First file OK, second file wrong type
        $validImage = 'data:image/jpeg;base64,' . base64_encode('valid');
        $invalidPdf = 'data:application/pdf;base64,' . base64_encode('pdf');

        // Expect exception on second file
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("has invalid type 'application/pdf'");

        // Act: Should fail on second file
        $this->saveObject->saveObject(
            register: $this->mockRegister,
            schema: $this->mockSchema,
            data: ['images' => [$validImage, $invalidPdf]],
            uuid: null,
            folderId: null,
            rbac: false,
            multi: false,
            persist: true,
            silent: true,
            validation: false
        );
    }
}

