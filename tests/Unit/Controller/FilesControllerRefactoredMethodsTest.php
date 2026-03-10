<?php

/**
 * FilesController Refactored Methods Unit Tests
 *
 * Comprehensive tests for the private methods extracted during Phase 1 refactoring.
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
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Unit tests for FilesController refactored methods.
 *
 * Tests the extracted private methods using reflection:
 * 1. validateAndGetObject(string $register, string $schema, string $id)
 * 2. extractUploadedFiles()
 * 3. normalizeMultipartFiles(array $files, array $data)
 * 4. normalizeSingleFile(array $files, array $data)
 * 5. normalizeMultipleFiles(array $files, array $data, array $fileNames)
 * 6. processUploadedFiles(ObjectEntity $object, array $uploadedFiles)
 * 7. validateUploadedFile(array $file)
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

	/** @var MockObject|IRootFolder */
	private $rootFolder;

	/** @var MockObject|IUserManager */
	private $userManager;

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
		$this->fileService = $this->createMock(FileService::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userManager = $this->createMock(IUserManager::class);

		// Create FilesController instance.
		$this->filesController = new FilesController(
			appName: 'openregister',
			request: $this->request,
			fileService: $this->fileService,
			objectService: $this->objectService,
			rootFolder: $this->rootFolder,
			userManager: $this->userManager
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
			->method('setSchema')
			->with('test-schema');

		$this->objectService
			->expects($this->once())
			->method('setRegister')
			->with('test-register');

		$this->objectService
			->expects($this->once())
			->method('setObject')
			->with($uuid);

		$this->objectService
			->expects($this->once())
			->method('getObject')
			->willReturn($mockObject);

		$result = $this->invokePrivateMethod(
			methodName: 'validateAndGetObject',
			parameters: ['test-register', 'test-schema', $uuid]
		);

		$this->assertSame($mockObject, $result, 'Should return the found object.');
	}

	/**
	 * Test validateAndGetObject returns null when object not found.
	 *
	 * @return void
	 */
	public function testValidateAndGetObjectReturnsNullWhenNotFound(): void
	{
		$this->objectService
			->method('getObject')
			->willReturn(null);

		$result = $this->invokePrivateMethod(
			methodName: 'validateAndGetObject',
			parameters: ['test-register', 'test-schema', 'non-existent-uuid']
		);

		$this->assertNull($result, 'Should return null when object not found.');
	}

	// ==================== validateUploadedFile() Tests ====================

	/**
	 * Test validateUploadedFile with valid file.
	 *
	 * @return void
	 */
	public function testValidateUploadedFileWithValidFile(): void
	{
		// Create a temp file so file_exists and is_readable checks pass.
		$tmpFile = tempnam(sys_get_temp_dir(), 'test_');
		file_put_contents($tmpFile, 'test content');

		$file = [
			'name' => 'test.jpg',
			'type' => 'image/jpeg',
			'tmp_name' => $tmpFile,
			'error' => UPLOAD_ERR_OK,
			'size' => 12345
		];

		// Should not throw exception.
		$this->invokePrivateMethod(
			methodName: 'validateUploadedFile',
			parameters: [$file]
		);

		// If we get here, no exception was thrown.
		$this->assertTrue(true, 'Valid file should not throw exception.');

		// Clean up.
		unlink($tmpFile);
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

		$this->invokePrivateMethod(
			methodName: 'validateUploadedFile',
			parameters: [$file]
		);
	}

	/**
	 * Test validateUploadedFile with non-readable tmp file.
	 *
	 * @return void
	 */
	public function testValidateUploadedFileWithNonReadableFile(): void
	{
		$file = [
			'name' => 'test.jpg',
			'type' => 'image/jpeg',
			'tmp_name' => '/tmp/non_existent_file_xyz',
			'error' => UPLOAD_ERR_OK,
			'size' => 12345
		];

		$this->expectException(\Exception::class);

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
		$files = [
			'name' => 'test.jpg',
			'type' => 'image/jpeg',
			'tmp_name' => '/tmp/phpXYZ',
			'error' => 0,
			'size' => 12345
		];

		$data = [
			'share' => 'true',
			'tags' => 'tag1,tag2'
		];

		$result = $this->invokePrivateMethod(
			methodName: 'normalizeSingleFile',
			parameters: [$files, $data]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertEquals('test.jpg', $result['name'], 'Name should be preserved.');
		$this->assertEquals('image/jpeg', $result['type'], 'Type should be preserved.');
		$this->assertEquals('/tmp/phpXYZ', $result['tmp_name'], 'Tmp_name should be preserved.');
		$this->assertEquals(0, $result['error'], 'Error should be preserved.');
		$this->assertEquals(12345, $result['size'], 'Size should be preserved.');
		$this->assertTrue($result['share'], 'Share should be true.');
		$this->assertIsArray($result['tags'], 'Tags should be an array.');
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

		$data = [
			'share' => 'true',
			'tags' => ['tag1', 'tag2']
		];

		$fileNames = ['file1.jpg', 'file2.png'];

		$result = $this->invokePrivateMethod(
			methodName: 'normalizeMultipleFiles',
			parameters: [$files, $data, $fileNames]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertCount(2, $result, 'Should return 2 normalized files.');

		// Check first file.
		$this->assertEquals('file1.jpg', $result[0]['name'], 'First file name should match.');
		$this->assertEquals('image/jpeg', $result[0]['type'], 'First file type should match.');

		// Check second file.
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

		$data = [
			'share' => 'false',
			'tags' => ['']
		];

		$fileNames = ['file1.jpg'];

		$result = $this->invokePrivateMethod(
			methodName: 'normalizeMultipleFiles',
			parameters: [$files, $data, $fileNames]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertCount(1, $result, 'Should return 1 normalized file.');
		$this->assertEquals('file1.jpg', $result[0]['name'], 'File name should match.');
	}

	// ==================== normalizeMultipartFiles() Tests ====================

	/**
	 * Test normalizeMultipartFiles with single file upload.
	 *
	 * @return void
	 */
	public function testNormalizeMultipartFilesWithSingleFile(): void
	{
		$files = [
			'name' => 'profile.jpg',
			'type' => 'image/jpeg',
			'tmp_name' => '/tmp/php1',
			'error' => 0,
			'size' => 10000
		];

		$data = [
			'share' => 'true',
			'tags' => 'tag1'
		];

		$result = $this->invokePrivateMethod(
			methodName: 'normalizeMultipartFiles',
			parameters: [$files, $data]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertCount(1, $result, 'Should return 1 normalized file for single upload.');
		$this->assertEquals('profile.jpg', $result[0]['name'], 'File name should match.');
	}

	/**
	 * Test normalizeMultipartFiles with multiple file upload.
	 *
	 * @return void
	 */
	public function testNormalizeMultipartFilesWithMultipleFiles(): void
	{
		$files = [
			'name' => ['doc1.pdf', 'doc2.pdf'],
			'type' => ['application/pdf', 'application/pdf'],
			'tmp_name' => ['/tmp/php2', '/tmp/php3'],
			'error' => [0, 0],
			'size' => [20000, 30000]
		];

		$data = [
			'share' => 'false',
			'tags' => ['', '']
		];

		$result = $this->invokePrivateMethod(
			methodName: 'normalizeMultipartFiles',
			parameters: [$files, $data]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertCount(2, $result, 'Should return 2 normalized files.');
		$this->assertEquals('doc1.pdf', $result[0]['name'], 'First file name should match.');
		$this->assertEquals('doc2.pdf', $result[1]['name'], 'Second file name should match.');
	}

	/**
	 * Test normalizeMultipartFiles with empty files.
	 *
	 * @return void
	 */
	public function testNormalizeMultipartFilesWithEmptyFiles(): void
	{
		$files = [];
		$data = ['share' => 'false', 'tags' => ''];

		$result = $this->invokePrivateMethod(
			methodName: 'normalizeMultipartFiles',
			parameters: [$files, $data]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertEmpty($result, 'Result should be empty.');
	}

	// ==================== processUploadedFiles() Tests ====================

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
			->method('addFile');

		$result = $this->invokePrivateMethod(
			methodName: 'processUploadedFiles',
			parameters: [$object, []]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertEmpty($result, 'Result should be empty when no files.');
	}
}
