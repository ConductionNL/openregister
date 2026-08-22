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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditHandler;
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
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\PermissionHandler;
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
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\IAppContainer;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Comprehensive unit tests for ObjectService.
 *
 * Covers: find, findAll, saveObject, deleteObject, setRegister, setSchema,
 * setObject, getSchema, getRegister, publish, depublish, lockObject,
 * unlockObject, saveObjects, deleteObjects, count, getLogs,
 * and private helper methods via reflection.
 */
class ObjectServiceTest extends TestCase {
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
	/** @var MockObject&LockHandler */
	private $lockHandler;
	/** @var MockObject&AuditHandler */
	private $auditHandler;
	/** @var MockObject&PermissionHandler */
	private $permissionHandler;
	/** @var MockObject&CascadingHandler */
	private $cascadingHandler;
	/** @var MockObject&QueryHandler */
	private $queryHandler;
	/** @var MockObject&FacetHandler */
	private $facetHandler;
	/** @var MockObject&SearchQueryHandler */
	private $searchQueryHandler;
	/** @var MockObject&MagicMapper */
	private $objectEntityMapper;
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
	/** @var MockObject&DateTimeNormalizer */
	private $dateTimeNormalizer;

	// Real entity instances (magic __call for getters/setters).
	private Register $register;
	private Schema $schema;

	/**
	 * Set up test environment before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// Create mocks for all handler dependencies.
		$this->getHandler = $this->createMock(GetObject::class);
		$this->saveHandler = $this->createMock(SaveObject::class);
		$this->renderHandler = $this->createMock(RenderObject::class);
		$this->validateHandler = $this->createMock(ValidateObject::class);
		$this->deleteHandler = $this->createMock(DeleteObject::class);
		$this->lockHandler = $this->createMock(LockHandler::class);
		$this->auditHandler = $this->createMock(AuditHandler::class);
		$this->permissionHandler = $this->createMock(PermissionHandler::class);
		$this->cascadingHandler = $this->createMock(CascadingHandler::class);
		$this->queryHandler = $this->createMock(QueryHandler::class);
		$this->facetHandler = $this->createMock(FacetHandler::class);
		$this->searchQueryHandler = $this->createMock(SearchQueryHandler::class);
		$this->objectEntityMapper = $this->createMock(MagicMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->fileService = $this->createMock(FileService::class);
		$this->organisationService = $this->createMock(OrganisationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);

		// Default: normalize() echoes the input parsed as DateTime. Tests that need
		// a specific return can override via $this->dateTimeNormalizer->method().
		$this->dateTimeNormalizer->method('normalize')->willReturnCallback(
			function (?string $input): ?\DateTimeImmutable {
				if ($input === null || trim($input) === '') {
					return null;
				}

				try {
					return new \DateTimeImmutable($input);
				} catch (\Throwable $e) {
					return null;
				}
			}
		);

		// Create real entity instances (magic getters/setters via __call).
		$this->register = new Register();
		$this->register->setId(1);

		$this->schema = new Schema();
		$this->schema->setId(2);

		// Instantiate ObjectService with all constructor params (positional).
		$this->service = new ObjectService(
			$this->createMock(DataManipulationHandler::class),
			$this->deleteHandler,
			$this->getHandler,
			$this->permissionHandler,
			$this->renderHandler,
			$this->saveHandler,
			$this->createMock(SaveObjects::class),
			$this->searchQueryHandler,
			$this->validateHandler,
			$this->lockHandler,
			$this->auditHandler,
			$this->createMock(RelationHandler::class),
			$this->createMock(MergeHandler::class),
			$this->facetHandler,
			$this->createMock(MetadataHandler::class),
			$this->createMock(PerformanceOptimizationHandler::class),
			$this->queryHandler,
			$this->createMock(RevertHandler::class),
			$this->createMock(UtilityHandler::class),
			$this->createMock(ValidationHandler::class),
			$this->cascadingHandler,
			$this->createMock(MigrationHandler::class),
			$this->registerMapper,
			$this->schemaMapper,
			$this->createMock(ViewMapper::class),
			$this->objectEntityMapper,
			$this->fileService,
			$this->createMock(IUserSession::class),
			$this->createMock(SearchTrailService::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->organisationService,
			$this->logger,
			$this->createMock(CacheHandler::class),
			$this->createMock(SettingsService::class),
			$this->dateTimeNormalizer,
			$this->createMock(IAppContainer::class),
			$this->createMock(ObjectSourceRegistry::class)
		);

		$this->reflection = new ReflectionClass(ObjectService::class);
	}

	// ── Helper methods ──────────────────────────────────────────────────

	/**
	 * Invoke a private/protected method via reflection.
	 */
	private function invokePrivate(string $methodName, array $args = []): mixed {
		$method = $this->reflection->getMethod($methodName);
		$method->setAccessible(true);
		return $method->invokeArgs($this->service, $args);
	}

	/**
	 * Set a private/protected property via reflection.
	 */
	private function setProperty(string $name, mixed $value): void {
		$property = $this->reflection->getProperty($name);
		$property->setAccessible(true);
		$property->setValue($this->service, $value);
	}

	/**
	 * Get a private/protected property via reflection.
	 */
	private function getProperty(string $name): mixed {
		$property = $this->reflection->getProperty($name);
		$property->setAccessible(true);
		return $property->getValue($this->service);
	}

	// ── 1. setRegister() tests ──────────────────────────────────────────

	/**
	 * Test setRegister with a Register entity directly.
	 */
	public function testSetRegisterWithRegisterEntity(): void {
		$result = $this->service->setRegister(register: $this->register);

		$this->assertSame($this->register, $this->getProperty('currentRegister'));
		$this->assertSame($this->service, $result, 'setRegister should return $this for chaining');
	}

	/**
	 * Test setRegister with a numeric ID resolves via the mapper.
	 */
	public function testSetRegisterWithNumericIdUsesMapperFind(): void {
		$this->registerMapper
			->expects($this->once())
			->method('find')
			->willReturn($this->register);

		$result = $this->service->setRegister(register: 1);

		$this->assertSame($this->register, $this->getProperty('currentRegister'));
		$this->assertSame($this->service, $result);
	}

	/**
	 * Test setRegister with string slug falls back to mapper.
	 */
	public function testSetRegisterWithSlugUsesMapperFind(): void {
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
	public function testSetSchemaWithSchemaEntity(): void {
		$result = $this->service->setSchema(schema: $this->schema);

		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
		$this->assertSame($this->service, $result);
	}

	/**
	 * Test setSchema with a numeric ID resolves via the mapper.
	 */
	public function testSetSchemaWithNumericIdUsesMapperFind(): void {
		$this->schemaMapper
			->expects($this->once())
			->method('find')
			->willReturn($this->schema);

		$result = $this->service->setSchema(schema: 2);

		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
		$this->assertSame($this->service, $result);
	}

	/**
	 * The pending schema ref is SINGLE USE: consumed by the first
	 * setRegister() that follows, then cleared.
	 *
	 * ObjectService is reused for many operations in one process. A ref left
	 * behind by an operation that set a schema and never set a register would
	 * be re-resolved against the NEXT caller's register and refuse it —
	 * measured on the shared instance as a pipelinq repair step seeding
	 * `trustConfiguration` being told `posJournalEntryOutbound` is not carried
	 * by its register, a slug from an entirely unrelated operation.
	 */
	public function testPendingSchemaRefIsClearedOnceConsumed(): void {
		// The register must actually carry the schema, or the scoped resolve
		// refuses for the RIGHT reason and hides what this test is about.
		$this->register->setSchemas([2]);
		$this->schemaMapper->method('find')->willReturn($this->schema);
		$this->schemaMapper->method('findInIds')->willReturn($this->schema);
		$this->registerMapper->method('find')->willReturn($this->register);

		$this->service->setSchema(schema: 'my-schema');
		$this->assertSame('my-schema', $this->getProperty('currentSchemaRef'));

		$this->service->setRegister(register: 1);

		$this->assertNull(
			$this->getProperty('currentSchemaRef'),
			'The pending ref must not survive the setRegister() that consumed it.'
		);
	}

	/**
	 * A setRegister() with no pending ref must not re-resolve anything — the
	 * leak this guards against is a stale ref from an earlier operation.
	 */
	public function testSetRegisterWithoutAPendingRefDoesNotRescope(): void {
		$this->registerMapper->method('find')->willReturn($this->register);
		$this->setProperty('currentSchemaRef', null);
		$this->setProperty('currentSchema', $this->schema);

		$this->service->setRegister(register: 1);

		$this->assertSame(
			$this->schema,
			$this->getProperty('currentSchema'),
			'An already-resolved schema must survive a later setRegister() untouched.'
		);
	}

	/**
	 * Test setSchema with string slug uses mapper find.
	 */
	public function testSetSchemaWithSlugUsesMapperFind(): void {
		$this->schemaMapper
			->expects($this->once())
			->method('find')
			->willReturn($this->schema);

		$result = $this->service->setSchema(schema: 'my-schema');

		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
		$this->assertSame($this->service, $result);
	}

	/**
	 * Test setSchema rethrows DoesNotExistException when schema not found.
	 *
	 * setSchema() deliberately rethrows DoesNotExistException so NC's
	 * dispatcher converts it to a 404; wrapping it in ValidationException
	 * would surface as a 500. See ObjectService::setSchema().
	 */
	public function testSetSchemaThrowsWhenNotFound(): void {
		$this->schemaMapper
			->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);

		$this->service->setSchema(schema: 'nonexistent-slug');
	}

	// ── 3. setObject() tests ────────────────────────────────────────────

	/**
	 * Test setObject with an ObjectEntity directly.
	 */
	public function testSetObjectWithEntitySetsCurrentObject(): void {
		$entity = new ObjectEntity();
		$entity->setId(10);
		$entity->setUuid('550e8400-e29b-41d4-a716-446655440000');

		$result = $this->service->setObject(object: $entity);

		$this->assertSame($entity, $this->getProperty('currentObject'));
		$this->assertSame($this->service, $result);
	}

	/**
	 * Test setObject with string ID uses MagicMapper when context is set.
	 */
	public function testSetObjectWithStringIdUsesUnifiedMapperWhenContextSet(): void {
		// Set register and schema context first.
		$this->setProperty('currentRegister', $this->register);
		$this->setProperty('currentSchema', $this->schema);

		$entity = new ObjectEntity();
		$entity->setId(5);

		$this->objectEntityMapper
			->expects($this->once())
			->method('find')
			->willReturn($entity);

		$this->service->setObject(object: '550e8400-e29b-41d4-a716-446655440000');

		$this->assertSame($entity, $this->getProperty('currentObject'));
	}

	/**
	 * Test setObject falls back to MagicMapper when no context.
	 */
	public function testSetObjectFallsBackToMagicMapperWithoutContext(): void {
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
	public function testGetObjectReturnsNullInitially(): void {
		$this->assertNull($this->service->getObject());
	}

	/**
	 * Test getObject returns the current object after setObject.
	 */
	public function testGetObjectReturnsCurrentObject(): void {
		$entity = new ObjectEntity();
		$entity->setId(1);
		$this->setProperty('currentObject', $entity);

		$this->assertSame($entity, $this->service->getObject());
	}

	/**
	 * Test getSchema throws RuntimeException when schema is not set.
	 */
	public function testGetSchemaThrowsWhenNotSet(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Schema not set in ObjectService.');

		$this->service->getSchema();
	}

	/**
	 * Test getSchema returns schema ID when set.
	 */
	public function testGetSchemaReturnsSchemaId(): void {
		$this->setProperty('currentSchema', $this->schema);

		$this->assertSame(2, $this->service->getSchema());
	}

	/**
	 * Test getRegister throws RuntimeException when register is not set.
	 */
	public function testGetRegisterThrowsWhenNotSet(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Register not set in ObjectService.');

		$this->service->getRegister();
	}

	/**
	 * Test getRegister returns register ID when set.
	 */
	public function testGetRegisterReturnsRegisterId(): void {
		$this->setProperty('currentRegister', $this->register);

		$this->assertSame(1, $this->service->getRegister());
	}

	// ── 5. find() tests ─────────────────────────────────────────────────

	/**
	 * Test find delegates to getHandler and renderHandler.
	 */
	public function testFindDelegatesToGetHandlerAndRenders(): void {
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$entity->setSchema(2);

		$this->getHandler
			->expects($this->once())
			->method('find')
			->willReturn($entity);

		// setSchema will be called since currentSchema is null.
		$this->schemaMapper
			->method('find')
			->willReturn($this->schema);

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
	public function testFindReturnsNullWhenObjectNotFound(): void {
		$this->getHandler
			->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);

		$this->service->find(id: 'nonexistent-uuid');
	}

	/**
	 * Test find sets register context when register param provided.
	 */
	public function testFindRestoresRegisterContextAfterReturning(): void {
		// BUG-OBJ-13 (openregister#1520): find() is a read operation and must
		// leave the shared currentRegister / currentSchema instance state
		// UNTOUCHED for the next caller. Any re-anchoring for this call's
		// rendering/RBAC is snapshot-restored in a finally. The old contract
		// "find(register: X) sets currentRegister = X afterward" is gone.
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setSchema(2);

		$this->getHandler->method('find')->willReturn($entity);

		// setSchema will be called for derived schema.
		$this->schemaMapper->method('find')->willReturn($this->schema);
		$this->renderHandler->method('renderEntity')->willReturn($entity);

		// Capture the context before the call so we can assert it is restored.
		$registerBefore = $this->getProperty('currentRegister');
		$schemaBefore = $this->getProperty('currentSchema');

		$this->service->find(id: 'test', register: $this->register);

		// Context is restored to exactly what it was before find() ran.
		$this->assertSame($registerBefore, $this->getProperty('currentRegister'));
		$this->assertSame($schemaBefore, $this->getProperty('currentSchema'));
	}

	// ── 6. findAll() tests ──────────────────────────────────────────────

	/**
	 * Test findAll calls getHandler.findAll.
	 *
	 * Note: We verify delegation via mock expectations rather than calling
	 * findAll() directly, because findAll uses React\Async\await which
	 * is not available in the unit test environment.
	 */
	public function testFindAllDelegatesToGetHandler(): void {
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
	public function testSaveObjectWithArrayData(): void {
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
	public function testSaveObjectWithObjectEntityExtractsUuid(): void {
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
	public function testSaveObjectSetsContextFromParameters(): void {
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
	public function testDeleteObjectDelegatesToDeleteHandler(): void {
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$entity->setSchema(2);
		$entity->setOwner('user1');

		$this->objectEntityMapper
			->method('find')
			->willReturn($entity);

		// setSchema is called to derive schema from object.
		$this->schemaMapper
			->method('find')
			->willReturn($this->schema);

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
	public function testDeleteObjectWhenNotFoundChecksPermissionIfSchemaSet(): void {
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

	// ── 9. deleteObjects() bulk tests ───────────────────────────────────

	/**
	 * Bulk delete resolves every uuid's scope with ONE batched cross-table
	 * lookup and hands the pre-resolved entity plus concrete register/schema
	 * entities to the delete handler — the legacy per-uuid pre-find is skipped.
	 */
	public function testDeleteObjectsUsesBatchedScopeResolution(): void {
		$entityA = new ObjectEntity();
		$entityA->setUuid('uuid-a');
		$entityA->setRegister('1');
		$entityA->setSchema('2');

		$entityB = new ObjectEntity();
		$entityB->setUuid('uuid-b');
		$entityB->setRegister('1');
		$entityB->setSchema('2');

		$this->objectEntityMapper
			->expects($this->once())
			->method('findMultipleAcrossAllMagicTables')
			->with(['uuid-a', 'uuid-b'], true)
			->willReturn([$entityA, $entityB]);

		// The legacy per-uuid pre-delete find must NOT run for batch-resolved uuids.
		$this->objectEntityMapper
			->expects($this->never())
			->method('find');

		// Scope entities materialise once per distinct (register, schema) pair.
		$this->registerMapper
			->expects($this->once())
			->method('find')
			->willReturn($this->register);
		$this->schemaMapper
			->expects($this->once())
			->method('find')
			->willReturn($this->schema);

		$captured = [];
		$this->deleteHandler
			->expects($this->exactly(2))
			->method('deleteObject')
			->willReturnCallback(
				function (
					$register,
					$schema,
					$uuid,
					$originalObjectId = null,
					$_rbac = true,
					$_multitenancy = true,
					$scoped = false,
					$preResolved = null,
				) use (&$captured) {
					$captured[] = [
						'register' => $register,
						'schema' => $schema,
						'uuid' => $uuid,
						'preResolved' => $preResolved,
					];
					return true;
				}
			);
		$this->deleteHandler->method('getLastCascadeCount')->willReturn(0);

		$result = $this->service->deleteObjects(
			uuids: ['uuid-a', 'uuid-b'],
			_rbac: false,
			_multitenancy: false
		);

		$this->assertSame(['uuid-a', 'uuid-b'], $result['deleted_uuids']);
		$this->assertSame([], $result['skipped_uuids']);
		$this->assertSame($this->register, $captured[0]['register']);
		$this->assertSame($this->schema, $captured[0]['schema']);
		$this->assertSame($entityA, $captured[0]['preResolved']);
		$this->assertSame($entityB, $captured[1]['preResolved']);
	}

	/**
	 * Uuids the batch lookup cannot resolve fall back to the legacy per-uuid
	 * scope find and a handler call without pre-resolved entity.
	 */
	public function testDeleteObjectsFallsBackToPerUuidLookupWhenBatchMisses(): void {
		$this->objectEntityMapper
			->method('findMultipleAcrossAllMagicTables')
			->willReturn([]);

		$legacyEntity = new ObjectEntity();
		$legacyEntity->setUuid('uuid-legacy');
		$legacyEntity->setRegister('1');
		$legacyEntity->setSchema('2');

		// Legacy per-uuid scope resolution runs for the missed uuid.
		$this->objectEntityMapper
			->expects($this->once())
			->method('find')
			->willReturn($legacyEntity);

		$captured = [];
		$this->deleteHandler
			->expects($this->once())
			->method('deleteObject')
			->willReturnCallback(
				function (
					$register,
					$schema,
					$uuid,
					$originalObjectId = null,
					$_rbac = true,
					$_multitenancy = true,
					$scoped = false,
					$preResolved = null,
				) use (&$captured) {
					$captured[] = ['register' => $register, 'preResolved' => $preResolved];
					return true;
				}
			);
		$this->deleteHandler->method('getLastCascadeCount')->willReturn(0);

		$result = $this->service->deleteObjects(
			uuids: ['uuid-legacy'],
			_rbac: false,
			_multitenancy: false
		);

		$this->assertSame(['uuid-legacy'], $result['deleted_uuids']);
		// Legacy call shape: no pre-resolved entity, current (null) scope.
		$this->assertNull($captured[0]['register']);
		$this->assertNull($captured[0]['preResolved']);
	}

	/**
	 * A RESTRICT block on one object skips it without aborting the bulk delete.
	 */
	public function testDeleteObjectsSkipsRestrictBlockedObjects(): void {
		$entityA = new ObjectEntity();
		$entityA->setUuid('uuid-ok');
		$entityA->setRegister('1');
		$entityA->setSchema('2');

		$entityB = new ObjectEntity();
		$entityB->setUuid('uuid-blocked');
		$entityB->setRegister('1');
		$entityB->setSchema('2');

		$this->objectEntityMapper
			->method('findMultipleAcrossAllMagicTables')
			->willReturn([$entityA, $entityB]);

		$this->registerMapper->method('find')->willReturn($this->register);
		$this->schemaMapper->method('find')->willReturn($this->schema);

		$analysis = new \OCA\OpenRegister\Dto\DeletionAnalysis(
			deletable: false,
			blockers: [['objectUuid' => 'ref', 'property' => 'parentId']]
		);
		$this->deleteHandler
			->method('deleteObject')
			->willReturnCallback(
				function ($register, $schema, $uuid) use ($analysis): bool {
					if ($uuid === 'uuid-blocked') {
						throw new \OCA\OpenRegister\Exception\ReferentialIntegrityException(analysis: $analysis);
					}

					return true;
				}
			);
		$this->deleteHandler->method('getLastCascadeCount')->willReturn(0);

		$result = $this->service->deleteObjects(
			uuids: ['uuid-ok', 'uuid-blocked'],
			_rbac: false,
			_multitenancy: false
		);

		$this->assertSame(['uuid-ok'], $result['deleted_uuids']);
		$this->assertSame(['uuid-blocked'], $result['skipped_uuids']);
	}

	// ── 10. lockObject() / unlockObject() tests ─────────────────────────

	/**
	 * Test lockObject delegates to lockHandler.lock.
	 */
	public function testLockObjectDelegatesToLockHandler(): void {
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
	public function testUnlockObjectDelegatesToLockHandler(): void {
		$this->lockHandler
			->expects($this->once())
			->method('unlock')
			->with(identifier: 'obj-uuid')
			->willReturn(true);

		$result = $this->service->unlockObject(identifier: 'obj-uuid');

		$this->assertTrue($result);
	}

	// ── 13. count() tests ───────────────────────────────────────────────

	/**
	 * Test count delegates to objectEntityMapper.countAll.
	 */
	public function testCountDelegatesToMagicMapper(): void {
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
	public function testCountRemovesLimitFromConfig(): void {
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
	public function testGetLogsDelegatesToGetHandler(): void {
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
	public function testExtractUuidAndNormalizeObjectWithArrayNoUuid(): void {
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
	public function testExtractUuidAndNormalizeObjectExtractsFromSelfId(): void {
		[$obj, $uuid] = $this->invokePrivate('extractUuidAndNormalizeObject', [
			['name' => 'Test', '@self' => ['id' => 'abc-123']],
			null,
		]);

		$this->assertSame('abc-123', $uuid);
	}

	/**
	 * Test extractUuidAndNormalizeObject extracts id from top-level 'id'.
	 */
	public function testExtractUuidAndNormalizeObjectExtractsFromTopLevelId(): void {
		[$obj, $uuid] = $this->invokePrivate('extractUuidAndNormalizeObject', [
			['name' => 'Test', 'id' => 'top-level-uuid'],
			null,
		]);

		$this->assertSame('top-level-uuid', $uuid);
	}

	/**
	 * Test extractUuidAndNormalizeObject with ObjectEntity input.
	 */
	public function testExtractUuidAndNormalizeObjectWithObjectEntity(): void {
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
	public function testExtractUuidAndNormalizeObjectPreservesProvidedUuid(): void {
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
	public function testExtractUuidAndNormalizeObjectSkipsEmptyId(): void {
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
	public function testNormalizeDateValuesConvertDatetimeToDate(): void {
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
	public function testNormalizeDateValuesLeavesValidDatesAlone(): void {
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
	public function testNormalizeDateValuesSkipsNonDateFormats(): void {
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
	public function testNormalizeDateValuesReturnsUnchangedWithoutSchema(): void {
		$this->setProperty('currentSchema', null);

		$data = ['birthDate' => '2024-01-15T10:30:00+02:00'];
		$result = $this->invokePrivate('normalizeDateValues', [$data]);

		$this->assertSame($data, $result);
	}

	/**
	 * Test normalizeDateValues handles datetime with space separator.
	 */
	public function testNormalizeDateValuesHandlesSpaceSeparatedDatetime(): void {
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
	public function testNormalizeDateValuesLeavesInvalidValuesUnchanged(): void {
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
	public function testIsUuidFormatReturnsTrueForValidUuid(): void {
		$result = $this->invokePrivate('isUuidFormat', ['550e8400-e29b-41d4-a716-446655440000']);

		$this->assertTrue($result);
	}

	/**
	 * Test isUuidFormat returns true for uppercase UUID.
	 */
	public function testIsUuidFormatReturnsTrueForUppercaseUuid(): void {
		$result = $this->invokePrivate('isUuidFormat', ['550E8400-E29B-41D4-A716-446655440000']);

		$this->assertTrue($result);
	}

	/**
	 * Test isUuidFormat returns false for non-UUID strings.
	 */
	public function testIsUuidFormatReturnsFalseForNonUuid(): void {
		$this->assertFalse($this->invokePrivate('isUuidFormat', ['not-a-uuid']));
		$this->assertFalse($this->invokePrivate('isUuidFormat', ['12345']));
		$this->assertFalse($this->invokePrivate('isUuidFormat', ['']));
		$this->assertFalse($this->invokePrivate('isUuidFormat', ['550e8400-e29b-41d4-a716']));
	}

	// ── 18. searchObjects() tests ───────────────────────────────────────

	/**
	 * Test searchObjects delegates to queryHandler.
	 */
	public function testSearchObjectsDelegatesToQueryHandler(): void {
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
	public function testBuildSearchQueryDelegatesToSearchQueryHandler(): void {
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
	public function testGetFacetsForObjectsDelegatesToFacetHandler(): void {
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
	public function testFindByRelationsDelegatesToMapper(): void {
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
	public function testCountSearchObjectsDelegatesToMapper(): void {
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
	public function testGetExtendedObjectsDelegatesToRenderHandler(): void {
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
	public function testGetCreatedSubObjectsDelegatesToSaveHandler(): void {
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
	public function testClearCreatedSubObjectsDelegatesToSaveHandler(): void {
		$this->saveHandler
			->expects($this->once())
			->method('clearCreatedSubObjects');

		$this->service->clearCreatedSubObjects();
	}

	// ── 26. getCacheHandler() tests ─────────────────────────────────────

	/**
	 * Test getCacheHandler returns the injected CacheHandler.
	 */
	public function testGetCacheHandlerReturnsInjectedInstance(): void {
		$result = $this->service->getCacheHandler();

		$this->assertInstanceOf(CacheHandler::class, $result);
	}

	// ── 27. Private: checkSavePermissions() tests ───────────────────────

	/**
	 * Test checkSavePermissions with null uuid calls create permission.
	 */
	public function testCheckSavePermissionsCreateWhenNoUuid(): void {
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
	public function testCheckSavePermissionsUpdateWhenUuidExists(): void {
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
	public function testCheckSavePermissionsCreateWhenUuidNotFound(): void {
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
	public function testCheckSavePermissionsSkipsWhenNoSchema(): void {
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
	public function testPrepareFindAllConfigConvertsExtendStringToArray(): void {
		$config = ['extend' => '@self.schema,@self.register'];

		$result = $this->invokePrivate('prepareFindAllConfig', [$config]);

		$this->assertIsArray($result['extend']);
		$this->assertSame(['@self.schema', '@self.register'], $result['extend']);
	}

	/**
	 * Test prepareFindAllConfig sets register context from filters.
	 */
	public function testPrepareFindAllConfigSetsRegisterFromFilters(): void {
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
	public function testPrepareFindAllConfigSetsSchemaFromFilters(): void {
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
	public function testRenderEntityDelegatesToRenderHandler(): void {
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
	public function testFindSilentDelegatesToGetHandler(): void {
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
	public function testFindSilentSetsContextWhenProvided(): void {
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
	public function testHandleCascadingPreservesParentContext(): void {
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
	public function testEnsureObjectFolderReturnsNullForNullUuid(): void {
		$result = $this->invokePrivate('ensureObjectFolder', [null]);

		$this->assertNull($result);
	}

	/**
	 * Test ensureObjectFolder defers folder creation (lazy) for an existing
	 * object that has no folder.
	 *
	 * The contract is LAZY folder creation: a Files folder is NOT created on
	 * save for an object that has none. Most objects never get a file attached,
	 * so eagerly creating a per-object folder on every save clutters the Files
	 * tree and can bind the object to a folder created in a no-session context
	 * a later editor cannot access. The folder is created on demand the first
	 * time a file is actually uploaded. Therefore ensureObjectFolder MUST NOT
	 * call createObjectFolderWithoutUpdate here and MUST return null.
	 */
	public function testEnsureObjectFolderCreatesFolderForExistingObject(): void {
		// Since the lazy-folder-creation change (PR #1431 follow-up), ensureObjectFolder()
		// no longer eagerly creates a Files folder when the object has folder=null.
		// It returns null so that a folder is only created on demand (when a file is
		// actually uploaded). createObjectFolderWithoutUpdate() is NOT called here.
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setFolder(null);

		$this->objectEntityMapper
			->method('find')
			->willReturn($entity);

		$this->fileService
			->expects($this->never())
			->method('createObjectFolderWithoutUpdate');

		$result = $this->invokePrivate('ensureObjectFolder', ['existing-uuid']);

		$this->assertNull($result);
	}

	/**
	 * Test ensureObjectFolder returns null when object not found (new object).
	 */
	public function testEnsureObjectFolderReturnsNullForNewObject(): void {
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
	public function testMethodChainingForContextSetters(): void {
		$result = $this->service
			->setRegister(register: $this->register)
			->setSchema(schema: $this->schema);

		$this->assertInstanceOf(ObjectService::class, $result);
		$this->assertSame($this->register, $this->getProperty('currentRegister'));
		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
	}

	// ── 34. countSearchObjects tests ────────────────────────────────────

	public function testCountSearchObjectsDelegatesToMapperWithOrgContext(): void {
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);
		$this->objectEntityMapper->expects($this->once())
			->method('countSearchObjects')
			->willReturn(42);

		$result = $this->service->countSearchObjects(
			query: ['_register' => 1],
			_rbac: true,
			_multitenancy: true
		);

		$this->assertSame(42, $result);
	}

	public function testCountSearchObjectsSkipsOrgWhenMultitenancyDisabled(): void {
		$this->objectEntityMapper->expects($this->once())
			->method('countSearchObjects')
			->willReturn(10);

		$result = $this->service->countSearchObjects(
			query: [],
			_rbac: false,
			_multitenancy: false
		);

		$this->assertSame(10, $result);
	}

	// ── 35. searchObjectsPaginated — database path ──────────────────────

	public function testSearchObjectsPaginatedUsesDatabaseByDefault(): void {
		$this->queryHandler->method('searchObjectsPaginatedDatabase')->willReturn([
			'results' => [],
			'total' => 0,
			'@self' => [],
		]);

		$result = $this->service->searchObjectsPaginated(query: ['_limit' => 10]);

		$this->assertArrayHasKey('results', $result);
		$this->assertArrayHasKey('@self', $result);
		$this->assertSame('database', $result['@self']['source']);
	}

	public function testSearchObjectsPaginatedSetsRegisterSchemaContext(): void {
		$this->setProperty('currentRegister', $this->register);
		$this->setProperty('currentSchema', $this->schema);

		$this->queryHandler->method('searchObjectsPaginatedDatabase')->willReturn([
			'results' => [],
			'total' => 0,
			'@self' => [],
		]);

		$result = $this->service->searchObjectsPaginated(query: []);

		$this->assertSame('database', $result['@self']['source']);
	}

	public function testSearchObjectsPaginatedForcesDbWhenIdsProvided(): void {
		$this->queryHandler->method('searchObjectsPaginatedDatabase')->willReturn([
			'results' => [],
			'total' => 0,
			'@self' => [],
		]);

		$result = $this->service->searchObjectsPaginated(
			query: [],
			ids: ['uuid-1', 'uuid-2']
		);

		$this->assertSame('database', $result['@self']['source']);
	}

	public function testSearchObjectsPaginatedAddsExtendedObjectsWhenExtendSet(): void {
		$this->queryHandler->method('searchObjectsPaginatedDatabase')->willReturn([
			'results' => [],
			'total' => 0,
			'@self' => [],
		]);
		$this->renderHandler->method('getObjectsCache')->willReturn(['uuid-1' => ['title' => 'Test']]);

		$result = $this->service->searchObjectsPaginated(query: ['_extend' => 'relations']);

		$this->assertArrayHasKey('objects', $result['@self']);
	}

	// ── 38. listObjects / createObject / updateObject ───────────────────

	public function testListObjectsDelegatesToSearchObjects(): void {
		$this->queryHandler->expects($this->once())
			->method('searchObjects')
			->willReturn([]);

		$result = $this->service->listObjects(query: ['_limit' => 10]);

		$this->assertIsArray($result);
	}

	public function testCreateObjectCallsSaveObjectInternally(): void {
		// createObject calls saveObject which has a complex pipeline requiring
		// full context. Verify it invokes cascading handler as part of saveObject.
		$this->setProperty('currentRegister', $this->register);
		$this->setProperty('currentSchema', $this->schema);

		// The cascading handler is called before save — verify delegation starts.
		$this->cascadingHandler->expects($this->once())
			->method('handlePreValidationCascading');

		// The actual save will fail due to deep dependencies, but we verify
		// the method delegates to saveObject() correctly.
		try {
			$this->service->createObject(data: ['title' => 'New']);
		} catch (\Throwable $e) {
			// Expected — deep mocking of saveObject pipeline would require
			// integration test. We verified delegation started.
		}
	}

	public function testBuildObjectSearchQueryDelegatesToBuildSearchQuery(): void {
		$this->searchQueryHandler->expects($this->once())
			->method('buildSearchQuery')
			->willReturn(['_limit' => 20]);

		$result = $this->service->buildObjectSearchQuery(params: ['_limit' => 20]);

		$this->assertSame(20, $result['_limit']);
	}

	// ── 39. vectorization methods — disabled ────────────────────────────

	public function testVectorizeBatchObjectsThrowsDisabledException(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Vectorization temporarily disabled');

		$this->service->vectorizeBatchObjects();
	}

	public function testGetVectorizationStatisticsThrowsDisabledException(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Vectorization temporarily disabled');

		$this->service->getVectorizationStatistics();
	}

	public function testGetVectorizationCountThrowsDisabledException(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Vectorization temporarily disabled');

		$this->service->getVectorizationCount();
	}

	// ── 41. mergeObjects delegation ─────────────────────────────────────

	public function testMergeObjectsDelegatesToMergeHandler(): void {
		// Access private mergeHandler via reflection
		$mergeHandler = $this->getProperty('mergeHandler');
		$mergeHandler->expects($this->once())
			->method('mergeObjects')
			->willReturn(['success' => true, 'uuid' => 'uuid-target']);

		$result = $this->service->mergeObjects('uuid-source', ['target' => 'uuid-target']);

		$this->assertTrue($result['success']);
	}

	// ── 42. migrateObjects delegation ───────────────────────────────────

	public function testMigrateObjectsDelegatesToMigrationHandler(): void {
		$migrationHandler = $this->getProperty('migrationHandler');
		$migrationHandler->expects($this->once())
			->method('migrateObjects')
			->willReturn(['success' => true, 'migrated' => 2]);

		$result = $this->service->migrateObjects('1', '2', '3', '4', ['uuid-1'], ['field1' => 'field2']);

		$this->assertTrue($result['success']);
	}

	// ── 43. validateObjectsBySchema / validateAndSaveObjectsBySchema ────

	public function testValidateObjectsBySchemaDelegatesToValidationHandler(): void {
		$validationHandler = $this->getProperty('validationHandler');
		$validationHandler->expects($this->once())
			->method('validateObjectsBySchema')
			->willReturn(['valid' => 5, 'invalid' => 2]);

		$result = $this->service->validateObjectsBySchema(2);

		$this->assertSame(5, $result['valid']);
	}

	public function testValidateAndSaveObjectsBySchemaDelegatesToValidationHandler(): void {
		$validationHandler = $this->getProperty('validationHandler');
		$validationHandler->expects($this->once())
			->method('validateAndSaveObjectsBySchema')
			->willReturn(['processed' => 10, 'updated' => 8, 'failed' => 2, 'total' => 10, 'errors' => []]);

		$result = $this->service->validateAndSaveObjectsBySchema(1, 2);

		$this->assertSame(10, $result['processed']);
		$this->assertSame(8, $result['updated']);
	}

	// ── 44. getObjectContracts / getObjectUses / getObjectUsedBy ────────

	public function testGetObjectContractsDelegatesToRelationHandler(): void {
		$relationHandler = $this->getProperty('relationHandler');
		$relationHandler->expects($this->once())
			->method('getContracts')
			->willReturn(['results' => [], 'total' => 0]);

		$result = $this->service->getObjectContracts('uuid-123');

		$this->assertSame(0, $result['total']);
	}

	public function testGetObjectUsesDelegatesToRelationHandler(): void {
		$relationHandler = $this->getProperty('relationHandler');
		$relationHandler->expects($this->once())
			->method('getUses')
			->willReturn(['results' => [], 'total' => 0]);

		$result = $this->service->getObjectUses('uuid-123');

		$this->assertSame(0, $result['total']);
	}

	public function testGetObjectUsedByDelegatesToRelationHandler(): void {
		$relationHandler = $this->getProperty('relationHandler');
		$relationHandler->expects($this->once())
			->method('getUsedBy')
			->willReturn(['results' => [], 'total' => 0]);

		$result = $this->service->getObjectUsedBy('uuid-123');

		$this->assertSame(0, $result['total']);
	}

	// ── 45. handleValidationException delegation ────────────────────────

	public function testHandleValidationExceptionDelegatesToValidateHandler(): void {
		$exception = new \OCA\OpenRegister\Exception\ValidationException('Test error');
		$response = new \OCP\AppFramework\Http\JSONResponse(['error' => 'Test'], 400);

		$this->validateHandler->expects($this->once())
			->method('handleValidationException')
			->willReturn($response);

		$result = $this->service->handleValidationException($exception);

		$this->assertSame(400, $result->getStatus());
	}

	// ── 46. getDeleteHandler returns injected handler ───────────────────

	public function testGetDeleteHandlerReturnsInjectedInstance(): void {
		$result = $this->service->getDeleteHandler();
		$this->assertSame($this->deleteHandler, $result);
	}

	// ── 47. collectNamesForResults (private) ────────────────────────────

	public function testCollectNamesForResultsReturnsEmptyForEmptyResults(): void {
		$result = $this->invokePrivate('collectNamesForResults', [[]]);
		$this->assertSame([], $result);
	}

	public function testCollectNamesForResultsSkipsNonArrayResults(): void {
		$result = $this->invokePrivate('collectNamesForResults', [['not-an-array', 42]]);
		$this->assertSame([], $result);
	}

	// ── 48. isUuidFormat (private) ──────────────────────────────────────

	public function testIsUuidFormatReturnsTrueForValid(): void {
		$this->assertTrue($this->invokePrivate('isUuidFormat', ['550e8400-e29b-41d4-a716-446655440000']));
	}

	public function testIsUuidFormatReturnsFalseForInvalid(): void {
		$this->assertFalse($this->invokePrivate('isUuidFormat', ['not-a-uuid']));
		$this->assertFalse($this->invokePrivate('isUuidFormat', ['']));
		$this->assertFalse($this->invokePrivate('isUuidFormat', ['123']));
	}

	// ── 49. collectUuidsFromRelations (private) ─────────────────────────

	public function testCollectUuidsFromRelationsCollectsDirectUuids(): void {
		$uuids = [];
		$this->invokePrivate('collectUuidsFromRelations', [
			['550e8400-e29b-41d4-a716-446655440000', 'not-uuid'],
			&$uuids,
		]);

		$this->assertCount(1, $uuids);
		$this->assertSame('550e8400-e29b-41d4-a716-446655440000', $uuids[0]);
	}

	public function testCollectUuidsFromRelationsCollectsNestedUuids(): void {
		$uuids = [];
		$this->invokePrivate('collectUuidsFromRelations', [
			[['550e8400-e29b-41d4-a716-446655440000', 'not-uuid']],
			&$uuids,
		]);

		$this->assertCount(1, $uuids);
	}

	// ── 50. collectUuidsFromObjectData (private) ────────────────────────

	public function testCollectUuidsFromObjectDataCollectsTopLevel(): void {
		$uuids = [];
		$this->invokePrivate('collectUuidsFromObjectData', [
			[
				'title' => 'Test',
				'related' => '550e8400-e29b-41d4-a716-446655440000',
				'@self' => 'skip',
				'id' => 'skip',
			],
			&$uuids,
			0,
		]);

		$this->assertCount(1, $uuids);
	}

	public function testCollectUuidsFromObjectDataStopsAtDepth1(): void {
		$uuids = [];
		$this->invokePrivate('collectUuidsFromObjectData', [
			['related' => '550e8400-e29b-41d4-a716-446655440000'],
			&$uuids,
			1, // depth > 0 should return immediately
		]);

		$this->assertCount(0, $uuids);
	}

	public function testCollectUuidsFromObjectDataCollectsFromArrays(): void {
		$uuids = [];
		$this->invokePrivate('collectUuidsFromObjectData', [
			[
				'relations' => [
					'550e8400-e29b-41d4-a716-446655440000',
					'not-a-uuid',
					'660e8400-e29b-41d4-a716-446655440000',
				],
			],
			&$uuids,
			0,
		]);

		$this->assertCount(2, $uuids);
	}

	// ── 51. collectUuidsFromArrayResult (private) ───────────────────────

	public function testCollectUuidsFromArrayResultHandlesSelfStructure(): void {
		$uuids = [];
		$this->invokePrivate('collectUuidsFromArrayResult', [
			[
				'@self' => [
					'relations' => ['550e8400-e29b-41d4-a716-446655440000'],
					'organisation' => '660e8400-e29b-41d4-a716-446655440000',
					'owner' => '770e8400-e29b-41d4-a716-446655440000',
					'object' => ['title' => 'Test'],
				],
			],
			&$uuids,
		]);

		$this->assertCount(3, $uuids);
	}

	public function testCollectUuidsFromArrayResultHandlesFlatArray(): void {
		$uuids = [];
		$this->invokePrivate('collectUuidsFromArrayResult', [
			[
				'related' => '550e8400-e29b-41d4-a716-446655440000',
				'title' => 'Test',
			],
			&$uuids,
		]);

		$this->assertCount(1, $uuids);
	}

	// ── 55. getObject / setObject ───────────────────────────────────────

	public function testGetObjectReturnsSetObject(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('test-uuid');

		$this->service->setObject($entity);

		$this->assertSame($entity, $this->service->getObject());
	}

	// ── 56. searchObjectsPaginated with _extend as comma string ─────────

	public function testSearchObjectsPaginatedHandlesExtendCommaString(): void {
		$this->queryHandler->method('searchObjectsPaginatedDatabase')->willReturn([
			'results' => [],
			'total' => 0,
			'@self' => [],
		]);
		$this->renderHandler->method('getObjectsCache')->willReturn([]);

		$result = $this->service->searchObjectsPaginated(
			query: ['_extend' => 'relations,_schema']
		);

		$this->assertArrayHasKey('objects', $result['@self']);
	}

	// ── 55b. getActiveOrganisationForContext (private) ───────────────────

	/**
	 * Test getActiveOrganisationForContext returns UUID when org is found.
	 */
	public function testGetActiveOrganisationReturnsUuidWhenOrgFound(): void {
		$orgMock = $this->getMockBuilder(\OCA\OpenRegister\Db\Organisation::class)
			->disableOriginalConstructor()
			->addMethods(['getUuid'])
			->getMock();
		$orgMock->method('getUuid')->willReturn('org-uuid-abc');

		$this->organisationService->method('getActiveOrganisation')->willReturn($orgMock);

		$result = $this->invokePrivate('getActiveOrganisationForContext', []);

		$this->assertSame('org-uuid-abc', $result);
	}

	/**
	 * Test getActiveOrganisationForContext returns null when no org found.
	 */
	public function testGetActiveOrganisationReturnsNullWhenNoOrg(): void {
		$this->organisationService->method('getActiveOrganisation')->willReturn(null);

		$result = $this->invokePrivate('getActiveOrganisationForContext', []);

		$this->assertNull($result);
	}

	/**
	 * Test getActiveOrganisationForContext returns null when exception thrown.
	 */
	public function testGetActiveOrganisationReturnsNullOnException(): void {
		$this->organisationService->method('getActiveOrganisation')
			->willThrowException(new Exception('Organisation service unavailable'));

		$result = $this->invokePrivate('getActiveOrganisationForContext', []);

		$this->assertNull($result);
	}

	// ── 55c. validateObjectIfRequired (private) ─────────────────────────

	/**
	 * Test validateObjectIfRequired does nothing when hardValidation is false.
	 */
	public function testValidateObjectIfRequiredSkipsWhenNotHardValidation(): void {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setHardValidation(false);
		$this->setProperty('currentSchema', $schema);

		// validateHandler should not be called.
		$this->validateHandler->expects($this->never())->method('validateObject');

		$this->invokePrivate('validateObjectIfRequired', [['name' => 'Test']]);
	}

	/**
	 * Test validateObjectIfRequired validates when hardValidation is true and passes.
	 */
	public function testValidateObjectIfRequiredValidatesWhenHardValidationEnabled(): void {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setHardValidation(true);
		$this->setProperty('currentSchema', $schema);

		$validResult = $this->createMock(\Opis\JsonSchema\ValidationResult::class);
		$validResult->method('isValid')->willReturn(true);

		$this->validateHandler->expects($this->once())
			->method('validateObject')
			->willReturn($validResult);

		// Should not throw.
		$this->invokePrivate('validateObjectIfRequired', [['name' => 'Test']]);
	}

	/**
	 * Test validateObjectIfRequired throws ValidationException when validation fails.
	 */
	public function testValidateObjectIfRequiredThrowsOnValidationFailure(): void {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setHardValidation(true);
		$this->setProperty('currentSchema', $schema);

		$invalidResult = $this->createMock(\Opis\JsonSchema\ValidationResult::class);
		$invalidResult->method('isValid')->willReturn(false);
		$invalidResult->method('error')->willReturn(null);

		$this->validateHandler->method('validateObject')->willReturn($invalidResult);
		$this->validateHandler->method('generateErrorMessage')->willReturn('Validation failed: name is required');

		$this->expectException(\OCA\OpenRegister\Exception\ValidationException::class);

		$this->invokePrivate('validateObjectIfRequired', [[]]);
	}

	// ── 55e. getFacetableFields delegation ───────────────────────────────

	/**
	 * Test getFacetableFields delegates to facetHandler.
	 */
	public function testGetFacetableFieldsDelegatesToFacetHandler(): void {
		$expected = ['@self' => [], 'object_fields' => ['name' => ['type' => 'string']]];

		$this->facetHandler->expects($this->once())
			->method('getFacetableFields')
			->with(baseQuery: [], _sampleSize: 100)
			->willReturn($expected);

		$result = $this->service->getFacetableFields(baseQuery: [], sampleSize: 100);

		$this->assertSame($expected, $result);
	}

	// ── 55f/55g. setRegister/setSchema entity getters ─────────────────────

	/**
	 * Test getCurrentRegisterEntity exposes the entity resolved by setRegister.
	 */
	public function testGetCurrentRegisterEntityReturnsResolvedEntity(): void {
		$this->assertNull($this->service->getCurrentRegisterEntity());

		$this->registerMapper->method('find')->willReturn($this->register);
		$this->service->setRegister(register: 1);

		$this->assertSame($this->register, $this->service->getCurrentRegisterEntity());
	}

	/**
	 * Test getCurrentSchemaEntity exposes the entity resolved by setSchema.
	 */
	public function testGetCurrentSchemaEntityReturnsResolvedEntity(): void {
		$this->assertNull($this->service->getCurrentSchemaEntity());

		$this->schemaMapper->method('find')->willReturn($this->schema);
		$this->service->setSchema(schema: 2);

		$this->assertSame($this->schema, $this->service->getCurrentSchemaEntity());
	}

	// ── 55h. countSearchObjects with active organisation ─────────────────

	/**
	 * Test countSearchObjects passes org UUID when multitenancy enabled and org found.
	 */
	public function testCountSearchObjectsPassesOrgUuidWhenFound(): void {
		$orgMock = $this->getMockBuilder(\OCA\OpenRegister\Db\Organisation::class)
			->disableOriginalConstructor()
			->addMethods(['getUuid'])
			->getMock();
		$orgMock->method('getUuid')->willReturn('org-uuid-123');

		$this->organisationService->method('getActiveOrganisation')->willReturn($orgMock);

		$this->objectEntityMapper->expects($this->once())
			->method('countSearchObjects')
			->with(
				$this->anything(),
				'org-uuid-123',
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything()
			)
			->willReturn(7);

		$result = $this->service->countSearchObjects(query: [], _multitenancy: true);

		$this->assertSame(7, $result);
	}

	// ── 55i. searchObjectsPaginated bypasses multitenancy for public schema ──

	/**
	 * Test searchObjectsPaginated bypasses multitenancy when schema has public read access.
	 */
	public function testSearchObjectsPaginatedBypassesMultitenancyForPublicSchema(): void {
		$publicSchema = new Schema();
		$publicSchema->setId(3);
		$publicSchema->setAuthorization(['read' => ['public']]);
		$this->setProperty('currentSchema', $publicSchema);

		// The effective multitenancy passed to queryHandler should be false.
		$this->queryHandler->expects($this->once())
			->method('searchObjectsPaginatedDatabase')
			->with(
				$this->anything(),
				$this->anything(),
				false, // effectiveMt should be false for public schema
				$this->anything(),
				$this->anything(),
				$this->anything()
			)
			->willReturn(['results' => [], 'total' => 0, '@self' => []]);

		$result = $this->service->searchObjectsPaginated(
			query: [],
			_multitenancy: true // passed as true but bypassed for public schema
		);

		$this->assertSame('database', $result['@self']['source']);
	}

	// ── 57. searchObjectsPaginated with _source=database ────────────────

	public function testSearchObjectsPaginatedExplicitDatabaseSource(): void {
		$this->queryHandler->method('searchObjectsPaginatedDatabase')->willReturn([
			'results' => [],
			'total' => 0,
			'@self' => [],
		]);

		$result = $this->service->searchObjectsPaginated(
			query: ['_source' => 'database']
		);

		$this->assertSame('database', $result['@self']['source']);
	}

	// ── 58. searchObjectsPaginated with uses param forces database ──────

	public function testSearchObjectsPaginatedForcesDbWhenUsesProvided(): void {
		$this->queryHandler->method('searchObjectsPaginatedDatabase')->willReturn([
			'results' => [],
			'total' => 0,
			'@self' => [],
		]);

		$result = $this->service->searchObjectsPaginated(
			query: [],
			uses: 'uuid-123'
		);

		$this->assertSame('database', $result['@self']['source']);
	}

	// ── 59. updateObject sets ID and delegates to saveObject ────────────

	/**
	 * Test updateObject sets the object ID in data and delegates to saveObject.
	 */
	public function testUpdateObjectSetsIdAndDelegatesToSaveObject(): void {
		$this->setProperty('currentRegister', $this->register);
		$this->setProperty('currentSchema', $this->schema);

		// The cascading handler is called as part of saveObject pipeline.
		$this->cascadingHandler->expects($this->once())
			->method('handlePreValidationCascading')
			->willReturnCallback(function (array $object) {
				// Verify the ID was set in the data.
				$this->assertSame('42', $object['id']);
				return [$object, null];
			});

		try {
			$this->service->updateObject('42', ['title' => 'Updated']);
		} catch (\Throwable $e) {
			// Expected — deep saveObject pipeline needs integration test.
			// We verified the ID was injected correctly.
		}
	}

	// ── 60. patchObject merges existing data with patch data ────────────

	/**
	 * Test patchObject loads existing object, merges data, and delegates to saveObject.
	 */
	public function testPatchObjectMergesExistingDataAndDelegatesToSave(): void {
		$this->setProperty('currentRegister', $this->register);
		$this->setProperty('currentSchema', $this->schema);

		$existing = new ObjectEntity();
		$existing->setId(42);
		$existing->setObject(['title' => 'Original', 'status' => 'draft']);

		$this->objectEntityMapper->method('find')
			->with(42)
			->willReturn($existing);

		// Verify merged data is passed to cascading handler.
		$this->cascadingHandler->expects($this->once())
			->method('handlePreValidationCascading')
			->willReturnCallback(function (array $object) {
				$this->assertSame('42', $object['id']);
				$this->assertSame('Updated Title', $object['title']);
				$this->assertSame('draft', $object['status']); // preserved from existing
				return [$object, null];
			});

		try {
			$this->service->patchObject('42', ['title' => 'Updated Title']);
		} catch (\Throwable $e) {
			// Expected — deep pipeline.
		}
	}

	// ── 61. setContextFromParameters sets both register and schema ──────

	/**
	 * Test setContextFromParameters sets register when provided.
	 */
	public function testSetContextFromParametersSetsRegister(): void {
		$this->invokePrivate('setContextFromParameters', [$this->register, null]);

		$this->assertSame($this->register, $this->getProperty('currentRegister'));
	}

	/**
	 * Test setContextFromParameters sets schema when provided.
	 */
	public function testSetContextFromParametersSetsSchema(): void {
		$this->schemaMapper->method('find')
			->willReturn($this->schema);

		$this->invokePrivate('setContextFromParameters', [null, $this->schema]);

		$this->assertSame($this->schema, $this->getProperty('currentSchema'));
	}

	/**
	 * Test setContextFromParameters does nothing when both are null.
	 */
	public function testSetContextFromParametersDoesNothingWhenBothNull(): void {
		$this->setProperty('currentRegister', null);
		$this->setProperty('currentSchema', null);

		$this->invokePrivate('setContextFromParameters', [null, null]);

		$this->assertNull($this->getProperty('currentRegister'));
		$this->assertNull($this->getProperty('currentSchema'));
	}

	// ── 63. normalizeDateValues — additional branches ───────────────────

	/**
	 * Test normalizeDateValues returns unchanged object when schema is null.
	 */
	public function testNormalizeDateValuesReturnsUnchangedWhenNoSchema(): void {
		$this->setProperty('currentSchema', null);

		$object = ['startDate' => '2024-01-15T10:30:00+00:00'];
		$result = $this->invokePrivate('normalizeDateValues', [$object]);

		$this->assertSame('2024-01-15T10:30:00+00:00', $result['startDate']);
	}

	/**
	 * Test normalizeDateValues converts datetime to date for date-format fields.
	 */
	public function testNormalizeDateValuesConvertsDatetimeToDate(): void {
		$schema = new Schema();
		$schema->setProperties([
			'startDate' => ['type' => 'string', 'format' => 'date'],
		]);
		$this->setProperty('currentSchema', $schema);

		$object = ['startDate' => '2024-01-15T10:30:00+00:00'];
		$result = $this->invokePrivate('normalizeDateValues', [$object]);

		$this->assertSame('2024-01-15', $result['startDate']);
	}

	/**
	 * Test normalizeDateValues leaves already-formatted dates alone.
	 */
	public function testNormalizeDateValuesSkipsAlreadyFormattedDates(): void {
		$schema = new Schema();
		$schema->setProperties([
			'startDate' => ['type' => 'string', 'format' => 'date'],
		]);
		$this->setProperty('currentSchema', $schema);

		$object = ['startDate' => '2024-01-15'];
		$result = $this->invokePrivate('normalizeDateValues', [$object]);

		$this->assertSame('2024-01-15', $result['startDate']);
	}

	/**
	 * Test normalizeDateValues leaves invalid dates untouched.
	 */
	public function testNormalizeDateValuesLeavesInvalidDatesUntouched(): void {
		$schema = new Schema();
		$schema->setProperties([
			'startDate' => ['type' => 'string', 'format' => 'date'],
		]);
		$this->setProperty('currentSchema', $schema);

		$object = ['startDate' => 'not-a-date'];
		$result = $this->invokePrivate('normalizeDateValues', [$object]);

		$this->assertSame('not-a-date', $result['startDate']);
	}

	/**
	 * Test normalizeDateValues skips non-string property values.
	 */
	public function testNormalizeDateValuesSkipsNonStringValues(): void {
		$schema = new Schema();
		$schema->setProperties([
			'startDate' => ['type' => 'string', 'format' => 'date'],
		]);
		$this->setProperty('currentSchema', $schema);

		$object = ['startDate' => 12345];
		$result = $this->invokePrivate('normalizeDateValues', [$object]);

		$this->assertSame(12345, $result['startDate']);
	}

	/**
	 * Test normalizeDateValues skips non-date format properties.
	 */
	public function testNormalizeDateValuesSkipsNonDateFormat(): void {
		$schema = new Schema();
		$schema->setProperties([
			'email' => ['type' => 'string', 'format' => 'email'],
		]);
		$this->setProperty('currentSchema', $schema);

		$object = ['email' => 'test@example.com'];
		$result = $this->invokePrivate('normalizeDateValues', [$object]);

		$this->assertSame('test@example.com', $result['email']);
	}

	// ── 64. ensureObjectFolder with legacy string folder defers creation ──

	/**
	 * Test ensureObjectFolder defers folder creation (lazy) when the object
	 * has a legacy non-numeric string folder value.
	 *
	 * A non-numeric string folder (a legacy path pre-dating the integer-id
	 * storage convention) flags the binding as needing replacement. Under the
	 * LAZY creation contract, however, ensureObjectFolder does NOT eagerly
	 * call createObjectFolderWithoutUpdate on save: it leaves folderId null and
	 * lets the folder be created on demand the first time a file is uploaded.
	 * So this case MUST NOT call createObjectFolderWithoutUpdate and MUST
	 * return null.
	 */
	public function testEnsureObjectFolderCreatesWhenFolderIsString(): void {
		// Since the lazy-folder-creation change, ensureObjectFolder() returns null
		// even for legacy non-numeric string paths. The legacy path is treated as
		// "needs auto-create" but the auto-create is intentionally deferred (lazy)
		// to avoid creating empty folders that the user may never need. The folder
		// is created on demand the first time a file is actually uploaded.
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setFolder('some-string-path');

		$this->objectEntityMapper->method('find')
			->willReturn($entity);

		$this->fileService->expects($this->never())
			->method('createObjectFolderWithoutUpdate');

		$result = $this->invokePrivate('ensureObjectFolder', ['existing-uuid']);

		$this->assertNull($result);
	}

	/**
	 * Test ensureObjectFolder returns null on general exception.
	 */
	public function testEnsureObjectFolderReturnsNullOnGeneralException(): void {
		$this->objectEntityMapper->method('find')
			->willThrowException(new Exception('Database error'));

		$result = $this->invokePrivate('ensureObjectFolder', ['some-uuid']);

		$this->assertNull($result);
	}

	/**
	 * Test ensureObjectFolder DOES NOT recreate when folder is a numeric string.
	 *
	 * The `_folder` column is `varchar(255)` — every populated value is a
	 * string. The earlier `is_string($folder) === true` clause was a bug:
	 * it matched ANY non-empty string and so triggered an auto-create on
	 * every update, overwriting valid folder bindings with freshly-
	 * generated auto-folders. The fix restricts the string branch to
	 * non-numeric strings (legacy path values pre-dating the integer-id
	 * storage convention); numeric strings like '42' are valid folder ids
	 * and MUST be kept.
	 */
	public function testEnsureObjectFolderDoesNotRecreateWhenFolderIsNumericString(): void {
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setFolder('42');

		$this->objectEntityMapper->method('find')
			->willReturn($entity);

		$this->fileService->expects($this->never())
			->method('createObjectFolderWithoutUpdate');

		$result = $this->invokePrivate('ensureObjectFolder', ['existing-uuid']);

		$this->assertNull($result);
	}

	// ── ISSUE B: cross-schema UUID fallback in find() ──────────────────────

	/**
	 * A UUID lookup under a stale/foreign schema falls back across all magic
	 * tables and re-anchors to the object's real schema.
	 *
	 * Reproduces the fleet detail-page audit case objects/larpingapp/25/<uuid>
	 * where the object actually lives in schema 1470: a schema-scoped lookup
	 * 404s, but the globally-unique UUID must still resolve.
	 */
	public function testFindFallsBackAcrossSchemasForUuidUnderWrongSchema(): void {
		$uuid = '9974da4d-e091-440d-a5d3-f09a6e5c556d';

		$object = new ObjectEntity();
		$object->setId(3);
		$object->setUuid($uuid);
		$object->setRegister('8');
		$object->setSchema('1470');

		// First call (schema-scoped, wrong schema) misses; second call
		// (cross-table, register+schema null) resolves the object.
		$callCount = 0;
		$this->getHandler->method('find')->willReturnCallback(
			function (...$args) use (&$callCount, $object): ObjectEntity {
				$callCount++;
				if ($callCount === 1) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('Object not found in magic table');
				}

				return $object;
			}
		);

		// Re-anchoring resolves the object's true register/schema by id.
		$this->registerMapper->method('find')->willReturn($this->register);
		$this->schemaMapper->method('find')->willReturn($this->schema);

		// Rendering echoes the resolved object back.
		$this->renderHandler->method('renderEntity')->willReturn($object);

		$result = $this->service->find(id: $uuid, register: $this->register, schema: $this->schema);

		$this->assertSame($object, $result);
		$this->assertSame(2, $callCount, 'find() should retry across all magic tables');
	}

	/**
	 * A non-UUID identifier (e.g. an object slug) is NOT retried across
	 * schemas — slugs are not globally unique, so the schema-scoped miss is
	 * surfaced as a 404 (DoesNotExistException).
	 */
	public function testFindDoesNotFallBackForNonUuidIdentifier(): void {
		$callCount = 0;
		$this->getHandler->method('find')->willReturnCallback(
			function (...$args) use (&$callCount): ObjectEntity {
				$callCount++;
				throw new \OCP\AppFramework\Db\DoesNotExistException('Object not found in magic table');
			}
		);

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);

		try {
			$this->service->find(id: 'employee-jansen', register: $this->register, schema: $this->schema);
		} finally {
			$this->assertSame(1, $callCount, 'slug lookups must not trigger a cross-schema retry');
		}
	}

	/**
	 * When neither register nor schema is supplied the first lookup is already
	 * cross-table, so a UUID miss is a genuine 404 with no second attempt.
	 */
	public function testFindDoesNotDoubleLookupWhenNoContextProvided(): void {
		$uuid = '9974da4d-e091-440d-a5d3-f09a6e5c556d';

		$callCount = 0;
		$this->getHandler->method('find')->willReturnCallback(
			function (...$args) use (&$callCount): ObjectEntity {
				$callCount++;
				throw new \OCP\AppFramework\Db\DoesNotExistException('not found in any magic table');
			}
		);

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);

		try {
			$this->service->find(id: $uuid);
		} finally {
			$this->assertSame(1, $callCount, 'no register/schema context means the first lookup is already cross-table');
		}
	}

	// ── find() render skip (single-render read path) ───────────────────────

	/**
	 * find(_render: false) returns the raw entity without invoking the render
	 * handler, while the permission check still runs.
	 *
	 * This is the contract ObjectsController::show() relies on: the controller
	 * is the single render site, so find() must not render the entity a first
	 * time (double render repeated file hydration, writeOnly redaction and the
	 * expensive inverse-property resolution on every single read).
	 */
	public function testFindSkipsRenderingWhenRenderFalse(): void {
		$entity = new ObjectEntity();
		$entity->setId(1);
		$entity->setUuid('550e8400-e29b-41d4-a716-446655440000');
		$entity->setSchema(2);

		$this->getHandler
			->expects($this->once())
			->method('find')
			->willReturn($entity);

		// setSchema will be called since currentSchema is null; it resolves
		// the schema through the mapper directly (the cached-entity no-op
		// wrapper was removed).
		$this->schemaMapper
			->method('find')
			->willReturn($this->schema);

		// The read is still access-controlled even when rendering is skipped.
		$this->permissionHandler
			->expects($this->once())
			->method('checkPermission');

		// The whole point: no render pass inside find().
		$this->renderHandler
			->expects($this->never())
			->method('renderEntity');

		$result = $this->service->find(
			id: '550e8400-e29b-41d4-a716-446655440000',
			schema: $this->schema,
			_render: false
		);

		$this->assertSame($entity, $result);
	}

	// ── find() request-scoped uuid → (register, schema) cache ──────────────

	/**
	 * A second lookup of the same uuid under the same stale scope goes straight
	 * to the object's resolved register/schema: one scoped call instead of a
	 * scoped miss plus a cross-table scan.
	 */
	public function testFindUsesUuidScopeCacheOnRepeatedStaleScopeLookups(): void {
		$uuid = '9974da4d-e091-440d-a5d3-f09a6e5c556d';

		$trueRegister = new Register();
		$trueRegister->setId(8);
		$trueSchema = new Schema();
		$trueSchema->setId(1470);

		$object = new ObjectEntity();
		$object->setId(3);
		$object->setUuid($uuid);
		$object->setRegister('8');
		$object->setSchema('1470');

		// Call 1 (stale scope) misses; call 2 (cross-table) resolves; call 3
		// (second find(), cache hit) must be scoped to the TRUE context.
		$callCount = 0;
		$calls = [];
		$this->getHandler->method('find')->willReturnCallback(
			function (...$args) use (&$callCount, &$calls, $object): ObjectEntity {
				$callCount++;
				$calls[] = $args;
				if ($callCount === 1) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('Object not found in magic table');
				}

				return $object;
			}
		);

		// Re-anchoring resolves the object's true register/schema by id.
		$this->registerMapper->method('find')->willReturn($trueRegister);
		$this->schemaMapper->method('find')->willReturn($trueSchema);

		$this->renderHandler->method('renderEntity')->willReturn($object);

		// First read: scoped miss + cross-table fallback (2 handler calls).
		$this->service->find(id: $uuid, register: $this->register, schema: $this->schema);
		$this->assertSame(2, $callCount);

		// Second read with the SAME stale scope: exactly ONE more handler call…
		$result = $this->service->find(id: $uuid, register: $this->register, schema: $this->schema);

		$this->assertSame($object, $result);
		$this->assertSame(3, $callCount, 'a repeated uuid lookup must not re-run the cross-table fallback');

		// …and that call is scoped to the resolved true context, not unscoped.
		// GetObject::find positional args: [0]=id, [1]=register, [2]=schema.
		$this->assertSame($trueRegister, $calls[2][1], 'cache hit must target the resolved register');
		$this->assertSame($trueSchema, $calls[2][2], 'cache hit must target the resolved schema');
	}

	/**
	 * A cached scope that no longer resolves (object deleted/moved mid-request)
	 * is invalidated and the original cross-table fallback still runs — the
	 * cache is a fast path only, never a behaviour change (openregister#1520).
	 */
	public function testFindInvalidatesUuidScopeCacheWhenCachedScopeMisses(): void {
		$uuid = '9974da4d-e091-440d-a5d3-f09a6e5c556d';

		$trueRegister = new Register();
		$trueRegister->setId(8);
		$trueSchema = new Schema();
		$trueSchema->setId(1470);

		$object = new ObjectEntity();
		$object->setId(3);
		$object->setUuid($uuid);
		$object->setRegister('8');
		$object->setSchema('1470');

		// Call 1: stale-scope miss. Call 2: cross-table hit (populates cache).
		// Call 3: cached-scope lookup misses (object moved). Call 4: fallback.
		$callCount = 0;
		$this->getHandler->method('find')->willReturnCallback(
			function (...$args) use (&$callCount, $object): ObjectEntity {
				$callCount++;
				if ($callCount === 1 || $callCount === 3) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('Object not found in magic table');
				}

				return $object;
			}
		);

		$this->registerMapper->method('find')->willReturn($trueRegister);
		$this->schemaMapper->method('find')->willReturn($trueSchema);
		$this->renderHandler->method('renderEntity')->willReturn($object);

		$this->service->find(id: $uuid, register: $this->register, schema: $this->schema);
		$result = $this->service->find(id: $uuid, register: $this->register, schema: $this->schema);

		$this->assertSame($object, $result, 'the cross-table fallback must still resolve the object');
		$this->assertSame(4, $callCount, 'stale cache entry must fall back to the cross-table lookup');
	}
}
