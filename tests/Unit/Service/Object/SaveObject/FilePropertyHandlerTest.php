<?php

declare(strict_types=1);

/**
 * FilePropertyHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for FilePropertyHandler
 *
 * Tests file upload processing, file detection, validation, and security checks.
 */
class FilePropertyHandlerTest extends TestCase
{
    /** @var FilePropertyHandler */
    private FilePropertyHandler $handler;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var FileService&MockObject */
    private FileService $fileService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->fileService = $this->createMock(FileService::class);

        $this->handler = new FilePropertyHandler(
            $this->logger,
            $this->fileService
        );
    }

    // =========================================================================
    // processUploadedFiles
    // =========================================================================

    public function testProcessUploadedFilesWithValidFile(): void
    {
        // Create a temp file for testing.
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'test content');

        $uploadedFiles = [
            'document' => [
                'name' => 'test.txt',
                'type' => 'text/plain',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 12,
            ],
        ];

        $data = ['name' => 'Test'];

        $result = $this->handler->processUploadedFiles($uploadedFiles, $data);

        $this->assertArrayHasKey('document', $result);
        $this->assertStringStartsWith('data:text/plain;base64,', $result['document']);
        $this->assertSame('Test', $result['name']);

        unlink($tmpFile);
    }

    public function testProcessUploadedFilesSkipsUploadErrors(): void
    {
        $uploadedFiles = [
            'document' => [
                'name' => 'test.txt',
                'type' => 'text/plain',
                'tmp_name' => '/tmp/nonexistent',
                'error' => UPLOAD_ERR_NO_FILE,
                'size' => 0,
            ],
        ];

        $data = ['name' => 'Test'];

        $this->logger->expects($this->atLeastOnce())
            ->method('warning');

        $result = $this->handler->processUploadedFiles($uploadedFiles, $data);

        $this->assertArrayNotHasKey('document', $result);
        $this->assertSame('Test', $result['name']);
    }

    public function testProcessUploadedFilesHandlesArrayFieldNames(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tmpFile, 'image data');

        $uploadedFiles = [
            'images[0]' => [
                'name' => 'photo.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 10,
            ],
        ];

        $data = [];

        $result = $this->handler->processUploadedFiles($uploadedFiles, $data);

        // Array field names are cleaned: 'images[0]' -> 'images'.
        $this->assertArrayHasKey('images', $result);
        $this->assertIsArray($result['images']);
        $this->assertCount(1, $result['images']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $result['images'][0]);

        unlink($tmpFile);
    }

    public function testProcessUploadedFilesThrowsOnReadFailure(): void
    {
        $uploadedFiles = [
            'document' => [
                'name' => 'test.txt',
                'type' => 'text/plain',
                'tmp_name' => '/nonexistent/path/file.tmp',
                'error' => UPLOAD_ERR_OK,
                'size' => 100,
            ],
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Failed to read uploaded file for field 'document'");

        $this->handler->processUploadedFiles($uploadedFiles, []);
    }

    // =========================================================================
    // isFileProperty
    // =========================================================================

    public function testIsFilePropertyWithSchemaFileType(): void
    {
        $schema = new Schema();
        $schema->setProperties([
            'document' => ['type' => 'file'],
        ]);

        $result = $this->handler->isFileProperty('some-value', $schema, 'document');

        $this->assertTrue($result);
    }

    public function testIsFilePropertyWithSchemaArrayFileType(): void
    {
        $schema = new Schema();
        $schema->setProperties([
            'images' => ['type' => 'array', 'items' => ['type' => 'file']],
        ]);

        $result = $this->handler->isFileProperty(['val'], $schema, 'images');

        $this->assertTrue($result);
    }

    public function testIsFilePropertyWithSchemaStringType(): void
    {
        $schema = new Schema();
        $schema->setProperties([
            'name' => ['type' => 'string'],
        ]);

        $result = $this->handler->isFileProperty('John', $schema, 'name');

        $this->assertFalse($result);
    }

    public function testIsFilePropertyWithSchemaPropertyNotFound(): void
    {
        $schema = new Schema();
        $schema->setProperties([
            'name' => ['type' => 'string'],
        ]);

        $result = $this->handler->isFileProperty('value', $schema, 'nonexistent');

        $this->assertFalse($result);
    }

    public function testIsFilePropertyWithDataUri(): void
    {
        $dataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA';

        $result = $this->handler->isFileProperty($dataUri);

        $this->assertTrue($result);
    }

    public function testIsFilePropertyWithUrlAndFileExtension(): void
    {
        $url = 'https://example.com/files/report.pdf';

        $result = $this->handler->isFileProperty($url);

        $this->assertTrue($result);
    }

    public function testIsFilePropertyWithRegularWebUrl(): void
    {
        $url = 'https://example.com/about-us';

        $result = $this->handler->isFileProperty($url);

        $this->assertFalse($result);
    }

    public function testIsFilePropertyWithBase64String(): void
    {
        // Create a base64 string long enough (>100 chars).
        $content = str_repeat('A', 200);
        $base64 = base64_encode($content);

        $result = $this->handler->isFileProperty($base64);

        $this->assertTrue($result);
    }

    public function testIsFilePropertyWithShortString(): void
    {
        $result = $this->handler->isFileProperty('hello');

        $this->assertFalse($result);
    }

    public function testIsFilePropertyWithArrayOfDataUris(): void
    {
        $value = [
            'data:image/png;base64,iVBORw0',
        ];

        $result = $this->handler->isFileProperty($value);

        $this->assertTrue($result);
    }

    public function testIsFilePropertyWithNonFileArray(): void
    {
        $value = ['tag1', 'tag2', 'tag3'];

        $result = $this->handler->isFileProperty($value);

        $this->assertFalse($result);
    }

    // =========================================================================
    // isFileObject
    // =========================================================================

    public function testIsFileObjectWithValidFileObject(): void
    {
        $value = [
            'id' => '123',
            'title' => 'document.pdf',
            'path' => '/files/document.pdf',
            'type' => 'application/pdf',
            'size' => 1024,
        ];

        $result = $this->handler->isFileObject($value);

        $this->assertTrue($result);
    }

    public function testIsFileObjectWithoutId(): void
    {
        $value = [
            'title' => 'document.pdf',
            'path' => '/files/document.pdf',
        ];

        $result = $this->handler->isFileObject($value);

        $this->assertFalse($result);
    }

    public function testIsFileObjectWithoutTitleOrPath(): void
    {
        $value = [
            'id' => '123',
            'name' => 'not a file property',
        ];

        $result = $this->handler->isFileObject($value);

        $this->assertFalse($result);
    }

    public function testIsFileObjectWithMinimalFileObject(): void
    {
        $value = [
            'id' => '456',
            'title' => 'photo.jpg',
        ];

        $result = $this->handler->isFileObject($value);

        $this->assertTrue($result);
    }

    // =========================================================================
    // parseFileData
    // =========================================================================

    public function testParseFileDataWithDataUri(): void
    {
        $content = 'Hello World';
        $base64 = base64_encode($content);
        $dataUri = "data:text/plain;base64,{$base64}";

        $result = $this->handler->parseFileData($dataUri);

        $this->assertSame($content, $result['content']);
        $this->assertSame('text/plain', $result['mimeType']);
        $this->assertSame(strlen($content), $result['size']);
        $this->assertArrayHasKey('extension', $result);
    }

    public function testParseFileDataWithPlainBase64(): void
    {
        $content = 'Some binary content';
        $base64 = base64_encode($content);

        $result = $this->handler->parseFileData($base64);

        $this->assertSame($content, $result['content']);
        $this->assertSame(strlen($content), $result['size']);
    }

    public function testParseFileDataThrowsOnInvalidDataUri(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid data URI format');

        $this->handler->parseFileData('data:invalid-format');
    }

    public function testParseFileDataThrowsOnInvalidBase64(): void
    {
        $this->expectException(Exception::class);

        // Not valid base64.
        $this->handler->parseFileData('!!!not-base64!!!');
    }

    public function testParseFileDataWithImageDataUri(): void
    {
        // Create a small valid PNG-like base64.
        $pngData = 'fake-png-content-for-test';
        $base64 = base64_encode($pngData);
        $dataUri = "data:image/png;base64,{$base64}";

        $result = $this->handler->parseFileData($dataUri);

        $this->assertSame('image/png', $result['mimeType']);
        $this->assertSame($pngData, $result['content']);
    }

    // =========================================================================
    // validateFileAgainstConfig
    // =========================================================================

    public function testValidateFileAgainstConfigPassesForValidFile(): void
    {
        $fileData = [
            'content' => 'test',
            'mimeType' => 'image/png',
            'extension' => 'png',
            'size' => 100,
        ];

        $fileConfig = [
            'type' => 'file',
            'allowedTypes' => ['image/png', 'image/jpeg'],
            'maxSize' => 1000,
        ];

        // Should not throw.
        $this->handler->validateFileAgainstConfig($fileData, $fileConfig, 'avatar');
        $this->assertTrue(true);
    }

    public function testValidateFileAgainstConfigRejectsInvalidMimeType(): void
    {
        $fileData = [
            'content' => 'test',
            'mimeType' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
        ];

        $fileConfig = [
            'type' => 'file',
            'allowedTypes' => ['image/png', 'image/jpeg'],
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid type');

        $this->handler->validateFileAgainstConfig($fileData, $fileConfig, 'avatar');
    }

    public function testValidateFileAgainstConfigRejectsOversizedFile(): void
    {
        $fileData = [
            'content' => str_repeat('x', 2000),
            'mimeType' => 'image/png',
            'extension' => 'png',
            'size' => 2000,
        ];

        $fileConfig = [
            'type' => 'file',
            'maxSize' => 1000,
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('exceeds maximum size');

        $this->handler->validateFileAgainstConfig($fileData, $fileConfig, 'avatar');
    }

    public function testValidateFileAgainstConfigWithArrayIndex(): void
    {
        $fileData = [
            'content' => 'test',
            'mimeType' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
        ];

        $fileConfig = [
            'type' => 'file',
            'allowedTypes' => ['image/png'],
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('avatar[2]');

        $this->handler->validateFileAgainstConfig($fileData, $fileConfig, 'avatar', 2);
    }

    public function testValidateFileAgainstConfigPassesWithNoRestrictions(): void
    {
        $fileData = [
            'content' => 'anything',
            'mimeType' => 'application/octet-stream',
            'extension' => 'bin',
            'size' => 999999,
        ];

        $fileConfig = ['type' => 'file'];

        // Should not throw - no restrictions.
        $this->handler->validateFileAgainstConfig($fileData, $fileConfig, 'upload');
        $this->assertTrue(true);
    }

    // =========================================================================
    // blockExecutableFiles
    // =========================================================================

    public function testBlockExecutableFilesBlocksExeExtension(): void
    {
        $fileData = [
            'filename' => 'virus.exe',
            'mimeType' => 'application/octet-stream',
            'content' => '',
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('executable file');

        $this->handler->blockExecutableFiles($fileData, 'File at document');
    }

    public function testBlockExecutableFilesBlocksBatExtension(): void
    {
        $fileData = [
            'filename' => 'script.bat',
            'mimeType' => 'application/octet-stream',
            'content' => '',
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('executable file');

        $this->handler->blockExecutableFiles($fileData, 'File at script');
    }

    public function testBlockExecutableFilesBlocksExecutableMimeType(): void
    {
        $fileData = [
            'mimeType' => 'application/x-executable',
            'content' => '',
        ];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('executable MIME type');

        $this->handler->blockExecutableFiles($fileData, 'File at upload');
    }

    public function testBlockExecutableFilesAllowsSafeFiles(): void
    {
        $fileData = [
            'filename' => 'report.pdf',
            'mimeType' => 'application/pdf',
            'content' => '%PDF-1.4',
        ];

        // Should not throw.
        $this->handler->blockExecutableFiles($fileData, 'File at document');
        $this->assertTrue(true);
    }

    public function testBlockExecutableFilesAllowsImages(): void
    {
        $fileData = [
            'filename' => 'photo.jpg',
            'mimeType' => 'image/jpeg',
            'content' => '',
        ];

        // Should not throw.
        $this->handler->blockExecutableFiles($fileData, 'File at image');
        $this->assertTrue(true);
    }
}
