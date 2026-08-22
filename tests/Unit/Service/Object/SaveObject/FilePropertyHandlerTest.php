<?php

declare(strict_types=1);

/**
 * FilePropertyHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   OpenRegister Team
 * @license  EUPL-1.2
 * @link     https://github.com/OpenRegister/OpenRegister
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for FilePropertyHandler
 *
 * Tests file upload processing, file detection, validation, and security checks.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class FilePropertyHandlerTest extends TestCase {
	/** @var FilePropertyHandler */
	private FilePropertyHandler $handler;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var FileService&MockObject */
	private FileService $fileService;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->fileService = $this->createMock(FileService::class);

		$this->handler = new FilePropertyHandler(
			$this->logger,
			$this->fileService
		);
	}

	/**
	 * Helper to invoke private/protected methods via reflection.
	 *
	 * @param string $methodName Method name.
	 * @param array $args Arguments to pass.
	 *
	 * @return mixed
	 */
	private function invokePrivateMethod(string $methodName, array $args = []) {
		$method = new ReflectionMethod(FilePropertyHandler::class, $methodName);
		$method->setAccessible(true);
		return $method->invokeArgs($this->handler, $args);
	}

	/**
	 * Helper to create an ObjectEntity with a specific ID and UUID via reflection.
	 *
	 * @param int $id The entity ID.
	 * @param string $uuid The UUID.
	 *
	 * @return ObjectEntity
	 */
	private function createObjectEntity(int $id = 1, string $uuid = 'test-uuid-1234'): ObjectEntity {
		$entity = new ObjectEntity();

		$ref = new ReflectionClass($entity);
		$idProp = $ref->getProperty('id');
		$idProp->setAccessible(true);
		$idProp->setValue($entity, $id);

		$entity->setUuid($uuid);

		return $entity;
	}

	// =========================================================================
	// processUploadedFiles
	// =========================================================================

	public function testProcessUploadedFilesWithValidFile(): void {
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

	public function testProcessUploadedFilesSkipsUploadErrors(): void {
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

	public function testProcessUploadedFilesHandlesArrayFieldNames(): void {
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

	public function testProcessUploadedFilesThrowsOnReadFailure(): void {
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

	public function testProcessUploadedFilesWithEmptyBracketArrayField(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_');
		file_put_contents($tmpFile, 'data');

		$uploadedFiles = [
			'docs[]' => [
				'name' => 'file.pdf',
				'type' => 'application/pdf',
				'tmp_name' => $tmpFile,
				'error' => UPLOAD_ERR_OK,
				'size' => 4,
			],
		];

		$result = $this->handler->processUploadedFiles($uploadedFiles, []);

		$this->assertArrayHasKey('docs', $result);
		$this->assertIsArray($result['docs']);

		unlink($tmpFile);
	}

	public function testProcessUploadedFilesAppendsToExistingArrayField(): void {
		$tmpFile1 = tempnam(sys_get_temp_dir(), 'test_');
		file_put_contents($tmpFile1, 'data1');
		$tmpFile2 = tempnam(sys_get_temp_dir(), 'test_');
		file_put_contents($tmpFile2, 'data2');

		$uploadedFiles = [
			'images[0]' => [
				'name' => 'a.jpg',
				'type' => 'image/jpeg',
				'tmp_name' => $tmpFile1,
				'error' => UPLOAD_ERR_OK,
				'size' => 5,
			],
			'images[1]' => [
				'name' => 'b.jpg',
				'type' => 'image/jpeg',
				'tmp_name' => $tmpFile2,
				'error' => UPLOAD_ERR_OK,
				'size' => 5,
			],
		];

		$result = $this->handler->processUploadedFiles($uploadedFiles, []);

		$this->assertArrayHasKey('images', $result);
		$this->assertCount(2, $result['images']);

		unlink($tmpFile1);
		unlink($tmpFile2);
	}

	public function testProcessUploadedFilesWithMissingType(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_');
		file_put_contents($tmpFile, 'binary content');

		$uploadedFiles = [
			'upload' => [
				'name' => 'file.bin',
				'tmp_name' => $tmpFile,
				'error' => UPLOAD_ERR_OK,
				'size' => 14,
			],
		];

		$result = $this->handler->processUploadedFiles($uploadedFiles, []);

		// Should fallback to application/octet-stream.
		$this->assertArrayHasKey('upload', $result);
		$this->assertStringStartsWith('data:application/octet-stream;base64,', $result['upload']);

		unlink($tmpFile);
	}

	public function testProcessUploadedFilesOverridesExistingDataField(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_');
		file_put_contents($tmpFile, 'new content');

		$uploadedFiles = [
			'avatar' => [
				'name' => 'pic.png',
				'type' => 'image/png',
				'tmp_name' => $tmpFile,
				'error' => UPLOAD_ERR_OK,
				'size' => 11,
			],
		];

		$data = ['avatar' => 'old-value', 'name' => 'Test'];

		$result = $this->handler->processUploadedFiles($uploadedFiles, $data);

		// Uploaded file should override the existing value.
		$this->assertStringStartsWith('data:image/png;base64,', $result['avatar']);
		$this->assertSame('Test', $result['name']);

		unlink($tmpFile);
	}

	// =========================================================================
	// isFileProperty
	// =========================================================================

	public function testIsFilePropertyWithSchemaFileType(): void {
		$schema = new Schema();
		$schema->setProperties([
			'document' => ['type' => 'file'],
		]);

		$result = $this->handler->isFileProperty('some-value', $schema, 'document');

		$this->assertTrue($result);
	}

	public function testIsFilePropertyWithSchemaArrayFileType(): void {
		$schema = new Schema();
		$schema->setProperties([
			'images' => ['type' => 'array', 'items' => ['type' => 'file']],
		]);

		$result = $this->handler->isFileProperty(['val'], $schema, 'images');

		$this->assertTrue($result);
	}

	public function testIsFilePropertyWithSchemaStringType(): void {
		$schema = new Schema();
		$schema->setProperties([
			'name' => ['type' => 'string'],
		]);

		$result = $this->handler->isFileProperty('John', $schema, 'name');

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithSchemaPropertyNotFound(): void {
		$schema = new Schema();
		$schema->setProperties([
			'name' => ['type' => 'string'],
		]);

		$result = $this->handler->isFileProperty('value', $schema, 'nonexistent');

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithDataUri(): void {
		$dataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA';

		$result = $this->handler->isFileProperty($dataUri);

		$this->assertTrue($result);
	}

	public function testIsFilePropertyWithUrlAndFileExtension(): void {
		$url = 'https://example.com/files/report.pdf';

		$result = $this->handler->isFileProperty($url);

		$this->assertTrue($result);
	}

	public function testIsFilePropertyWithRegularWebUrl(): void {
		$url = 'https://example.com/about-us';

		$result = $this->handler->isFileProperty($url);

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithBase64String(): void {
		// Create a base64 string long enough (>100 chars).
		$content = str_repeat('A', 200);
		$base64 = base64_encode($content);

		$result = $this->handler->isFileProperty($base64);

		$this->assertTrue($result);
	}

	public function testIsFilePropertyWithShortString(): void {
		$result = $this->handler->isFileProperty('hello');

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithArrayOfDataUris(): void {
		$value = [
			'data:image/png;base64,iVBORw0',
		];

		$result = $this->handler->isFileProperty($value);

		$this->assertTrue($result);
	}

	public function testIsFilePropertyWithNonFileArray(): void {
		$value = ['tag1', 'tag2', 'tag3'];

		$result = $this->handler->isFileProperty($value);

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithFileObjectValue(): void {
		$value = [
			'id' => '42',
			'title' => 'doc.pdf',
			'path' => '/files/doc.pdf',
			'type' => 'application/pdf',
			'size' => 1024,
		];

		$result = $this->handler->isFileProperty($value);

		$this->assertTrue($result);
	}

	public function testIsFilePropertyWithArrayOfFileObjects(): void {
		$value = [
			['id' => '1', 'title' => 'a.pdf', 'type' => 'application/pdf', 'size' => 100],
		];

		$result = $this->handler->isFileProperty($value);

		$this->assertTrue($result);
	}

	public function testIsFilePropertyWithArrayOfUrls(): void {
		$value = [
			'https://example.com/files/document.pdf',
		];

		$result = $this->handler->isFileProperty($value);

		$this->assertTrue($result);
	}

	public function testIsFilePropertyWithArrayOfBase64(): void {
		$content = str_repeat('B', 200);
		$value = [base64_encode($content)];

		$result = $this->handler->isFileProperty($value);

		$this->assertTrue($result);
	}

	public function testIsFilePropertyWithSchemaArrayNonFileItems(): void {
		$schema = new Schema();
		$schema->setProperties([
			'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
		]);

		$result = $this->handler->isFileProperty(['tag1'], $schema, 'tags');

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithNullValue(): void {
		$result = $this->handler->isFileProperty(null);

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithIntegerValue(): void {
		$result = $this->handler->isFileProperty(42);

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithUrlNoPath(): void {
		$result = $this->handler->isFileProperty('https://example.com');

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithUrlPathNoExtension(): void {
		$result = $this->handler->isFileProperty('https://example.com/page');

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithArrayOfNonFileUrls(): void {
		$value = [
			'https://example.com/about',
			'https://example.com/contact',
		];

		$result = $this->handler->isFileProperty($value);

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithShortBase64String(): void {
		// Short string that is valid base64 but under 100 chars.
		$result = $this->handler->isFileProperty(base64_encode('short'));

		$this->assertFalse($result);
	}

	public function testIsFilePropertyWithSchemaNoType(): void {
		$schema = new Schema();
		$schema->setProperties([
			'something' => ['description' => 'no type specified'],
		]);

		$result = $this->handler->isFileProperty('value', $schema, 'something');

		$this->assertFalse($result);
	}

	// =========================================================================
	// isFileObject
	// =========================================================================

	public function testIsFileObjectWithValidFileObject(): void {
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

	public function testIsFileObjectWithoutId(): void {
		$value = [
			'title' => 'document.pdf',
			'path' => '/files/document.pdf',
		];

		$result = $this->handler->isFileObject($value);

		$this->assertFalse($result);
	}

	public function testIsFileObjectWithoutTitleOrPath(): void {
		$value = [
			'id' => '123',
			'name' => 'not a file property',
		];

		$result = $this->handler->isFileObject($value);

		$this->assertFalse($result);
	}

	public function testIsFileObjectWithMinimalFileObject(): void {
		$value = [
			'id' => '456',
			'title' => 'photo.jpg',
		];

		$result = $this->handler->isFileObject($value);

		$this->assertTrue($result);
	}

	public function testIsFileObjectWithIdAndPath(): void {
		$value = [
			'id' => '789',
			'path' => '/files/report.pdf',
		];

		$result = $this->handler->isFileObject($value);

		$this->assertTrue($result);
	}

	public function testIsFileObjectWithAccessUrl(): void {
		$value = [
			'id' => '10',
			'title' => 'doc.txt',
			'accessUrl' => 'https://example.com/file/10',
		];

		$result = $this->handler->isFileObject($value);

		$this->assertTrue($result);
	}

	public function testIsFileObjectWithDownloadUrl(): void {
		$value = [
			'id' => '11',
			'title' => 'archive.zip',
			'downloadUrl' => 'https://example.com/download/11',
		];

		$result = $this->handler->isFileObject($value);

		$this->assertTrue($result);
	}

	public function testIsFileObjectEmptyArray(): void {
		$result = $this->handler->isFileObject([]);

		$this->assertFalse($result);
	}

	// =========================================================================
	// parseFileData
	// =========================================================================

	public function testParseFileDataWithDataUri(): void {
		$content = 'Hello World';
		$base64 = base64_encode($content);
		$dataUri = "data:text/plain;base64,{$base64}";

		$result = $this->handler->parseFileData($dataUri);

		$this->assertSame($content, $result['content']);
		$this->assertSame('text/plain', $result['mimeType']);
		$this->assertSame(strlen($content), $result['size']);
		$this->assertArrayHasKey('extension', $result);
	}

	public function testParseFileDataWithPlainBase64(): void {
		$content = 'Some binary content';
		$base64 = base64_encode($content);

		$result = $this->handler->parseFileData($base64);

		$this->assertSame($content, $result['content']);
		$this->assertSame(strlen($content), $result['size']);
	}

	public function testParseFileDataThrowsOnInvalidDataUri(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Invalid data URI format');

		$this->handler->parseFileData('data:invalid-format');
	}

	public function testParseFileDataThrowsOnInvalidBase64(): void {
		$this->expectException(Exception::class);

		// Not valid base64.
		$this->handler->parseFileData('!!!not-base64!!!');
	}

	public function testParseFileDataWithImageDataUri(): void {
		// Create a small valid PNG-like base64.
		$pngData = 'fake-png-content-for-test';
		$base64 = base64_encode($pngData);
		$dataUri = "data:image/png;base64,{$base64}";

		$result = $this->handler->parseFileData($dataUri);

		$this->assertSame('image/png', $result['mimeType']);
		$this->assertSame($pngData, $result['content']);
	}

	public function testParseFileDataWithApplicationPdfDataUri(): void {
		$pdfData = '%PDF-1.4 fake content';
		$base64 = base64_encode($pdfData);
		$dataUri = "data:application/pdf;base64,{$base64}";

		$result = $this->handler->parseFileData($dataUri);

		$this->assertSame('application/pdf', $result['mimeType']);
		$this->assertSame('pdf', $result['extension']);
		$this->assertSame($pdfData, $result['content']);
		$this->assertSame(strlen($pdfData), $result['size']);
	}

	public function testParseFileDataWithInvalidBase64InDataUri(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Invalid base64 content in data URI');

		// Invalid base64 payload after data URI prefix.
		$this->handler->parseFileData('data:text/plain;base64,!!!invalid!!!');
	}

	public function testParseFileDataPlainBase64ReturnsExtension(): void {
		$content = 'plain text content';
		$base64 = base64_encode($content);

		$result = $this->handler->parseFileData($base64);

		// Should have an extension key (detected from content or fallback).
		$this->assertArrayHasKey('extension', $result);
		$this->assertIsString($result['extension']);
	}

	// =========================================================================
	// validateFileAgainstConfig
	// =========================================================================

	public function testValidateFileAgainstConfigPassesForValidFile(): void {
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

	public function testValidateFileAgainstConfigRejectsInvalidMimeType(): void {
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

	public function testValidateFileAgainstConfigRejectsOversizedFile(): void {
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

	public function testValidateFileAgainstConfigWithArrayIndex(): void {
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

	public function testValidateFileAgainstConfigPassesWithNoRestrictions(): void {
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

	public function testValidateFileAgainstConfigCallsBlockExecutableFiles(): void {
		$fileData = [
			'content' => 'MZ' . str_repeat("\x00", 100),
			'mimeType' => 'application/octet-stream',
			'extension' => 'bin',
			'size' => 102,
		];

		$fileConfig = ['type' => 'file'];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable code');

		$this->handler->validateFileAgainstConfig($fileData, $fileConfig, 'upload');
	}

	public function testValidateFileAgainstConfigAllowExecutablesFlag(): void {
		$fileData = [
			'content' => 'MZ' . str_repeat("\x00", 100),
			'mimeType' => 'application/octet-stream',
			'extension' => 'bin',
			'size' => 102,
		];

		$fileConfig = [
			'type' => 'file',
			'allowExecutables' => true,
		];

		// Should not throw because allowExecutables is true.
		$this->handler->validateFileAgainstConfig($fileData, $fileConfig, 'upload');
		$this->assertTrue(true);
	}

	public function testValidateFileAgainstConfigWithEmptyAllowedTypes(): void {
		$fileData = [
			'content' => 'test',
			'mimeType' => 'text/plain',
			'extension' => 'txt',
			'size' => 4,
		];

		$fileConfig = [
			'type' => 'file',
			'allowedTypes' => [],
		];

		// Empty allowedTypes means no restriction.
		$this->handler->validateFileAgainstConfig($fileData, $fileConfig, 'doc');
		$this->assertTrue(true);
	}

	public function testValidateFileAgainstConfigMaxSizeZero(): void {
		$fileData = [
			'content' => str_repeat('x', 5000),
			'mimeType' => 'text/plain',
			'extension' => 'txt',
			'size' => 5000,
		];

		$fileConfig = [
			'type' => 'file',
			'maxSize' => 0,
		];

		// maxSize of 0 means no restriction.
		$this->handler->validateFileAgainstConfig($fileData, $fileConfig, 'doc');
		$this->assertTrue(true);
	}

	// =========================================================================
	// Binary MIME scoping on the OBJECT-SAVE path — openregister#2776
	//
	// This handler carried a byte-identical COPY of the executable-content
	// checks, so the same legitimate PNG that the `/files` endpoints rejected
	// was also rejected here. Both call sites now share
	// ExecutableContentDetector; these tests pin BOTH directions on THIS path
	// so the two can never drift apart again.
	// =========================================================================

	/**
	 * The reproducer from the Jira attachment migration: a real 1283x926 PNG
	 * screenshot whose first 1024 bytes contain `<?=` at offset 710.
	 *
	 * @return string
	 */
	private function falsePositivePngPath(): string {
		return __DIR__ . '/../../../../fixtures/files/php-tag-false-positive.png';
	}//end falsePositivePngPath()

	public function testFilePropertyPathAcceptsTheRealPngReproducer(): void {
		$content = (string)file_get_contents($this->falsePositivePngPath());

		// Guard the guard: the fixture must still trip the OLD universal rule,
		// otherwise this test would pass vacuously.
		$this->assertStringContainsString('<?=', substr($content, 0, 1024));

		$fileData = [
			'filename' => 'Gegevens weergeven.PNG',
			'mimeType' => 'image/png',
			'content' => $content,
		];

		$this->handler->blockExecutableFiles($fileData, 'File at attachment');

		$this->assertTrue(true, 'a genuine PNG must not be rejected as PHP on the object-save path');
	}//end testFilePropertyPathAcceptsTheRealPngReproducer()

	/**
	 * @dataProvider filePropertyBinaryContainerProvider
	 */
	public function testFilePropertyPathSkipsPhpScanForBinaryContainers(string $magic, string $fileName, string $mime): void {
		$fileData = [
			'filename' => $fileName,
			'mimeType' => $mime,
			'content' => $magic . str_repeat("\x00", 32) . "<?php system('whoami'); ?>",
		];

		$this->handler->blockExecutableFiles($fileData, 'File at attachment');

		$this->assertTrue(true, "{$fileName} must not be scanned for PHP tags");
	}//end testFilePropertyPathSkipsPhpScanForBinaryContainers()

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function filePropertyBinaryContainerProvider(): array {
		return [
			'png' => ["\x89PNG\r\n\x1A\n", 'screenshot.png', 'image/png'],
			'jpeg' => ["\xFF\xD8\xFF\xE0", 'photo.jpg', 'image/jpeg'],
			'pdf' => ['%PDF-1.7', 'report.pdf', 'application/pdf'],
			'docx' => ["PK\x03\x04", 'contract.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
		];
	}//end filePropertyBinaryContainerProvider()

	// --- MUST-FAIL side: the protection survives on this path too. ---

	/**
	 * @dataProvider filePropertyStillRejectedProvider
	 */
	public function testFilePropertyPathStillRejectsPhpPayloads(string $content, string $fileName, string $mime): void {
		$fileData = [
			'filename' => $fileName,
			'mimeType' => $mime,
			'content' => $content,
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('PHP code');

		$this->handler->blockExecutableFiles($fileData, 'File at attachment');
	}//end testFilePropertyPathStillRejectsPhpPayloads()

	/**
	 * Note: a `.php`/`.phtml` name is refused by the extension blocklist BEFORE
	 * content is read, with a different message, so those cases are covered by
	 * the existing extension tests rather than here. These all reach the
	 * content scan and must still be refused by it.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function filePropertyStillRejectedProvider(): array {
		$php = "<?php system(\$_GET['c']); ?>";

		return [
			'php source as .txt' => ["notes\n$php", 'notes.txt', 'text/plain'],
			'php in html' => ["<html><body>$php</body></html>", 'page.html', 'text/html'],
			'php short echo in xml' => ["<root><?= 'x' ?></root>", 'feed.xml', 'application/xml'],
			'php inside svg' => ["<svg xmlns='http://www.w3.org/2000/svg'>$php</svg>", 'logo.svg', 'image/svg+xml'],
			'script language=php' => ["<html><script language=\"php\">echo 1;</script>", 'page.html', 'text/html'],
			'no extension at all' => ["notes\n$php", 'attachment', 'application/octet-stream'],
			'unrecognised byte stream' => ["\x01\x02\x03\x04$php", 'blob.dat', 'application/octet-stream'],
			// Polyglots: real PNG magic bytes under an interpreted/markup name.
			'png magic saved as .html' => ["\x89PNG\r\n\x1A\n$php", 'polyglot.html', 'text/html'],
			'png magic saved as .svg' => ["\x89PNG\r\n\x1A\n$php", 'polyglot.svg', 'image/svg+xml'],
		];
	}//end filePropertyStillRejectedProvider()

	public function testFilePropertyPathStillRejectsExecutableBytesUnderAnImageName(): void {
		// The MIME scoping narrows ONLY the embedded-PHP-tag scan. The offset-0
		// signature table is untouched and still universal on this path.
		$fileData = [
			'filename' => 'innocent.png',
			'mimeType' => 'image/png',
			'content' => 'MZ' . str_repeat("\x00", 64),
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable code');

		$this->handler->blockExecutableFiles($fileData, 'File at attachment');
	}//end testFilePropertyPathStillRejectsExecutableBytesUnderAnImageName()

	public function testFilePropertyPathStillRejectsShebangUnderAnImageName(): void {
		$fileData = [
			'filename' => 'data.png',
			'mimeType' => 'image/png',
			'content' => "#!/usr/bin/env python\nprint(1)\n",
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('shebang');

		$this->handler->blockExecutableFiles($fileData, 'File at attachment');
	}//end testFilePropertyPathStillRejectsShebangUnderAnImageName()

	public function testFilePropertyPathStillRejectsPhpSourceUnderAPhpName(): void {
		// The dangerous-extension blocklist is the primary control and runs
		// before content is even read — a .php upload is refused whatever its
		// bytes look like, including a PNG-magic polyglot.
		$fileData = [
			'filename' => 'polyglot.phtml',
			'mimeType' => 'image/png',
			'content' => "\x89PNG\r\n\x1A\n<?php echo 1;",
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable file');

		$this->handler->blockExecutableFiles($fileData, 'File at attachment');
	}//end testFilePropertyPathStillRejectsPhpSourceUnderAPhpName()

	// =========================================================================
	// blockExecutableFiles
	// =========================================================================

	public function testBlockExecutableFilesBlocksExeExtension(): void {
		$fileData = [
			'filename' => 'virus.exe',
			'mimeType' => 'application/octet-stream',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable file');

		$this->handler->blockExecutableFiles($fileData, 'File at document');
	}

	public function testBlockExecutableFilesBlocksBatExtension(): void {
		$fileData = [
			'filename' => 'script.bat',
			'mimeType' => 'application/octet-stream',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable file');

		$this->handler->blockExecutableFiles($fileData, 'File at script');
	}

	public function testBlockExecutableFilesBlocksExecutableMimeType(): void {
		$fileData = [
			'mimeType' => 'application/x-executable',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable MIME type');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesAllowsSafeFiles(): void {
		$fileData = [
			'filename' => 'report.pdf',
			'mimeType' => 'application/pdf',
			'content' => '%PDF-1.4',
		];

		// Should not throw.
		$this->handler->blockExecutableFiles($fileData, 'File at document');
		$this->assertTrue(true);
	}

	public function testBlockExecutableFilesAllowsImages(): void {
		$fileData = [
			'filename' => 'photo.jpg',
			'mimeType' => 'image/jpeg',
			'content' => '',
		];

		// Should not throw.
		$this->handler->blockExecutableFiles($fileData, 'File at image');
		$this->assertTrue(true);
	}

	public function testBlockExecutableFilesBlocksPhpExtension(): void {
		$fileData = [
			'filename' => 'shell.php',
			'mimeType' => 'text/plain',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable file');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksShExtension(): void {
		$fileData = [
			'filename' => 'install.sh',
			'mimeType' => 'text/plain',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable file');

		$this->handler->blockExecutableFiles($fileData, 'File at script');
	}

	public function testBlockExecutableFilesBlocksPyExtension(): void {
		$fileData = [
			'filename' => 'hack.py',
			'mimeType' => 'text/plain',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable file');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksMsiExtension(): void {
		$fileData = [
			'filename' => 'installer.msi',
			'mimeType' => 'application/octet-stream',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable file');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksDllExtension(): void {
		$fileData = [
			'filename' => 'library.dll',
			'mimeType' => 'application/octet-stream',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable file');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksWindowsExeMagicBytes(): void {
		$fileData = [
			'mimeType' => 'application/octet-stream',
			'content' => 'MZ' . str_repeat("\x00", 100),
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable code');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksElfMagicBytes(): void {
		$fileData = [
			'mimeType' => 'application/octet-stream',
			'content' => "\x7FELF" . str_repeat("\x00", 100),
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable code');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksShellScript(): void {
		$fileData = [
			'mimeType' => 'text/plain',
			'content' => "#!/bin/sh\necho hello",
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable code');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksBashScript(): void {
		$fileData = [
			'mimeType' => 'text/plain',
			'content' => "#!/bin/bash\necho hello",
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable code');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksEnvShebang(): void {
		$fileData = [
			'mimeType' => 'text/plain',
			'content' => "#!/usr/bin/env python3\nprint('hi')",
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable code');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksPhpTag(): void {
		$fileData = [
			'filename' => 'legit.txt',
			'mimeType' => 'text/plain',
			'content' => "<?php echo 'hacked'; ?>",
		];

		$this->expectException(Exception::class);
		// Could match either magic bytes or PHP tag detection.
		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksPhpShortTag(): void {
		$fileData = [
			'filename' => 'legit.txt',
			'mimeType' => 'text/plain',
			'content' => "Some text\n<?= 'injected' ?>",
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('PHP code');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksJavaClassMagicBytes(): void {
		$fileData = [
			'mimeType' => 'application/octet-stream',
			'content' => "\xCA\xFE\xBA\xBE" . str_repeat("\x00", 100),
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable code');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksShellscriptMimeType(): void {
		$fileData = [
			'mimeType' => 'application/x-shellscript',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable MIME type');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksDosexecMimeType(): void {
		$fileData = [
			'mimeType' => 'application/x-dosexec',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable MIME type');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksPhpMimeType(): void {
		$fileData = [
			'mimeType' => 'application/x-php',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable MIME type');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksPythonMimeType(): void {
		$fileData = [
			'mimeType' => 'application/x-python-code',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable MIME type');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksJarMimeType(): void {
		$fileData = [
			'mimeType' => 'application/java-archive',
			'content' => '',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('executable MIME type');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksShebangInContent(): void {
		// Shebang with perl - detected by the regex check.
		$fileData = [
			'filename' => 'data.txt',
			'mimeType' => 'text/plain',
			'content' => "#!/usr/bin/perl\nuse strict;",
		];

		$this->expectException(Exception::class);

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesBlocksPhpScriptTag(): void {
		$fileData = [
			'filename' => 'page.html',
			'mimeType' => 'text/html',
			'content' => '<html><script language="php">echo "test";</script></html>',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('PHP code');

		$this->handler->blockExecutableFiles($fileData, 'File at upload');
	}

	public function testBlockExecutableFilesNoFilenameNoContent(): void {
		$fileData = [
			'mimeType' => 'text/plain',
			'content' => '',
		];

		// No filename, empty content, safe MIME => should pass.
		$this->handler->blockExecutableFiles($fileData, 'File at upload');
		$this->assertTrue(true);
	}

	public function testBlockExecutableFilesNullMimeType(): void {
		$fileData = [
			'content' => 'safe text content',
		];

		// No mimeType key at all => should pass.
		$this->handler->blockExecutableFiles($fileData, 'File at upload');
		$this->assertTrue(true);
	}

	// =========================================================================
	// handleFileProperty — file deletion
	// =========================================================================

	public function testHandleFilePropertyDeletionSingleFile(): void {
		$entity = $this->createObjectEntity(1, 'uuid-del-1');
		$entity->setObject(['document' => 42]);

		$schema = new Schema();
		$schema->setProperties([
			'document' => ['type' => 'file'],
		]);

		$this->fileService->expects($this->once())
			->method('deleteFile')
			->with(42, $entity);

		$object = ['document' => null];

		$this->handler->handleFileProperty($entity, $object, 'document', $schema);

		$this->assertNull($object['document']);
	}

	public function testHandleFilePropertyDeletionArrayFiles(): void {
		$entity = $this->createObjectEntity(1, 'uuid-del-2');
		$entity->setObject(['images' => [10, 20, 30]]);

		$schema = new Schema();
		$schema->setProperties([
			'images' => ['type' => 'array', 'items' => ['type' => 'file']],
		]);

		$this->fileService->expects($this->exactly(3))
			->method('deleteFile');

		$object = ['images' => []];

		$this->handler->handleFileProperty($entity, $object, 'images', $schema);

		$this->assertSame([], $object['images']);
	}

	public function testHandleFilePropertyDeletionWithDeleteException(): void {
		$entity = $this->createObjectEntity(1, 'uuid-del-3');
		$entity->setObject(['document' => 99]);

		$schema = new Schema();
		$schema->setProperties([
			'document' => ['type' => 'file'],
		]);

		$this->fileService->expects($this->once())
			->method('deleteFile')
			->willThrowException(new Exception('File not found'));

		$this->logger->expects($this->atLeastOnce())
			->method('warning');

		$object = ['document' => null];

		// Should not throw even though deleteFile fails.
		$this->handler->handleFileProperty($entity, $object, 'document', $schema);

		$this->assertNull($object['document']);
	}

	public function testHandleFilePropertyDeletionArrayWithDeleteException(): void {
		$entity = $this->createObjectEntity(1, 'uuid-del-4');
		$entity->setObject(['images' => [10, 20]]);

		$schema = new Schema();
		$schema->setProperties([
			'images' => ['type' => 'array', 'items' => ['type' => 'file']],
		]);

		$this->fileService->expects($this->exactly(2))
			->method('deleteFile')
			->willThrowException(new Exception('File not found'));

		$object = ['images' => []];

		// Should not throw.
		$this->handler->handleFileProperty($entity, $object, 'images', $schema);

		$this->assertSame([], $object['images']);
	}

	public function testHandleFilePropertyDeletionNoExistingFiles(): void {
		$entity = $this->createObjectEntity(1, 'uuid-del-5');
		$entity->setObject(['document' => null]);

		$schema = new Schema();
		$schema->setProperties([
			'document' => ['type' => 'file'],
		]);

		$this->fileService->expects($this->never())
			->method('deleteFile');

		$object = ['document' => null];

		$this->handler->handleFileProperty($entity, $object, 'document', $schema);

		$this->assertNull($object['document']);
	}

	public function testHandleFilePropertyDeletionEmptyStringExistingId(): void {
		$entity = $this->createObjectEntity(1, 'uuid-del-6');
		$entity->setObject(['document' => '']);

		$schema = new Schema();
		$schema->setProperties([
			'document' => ['type' => 'file'],
		]);

		$this->fileService->expects($this->never())
			->method('deleteFile');

		$object = ['document' => null];

		$this->handler->handleFileProperty($entity, $object, 'document', $schema);

		$this->assertNull($object['document']);
	}

	// =========================================================================
	// handleFileProperty — property not in schema
	// =========================================================================

	public function testHandleFilePropertyThrowsIfPropertyNotInSchema(): void {
		$entity = $this->createObjectEntity();

		$schema = new Schema();
		$schema->setProperties([
			'name' => ['type' => 'string'],
		]);

		$object = ['nonexistent' => 'value'];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage("Property 'nonexistent' not found in schema");

		$this->handler->handleFileProperty($entity, $object, 'nonexistent', $schema);
	}

	// =========================================================================
	// handleFileProperty — non-file property throws
	// =========================================================================

	public function testHandleFilePropertyThrowsIfNotFileProperty(): void {
		$entity = $this->createObjectEntity();

		$schema = new Schema();
		$schema->setProperties([
			'name' => ['type' => 'string'],
		]);

		$object = ['name' => 'test'];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage("Property 'name' is not configured as a file property");

		$this->handler->handleFileProperty($entity, $object, 'name', $schema);
	}

	public function testHandleFilePropertyArrayNotFileThrows(): void {
		$entity = $this->createObjectEntity();

		$schema = new Schema();
		$schema->setProperties([
			'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
		]);

		$object = ['tags' => ['a', 'b']];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('not configured as a file property');

		$this->handler->handleFileProperty($entity, $object, 'tags', $schema);
	}

	// =========================================================================
	// handleFileProperty — array receives non-array throws
	// =========================================================================

	public function testHandleFilePropertyArrayReceivesNonArrayThrows(): void {
		$entity = $this->createObjectEntity();
		$entity->setObject([]);

		$schema = new Schema();
		$schema->setProperties([
			'images' => ['type' => 'array', 'items' => ['type' => 'file']],
		]);

		$object = ['images' => 'not-an-array'];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('configured as array but received non-array');

		$this->handler->handleFileProperty($entity, $object, 'images', $schema);
	}

	// =========================================================================
	// handleFileProperty — single file upload with data URI
	// =========================================================================

	public function testHandleFilePropertySingleFileUpload(): void {
		$entity = $this->createObjectEntity(1, 'uuid-upload-1');
		$entity->setObject([]);

		$schema = new Schema();
		$schema->setProperties([
			'document' => ['type' => 'file'],
		]);

		$content = 'test file content';
		$base64 = base64_encode($content);
		$dataUri = "data:text/plain;base64,{$base64}";

		$fileMock = $this->createMock(File::class);
		$fileMock->method('getId')->willReturn(42);

		$this->fileService->expects($this->once())
			->method('addFile')
			->willReturn($fileMock);

		$object = ['document' => $dataUri];

		$this->handler->handleFileProperty($entity, $object, 'document', $schema);

		$this->assertSame(42, $object['document']);
	}

	public function testHandleFilePropertyArrayFileUpload(): void {
		$entity = $this->createObjectEntity(1, 'uuid-upload-2');
		$entity->setObject([]);

		$schema = new Schema();
		$schema->setProperties([
			'images' => ['type' => 'array', 'items' => ['type' => 'file']],
		]);

		$content1 = 'image data 1';
		$dataUri1 = 'data:image/png;base64,' . base64_encode($content1);
		$content2 = 'image data 2';
		$dataUri2 = 'data:image/jpeg;base64,' . base64_encode($content2);

		$fileMock1 = $this->createMock(File::class);
		$fileMock1->method('getId')->willReturn(100);
		$fileMock2 = $this->createMock(File::class);
		$fileMock2->method('getId')->willReturn(101);

		$this->fileService->expects($this->exactly(2))
			->method('addFile')
			->willReturnOnConsecutiveCalls($fileMock1, $fileMock2);

		$object = ['images' => [$dataUri1, $dataUri2]];

		$this->handler->handleFileProperty($entity, $object, 'images', $schema);

		$this->assertSame([100, 101], $object['images']);
	}

	// =========================================================================
	// handleFileProperty — autoShare from schema configuration (replaces legacy autoPublish)
	// =========================================================================

	public function testHandleFilePropertyAutoPublishFromSchemaConfig(): void {
		// Renamed: autoPublish was renamed to autoShare in 2026.
		// This test verifies that schema-level autoShare: true causes the file to be shared.
		// A Schema mock is used so getConfiguration() returns ['autoShare' => true] without
		// Schema::validateConfigurationArray() stripping the key (it only validates known keys).
		$entity = $this->createObjectEntity(1, 'uuid-autoshare-schema');
		$entity->setObject([]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn([
			'document' => ['type' => 'file'],
		]);
		$schema->method('getConfiguration')->willReturn(['autoShare' => true]);

		$content = 'some content';
		$dataUri = 'data:text/plain;base64,' . base64_encode($content);

		$fileMock = $this->createMock(File::class);
		$fileMock->method('getId')->willReturn(55);

		$this->fileService->expects($this->once())
			->method('addFile')
			->with(
				$entity,
				$this->matchesRegularExpression('/^document_\d+_[a-f0-9]+\.txt$/'),
				$content,
				true,
				$this->isType('array')
			)
			->willReturn($fileMock);

		$object = ['document' => $dataUri];

		$this->handler->handleFileProperty($entity, $object, 'document', $schema);

		$this->assertSame(55, $object['document']);
	}

	public function testHandleFilePropertyAutoPublishAtPropertyLevel(): void {
		// Renamed: autoPublish was renamed to autoShare in 2026.
		// This test verifies that property-level autoShare: true causes the file to be shared.
		$entity = $this->createObjectEntity(1, 'uuid-autoshare-prop');
		$entity->setObject([]);

		$schema = new Schema();
		$schema->setProperties([
			'document' => ['type' => 'file', 'autoShare' => true],
		]);

		$content = 'some content';
		$dataUri = 'data:text/plain;base64,' . base64_encode($content);

		$fileMock = $this->createMock(File::class);
		$fileMock->method('getId')->willReturn(56);

		$this->fileService->expects($this->once())
			->method('addFile')
			->with(
				$entity,
				$this->isType('string'),
				$content,
				true,
				$this->isType('array')
			)
			->willReturn($fileMock);

		$object = ['document' => $dataUri];

		$this->handler->handleFileProperty($entity, $object, 'document', $schema);

		$this->assertSame(56, $object['document']);
	}

	// =========================================================================
	// handleFileProperty — auto-tags
	// =========================================================================

	public function testHandleFilePropertyWithAutoTags(): void {
		$entity = $this->createObjectEntity(1, 'uuid-tags');
		$entity->setObject([]);

		$schema = new Schema();
		$schema->setProperties([
			'photo' => [
				'type' => 'file',
				'autoTags' => ['category:profile', 'public'],
			],
		]);

		$content = 'image data';
		$dataUri = 'data:image/png;base64,' . base64_encode($content);

		$fileMock = $this->createMock(File::class);
		$fileMock->method('getId')->willReturn(60);

		$this->fileService->expects($this->once())
			->method('addFile')
			->with(
				$entity,
				$this->isType('string'),
				$content,
				false,
				['property:photo', 'category:profile', 'public']
			)
			->willReturn($fileMock);

		$object = ['photo' => $dataUri];

		$this->handler->handleFileProperty($entity, $object, 'photo', $schema);

		$this->assertSame(60, $object['photo']);
	}

	// =========================================================================
	// handleFileProperty — single file that is not a file property value (integer ID)
	// =========================================================================

	public function testHandleFilePropertySingleValueNotFileProperty(): void {
		$entity = $this->createObjectEntity(1, 'uuid-nf');
		$entity->setObject([]);

		$schema = new Schema();
		$schema->setProperties([
			'document' => ['type' => 'file'],
		]);

		// An integer file ID is not detected as isFileProperty().
		$object = ['document' => 42];

		$this->fileService->expects($this->never())
			->method('addFile');

		$this->handler->handleFileProperty($entity, $object, 'document', $schema);

		// The value should remain unchanged since isFileProperty returns false for int.
		$this->assertSame(42, $object['document']);
	}

	// =========================================================================
	// handleFileProperty — array with non-file items
	// =========================================================================

	public function testHandleFilePropertyArrayWithNonFileItems(): void {
		$entity = $this->createObjectEntity(1, 'uuid-nf-arr');
		$entity->setObject([]);

		$schema = new Schema();
		$schema->setProperties([
			'images' => ['type' => 'array', 'items' => ['type' => 'file']],
		]);

		// Integer IDs are not detected as file properties.
		$object = ['images' => [10, 20]];

		$this->fileService->expects($this->never())
			->method('addFile');

		$this->handler->handleFileProperty($entity, $object, 'images', $schema);

		// Result should be an empty array since no items passed isFileProperty.
		$this->assertSame([], $object['images']);
	}

	// =========================================================================
	// processSingleFileProperty
	// =========================================================================

	public function testProcessSingleFilePropertyWithDataUri(): void {
		$entity = $this->createObjectEntity(1, 'uuid-psfp-1');

		$content = 'test data';
		$dataUri = 'data:text/plain;base64,' . base64_encode($content);

		$fileMock = $this->createMock(File::class);
		$fileMock->method('getId')->willReturn(77);

		$this->fileService->expects($this->once())
			->method('addFile')
			->willReturn($fileMock);

		$result = $this->handler->processSingleFileProperty(
			$entity,
			$dataUri,
			'attachment',
			['type' => 'file']
		);

		$this->assertSame(77, $result);
	}

	public function testProcessSingleFilePropertyWithBase64(): void {
		$entity = $this->createObjectEntity(1, 'uuid-psfp-2');

		$content = 'plain binary data';
		$base64 = base64_encode($content);

		$fileMock = $this->createMock(File::class);
		$fileMock->method('getId')->willReturn(88);

		$this->fileService->expects($this->once())
			->method('addFile')
			->willReturn($fileMock);

		$result = $this->handler->processSingleFileProperty(
			$entity,
			$base64,
			'binary',
			['type' => 'file']
		);

		$this->assertSame(88, $result);
	}

	public function testProcessSingleFilePropertyWithFileObjectExisting(): void {
		$entity = $this->createObjectEntity(1, 'uuid-psfp-3');

		$fileMock = $this->createMock(File::class);
		$fileMock->method('getId')->willReturn(42);

		$this->fileService->expects($this->once())
			->method('getFile')
			->with($entity, 42)
			->willReturn($fileMock);

		$fileObject = [
			'id' => 42,
			'title' => 'existing.pdf',
			'path' => '/files/existing.pdf',
		];

		$result = $this->handler->processSingleFileProperty(
			$entity,
			$fileObject,
			'document',
			['type' => 'file']
		);

		$this->assertSame(42, $result);
	}

	public function testProcessSingleFilePropertyWithUnsupportedType(): void {
		$entity = $this->createObjectEntity(1, 'uuid-psfp-4');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Unsupported file input type');

		$this->handler->processSingleFileProperty(
			$entity,
			12345,
			'document',
			['type' => 'file']
		);
	}

	public function testProcessSingleFilePropertyWithFileObjectNoExistingFile(): void {
		$entity = $this->createObjectEntity(1, 'uuid-psfp-5');

		$this->fileService->expects($this->once())
			->method('getFile')
			->willThrowException(new Exception('Not found'));

		$fileObject = [
			'id' => 999,
			'title' => 'gone.pdf',
			'path' => '/files/gone.pdf',
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('no downloadable URL');

		$this->handler->processSingleFileProperty(
			$entity,
			$fileObject,
			'document',
			['type' => 'file']
		);
	}

	public function testProcessSingleFilePropertyWithFileObjectNonNumericId(): void {
		$entity = $this->createObjectEntity(1, 'uuid-psfp-6');

		$fileObject = [
			'id' => 'abc-not-numeric',
			'title' => 'doc.pdf',
			'path' => '/files/doc.pdf',
		];

		// Non-numeric ID, no downloadUrl/accessUrl => throws.
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('no downloadable URL');

		$this->handler->processSingleFileProperty(
			$entity,
			$fileObject,
			'document',
			['type' => 'file']
		);
	}

	public function testProcessSingleFilePropertyWithFileObjectGetFileReturnsNull(): void {
		$entity = $this->createObjectEntity(1, 'uuid-psfp-7');

		$this->fileService->expects($this->once())
			->method('getFile')
			->with($entity, 50)
			->willReturn(null);

		$fileObject = [
			'id' => 50,
			'title' => 'maybe.pdf',
			'path' => '/files/maybe.pdf',
		];

		// File not found (getFile returns null), no downloadUrl => throws.
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('no downloadable URL');

		$this->handler->processSingleFileProperty(
			$entity,
			$fileObject,
			'document',
			['type' => 'file']
		);
	}

	public function testProcessSingleFilePropertyWithIndex(): void {
		$entity = $this->createObjectEntity(1, 'uuid-psfp-idx');

		$content = 'indexed file';
		$dataUri = 'data:text/plain;base64,' . base64_encode($content);

		$fileMock = $this->createMock(File::class);
		$fileMock->method('getId')->willReturn(99);

		$this->fileService->expects($this->once())
			->method('addFile')
			->with(
				$entity,
				// Filename should contain the index.
				$this->matchesRegularExpression('/^images_3_\d+_[a-f0-9]+\.txt$/'),
				$content,
				false,
				$this->isType('array')
			)
			->willReturn($fileMock);

		$result = $this->handler->processSingleFileProperty(
			$entity,
			$dataUri,
			'images',
			['type' => 'file'],
			3
		);

		$this->assertSame(99, $result);
	}

	// =========================================================================
	// Private method tests via reflection — generateFileName
	// =========================================================================

	public function testGenerateFileNameWithoutIndex(): void {
		$result = $this->invokePrivateMethod('generateFileName', ['avatar', 'png', null]);

		$this->assertMatchesRegularExpression('/^avatar_\d+_[a-f0-9]{8}\.png$/', $result);
	}

	public function testGenerateFileNameWithIndex(): void {
		$result = $this->invokePrivateMethod('generateFileName', ['images', 'jpg', 2]);

		$this->assertMatchesRegularExpression('/^images_2_\d+_[a-f0-9]{8}\.jpg$/', $result);
	}

	// =========================================================================
	// Private method tests via reflection — prepareAutoTags
	// =========================================================================

	public function testPrepareAutoTagsBasic(): void {
		$result = $this->invokePrivateMethod('prepareAutoTags', [
			['type' => 'file'],
			'document',
			null,
		]);

		$this->assertSame(['property:document'], $result);
	}

	public function testPrepareAutoTagsWithConfiguredTags(): void {
		$result = $this->invokePrivateMethod('prepareAutoTags', [
			['type' => 'file', 'autoTags' => ['public', 'category:photos']],
			'photo',
			null,
		]);

		$this->assertSame(['property:photo', 'public', 'category:photos'], $result);
	}

	public function testPrepareAutoTagsDeduplicates(): void {
		$result = $this->invokePrivateMethod('prepareAutoTags', [
			['type' => 'file', 'autoTags' => ['property:avatar', 'extra']],
			'avatar',
			null,
		]);

		// property:avatar appears as both the auto tag and the config tag — should deduplicate.
		$this->assertCount(2, $result);
		$this->assertContains('property:avatar', $result);
		$this->assertContains('extra', $result);
	}

	public function testPrepareAutoTagsNonArrayAutoTags(): void {
		$result = $this->invokePrivateMethod('prepareAutoTags', [
			['type' => 'file', 'autoTags' => 'not-an-array'],
			'field',
			null,
		]);

		// autoTags is not an array, should only have the property tag.
		$this->assertSame(['property:field'], $result);
	}

	// =========================================================================
	// Private method tests via reflection — getExtensionFromMimeType
	// =========================================================================

	public function testGetExtensionFromMimeTypeCommonTypes(): void {
		$cases = [
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			'image/svg+xml' => 'svg',
			'application/pdf' => 'pdf',
			'text/plain' => 'txt',
			'text/csv' => 'csv',
			'application/json' => 'json',
			'application/zip' => 'zip',
			'audio/mpeg' => 'mp3',
			'video/mp4' => 'mp4',
		];

		foreach ($cases as $mime => $expected) {
			$result = $this->invokePrivateMethod('getExtensionFromMimeType', [$mime]);
			$this->assertSame($expected, $result, "MIME type {$mime} should map to extension {$expected}");
		}
	}

	public function testGetExtensionFromMimeTypeUnknownTypeReturnsBin(): void {
		$result = $this->invokePrivateMethod('getExtensionFromMimeType', ['application/x-unknown-type']);

		$this->assertSame('bin', $result);
	}

	public function testGetExtensionFromMimeTypeOfficeDocuments(): void {
		$cases = [
			'application/msword' => 'doc',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
			'application/vnd.ms-excel' => 'xls',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
		];

		foreach ($cases as $mime => $expected) {
			$result = $this->invokePrivateMethod('getExtensionFromMimeType', [$mime]);
			$this->assertSame($expected, $result);
		}
	}

	// =========================================================================
	// Private method tests via reflection — parseFileDataFromUrl
	// =========================================================================

	public function testParseFileDataFromUrlWithExtension(): void {
		$url = 'https://example.com/path/to/file.pdf';
		$content = 'fake pdf content';

		$result = $this->invokePrivateMethod('parseFileDataFromUrl', [$url, $content]);

		$this->assertSame($content, $result['content']);
		$this->assertSame('pdf', $result['extension']);
		$this->assertSame(strlen($content), $result['size']);
		$this->assertArrayHasKey('mimeType', $result);
	}

	public function testParseFileDataFromUrlWithoutExtension(): void {
		$url = 'https://example.com/download';
		$content = 'some binary data';

		$result = $this->invokePrivateMethod('parseFileDataFromUrl', [$url, $content]);

		$this->assertSame($content, $result['content']);
		$this->assertSame(strlen($content), $result['size']);
		// Extension should be derived from MIME type detection.
		$this->assertArrayHasKey('extension', $result);
	}

	// =========================================================================
	// Private method tests via reflection — getCommonFileExtensions
	// =========================================================================

	public function testGetCommonFileExtensionsContainsExpectedTypes(): void {
		$result = $this->invokePrivateMethod('getCommonFileExtensions', []);

		$this->assertContains('pdf', $result);
		$this->assertContains('jpg', $result);
		$this->assertContains('png', $result);
		$this->assertContains('mp4', $result);
		$this->assertContains('zip', $result);
		$this->assertContains('csv', $result);
		$this->assertContains('doc', $result);
		$this->assertContains('exe', $result);
	}

	// =========================================================================
	// Private method tests via reflection — getDangerousExecutableExtensions
	// =========================================================================

	public function testGetDangerousExecutableExtensionsContainsExpected(): void {
		$result = $this->invokePrivateMethod('getDangerousExecutableExtensions', []);

		$this->assertContains('exe', $result);
		$this->assertContains('bat', $result);
		$this->assertContains('sh', $result);
		$this->assertContains('php', $result);
		$this->assertContains('py', $result);
		$this->assertContains('jar', $result);
		$this->assertContains('dll', $result);
		$this->assertContains('apk', $result);
	}

	// =========================================================================
	// Private method tests via reflection — getExecutableMimeTypes
	// =========================================================================

	public function testGetExecutableMimeTypesContainsExpected(): void {
		$result = $this->invokePrivateMethod('getExecutableMimeTypes', []);

		$this->assertContains('application/x-executable', $result);
		$this->assertContains('application/x-sh', $result);
		$this->assertContains('application/x-php', $result);
		$this->assertContains('application/java-archive', $result);
		$this->assertContains('text/x-php', $result);
	}

	// =========================================================================
	// Private method tests via reflection — detectExecutableMagicBytes
	// =========================================================================

	public function testDetectExecutableMagicBytesSafeContent(): void {
		// Regular text content should not trigger.
		$this->invokePrivateMethod('detectExecutableMagicBytes', [
			'This is perfectly safe text content.',
			'File at test',
		]);

		$this->assertTrue(true);
	}

	public function testDetectExecutableMagicBytesWindowsExe(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Windows executable');

		$this->invokePrivateMethod('detectExecutableMagicBytes', [
			'MZ' . str_repeat("\x00", 100),
			'File at test',
		]);
	}

	public function testDetectExecutableMagicBytesElf(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Linux/Unix executable');

		$this->invokePrivateMethod('detectExecutableMagicBytes', [
			"\x7FELF" . str_repeat("\x00", 100),
			'File at test',
		]);
	}

	public function testDetectExecutableMagicBytesPerlShebang(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('script shebang');

		$this->invokePrivateMethod('detectExecutableMagicBytes', [
			"some header\n#!/usr/bin/perl\nuse strict;",
			'File at test',
		]);
	}

	public function testDetectExecutableMagicBytesRubyShebang(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('script shebang');

		$this->invokePrivateMethod('detectExecutableMagicBytes', [
			"#!/usr/bin/ruby\nputs 'hello'",
			'File at test',
		]);
	}

	public function testDetectExecutableMagicBytesNodeShebang(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('env shebang');

		$this->invokePrivateMethod('detectExecutableMagicBytes', [
			"#!/usr/bin/env node\nconsole.log('hi');",
			'File at test',
		]);
	}

	public function testDetectExecutableMagicBytesEmbeddedPhpTag(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('PHP code');

		$this->invokePrivateMethod('detectExecutableMagicBytes', [
			"normal text <?php system('ls'); ?>",
			'File at test',
		]);
	}

	public function testDetectExecutableMagicBytesPhpShortEchoTag(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('PHP code');

		$this->invokePrivateMethod('detectExecutableMagicBytes', [
			"normal text <?= 'injected' ?>",
			'File at test',
		]);
	}

	public function testDetectExecutableMagicBytesZipSignatureSkipped(): void {
		// PK\x03\x04 is ZIP which has description = false, so it should be skipped.
		$this->invokePrivateMethod('detectExecutableMagicBytes', [
			"PK\x03\x04" . str_repeat("\x00", 100),
			'File at test',
		]);

		$this->assertTrue(true);
	}

	// =========================================================================
	// handleFileProperty — deletion with array containing non-numeric IDs
	// =========================================================================

	public function testHandleFilePropertyDeletionArrayWithNonNumericIds(): void {
		$entity = $this->createObjectEntity(1, 'uuid-del-nonnum');
		$entity->setObject(['images' => ['not-a-number', 10]]);

		$schema = new Schema();
		$schema->setProperties([
			'images' => ['type' => 'array', 'items' => ['type' => 'file']],
		]);

		// Only the numeric ID (10) should trigger deleteFile.
		$this->fileService->expects($this->once())
			->method('deleteFile')
			->with(10, $entity);

		$object = ['images' => []];

		$this->handler->handleFileProperty($entity, $object, 'images', $schema);

		$this->assertSame([], $object['images']);
	}

	// =========================================================================
	// handleFileProperty — mixed array: some file items, some not
	// =========================================================================

	public function testHandleFilePropertyArrayMixedFileAndNonFile(): void {
		$entity = $this->createObjectEntity(1, 'uuid-mix');
		$entity->setObject([]);

		$schema = new Schema();
		$schema->setProperties([
			'images' => ['type' => 'array', 'items' => ['type' => 'file']],
		]);

		$content = 'real image';
		$dataUri = 'data:image/png;base64,' . base64_encode($content);

		$fileMock = $this->createMock(File::class);
		$fileMock->method('getId')->willReturn(200);

		$this->fileService->expects($this->once())
			->method('addFile')
			->willReturn($fileMock);

		// First item is a data URI (file), second is a plain integer (not file).
		$object = ['images' => [$dataUri, 42]];

		$this->handler->handleFileProperty($entity, $object, 'images', $schema);

		$this->assertSame([200], $object['images']);
	}

	// =========================================================================
	// Edge case: handleFileProperty with property-level autoPublish overrides schema
	// =========================================================================

	public function testHandleFilePropertyPropertyAutoPublishOverridesSchema(): void {
		$entity = $this->createObjectEntity(1, 'uuid-override');
		$entity->setObject([]);

		$schema = new Schema();
		$schema->setProperties([
			'document' => ['type' => 'file', 'autoPublish' => false],
		]);
		// Schema-level says true, but property-level says false.
		$schema->setConfiguration(['autoPublish' => true]);

		$content = 'content';
		$dataUri = 'data:text/plain;base64,' . base64_encode($content);

		$fileMock = $this->createMock(File::class);
		$fileMock->method('getId')->willReturn(70);

		$this->fileService->expects($this->once())
			->method('addFile')
			->with(
				$entity,
				$this->isType('string'),
				$content,
				false,
				$this->isType('array')
			)
			->willReturn($fileMock);

		$object = ['document' => $dataUri];

		$this->handler->handleFileProperty($entity, $object, 'document', $schema);

		$this->assertSame(70, $object['document']);
	}

	/**
	 * Schema-based detection: a property declared as `type: file` IS a file property.
	 *
	 * This path had no direct coverage. It is also the hot predicate — called once
	 * per property per object — so it is worth pinning: two debug log lines were
	 * removed from it after they filled a dev instance's disk with 112 GB of
	 * per-property logging during an upgrade.
	 *
	 * @return void
	 */
	public function testSchemaDeclaredFilePropertyIsDetected(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(['document' => ['type' => 'file']]);

		$this->assertTrue($this->handler->isFileProperty('anything', $schema, 'document'));
	}

	/**
	 * A property the schema does not declare is not a file property.
	 *
	 * @return void
	 */
	public function testPropertyAbsentFromSchemaIsNotAFileProperty(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(['title' => ['type' => 'string']]);

		$this->assertFalse($this->handler->isFileProperty('anything', $schema, 'nosuchproperty'));
	}

	/**
	 * A declared non-file property is not a file property, whatever its value.
	 *
	 * Asserted with a value that WOULD pass the value-shape heuristics, so the
	 * schema is shown to win over the shape rather than merely agreeing with it.
	 *
	 * @return void
	 */
	public function testSchemaTypeWinsOverAFileLookingValue(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(['title' => ['type' => 'string']]);

		$this->assertFalse(
			$this->handler->isFileProperty('https://example.com/files/document.pdf', $schema, 'title')
		);
	}

	/**
	 * A schema with no properties at all does not crash the predicate.
	 *
	 * Uses an empty array rather than null: getProperties() is typed `array`, so
	 * null is not reachable — which also means the `?? []` guarding it in the
	 * handler is dead. Left in place; noting it here rather than changing
	 * unrelated code in a fix for a logging problem.
	 *
	 * @return void
	 */
	public function testASchemaWithNoPropertiesIsHandled(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn([]);

		$this->assertFalse($this->handler->isFileProperty('anything', $schema, 'document'));
	}
}
