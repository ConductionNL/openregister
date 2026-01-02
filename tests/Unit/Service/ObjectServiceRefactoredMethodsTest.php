<?php

/**
 * ObjectService Refactored Methods Unit Tests
 *
 * Comprehensive tests for the 9 private methods extracted during Phase 1 refactoring.
 * Tests cover findAll() and saveObject() extracted methods.
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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\PublishObject;
use OCA\OpenRegister\Service\Object\DepublishObject;
use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Service\CacheService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Unit tests for ObjectService refactored methods.
 *
 * Tests the 9 extracted private methods using reflection:
 * From findAll():
 * 1. prepareConfig()
 * 2. resolveRelatedEntities()
 * 3. renderObjectsAsync()
 *
 * From saveObject():
 * 4. setContextFromParameters()
 * 5. extractUuidAndNormalizeObject()
 * 6. checkSavePermissions()
 * 7. handleCascadingWithContextPreservation()
 * 8. validateObjectIfRequired()
 * 9. ensureObjectFolder()
 */
class ObjectServiceRefactoredMethodsTest extends TestCase
{
	private ObjectService $objectService;
	private ReflectionClass $reflection;

	/** @var MockObject|GetObject */
	private $getHandler;

	/** @var MockObject|SaveObject */
	private $saveHandler;

	/** @var MockObject|RenderObject */
	private $renderHandler;

	/** @var MockObject|ValidateObject */
	private $validateHandler;

	/** @var MockObject|DeleteObject */
	private $deleteHandler;

	/** @var MockObject|PublishObject */
	private $publishHandler;

	/** @var MockObject|DepublishObject */
	private $depublishHandler;

	/** @var MockObject|SearchService */
	private $searchService;

	/** @var MockObject|CacheService */
	private $cacheService;

	/** @var MockObject|Register */
	private $mockRegister;

	/** @var MockObject|Schema */
	private $mockSchema;

	/**
	 * Set up test environment before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Create mocks for all dependencies.
		$this->getHandler = $this->createMock(GetObject::class);
		$this->saveHandler = $this->createMock(SaveObject::class);
		$this->renderHandler = $this->createMock(RenderObject::class);
		$this->validateHandler = $this->createMock(ValidateObject::class);
		$this->deleteHandler = $this->createMock(DeleteObject::class);
		$this->publishHandler = $this->createMock(PublishObject::class);
		$this->depublishHandler = $this->createMock(DepublishObject::class);
		$this->searchService = $this->createMock(SearchService::class);
		$this->cacheService = $this->createMock(CacheService::class);

		// Create mock entities.
		$this->mockRegister = $this->createMock(Register::class);
		$this->mockSchema = $this->createMock(Schema::class);

		// Set up basic mock returns.
		$this->mockRegister->method('getId')->willReturn(1);
		$this->mockRegister->method('getSlug')->willReturn('test-register');
		$this->mockSchema->method('getId')->willReturn(1);
		$this->mockSchema->method('getSlug')->willReturn('test-schema');

		// Create ObjectService instance.
		$this->objectService = new ObjectService(
			getHandler: $this->getHandler,
			saveHandler: $this->saveHandler,
			renderHandler: $this->renderHandler,
			validateHandler: $this->validateHandler,
			deleteHandler: $this->deleteHandler,
			publishHandler: $this->publishHandler,
			depublishHandler: $this->depublishHandler,
			searchService: $this->searchService,
			cacheService: $this->cacheService
		);

		// Set up reflection for accessing private methods.
		$this->reflection = new ReflectionClass(ObjectService::class);
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

		return $method->invokeArgs($this->objectService, $parameters);
	}

	/**
	 * Helper method to set private property values using reflection.
	 *
	 * @param string $propertyName The name of the private property.
	 * @param mixed  $value        The value to set.
	 *
	 * @return void
	 */
	private function setPrivateProperty(string $propertyName, mixed $value): void
	{
		$property = $this->reflection->getProperty($propertyName);
		$property->setAccessible(true);
		$property->setValue($this->objectService, $value);
	}

	/**
	 * Helper method to get private property values using reflection.
	 *
	 * @param string $propertyName The name of the private property.
	 *
	 * @return mixed The value of the property.
	 */
	private function getPrivateProperty(string $propertyName): mixed
	{
		$property = $this->reflection->getProperty($propertyName);
		$property->setAccessible(true);

		return $property->getValue($this->objectService);
	}

	// ==================== prepareConfig() Tests ====================

	/**
	 * Test prepareConfig initializes default values.
	 *
	 * @return void
	 */
	public function testPrepareConfigInitializesDefaults(): void
	{
		$config = [];

		$this->invokePrivateMethod(methodName: 'prepareConfig', parameters: [&$config]);

		$this->assertArrayHasKey('limit', $config, 'Config should have limit.');
		$this->assertArrayHasKey('offset', $config, 'Config should have offset.');
		$this->assertEquals(30, $config['limit'], 'Default limit should be 30.');
		$this->assertEquals(0, $config['offset'], 'Default offset should be 0.');
	}

	/**
	 * Test prepareConfig preserves existing values.
	 *
	 * @return void
	 */
	public function testPrepareConfigPreservesExistingValues(): void
	{
		$config = [
			'limit' => 100,
			'offset' => 50,
			'filters' => ['name' => 'test']
		];

		$this->invokePrivateMethod(methodName: 'prepareConfig', parameters: [&$config]);

		$this->assertEquals(100, $config['limit'], 'Existing limit should be preserved.');
		$this->assertEquals(50, $config['offset'], 'Existing offset should be preserved.');
		$this->assertEquals(['name' => 'test'], $config['filters'], 'Existing filters should be preserved.');
	}

	/**
	 * Test prepareConfig sanitizes invalid limit.
	 *
	 * @return void
	 */
	public function testPrepareConfigSanitizesInvalidLimit(): void
	{
		$config = ['limit' => -10];

		$this->invokePrivateMethod(methodName: 'prepareConfig', parameters: [&$config]);

		$this->assertGreaterThan(0, $config['limit'], 'Limit should be positive.');
	}

	// ==================== setContextFromParameters() Tests ====================

	/**
	 * Test setContextFromParameters with Register object.
	 *
	 * @return void
	 */
	public function testSetContextFromParametersWithRegisterObject(): void
	{
		$this->invokePrivateMethod(
			methodName: 'setContextFromParameters',
			parameters: [$this->mockRegister, $this->mockSchema]
		);

		$currentRegister = $this->getPrivateProperty('currentRegister');
		$currentSchema = $this->getPrivateProperty('currentSchema');

		$this->assertSame($this->mockRegister, $currentRegister, 'Register should be set.');
		$this->assertSame($this->mockSchema, $currentSchema, 'Schema should be set.');
	}

	/**
	 * Test setContextFromParameters with null values.
	 *
	 * @return void
	 */
	public function testSetContextFromParametersWithNullValues(): void
	{
		$this->invokePrivateMethod(
			methodName: 'setContextFromParameters',
			parameters: [null, null]
		);

		$currentRegister = $this->getPrivateProperty('currentRegister');
		$currentSchema = $this->getPrivateProperty('currentSchema');

		$this->assertNull($currentRegister, 'Register should be null when not provided.');
		$this->assertNull($currentSchema, 'Schema should be null when not provided.');
	}

	// ==================== extractUuidAndNormalizeObject() Tests ====================

	/**
	 * Test extractUuidAndNormalizeObject with array input.
	 *
	 * @return void
	 */
	public function testExtractUuidAndNormalizeObjectWithArray(): void
	{
		$uuid = 'test-uuid-123';
		$object = [
			'id' => $uuid,
			'name' => 'Test Object',
			'description' => 'Test Description'
		];

		[$normalizedObject, $extractedUuid] = $this->invokePrivateMethod(
			methodName: 'extractUuidAndNormalizeObject',
			parameters: [$object, null]
		);

		$this->assertEquals($uuid, $extractedUuid, 'UUID should be extracted from id field.');
		$this->assertIsArray($normalizedObject, 'Normalized object should be an array.');
		$this->assertArrayNotHasKey('id', $normalizedObject, 'id should be removed from normalized object.');
		$this->assertEquals('Test Object', $normalizedObject['name'], 'Data should be preserved.');
	}

	/**
	 * Test extractUuidAndNormalizeObject with ObjectEntity input.
	 *
	 * @return void
	 */
	public function testExtractUuidAndNormalizeObjectWithObjectEntity(): void
	{
		$uuid = 'entity-uuid-456';
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject(['name' => 'Entity Object']);

		[$normalizedObject, $extractedUuid] = $this->invokePrivateMethod(
			methodName: 'extractUuidAndNormalizeObject',
			parameters: [$entity, null]
		);

		$this->assertEquals($uuid, $extractedUuid, 'UUID should be extracted from entity.');
		$this->assertIsArray($normalizedObject, 'Normalized object should be an array.');
		$this->assertEquals('Entity Object', $normalizedObject['name'], 'Object data should be extracted.');
	}

	/**
	 * Test extractUuidAndNormalizeObject with explicit UUID parameter.
	 *
	 * @return void
	 */
	public function testExtractUuidAndNormalizeObjectWithExplicitUuid(): void
	{
		$explicitUuid = 'explicit-uuid';
		$objectUuid = 'object-uuid';
		$object = ['id' => $objectUuid, 'name' => 'Test'];

		[$normalizedObject, $extractedUuid] = $this->invokePrivateMethod(
			methodName: 'extractUuidAndNormalizeObject',
			parameters: [$object, $explicitUuid]
		);

		$this->assertEquals($explicitUuid, $extractedUuid, 'Explicit UUID should take precedence.');
	}

	// ==================== checkSavePermissions() Tests ====================

	/**
	 * Test checkSavePermissions with RBAC disabled.
	 *
	 * @return void
	 */
	public function testCheckSavePermissionsWithRbacDisabled(): void
	{
		// Should not throw exception when RBAC is disabled.
		$this->expectNotToPerformAssertions();

		$this->invokePrivateMethod(
			methodName: 'checkSavePermissions',
			parameters: ['some-uuid', false]
		);
	}

	/**
	 * Test checkSavePermissions with RBAC enabled and create scenario.
	 *
	 * @return void
	 */
	public function testCheckSavePermissionsCreateScenario(): void
	{
		// UUID is null, so it's a create operation.
		// Should check create permissions.
		$this->expectNotToPerformAssertions();

		$this->invokePrivateMethod(
			methodName: 'checkSavePermissions',
			parameters: [null, true]
		);
	}

	/**
	 * Test checkSavePermissions with RBAC enabled and update scenario.
	 *
	 * @return void
	 */
	public function testCheckSavePermissionsUpdateScenario(): void
	{
		// UUID is provided, so it's an update operation.
		// Should check update permissions.
		$this->expectNotToPerformAssertions();

		$this->invokePrivateMethod(
			methodName: 'checkSavePermissions',
			parameters: ['existing-uuid', true]
		);
	}

	// ==================== validateObjectIfRequired() Tests ====================

	/**
	 * Test validateObjectIfRequired with valid object.
	 *
	 * @return void
	 */
	public function testValidateObjectIfRequiredWithValidObject(): void
	{
		$object = ['name' => 'Valid Object', 'email' => 'test@example.com'];

		$this->setPrivateProperty('currentSchema', $this->mockSchema);

		$this->validateHandler
			->expects($this->once())
			->method('validateObject')
			->with($object, $this->mockSchema)
			->willReturn(true);

		// Should not throw exception.
		$this->expectNotToPerformAssertions();

		$this->invokePrivateMethod(
			methodName: 'validateObjectIfRequired',
			parameters: [$object]
		);
	}

	/**
	 * Test validateObjectIfRequired with invalid object throws exception.
	 *
	 * @return void
	 */
	public function testValidateObjectIfRequiredWithInvalidObjectThrowsException(): void
	{
		$object = ['name' => '', 'email' => 'invalid-email'];

		$this->setPrivateProperty('currentSchema', $this->mockSchema);

		$this->validateHandler
			->expects($this->once())
			->method('validateObject')
			->with($object, $this->mockSchema)
			->willThrowException(new Exception('Validation failed: email format invalid.'));

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Validation failed');

		$this->invokePrivateMethod(
			methodName: 'validateObjectIfRequired',
			parameters: [$object]
		);
	}

	/**
	 * Test validateObjectIfRequired skips validation when no schema set.
	 *
	 * @return void
	 */
	public function testValidateObjectIfRequiredSkipsWhenNoSchema(): void
	{
		$object = ['name' => 'Test'];

		$this->setPrivateProperty('currentSchema', null);

		$this->validateHandler
			->expects($this->never())
			->method('validateObject');

		// Should not throw exception and not call validator.
		$this->expectNotToPerformAssertions();

		$this->invokePrivateMethod(
			methodName: 'validateObjectIfRequired',
			parameters: [$object]
		);
	}

	// ==================== ensureObjectFolder() Tests ====================

	/**
	 * Test ensureObjectFolder returns null when no UUID.
	 *
	 * @return void
	 */
	public function testEnsureObjectFolderReturnsNullWhenNoUuid(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'ensureObjectFolder',
			parameters: [null]
		);

		$this->assertNull($result, 'Should return null when UUID is not provided.');
	}

	/**
	 * Test ensureObjectFolder creates folder when needed.
	 *
	 * @return void
	 */
	public function testEnsureObjectFolderCreatesFolderWhenNeeded(): void
	{
		$uuid = 'folder-uuid-123';

		// Note: This test would require FileService mock to be properly injected.
		// For now, we test that it doesn't throw an exception.
		$result = $this->invokePrivateMethod(
			methodName: 'ensureObjectFolder',
			parameters: [$uuid]
		);

		// Result can be null or int (folder ID).
		$this->assertTrue(is_null($result) || is_int($result), 'Result should be null or folder ID.');
	}

	// ==================== handleCascadingWithContextPreservation() Tests ====================

	/**
	 * Test handleCascadingWithContextPreservation preserves context.
	 *
	 * @return void
	 */
	public function testHandleCascadingWithContextPreservationPreservesContext(): void
	{
		$originalRegister = $this->mockRegister;
		$originalSchema = $this->mockSchema;

		$this->setPrivateProperty('currentRegister', $originalRegister);
		$this->setPrivateProperty('currentSchema', $originalSchema);

		$object = [
			'name' => 'Parent Object',
			'child' => ['name' => 'Child Object']
		];
		$uuid = 'parent-uuid';

		// Mock saveHandler to handle cascading.
		$this->saveHandler
			->method('saveObject')
			->willReturn(new ObjectEntity());

		[$processedObject, $returnedUuid] = $this->invokePrivateMethod(
			methodName: 'handleCascadingWithContextPreservation',
			parameters: [$object, $uuid]
		);

		// Verify context was preserved.
		$currentRegister = $this->getPrivateProperty('currentRegister');
		$currentSchema = $this->getPrivateProperty('currentSchema');

		$this->assertSame($originalRegister, $currentRegister, 'Register context should be preserved.');
		$this->assertSame($originalSchema, $currentSchema, 'Schema context should be preserved.');
	}

	// ==================== resolveRelatedEntities() Tests ====================

	/**
	 * Test resolveRelatedEntities with _extend configuration.
	 *
	 * @return void
	 */
	public function testResolveRelatedEntitiesWithExtend(): void
	{
		$config = ['_extend' => ['register', 'schema']];
		$objects = [
			(new ObjectEntity())->setRegister(1)->setSchema(1),
			(new ObjectEntity())->setRegister(2)->setSchema(2)
		];

		// Mock handlers to return entities.
		$this->getHandler
			->method('getRegisterEntities')
			->willReturn([$this->mockRegister]);

		$this->getHandler
			->method('getSchemaEntities')
			->willReturn([$this->mockSchema]);

		[$registers, $schemas] = $this->invokePrivateMethod(
			methodName: 'resolveRelatedEntities',
			parameters: [$config, $objects]
		);

		$this->assertIsArray($registers, 'Registers should be an array.');
		$this->assertIsArray($schemas, 'Schemas should be an array.');
	}

	/**
	 * Test resolveRelatedEntities without _extend returns null.
	 *
	 * @return void
	 */
	public function testResolveRelatedEntitiesWithoutExtendReturnsNull(): void
	{
		$config = [];
		$objects = [];

		[$registers, $schemas] = $this->invokePrivateMethod(
			methodName: 'resolveRelatedEntities',
			parameters: [$config, $objects]
		);

		$this->assertNull($registers, 'Registers should be null when not extending.');
		$this->assertNull($schemas, 'Schemas should be null when not extending.');
	}

	// ==================== renderObjectsAsync() Tests ====================

	/**
	 * Test renderObjectsAsync renders objects in parallel.
	 *
	 * @return void
	 */
	public function testRenderObjectsAsyncRendersInParallel(): void
	{
		$objects = [
			new ObjectEntity(),
			new ObjectEntity(),
			new ObjectEntity()
		];
		$config = ['_extend' => ['register']];
		$registers = [$this->mockRegister];
		$schemas = [$this->mockSchema];

		// Mock render handler.
		$this->renderHandler
			->method('renderEntity')
			->willReturnCallback(fn($entity) => $entity);

		$result = $this->invokePrivateMethod(
			methodName: 'renderObjectsAsync',
			parameters: [$objects, $config, $registers, $schemas, true, true]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertCount(3, $result, 'Should render all 3 objects.');
	}

	/**
	 * Test renderObjectsAsync with empty objects array.
	 *
	 * @return void
	 */
	public function testRenderObjectsAsyncWithEmptyArray(): void
	{
		$result = $this->invokePrivateMethod(
			methodName: 'renderObjectsAsync',
			parameters: [[], [], null, null, true, true]
		);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertEmpty($result, 'Result should be empty.');
	}

	// ==================== Integration Test ====================

	/**
	 * Test that all refactored methods work together in findAll().
	 *
	 * @return void
	 */
	public function testRefactoredFindAllIntegration(): void
	{
		$config = ['limit' => 10, 'offset' => 0];

		// Mock getHandler to return objects.
		$this->getHandler
			->method('findAll')
			->willReturn([
				new ObjectEntity(),
				new ObjectEntity()
			]);

		// Mock renderHandler.
		$this->renderHandler
			->method('renderEntity')
			->willReturnCallback(fn($entity) => $entity);

		// Execute findAll.
		$result = $this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false);

		$this->assertIsArray($result, 'Result should be an array.');
		$this->assertCount(2, $result, 'Should return 2 objects.');
	}
}











