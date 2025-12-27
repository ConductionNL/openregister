<?php

/**
 * FilesController Refactored Methods Unit Tests
 *
 * Comprehensive tests for the 7 private methods extracted during Phase 1 refactoring.
 * Tests cover multipart file upload handling and validation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Unit tests for FilesController refactored methods.
 *
 * Tests the 7 extracted private methods using reflection:
 * 1. validateAndGetObject()
 * 2. extractUploadedFiles()
 * 3. normalizeMultipartFiles()
 * 4. normalizeSingleFile()
 * 5. normalizeMultipleFiles()
 * 6. processUploadedFiles()
 * 7. validateUploadedFile()
 */
class FilesControllerRefactoredMethodsTest extends TestCase
{
	private FilesController $filesController;
	private ReflectionClass $reflection;

	/** @var MockObject|IRequest */
	private $request;

	/** @var MockObject|ObjectService */
	private $objectService;

	/** @var MockObject|FileService */
	private $fileService;

	/**
	 * Set up test environment before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Create mocks for all dependencies.
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->fileService = $this->createMock(FileService::class);

		// Create FilesController instance.
		$this->filesController = new FilesController(
			AppName: 'openregister',
			request: $this->request,
			objectService: $this->objectService,
			fileService: $this->fileService
		);

		// Set up reflection for accessing private methods.
		$this->reflection = new ReflectionClass(FilesController::class);
	}

	/**
	 * Helper method to invoke private methods using reflection.
	 *
	 * @param string $methodName The name of the private method.
	 * @param array  $parameters The parameters to pass to the method.
	 *
	 * @return mixed The result of the method invocation.
	 */
	private function invokePrivateMethod(string $methodName, array $parameters = []): mixed
	{
		$method = $this->reflection->getMethod($methodName);
		$method->setAccessible(true);

		return $method->invokeArgs($this->filesController, $parameters);
	}

	// ==================== validateAndGetObject() Tests ====================

	/**
	 * Test validateAndGetObject returns existing object.
	 *
	 * @return void
	 */
	public function testValidateAndGetObjectReturnsExistingObject(): void
	{
		$uuid = 'test-uuid-123';
		$mockObject = new ObjectEntity();
		$mockObject->setUuid($uuid);
		$mockObject->setId(1);

		$this->objectService
			->expects($this->once())
			->method('findObject')
			->with(null, null, ['uuid' => $uuid])
			->willReturn($mockObject);

		$result = $this->invokePrivateMethod(
			methodName: 'validateAndGetObject',
			parameters: [$uuid]
		);

		$this->assertSame($mockObject, $result, 'Should return the found object.');
	}

	/**
	 * Test validateAndGetObject throws exception when object not found.
	 *
	 * @return void
	 */
	public function testValidateAndGetObjectThrowsExceptionWhenNotFound(): void
	{
		$uuid = 'non-existent-uuid';

		$this->objectService
			->expects($this->once())
			->method('findObject')
			->with(null, null, ['uuid' => $uuid])
			->willThrowException(new DoesNotExistException('Object not found.'));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Object not found');

		$this->invokePrivateMethod(
			methodName: 'validateAndGetObject',
			parameters: [$uuid]
		);
	}

	// ==================== extractUploadedFiles() Tests ====================

	/**
	 * Test extractUploadedFiles extracts from $_FILES.
	 *
	 * @return void
	 */
	public function testExtractUploadedFilesFromGlobal(): void
	{
		$_FILES = [
			'avatar' => [
				'name' => 'profile.jpg',
				'type' => 'image/jpeg',
				'tmp_name' => '/tmp/phpXYZ',
				'error' => 0,
				'size' => 12345
			],
			'document' => [
				'name' => 'file.pdf',
				'type' => 'application/pdf',
				'tmp_name' => '/tmp/phpABC',
				'error' => 0,
				'size' => 54321
			]
		];

		$result = $this->invokePrivateMethod(methodName: 'extractUploadedFiles');

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertArrayHasKey('avatar', $result, 'Should have avatar file.');
		$this->assertArrayHasKey('document', $result, 'Should have document file.');
		$this->assertEquals('profile.jpg', $result['avatar']['name'], 'Avatar name should match.');
		$this->assertEquals('file.pdf', $result['document']['name'], 'Document name should match.');

		// Clean up.
		$_FILES = [];
	}

	/**
	 * Test extractUploadedFiles returns empty when no files.
	 *
	 * @return void
	 */
	public function testExtractUploadedFilesReturnsEmptyWhenNoFiles(): void
	{
		$_FILES = [];

		$result = $this->invokePrivateMethod(methodName: 'extractUploadedFiles');

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertEmpty($result, 'Result should be empty when no files uploaded.');
	}

	// ==================== validateUploadedFile() Tests ====================

	/**
	 * Test validateUploadedFile with valid file.
	 *
	 * @return void
	 */
	public function testValidateUploadedFileWithValidFile(): void
	{
		$file = [
			'name' => 'test.jpg',
			'type' => 'image/jpeg',
			'tmp_name' => '/tmp/phpXYZ',
			'error' => 0,
			'size' => 12345
		];

		// Should not throw exception.
		$this->expectNotToPerformAssertions();

		$this->invokePrivateMethod(
			methodName: 'validateUploadedFile',
			parameters: [$file]
		);
	}

	/**
	 * Test validateUploadedFile with upload error.
	 *
	 * @return void
	 */
	public function testValidateUploadedFileWithUploadError(): void
	{
		$file = [
			'name' => 'test.jpg',
			'type' => 'image/jpeg',
			'tmp_name' => '',
			'error' => UPLOAD_ERR_NO_FILE,
			'size' => 0
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('upload error');

		$this->invokePrivateMethod(
			methodName: 'validateUploadedFile',
			parameters: [$file]
		);
	}

	/**
	 * Test validateUploadedFile with missing name.
	 *
	 * @return void
	 */
	public function testValidateUploadedFileWithMissingName(): void
	{
		$file = [
			'name' => '',
			'type' => 'image/jpeg',
			'tmp_name' => '/tmp/phpXYZ',
			'error' => 0,
			'size' => 12345
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('name');

		$this->invokePrivateMethod(
			methodName: 'validateUploadedFile',
			parameters: [$file]
		);
	}

	/**
	 * Test validateUploadedFile with zero size.
	 *
	 * @return void
	 */
	public function testValidateUploadedFileWithZeroSize(): void
	{
		$file = [
			'name' => 'test.jpg',
			'type' => 'image/jpeg',
			'tmp_name' => '/tmp/phpXYZ',
			'error' => 0,
			'size' => 0
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('size');

		$this->invokePrivateMethod(
			methodName: 'validateUploadedFile',
			parameters: [$file]
		);
	}

	// ==================== normalizeSingleFile() Tests ====================

	/**
	 * Test normalizeSingleFile normalizes structure.
	 *
	 * @return void
	 */
	public function testNormalizeSingleFileNormalizesStructure(): void
	{
		$file = [
			'name' => 'test.jpg',
			'type' => 'image/jpeg',
			'tmp_name' => '/tmp/phpXYZ',
			'error' => 0,
			'size' => 12345
		];

		$result = $this->invokePrivateMethod(
			methodName: 'normalizeSingleFile',
			parameters: [$file, 'avatar']
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertCount(1, $result, 'Should return single file in array.');
		$this->assertEquals('avatar', $result[0]['property'], 'Property should be set.');
		$this->assertEquals('test.jpg', $result[0]['name'], 'Name should be preserved.');
		$this->assertEquals('image/jpeg', $result[0]['type'], 'Type should be preserved.');
		$this->assertEquals('/tmp/phpXYZ', $result[0]['tmp_name'], 'Tmp_name should be preserved.');
		$this->assertEquals(0, $result[0]['error'], 'Error should be preserved.');
		$this->assertEquals(12345, $result[0]['size'], 'Size should be preserved.');
	}

	// ==================== normalizeMultipleFiles() Tests ====================

	/**
	 * Test normalizeMultipleFiles with multiple files.
	 *
	 * @return void
	 */
	public function testNormalizeMultipleFilesWithMultipleFiles(): void
	{
		$files = [
			'name' => ['file1.jpg', 'file2.png'],
			'type' => ['image/jpeg', 'image/png'],
			'tmp_name' => ['/tmp/php1', '/tmp/php2'],
			'error' => [0, 0],
			'size' => [10000, 20000]
		];

		$result = $this->invokePrivateMethod(
			methodName: 'normalizeMultipleFiles',
			parameters: [$files, 'documents']
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertCount(2, $result, 'Should return 2 normalized files.');

		// Check first file.
		$this->assertEquals('documents', $result[0]['property'], 'Property should be set.');
		$this->assertEquals('file1.jpg', $result[0]['name'], 'First file name should match.');
		$this->assertEquals('image/jpeg', $result[0]['type'], 'First file type should match.');

		// Check second file.
		$this->assertEquals('documents', $result[1]['property'], 'Property should be set.');
		$this->assertEquals('file2.png', $result[1]['name'], 'Second file name should match.');
		$this->assertEquals('image/png', $result[1]['type'], 'Second file type should match.');
	}

	/**
	 * Test normalizeMultipleFiles with single file in array format.
	 *
	 * @return void
	 */
	public function testNormalizeMultipleFilesWithSingleFile(): void
	{
		$files = [
			'name' => ['file1.jpg'],
			'type' => ['image/jpeg'],
			'tmp_name' => ['/tmp/php1'],
			'error' => [0],
			'size' => [10000]
		];

		$result = $this->invokePrivateMethod(
			methodName: 'normalizeMultipleFiles',
			parameters: [$files, 'avatar']
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertCount(1, $result, 'Should return 1 normalized file.');
		$this->assertEquals('file1.jpg', $result[0]['name'], 'File name should match.');
	}

	// ==================== normalizeMultipartFiles() Tests ====================

	/**
	 * Test normalizeMultipartFiles with mixed single and multiple files.
	 *
	 * @return void
	 */
	public function testNormalizeMultipartFilesWithMixedFiles(): void
	{
		$uploadedFiles = [
			'avatar' => [
				'name' => 'profile.jpg',
				'type' => 'image/jpeg',
				'tmp_name' => '/tmp/php1',
				'error' => 0,
				'size' => 10000
			],
			'documents' => [
				'name' => ['doc1.pdf', 'doc2.pdf'],
				'type' => ['application/pdf', 'application/pdf'],
				'tmp_name' => ['/tmp/php2', '/tmp/php3'],
				'error' => [0, 0],
				'size' => [20000, 30000]
			]
		];

		$result = $this->invokePrivateMethod(
			methodName: 'normalizeMultipartFiles',
			parameters: [$uploadedFiles]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertCount(3, $result, 'Should return 3 normalized files (1 avatar + 2 documents).');

		// Check avatar (single file).
		$avatarFiles = array_filter($result, fn($f) => $f['property'] === 'avatar');
		$this->assertCount(1, $avatarFiles, 'Should have 1 avatar file.');

		// Check documents (multiple files).
		$documentFiles = array_filter($result, fn($f) => $f['property'] === 'documents');
		$this->assertCount(2, $documentFiles, 'Should have 2 document files.');
	}

	/**
	 * Test normalizeMultipartFiles with empty array.
	 *
	 * @return void
	 */
	public function testNormalizeMultipartFilesWithEmptyArray(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'normalizeMultipartFiles',
			parameters: [[]]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertEmpty($result, 'Result should be empty.');
	}

	// ==================== processUploadedFiles() Tests ====================

	/**
	 * Test processUploadedFiles processes files and updates object.
	 *
	 * @return void
	 */
	public function testProcessUploadedFilesProcessesFiles(): void
	{
		$normalizedFiles = [
			[
				'property' => 'avatar',
				'name' => 'profile.jpg',
				'type' => 'image/jpeg',
				'tmp_name' => '/tmp/php1',
				'error' => 0,
				'size' => 10000
			],
			[
				'property' => 'document',
				'name' => 'file.pdf',
				'type' => 'application/pdf',
				'tmp_name' => '/tmp/php2',
				'error' => 0,
				'size' => 20000
			]
		];

		$object = new ObjectEntity();
		$object->setId(1);
		$object->setUuid('test-uuid');
		$object->setObject(['name' => 'Test Object']);

		// Mock file service to return file IDs.
		$this->fileService
			->expects($this->exactly(2))
			->method('uploadFile')
			->willReturnOnConsecutiveCalls('file-id-1', 'file-id-2');

		// Mock object service to save updated object.
		$this->objectService
			->expects($this->once())
			->method('saveObject')
			->willReturn($object);

		// Execute method.
		$this->invokePrivateMethod(
			methodName: 'processUploadedFiles',
			parameters: [$normalizedFiles, $object]
		);

		// Verify object data was updated.
		$objectData = $object->getObject();
		$this->assertArrayHasKey('avatar', $objectData, 'Object should have avatar property.');
		$this->assertArrayHasKey('document', $objectData, 'Object should have document property.');
		$this->assertEquals('file-id-1', $objectData['avatar'], 'Avatar should have file ID.');
		$this->assertEquals('file-id-2', $objectData['document'], 'Document should have file ID.');
	}

	/**
	 * Test processUploadedFiles with empty files array.
	 *
	 * @return void
	 */
	public function testProcessUploadedFilesWithEmptyArray(): void
	{
		$object = new ObjectEntity();
		$object->setId(1);

		$this->fileService
			->expects($this->never())
			->method('uploadFile');

		$this->objectService
			->expects($this->never())
			->method('saveObject');

		// Should not throw exception.
		$this->expectNotToPerformAssertions();

		$this->invokePrivateMethod(
			methodName: 'processUploadedFiles',
			parameters: [[], $object]
		);
	}

	/**
	 * Test processUploadedFiles handles upload failure gracefully.
	 *
	 * @return void
	 */
	public function testProcessUploadedFilesHandlesUploadFailure(): void
	{
		$normalizedFiles = [
			[
				'property' => 'avatar',
				'name' => 'profile.jpg',
				'type' => 'image/jpeg',
				'tmp_name' => '/tmp/php1',
				'error' => 0,
				'size' => 10000
			]
		];

		$object = new ObjectEntity();
		$object->setId(1);

		// Mock file service to throw exception.
		$this->fileService
			->expects($this->once())
			->method('uploadFile')
			->willThrowException(new \Exception('Upload failed.'));

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Upload failed');

		$this->invokePrivateMethod(
			methodName: 'processUploadedFiles',
			parameters: [$normalizedFiles, $object]
		);
	}

	// ==================== Integration Test ====================

	/**
	 * Test that all refactored methods work together in createMultipart().
	 *
	 * This integration test is limited as it requires actual HTTP request simulation.
	 * Full integration testing should be done at the API level.
	 *
	 * @return void
	 */
	public function testRefactoredCreateMultipartMethodsWorkTogether(): void
	{
		// This test verifies the flow conceptually.
		// Full integration requires request mocking which is complex.
		$uuid = 'test-uuid-123';
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setId(1);
		$object->setObject(['name' => 'Test Object']);

		// Mock finding the object.
		$this->objectService
			->method('findObject')
			->willReturn($object);

		// Simulate file upload.
		$_FILES = [
			'avatar' => [
				'name' => 'profile.jpg',
				'type' => 'image/jpeg',
				'tmp_name' => '/tmp/phpXYZ',
				'error' => 0,
				'size' => 12345
			]
		];

		// Test individual method calls in sequence.
		$foundObject = $this->invokePrivateMethod('validateAndGetObject', [$uuid]);
		$this->assertSame($object, $foundObject, 'Object should be found.');

		$uploadedFiles = $this->invokePrivateMethod('extractUploadedFiles');
		$this->assertNotEmpty($uploadedFiles, 'Files should be extracted.');

		$normalizedFiles = $this->invokePrivateMethod('normalizeMultipartFiles', [$uploadedFiles]);
		$this->assertNotEmpty($normalizedFiles, 'Files should be normalized.');

		// Clean up.
		$_FILES = [];

		$this->assertTrue(true, 'Integration flow completed successfully.');
	}
}




