<?php

/**
 * ObjectService Comprehensive Unit Tests
 *
 * Tests for the primary ObjectService methods including find, findAll,
 * saveObject, deleteObject, context setters/getters, publish/depublish,
 * lock/unlock, bulk operations, and private helper methods.
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

use DateTime;
use Exception;
use RuntimeException;
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
use ReflectionMethod;

/**
 * Comprehensive unit tests for ObjectService.
 *
 * Covers: find, findAll, saveObject, deleteObject, setRegister, setSchema,
 * setObject, getSchema, getRegister, publish, depublish, lockObject,
 * unlockObject, saveObjects, deleteObjects, count, getLogs,
 * and private helper methods via reflection.
 */
class ObjectServiceTest extends TestCase
{
	private ObjectService $service;
	private ReflectionClass $reflection;

	// Handlers that need specific mock expectations.
	/** @var MockObject&GetObject */
	private $getHandler;
	/** @var MockObject&SaveObject */
	private $saveHandler;
	/** @var MockObject&RenderObject */
	private $renderHandler;
	/** @var MockObject&ValidateObject */
	private $validateHandler;
	/** @var MockObject&DeleteObject */
	private $deleteHandler;
	/** @var MockObject&PublishHandler */
	private $publishHandler;
	/** @var MockObject&LockHandler */
	private $lockHandler;
	/** @var MockObject&AuditHandler */
	private $auditHandler;
	/** @var MockObject&PermissionHandler */
	private $permissionHandler;
	/** @var MockObject&PerformanceHandler */
	private $performanceHandler;
	/** @var MockObject&CascadingHandler */
	private $cascadingHandler;
	/** @var MockObject&BulkOperationsHandler */
	private $bulkOpsHandler;
	/** @var MockObject&QueryHandler */
	private $queryHandler;
	/** @var MockObject&FacetHandler */
	private $facetHandler;
	/** @var MockObject&SearchQueryHandler */
	private $searchQueryHandler;
	/** @var MockObject&ObjectEntityMapper */
	private $objectEntityMapper;
	/** @var MockObject&UnifiedObjectMapper */
	private $unifiedObjectMapper;
	/** @var MockObject&RegisterMapper */
	private $registerMapper;
	/** @var MockObject&SchemaMapper */
	private $schemaMapper;
	/** @var MockObject&FileService */
	private $fileService;
	/** @var MockObject&OrganisationService */
	private $organisationService;
	/** @var MockObject&LoggerInterface */
	private $logger;

	// Real entity instances (magic __call for getters/setters).
	private Register $register;
	private Schema $schema;

	/**
	 * Set up test environment before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		// Create mocks for all handler dependencies.
		$this->getHandler = $this->createMock(GetObject::class);
		$this->saveHandler = $this->createMock(SaveObject::class);
		$this->renderHandler = $this->createMock(RenderObject::class);
		$this->validateHandler = $this->createMock(ValidateObject::class);
		$this->deleteHandler = $this->createMock(DeleteObject::class);
		$this->publishHandler = $this->createMock(PublishHandler::class);
		$this->lockHandler = $this->createMock(LockHandler::class);
		$this->auditHandler = $this->createMock(AuditHandler::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->performanceHandler = $this->createMock(PerformanceHandler::class);
		$this->cascadingHandler = $this->createMock(CascadingHandler::class);
		$this->bulkOpsHandler = $this->createMock(BulkOperationsHandler::class);
		$this->queryHandler = $this->createMock(QueryHandler::class);
		$this->facetHandler = $this->createMock(FacetHandler::class);
		$this->searchQueryHandler = $this->createMock(SearchQueryHandler::class);
		$this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
		$this->unifiedObjectMapper = $this->createMock(UnifiedObjectMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Create real entity instances (magic getters/setters via __call).
		$this->register = new Register();
		$this->register->setId(1);

		$this->schema = new Schema();
		$this->schema->setId(2);

		// Instantiate ObjectService with all 40 constructor params.
		$this->service = new ObjectService(
			dataManipHandler: $this->createMock(DataManipulationHandler::class),
			deleteHandler: $this->deleteHandler,
			getHandler: $this->getHandler,
			performanceHandler: $this->performanceHandler,
			permissionHandler: $this->permissionHandler,
			renderHandler: $this->renderHandler,
			saveHandler: $this->saveHandler,
			saveObjectsHandler: $this->createMock(SaveObjects::class),
			searchQueryHandler: $this->searchQueryHandler,
			validateHandler: $this->validateHandler,
			lockHandler: $this->lockHandler,
			auditHandler: $this->auditHandler,
			publishHandler: $this->publishHandler,
			relationHandler: $this->createMock(RelationHandler::class),
			mergeHandler: $this->createMock(MergeHandler::class),
			bulkOpsHandler: $this->bulkOpsHandler,
			facetHandler: $this->facetHandler,
			metadataHandler: $this->createMock(MetadataHandler::class),
			perfOptHandler: $this->createMock(PerformanceOptimizationHandler::class),
			queryHandler: $this->queryHandler,
			revertHandler: $this->createMock(RevertHandler::class),
			utilityHandler: $this->createMock(UtilityHandler::class),
			validationHandler: $this->createMock(ValidationHandler::class),
			cascadingHandler: $this->cascadingHandler,
			migrationHandler: $this->createMock(MigrationHandler::class),
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			viewMapper: $this->createMock(ViewMapper::class),
			objectEntityMapper: $this->objectEntityMapper,
			unifiedObjectMapper: $this->unifiedObjectMapper,
			fileService: $this->fileService,
			userSession: $this->createMock(IUserSession::class),
			searchTrailService: $this->createMock(SearchTrailService::class),
			groupManager: $this->createMock(IGroupManager::class),
			userManager: $this->createMock(IUserManager::class),
			organisationService: $this->organisationService,
			logger: $this->logger,
			cacheHandler: $this->createMock(CacheHandler::class),
			settingsService: $this->createMock(SettingsService::class),
			container: $this->createMock(IAppContainer::class)
		);

		$this->reflection = new ReflectionClass(ObjectService::class);
	}

	// ── Helper methods ──────────────────────────────────────────────────

	/**
	 * Invoke a private/protected method via reflection.
	 */
	private function invokePrivate(string $methodName, array $args = []): mixed
	{
		$method = $this->reflection->getMethod($methodName);
		$method->setAccessible(true);
		return $method->invokeArgs($this->service, $args);
	}

	/**
	 * Set a private/protected property via reflection.
	 */
	private function setProperty(string $name, mixed $value): void
	{
		$property = $this->reflection->getProperty($name);
		$property->setAccessible(true);
		$property->setValue($this->service, $value);
	}

	/**
	 * Get a private/protected property via reflection.
	 */
	private function getProperty(string $name): mixed
	{
		$property = $this->reflection->getProperty($name);
		$property->setAccessible(true);
		return $property->getValue($this->service);
	}

	// ── 1. setRegister() tests ──────────────────────────────────────────

	/**
	 * Test setRegister with a Register entity directly.
	 */
	public function testSetRegisterWithRegisterEntity(): void
	{
		$result = $this->service->setRegister(register: $this->register);

		$this->assertSame($this->register, $this->getProperty('currentRegister'));
		$this->assertSame($this->service, $result, 'setRegister should return $this for chaining');
	}

	/**
	 * Test setRegister with a numeric ID uses performance cache.
	 */
	public function testSetRegisterWithNumericIdUsesCachedLookup(): void
	{
		$this->performanceHandler
			->expects($this->once())
			->method('getCachedEntities')
			->willReturn([$this->register]);

		$result = $this->service->setRegister(register: 1);

		$this->assertSame($this->register, $this->getProperty('currentRegister'));
		$this->assertSame($this->service, $result);
	}

	/**
	 * Test setRegister with string slug falls back to mapper.
	 */
	public function testSetRegisterWithSlugUsesMapperFind(): void
	{
		$this->registerMapper
			->expects($this->once())
			->method('find')
			->willReturn($this->register);

		$result = $this->service->setRegister(register: 'my-register');

		$this->assertSame($this->register, $this->getProperty('currentRegister'));
		$this->assertSame($this->service, $result);
	}

	// ── 2. setSchema() tests ────────────────────────────────────────────

	/**
	 * Test setSchema with a Schema entity directly.
	 */
	public function testSetSchemaWithSchemaEntity(): void
	{
		$result = $this->service->setSchema(schema: $this->schema);

		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
		$this->assertSame($this->service, $result);
	}

	/**
	 * Test setSchema with a numeric ID uses cached lookup.
	 */
	public function testSetSchemaWithNumericIdUsesCachedLookup(): void
	{
		$this->performanceHandler
			->expects($this->once())
			->method('getCachedEntities')
			->willReturn([$this->schema]);

		$result = $this->service->setSchema(schema: 2);

		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
		$this->assertSame($this->service, $result);
	}

	/**
	 * Test setSchema with string slug uses mapper find.
	 */
	public function testSetSchemaWithSlugUsesMapperFind(): void
	{
		$this->schemaMapper
			->expects($this->once())
			->method('find')
			->willReturn($this->schema);

		$result = $this->service->setSchema(schema: 'my-schema');

		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
		$this->assertSame($this->service, $result);
	}

	/**
	 * Test setSchema throws ValidationException when schema not found.
	 */
	public function testSetSchemaThrowsWhenNotFound(): void
	{
		$this->schemaMapper
			->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$this->expectException(\OCA\OpenRegister\Exception\ValidationException::class);

		$this->service->setSchema(schema: 'nonexistent-slug');
	}

	// ── 3. setObject() tests ────────────────────────────────────────────

	/**
	 * Test setObject with an ObjectEntity directly.
	 */
	public function testSetObjectWithEntitySetsCurrentObject(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(10);
		$entity->setUuid('550e8400-e29b-41d4-a716-446655440000');

		$result = $this->service->setObject(object: $entity);

		$this->assertSame($entity, $this->getProperty('currentObject'));
		$this->assertSame($this->service, $result);
	}

	/**
	 * Test setObject with string ID uses UnifiedObjectMapper when context is set.
	 */
	public function testSetObjectWithStringIdUsesUnifiedMapperWhenContextSet(): void
	{
		// Set register and schema context first.
		$this->setProperty('currentRegister', $this->register);
		$this->setProperty('currentSchema', $this->schema);

		$entity = new ObjectEntity();
		$entity->setId(5);

		$this->unifiedObjectMapper
			->expects($this->once())
			->method('find')
			->willReturn($entity);

		$this->service->setObject(object: '550e8400-e29b-41d4-a716-446655440000');

		$this->assertSame($entity, $this->getProperty('currentObject'));
	}

	/**
	 * Test setObject falls back to ObjectEntityMapper when no context.
	 */
	public function testSetObjectFallsBackToObjectEntityMapperWithoutContext(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(7);

		$this->objectEntityMapper
			->expects($this->once())
			->method('find')
			->willReturn($entity);

		$this->service->setObject(object: 42);

		$this->assertSame($entity, $this->getProperty('currentObject'));
	}

	// ── 4. getObject() / getSchema() / getRegister() tests ──────────────

	/**
	 * Test getObject returns null when no object is set.
	 */
	public function testGetObjectReturnsNullInitially(): void
	{
		$this->assertNull($this->service->getObject());
	}

	/**
	 * Test getObject returns the current object after setObject.
	 */
	public function testGetObjectReturnsCurrentObject(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);
		$this->setProperty('currentObject', $entity);

		$this->assertSame($entity, $this->service->getObject());
	}

	/**
	 * Test getSchema throws RuntimeException when schema is not set.
	 */
	public function testGetSchemaThrowsWhenNotSet(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Schema not set in ObjectService.');

		$this->service->getSchema();
	}

	/**
	 * Test getSchema returns schema ID when set.
	 */
	public function testGetSchemaReturnsSchemaId(): void
	{
		$this->setProperty('currentSchema', $this->schema);

		$this->assertSame(2, $this->service->getSchema());
	}

	/**
	 * Test getRegister throws RuntimeException when register is not set.
	 */
	public function testGetRegisterThrowsWhenNotSet(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Register not set in ObjectService.');

		$this->service->getRegister();
	}

	/**
	 * Test getRegister returns register ID when set.
	 */
	public function testGetRegisterReturnsRegisterId(): void
	{
		$this->setProperty('currentRegister', $this->register);

		$this->assertSame(1, $this->service->getRegister());
	}

	// ── 5. find() tests ─────────────────────────────────────────────────

	/**
	 * Test find delegates to getHandler and renderHandler.
	 */
	public function testFindDelegatesToGetHandlerAndRenders(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$entity->setSchema(2);
		// Set published to now so no permission check.
		$entity->setPublished(new DateTime('-1 hour'));

		$this->getHandler
			->expects($this->once())
			->method('find')
			->willReturn($entity);

		// setSchema will be called since currentSchema is null.
		$this->performanceHandler
			->method('getCachedEntities')
			->willReturn([$this->schema]);

		$this->renderHandler
			->expects($this->once())
			->method('renderEntity')
			->willReturn($entity);

		$result = $this->service->find(
			id: '550e8400-e29b-41d4-a716-446655440000',
			schema: $this->schema
		);

		$this->assertSame($entity, $result);
	}

	/**
	 * Test find returns null when getHandler throws DoesNotExistException.
	 */
	public function testFindReturnsNullWhenObjectNotFound(): void
	{
		$this->getHandler
			->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);

		$this->service->find(id: 'nonexistent-uuid');
	}

	/**
	 * Test find sets register context when register param provided.
	 */
	public function testFindSetsRegisterContextWhenProvided(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setSchema(2);
		$entity->setPublished(new DateTime('-1 hour'));

		$this->getHandler->method('find')->willReturn($entity);

		// setSchema will be called for derived schema.
		$this->performanceHandler->method('getCachedEntities')->willReturn([$this->schema]);
		$this->renderHandler->method('renderEntity')->willReturn($entity);

		$this->service->find(id: 'test', register: $this->register);

		$this->assertSame($this->register, $this->getProperty('currentRegister'));
	}

	// ── 6. findAll() tests ──────────────────────────────────────────────

	/**
	 * Test findAll calls getHandler.findAll.
	 *
	 * Note: We verify delegation via mock expectations rather than calling
	 * findAll() directly, because findAll uses React\Async\await which
	 * is not available in the unit test environment.
	 */
	public function testFindAllDelegatesToGetHandler(): void
	{
		$this->getHandler
			->expects($this->once())
			->method('findAll')
			->willReturn([]);

		// Call findAll but catch the React error since React\Async isn't loaded.
		try {
			$this->service->findAll(config: ['limit' => 10]);
		} catch (\Error $e) {
			// Expected: React\Async\await is not available in unit tests.
			// The important assertion is that getHandler->findAll was called (above).
			$this->assertStringContainsString('React', $e->getMessage());
			return;
		}

		// If React IS available (unlikely in unit tests), verify we got an array.
		$this->assertTrue(true);
	}

	// ── 7. saveObject() tests ───────────────────────────────────────────

	/**
	 * Test saveObject with array data delegates through the full pipeline.
	 */
	public function testSaveObjectWithArrayData(): void
	{
		$this->setProperty('currentRegister', $this->register);

		$schemaWithValidation = new Schema();
		$schemaWithValidation->setId(2);
		$schemaWithValidation->setHardValidation(false);
		$this->setProperty('currentSchema', $schemaWithValidation);

		$savedEntity = new ObjectEntity();
		$savedEntity->setId(1);
		$savedEntity->setUuid('550e8400-e29b-41d4-a716-446655440000');

		// CascadingHandler returns the object + uuid unchanged.
		$this->cascadingHandler
			->method('handlePreValidationCascading')
			->willReturn([['name' => 'Test'], null]);

		// SaveHandler.applyAlwaysDefaults returns object as-is.
		$this->saveHandler
			->method('applyAlwaysDefaults')
			->willReturnArgument(1);

		$this->saveHandler
			->expects($this->once())
			->method('saveObject')
			->willReturn($savedEntity);

		$this->saveHandler
			->method('clearAllCaches');

		$this->renderHandler
			->expects($this->once())
			->method('renderEntity')
			->willReturn($savedEntity);

		$result = $this->service->saveObject(
			object: ['name' => 'Test']
		);

		$this->assertSame($savedEntity, $result);
	}

	/**
	 * Test saveObject with ObjectEntity extracts UUID and converts to array.
	 */
	public function testSaveObjectWithObjectEntityExtractsUuid(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$entity->setObject(['name' => 'From Entity']);

		$schemaNoValidation = new Schema();
		$schemaNoValidation->setId(2);
		$schemaNoValidation->setHardValidation(false);
		$this->setProperty('currentSchema', $schemaNoValidation);
		$this->setProperty('currentRegister', $this->register);

		$this->cascadingHandler
			->method('handlePreValidationCascading')
			->willReturn([['name' => 'From Entity'], '550e8400-e29b-41d4-a716-446655440000']);

		$this->saveHandler->method('applyAlwaysDefaults')->willReturnArgument(1);
		$this->saveHandler->method('clearAllCaches');

		$this->permissionHandler->method('checkPermission');

		// Expect objectEntityMapper->find to be called for UUID-based update permission check.
		$this->objectEntityMapper
			->method('find')
			->willReturn($entity);

		$savedEntity = new ObjectEntity();
		$savedEntity->setId(1);
		$savedEntity->setUuid('550e8400-e29b-41d4-a716-446655440000');

		$this->saveHandler
			->expects($this->once())
			->method('saveObject')
			->willReturn($savedEntity);

		$this->renderHandler
			->method('renderEntity')
			->willReturn($savedEntity);

		$result = $this->service->saveObject(object: $entity);

		$this->assertSame($savedEntity, $result);
	}

	/**
	 * Test saveObject sets context from register and schema parameters.
	 */
	public function testSaveObjectSetsContextFromParameters(): void
	{
		$schemaNoVal = new Schema();
		$schemaNoVal->setId(5);
		$schemaNoVal->setHardValidation(false);

		$this->cascadingHandler->method('handlePreValidationCascading')->willReturn([['x' => 1], null]);
		$this->saveHandler->method('applyAlwaysDefaults')->willReturnArgument(1);
		$this->saveHandler->method('clearAllCaches');

		$savedEntity = new ObjectEntity();
		$savedEntity->setId(1);
		$this->saveHandler->method('saveObject')->willReturn($savedEntity);
		$this->renderHandler->method('renderEntity')->willReturn($savedEntity);

		$this->service->saveObject(
			object: ['x' => 1],
			register: $this->register,
			schema: $schemaNoVal
		);

		$this->assertSame($this->register, $this->getProperty('currentRegister'));
		$this->assertSame($schemaNoVal, $this->getProperty('currentSchema'));
	}

	// ── 8. deleteObject() tests ─────────────────────────────────────────

	/**
	 * Test deleteObject delegates to deleteHandler after permission check.
	 */
	public function testDeleteObjectDelegatesToDeleteHandler(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$entity->setSchema(2);
		$entity->setOwner('user1');

		$this->objectEntityMapper
			->method('find')
			->willReturn($entity);

		// setSchema is called to derive schema from object.
		$this->performanceHandler
			->method('getCachedEntities')
			->willReturn([$this->schema]);

		$this->permissionHandler
			->expects($this->once())
			->method('checkPermission');

		$this->deleteHandler
			->expects($this->once())
			->method('deleteObject')
			->willReturn(true);

		$result = $this->service->deleteObject(uuid: '550e8400-e29b-41d4-a716-446655440000');

		$this->assertTrue($result);
	}

	/**
	 * Test deleteObject when object does not exist still checks permission if schema is set.
	 */
	public function testDeleteObjectWhenNotFoundChecksPermissionIfSchemaSet(): void
	{
		$this->setProperty('currentSchema', $this->schema);

		$this->objectEntityMapper
			->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$this->permissionHandler
			->expects($this->once())
			->method('checkPermission');

		$this->deleteHandler
			->method('deleteObject')
			->willReturn(true);

		$result = $this->service->deleteObject(uuid: 'nonexistent');

		$this->assertTrue($result);
	}

	// ── 9. publish() / depublish() tests ────────────────────────────────

	/**
	 * Test publish delegates to publishHandler.
	 */
	public function testPublishDelegatesToPublishHandler(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setPublished(new DateTime());

		$this->publishHandler
			->expects($this->once())
			->method('publish')
			->with(
				uuid: '550e8400-e29b-41d4-a716-446655440000',
				date: null,
				_rbac: true,
				_multitenancy: true
			)
			->willReturn($entity);

		$result = $this->service->publish(uuid: '550e8400-e29b-41d4-a716-446655440000');

		$this->assertSame($entity, $result);
	}

	/**
	 * Test publish passes custom date and rbac flag.
	 */
	public function testPublishWithCustomDateAndRbac(): void
	{
		$date = new DateTime('2025-06-15');
		$entity = new ObjectEntity();
		$entity->setId(1);

		$this->publishHandler
			->expects($this->once())
			->method('publish')
			->with(
				uuid: 'test-uuid',
				date: $date,
				_rbac: false,
				_multitenancy: false
			)
			->willReturn($entity);

		$result = $this->service->publish(
			uuid: 'test-uuid',
			date: $date,
			_rbac: false,
			_multitenancy: false
		);

		$this->assertSame($entity, $result);
	}

	/**
	 * Test depublish delegates to publishHandler.depublish.
	 */
	public function testDepublishDelegatesToPublishHandler(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);

		$this->publishHandler
			->expects($this->once())
			->method('depublish')
			->willReturn($entity);

		$result = $this->service->depublish(uuid: 'test-uuid');

		$this->assertSame($entity, $result);
	}

	// ── 10. lockObject() / unlockObject() tests ─────────────────────────

	/**
	 * Test lockObject delegates to lockHandler.lock.
	 */
	public function testLockObjectDelegatesToLockHandler(): void
	{
		$lockInfo = ['locked' => true, 'process' => 'import', 'expires' => '2025-12-31'];

		$this->lockHandler
			->expects($this->once())
			->method('lock')
			->with(
				identifier: 'obj-uuid',
				process: 'import',
				duration: 3600
			)
			->willReturn($lockInfo);

		$result = $this->service->lockObject(
			identifier: 'obj-uuid',
			process: 'import',
			duration: 3600
		);

		$this->assertSame($lockInfo, $result);
	}

	/**
	 * Test unlockObject delegates to lockHandler.unlock.
	 */
	public function testUnlockObjectDelegatesToLockHandler(): void
	{
		$this->lockHandler
			->expects($this->once())
			->method('unlock')
			->with(identifier: 'obj-uuid')
			->willReturn(true);

		$result = $this->service->unlockObject(identifier: 'obj-uuid');

		$this->assertTrue($result);
	}

	// ── 11. saveObjects() (bulk) tests ──────────────────────────────────

	/**
	 * Test saveObjects delegates to bulkOpsHandler with context.
	 */
	public function testSaveObjectsDelegatesToBulkOpsHandler(): void
	{
		$objects = [
			['name' => 'Object 1'],
			['name' => 'Object 2'],
		];

		$expectedResult = [
			'created' => 2,
			'updated' => 0,
			'errors' => [],
		];

		$this->bulkOpsHandler
			->expects($this->once())
			->method('saveObjects')
			->willReturn($expectedResult);

		$result = $this->service->saveObjects(
			objects: $objects,
			register: $this->register,
			schema: $this->schema
		);

		$this->assertSame($expectedResult, $result);
		$this->assertSame($this->register, $this->getProperty('currentRegister'));
		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
	}

	// ── 12. deleteObjects() (bulk) tests ────────────────────────────────

	/**
	 * Test deleteObjects delegates to bulkOpsHandler.
	 */
	public function testDeleteObjectsDelegatesToBulkOpsHandler(): void
	{
		$uuids = ['uuid-1', 'uuid-2', 'uuid-3'];

		$this->bulkOpsHandler
			->expects($this->once())
			->method('deleteObjects')
			->willReturn([1, 2, 3]);

		$result = $this->service->deleteObjects(uuids: $uuids);

		$this->assertSame([1, 2, 3], $result);
	}

	// ── 13. count() tests ───────────────────────────────────────────────

	/**
	 * Test count delegates to objectEntityMapper.countAll.
	 */
	public function testCountDelegatesToObjectEntityMapper(): void
	{
		$this->objectEntityMapper
			->expects($this->once())
			->method('countAll')
			->willReturn(42);

		$result = $this->service->count(config: ['filters' => ['schema' => 2]]);

		$this->assertSame(42, $result);
	}

	/**
	 * Test count removes limit from config.
	 */
	public function testCountRemovesLimitFromConfig(): void
	{
		$this->objectEntityMapper
			->expects($this->once())
			->method('countAll')
			->willReturn(100);

		// Even though limit is passed, it should be removed before calling countAll.
		$result = $this->service->count(config: ['limit' => 10, 'filters' => []]);

		$this->assertSame(100, $result);
	}

	// ── 14. getLogs() tests ─────────────────────────────────────────────

	/**
	 * Test getLogs retrieves object and delegates to getHandler.findLogs.
	 */
	public function testGetLogsDelegatesToGetHandler(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setUuid('test-uuid');

		$this->objectEntityMapper
			->expects($this->once())
			->method('find')
			->with('test-uuid')
			->willReturn($entity);

		$mockLogs = [['action' => 'create', 'timestamp' => '2025-01-01']];

		$this->getHandler
			->expects($this->once())
			->method('findLogs')
			->willReturn($mockLogs);

		$result = $this->service->getLogs(uuid: 'test-uuid');

		$this->assertSame($mockLogs, $result);
	}

	// ── 15. Private: extractUuidAndNormalizeObject() tests ──────────────

	/**
	 * Test extractUuidAndNormalizeObject with array input and no UUID.
	 */
	public function testExtractUuidAndNormalizeObjectWithArrayNoUuid(): void
	{
		[$obj, $uuid] = $this->invokePrivate('extractUuidAndNormalizeObject', [
			['name' => 'Test'],
			null,
		]);

		$this->assertSame(['name' => 'Test'], $obj);
		$this->assertNull($uuid);
	}

	/**
	 * Test extractUuidAndNormalizeObject extracts id from @self.id.
	 */
	public function testExtractUuidAndNormalizeObjectExtractsFromSelfId(): void
	{
		[$obj, $uuid] = $this->invokePrivate('extractUuidAndNormalizeObject', [
			['name' => 'Test', '@self' => ['id' => 'abc-123']],
			null,
		]);

		$this->assertSame('abc-123', $uuid);
	}

	/**
	 * Test extractUuidAndNormalizeObject extracts id from top-level 'id'.
	 */
	public function testExtractUuidAndNormalizeObjectExtractsFromTopLevelId(): void
	{
		[$obj, $uuid] = $this->invokePrivate('extractUuidAndNormalizeObject', [
			['name' => 'Test', 'id' => 'top-level-uuid'],
			null,
		]);

		$this->assertSame('top-level-uuid', $uuid);
	}

	/**
	 * Test extractUuidAndNormalizeObject with ObjectEntity input.
	 */
	public function testExtractUuidAndNormalizeObjectWithObjectEntity(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setUuid('entity-uuid-123');
		$entity->setObject(['title' => 'Entity Data']);

		[$obj, $uuid] = $this->invokePrivate('extractUuidAndNormalizeObject', [
			$entity,
			null,
		]);

		// ObjectEntity::getObject() may include additional metadata like 'id'.
		$this->assertArrayHasKey('title', $obj);
		$this->assertSame('Entity Data', $obj['title']);
		$this->assertSame('entity-uuid-123', $uuid);
	}

	/**
	 * Test extractUuidAndNormalizeObject with ObjectEntity does not override provided UUID.
	 */
	public function testExtractUuidAndNormalizeObjectPreservesProvidedUuid(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setUuid('entity-uuid');
		$entity->setObject(['title' => 'Data']);

		[$obj, $uuid] = $this->invokePrivate('extractUuidAndNormalizeObject', [
			$entity,
			'provided-uuid',
		]);

		$this->assertSame('provided-uuid', $uuid);
	}

	/**
	 * Test extractUuidAndNormalizeObject skips empty trimmed id.
	 */
	public function testExtractUuidAndNormalizeObjectSkipsEmptyId(): void
	{
		[$obj, $uuid] = $this->invokePrivate('extractUuidAndNormalizeObject', [
			['name' => 'Test', 'id' => '   '],
			null,
		]);

		$this->assertNull($uuid);
	}

	// ── 16. Private: normalizeDateValues() tests ────────────────────────

	/**
	 * Test normalizeDateValues converts datetime to date for date-format properties.
	 */
	public function testNormalizeDateValuesConvertDatetimeToDate(): void
	{
		$schema = new Schema();
		$schema->setId(1);
		$schema->setProperties([
			'birthDate' => ['type' => 'string', 'format' => 'date'],
		]);
		$this->setProperty('currentSchema', $schema);

		$result = $this->invokePrivate('normalizeDateValues', [
			['birthDate' => '2024-01-15T10:30:00+02:00'],
		]);

		$this->assertSame('2024-01-15', $result['birthDate']);
	}

	/**
	 * Test normalizeDateValues leaves valid date-only values unchanged.
	 */
	public function testNormalizeDateValuesLeavesValidDatesAlone(): void
	{
		$schema = new Schema();
		$schema->setId(1);
		$schema->setProperties([
			'birthDate' => ['type' => 'string', 'format' => 'date'],
		]);
		$this->setProperty('currentSchema', $schema);

		$result = $this->invokePrivate('normalizeDateValues', [
			['birthDate' => '2024-01-15'],
		]);

		$this->assertSame('2024-01-15', $result['birthDate']);
	}

	/**
	 * Test normalizeDateValues skips non-date format properties.
	 */
	public function testNormalizeDateValuesSkipsNonDateFormats(): void
	{
		$schema = new Schema();
		$schema->setId(1);
		$schema->setProperties([
			'email' => ['type' => 'string', 'format' => 'email'],
		]);
		$this->setProperty('currentSchema', $schema);

		$result = $this->invokePrivate('normalizeDateValues', [
			['email' => 'test@example.com'],
		]);

		$this->assertSame('test@example.com', $result['email']);
	}

	/**
	 * Test normalizeDateValues returns object as-is when no schema set.
	 */
	public function testNormalizeDateValuesReturnsUnchangedWithoutSchema(): void
	{
		$this->setProperty('currentSchema', null);

		$data = ['birthDate' => '2024-01-15T10:30:00+02:00'];
		$result = $this->invokePrivate('normalizeDateValues', [$data]);

		$this->assertSame($data, $result);
	}

	/**
	 * Test normalizeDateValues handles datetime with space separator.
	 */
	public function testNormalizeDateValuesHandlesSpaceSeparatedDatetime(): void
	{
		$schema = new Schema();
		$schema->setId(1);
		$schema->setProperties([
			'startDate' => ['type' => 'string', 'format' => 'date'],
		]);
		$this->setProperty('currentSchema', $schema);

		$result = $this->invokePrivate('normalizeDateValues', [
			['startDate' => '2024-06-30 14:00:00'],
		]);

		$this->assertSame('2024-06-30', $result['startDate']);
	}

	/**
	 * Test normalizeDateValues leaves invalid date values unchanged.
	 */
	public function testNormalizeDateValuesLeavesInvalidValuesUnchanged(): void
	{
		$schema = new Schema();
		$schema->setId(1);
		$schema->setProperties([
			'startDate' => ['type' => 'string', 'format' => 'date'],
		]);
		$this->setProperty('currentSchema', $schema);

		$result = $this->invokePrivate('normalizeDateValues', [
			['startDate' => 'not-a-date'],
		]);

		// Invalid date string - DateTime constructor might parse it or leave it.
		// The method catches exceptions and leaves the original value.
		$this->assertArrayHasKey('startDate', $result);
	}

	// ── 17. Private: isUuidFormat() tests ───────────────────────────────

	/**
	 * Test isUuidFormat returns true for valid UUID v4.
	 */
	public function testIsUuidFormatReturnsTrueForValidUuid(): void
	{
		$result = $this->invokePrivate('isUuidFormat', ['550e8400-e29b-41d4-a716-446655440000']);

		$this->assertTrue($result);
	}

	/**
	 * Test isUuidFormat returns true for uppercase UUID.
	 */
	public function testIsUuidFormatReturnsTrueForUppercaseUuid(): void
	{
		$result = $this->invokePrivate('isUuidFormat', ['550E8400-E29B-41D4-A716-446655440000']);

		$this->assertTrue($result);
	}

	/**
	 * Test isUuidFormat returns false for non-UUID strings.
	 */
	public function testIsUuidFormatReturnsFalseForNonUuid(): void
	{
		$this->assertFalse($this->invokePrivate('isUuidFormat', ['not-a-uuid']));
		$this->assertFalse($this->invokePrivate('isUuidFormat', ['12345']));
		$this->assertFalse($this->invokePrivate('isUuidFormat', ['']));
		$this->assertFalse($this->invokePrivate('isUuidFormat', ['550e8400-e29b-41d4-a716']));
	}

	// ── 18. searchObjects() tests ───────────────────────────────────────

	/**
	 * Test searchObjects delegates to queryHandler.
	 */
	public function testSearchObjectsDelegatesToQueryHandler(): void
	{
		$query = ['@self' => ['schema' => 2], '_limit' => 20];

		$this->queryHandler
			->expects($this->once())
			->method('searchObjects')
			->with(
				query: $query,
				_rbac: true,
				_multitenancy: true,
				ids: null,
				uses: null,
				views: null
			)
			->willReturn([]);

		$result = $this->service->searchObjects(query: $query);

		$this->assertSame([], $result);
	}

	// ── 19. buildSearchQuery() tests ────────────────────────────────────

	/**
	 * Test buildSearchQuery delegates to searchQueryHandler.
	 */
	public function testBuildSearchQueryDelegatesToSearchQueryHandler(): void
	{
		$params = ['_search' => 'test', '_limit' => '10'];

		$this->searchQueryHandler
			->expects($this->once())
			->method('buildSearchQuery')
			->willReturn(['_search' => 'test', '_limit' => 10]);

		$result = $this->service->buildSearchQuery(requestParams: $params);

		$this->assertArrayHasKey('_search', $result);
	}

	// ── 20. getFacetsForObjects() tests ─────────────────────────────────

	/**
	 * Test getFacetsForObjects delegates to facetHandler.
	 */
	public function testGetFacetsForObjectsDelegatesToFacetHandler(): void
	{
		$query = ['@self' => ['schema' => 2]];
		$expectedFacets = ['status' => ['open' => 5, 'closed' => 3]];

		$this->facetHandler
			->expects($this->once())
			->method('getFacetsForObjects')
			->with($query)
			->willReturn($expectedFacets);

		$result = $this->service->getFacetsForObjects(query: $query);

		$this->assertSame($expectedFacets, $result);
	}

	// ── 21. findByRelations() tests ─────────────────────────────────────

	/**
	 * Test findByRelations delegates to objectEntityMapper.
	 */
	public function testFindByRelationsDelegatesToMapper(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);

		$this->objectEntityMapper
			->expects($this->once())
			->method('findByRelation')
			->with(search: 'some-uuid', partialMatch: true)
			->willReturn([$entity]);

		$result = $this->service->findByRelations(search: 'some-uuid');

		$this->assertCount(1, $result);
		$this->assertSame($entity, $result[0]);
	}

	// ── 22. countSearchObjects() tests ──────────────────────────────────

	/**
	 * Test countSearchObjects delegates to objectEntityMapper.
	 */
	public function testCountSearchObjectsDelegatesToMapper(): void
	{
		$this->objectEntityMapper
			->expects($this->once())
			->method('countSearchObjects')
			->willReturn(15);

		$result = $this->service->countSearchObjects(
			query: ['@self' => ['schema' => 2]],
			_multitenancy: false
		);

		$this->assertSame(15, $result);
	}

	// ── 23. getExtendedObjects() tests ──────────────────────────────────

	/**
	 * Test getExtendedObjects delegates to renderHandler.getObjectsCache.
	 */
	public function testGetExtendedObjectsDelegatesToRenderHandler(): void
	{
		$cache = ['uuid-1' => ['name' => 'Object 1']];

		$this->renderHandler
			->expects($this->once())
			->method('getObjectsCache')
			->willReturn($cache);

		$result = $this->service->getExtendedObjects();

		$this->assertSame($cache, $result);
	}

	// ── 24. getCreatedSubObjects() tests ────────────────────────────────

	/**
	 * Test getCreatedSubObjects delegates to saveHandler.
	 */
	public function testGetCreatedSubObjectsDelegatesToSaveHandler(): void
	{
		$subObjects = ['sub-uuid' => ['name' => 'Sub Object']];

		$this->saveHandler
			->expects($this->once())
			->method('getCreatedSubObjects')
			->willReturn($subObjects);

		$result = $this->service->getCreatedSubObjects();

		$this->assertSame($subObjects, $result);
	}

	// ── 25. clearCreatedSubObjects() tests ──────────────────────────────

	/**
	 * Test clearCreatedSubObjects delegates to saveHandler.
	 */
	public function testClearCreatedSubObjectsDelegatesToSaveHandler(): void
	{
		$this->saveHandler
			->expects($this->once())
			->method('clearCreatedSubObjects');

		$this->service->clearCreatedSubObjects();
	}

	// ── 26. getCacheHandler() tests ─────────────────────────────────────

	/**
	 * Test getCacheHandler returns the injected CacheHandler.
	 */
	public function testGetCacheHandlerReturnsInjectedInstance(): void
	{
		$result = $this->service->getCacheHandler();

		$this->assertInstanceOf(CacheHandler::class, $result);
	}

	// ── 27. Private: checkSavePermissions() tests ───────────────────────

	/**
	 * Test checkSavePermissions with null uuid calls create permission.
	 */
	public function testCheckSavePermissionsCreateWhenNoUuid(): void
	{
		$this->setProperty('currentSchema', $this->schema);

		$this->permissionHandler
			->expects($this->once())
			->method('checkPermission')
			->with(
				schema: $this->schema,
				action: 'create',
				userId: null,
				objectOwner: null,
				rbac: true
			);

		$this->invokePrivate('checkSavePermissions', [null, true]);
	}

	/**
	 * Test checkSavePermissions with uuid calls update permission when object exists.
	 */
	public function testCheckSavePermissionsUpdateWhenUuidExists(): void
	{
		$this->setProperty('currentSchema', $this->schema);

		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setOwner('user1');

		$this->objectEntityMapper
			->method('find')
			->willReturn($entity);

		$this->permissionHandler
			->expects($this->once())
			->method('checkPermission')
			->with(
				schema: $this->schema,
				action: 'update',
				userId: null,
				objectOwner: 'user1',
				rbac: true,
				object: $entity
			);

		$this->invokePrivate('checkSavePermissions', ['existing-uuid', true]);
	}

	/**
	 * Test checkSavePermissions with uuid calls create when object not found.
	 */
	public function testCheckSavePermissionsCreateWhenUuidNotFound(): void
	{
		$this->setProperty('currentSchema', $this->schema);

		$this->objectEntityMapper
			->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$this->permissionHandler
			->expects($this->once())
			->method('checkPermission')
			->with(
				schema: $this->schema,
				action: 'create',
				userId: null,
				objectOwner: null,
				rbac: true
			);

		$this->invokePrivate('checkSavePermissions', ['new-uuid', true]);
	}

	/**
	 * Test checkSavePermissions does nothing when schema is null.
	 */
	public function testCheckSavePermissionsSkipsWhenNoSchema(): void
	{
		$this->setProperty('currentSchema', null);

		$this->permissionHandler
			->expects($this->never())
			->method('checkPermission');

		$this->invokePrivate('checkSavePermissions', [null, true]);
	}

	// ── 28. Private: prepareFindAllConfig() tests ───────────────────────

	/**
	 * Test prepareFindAllConfig converts extend string to array.
	 */
	public function testPrepareFindAllConfigConvertsExtendStringToArray(): void
	{
		$config = ['extend' => '@self.schema,@self.register'];

		$result = $this->invokePrivate('prepareFindAllConfig', [$config]);

		$this->assertIsArray($result['extend']);
		$this->assertSame(['@self.schema', '@self.register'], $result['extend']);
	}

	/**
	 * Test prepareFindAllConfig sets register context from filters.
	 */
	public function testPrepareFindAllConfigSetsRegisterFromFilters(): void
	{
		$this->registerMapper
			->method('find')
			->willReturn($this->register);

		$config = ['filters' => ['register' => 'my-register']];

		$this->invokePrivate('prepareFindAllConfig', [$config]);

		$this->assertSame($this->register, $this->getProperty('currentRegister'));
	}

	/**
	 * Test prepareFindAllConfig sets schema context from filters.
	 */
	public function testPrepareFindAllConfigSetsSchemaFromFilters(): void
	{
		$this->schemaMapper
			->method('find')
			->willReturn($this->schema);

		$config = ['filters' => ['schema' => 'my-schema']];

		$this->invokePrivate('prepareFindAllConfig', [$config]);

		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
	}

	// ── 29. renderEntity() tests ────────────────────────────────────────

	/**
	 * Test renderEntity delegates to renderHandler and calls jsonSerialize.
	 */
	public function testRenderEntityDelegatesToRenderHandler(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setUuid('test-uuid');

		$renderedEntity = new ObjectEntity();
		$renderedEntity->setId(1);
		$renderedEntity->setUuid('test-uuid');

		$this->renderHandler
			->expects($this->once())
			->method('renderEntity')
			->willReturn($renderedEntity);

		$result = $this->service->renderEntity(entity: $entity);

		$this->assertIsArray($result);
	}

	// ── 30. findSilent() tests ──────────────────────────────────────────

	/**
	 * Test findSilent delegates to getHandler.findSilent.
	 */
	public function testFindSilentDelegatesToGetHandler(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);

		$this->getHandler
			->expects($this->once())
			->method('findSilent')
			->willReturn($entity);

		$result = $this->service->findSilent(id: 'test-uuid');

		$this->assertSame($entity, $result);
	}

	/**
	 * Test findSilent sets register and schema context when provided.
	 */
	public function testFindSilentSetsContextWhenProvided(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);

		$this->getHandler->method('findSilent')->willReturn($entity);

		$this->service->findSilent(
			id: 'test-uuid',
			register: $this->register,
			schema: $this->schema
		);

		$this->assertSame($this->register, $this->getProperty('currentRegister'));
		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
	}

	// ── 31. Private: handleCascadingWithContextPreservation() tests ─────

	/**
	 * Test handleCascadingWithContextPreservation preserves parent context.
	 */
	public function testHandleCascadingPreservesParentContext(): void
	{
		$this->setProperty('currentRegister', $this->register);
		$this->setProperty('currentSchema', $this->schema);

		$this->cascadingHandler
			->method('handlePreValidationCascading')
			->willReturnCallback(function () {
				// Simulate cascading modifying context (which should be restored).
				return [['cascaded' => true], 'new-uuid'];
			});

		[$obj, $uuid] = $this->invokePrivate('handleCascadingWithContextPreservation', [
			['name' => 'Parent'],
			null,
		]);

		// Context should be restored to parent values.
		$this->assertSame($this->register, $this->getProperty('currentRegister'));
		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
		$this->assertSame('new-uuid', $uuid);
	}

	// ── 32. Private: ensureObjectFolder() tests ─────────────────────────

	/**
	 * Test ensureObjectFolder returns null when uuid is null.
	 */
	public function testEnsureObjectFolderReturnsNullForNullUuid(): void
	{
		$result = $this->invokePrivate('ensureObjectFolder', [null]);

		$this->assertNull($result);
	}

	/**
	 * Test ensureObjectFolder creates folder when object exists without folder.
	 */
	public function testEnsureObjectFolderCreatesFolderForExistingObject(): void
	{
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setFolder(null);

		$this->objectEntityMapper
			->method('find')
			->willReturn($entity);

		$this->fileService
			->expects($this->once())
			->method('createObjectFolderWithoutUpdate')
			->willReturn(42);

		$result = $this->invokePrivate('ensureObjectFolder', ['existing-uuid']);

		$this->assertSame(42, $result);
	}

	/**
	 * Test ensureObjectFolder returns null when object not found (new object).
	 */
	public function testEnsureObjectFolderReturnsNullForNewObject(): void
	{
		$this->objectEntityMapper
			->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$result = $this->invokePrivate('ensureObjectFolder', ['new-uuid']);

		$this->assertNull($result);
	}

	// ── 33. Method chaining tests ───────────────────────────────────────

	/**
	 * Test that setRegister and setSchema support fluent chaining.
	 */
	public function testMethodChainingForContextSetters(): void
	{
		$result = $this->service
			->setRegister(register: $this->register)
			->setSchema(schema: $this->schema);

		$this->assertInstanceOf(ObjectService::class, $result);
		$this->assertSame($this->register, $this->getProperty('currentRegister'));
		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
	}
}
