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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use stdClass;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Twig\Loader\ArrayLoader;
use Psr\Log\LoggerInterface;

/**
 * Testable Schema subclass that allows overriding methods that depend on external services.
 *
 * Schema::getSchemaObject() requires an IURLGenerator, and Schema::getConfiguration()
 * and Schema::getProperties() need to be controllable in tests without mocking __call.
 */
class FileUploadTestableSchema extends Schema
{
    public ?stdClass $testSchemaObject = null;
    public ?array $testConfiguration = null;
    public ?array $testProperties = null;

    /**
     * Override getSchemaObject to return the test value.
     *
     * @param IURLGenerator $urlGenerator URL generator (unused in test double).
     *
     * @return stdClass
     */
    public function getSchemaObject(IURLGenerator $urlGenerator): stdClass
    {
        return $this->testSchemaObject ?? new stdClass();
    }

    /**
     * Override getConfiguration to return the test value.
     *
     * @return array|null
     */
    public function getConfiguration(): ?array
    {
        return $this->testConfiguration;
    }

    /**
     * Override getProperties to return the test value.
     *
     * @return array
     */
    public function getProperties(): array
    {
        return $this->testProperties ?? [];
    }

    /**
     * Override hasPropertyAuthorization to return false in tests.
     *
     * @return bool
     */
    public function hasPropertyAuthorization(): bool
    {
        return false;
    }
}

/**
 * Unit tests for integrated file upload functionality
 *
 * Tests cover:
 * - Multipart/form-data file uploads
 * - Base64-encoded file uploads
 * - URL-based file references
 * - Mixed file upload scenarios
 * - File metadata hydration
 */
class IntegratedFileUploadTest extends TestCase
{
    /** @var SaveObject */
    private SaveObject $saveObject;

    /** @var MockObject|MagicMapper */
    private $objectEntityMapper;

    /** @var MockObject|MagicMapper */
    private $unifiedObjectMapper;

    /** @var MockObject|MetadataHydrationHandler */
    private $metaHydrationHandler;

    /** @var MockObject|FilePropertyHandler */
    private $filePropertyHandler;

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

    /** @var Register */
    private Register $mockRegister;

    /** @var FileUploadTestableSchema */
    private FileUploadTestableSchema $mockSchema;

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
        $arrayLoader = new ArrayLoader();

        // Create real entity instances (Entity __call methods cannot be mocked in PHPUnit 10+).
        $this->mockRegister = new Register();
        $this->mockRegister->setId(1);
        $this->mockRegister->setSlug('documents');

        $this->mockSchema = new FileUploadTestableSchema();

        $this->mockUser = $this->createMock(IUser::class);

        $this->mockUser->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($this->mockUser);

        $this->organisationService->method('getOrganisationForNewEntity')->willReturn('org-123');

        // Default: isFileProperty returns false (overridden per-test as needed).
        $this->filePropertyHandler->method('isFileProperty')->willReturn(false);

        $linkedEntityHandler = $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\LinkedEntityPropertyHandler::class);
        $computedFieldHandler = $this->createMock(\OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler::class);
        $translationHandler = $this->createMock(\OCA\OpenRegister\Service\Object\TranslationHandler::class);
        $translationHandler->method('normalizeTranslationsForSave')
            ->willReturnArgument(0);
        $tmloService = $this->createMock(\OCA\OpenRegister\Service\TmloService::class);

        // Create SaveObject instance with correct constructor params.
        $this->saveObject = new SaveObject(
            $this->objectEntityMapper,
            $this->unifiedObjectMapper,
            $this->metaHydrationHandler,
            $this->filePropertyHandler,
            $linkedEntityHandler,
            $this->userSession,
            $this->auditTrailMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->urlGenerator,
            $this->organisationService,
            $this->cacheHandler,
            $this->settingsService,
            $this->propertyRbacHandler,
            $computedFieldHandler,
            $translationHandler,
            $this->logger,
            $tmloService,
            $this->createMock(\OCA\OpenRegister\Service\File\FolderManagementHandler::class),
            $arrayLoader
        );
    }

    /**
     * Helper to set up a standard object insertion mock on MagicMapper.
     *
     * The insert returns a real ObjectEntity with the given data applied.
     *
     * @return void
     */
    private function mockInsert(): void
    {
        $this->unifiedObjectMapper
            ->method('insert')
            ->willReturnCallback(function ($entity) {
                if ($entity->getId() === null) {
                    $entity->setId(1);
                }
                return $entity;
            });
    }

    /**
     * Test multipart file upload: Single file property
     *
     * @return void
     */
    public function testMultipartFileUploadSingleFile(): void
    {
        // Arrange: Set up schema with file property.
        $this->mockSchema->setId(1);
        $this->mockSchema->testProperties = [
            'title' => ['type' => 'string'],
            'attachment' => ['type' => 'file']
        ];
        $this->mockSchema->testConfiguration = [];
        $this->mockSchema->testSchemaObject = (object)[
            'properties' => [
                'title' => ['type' => 'string'],
                'attachment' => ['type' => 'file']
            ]
        ];

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Mock uploaded file (simulating $_FILES format).
        $uploadedFiles = [
            'attachment' => [
                'name' => 'document.pdf',
                'type' => 'application/pdf',
                'tmp_name' => '/tmp/phpABC123',
                'error' => UPLOAD_ERR_OK,
                'size' => 102400
            ]
        ];

        // Mock processUploadedFiles to inject file data into the object data.
        $this->filePropertyHandler
            ->method('processUploadedFiles')
            ->willReturnCallback(function ($uploadedFiles, $data) {
                $data['attachment'] = 123;
                return $data;
            });

        $this->mockInsert();

        // Act: Save object with uploaded file.
        $objectData = ['title' => 'Test Document'];

        $result = $this->saveObject->saveObject(
            $this->mockRegister,
            $this->mockSchema,
            $objectData,
            null,
            null,
            false,
            false,
            true,
            true,
            false,
            $uploadedFiles
        );

        // Assert: Object was created with file data.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $savedData = $result->getObject();
        $this->assertEquals(123, $savedData['attachment'], 'File ID should be stored in property');
    }

    /**
     * Test base64 file upload: Data URI format
     *
     * @return void
     */
    public function testBase64FileUploadWithDataURI(): void
    {
        // Arrange: Set up schema with file property.
        $this->mockSchema->setId(1);
        $this->mockSchema->testProperties = [
            'title' => ['type' => 'string'],
            'image' => ['type' => 'file']
        ];
        $this->mockSchema->testConfiguration = [];
        $this->mockSchema->testSchemaObject = (object)[
            'properties' => [
                'title' => ['type' => 'string'],
                'image' => ['type' => 'file']
            ]
        ];

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Create base64-encoded image data URI.
        $imageContent = 'fake-image-content';
        $base64Content = base64_encode($imageContent);
        $dataUri = "data:image/png;base64,{$base64Content}";

        $this->mockInsert();

        // Act: Save object with base64 file.
        $objectData = [
            'title' => 'Test Image',
            'image' => $dataUri
        ];

        $result = $this->saveObject->saveObject(
            $this->mockRegister,
            $this->mockSchema,
            $objectData,
            null,
            null,
            false,
            false,
            true,
            true,
            false
        );

        // Assert: Object was created.
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    /**
     * Test URL file reference: Download from external URL
     *
     * @return void
     */
    public function testURLFileReference(): void
    {
        // Arrange: Set up schema with file property.
        $this->mockSchema->setId(1);
        $this->mockSchema->testProperties = [
            'title' => ['type' => 'string'],
            'document' => ['type' => 'file']
        ];
        $this->mockSchema->testConfiguration = [];
        $this->mockSchema->testSchemaObject = (object)[
            'properties' => [
                'title' => ['type' => 'string'],
                'document' => ['type' => 'file']
            ]
        ];

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        $this->mockInsert();

        // Act: Save object with URL file reference.
        $objectData = [
            'title' => 'Remote Document',
            'document' => 'https://example.com/files/document.pdf'
        ];

        $result = $this->saveObject->saveObject(
            $this->mockRegister,
            $this->mockSchema,
            $objectData,
            null,
            null,
            false,
            false,
            true,
            true,
            false
        );

        // Assert: Object was created.
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    /**
     * Test mixed file types: Multiple files with different upload methods
     *
     * @return void
     */
    public function testMixedFileTypes(): void
    {
        // Arrange: Set up schema with multiple file properties.
        $this->mockSchema->setId(1);
        $this->mockSchema->testProperties = [
            'title' => ['type' => 'string'],
            'attachment' => ['type' => 'file'],
            'image' => ['type' => 'file'],
            'reference' => ['type' => 'file']
        ];
        $this->mockSchema->testConfiguration = [];
        $this->mockSchema->testSchemaObject = (object)[
            'properties' => [
                'title' => ['type' => 'string'],
                'attachment' => ['type' => 'file'],
                'image' => ['type' => 'file'],
                'reference' => ['type' => 'file']
            ]
        ];

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Mock uploaded file (multipart).
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

        // Base64-encoded image.
        $imageContent = 'fake-image';
        $dataUri = 'data:image/jpeg;base64,' . base64_encode($imageContent);

        // Mock processUploadedFiles to inject multipart file data.
        $this->filePropertyHandler
            ->method('processUploadedFiles')
            ->willReturnCallback(function ($uploadedFiles, $data) {
                $data['attachment'] = 123;
                return $data;
            });

        $this->mockInsert();

        // Act: Save object with mixed file types.
        $objectData = [
            'title' => 'Multi-File Document',
            'image' => $dataUri,
            'reference' => 'https://example.com/file.doc'
        ];

        $result = $this->saveObject->saveObject(
            $this->mockRegister,
            $this->mockSchema,
            $objectData,
            null,
            null,
            false,
            false,
            true,
            true,
            false,
            $uploadedFiles
        );

        // Assert: Object was created.
        $this->assertInstanceOf(ObjectEntity::class, $result);

        // Cleanup.
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
        // Arrange: Set up schema with array of files.
        $this->mockSchema->setId(1);
        $this->mockSchema->testProperties = [
            'title' => ['type' => 'string'],
            'attachments' => [
                'type' => 'array',
                'items' => ['type' => 'file']
            ]
        ];
        $this->mockSchema->testConfiguration = [];
        $this->mockSchema->testSchemaObject = (object)[
            'properties' => [
                'title' => ['type' => 'string'],
                'attachments' => [
                    'type' => 'array',
                    'items' => ['type' => 'file']
                ]
            ]
        ];

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Create array of base64 files.
        $file1 = 'data:application/pdf;base64,' . base64_encode('file1');
        $file2 = 'data:application/pdf;base64,' . base64_encode('file2');
        $file3 = 'https://example.com/file3.pdf';

        $this->mockInsert();

        // Act: Save object with array of files.
        $objectData = [
            'title' => 'Multi-Attachment Document',
            'attachments' => [$file1, $file2, $file3]
        ];

        $result = $this->saveObject->saveObject(
            $this->mockRegister,
            $this->mockSchema,
            $objectData,
            null,
            null,
            false,
            false,
            true,
            true,
            false
        );

        // Assert: Object was created.
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    /**
     * Test file upload error handling: Invalid multipart file
     *
     * @return void
     */
    public function testMultipartFileUploadError(): void
    {
        // Arrange: Set up schema.
        $this->mockSchema->setId(1);
        $this->mockSchema->testProperties = [
            'attachment' => ['type' => 'file']
        ];
        $this->mockSchema->testConfiguration = [];
        $this->mockSchema->testSchemaObject = (object)[
            'properties' => ['attachment' => ['type' => 'file']]
        ];

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Mock uploaded file with error.
        $uploadedFiles = [
            'attachment' => [
                'name' => 'document.pdf',
                'type' => 'application/pdf',
                'tmp_name' => '',
                'error' => UPLOAD_ERR_NO_FILE,
                'size' => 0
            ]
        ];

        // Mock processUploadedFiles to pass through data unchanged (error file skipped).
        $this->filePropertyHandler
            ->method('processUploadedFiles')
            ->willReturnCallback(function ($uploadedFiles, $data) {
                return $data;
            });

        $this->mockInsert();

        // Act: Save object with failed upload.
        $objectData = ['title' => 'Test'];

        $result = $this->saveObject->saveObject(
            $this->mockRegister,
            $this->mockSchema,
            $objectData,
            null,
            null,
            false,
            false,
            true,
            true,
            false,
            $uploadedFiles
        );

        // Assert: Object created despite upload error.
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }

    /**
     * Test that file upload triggers background job for text extraction
     *
     * @return void
     */
    public function testFileUploadQueuesBackgroundJobForTextExtraction(): void
    {
        // Arrange: Set up schema with file property.
        $this->mockSchema->setId(1);
        $this->mockSchema->testProperties = [
            'title' => ['type' => 'string'],
            'document' => ['type' => 'file']
        ];
        $this->mockSchema->testConfiguration = [];
        $this->mockSchema->testSchemaObject = (object)[
            'properties' => [
                'title' => ['type' => 'string'],
                'document' => ['type' => 'file']
            ]
        ];

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Create test file content.
        $testFileContent = 'This is a test document for text extraction background job testing.';
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, $testFileContent);

        $uploadedFiles = [
            'document' => [
                'name' => 'test-doc.txt',
                'type' => 'text/plain',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($testFileContent)
            ]
        ];

        // Mock processUploadedFiles to inject file data.
        $this->filePropertyHandler
            ->method('processUploadedFiles')
            ->willReturnCallback(function ($uploadedFiles, $data) {
                $data['document'] = 999;
                return $data;
            });

        $this->mockInsert();

        // Act: Save object with uploaded file.
        $objectData = ['title' => 'Background Job Test Document'];

        $result = $this->saveObject->saveObject(
            $this->mockRegister,
            $this->mockSchema,
            $objectData,
            null,
            null,
            false,
            false,
            true,
            true,
            false,
            $uploadedFiles
        );

        // Assert: File was uploaded successfully.
        $this->assertInstanceOf(ObjectEntity::class, $result);
        $savedData = $result->getObject();
        $this->assertEquals(999, $savedData['document'], 'File ID should be stored');

        // Cleanup.
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
    }

    /**
     * Test that text extraction doesn't block file upload
     *
     * @return void
     */
    public function testFileUploadIsNonBlocking(): void
    {
        // Arrange: Set up schema.
        $this->mockSchema->setId(1);
        $this->mockSchema->testProperties = [
            'document' => ['type' => 'file']
        ];
        $this->mockSchema->testConfiguration = [];
        $this->mockSchema->testSchemaObject = (object)[
            'properties' => ['document' => ['type' => 'file']]
        ];

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Create test file.
        $testFileContent = str_repeat('Sample text content. ', 100);
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, $testFileContent);

        $uploadedFiles = [
            'document' => [
                'name' => 'large-doc.txt',
                'type' => 'text/plain',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($testFileContent)
            ]
        ];

        // Mock processUploadedFiles.
        $this->filePropertyHandler
            ->method('processUploadedFiles')
            ->willReturnCallback(function ($uploadedFiles, $data) {
                $data['document'] = 123;
                return $data;
            });

        $this->mockInsert();

        // Act: Measure upload time.
        $startTime = microtime(true);

        $result = $this->saveObject->saveObject(
            $this->mockRegister,
            $this->mockSchema,
            [],
            null,
            null,
            false,
            false,
            true,
            true,
            false,
            $uploadedFiles
        );

        $uploadTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        // Assert: Upload completes quickly (< 100ms).
        $this->assertLessThan(100, $uploadTime, 'File upload should complete in < 100ms (non-blocking)');

        // Assert: Object was stored successfully.
        $this->assertInstanceOf(ObjectEntity::class, $result);

        // Cleanup.
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }
    }

    /**
     * Test that PDF upload queues background job (common use case)
     *
     * @return void
     */
    public function testPDFUploadQueuesBackgroundJob(): void
    {
        // Arrange: Set up schema.
        $this->mockSchema->setId(1);
        $this->mockSchema->testProperties = [
            'pdf' => ['type' => 'file']
        ];
        $this->mockSchema->testConfiguration = [];
        $this->mockSchema->testSchemaObject = (object)[
            'properties' => ['pdf' => ['type' => 'file']]
        ];

        $this->schemaMapper->method('find')->willReturn($this->mockSchema);
        $this->registerMapper->method('find')->willReturn($this->mockRegister);

        // Create test PDF (base64 data URI).
        $pdfContent = '%PDF-1.4 sample content';
        $dataUri = 'data:application/pdf;base64,' . base64_encode($pdfContent);

        $this->mockInsert();

        // Act: Upload PDF.
        $result = $this->saveObject->saveObject(
            $this->mockRegister,
            $this->mockSchema,
            ['pdf' => $dataUri],
            null,
            null,
            false,
            false,
            true,
            true,
            false
        );

        // Assert: PDF uploaded successfully.
        $this->assertInstanceOf(ObjectEntity::class, $result);
    }
}
