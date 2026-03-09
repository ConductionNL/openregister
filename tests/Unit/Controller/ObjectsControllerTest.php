<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCP\DB\Exception as DBException;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ObjectsController
 *
 * Tests cover the main CRUD methods and error handling.
 * ObjectsController is very large, so we focus on the key public methods.
 *
 * @package Unit\Controller
 */
class ObjectsControllerTest extends TestCase
{
    private ObjectsController $controller;
    private IRequest&MockObject $request;
    private IAppConfig&MockObject $config;
    private IAppManager&MockObject $appManager;
    private ContainerInterface&MockObject $container;
    private ObjectEntityMapper&MockObject $objectEntityMapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private ObjectService&MockObject $objectService;
    private IUserSession&MockObject $userSession;
    private IGroupManager&MockObject $groupManager;
    private ExportService&MockObject $exportService;
    private ImportService&MockObject $importService;
    private WebhookService&MockObject $webhookService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->exportService = $this->createMock(ExportService::class);
        $this->importService = $this->createMock(ImportService::class);
        $this->webhookService = $this->createMock(WebhookService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ObjectsController(
            'openregister',
            $this->request,
            $this->config,
            $this->appManager,
            $this->container,
            $this->objectEntityMapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->auditTrailMapper,
            $this->objectService,
            $this->userSession,
            $this->groupManager,
            $this->exportService,
            $this->importService,
            $this->webhookService,
            $this->logger
        );
    }

    private function setupAdminUser(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);
    }

    private function setupRegularUser(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['users']);
    }

    public function testDestroyReturns204OnSuccess(): void
    {
        $this->setupAdminUser();

        $this->objectService->method('deleteObject')->willReturn(true);

        $result = $this->controller->destroy('uuid-123', 'reg', 'schema', $this->objectService);

        $this->assertSame(204, $result->getStatus());
    }

    public function testDestroyReturns500WhenDeleteFails(): void
    {
        $this->setupAdminUser();

        $this->objectService->method('deleteObject')->willReturn(false);

        $result = $this->controller->destroy('uuid-123', 'reg', 'schema', $this->objectService);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testDestroyReturns403OnException(): void
    {
        $this->setupRegularUser();

        $this->objectService->method('deleteObject')
            ->willThrowException(new Exception('Permission denied'));

        $result = $this->controller->destroy('uuid-123', 'reg', 'schema', $this->objectService);

        $this->assertSame(403, $result->getStatus());
    }

    public function testDestroyReturns409OnReferentialIntegrityException(): void
    {
        $this->setupAdminUser();

        $analysis = new DeletionAnalysis(
            false,
            [],
            [],
            [],
            [['uuid' => 'blocker-1', 'reason' => 'RESTRICT']],
            []
        );
        $exception = new ReferentialIntegrityException($analysis);
        $this->objectService->method('deleteObject')
            ->willThrowException($exception);

        $result = $this->controller->destroy('uuid-123', 'reg', 'schema', $this->objectService);

        $this->assertSame(409, $result->getStatus());
    }

    public function testControllerConstructorSetsProperties(): void
    {
        // If the controller is constructed without errors, the test passes.
        $this->assertInstanceOf(ObjectsController::class, $this->controller);
    }

    public function testDestroyReturns422OnHookStoppedException(): void
    {
        $this->setupAdminUser();

        $exception = new \OCA\OpenRegister\Exception\HookStoppedException(
            'Hook blocked',
            ['field' => 'error']
        );
        $this->objectService->method('deleteObject')
            ->willThrowException($exception);

        $result = $this->controller->destroy('uuid-123', 'reg', 'schema', $this->objectService);

        $this->assertSame(422, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testLockReturnsLockedObject(): void
    {
        $this->request->method('getParams')->willReturn(['process' => 'editing', 'duration' => 300]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('lockObject')->willReturn([
            'uuid' => 'uuid-123',
            'lockedBy' => 'admin',
        ]);

        $result = $this->controller->lock('reg1', 'schema1', 'uuid-123');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['locked']);
        $this->assertSame('uuid-123', $data['uuid']);
    }

    public function testLockReturns404WhenObjectNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('lockObject')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->lock('reg1', 'schema1', 'uuid-123');

        $this->assertSame(404, $result->getStatus());
    }

    public function testLockReturns500OnError(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('lockObject')
            ->willThrowException(new \RuntimeException('Lock failed'));

        $result = $this->controller->lock('reg1', 'schema1', 'uuid-123');

        $this->assertSame(500, $result->getStatus());
    }

    public function testUnlockReturnsUnlockedObject(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->unlock('reg1', 'schema1', 'uuid-123');

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['locked']);
        $this->assertSame('uuid-123', $data['uuid']);
        $this->assertSame('Object unlocked successfully', $data['message']);
    }

    public function testPublishReturnsPublishedObject(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->request->method('getParam')->willReturn(null);
        $this->objectService->method('publish')->willReturn($objectEntity);

        $result = $this->controller->publish('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    public function testPublishReturns400OnException(): void
    {
        $this->setupAdminUser();

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->request->method('getParam')->willReturn(null);
        $this->objectService->method('publish')
            ->willThrowException(new DBException('Publish failed'));

        $result = $this->controller->publish('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testDepublishReturnsDepublishedObject(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->request->method('getParam')->willReturn(null);
        $this->objectService->method('depublish')->willReturn($objectEntity);

        $result = $this->controller->depublish('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    public function testDepublishReturns400OnException(): void
    {
        $this->setupAdminUser();

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->request->method('getParam')->willReturn(null);
        $this->objectService->method('depublish')
            ->willThrowException(new DBException('Depublish failed'));

        $result = $this->controller->depublish('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    public function testMergeReturnsMergedObject(): void
    {
        $this->request->method('getParams')->willReturn([
            'target' => 'uuid-456',
            'object' => ['title' => 'Merged'],
        ]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('mergeObjects')->willReturn([
            'success' => true,
            'uuid' => 'uuid-456',
        ]);

        $result = $this->controller->merge('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    public function testMergeReturns400WhenTargetMissing(): void
    {
        $this->request->method('getParams')->willReturn([
            'object' => ['title' => 'Merged'],
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $result = $this->controller->merge('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame('Target object ID is required', $result->getData()['error']);
    }

    public function testMergeReturns400WhenObjectDataMissing(): void
    {
        $this->request->method('getParams')->willReturn([
            'target' => 'uuid-456',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $result = $this->controller->merge('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame('Object data is required', $result->getData()['error']);
    }

    public function testMergeReturns404OnDoesNotExistException(): void
    {
        $this->request->method('getParams')->willReturn([
            'target' => 'uuid-456',
            'object' => ['title' => 'Merged'],
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('mergeObjects')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->merge('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testMergeReturns400OnInvalidArgument(): void
    {
        $this->request->method('getParams')->willReturn([
            'target' => 'uuid-456',
            'object' => ['title' => 'Merged'],
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('mergeObjects')
            ->willThrowException(new \InvalidArgumentException('Invalid merge'));

        $result = $this->controller->merge('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    public function testMergeReturns500OnGenericException(): void
    {
        $this->request->method('getParams')->willReturn([
            'target' => 'uuid-456',
            'object' => ['title' => 'Merged'],
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('mergeObjects')
            ->willThrowException(new Exception('Merge error'));

        $result = $this->controller->merge('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(500, $result->getStatus());
    }

    public function testMigrateReturns400WhenSourceMissing(): void
    {
        $this->request->method('getParams')->willReturn([
            'targetRegister' => '2',
            'targetSchema' => '3',
            'objects' => ['uuid-1'],
            'mapping' => ['field1' => 'field2'],
        ]);

        $result = $this->controller->migrate($this->objectService);

        $this->assertSame(400, $result->getStatus());
        $this->assertStringContainsString('Source register', $result->getData()['error']);
    }

    public function testMigrateReturns400WhenTargetMissing(): void
    {
        $this->request->method('getParams')->willReturn([
            'sourceRegister' => '1',
            'sourceSchema' => '2',
            'objects' => ['uuid-1'],
            'mapping' => ['field1' => 'field2'],
        ]);

        $result = $this->controller->migrate($this->objectService);

        $this->assertSame(400, $result->getStatus());
        $this->assertStringContainsString('Target register', $result->getData()['error']);
    }

    public function testMigrateReturns400WhenObjectsMissing(): void
    {
        $this->request->method('getParams')->willReturn([
            'sourceRegister' => '1',
            'sourceSchema' => '2',
            'targetRegister' => '3',
            'targetSchema' => '4',
            'mapping' => ['field1' => 'field2'],
        ]);

        $result = $this->controller->migrate($this->objectService);

        $this->assertSame(400, $result->getStatus());
        $this->assertStringContainsString('object ID', $result->getData()['error']);
    }

    public function testMigrateReturns400WhenMappingMissing(): void
    {
        $this->request->method('getParams')->willReturn([
            'sourceRegister' => '1',
            'sourceSchema' => '2',
            'targetRegister' => '3',
            'targetSchema' => '4',
            'objects' => ['uuid-1'],
        ]);

        $result = $this->controller->migrate($this->objectService);

        $this->assertSame(400, $result->getStatus());
        $this->assertStringContainsString('mapping', $result->getData()['error']);
    }

    public function testMigrateReturnsMigrationResult(): void
    {
        $this->request->method('getParams')->willReturn([
            'sourceRegister' => '1',
            'sourceSchema' => '2',
            'targetRegister' => '3',
            'targetSchema' => '4',
            'objects' => ['uuid-1'],
            'mapping' => ['field1' => 'field2'],
        ]);

        $this->objectService->method('migrateObjects')->willReturn([
            'success' => true,
            'migrated' => 1,
        ]);

        $result = $this->controller->migrate($this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testMigrateReturns404OnDoesNotExist(): void
    {
        $this->request->method('getParams')->willReturn([
            'sourceRegister' => '1',
            'sourceSchema' => '2',
            'targetRegister' => '3',
            'targetSchema' => '4',
            'objects' => ['uuid-1'],
            'mapping' => ['field1' => 'field2'],
        ]);

        $this->objectService->method('migrateObjects')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->migrate($this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testMigrateReturns500OnGenericException(): void
    {
        $this->request->method('getParams')->willReturn([
            'sourceRegister' => '1',
            'sourceSchema' => '2',
            'targetRegister' => '3',
            'targetSchema' => '4',
            'objects' => ['uuid-1'],
            'mapping' => ['field1' => 'field2'],
        ]);

        $this->objectService->method('migrateObjects')
            ->willThrowException(new Exception('Migration error'));

        $result = $this->controller->migrate($this->objectService);

        $this->assertSame(500, $result->getStatus());
    }

    public function testVectorizeBatchSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'views' => null,
            'batchSize' => 10,
        ]);

        $this->objectService->method('vectorizeBatchObjects')->willReturn([
            'processed' => 10,
        ]);

        $result = $this->controller->vectorizeBatch();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
    }

    public function testVectorizeBatchReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('vectorizeBatchObjects')
            ->willThrowException(new DBException('Vectorization failed'));

        $result = $this->controller->vectorizeBatch();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testGetObjectVectorizationStatsSuccess(): void
    {
        $this->request->method('getParam')->willReturn(null);

        $this->objectService->method('getVectorizationStatistics')->willReturn([
            'total' => 100,
            'vectorized' => 50,
        ]);

        $result = $this->controller->getObjectVectorizationStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('stats', $data);
    }

    public function testGetObjectVectorizationStatsError(): void
    {
        $this->request->method('getParam')->willReturn(null);

        $this->objectService->method('getVectorizationStatistics')
            ->willThrowException(new DBException('Stats error'));

        $result = $this->controller->getObjectVectorizationStats();

        $this->assertSame(500, $result->getStatus());
    }

    public function testGetObjectVectorizationCountSuccess(): void
    {
        $this->request->method('getParam')->willReturn(null);

        $this->objectService->method('getVectorizationCount')->willReturn(42);

        $result = $this->controller->getObjectVectorizationCount();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(42, $data['count']);
    }

    public function testGetObjectVectorizationCountError(): void
    {
        $this->request->method('getParam')->willReturn(null);

        $this->objectService->method('getVectorizationCount')
            ->willThrowException(new DBException('Count error'));

        $result = $this->controller->getObjectVectorizationCount();

        $this->assertSame(500, $result->getStatus());
    }

    public function testValidateReturns400WhenParamsMissing(): void
    {
        $this->request->method('getParam')->willReturn(null);

        $result = $this->controller->validate();

        $this->assertSame(400, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testValidateReturnsSuccessResult(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['register', null, '1'],
                ['schema', null, '2'],
                ['limit', null, null],
                ['offset', null, null],
            ]);

        $this->objectService->method('validateAndSaveObjectsBySchema')->willReturn([
            'processed' => 10,
            'updated' => 8,
            'failed' => 2,
            'total' => 10,
            'errors' => [],
        ]);

        $result = $this->controller->validate();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(10, $data['statistics']['processed']);
    }

    public function testValidateReturns500OnException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['register', null, '1'],
                ['schema', null, '2'],
                ['limit', null, null],
                ['offset', null, null],
            ]);

        $this->objectService->method('validateAndSaveObjectsBySchema')
            ->willThrowException(new DBException('Validation error'));

        $result = $this->controller->validate();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testClearBlobSuccess(): void
    {
        $this->objectEntityMapper->method('clearBlobObjects')->willReturn([
            'deleted' => 25,
        ]);

        $result = $this->controller->clearBlob();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(25, $data['deleted']);
    }

    public function testClearBlobReturns500OnException(): void
    {
        $this->objectEntityMapper->method('clearBlobObjects')
            ->willThrowException(new DBException('Clear failed'));

        $result = $this->controller->clearBlob();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }

    public function testContractsReturnsPaginatedResults(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    public function testUsesReturnsPaginatedResults(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectUses')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->uses('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
    }

    public function testUsedReturnsPaginatedResults(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectUsedBy')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->used('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
    }

    public function testCanDeleteReturnsAnalysis(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $ref = new \ReflectionClass($objectEntity);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($objectEntity, 1);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectEntityMapper->method('findAcrossAllSources')->willReturn([
            'object' => $objectEntity,
        ]);

        $analysis = new DeletionAnalysis(true, [], [], [], [], []);
        $deleteHandler = $this->createMock(\OCA\OpenRegister\Service\Object\DeleteObject::class);
        $deleteHandler->method('canDelete')->willReturn($analysis);
        $this->objectService->method('getDeleteHandler')->willReturn($deleteHandler);

        $result = $this->controller->canDelete('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    public function testCanDeleteReturns404WhenNotFound(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectEntityMapper->method('findAcrossAllSources')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->canDelete('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testCanDeleteReturns403OnException(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectEntityMapper->method('findAcrossAllSources')
            ->willThrowException(new Exception('Permission denied'));

        $result = $this->controller->canDelete('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(403, $result->getStatus());
    }

    public function testImportReturns400WhenNoFile(): void
    {
        $this->request->method('getUploadedFile')->willReturn(null);

        $result = $this->controller->import(1);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame('No file uploaded', $result->getData()['error']);
    }

    public function testImportSuccess(): void
    {
        $register = new \OCA\OpenRegister\Db\Register();
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, 1);

        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'import.csv',
            'tmp_name' => '/tmp/import.csv',
            'size' => 1024,
        ]);
        $this->request->method('getParam')->willReturn(null);

        $this->registerMapper->method('find')->willReturn($register);
        $this->objectService->method('importObjects')->willReturn([
            'imported' => 10,
            'failed' => 0,
        ]);

        $user = $this->createMock(\OCP\IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $result = $this->controller->import(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Import successful', $data['message']);
    }

    public function testImportReturns500OnException(): void
    {
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'import.csv',
            'tmp_name' => '/tmp/import.csv',
        ]);

        $this->registerMapper->method('find')
            ->willThrowException(new DBException('Register not found'));

        $result = $this->controller->import(1);

        $this->assertSame(500, $result->getStatus());
    }
}
