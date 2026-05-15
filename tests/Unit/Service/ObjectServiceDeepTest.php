<?php

/**
 * ObjectService Deep Coverage Tests
 *
 * Tests targeting uncovered lines in ObjectService:
 * - setRegister (numeric vs slug, cache miss fallback)
 * - setSchema (numeric vs slug, DoesNotExistException)
 * - setObject (with/without register+schema context)
 * - getObject
 * - prepareFindAllConfig (extend string-to-array, register/schema filters)
 * - extractUuidAndNormalizeObject (ObjectEntity, @self.id, id field)
 * - checkSavePermissions (null schema, null uuid, uuid exists, uuid not found)
 * - normalizeDateValues (no schema, date format, datetime conversion)
 * - ensureObjectFolder (null uuid, existing folder, DoesNotExistException)
 * - ensureObjectFolderExists (null folder, string path, folder creation)
 * - count
 * - findByRelations
 * - deleteObject
 * - getActiveOrganisationForContext
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
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
use OCA\OpenRegister\Service\Object\PerformanceHandler;
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

/**
 * Deep coverage tests for ObjectService
 */
class ObjectServiceDeepTest extends TestCase
{

    private ObjectService $service;

    private MockObject|PerformanceHandler $performanceHandler;

    private MockObject|RegisterMapper $registerMapper;

    private MockObject|SchemaMapper $schemaMapper;

    private MockObject|MagicMapper $objectEntityMapper;

    private MockObject|LoggerInterface $logger;

    private MockObject|PermissionHandler $permissionHandler;

    private MockObject|GetObject $getHandler;

    private MockObject|SaveObject $saveHandler;

    private MockObject|DeleteObject $deleteHandler;

    private MockObject|RenderObject $renderHandler;

    private MockObject|ValidateObject $validateHandler;

    private MockObject|FileService $fileService;

    private MockObject|CascadingHandler $cascadingHandler;

    private MockObject|OrganisationService $organisationService;

    private MockObject|IAppContainer $container;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        $dataManipHandler         = $this->createMock(DataManipulationHandler::class);
        $this->deleteHandler      = $this->createMock(DeleteObject::class);
        $this->getHandler         = $this->createMock(GetObject::class);
        $this->performanceHandler = $this->createMock(PerformanceHandler::class);
        $this->permissionHandler  = $this->createMock(PermissionHandler::class);
        $this->renderHandler      = $this->createMock(RenderObject::class);
        $this->saveHandler        = $this->createMock(SaveObject::class);
        $saveObjectsHandler       = $this->createMock(SaveObjects::class);
        $searchQueryHandler       = $this->createMock(SearchQueryHandler::class);
        $this->validateHandler    = $this->createMock(ValidateObject::class);
        $lockHandler            = $this->createMock(LockHandler::class);
        $auditHandler           = $this->createMock(AuditHandler::class);
        $relationHandler        = $this->createMock(RelationHandler::class);
        $mergeHandler           = $this->createMock(MergeHandler::class);
        $facetHandler           = $this->createMock(FacetHandler::class);
        $metadataHandler        = $this->createMock(MetadataHandler::class);
        $perfOptHandler         = $this->createMock(PerformanceOptimizationHandler::class);
        $queryHandler           = $this->createMock(QueryHandler::class);
        $revertHandler          = $this->createMock(RevertHandler::class);
        $utilityHandler         = $this->createMock(UtilityHandler::class);
        $validationHandler      = $this->createMock(ValidationHandler::class);
        $this->cascadingHandler = $this->createMock(CascadingHandler::class);
        $migrationHandler       = $this->createMock(MigrationHandler::class);
        $this->registerMapper   = $this->createMock(RegisterMapper::class);
        $this->schemaMapper     = $this->createMock(SchemaMapper::class);
        $viewMapper = $this->createMock(ViewMapper::class);
        $this->objectEntityMapper = $this->createMock(MagicMapper::class);
        $this->fileService        = $this->createMock(FileService::class);
        $userSession        = $this->createMock(IUserSession::class);
        $searchTrailService = $this->createMock(SearchTrailService::class);
        $groupManager       = $this->createMock(IGroupManager::class);
        $userManager        = $this->createMock(IUserManager::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $cacheHandler       = $this->createMock(CacheHandler::class);
        $settingsService    = $this->createMock(SettingsService::class);
        $dateTimeNormalizer = $this->createMock(DateTimeNormalizer::class);
        $dateTimeNormalizer->method('normalize')->willReturnCallback(
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
        $this->container = $this->createMock(IAppContainer::class);

        $this->service = new ObjectService(
            $dataManipHandler,
            $this->deleteHandler,
            $this->getHandler,
            $this->performanceHandler,
            $this->permissionHandler,
            $this->renderHandler,
            $this->saveHandler,
            $saveObjectsHandler,
            $searchQueryHandler,
            $this->validateHandler,
            $lockHandler,
            $auditHandler,
            $relationHandler,
            $mergeHandler,
            $facetHandler,
            $metadataHandler,
            $perfOptHandler,
            $queryHandler,
            $revertHandler,
            $utilityHandler,
            $validationHandler,
            $this->cascadingHandler,
            $migrationHandler,
            $this->registerMapper,
            $this->schemaMapper,
            $viewMapper,
            $this->objectEntityMapper,
            $this->fileService,
            $userSession,
            $searchTrailService,
            $groupManager,
            $userManager,
            $this->organisationService,
            $this->logger,
            $cacheHandler,
            $settingsService,
            $dateTimeNormalizer,
            $this->container
        );

    }//end setUp()

    // =========================================================================
    // getObject
    // =========================================================================

    /**
     * Test getObject returns null when no context
     *
     * @return void
     */
    public function testGetObjectReturnsNullWithoutContext(): void
    {
        $this->assertNull($this->service->getObject());

    }//end testGetObjectReturnsNullWithoutContext()

    // =========================================================================
    // setRegister with slug string
    // =========================================================================

    /**
     * Test setRegister with Register object
     *
     * @return void
     */
    public function testSetRegisterWithRegisterObject(): void
    {
        $register = new Register();
        $register->setId(1);

        $result = $this->service->setRegister($register);
        $this->assertSame($this->service, $result);

    }//end testSetRegisterWithRegisterObject()

    /**
     * Test setRegister with slug string
     *
     * @return void
     */
    public function testSetRegisterWithSlugString(): void
    {
        $register = $this->createMock(Register::class);
        $this->registerMapper->method('find')->willReturn($register);

        $result = $this->service->setRegister('my-register');
        $this->assertSame($this->service, $result);

    }//end testSetRegisterWithSlugString()

    /**
     * Test setRegister with numeric ID uses cache
     *
     * @return void
     */
    public function testSetRegisterWithNumericIdUsesCache(): void
    {
        $register = $this->createMock(Register::class);
        $this->performanceHandler->method('getCachedEntities')
            ->willReturn([$register]);

        $result = $this->service->setRegister(42);
        $this->assertSame($this->service, $result);

    }//end testSetRegisterWithNumericIdUsesCache()

    /**
     * Test setRegister with numeric ID falls back when cache fails
     *
     * @return void
     */
    public function testSetRegisterWithNumericIdCacheFallback(): void
    {
        $register = $this->createMock(Register::class);

        // Cache returns non-register (e.g., string or null).
        $this->performanceHandler->method('getCachedEntities')
            ->willReturn(['not-a-register']);

        $this->registerMapper->method('find')->willReturn($register);

        $result = $this->service->setRegister(42);
        $this->assertSame($this->service, $result);

    }//end testSetRegisterWithNumericIdCacheFallback()

    // =========================================================================
    // setSchema
    // =========================================================================

    /**
     * Test setSchema with Schema object
     *
     * @return void
     */
    public function testSetSchemaWithSchemaObject(): void
    {
        $schema = $this->createMock(Schema::class);
        $result = $this->service->setSchema($schema);
        $this->assertSame($this->service, $result);

    }//end testSetSchemaWithSchemaObject()

    /**
     * Test setSchema with numeric ID uses cache
     *
     * @return void
     */
    public function testSetSchemaWithNumericIdUsesCache(): void
    {
        $schema = $this->createMock(Schema::class);
        $this->performanceHandler->method('getCachedEntities')
            ->willReturn([$schema]);

        $result = $this->service->setSchema(5);
        $this->assertSame($this->service, $result);

    }//end testSetSchemaWithNumericIdUsesCache()

    /**
     * Test setSchema with slug string
     *
     * @return void
     */
    public function testSetSchemaWithSlugString(): void
    {
        $schema = $this->createMock(Schema::class);
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->service->setSchema('my-schema');
        $this->assertSame($this->service, $result);

    }//end testSetSchemaWithSlugString()

    /**
     * Test setSchema throws DoesNotExistException when not found.
     *
     * The service intentionally rethrows DoesNotExistException (not ValidationException)
     * so the Nextcloud framework converts it to a 404 response rather than a 500.
     *
     * @return void
     */
    public function testSetSchemaThrowsOnNotFound(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->service->setSchema('nonexistent');

    }//end testSetSchemaThrowsOnNotFound()

    // =========================================================================
    // setObject
    // =========================================================================

    /**
     * Test setObject with ObjectEntity
     *
     * @return void
     */
    public function testSetObjectWithEntity(): void
    {
        $entity = $this->createMock(ObjectEntity::class);

        $result = $this->service->setObject($entity);
        $this->assertSame($this->service, $result);
        $this->assertSame($entity, $this->service->getObject());

    }//end testSetObjectWithEntity()

    /**
     * Test setObject with ID uses objectEntityMapper when no context
     *
     * @return void
     */
    public function testSetObjectWithIdNoContext(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $this->objectEntityMapper->method('find')->willReturn($entity);

        $result = $this->service->setObject(123);
        $this->assertSame($entity, $this->service->getObject());

    }//end testSetObjectWithIdNoContext()

    // =========================================================================
    // prepareFindAllConfig (private)
    // =========================================================================

    /**
     * Test prepareFindAllConfig converts extend string to array
     *
     * @return void
     */
    public function testPrepareFindAllConfigExtendsStringToArray(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'prepareFindAllConfig');

        $config = ['extend' => 'field1,field2,field3'];
        $result = $method->invoke($this->service, $config);

        $this->assertIsArray($result['extend']);
        $this->assertCount(3, $result['extend']);
        $this->assertEquals('field1', $result['extend'][0]);

    }//end testPrepareFindAllConfigExtendsStringToArray()

    /**
     * Test prepareFindAllConfig sets register context from filters
     *
     * @return void
     */
    public function testPrepareFindAllConfigSetsRegister(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'prepareFindAllConfig');

        $register = $this->createMock(Register::class);
        $this->registerMapper->method('find')->willReturn($register);

        $config = ['filters' => ['register' => 'my-reg']];
        $result = $method->invoke($this->service, $config);

        $this->assertArrayHasKey('filters', $result);

    }//end testPrepareFindAllConfigSetsRegister()

    /**
     * Test prepareFindAllConfig sets schema context from filters
     *
     * @return void
     */
    public function testPrepareFindAllConfigSetsSchema(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'prepareFindAllConfig');

        $schema = $this->createMock(Schema::class);
        $this->schemaMapper->method('find')->willReturn($schema);

        $config = ['filters' => ['schema' => 'my-schema']];
        $result = $method->invoke($this->service, $config);

        $this->assertArrayHasKey('filters', $result);

    }//end testPrepareFindAllConfigSetsSchema()

    // =========================================================================
    // extractUuidAndNormalizeObject (private)
    // =========================================================================

    /**
     * Test extractUuidAndNormalizeObject with array and uuid in @self.id
     *
     * @return void
     */
    public function testExtractUuidSelfId(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'extractUuidAndNormalizeObject');

        $object = ['@self' => ['id' => 'uuid-from-self'], 'name' => 'Test'];
        [$resultObj, $resultUuid] = $method->invoke($this->service, $object, null);

        $this->assertEquals('uuid-from-self', $resultUuid);

    }//end testExtractUuidSelfId()

    /**
     * Test extractUuidAndNormalizeObject with array and uuid in id
     *
     * @return void
     */
    public function testExtractUuidIdField(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'extractUuidAndNormalizeObject');

        $object = ['id' => 'uuid-from-id', 'name' => 'Test'];
        [$resultObj, $resultUuid] = $method->invoke($this->service, $object, null);

        $this->assertEquals('uuid-from-id', $resultUuid);

    }//end testExtractUuidIdField()

    /**
     * Test extractUuidAndNormalizeObject with explicit uuid takes priority
     *
     * @return void
     */
    public function testExtractUuidExplicitTakesPriority(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'extractUuidAndNormalizeObject');

        $object = ['id' => 'uuid-from-id', 'name' => 'Test'];
        [$resultObj, $resultUuid] = $method->invoke($this->service, $object, 'explicit-uuid');

        $this->assertEquals('explicit-uuid', $resultUuid);

    }//end testExtractUuidExplicitTakesPriority()

    /**
     * Test extractUuidAndNormalizeObject skips empty id
     *
     * @return void
     */
    public function testExtractUuidSkipsEmptyId(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'extractUuidAndNormalizeObject');

        $object = ['id' => '  ', 'name' => 'Test'];
        [$resultObj, $resultUuid] = $method->invoke($this->service, $object, null);

        $this->assertNull($resultUuid);

    }//end testExtractUuidSkipsEmptyId()

    // =========================================================================
    // checkSavePermissions (private)
    // =========================================================================

    /**
     * Test checkSavePermissions returns early when no schema
     *
     * @return void
     */
    public function testCheckSavePermissionsNoSchema(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'checkSavePermissions');

        // No schema set, should return without calling checkPermission.
        $this->permissionHandler->expects($this->never())
            ->method('checkPermission');

        $method->invoke($this->service, null, true);

    }//end testCheckSavePermissionsNoSchema()

    /**
     * Test checkSavePermissions checks create for null uuid
     *
     * @return void
     */
    public function testCheckSavePermissionsCreateForNullUuid(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'checkSavePermissions');

        // Set schema context.
        $schema = $this->createMock(Schema::class);
        $this->service->setSchema($schema);

        $this->permissionHandler->expects($this->once())
            ->method('checkPermission')
            ->with(
                $this->identicalTo($schema),
                $this->equalTo('create'),
                $this->anything(),
                $this->anything(),
                $this->anything()
            );

        $method->invoke($this->service, null, true);

    }//end testCheckSavePermissionsCreateForNullUuid()

    /**
     * Test checkSavePermissions checks update for existing uuid
     *
     * @return void
     */
    public function testCheckSavePermissionsUpdateForExistingUuid(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'checkSavePermissions');

        $schema = new Schema();
        $schema->setId(1);
        $this->service->setSchema($schema);

        $existingObj = new ObjectEntity();
        $existingObj->setOwner('admin');

        $this->objectEntityMapper->method('find')->willReturn($existingObj);

        $this->permissionHandler->expects($this->once())
            ->method('checkPermission')
            ->with(
                $this->anything(),
                $this->equalTo('update'),
                $this->anything(),
                $this->equalTo('admin'),
                $this->anything()
            );

        $method->invoke($this->service, 'existing-uuid', true);

    }//end testCheckSavePermissionsUpdateForExistingUuid()

    /**
     * Test checkSavePermissions checks create when uuid not found
     *
     * @return void
     */
    public function testCheckSavePermissionsCreateWhenUuidNotFound(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'checkSavePermissions');

        $schema = $this->createMock(Schema::class);
        $this->service->setSchema($schema);

        $this->objectEntityMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $this->permissionHandler->expects($this->once())
            ->method('checkPermission')
            ->with(
                $this->identicalTo($schema),
                $this->equalTo('create'),
                $this->anything(),
                $this->anything(),
                $this->anything()
            );

        $method->invoke($this->service, 'new-uuid', true);

    }//end testCheckSavePermissionsCreateWhenUuidNotFound()

    // =========================================================================
    // normalizeDateValues (private)
    // =========================================================================

    /**
     * Test normalizeDateValues with no schema returns unchanged
     *
     * @return void
     */
    public function testNormalizeDateValuesNoSchema(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'normalizeDateValues');

        $object = ['date' => '2024-01-15T10:30:00+02:00'];
        $result = $method->invoke($this->service, $object);

        $this->assertEquals($object, $result);

    }//end testNormalizeDateValuesNoSchema()

    /**
     * Test normalizeDateValues converts datetime to date
     *
     * @return void
     */
    public function testNormalizeDateValuesConvertsDatetime(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'normalizeDateValues');

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn(
                [
                    'startDate' => ['type' => 'string', 'format' => 'date'],
                ]
                );
        $this->service->setSchema($schema);

        $object = ['startDate' => '2024-01-15T10:30:00+02:00'];
        $result = $method->invoke($this->service, $object);

        $this->assertEquals('2024-01-15', $result['startDate']);

    }//end testNormalizeDateValuesConvertsDatetime()

    /**
     * Test normalizeDateValues skips already valid date
     *
     * @return void
     */
    public function testNormalizeDateValuesSkipsValidDate(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'normalizeDateValues');

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn(
                [
                    'startDate' => ['type' => 'string', 'format' => 'date'],
                ]
                );
        $this->service->setSchema($schema);

        $object = ['startDate' => '2024-01-15'];
        $result = $method->invoke($this->service, $object);

        $this->assertEquals('2024-01-15', $result['startDate']);

    }//end testNormalizeDateValuesSkipsValidDate()

    /**
     * Test normalizeDateValues skips non-date format
     *
     * @return void
     */
    public function testNormalizeDateValuesSkipsNonDateFormat(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'normalizeDateValues');

        $schema = $this->createMock(Schema::class);
        $schema->method('getProperties')->willReturn(
                [
                    'email' => ['type' => 'string', 'format' => 'email'],
                ]
                );
        $this->service->setSchema($schema);

        $object = ['email' => 'test@example.com'];
        $result = $method->invoke($this->service, $object);

        $this->assertEquals('test@example.com', $result['email']);

    }//end testNormalizeDateValuesSkipsNonDateFormat()

    // =========================================================================
    // ensureObjectFolder (private)
    // =========================================================================

    /**
     * Test ensureObjectFolder with null uuid returns null
     *
     * @return void
     */
    public function testEnsureObjectFolderNullUuid(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'ensureObjectFolder');

        $result = $method->invoke($this->service, null);
        $this->assertNull($result);

    }//end testEnsureObjectFolderNullUuid()

    /**
     * Test ensureObjectFolder with DoesNotExistException returns null
     *
     * @return void
     */
    public function testEnsureObjectFolderObjectNotFound(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'ensureObjectFolder');

        $this->objectEntityMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $method->invoke($this->service, 'uuid-123');
        $this->assertNull($result);

    }//end testEnsureObjectFolderObjectNotFound()

    // =========================================================================
    // ensureObjectFolderExists (public)
    // =========================================================================

    /**
     * Test ensureObjectFolderExists with null folder creates folder
     *
     * @return void
     */
    public function testEnsureObjectFolderExistsNullFolder(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setFolder(null);

        $folderNode = $this->createMock(\OCP\Files\Folder::class);
        $folderNode->method('getId')->willReturn(42);

        $this->fileService->method('createEntityFolder')->willReturn($folderNode);

        $this->objectEntityMapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $this->service->ensureObjectFolderExists($entity);

        $this->assertSame('42', $entity->getFolder());

    }//end testEnsureObjectFolderExistsNullFolder()

    /**
     * Test ensureObjectFolderExists with empty string creates folder
     *
     * @return void
     */
    public function testEnsureObjectFolderExistsEmptyString(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setFolder('');

        $folderNode = $this->createMock(\OCP\Files\Folder::class);
        $folderNode->method('getId')->willReturn(99);

        $this->fileService->method('createEntityFolder')->willReturn($folderNode);

        $this->objectEntityMapper->method('update')->willReturnArgument(0);

        $this->service->ensureObjectFolderExists($entity);

        $this->assertSame('99', $entity->getFolder());

    }//end testEnsureObjectFolderExistsEmptyString()

    /**
     * Test ensureObjectFolderExists handles createEntityFolder returning null
     *
     * @return void
     */
    public function testEnsureObjectFolderExistsFolderNull(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setFolder(null);

        $this->fileService->method('createEntityFolder')->willReturn(null);

        // Should not call update since no folder node returned.
        $this->objectEntityMapper->expects($this->never())
            ->method('update');

        $this->service->ensureObjectFolderExists($entity);

    }//end testEnsureObjectFolderExistsFolderNull()

    /**
     * Test ensureObjectFolderExists handles folder creation exception
     *
     * @return void
     */
    public function testEnsureObjectFolderExistsException(): void
    {
        $entity = new ObjectEntity();
        $entity->setUuid('test-uuid');
        $entity->setFolder(null);

        $this->fileService->method('createEntityFolder')
            ->willThrowException(new \Exception('Cannot create folder'));

        // Should not call update since exception is caught before update.
        $this->objectEntityMapper->expects($this->never())
            ->method('update');

        // Should not throw - silently handles exception.
        $this->service->ensureObjectFolderExists($entity);

        // Folder should remain null since creation failed.
        $this->assertNull($entity->getFolder());

    }//end testEnsureObjectFolderExistsException()

    // =========================================================================
    // findByRelations
    // =========================================================================

    /**
     * Test findByRelations delegates to mapper
     *
     * @return void
     */
    public function testFindByRelationsDelegates(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $this->objectEntityMapper->method('findByRelation')->willReturn([$obj]);

        $result = $this->service->findByRelations('some-uuid');
        $this->assertCount(1, $result);

    }//end testFindByRelationsDelegates()

    /**
     * Test findByRelations with partial match disabled
     *
     * @return void
     */
    public function testFindByRelationsExactMatch(): void
    {
        $this->objectEntityMapper->expects($this->once())
            ->method('findByRelation')
            ->with('uuid-123', 'uuid-123', false)
            ->willReturn([]);

        $result = $this->service->findByRelations('uuid-123', false);
        $this->assertIsArray($result);

    }//end testFindByRelationsExactMatch()

    // =========================================================================
    // getActiveOrganisationForContext (private)
    // =========================================================================

    /**
     * Test getActiveOrganisationForContext returns UUID
     *
     * @return void
     */
    public function testGetActiveOrganisationForContextReturnsUuid(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'getActiveOrganisationForContext');

        $org = new \OCA\OpenRegister\Db\Organisation();
        $org->setUuid('org-uuid');

        $this->organisationService->method('getActiveOrganisation')->willReturn($org);

        $result = $method->invoke($this->service);
        $this->assertEquals('org-uuid', $result);

    }//end testGetActiveOrganisationForContextReturnsUuid()

    /**
     * Test getActiveOrganisationForContext returns null when no org
     *
     * @return void
     */
    public function testGetActiveOrganisationForContextReturnsNull(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'getActiveOrganisationForContext');

        $this->organisationService->method('getActiveOrganisation')->willReturn(null);

        $result = $method->invoke($this->service);
        $this->assertNull($result);

    }//end testGetActiveOrganisationForContextReturnsNull()

    /**
     * Test getActiveOrganisationForContext handles exception
     *
     * @return void
     */
    public function testGetActiveOrganisationForContextException(): void
    {
        $method = new \ReflectionMethod(ObjectService::class, 'getActiveOrganisationForContext');

        $this->organisationService->method('getActiveOrganisation')
            ->willThrowException(new \Exception('Service error'));

        $result = $method->invoke($this->service);
        $this->assertNull($result);

    }//end testGetActiveOrganisationForContextException()
}//end class
