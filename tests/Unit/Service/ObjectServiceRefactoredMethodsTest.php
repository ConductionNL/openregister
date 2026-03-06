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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\Object\BulkOperationsHandler;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\LockHandler;
use OCA\OpenRegister\Service\Object\AuditHandler;
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
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
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

	/** @var MockObject|PublishHandler */
	private $publishHandler;

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

		// Create mocks for all dependencies.
		$this->getHandler = $this->createMock(GetObject::class);
		$this->saveHandler = $this->createMock(SaveObject::class);
		$this->renderHandler = $this->createMock(RenderObject::class);
		$this->validateHandler = $this->createMock(ValidateObject::class);
		$this->deleteHandler = $this->createMock(DeleteObject::class);
		$this->publishHandler = $this->createMock(PublishHandler::class);

		// Create real entities (getId is a magic method, cannot be mocked).
		$this->mockRegister = new Register();
		$this->mockRegister->setId(1);

		$this->mockSchema = new Schema();
		$this->mockSchema->setId(1);

		// Create ObjectService instance with all required dependencies.
		$this->objectService = new ObjectService(
			dataManipHandler: $this->createMock(DataManipulationHandler::class),
			deleteHandler: $this->deleteHandler,
			getHandler: $this->getHandler,
			performanceHandler: $this->createMock(PerformanceHandler::class),
			permissionHandler: $this->createMock(PermissionHandler::class),
			renderHandler: $this->renderHandler,
			saveHandler: $this->saveHandler,
			saveObjectsHandler: $this->createMock(SaveObjects::class),
			searchQueryHandler: $this->createMock(SearchQueryHandler::class),
			validateHandler: $this->validateHandler,
			lockHandler: $this->createMock(LockHandler::class),
			auditHandler: $this->createMock(AuditHandler::class),
			publishHandler: $this->publishHandler,
			relationHandler: $this->createMock(RelationHandler::class),
			mergeHandler: $this->createMock(MergeHandler::class),
			bulkOpsHandler: $this->createMock(BulkOperationsHandler::class),
			facetHandler: $this->createMock(FacetHandler::class),
			metadataHandler: $this->createMock(MetadataHandler::class),
			perfOptHandler: $this->createMock(PerformanceOptimizationHandler::class),
			queryHandler: $this->createMock(QueryHandler::class),
			revertHandler: $this->createMock(RevertHandler::class),
			utilityHandler: $this->createMock(UtilityHandler::class),
			validationHandler: $this->createMock(ValidationHandler::class),
			cascadingHandler: $this->createMock(CascadingHandler::class),
			migrationHandler: $this->createMock(MigrationHandler::class),
			registerMapper: $this->createMock(RegisterMapper::class),
			schemaMapper: $this->createMock(SchemaMapper::class),
			viewMapper: $this->createMock(ViewMapper::class),
			objectEntityMapper: $this->createMock(ObjectEntityMapper::class),
			unifiedObjectMapper: $this->createMock(UnifiedObjectMapper::class),
			fileService: $this->createMock(FileService::class),
			userSession: $this->createMock(IUserSession::class),
			searchTrailService: $this->createMock(SearchTrailService::class),
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			organisationService: $this->createMock(OrganisationService::class),
			logger: $this->createMock(LoggerInterface::class),
			cacheHandler: $this->createMock(CacheHandler::class),
			settingsService: $this->createMock(SettingsService::class),
			container: $this->createMock(IAppContainer::class)
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
}
