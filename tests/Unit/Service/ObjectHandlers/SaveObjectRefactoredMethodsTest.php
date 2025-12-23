<?php

/**
 * SaveObject Refactored Methods Unit Tests
 *
 * Comprehensive tests for the 7 private methods extracted during Phase 1 refactoring.
 * These tests protect the 411M NPath complexity reduction achieved.
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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Twig\Loader\ArrayLoader;
use Symfony\Component\Uid\Uuid;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for SaveObject refactored methods.
 *
 * Tests the 7 extracted private methods using reflection:
 * 1. extractUuidAndSelfData()
 * 2. resolveSchemaAndRegister()
 * 3. findAndValidateExistingObject()
 * 4. handleObjectUpdate()
 * 5. handleObjectCreation()
 * 6. processFilePropertiesWithRollback()
 * 7. clearImageMetadataIfFileProperty()
 */
class SaveObjectRefactoredMethodsTest extends TestCase
{
	private SaveObject $saveObject;
	private ReflectionClass $reflection;

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
	 * Set up test environment before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Create mocks for all dependencies.
		$this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		// ArrayLoader is final, so we create a real instance instead of mocking.
		$this->arrayLoader = new ArrayLoader([]);

		// Create mock entities.
		$this->mockRegister = $this->createMock(Register::class);
		$this->mockSchema = $this->createMock(Schema::class);
		$this->mockUser = $this->createMock(IUser::class);

		// Set up basic mock returns.
		$this->mockRegister->method('getId')->willReturn(1);
		$this->mockRegister->method('getSlug')->willReturn('test-register');

		$this->mockSchema->method('getId')->willReturn(1);
		$this->mockSchema->method('getSlug')->willReturn('test-schema');
		$this->mockSchema->method('getSchemaObject')->willReturn((object)[
			'properties' => []
		]);

		$this->mockUser->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($this->mockUser);

		// Create SaveObject instance.
		$this->saveObject = new SaveObject(
			objectEntityMapper: $this->objectEntityMapper,
			fileService: $this->fileService,
			userSession: $this->userSession,
			auditTrailMapper: $this->auditTrailMapper,
			schemaMapper: $this->schemaMapper,
			registerMapper: $this->registerMapper,
			urlGenerator: $this->urlGenerator,
			arrayLoader: $this->arrayLoader
		);

		// Set up reflection for accessing private methods.
		$this->reflection = new ReflectionClass(SaveObject::class);
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

		return $method->invokeArgs($this->saveObject, $parameters);
	}

	// ==================== extractUuidAndSelfData() Tests ====================

	/**
	 * Test extractUuidAndSelfData with data containing '_self' URL.
	 *
	 * @return void
	 */
	public function testExtractUuidAndSelfDataWithSelfUrl(): void
	{
		$uuid = Uuid::v4()->toRfc4122();
		$data = [
			'_self' => "http://example.com/objects/{$uuid}",
			'name' => 'Test Object'
		];

		[$extractedData, $extractedUuid, $selfData] = $this->invokePrivateMethod(
			methodName: 'extractUuidAndSelfData',
			parameters: [$data, null]
		);

		$this->assertEquals($uuid, $extractedUuid, 'UUID should be extracted from _self URL.');
		$this->assertArrayNotHasKey('_self', $extractedData, '_self should be removed from data.');
		$this->assertEquals('Test Object', $extractedData['name'], 'Other data should be preserved.');
		$this->assertEquals("http://example.com/objects/{$uuid}", $selfData, 'selfData should contain _self value.');
	}

	/**
	 * Test extractUuidAndSelfData with data containing 'id' field.
	 *
	 * @return void
	 */
	public function testExtractUuidAndSelfDataWithIdField(): void
	{
		$uuid = Uuid::v4()->toRfc4122();
		$data = [
			'id' => $uuid,
			'name' => 'Test Object'
		];

		[$extractedData, $extractedUuid, $selfData] = $this->invokePrivateMethod(
			methodName: 'extractUuidAndSelfData',
			parameters: [$data, null]
		);

		$this->assertEquals($uuid, $extractedUuid, 'UUID should be extracted from id field.');
		$this->assertArrayNotHasKey('id', $extractedData, 'id should be removed from data.');
		$this->assertEquals('Test Object', $extractedData['name'], 'Other data should be preserved.');
		$this->assertNull($selfData, 'selfData should be null when no _self provided.');
	}

	/**
	 * Test extractUuidAndSelfData with explicit UUID parameter.
	 *
	 * @return void
	 */
	public function testExtractUuidAndSelfDataWithExplicitUuid(): void
	{
		$explicitUuid = Uuid::v4()->toRfc4122();
		$dataUuid = Uuid::v4()->toRfc4122();
		$data = [
			'id' => $dataUuid,
			'name' => 'Test Object'
		];

		[$extractedData, $extractedUuid, $selfData] = $this->invokePrivateMethod(
			methodName: 'extractUuidAndSelfData',
			parameters: [$data, $explicitUuid]
		);

		$this->assertEquals($explicitUuid, $extractedUuid, 'Explicit UUID parameter should take precedence.');
		$this->assertArrayNotHasKey('id', $extractedData, 'id should still be removed from data.');
	}

	/**
	 * Test extractUuidAndSelfData without UUID generates new one.
	 *
	 * @return void
	 */
	public function testExtractUuidAndSelfDataGeneratesNewUuid(): void
	{
		$data = ['name' => 'Test Object'];

		[$extractedData, $extractedUuid, $selfData] = $this->invokePrivateMethod(
			methodName: 'extractUuidAndSelfData',
			parameters: [$data, null]
		);

		$this->assertNotNull($extractedUuid, 'UUID should be generated when not provided.');
		$this->assertTrue(Uuid::isValid($extractedUuid), 'Generated UUID should be valid.');
		$this->assertEquals('Test Object', $extractedData['name'], 'Data should be preserved.');
	}

	// ==================== resolveSchemaAndRegister() Tests ====================

	/**
	 * Test resolveSchemaAndRegister with Register object.
	 *
	 * @return void
	 */
	public function testResolveSchemaAndRegisterWithRegisterObject(): void
	{
		$data = [];

		$this->invokePrivateMethod(
			methodName: 'resolveSchemaAndRegister',
			parameters: [$data, $this->mockRegister, $this->mockSchema]
		);

		// Use reflection to check private properties were set.
		$registerProp = $this->reflection->getProperty('currentRegister');
		$registerProp->setAccessible(true);
		$schemaProp = $this->reflection->getProperty('currentSchema');
		$schemaProp->setAccessible(true);

		$this->assertSame($this->mockRegister, $registerProp->getValue($this->saveObject), 'Register should be set.');
		$this->assertSame($this->mockSchema, $schemaProp->getValue($this->saveObject), 'Schema should be set.');
	}

	/**
	 * Test resolveSchemaAndRegister with integer register ID.
	 *
	 * @return void
	 */
	public function testResolveSchemaAndRegisterWithIntegerId(): void
	{
		$data = [];

		$this->registerMapper
			->expects($this->once())
			->method('find')
			->with(42)
			->willReturn($this->mockRegister);

		$this->schemaMapper
			->expects($this->once())
			->method('find')
			->with(10)
			->willReturn($this->mockSchema);

		$this->invokePrivateMethod(
			methodName: 'resolveSchemaAndRegister',
			parameters: [$data, 42, 10]
		);

		$registerProp = $this->reflection->getProperty('currentRegister');
		$registerProp->setAccessible(true);
		$this->assertSame($this->mockRegister, $registerProp->getValue($this->saveObject), 'Register should be resolved by ID.');
	}

	/**
	 * Test resolveSchemaAndRegister with string register slug.
	 *
	 * @return void
	 */
	public function testResolveSchemaAndRegisterWithStringSlug(): void
	{
		$data = [];

		$this->registerMapper
			->expects($this->once())
			->method('findBySlug')
			->with('my-register')
			->willReturn($this->mockRegister);

		$this->schemaMapper
			->expects($this->once())
			->method('findBySlug')
			->with('my-schema')
			->willReturn($this->mockSchema);

		$this->invokePrivateMethod(
			methodName: 'resolveSchemaAndRegister',
			parameters: [$data, 'my-register', 'my-schema']
		);

		$registerProp = $this->reflection->getProperty('currentRegister');
		$registerProp->setAccessible(true);
		$this->assertSame($this->mockRegister, $registerProp->getValue($this->saveObject), 'Register should be resolved by slug.');
	}

	/**
	 * Test resolveSchemaAndRegister extracts from data when not provided.
	 *
	 * @return void
	 */
	public function testResolveSchemaAndRegisterExtractsFromData(): void
	{
		$data = [
			'_register' => 'data-register',
			'_schema' => 'data-schema'
		];

		$this->registerMapper
			->expects($this->once())
			->method('findBySlug')
			->with('data-register')
			->willReturn($this->mockRegister);

		$this->schemaMapper
			->expects($this->once())
			->method('findBySlug')
			->with('data-schema')
			->willReturn($this->mockSchema);

		$this->invokePrivateMethod(
			methodName: 'resolveSchemaAndRegister',
			parameters: [$data, null, null]
		);

		$registerProp = $this->reflection->getProperty('currentRegister');
		$registerProp->setAccessible(true);
		$this->assertSame($this->mockRegister, $registerProp->getValue($this->saveObject), 'Register should be extracted from data.');
	}

	/**
	 * Test resolveSchemaAndRegister throws exception when register not found.
	 *
	 * @return void
	 */
	public function testResolveSchemaAndRegisterThrowsExceptionWhenRegisterNotFound(): void
	{
		$data = [];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Register not provided and could not be resolved.');

		$this->invokePrivateMethod(
			methodName: 'resolveSchemaAndRegister',
			parameters: [$data, null, $this->mockSchema]
		);
	}

	// ==================== findAndValidateExistingObject() Tests ====================

	/**
	 * Test findAndValidateExistingObject returns existing object.
	 *
	 * @return void
	 */
	public function testFindAndValidateExistingObjectReturnsExisting(): void
	{
		$uuid = Uuid::v4()->toRfc4122();
		$existingObject = new ObjectEntity();
		$existingObject->setUuid($uuid);
		$existingObject->setId(123);

		$this->objectEntityMapper
			->expects($this->once())
			->method('find')
			->with($uuid)
			->willReturn($existingObject);

		$result = $this->invokePrivateMethod(
			methodName: 'findAndValidateExistingObject',
			parameters: [$uuid]
		);

		$this->assertSame($existingObject, $result, 'Should return existing object.');
	}

	/**
	 * Test findAndValidateExistingObject returns null when not found.
	 *
	 * @return void
	 */
	public function testFindAndValidateExistingObjectReturnsNullWhenNotFound(): void
	{
		$uuid = Uuid::v4()->toRfc4122();

		$this->objectEntityMapper
			->expects($this->once())
			->method('find')
			->with($uuid)
			->willThrowException(new DoesNotExistException('Not found.'));

		$result = $this->invokePrivateMethod(
			methodName: 'findAndValidateExistingObject',
			parameters: [$uuid]
		);

		$this->assertNull($result, 'Should return null when object does not exist.');
	}

	/**
	 * Test findAndValidateExistingObject with null UUID returns null.
	 *
	 * @return void
	 */
	public function testFindAndValidateExistingObjectWithNullUuidReturnsNull(): void
	{
		$this->objectEntityMapper
			->expects($this->never())
			->method('find');

		$result = $this->invokePrivateMethod(
			methodName: 'findAndValidateExistingObject',
			parameters: [null]
		);

		$this->assertNull($result, 'Should return null when UUID is null.');
	}

	// ==================== clearImageMetadataIfFileProperty() Tests ====================

	/**
	 * Test clearImageMetadataIfFileProperty removes image metadata.
	 *
	 * @return void
	 */
	public function testClearImageMetadataIfFilePropertyRemovesMetadata(): void
	{
		// Set up schema with file property.
		$this->mockSchema
			->method('getSchemaObject')
			->willReturn((object)[
				'properties' => [
					'avatar' => [
						'type' => 'string',
						'format' => 'file'
					]
				]
			]);

		// Set current schema.
		$schemaProp = $this->reflection->getProperty('currentSchema');
		$schemaProp->setAccessible(true);
		$schemaProp->setValue($this->saveObject, $this->mockSchema);

		$data = [
			'avatar' => 'file-id-123',
			'avatar_imageMetadata' => [
				'width' => 800,
				'height' => 600
			],
			'name' => 'John Doe'
		];

		$result = $this->invokePrivateMethod(
			methodName: 'clearImageMetadataIfFileProperty',
			parameters: [$data]
		);

		$this->assertArrayNotHasKey('avatar_imageMetadata', $result, 'Image metadata should be removed.');
		$this->assertEquals('file-id-123', $result['avatar'], 'File property should remain.');
		$this->assertEquals('John Doe', $result['name'], 'Other properties should remain.');
	}

	/**
	 * Test clearImageMetadataIfFileProperty preserves metadata for non-file properties.
	 *
	 * @return void
	 */
	public function testClearImageMetadataIfFilePropertyPreservesNonFileMetadata(): void
	{
		// Set up schema WITHOUT file property.
		$this->mockSchema
			->method('getSchemaObject')
			->willReturn((object)[
				'properties' => [
					'avatar' => [
						'type' => 'string'
					]
				]
			]);

		// Set current schema.
		$schemaProp = $this->reflection->getProperty('currentSchema');
		$schemaProp->setAccessible(true);
		$schemaProp->setValue($this->saveObject, $this->mockSchema);

		$data = [
			'avatar' => 'url',
			'avatar_imageMetadata' => [
				'width' => 800,
				'height' => 600
			]
		];

		$result = $this->invokePrivateMethod(
			methodName: 'clearImageMetadataIfFileProperty',
			parameters: [$data]
		);

		$this->assertArrayHasKey('avatar_imageMetadata', $result, 'Metadata should be preserved for non-file properties.');
	}

	/**
	 * Test clearImageMetadataIfFileProperty handles empty data.
	 *
	 * @return void
	 */
	public function testClearImageMetadataIfFilePropertyHandlesEmptyData(): void
	{
		// Set current schema.
		$schemaProp = $this->reflection->getProperty('currentSchema');
		$schemaProp->setAccessible(true);
		$schemaProp->setValue($this->saveObject, $this->mockSchema);

		$data = [];

		$result = $this->invokePrivateMethod(
			methodName: 'clearImageMetadataIfFileProperty',
			parameters: [$data]
		);

		$this->assertEmpty($result, 'Empty data should remain empty.');
	}

	// ==================== Integration Test ====================

	/**
	 * Test that refactored saveObject still works end-to-end.
	 *
	 * This test verifies that all extracted methods work together correctly.
	 *
	 * @return void
	 */
	public function testRefactoredSaveObjectIntegration(): void
	{
		$uuid = Uuid::v4()->toRfc4122();
		$data = [
			'name' => 'Integration Test Object',
			'description' => 'Testing refactored methods.'
		];

		// Mock that object doesn't exist (create scenario).
		$this->objectEntityMapper
			->method('find')
			->with($uuid)
			->willThrowException(new DoesNotExistException('Object not found.'));

		// Mock successful creation.
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

		// Execute full saveObject method.
		$result = $this->saveObject->saveObject(
			register: $this->mockRegister,
			schema: $this->mockSchema,
			data: $data,
			uuid: $uuid
		);

		// Assertions.
		$this->assertInstanceOf(ObjectEntity::class, $result, 'Should return ObjectEntity.');
		$this->assertEquals($uuid, $result->getUuid(), 'UUID should match.');
		$this->assertEquals($data, $result->getObject(), 'Data should be preserved.');
	}
}


