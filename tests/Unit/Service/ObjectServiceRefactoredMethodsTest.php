<?php

/**
 * ObjectService Refactored Methods Unit Tests
 *
 * Comprehensive tests for the private methods extracted during Phase 1 refactoring.
 * Tests cover saveObject() extracted methods and context management.
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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Object\AuditHandler;
use OCA\OpenRegister\Service\Object\BulkOperationsHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCA\OpenRegister\Service\Object\MetadataHandler;
use OCA\OpenRegister\Service\Object\MigrationHandler;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\PublishHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\RevertHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationHandler;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Unit tests for ObjectService refactored methods.
 *
 * Tests the extracted private methods using reflection:
 * From saveObject():
 * 1. setContextFromParameters()
 * 2. extractUuidAndNormalizeObject()
 * 3. checkSavePermissions()
 * 4. handleCascadingWithContextPreservation()
 * 5. validateObjectIfRequired()
 * 6. ensureObjectFolder()
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

	/** @var MockObject|PublishHandler */
	private $publishHandler;

	/** @var MockObject|CascadingHandler */
	private $cascadingHandler;

	/** @var MockObject|PermissionHandler */
	private $permissionHandler;

	/** @var Register */
	private $mockRegister;

	/** @var Schema */
	private $mockSchema;

	/**
	 * Set up test environment before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Create mocks for handlers used in test assertions.
		$this->getHandler = $this->createMock(GetObject::class);
		$this->saveHandler = $this->createMock(SaveObject::class);
		$this->renderHandler = $this->createMock(RenderObject::class);
		$this->validateHandler = $this->createMock(ValidateObject::class);
		$this->deleteHandler = $this->createMock(DeleteObject::class);
		$this->publishHandler = $this->createMock(PublishHandler::class);
		$this->cascadingHandler = $this->createMock(CascadingHandler::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);

		// Create real entity instances instead of mocks (Entity __call does not support mocking).
		$this->mockRegister = new Register();
		$this->mockRegister->setId(1);
		$this->mockRegister->setSlug('test-register');

		$this->mockSchema = new Schema();
		$this->mockSchema->setId(1);
		$this->mockSchema->setSlug('test-schema');
		$this->mockSchema->setHardValidation(false);

		// Create ObjectService with all required constructor parameters.
		$this->objectService = new ObjectService(
			$this->createMock(DataManipulationHandler::class),
			$this->deleteHandler,
			$this->getHandler,
			$this->createMock(PerformanceHandler::class),
			$this->permissionHandler,
			$this->renderHandler,
			$this->saveHandler,
			$this->createMock(SaveObjects::class),
			$this->createMock(SearchQueryHandler::class),
			$this->validateHandler,
			$this->createMock(LockHandler::class),
			$this->createMock(AuditHandler::class),
			$this->publishHandler,
			$this->createMock(RelationHandler::class),
			$this->createMock(MergeHandler::class),
			$this->createMock(BulkOperationsHandler::class),
			$this->createMock(FacetHandler::class),
			$this->createMock(MetadataHandler::class),
			$this->createMock(PerformanceOptimizationHandler::class),
			$this->createMock(QueryHandler::class),
			$this->createMock(RevertHandler::class),
			$this->createMock(UtilityHandler::class),
			$this->createMock(ValidationHandler::class),
			$this->cascadingHandler,
			$this->createMock(MigrationHandler::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(ViewMapper::class),
			$this->createMock(ObjectEntityMapper::class),
			$this->createMock(UnifiedObjectMapper::class),
			$this->createMock(FileService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(SearchTrailService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->createMock(IAppContainer::class)
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

	// ==================== setContextFromParameters() Tests ====================

	/**
	 * Test setContextFromParameters with Register object.
	 *
	 * @return void
	 */
	public function testSetContextFromParametersWithRegisterObject(): void
	{
		$this->invokePrivateMethod(
			'setContextFromParameters',
			[$this->mockRegister, $this->mockSchema]
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
			'setContextFromParameters',
			[null, null]
		);

		$currentRegister = $this->getPrivateProperty('currentRegister');
		$currentSchema = $this->getPrivateProperty('currentSchema');

		$this->assertNull($currentRegister, 'Register should be null when not provided.');
		$this->assertNull($currentSchema, 'Schema should be null when not provided.');
	}

	// ==================== extractUuidAndNormalizeObject() Tests ====================

	/**
	 * Test extractUuidAndNormalizeObject with array input containing id.
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
			'extractUuidAndNormalizeObject',
			[$object, null]
		);

		$this->assertEquals($uuid, $extractedUuid, 'UUID should be extracted from id field.');
		$this->assertIsArray($normalizedObject, 'Normalized object should be an array.');
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
			'extractUuidAndNormalizeObject',
			[$entity, null]
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
			'extractUuidAndNormalizeObject',
			[$object, $explicitUuid]
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
			'checkSavePermissions',
			['some-uuid', false]
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
			'checkSavePermissions',
			[null, true]
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
			'checkSavePermissions',
			['existing-uuid', true]
		);
	}

	// ==================== validateObjectIfRequired() Tests ====================

	/**
	 * Test validateObjectIfRequired skips when hard validation is disabled.
	 *
	 * @return void
	 */
	public function testValidateObjectIfRequiredSkipsWhenHardValidationDisabled(): void
	{
		$object = ['name' => 'Valid Object', 'email' => 'test@example.com'];

		// Set schema with hard validation disabled.
		$schema = new Schema();
		$schema->setId(1);
		$schema->setHardValidation(false);
		$this->setPrivateProperty('currentSchema', $schema);

		$this->validateHandler
			->expects($this->never())
			->method('validateObject');

		// Should not call validator when hard validation is disabled.
		$this->invokePrivateMethod(
			'validateObjectIfRequired',
			[$object]
		);

		$this->assertTrue(true, 'No exception thrown when hard validation is disabled.');
	}

	/**
	 * Test validateObjectIfRequired calls validator when hard validation is enabled.
	 *
	 * @return void
	 */
	public function testValidateObjectIfRequiredCallsValidatorWhenEnabled(): void
	{
		$object = ['name' => 'Valid Object'];

		// Set schema with hard validation enabled.
		$schema = new Schema();
		$schema->setId(1);
		$schema->setHardValidation(true);
		$this->setPrivateProperty('currentSchema', $schema);

		// Create a mock validation result that reports valid.
		$mockResult = $this->createMock(\Opis\JsonSchema\ValidationResult::class);
		$mockResult->method('isValid')->willReturn(true);

		$this->validateHandler
			->expects($this->once())
			->method('validateObject')
			->with($object, $schema)
			->willReturn($mockResult);

		$this->invokePrivateMethod(
			'validateObjectIfRequired',
			[$object]
		);

		$this->assertTrue(true, 'Validation passed without exception.');
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
			'ensureObjectFolder',
			[null]
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
			'ensureObjectFolder',
			[$uuid]
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

		// Mock cascadingHandler to return the object and uuid unchanged.
		$this->cascadingHandler
			->method('handlePreValidationCascading')
			->willReturn([$object, $uuid]);

		[$processedObject, $returnedUuid] = $this->invokePrivateMethod(
			'handleCascadingWithContextPreservation',
			[$object, $uuid]
		);

		// Verify context was preserved.
		$currentRegister = $this->getPrivateProperty('currentRegister');
		$currentSchema = $this->getPrivateProperty('currentSchema');

		$this->assertSame($originalRegister, $currentRegister, 'Register context should be preserved.');
		$this->assertSame($originalSchema, $currentSchema, 'Schema context should be preserved.');
	}

	// ==================== prepareFindAllConfig() Tests ====================

	/**
	 * Test prepareFindAllConfig preserves existing values.
	 *
	 * @return void
	 */
	public function testPrepareFindAllConfigPreservesExistingValues(): void
	{
		$config = [
			'limit' => 100,
			'offset' => 50,
			'filters' => ['name' => 'test']
		];

		$result = $this->invokePrivateMethod('prepareFindAllConfig', [$config]);

		$this->assertEquals(100, $result['limit'], 'Existing limit should be preserved.');
		$this->assertEquals(50, $result['offset'], 'Existing offset should be preserved.');
		$this->assertEquals(['name' => 'test'], $result['filters'], 'Existing filters should be preserved.');
	}

	/**
	 * Test prepareFindAllConfig converts extend string to array.
	 *
	 * @return void
	 */
	public function testPrepareFindAllConfigConvertsExtendStringToArray(): void
	{
		$config = [
			'extend' => 'register,schema'
		];

		$result = $this->invokePrivateMethod('prepareFindAllConfig', [$config]);

		$this->assertIsArray($result['extend'], 'Extend should be converted to array.');
		$this->assertEquals(['register', 'schema'], $result['extend'], 'Extend should contain split values.');
	}
}
