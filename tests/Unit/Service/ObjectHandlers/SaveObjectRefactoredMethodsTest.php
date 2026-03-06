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
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Loader\ArrayLoader;
use ReflectionClass;

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

	/** @var MockObject|UnifiedObjectMapper */
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

	/** @var Schema|MockObject */
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
		$this->unifiedObjectMapper = $this->createMock(UnifiedObjectMapper::class);
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

		// UnifiedObjectMapper/ObjectEntityMapper update pass-through.
		$this->unifiedObjectMapper->method('update')
			->willReturnCallback(function ($entity) {
				return $entity;
			});
		$this->objectEntityMapper->method('update')
			->willReturnCallback(function ($entity) {
				return $entity;
			});

		// Create SaveObject instance.
		$this->saveObject = new SaveObject(
			objectEntityMapper: $this->objectEntityMapper,
			unifiedObjectMapper: $this->unifiedObjectMapper,
			metaHydrationHandler: $this->metaHydrationHandler,
			filePropertyHandler: $this->filePropertyHandler,
			userSession: $this->userSession,
			auditTrailMapper: $this->auditTrailMapper,
			schemaMapper: $this->schemaMapper,
			registerMapper: $this->registerMapper,
			urlGenerator: $this->urlGenerator,
			organisationService: $this->organisationService,
			cacheHandler: $this->cacheHandler,
			settingsService: $this->settingsService,
			propertyRbacHandler: $this->propertyRbacHandler,
			logger: $this->logger,
			arrayLoader: new ArrayLoader(),
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
			'@self' => ['id' => $uuid],
			'name' => 'Test Object'
		];

		[$extractedUuid, $selfData, $extractedData] = $this->invokePrivateMethod(
			methodName: 'extractUuidAndSelfData',
			parameters: [$data, null, null]
		);

		$this->assertEquals($uuid, $extractedUuid, 'UUID should be extracted from @self.id.');
		$this->assertArrayNotHasKey('@self', $extractedData, '@self should be removed from data.');
		$this->assertEquals('Test Object', $extractedData['name'], 'Other data should be preserved.');
		$this->assertEquals(['id' => $uuid], $selfData, 'selfData should contain @self value.');
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

		[$extractedUuid, $selfData, $extractedData] = $this->invokePrivateMethod(
			methodName: 'extractUuidAndSelfData',
			parameters: [$data, null, null]
		);

		$this->assertEquals($uuid, $extractedUuid, 'UUID should be extracted from id field.');
		$this->assertArrayNotHasKey('id', $extractedData, 'id should be removed from data.');
		$this->assertEquals('Test Object', $extractedData['name'], 'Other data should be preserved.');
		$this->assertEmpty($selfData, 'selfData should be empty array when no @self provided.');
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

		[$extractedUuid, $selfData, $extractedData] = $this->invokePrivateMethod(
			methodName: 'extractUuidAndSelfData',
			parameters: [$data, $explicitUuid, null]
		);

		$this->assertEquals($explicitUuid, $extractedUuid, 'Explicit UUID parameter should take precedence.');
		$this->assertArrayNotHasKey('id', $extractedData, 'id should still be removed from data.');
	}

	/**
	 * Test extractUuidAndSelfData without UUID generates new one.
	 *
	 * @return void
	 */
	public function testExtractUuidAndSelfDataReturnsNullUuidWhenNotProvided(): void
	{
		$data = ['name' => 'Test Object'];

		[$extractedUuid, $selfData, $extractedData] = $this->invokePrivateMethod(
			methodName: 'extractUuidAndSelfData',
			parameters: [$data, null, null]
		);

		$this->assertNull($extractedUuid, 'UUID should be null when not provided (saveObject generates it later).');
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
		[$schema, $schemaId, $register, $registerId] = $this->invokePrivateMethod(
			methodName: 'resolveSchemaAndRegister',
			parameters: [$this->mockSchema, $this->mockRegister]
		);

		$this->assertSame($this->mockSchema, $schema, 'Schema should be returned as-is.');
		$this->assertEquals(1, $schemaId, 'Schema ID should match.');
		$this->assertSame($this->mockRegister, $register, 'Register should be returned as-is.');
		$this->assertEquals(1, $registerId, 'Register ID should match.');
	}

	/**
	 * Test resolveSchemaAndRegister with integer register ID.
	 *
	 * @return void
	 */
	public function testResolveSchemaAndRegisterWithIntegerId(): void
	{
		$this->registerMapper
			->method('find')
			->willReturn($this->mockRegister);

		$this->schemaMapper
			->method('find')
			->willReturn($this->mockSchema);

		[$schema, $schemaId, $register, $registerId] = $this->invokePrivateMethod(
			methodName: 'resolveSchemaAndRegister',
			parameters: [10, 42]
		);

		$this->assertSame($this->mockSchema, $schema, 'Schema should be resolved by ID.');
		$this->assertEquals(10, $schemaId, 'Schema ID should match the input.');
		$this->assertSame($this->mockRegister, $register, 'Register should be resolved by ID.');
		$this->assertEquals(42, $registerId, 'Register ID should match the input.');
	}

	/**
	 * Test resolveSchemaAndRegister with string register slug.
	 *
	 * @return void
	 */
	public function testResolveSchemaAndRegisterWithNullRegister(): void
	{
		// When register is null, it should remain null (e.g., for seedData objects).
		[$schema, $schemaId, $register, $registerId] = $this->invokePrivateMethod(
			methodName: 'resolveSchemaAndRegister',
			parameters: [$this->mockSchema, null]
		);

		$this->assertSame($this->mockSchema, $schema, 'Schema should be returned as-is.');
		$this->assertEquals(1, $schemaId, 'Schema ID should match.');
		$this->assertNull($register, 'Register should be null when input is null.');
		$this->assertNull($registerId, 'Register ID should be null when input is null.');
	}

	/**
	 * Test resolveSchemaAndRegister extracts from data when not provided.
	 *
	 * @return void
	 */
	public function testResolveSchemaAndRegisterWithStringThrowsOnInvalidReference(): void
	{
		// When a string reference cannot be resolved, an exception is thrown.
		$this->schemaMapper
			->method('findAll')
			->willReturn([]);
		$this->schemaMapper
			->method('find')
			->willThrowException(new DoesNotExistException('Not found'));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Could not resolve schema reference');

		$this->invokePrivateMethod(
			methodName: 'resolveSchemaAndRegister',
			parameters: ['nonexistent-schema', $this->mockRegister]
		);
	}

	/**
	 * Test resolveSchemaAndRegister throws exception when register not found.
	 *
	 * @return void
	 */
	public function testResolveSchemaAndRegisterThrowsExceptionForInvalidRegisterString(): void
	{
		// When a string register reference cannot be resolved, an exception is thrown.
		$this->registerMapper
			->method('findAll')
			->willReturn([]);
		$this->registerMapper
			->method('find')
			->willThrowException(new DoesNotExistException('Not found'));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Could not resolve register reference');

		$this->invokePrivateMethod(
			methodName: 'resolveSchemaAndRegister',
			parameters: [$this->mockSchema, 'nonexistent-register']
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
	public function testFindAndValidateExistingObjectWithRegisterAndSchema(): void
	{
		$uuid = Uuid::v4()->toRfc4122();
		$existingObject = new ObjectEntity();
		$existingObject->setUuid($uuid);
		$existingObject->setId(456);

		$this->objectEntityMapper
			->expects($this->once())
			->method('find')
			->willReturn($existingObject);

		$result = $this->invokePrivateMethod(
			methodName: 'findAndValidateExistingObject',
			parameters: [$uuid, $this->mockRegister, $this->mockSchema, true, true]
		);

		$this->assertSame($existingObject, $result, 'Should return existing object with register and schema context.');
	}

	// ==================== clearImageMetadataIfFileProperty() Tests ====================

	/**
	 * Test clearImageMetadataIfFileProperty clears image when image field is a file property.
	 *
	 * @return void
	 */
	public function testClearImageMetadataIfFilePropertyClearsImage(): void
	{
		// Set up schema with objectImageField pointing to a file property.
		$this->mockSchema
			->method('getConfiguration')
			->willReturn(['objectImageField' => 'avatar']);
		$this->mockSchema
			->method('getProperties')
			->willReturn([
				'avatar' => [
					'type' => 'file'
				]
			]);

		$entity = new ObjectEntity();
		$entity->setImage('http://example.com/old-image.png');

		$this->invokePrivateMethod(
			methodName: 'clearImageMetadataIfFileProperty',
			parameters: [$entity, $this->mockSchema]
		);

		$this->assertNull($entity->getImage(), 'Image should be cleared when image field is a file property.');
	}

	/**
	 * Test clearImageMetadataIfFileProperty preserves image for non-file properties.
	 *
	 * @return void
	 */
	public function testClearImageMetadataIfFilePropertyPreservesNonFileImage(): void
	{
		// Set up schema with objectImageField pointing to a non-file property.
		$this->mockSchema
			->method('getConfiguration')
			->willReturn(['objectImageField' => 'avatar']);
		$this->mockSchema
			->method('getProperties')
			->willReturn([
				'avatar' => [
					'type' => 'string'
				]
			]);

		$entity = new ObjectEntity();
		$entity->setImage('http://example.com/image.png');

		$this->invokePrivateMethod(
			methodName: 'clearImageMetadataIfFileProperty',
			parameters: [$entity, $this->mockSchema]
		);

		$this->assertEquals('http://example.com/image.png', $entity->getImage(), 'Image should be preserved for non-file properties.');
	}

	/**
	 * Test clearImageMetadataIfFileProperty handles no objectImageField config.
	 *
	 * @return void
	 */
	public function testClearImageMetadataIfFilePropertyHandlesNoConfig(): void
	{
		// Set up schema without objectImageField.
		$this->mockSchema
			->method('getConfiguration')
			->willReturn([]);
		$this->mockSchema
			->method('getProperties')
			->willReturn([]);

		$entity = new ObjectEntity();
		$entity->setImage('http://example.com/image.png');

		$this->invokePrivateMethod(
			methodName: 'clearImageMetadataIfFileProperty',
			parameters: [$entity, $this->mockSchema]
		);

		$this->assertEquals('http://example.com/image.png', $entity->getImage(), 'Image should be preserved when no objectImageField configured.');
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

		// Configure schema mock for full saveObject flow.
		$this->mockSchema
			->method('getSchemaObject')
			->willReturn((object)[
				'properties' => new \stdClass()
			]);
		$this->mockSchema
			->method('getProperties')
			->willReturn([]);
		$this->mockSchema
			->method('getConfiguration')
			->willReturn(null);

		// Mock that object doesn't exist (create scenario).
		$this->objectEntityMapper
			->method('find')
			->willThrowException(new DoesNotExistException('Object not found.'));

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
		$resultObject = $result->getObject();
		unset($resultObject['id']);
		$this->assertEquals($data, $resultObject, 'Data should be preserved.');
	}
}


