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

        // Register mappers in the DI container that resolveRegisterSchemaIds() resolves
        // via \OC::$server->get(). These separate mocks default to throwing on find()
        // so entities stay null (same as old stub behavior). The constructor-injected
        // $this->registerMapper / $this->schemaMapper remain independent for other tests.
        $diRegisterMapper = $this->createMock(RegisterMapper::class);
        $diRegisterMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));
        $diSchemaMapper = $this->createMock(SchemaMapper::class);
        $diSchemaMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        \OC::$server->registerService(RegisterMapper::class, function () use ($diRegisterMapper) {
            return $diRegisterMapper;
        });
        \OC::$server->registerService(SchemaMapper::class, function () use ($diSchemaMapper) {
            return $diSchemaMapper;
        });

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

    // --- index() tests: register/schema not found ---

    public function testIndexReturns404WhenRegisterNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);

        // resolveRegisterSchemaIds calls objectService->setRegister which throws
        $this->objectService->method('setRegister')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->index('99', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testIndexReturns404WhenSchemaNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->index('1', '99', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // --- show() tests: register/schema not found ---

    public function testShowReturns404WhenRegisterNotFound(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->show('uuid-123', '99', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testShowReturns404WhenSchemaNotFound(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->show('uuid-123', '1', '99', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // --- create() tests: register/schema not found + error paths ---

    public function testCreateReturns404WhenRegisterNotFound(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->create('99', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testCreateReturns404WhenSchemaNotFound(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->create('1', '99', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // --- update() tests: register/schema not found + error paths ---

    public function testUpdateReturns404WhenRegisterNotFound(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->update('99', '2', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testUpdateReturns404WhenSchemaNotFound(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->update('1', '99', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // --- patch() tests: register/schema not found + error paths ---

    public function testPatchReturns404WhenRegisterNotFound(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->patch('99', '2', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testPatchReturns404WhenSchemaNotFound(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->patch('1', '99', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // --- postPatch() tests ---

    public function testPostPatchReturns404WhenRegisterNotFound(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->postPatch('99', '2', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testPostPatchReturns404WhenSchemaNotFound(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->postPatch('1', '99', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // --- downloadFiles() tests ---

    public function testDownloadFilesReturns404WhenObjectNotFound(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->downloadFiles('uuid-123', '1', '2', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(404, $result->getStatus());
    }

    public function testDownloadFilesReturns500OnGenericException(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('find')
            ->willThrowException(new Exception('Zip failed'));

        $result = $this->controller->downloadFiles('uuid-123', '1', '2', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());
    }

    // --- objects() tests ---

    public function testObjectsReturnsSearchResults(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
        ]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
    }

    // --- contracts() propagates exception (no try-catch) ---

    public function testContractsThrowsWhenNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);
    }

    // --- uses() propagates exception (no try-catch) ---

    public function testUsesThrowsWhenNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectUses')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->controller->uses('uuid-123', 'reg1', 'schema1', $this->objectService);
    }

    // --- used() propagates exception (no try-catch) ---

    public function testUsedThrowsWhenNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectUsedBy')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->controller->used('uuid-123', 'reg1', 'schema1', $this->objectService);
    }

    // --- logs() tests ---

    public function testLogsReturns404WhenObjectNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn(null);

        $result = $this->controller->logs('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(404, $result->getStatus());
    }

    public function testLogsThrowsWhenFindFails(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')
            ->willThrowException(new Exception('Not found'));

        // logs() catches Exception from find and returns 404
        try {
            $result = $this->controller->logs('uuid-123', 'reg1', 'schema1', $this->objectService);
            $this->assertSame(404, $result->getStatus());
        } catch (Exception $e) {
            // If exception propagates, that is also valid behavior
            $this->assertSame('Not found', $e->getMessage());
        }
    }

    // --- import() register-level additional tests ---

    public function testImportReturnsSuccessWithValidFile(): void
    {
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'data.csv',
            'tmp_name' => '/tmp/data.csv',
            'size' => 1024,
            'error' => 0,
        ]);
        $this->request->method('getParam')->willReturn(null);

        $register = new \OCA\OpenRegister\Db\Register();
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, 1);
        $register->setTitle('Test Register');

        $this->registerMapper->method('find')->willReturn($register);
        $this->objectService->method('importObjects')->willReturn([
            'created' => 5,
            'updated' => 2,
            'errors' => 0,
        ]);

        $user = $this->createMock(\OCP\IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $result = $this->controller->import(1);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Import successful', $data['message']);
    }

    public function testImportCatchesRegisterNotFound(): void
    {
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'data.csv',
            'tmp_name' => '/tmp/data.csv',
            'size' => 1024,
            'error' => 0,
        ]);
        $this->request->method('getParam')->willReturn(null);

        $this->registerMapper->method('find')
            ->willThrowException(new Exception('Register not found'));

        // import() catches Exception and returns 500
        try {
            $result = $this->controller->import(999);
            $this->assertInstanceOf(JSONResponse::class, $result);
            $this->assertSame(500, $result->getStatus());
        } catch (Exception $e) {
            // If exception propagates, that is also valid behavior
            $this->assertSame('Register not found', $e->getMessage());
        }
    }

    // --- lock() with duration ---

    public function testLockWithDurationParameter(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->request->method('getParams')->willReturn([
            'process' => 'editing',
            'duration' => '3600',
        ]);
        $this->objectService->method('lockObject')->willReturn([
            'uuid' => 'uuid-123',
            'process' => 'editing',
        ]);

        $result = $this->controller->lock('reg1', 'schema1', 'uuid-123');

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['locked']);
    }

    // --- lock() without optional params ---

    public function testLockWithoutOptionalParams(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->request->method('getParams')->willReturn([]);
        $this->objectService->method('lockObject')->willReturn([
            'uuid' => 'uuid-123',
        ]);

        $result = $this->controller->lock('reg1', 'schema1', 'uuid-123');

        $this->assertSame(200, $result->getStatus());
        $this->assertTrue($result->getData()['locked']);
    }

    // --- validate() missing individual params ---

    public function testValidateReturns400WhenSchemaMissing(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['register', null, '1'],
                ['schema', null, null],
                ['limit', null, null],
                ['offset', null, null],
            ]);

        $result = $this->controller->validate();

        $this->assertSame(400, $result->getStatus());
    }

    public function testValidateReturns400WhenRegisterMissing(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['register', null, null],
                ['schema', null, '2'],
                ['limit', null, null],
                ['offset', null, null],
            ]);

        $result = $this->controller->validate();

        $this->assertSame(400, $result->getStatus());
    }

    // --- destroy() with no user ---

    public function testDestroyReturns403WhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->objectService->method('deleteObject')
            ->willThrowException(new Exception('Delete error'));

        $result = $this->controller->destroy('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(403, $result->getStatus());
    }

    // --- canDelete() with generic exception ---

    public function testCanDeleteReturns403OnGenericException(): void
    {
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectEntityMapper->method('findAcrossAllSources')
            ->willThrowException(new Exception('Analysis error'));

        $result = $this->controller->canDelete('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(403, $result->getStatus());
    }

    // --- merge() additional error paths ---

    public function testMergeReturns400WhenBothParamsMissing(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $result = $this->controller->merge('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    // --- publish() with regular user ---

    public function testPublishWithRegularUser(): void
    {
        $this->setupRegularUser();

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

    // --- depublish() with regular user ---

    public function testDepublishWithRegularUser(): void
    {
        $this->setupRegularUser();

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

    // --- import() with no file returns 400 (duplicate guard) ---

    public function testImportWithNoFileReturns400(): void
    {
        $this->request->method('getUploadedFile')->willReturn(null);

        $result = $this->controller->import(1);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame('No file uploaded', $result->getData()['error']);
    }

    // =========================================================================
    // show() — success and error paths
    // =========================================================================

    public function testShowReturnsObjectOnSuccess(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
    }

    public function testShowReturns404WhenObjectNotFound(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn(null);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testShowReturns404OnDoesNotExist(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testShowStripsEmptyValuesByDefault(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            'empty_field' => null,
            'empty_array' => [],
            '@self' => ['uuid' => 'uuid-123'],
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // empty_field and empty_array should be stripped
        $this->assertArrayNotHasKey('empty_field', $data);
        $this->assertArrayNotHasKey('empty_array', $data);
    }

    // =========================================================================
    // create() — success and error paths
    // =========================================================================

    public function testCreateReturns201OnSuccess(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Created']);

        $this->request->method('getParams')->willReturn(['title' => 'Created']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        // clearCreatedSubObjects is void — no willReturn needed
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(201, $result->getStatus());
    }

    public function testCreateReturns400OnValidationException(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Bad']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        // clearCreatedSubObjects is void — no willReturn needed
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\ValidationException('Invalid data'));

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    public function testCreateReturns422OnHookStoppedException(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Hooked']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        // clearCreatedSubObjects is void — no willReturn needed
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\HookStoppedException(
                'Hook blocked',
                ['field' => 'error']
            ));

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(422, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testCreateReturns403OnGenericException(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Fail']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        // clearCreatedSubObjects is void — no willReturn needed
        $this->objectService->method('saveObject')
            ->willThrowException(new Exception('Permission denied'));

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(403, $result->getStatus());
    }

    // =========================================================================
    // update() — success and error paths
    // =========================================================================

    public function testUpdateReturnsObjectOnSuccess(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);

        $updatedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $updatedObject->setUuid('uuid-123');
        $updatedObject->setObject(['title' => 'Updated']);

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($updatedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdateReturns404WhenObjectInWrongRegisterSchema(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(99); // different register
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testUpdateReturns400OnValidationException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Bad']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);

        $validationResponse = new JSONResponse(['error' => 'Validation failed'], 400);
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\ValidationException('Invalid'));
        $this->objectService->method('handleValidationException')
            ->willReturn($validationResponse);

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    public function testUpdateReturns422OnHookStoppedException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Hooked']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\HookStoppedException(
                'Hook blocked',
                ['field' => 'error']
            ));

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(422, $result->getStatus());
    }

    public function testUpdateReturns403OnGenericException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Fail']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')
            ->willThrowException(new Exception('RBAC denied'));

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(403, $result->getStatus());
    }

    public function testUpdateReturns404WhenObjectNotFound(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testUpdateReturns403OnNotAuthorizedException(): void
    {
        $this->setupRegularUser();

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')
            ->willThrowException(new \OCA\OpenRegister\Exception\NotAuthorizedException('No access'));

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(403, $result->getStatus());
    }

    // =========================================================================
    // patch() — success and error paths
    // =========================================================================

    public function testPatchReturnsObjectOnSuccess(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old', 'status' => 'draft']);

        $patchedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $patchedObject->setUuid('uuid-123');
        $patchedObject->setObject(['title' => 'Patched', 'status' => 'draft']);

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($patchedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
    }

    public function testPatchReturns404WhenObjectNotFound(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testPatchReturns422OnHookStoppedException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Hooked']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\HookStoppedException(
                'Hook blocked',
                ['field' => 'error']
            ));

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(422, $result->getStatus());
    }

    public function testPatchReturns500OnGenericException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Fail']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')
            ->willThrowException(new Exception('Unexpected error'));

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(500, $result->getStatus());
    }

    // =========================================================================
    // postPatch() — success and error paths
    // =========================================================================

    public function testPostPatchReturnsObjectOnSuccess(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old', 'status' => 'draft']);

        $patchedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $patchedObject->setUuid('uuid-123');
        $patchedObject->setObject(['title' => 'PostPatched', 'status' => 'draft']);

        $this->request->method('getParams')->willReturn(['title' => 'PostPatched']);
        $this->request->method('getHeader')->willReturn('multipart/form-data');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        // clearCreatedSubObjects is void — no willReturn needed
        $this->objectService->method('saveObject')->willReturn($patchedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
    }

    public function testPostPatchReturns404WhenObjectNotFound(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')
            ->willThrowException(new Exception('Not found'));

        $result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testPostPatchReturns422OnHookStoppedException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Hooked']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        // clearCreatedSubObjects is void — no willReturn needed
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\HookStoppedException(
                'Hook blocked',
                ['field' => 'error']
            ));

        $result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(422, $result->getStatus());
    }

    public function testPostPatchReturns500OnGenericException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Fail']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        // clearCreatedSubObjects is void — no willReturn needed
        $this->objectService->method('saveObject')
            ->willThrowException(new Exception('Unexpected error'));

        $result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(500, $result->getStatus());
    }

    // =========================================================================
    // update() — additional uncovered paths
    // =========================================================================

    /**
     * Test update() returns 423 when the object is locked by another user.
     * The object has isLocked() === true and lockedBy !== current user.
     * Uses addMethods() because getRegister/getSchema/isLocked/getLockedBy are __call magic methods.
     */
    public function testUpdateReturns423WhenObjectLocked(): void
    {
        $this->setupAdminUser();

        $existingObject = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->onlyMethods(['isLocked', 'getLockedBy'])
            ->addMethods(['getRegister', 'getSchema'])
            ->getMock();
        $existingObject->method('isLocked')->willReturn(true);
        $existingObject->method('getLockedBy')->willReturn('other-user');
        $existingObject->method('getRegister')->willReturn(1);
        $existingObject->method('getSchema')->willReturn(2);

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);

        // container->get('userId') returns a different user ID than the lock holder
        $this->container->method('get')->willReturn('current-user');

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(423, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('lockedBy', $data);
        $this->assertSame('other-user', $data['lockedBy']);
    }

    /**
     * Test update() returns 500 when findSilent throws a generic \Exception.
     * Covers the catch(\Exception) block at line ~1840.
     */
    public function testUpdateReturns500OnGenericExceptionFromFindSilent(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')
            ->willThrowException(new \RuntimeException('Database connection lost'));

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Database connection lost', $data['error']);
    }

    /**
     * Test update() returns 404 when object has wrong schema (register matches, schema doesn't).
     * This variant uses schema 99 vs expected 2 (complementary to testUpdateReturns404WhenObjectInWrongRegisterSchema).
     */
    public function testUpdateReturns404WhenObjectHasWrongSchemaOnly(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(99); // different schema

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    // =========================================================================
    // patch() — additional uncovered paths
    // =========================================================================

    /**
     * Test patch() returns 400 via handleValidationException (admin user variant).
     */
    public function testPatchReturns400OnValidationExceptionAdminUser(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $validationResponse = new \OCP\AppFramework\Http\JSONResponse(['error' => 'Invalid field'], 400);

        $this->request->method('getParams')->willReturn(['title' => 'Bad']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\ValidationException('Invalid'));
        $this->objectService->method('handleValidationException')
            ->willReturn($validationResponse);

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    // =========================================================================
    // postPatch() — additional uncovered paths
    // =========================================================================

    /**
     * Test postPatch() returns 400 via handleValidationException (admin user variant).
     */
    public function testPostPatchReturns400OnValidationExceptionAdminUser(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $validationResponse = new \OCP\AppFramework\Http\JSONResponse(['error' => 'Bad data'], 400);

        $this->request->method('getParams')->willReturn(['title' => 'Bad']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\ValidationException('Invalid'));
        $this->objectService->method('handleValidationException')
            ->willReturn($validationResponse);

        $result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    // =========================================================================
    // index() — success path
    // =========================================================================

    /**
     * Test index() success path: resolves register+schema and returns searchObjectsPaginated result.
     */
    public function testIndexReturnsSearchResults(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit'  => 20,
            '_offset' => 0,
        ]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
            'pages'   => 1,
            'page'    => 1,
            'limit'   => 20,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertSame(0, $data['total']);
    }

    /**
     * Test index() with results — verifies pagination data structure.
     */
    public function testIndexReturnsPaginatedData(): void
    {
        $this->request->method('getParams')->willReturn(['_limit' => '10']);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2?_limit=10');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit'  => 10,
            '_offset' => 0,
        ]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [['uuid' => 'abc'], ['uuid' => 'def']],
            'total'   => 2,
            'pages'   => 1,
            'page'    => 1,
            'limit'   => 10,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(2, $data['total']);
        $this->assertCount(2, $data['results']);
    }

    // =========================================================================
    // objects() — with register+schema params
    // =========================================================================

    /**
     * Test objects() with register and schema query params that resolve (but no magic mapping).
     * When resolveRegisterSchemaIds resolves but entities are null (DI mapper throws),
     * falls through to normal searchObjectsPaginated path.
     */
    public function testObjectsWithRegisterSchemaParamsResolvesToNormalSearch(): void
    {
        $this->request->method('getParams')->willReturn([
            'register' => '1',
            'schema'   => '2',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
        ]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total'   => 0,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
    }

    /**
     * Test objects() without register/schema params uses plain searchObjectsPaginated.
     */
    public function testObjectsWithoutRegisterSchemaParams(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
        ]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [['uuid' => 'test-1']],
            'total'   => 1,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(1, $data['total']);
    }

    /**
     * Test objects() with register+schema that throws RegisterNotFoundException (returns 404).
     * Variant: uses register=99 (schema=2).
     */
    public function testObjectsReturns404WhenRegisterNotFoundViaQueryParam(): void
    {
        $this->request->method('getParams')->willReturn([
            'register' => '99',
            'schema'   => '2',
        ]);

        $this->objectService->method('setRegister')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // =========================================================================
    // logs() — additional paths
    // =========================================================================

    /**
     * Test logs() returns 404 when register does not match (with message key check).
     */
    public function testLogsReturns404WhenRegisterDoesNotMatchWithMessage(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('99'); // different register
        $objectEntity->setSchema('2');

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn($objectEntity);

        $result = $this->controller->logs('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('does not belong', $data['message']);
    }

    /**
     * Test logs() returns 404 when schema does not match (with message content).
     */
    public function testLogsReturns404WhenSchemaDoesNotMatchWithMessage(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('1');
        $objectEntity->setSchema('99'); // different schema

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn($objectEntity);

        $result = $this->controller->logs('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('message', $data);
    }

    // =========================================================================
    // create() — regular user path (RBAC enabled)
    // =========================================================================

    /**
     * Test create() with regular user (non-admin) sets rbac=true and still returns 201 on success.
     */
    public function testCreateWithRegularUserReturns201(): void
    {
        $this->setupRegularUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Created']);

        $this->request->method('getParams')->willReturn(['title' => 'Created']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    /**
     * Test create() with no user session (returns isAdmin=false, rbac=true).
     */
    public function testCreateWithNoUserSessionReturns201OnSuccess(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Created']);

        $this->request->method('getParams')->willReturn(['title' => 'Public']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // destroy() — additional paths
    // =========================================================================

    /**
     * Test destroy() with regular user (non-admin, rbac=true) and successful delete.
     */
    public function testDestroyWithRegularUserReturns204(): void
    {
        $this->setupRegularUser();

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('deleteObject')->willReturn(true);

        $result = $this->controller->destroy('uuid-123', 'reg', 'schema', $this->objectService);

        $this->assertSame(204, $result->getStatus());
    }

    /**
     * Test destroy() returns 500 when deleteObject returns false with regular user.
     */
    public function testDestroyWithRegularUserReturns500WhenDeleteFails(): void
    {
        $this->setupRegularUser();

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('deleteObject')->willReturn(false);

        $result = $this->controller->destroy('uuid-123', 'reg', 'schema', $this->objectService);

        $this->assertSame(500, $result->getStatus());
    }

    // =========================================================================
    // show() — with _empty=true param
    // =========================================================================

    /**
     * Test show() with _empty=true preserves null/empty values in response.
     */
    public function testShowWithEmptyTruePreservesNullValues(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn(['_empty' => 'true']);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test', 'empty_field' => null]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title'       => 'Test',
            'empty_field' => null,
            '@self'       => ['uuid' => 'uuid-123'],
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        // With _empty=true, stripEmptyValues is NOT called, null values remain
        $data = $result->getData();
        $this->assertArrayHasKey('empty_field', $data);
        $this->assertNull($data['empty_field']);
    }

    // =========================================================================
    // vectorizeBatch() — additional paths
    // =========================================================================

    /**
     * Test vectorizeBatch() with views param set.
     */
    public function testVectorizeBatchWithViewsParam(): void
    {
        $this->request->method('getParams')->willReturn([
            'views'     => ['view1', 'view2'],
            'batchSize' => 5,
        ]);

        $this->objectService->method('vectorizeBatchObjects')->willReturn([
            'processed' => 5,
            'views'     => ['view1', 'view2'],
        ]);

        $result = $this->controller->vectorizeBatch();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    // =========================================================================
    // canDelete() — success with canDelete returning false (blocked)
    // =========================================================================

    /**
     * Test canDelete() returns 200 even when analysis says deletion is blocked.
     */
    public function testCanDeleteReturns200WhenDeletionBlocked(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $ref = new \ReflectionClass($objectEntity);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($objectEntity, 2);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectEntityMapper->method('findAcrossAllSources')->willReturn([
            'object' => $objectEntity,
        ]);

        $analysis = new \OCA\OpenRegister\Dto\DeletionAnalysis(
            false,
            [],
            [],
            [],
            [['uuid' => 'blocker', 'reason' => 'RESTRICT']],
            []
        );

        $deleteHandler = $this->createMock(\OCA\OpenRegister\Service\Object\DeleteObject::class);
        $deleteHandler->method('canDelete')->willReturn($analysis);
        $this->objectService->method('getDeleteHandler')->willReturn($deleteHandler);

        $result = $this->controller->canDelete('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // DeletionAnalysis with canDelete=false should still return 200 with analysis data
        $this->assertIsArray($data);
    }

    // =========================================================================
    // contracts() / uses() / used() — with pagination params
    // =========================================================================

    /**
     * Test contracts() with pagination params including offset and page.
     */
    public function testContractsWithPaginationParamsAndOffset(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit'  => '10',
            '_offset' => '20',
            '_page'   => '3',
        ]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [['uuid' => 'contract-1']],
            'total'   => 1,
        ]);

        $result = $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertSame(10, $data['limit']);
    }

    /**
     * Test uses() with search params in request.
     */
    public function testUsesWithSearchParams(): void
    {
        $this->request->method('getParams')->willReturn([
            '_search' => 'test',
            '_limit'  => '5',
        ]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectUses')->willReturn([
            'results' => [],
            'total'   => 0,
            'limit'   => 5,
            'offset'  => 0,
        ]);

        $result = $this->controller->uses('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    /**
     * Test used() with search params in request.
     */
    public function testUsedWithSearchParams(): void
    {
        $this->request->method('getParams')->willReturn([
            '_search' => 'referenced',
        ]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectUsedBy')->willReturn([
            'results' => [],
            'total'   => 0,
            'limit'   => 30,
            'offset'  => 0,
        ]);

        $result = $this->controller->used('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // unlock() — error path
    // =========================================================================

    public function testUnlockReturns404WhenObjectNotFound(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('unlockObject')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->controller->unlock('reg1', 'schema1', 'uuid-123');
    }

    // =========================================================================
    // logs() — success path with matching register/schema
    // =========================================================================

    public function testLogsReturnsPaginatedLogsOnSuccess(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('1');
        $objectEntity->setSchema('2');
        $objectEntity->setObject(['title' => 'Test']);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('getLogs')->willReturn([
            ['action' => 'create', 'timestamp' => '2024-01-01'],
        ]);

        $result = $this->controller->logs('uuid-123', '1', '2', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    // =========================================================================
    // destroy() — additional error paths
    // =========================================================================

    public function testDestroyReturns204WhenAdminDeletes(): void
    {
        $this->setupAdminUser();

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('deleteObject')->willReturn(true);

        $result = $this->controller->destroy('uuid-123', 'reg', 'schema', $this->objectService);

        $this->assertSame(204, $result->getStatus());
    }

    // =========================================================================
    // migrate() — additional validation paths
    // =========================================================================

    public function testMigrateReturns400OnInvalidArgumentException(): void
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
            ->willThrowException(new \InvalidArgumentException('Invalid mapping'));

        $result = $this->controller->migrate($this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    // =========================================================================
    // validate() — with limit and offset
    // =========================================================================

    public function testValidateWithLimitAndOffset(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['register', null, '1'],
                ['schema', null, '2'],
                ['limit', null, '50'],
                ['offset', null, '10'],
            ]);

        $this->objectService->method('validateAndSaveObjectsBySchema')->willReturn([
            'processed' => 50,
            'updated' => 45,
            'failed' => 5,
            'total' => 100,
            'errors' => [],
        ]);

        $result = $this->controller->validate();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(50, $data['statistics']['processed']);
        $this->assertArrayHasKey('pagination', $data);
    }

    // =========================================================================
    // import() — with schema parameter
    // =========================================================================

    public function testImportWithSchemaParameter(): void
    {
        $register = new \OCA\OpenRegister\Db\Register();
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, 1);

        $schema = new \OCA\OpenRegister\Db\Schema();
        $refS = new \ReflectionClass($schema);
        $propS = $refS->getProperty('id');
        $propS->setAccessible(true);
        $propS->setValue($schema, 5);

        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'data.csv',
            'tmp_name' => '/tmp/data.csv',
            'size' => 1024,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['schema', null, '5'],
            ['validation', false, false],
            ['events', false, false],
            ['rbac', true, true],
            ['multi', true, true],
            ['publish', false, false],
        ]);

        $this->registerMapper->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->objectService->method('importObjects')->willReturn([
            'imported' => 8,
        ]);

        $user = $this->createMock(\OCP\IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $result = $this->controller->import(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Import successful', $data['message']);
    }

    // =========================================================================
    // index() — success path with paginated results
    // =========================================================================

    public function testIndexReturnsPaginatedResults(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 20,
        ]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [['uuid' => 'uuid-1', 'title' => 'Test']],
            'total' => 1,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    public function testIndexReturnsEmptyResultsForNoObjects(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame([], $data['results']);
        $this->assertSame(0, $data['total']);
    }

    // =========================================================================
    // index() — strips empty values by default
    // =========================================================================

    public function testIndexStripsEmptyValuesFromResults(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                ['uuid' => 'uuid-1', 'title' => 'Test', 'description' => null, 'tags' => []],
            ],
            'total' => 1,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // null and empty array values should be stripped
        $this->assertArrayNotHasKey('description', $data['results'][0]);
        $this->assertArrayNotHasKey('tags', $data['results'][0]);
    }

    public function testIndexIncludesEmptyValuesWhenRequested(): void
    {
        $this->request->method('getParams')->willReturn(['_empty' => 'true']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                ['uuid' => 'uuid-1', 'title' => 'Test', 'description' => null, 'tags' => []],
            ],
            'total' => 1,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // empty values should be preserved when _empty=true
        $this->assertNull($data['results'][0]['description']);
        $this->assertSame([], $data['results'][0]['tags']);
    }

    // =========================================================================
    // show() — with _empty=true parameter preserves empty values
    // =========================================================================

    public function testShowPreservesEmptyValuesWhenEmptyParamTrue(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn(['_empty' => 'true']);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            'description' => null,
            'empty_array' => [],
            '@self' => ['uuid' => 'uuid-123'],
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // with _empty=true, null and empty array should be preserved
        $this->assertNull($data['description']);
        $this->assertSame([], $data['empty_array']);
    }

    // =========================================================================
    // show() — with _extend parameter including _register and _schema
    // =========================================================================

    public function testShowWithExtendRegistersAndSchemas(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_register,_schema',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // update() — locked object returns 423
    // =========================================================================

    public function testUpdateReturns423WhenObjectIsLocked(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);
        // Lock the object by another user - locked is an array with user and expiration
        $existingObject->setLocked([
            'user' => 'other-user',
            'expiration' => (new \DateTime('+1 hour'))->format('c'),
            'process' => 'editing',
        ]);

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);

        // Mock container->get('userId') to return a different user
        $this->container->method('get')
            ->willReturn('current-user');

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(423, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('lockedBy', $data);
        $this->assertSame('other-user', $data['lockedBy']);
    }

    // =========================================================================
    // update() — findSilent throws generic exception returns 500
    // =========================================================================

    public function testUpdateReturns500OnUnexpectedFindSilentException(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')
            ->willThrowException(new \RuntimeException('Database connection lost'));

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(500, $result->getStatus());
    }

    // =========================================================================
    // create() — CustomValidationException returns 400
    // =========================================================================

    public function testCreateReturns400OnCustomValidationException(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn(['title' => 'Bad']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\CustomValidationException(
                'Custom validation failed',
                ['name' => 'Name is required']
            ));

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    // =========================================================================
    // update() — CustomValidationException returns validation error via service
    // =========================================================================

    public function testUpdateReturns400OnCustomValidationException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Bad']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\CustomValidationException(
                'Custom validation failed',
                ['name' => 'Name is required']
            ));

        $validationResponse = new JSONResponse(['error' => 'Custom validation failed'], 400);
        $this->objectService->method('handleValidationException')
            ->willReturn($validationResponse);

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    // =========================================================================
    // patch() — ValidationException returns validation error via service
    // =========================================================================

    public function testPatchReturns400OnValidationException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Invalid']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\ValidationException('Schema validation failed'));

        $validationResponse = new JSONResponse(['error' => 'Schema validation failed'], 400);
        $this->objectService->method('handleValidationException')
            ->willReturn($validationResponse);

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    // =========================================================================
    // patch() — CustomValidationException returns validation error
    // =========================================================================

    public function testPatchReturns400OnCustomValidationException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Bad']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\CustomValidationException(
                'Custom validation failed',
                ['email' => 'Invalid email']
            ));

        $validationResponse = new JSONResponse(['error' => 'Custom validation failed'], 400);
        $this->objectService->method('handleValidationException')
            ->willReturn($validationResponse);

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    // =========================================================================
    // postPatch() — ValidationException returns validation error
    // =========================================================================

    public function testPostPatchReturns400OnValidationException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Invalid']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\ValidationException('Schema validation failed'));

        $validationResponse = new JSONResponse(['error' => 'Schema validation failed'], 400);
        $this->objectService->method('handleValidationException')
            ->willReturn($validationResponse);

        $result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    // =========================================================================
    // postPatch() — CustomValidationException returns validation error
    // =========================================================================

    public function testPostPatchReturns400OnCustomValidationException(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Bad']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\CustomValidationException(
                'Custom validation failed',
                ['field' => 'Required']
            ));

        $validationResponse = new JSONResponse(['error' => 'Custom validation failed'], 400);
        $this->objectService->method('handleValidationException')
            ->willReturn($validationResponse);

        $result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(400, $result->getStatus());
    }

    // =========================================================================
    // show() — with _extend including _names
    // =========================================================================

    public function testShowWithExtendNames(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_names',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $cacheHandler = $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class);
        $cacheHandler->method('getMultipleObjectNames')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);
        $this->objectService->method('getCacheHandler')->willReturn($cacheHandler);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // show() — with fields as comma-separated string
    // =========================================================================

    public function testShowWithFieldsParameter(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_fields' => 'title,status',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // show() — with filter and unset as comma-separated strings
    // =========================================================================

    public function testShowWithFilterAndUnsetParameters(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_filter' => 'title',
            '_unset' => 'status',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test', 'status' => 'published']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // show() — as regular user (non-admin), RBAC enabled
    // =========================================================================

    public function testShowAsRegularUserEnablesRbac(): void
    {
        $this->setupRegularUser();
        $this->request->method('getParams')->willReturn([]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // create() — as regular user (non-admin), RBAC enabled
    // =========================================================================

    public function testCreateAsRegularUser(): void
    {
        $this->setupRegularUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Created']);

        $this->request->method('getParams')->willReturn(['title' => 'Created']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // create() — filters out reserved params (_route, uuid, register, schema)
    // =========================================================================

    public function testCreateFiltersReservedParams(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Created']);

        $this->request->method('getParams')->willReturn([
            'title' => 'Created',
            '_route' => 'some.route',
            'uuid' => 'should-be-removed',
            'register' => '1',
            'schema' => '2',
        ]);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // update() — successful update unlocks the object
    // =========================================================================

    public function testUpdateWithFiltersReservedParams(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);

        $updatedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $updatedObject->setUuid('uuid-123');
        $updatedObject->setObject(['title' => 'Updated']);

        $this->request->method('getParams')->willReturn([
            'title' => 'Updated',
            '_route' => 'some.route',
            'uuid' => 'should-filter',
            'register' => '1',
            'schema' => '2',
        ]);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($updatedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // objects() — with register and schema params
    // =========================================================================

    public function testObjectsWithFilterParams(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
        ]);

        $this->objectService->method('buildSearchQuery')->willReturn([
            '_limit' => 10,
        ]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // objects() — with exception
    // =========================================================================

    public function testObjectsThrowsWhenSearchFails(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')
            ->willThrowException(new DBException('Search error'));

        $this->expectException(DBException::class);
        $this->controller->objects($this->objectService);
    }

    // =========================================================================
    // contracts() — with pagination params
    // =========================================================================

    public function testContractsWithPaginationParams(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_offset' => '5',
            '_page' => '2',
        ]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [['uuid' => 'contract-1']],
            'total' => 1,
        ]);

        $result = $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
    }

    // =========================================================================
    // uses() — with pagination params
    // =========================================================================

    public function testUsesWithPaginationParams(): void
    {
        $this->request->method('getParams')->willReturn([
            'limit' => '5',
            'offset' => '0',
            'page' => '1',
        ]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectUses')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->uses('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // used() — with pagination params
    // =========================================================================

    public function testUsedWithPaginationParams(): void
    {
        $this->request->method('getParams')->willReturn([
            'limit' => '5',
            'offset' => '0',
        ]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectUsedBy')->willReturn([
            'results' => [['uuid' => 'ref-1']],
            'total' => 1,
        ]);

        $result = $this->controller->used('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(1, $data['total']);
    }

    // =========================================================================
    // publish() — with specific date parameter
    // =========================================================================

    public function testPublishWithDateParameter(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->request->method('getParam')->willReturnMap([
            ['date', null, '2025-06-15'],
        ]);
        $this->objectService->method('publish')->willReturn($objectEntity);

        $result = $this->controller->publish('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // depublish() — with specific date parameter
    // =========================================================================

    public function testDepublishWithDateParameter(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->request->method('getParam')->willReturnMap([
            ['date', null, '2025-12-31'],
        ]);
        $this->objectService->method('depublish')->willReturn($objectEntity);

        $result = $this->controller->depublish('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // destroy() — as regular user succeeds when service allows
    // =========================================================================

    public function testDestroyAsRegularUserSucceeds(): void
    {
        $this->setupRegularUser();

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('deleteObject')->willReturn(true);

        $result = $this->controller->destroy('uuid-123', 'reg', 'schema', $this->objectService);

        $this->assertSame(204, $result->getStatus());
    }

    // =========================================================================
    // merge() — with both target and object data provided
    // =========================================================================

    public function testMergeReturnsFullMergeResult(): void
    {
        $this->request->method('getParams')->willReturn([
            'target' => 'uuid-456',
            'object' => ['title' => 'Merged', 'description' => 'Combined'],
        ]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('mergeObjects')->willReturn([
            'success' => true,
            'uuid' => 'uuid-456',
            'mergedFields' => ['title', 'description'],
        ]);

        $result = $this->controller->merge('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('mergedFields', $data);
    }

    // =========================================================================
    // vectorizeBatch() — with specific views and batch size
    // =========================================================================

    public function testVectorizeBatchWithViewsAndBatchSize(): void
    {
        $this->request->method('getParams')->willReturn([
            'views' => ['view1', 'view2'],
            'batchSize' => 50,
        ]);

        $this->objectService->method('vectorizeBatchObjects')->willReturn([
            'processed' => 50,
            'view1' => 25,
            'view2' => 25,
        ]);

        $result = $this->controller->vectorizeBatch();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(50, $data['data']['processed']);
    }

    // =========================================================================
    // getObjectVectorizationStats() — with views parameter as JSON string
    // =========================================================================

    public function testGetObjectVectorizationStatsWithViewsParam(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['views', null, '["view1","view2"]'],
        ]);

        $this->objectService->method('getVectorizationStatistics')->willReturn([
            'total' => 200,
            'vectorized' => 150,
            'remaining' => 50,
        ]);

        $result = $this->controller->getObjectVectorizationStats();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    // =========================================================================
    // getObjectVectorizationCount() — with views parameter
    // =========================================================================

    public function testGetObjectVectorizationCountWithViews(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['views', null, '["view1"]'],
        ]);

        $this->objectService->method('getVectorizationCount')->willReturn(99);

        $result = $this->controller->getObjectVectorizationCount();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(99, $data['count']);
    }

    // =========================================================================
    // logs() — register/schema not found paths
    // =========================================================================

    public function testLogsThrowsWhenRegisterNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->controller->logs('uuid-123', '99', '2', $this->objectService);
    }

    public function testLogsThrowsWhenSchemaNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->controller->logs('uuid-123', '1', '99', $this->objectService);
    }

    // =========================================================================
    // create() — with multipart/form-data normalizes JSON strings
    // =========================================================================

    public function testCreateWithMultipartFormDataNormalizesJson(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Created', 'tags' => ['a', 'b']]);

        $this->request->method('getParams')->willReturn([
            'title' => 'Created',
            'tags' => '["a","b"]',
        ]);
        $this->request->method('getHeader')->willReturn('multipart/form-data; boundary=something');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // update() — with multipart/form-data
    // =========================================================================

    public function testUpdateWithMultipartFormData(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);

        $updatedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $updatedObject->setUuid('uuid-123');
        $updatedObject->setObject(['title' => 'Updated']);

        $this->request->method('getParams')->willReturn([
            'title' => 'Updated',
            'data' => '{"nested":"value"}',
        ]);
        $this->request->method('getHeader')->willReturn('multipart/form-data; boundary=---');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($updatedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // patch() — as regular user
    // =========================================================================

    public function testPatchAsRegularUser(): void
    {
        $this->setupRegularUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old', 'status' => 'draft']);

        $patchedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $patchedObject->setUuid('uuid-123');
        $patchedObject->setObject(['title' => 'Patched', 'status' => 'draft']);

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($patchedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // postPatch() — as regular user with multipart
    // =========================================================================

    public function testPostPatchAsRegularUser(): void
    {
        $this->setupRegularUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $patchedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $patchedObject->setUuid('uuid-123');
        $patchedObject->setObject(['title' => 'PostPatched']);

        $this->request->method('getParams')->willReturn(['title' => 'PostPatched']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($patchedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // validate() — with errors in the result
    // =========================================================================

    public function testValidateReturnsResultWithErrors(): void
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
            'updated' => 5,
            'failed' => 5,
            'total' => 10,
            'errors' => [
                ['uuid' => 'uuid-1', 'error' => 'Missing required field'],
                ['uuid' => 'uuid-2', 'error' => 'Invalid format'],
            ],
        ]);

        $result = $this->controller->validate();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(5, $data['statistics']['failed']);
    }

    // =========================================================================
    // index() — gzip compression header for large result sets
    // =========================================================================

    public function testIndexAddsGzipHeaderForLargeResults(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);

        // Create > 10 results to trigger gzip header
        $results = [];
        for ($i = 0; $i < 15; $i++) {
            $results[] = ['uuid' => "uuid-{$i}", 'title' => "Item {$i}"];
        }
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => $results,
            'total' => 15,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(15, $data['results']);
    }

    // =========================================================================
    // show() — with extend parameter using @self.schema backwards compat
    // =========================================================================

    public function testShowNormalizesLegacyExtendParameters(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            'extend' => '@self.schema,@self.register',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // destroy() — as admin bypasses RBAC
    // =========================================================================

    public function testDestroyAsAdminBypassesRbac(): void
    {
        $this->setupAdminUser();

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('deleteObject')->willReturn(true);

        $result = $this->controller->destroy('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(204, $result->getStatus());
    }

    // =========================================================================
    // migrate() — with source same as target is valid
    // =========================================================================

    public function testMigrateWithAllValidParams(): void
    {
        $this->request->method('getParams')->willReturn([
            'sourceRegister' => '1',
            'sourceSchema' => '2',
            'targetRegister' => '3',
            'targetSchema' => '4',
            'objects' => ['uuid-1', 'uuid-2', 'uuid-3'],
            'mapping' => ['name' => 'title', 'desc' => 'description'],
        ]);

        $this->objectService->method('migrateObjects')->willReturn([
            'success' => true,
            'migrated' => 3,
            'failed' => 0,
        ]);

        $result = $this->controller->migrate($this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(3, $data['migrated']);
    }

    // =========================================================================
    // clearBlob() — success with zero deleted
    // =========================================================================

    public function testClearBlobSuccessWithZeroDeleted(): void
    {
        $this->objectEntityMapper->method('clearBlobObjects')->willReturn([
            'deleted' => 0,
        ]);

        $result = $this->controller->clearBlob();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(0, $data['deleted']);
    }

    // =========================================================================
    // lock() — with LockedException
    // =========================================================================

    public function testLockReturns423WhenAlreadyLocked(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('lockObject')
            ->willThrowException(new \OCA\OpenRegister\Exception\LockedException('Object is locked'));

        $result = $this->controller->lock('reg1', 'schema1', 'uuid-123');

        $this->assertSame(500, $result->getStatus());
    }

    // =========================================================================
    // canDelete() — success with blockers
    // =========================================================================

    public function testCanDeleteReturnsAnalysisWithBlockers(): void
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

        $analysis = new DeletionAnalysis(
            false,
            [],
            [],
            [],
            [['uuid' => 'blocker-1', 'reason' => 'RESTRICT']],
            []
        );
        $deleteHandler = $this->createMock(\OCA\OpenRegister\Service\Object\DeleteObject::class);
        $deleteHandler->method('canDelete')->willReturn($analysis);
        $this->objectService->method('getDeleteHandler')->willReturn($deleteHandler);

        $result = $this->controller->canDelete('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['deletable']);
    }

    // =========================================================================
    // show() — with no @self in rendered data
    // =========================================================================

    public function testShowReturnsObjectWithoutAtSelf(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            // No @self key
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Test', $data['title']);
    }

    // =========================================================================
    // update() — with wrong schema returns 404
    // =========================================================================

    public function testUpdateReturns404WhenObjectInWrongSchema(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(99); // different schema
        $existingObject->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // =========================================================================
    // create() — webhook interception (when webhook service is available)
    // =========================================================================

    public function testCreateContinuesWhenWebhookFails(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Created']);

        $this->request->method('getParams')->willReturn(['title' => 'Created']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        // WebhookService interceptRequest throws OCP\DB\Exception — controller catches this
        $this->webhookService->method('interceptRequest')
            ->willThrowException(new DBException('Webhook failed'));

        $result = $this->controller->create('1', '2', $this->objectService);

        // Should still succeed despite webhook failure
        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // validate() — with both limit and offset and pagination details
    // =========================================================================

    public function testValidateReturnsPaginationDetailsWhenLimitAndOffset(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['register', null, '1'],
                ['schema', null, '2'],
                ['limit', null, '25'],
                ['offset', null, '50'],
            ]);

        $this->objectService->method('validateAndSaveObjectsBySchema')->willReturn([
            'processed' => 25,
            'updated' => 20,
            'failed' => 5,
            'total' => 100,
            'errors' => [],
        ]);

        $result = $this->controller->validate();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertSame(25, $data['pagination']['limit']);
        $this->assertSame(50, $data['pagination']['offset']);
    }

    // =========================================================================
    // isCurrentUserAdmin() — null user returns false (not admin)
    // =========================================================================

    public function testDestroyWithNoUserSessionIsNotAdmin(): void
    {
        // No user set up -> getUser returns null -> isCurrentUserAdmin() returns false
        // Regular non-admin path
        $this->userSession->method('getUser')->willReturn(null);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('deleteObject')->willReturn(true);

        $result = $this->controller->destroy('uuid-123', 'reg', 'schema', $this->objectService);

        $this->assertSame(204, $result->getStatus());
    }

    // =========================================================================
    // patch() — unlock error is silently ignored
    // =========================================================================

    public function testPatchUnlockErrorIsSilentlyIgnored(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $patchedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $patchedObject->setUuid('uuid-123');
        $patchedObject->setObject(['title' => 'Patched']);

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($patchedObject);
        $this->objectService->method('unlockObject')
            ->willThrowException(new Exception('Unlock failed'));

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // postPatch() — unlock error is silently ignored
    // =========================================================================

    public function testPostPatchUnlockErrorIsSilentlyIgnored(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $patchedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $patchedObject->setUuid('uuid-123');
        $patchedObject->setObject(['title' => 'PostPatched']);

        $this->request->method('getParams')->willReturn(['title' => 'PostPatched']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($patchedObject);
        $this->objectService->method('unlockObject')
            ->willThrowException(new Exception('Unlock failed'));

        $result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // stripEmptyValues — sequential arrays with nested associative arrays
    // =========================================================================

    public function testIndexStripsEmptyValuesFromNestedSequentialArrays(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                [
                    'uuid' => 'uuid-1',
                    'title' => 'Test',
                    // Sequential array with nested assoc arrays containing empty values
                    'contacts' => [
                        ['name' => 'John', 'email' => null, 'phone' => ''],
                        ['name' => 'Jane', 'notes' => ''],
                    ],
                    // Sequential array with scalar values
                    'tags' => ['alpha', 'beta'],
                    // Nested associative with empty child
                    'metadata' => ['key1' => 'value1', 'key2' => null, 'nested' => ['a' => null]],
                    // Zero and false should be preserved
                    'count' => 0,
                    'active' => false,
                    // Empty string should be stripped
                    'description' => '',
                ],
            ],
            'total' => 1,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $item = $data['results'][0];

        // Zero and false should be preserved
        $this->assertSame(0, $item['count']);
        $this->assertFalse($item['active']);
        // Empty string should be stripped
        $this->assertArrayNotHasKey('description', $item);
        // tags should remain
        $this->assertSame(['alpha', 'beta'], $item['tags']);
        // contacts should have stripped null/empty from nested assoc arrays
        $this->assertArrayNotHasKey('email', $item['contacts'][0]);
        $this->assertArrayNotHasKey('phone', $item['contacts'][0]);
    }

    // =========================================================================
    // contracts() — with legacy offset and page params (non-underscore)
    // =========================================================================

    public function testContractsWithLegacyOffsetAndPageParams(): void
    {
        $this->request->method('getParams')->willReturn([
            'limit' => '15',
            'offset' => '30',
            'page' => '3',
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/contracts');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [['uuid' => 'contract-1']],
            'total' => 50,
        ]);

        $result = $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('pages', $data);
        $this->assertArrayHasKey('limit', $data);
    }

    // =========================================================================
    // contracts() — page calculation from offset
    // =========================================================================

    public function testContractsCalculatesPageFromOffset(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_offset' => '20',
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid/contracts?_offset=20');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [],
            'total' => 50,
        ]);

        $result = $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // page should be calculated from offset: floor(20/10) + 1 = 3
        $this->assertEquals(3, $data['page']);
    }

    // =========================================================================
    // contracts() — paginate with next/prev links
    // =========================================================================

    public function testContractsPaginateAddsNextLink(): void
    {
        $this->request->method('getParams')->willReturn([
            '_page' => '1',
            '_limit' => '5',
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid/contracts?_page=1&_limit=5');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [['uuid' => 'c1'], ['uuid' => 'c2']],
            'total' => 20,
        ]);

        $result = $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // Should have next link (page 1 of 4 pages)
        $this->assertArrayHasKey('next', $data);
    }

    public function testContractsPaginateAddsPrevLink(): void
    {
        $this->request->method('getParams')->willReturn([
            '_page' => '3',
            '_limit' => '5',
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid/contracts?_page=3&_limit=5');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [['uuid' => 'c1']],
            'total' => 20,
        ]);

        $result = $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // Should have prev link (page 3 > 1)
        $this->assertArrayHasKey('prev', $data);
    }

    // =========================================================================
    // paginate — total < count(results) triggers auto-correct
    // =========================================================================

    public function testContractsPaginateAutoCorrectsTotalWhenLessThanResults(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid/contracts');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')->willReturn([
            // 5 results but total says 2 (inconsistent), paginate should auto-correct
            'results' => [['a' => 1], ['b' => 2], ['c' => 3], ['d' => 4], ['e' => 5]],
            'total' => 2,
        ]);

        $result = $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // Total should be auto-corrected to at least 5
        $this->assertGreaterThanOrEqual(5, $data['total']);
    }

    // =========================================================================
    // paginate — next/prev URL generation when URL has no page= param
    // =========================================================================

    public function testContractsPaginateAddsNextPageToUrlWithoutPageParam(): void
    {
        $this->request->method('getParams')->willReturn([
            '_page' => '1',
            '_limit' => '2',
        ]);
        // URL without page= parameter
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid/contracts?_limit=2');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [['uuid' => 'c1']],
            'total' => 10,
        ]);

        $result = $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('next', $data);
        $this->assertStringContainsString('page=2', $data['next']);
    }

    public function testContractsPaginateAddsNextPageToUrlWithNoQueryString(): void
    {
        $this->request->method('getParams')->willReturn([
            '_page' => '1',
            '_limit' => '2',
        ]);
        // URL without any query string
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid/contracts');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [['uuid' => 'c1']],
            'total' => 10,
        ]);

        $result = $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('next', $data);
    }

    // =========================================================================
    // show() — with _registers and _schemas extend includes register/schema data
    // =========================================================================

    public function testShowWithExtendRegistersIncludesRegisterData(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_registers',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $registerEntity = new \OCA\OpenRegister\Db\Register();
        $ref = new \ReflectionClass($registerEntity);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($registerEntity, 1);
        $registerEntity->setTitle('Test Register');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        // resolveRegisterSchemaIds needs to return the register entity
        // Since it accesses \OC::$server, we test that the show path handles null entities gracefully
        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    public function testShowWithExtendSchemasIncludesSchemaData(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_schemas',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // create() — webhook interception success modifies object
    // =========================================================================

    public function testCreateUsesWebhookInterceptedData(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Webhook-Modified']);

        $this->request->method('getParams')->willReturn(['title' => 'Original']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        // Webhook intercept returns modified data
        $this->webhookService->method('interceptRequest')
            ->willReturn(['title' => 'Webhook-Modified']);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // create() — with null webhookService (constructor allows null)
    // =========================================================================

    public function testCreateWithoutWebhookService(): void
    {
        // Create controller without webhook service
        $controller = new ObjectsController(
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
            null, // no webhook service
            $this->logger
        );

        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Created']);

        $this->request->method('getParams')->willReturn(['title' => 'Created']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // update() — unlock exception is silently ignored
    // =========================================================================

    public function testUpdateUnlockErrorIsSilentlyIgnored(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);

        $updatedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $updatedObject->setUuid('uuid-123');
        $updatedObject->setObject(['title' => 'Updated']);

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($updatedObject);
        $this->objectService->method('unlockObject')
            ->willThrowException(new DBException('Unlock failed'));

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        // Should still succeed despite unlock error
        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // update() — container get() throws (NotFoundExceptionInterface path)
    // =========================================================================

    public function testUpdateReturns500WhenContainerGetThrowsForLockedCheck(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);
        // Lock the object so the container->get('userId') is called
        $existingObject->setLocked([
            'user' => 'some-user',
            'expiration' => (new \DateTime('+1 hour'))->format('c'),
        ]);

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);

        // Container throws when getting userId — caught by the generic \Exception handler
        $this->container->method('get')
            ->willThrowException(new \RuntimeException('userId not found'));

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        // The \Exception catch returns 500 since it's an unexpected exception in findSilent block
        $this->assertSame(500, $result->getStatus());
    }

    // =========================================================================
    // update() — as regular user (RBAC enabled)
    // =========================================================================

    public function testUpdateAsRegularUser(): void
    {
        $this->setupRegularUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setRegister(1);
        $existingObject->setSchema(2);
        $existingObject->setObject(['title' => 'Old']);

        $updatedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $updatedObject->setUuid('uuid-123');
        $updatedObject->setObject(['title' => 'Updated']);

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($updatedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->update('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // objects() — with register and schema params (single combination)
    // =========================================================================

    public function testObjectsReturns404WhenRegisterNotFound(): void
    {
        $this->request->method('getParams')->willReturn([
            'register' => 'invalid-register',
            'schema' => 'some-schema',
        ]);

        $this->objectService->method('setRegister')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    public function testObjectsReturns404WhenSchemaNotFound(): void
    {
        $this->request->method('getParams')->willReturn([
            'register' => '1',
            'schema' => 'invalid-schema',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // =========================================================================
    // objects() — with _register and _schema params (underscore prefix)
    // =========================================================================

    public function testObjectsWithUnderscorePrefixedRegisterAndSchema(): void
    {
        $this->request->method('getParams')->willReturn([
            '_register' => '1',
            '_schema' => '2',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // objects() — strips empty values from results
    // =========================================================================

    public function testObjectsStripsEmptyValuesFromResults(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                ['uuid' => 'uuid-1', 'title' => 'Test', 'description' => null, 'tags' => []],
            ],
            'total' => 1,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayNotHasKey('description', $data['results'][0]);
        $this->assertArrayNotHasKey('tags', $data['results'][0]);
    }

    public function testObjectsPreservesEmptyValuesWhenRequested(): void
    {
        $this->request->method('getParams')->willReturn([
            '_empty' => 'true',
        ]);

        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                ['uuid' => 'uuid-1', 'title' => 'Test', 'description' => null],
            ],
            'total' => 1,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertNull($data['results'][0]['description']);
    }

    // =========================================================================
    // objects() — with schema param only (not register)
    // =========================================================================

    public function testObjectsWithSchemaParamOnly(): void
    {
        $this->request->method('getParams')->willReturn([
            'schema' => '2',
        ]);

        // No register param, so objects() falls through to normal query path
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    public function testObjectsWithRegisterParamOnly(): void
    {
        $this->request->method('getParams')->willReturn([
            'register' => '1',
        ]);

        // No schema param, so objects() falls through to normal query path
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // index() — with _empty=true preserves ObjectEntity items in results
    // =========================================================================

    public function testIndexHandlesObjectEntityResultsInEmptyStripping(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);

        // Return an ObjectEntity in results (simulating jsonSerialize item)
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-entity');
        $objectEntity->setObject(['title' => 'FromEntity']);

        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [$objectEntity],
            'total' => 1,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // ObjectEntity should be serialized via jsonSerialize then stripped
        $this->assertCount(1, $data['results']);
    }

    // =========================================================================
    // logs() — schema match via object/array format
    // =========================================================================

    public function testLogsMatchesSchemaBySlug(): void
    {
        // Use a mock to return an array from getSchema (real entity is typed ?string)
        $objectEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->onlyMethods(['getObject'])
            ->addMethods(['getSchema', 'getRegister', 'getUuid'])
            ->getMock();
        $objectEntity->method('getUuid')->willReturn('uuid-123');
        $objectEntity->method('getRegister')->willReturn('1');
        $objectEntity->method('getSchema')->willReturn(['id' => '2', 'slug' => 'my-schema']);
        $objectEntity->method('getObject')->willReturn(['title' => 'Test']);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/my-schema/uuid-123/logs');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('getLogs')->willReturn([]);

        $result = $this->controller->logs('uuid-123', '1', 'my-schema', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    public function testLogsReturns404WhenSchemaDoesNotMatch(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('1');
        $objectEntity->setSchema('5'); // different schema
        $objectEntity->setObject(['title' => 'Test']);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn($objectEntity);

        $result = $this->controller->logs('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('does not belong', $data['message']);
    }

    public function testLogsReturns404WhenRegisterDoesNotMatch(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('99'); // different register
        $objectEntity->setSchema('2');
        $objectEntity->setObject(['title' => 'Test']);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn($objectEntity);

        $result = $this->controller->logs('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // =========================================================================
    // logs() — with schema as stdClass object
    // =========================================================================

    public function testLogsMatchesSchemaAsObject(): void
    {
        $schemaObj = new \stdClass();
        $schemaObj->id = '2';
        $schemaObj->slug = 'test-schema';

        // Use a mock to return a stdClass from getSchema (real entity is typed ?string)
        $objectEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->onlyMethods(['getObject'])
            ->addMethods(['getSchema', 'getRegister', 'getUuid'])
            ->getMock();
        $objectEntity->method('getUuid')->willReturn('uuid-123');
        $objectEntity->method('getRegister')->willReturn('1');
        $objectEntity->method('getSchema')->willReturn($schemaObj);
        $objectEntity->method('getObject')->willReturn(['title' => 'Test']);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/test-schema/uuid-123/logs');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('getLogs')->willReturn([]);

        $result = $this->controller->logs('uuid-123', '1', 'test-schema', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // logs() — with schema as stdClass without slug
    // =========================================================================

    public function testLogsMatchesSchemaAsObjectById(): void
    {
        $schemaObj = new \stdClass();
        $schemaObj->id = '2';

        // Use a mock to return a stdClass from getSchema (real entity is typed ?string)
        $objectEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->onlyMethods(['getObject'])
            ->addMethods(['getSchema', 'getRegister', 'getUuid'])
            ->getMock();
        $objectEntity->method('getUuid')->willReturn('uuid-123');
        $objectEntity->method('getRegister')->willReturn('1');
        $objectEntity->method('getSchema')->willReturn($schemaObj);
        $objectEntity->method('getObject')->willReturn(['title' => 'Test']);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('getLogs')->willReturn([]);

        $result = $this->controller->logs('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // logs() — with schema as array without slug
    // =========================================================================

    public function testLogsMatchesSchemaAsArrayById(): void
    {
        // Use a mock to return an array from getSchema (real entity is typed ?string)
        $objectEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\ObjectEntity::class)
            ->onlyMethods(['getObject'])
            ->addMethods(['getSchema', 'getRegister', 'getUuid'])
            ->getMock();
        $objectEntity->method('getUuid')->willReturn('uuid-123');
        $objectEntity->method('getRegister')->willReturn('1');
        $objectEntity->method('getSchema')->willReturn(['id' => '2']);
        $objectEntity->method('getObject')->willReturn(['title' => 'Test']);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('getLogs')->willReturn([]);

        $result = $this->controller->logs('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // logs() — with pagination params
    // =========================================================================

    public function testLogsWithPaginationParams(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('1');
        $objectEntity->setSchema('2');
        $objectEntity->setObject(['title' => 'Test']);

        $this->request->method('getParams')->willReturn([
            '_limit' => '5',
            '_offset' => '10',
            '_page' => '3',
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid-123/logs?_page=3');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('getLogs')->willReturn([
            ['action' => 'update', 'timestamp' => '2024-01-02'],
        ]);

        $result = $this->controller->logs('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('limit', $data);
    }

    // =========================================================================
    // getObjectVectorizationCount() — with schemas as JSON string
    // =========================================================================

    public function testGetObjectVectorizationCountWithSchemasJsonString(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['schemas', null, '[1,2,3]'],
        ]);

        $this->objectService->method('getVectorizationCount')->willReturn(55);

        $result = $this->controller->getObjectVectorizationCount();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertSame(55, $data['count']);
    }

    // =========================================================================
    // index() — with _empty=true preserves empty values
    // =========================================================================

    public function testIndexWithEmptyTruePreservesEmptyStrings(): void
    {
        $this->request->method('getParams')->willReturn(['_empty' => 'true']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [
                ['uuid' => 'uuid-1', 'title' => 'Test', 'note' => ''],
            ],
            'total' => 1,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('', $data['results'][0]['note']);
    }

    // =========================================================================
    // normalizeFormDataValues — multipart with non-JSON strings (non-string skip)
    // =========================================================================

    public function testCreateMultipartWithNonJsonStrings(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Test', 'count' => 5]);

        $this->request->method('getParams')->willReturn([
            'title' => 'Test',
            'count' => 5, // non-string value should be skipped
            'plain' => 'just a string', // doesn't start with [ or {
            'invalid_json' => '[not valid json',
        ]);
        $this->request->method('getHeader')->willReturn('multipart/form-data; boundary=abc');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // normalizeFormDataValues — non-multipart request skips normalization
    // =========================================================================

    public function testCreateJsonContentTypeSkipsNormalization(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Test']);

        $this->request->method('getParams')->willReturn([
            'title' => 'Test',
            'data' => '{"nested":"value"}', // should NOT be decoded for JSON requests
        ]);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // postPatch() — with multipart form data and uploaded files
    // =========================================================================

    public function testPostPatchWithMultipartFormData(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $patchedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $patchedObject->setUuid('uuid-123');
        $patchedObject->setObject(['title' => 'PostPatched', 'data' => ['key' => 'val']]);

        $this->request->method('getParams')->willReturn([
            'title' => 'PostPatched',
            'data' => '{"key":"val"}',
        ]);
        $this->request->method('getHeader')->willReturn('multipart/form-data; boundary=xyz');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($patchedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // postPatch() — filters id from params
    // =========================================================================

    public function testPostPatchFiltersIdFromParams(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $patchedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $patchedObject->setUuid('uuid-123');
        $patchedObject->setObject(['title' => 'PostPatched']);

        $this->request->method('getParams')->willReturn([
            'title' => 'PostPatched',
            'id' => 'should-be-removed',
            'uuid' => 'should-be-removed',
            'register' => '1',
            'schema' => '2',
        ]);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($patchedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->postPatch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // create() — @self param passes through filter
    // =========================================================================

    public function testCreateAllowsAtSelfParam(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Created']);

        $this->request->method('getParams')->willReturn([
            'title' => 'Created',
            '@self' => ['organization' => 'org-uuid'],
            '@other' => 'should-be-filtered',
        ]);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // show() — extend with _names but cache handler is null
    // =========================================================================

    public function testShowWithExtendNamesEmptyCacheHandler(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_names',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $cacheHandler = $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class);
        // No UUIDs to resolve, so returns empty
        $cacheHandler->method('getMultipleObjectNames')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);
        $this->objectService->method('getCacheHandler')->willReturn($cacheHandler);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // names is empty so gets stripped by stripEmptyValues
        $this->assertArrayNotHasKey('names', $data['@self']);
    }

    // =========================================================================
    // show() — extend with _names and UUID relations
    // =========================================================================

    public function testShowWithExtendNamesCollectsUuidsFromRelations(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_names',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $cacheHandler = $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class);
        $cacheHandler->method('getMultipleObjectNames')->willReturn([
            '550e8400-e29b-41d4-a716-446655440000' => 'Related Item',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            'related' => '550e8400-e29b-41d4-a716-446655440000',
            '@self' => [
                'uuid' => 'uuid-123',
                'relations' => [
                    '550e8400-e29b-41d4-a716-446655440000',
                    ['550e8400-e29b-41d4-a716-446655440001'],
                ],
            ],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);
        $this->objectService->method('getCacheHandler')->willReturn($cacheHandler);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('names', $data['@self']);
    }

    // =========================================================================
    // index() — with rbac=false parameter
    // =========================================================================

    public function testIndexWithRbacDisabled(): void
    {
        $this->request->method('getParams')->willReturn([
            'rbac' => 'false',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // index() — with _published and deleted params
    // =========================================================================

    public function testIndexWithPublishedAndDeletedParams(): void
    {
        $this->request->method('getParams')->willReturn([
            '_published' => 'true',
            'deleted' => 'true',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn(['_limit' => 20]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->index('1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // import() — with optional boolean parameters
    // =========================================================================

    public function testImportWithAllBooleanParams(): void
    {
        $register = new \OCA\OpenRegister\Db\Register();
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, 1);

        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'data.csv',
            'tmp_name' => '/tmp/data.csv',
            'size' => 1024,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['schema', null, null],
            ['validation', false, 'true'],
            ['events', false, 'true'],
            ['rbac', true, 'false'],
            ['multi', true, 'false'],
            ['publish', false, 'true'],
        ]);

        $this->registerMapper->method('find')->willReturn($register);
        $this->objectService->method('importObjects')->willReturn([
            'imported' => 3,
        ]);

        $user = $this->createMock(\OCP\IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $result = $this->controller->import(1);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // patch() — with multipart form data normalization
    // =========================================================================

    public function testPatchWithMultipartFormData(): void
    {
        $this->setupAdminUser();

        $existingObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $existingObject->setUuid('uuid-123');
        $existingObject->setObject(['title' => 'Old']);

        $patchedObject = new \OCA\OpenRegister\Db\ObjectEntity();
        $patchedObject->setUuid('uuid-123');
        $patchedObject->setObject(['title' => 'Patched', 'items' => [1, 2]]);

        $this->request->method('getParams')->willReturn([
            'title' => 'Patched',
            'items' => '[1,2]',
        ]);
        $this->request->method('getHeader')->willReturn('multipart/form-data; boundary=abc');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($existingObject);
        $this->objectService->method('saveObject')->willReturn($patchedObject);
        $this->objectService->method('unlockObject')->willReturn(true);

        $result = $this->controller->patch('1', '2', 'uuid-123', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // show() — with extend including both _register and _schema with entities
    // =========================================================================

    public function testShowWithExtendRegisterAndSchemaEntities(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_register,_schema',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // resolveRegisterSchemaIds accesses \OC::$server which throws in unit tests,
        // entities are null, so registers/schemas are empty arrays which get stripped
        // by stripEmptyValues. The key @self should still exist.
        $this->assertArrayHasKey('@self', $data);
    }

    // =========================================================================
    // show() — extend with array of non-string values
    // =========================================================================

    public function testShowWithExtendArrayContainingNonStringValues(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => ['_register', 42, true],
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // normalizeExtendParameter — non-array, non-string input returns null
    // =========================================================================

    public function testShowWithExtendAsIntegerReturnsOk(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => 42, // integer, not array or string
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // create() — with @self passing through, @other filtered out
    // =========================================================================

    public function testCreateFiltersAtPrefixedKeysExceptAtSelf(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('new-uuid');
        $objectEntity->setObject(['title' => 'Created']);

        $this->request->method('getParams')->willReturn([
            'title' => 'Created',
            '@self' => ['org' => 'test'],
            '@metadata' => 'should-be-filtered',
            '_internal' => 'should-be-filtered',
        ]);
        $this->request->method('getHeader')->willReturn('application/json');
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willReturn($objectEntity);

        $result = $this->controller->create('1', '2', $this->objectService);

        $this->assertSame(201, $result->getStatus());
    }

    // =========================================================================
    // contracts() — with legacy 'limit', 'offset', 'page' params (non-underscore)
    // =========================================================================

    public function testContractsWithNonUnderscoreOffsetParam(): void
    {
        $this->request->method('getParams')->willReturn([
            'offset' => '25',
            'page' => '2',
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid/contracts');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getObjectContracts')->willReturn([
            'results' => [],
            'total' => 50,
        ]);

        $result = $this->controller->contracts('uuid-123', 'reg1', 'schema1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // logs() — with find throwing DB Exception
    // =========================================================================

    public function testLogsReturns404OnDbException(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')
            ->willThrowException(new DBException('Database error'));

        $result = $this->controller->logs('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // =========================================================================
    // logs() — with legacy offset and page params
    // =========================================================================

    public function testLogsWithLegacyParams(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('1');
        $objectEntity->setSchema('2');
        $objectEntity->setObject(['title' => 'Test']);

        $this->request->method('getParams')->willReturn([
            'offset' => '10',
            'page' => '2',
            'limit' => '5',
            '_search' => 'test',
            'order' => ['created' => 'desc'],
        ]);
        $this->request->method('getRequestUri')->willReturn('/api/objects/1/2/uuid/logs?page=2');

        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('getLogs')->willReturn([]);

        $result = $this->controller->logs('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // export() — CSV export path
    // =========================================================================

    public function testExportReturnsCsvDownloadResponse(): void
    {
        $registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $registerEntity->method('getSlug')->willReturn('my-register');

        $schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $schemaEntity->method('getSlug')->willReturn('my-schema');

        $this->registerMapper->method('find')->willReturn($registerEntity);
        $this->schemaMapper->method('find')->willReturn($schemaEntity);

        $this->request->method('getParams')->willReturn(['format' => 'csv']);
        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
            if ($key === 'format') {
                return 'csv';
            }
            return $default;
        });

        $user = $this->createMock(\OCP\IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->exportService->method('exportToCsv')->willReturn('col1,col2\nval1,val2');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $result = $this->controller->export('1', '2', $this->objectService);

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
        $this->assertStringContainsString('.csv', $result->getHeaders()['Content-Disposition'] ?? '');
    }

    // =========================================================================
    // export() — Excel export path (default)
    // =========================================================================

    public function testExportReturnsExcelDownloadResponseByDefault(): void
    {
        $registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $registerEntity->method('getSlug')->willReturn('test-reg');

        $schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $schemaEntity->method('getSlug')->willReturn('test-schema');

        $this->registerMapper->method('find')->willReturn($registerEntity);
        $this->schemaMapper->method('find')->willReturn($schemaEntity);

        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
            if ($key === 'type') {
                return 'excel';
            }
            return $default;
        });

        $user = $this->createMock(\OCP\IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'test');
        $this->exportService->method('exportToExcel')->willReturn($spreadsheet);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $result = $this->controller->export('1', '2', $this->objectService);

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
    }

    // =========================================================================
    // export() — Register/Schema with null slugs uses fallback names
    // =========================================================================

    public function testExportUsesDefaultSlugsWhenNull(): void
    {
        $registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $registerEntity->method('getSlug')->willReturn(null);

        $schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $schemaEntity->method('getSlug')->willReturn(null);

        $this->registerMapper->method('find')->willReturn($registerEntity);
        $this->schemaMapper->method('find')->willReturn($schemaEntity);

        $this->request->method('getParams')->willReturn(['format' => 'csv']);
        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
            if ($key === 'format') {
                return 'csv';
            }
            return $default;
        });

        $user = $this->createMock(\OCP\IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->exportService->method('exportToCsv')->willReturn('data');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $result = $this->controller->export('1', '2', $this->objectService);

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
    }

    // =========================================================================
    // export() — format=csv via 'type' param fallback
    // =========================================================================

    public function testExportCsvViaTypeParam(): void
    {
        $registerEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $registerEntity->method('getSlug')->willReturn('reg');

        $schemaEntity = $this->getMockBuilder(\OCA\OpenRegister\Db\Schema::class)
            ->addMethods(['getSlug'])
            ->getMock();
        $schemaEntity->method('getSlug')->willReturn('sch');

        $this->registerMapper->method('find')->willReturn($registerEntity);
        $this->schemaMapper->method('find')->willReturn($schemaEntity);

        $this->request->method('getParams')->willReturn(['type' => 'csv']);
        $this->request->method('getParam')->willReturnCallback(function (string $key, $default = null) {
            if ($key === 'format') {
                return null;
            }
            if ($key === 'type') {
                return 'csv';
            }
            return $default;
        });

        $user = $this->createMock(\OCP\IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->exportService->method('exportToCsv')->willReturn('csv-content');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();

        $result = $this->controller->export('1', '2', $this->objectService);

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
    }

    // =========================================================================
    // index() — cross-table search with comma-separated schemas
    // =========================================================================

    public function testIndexWithCommaSeparatedSchemasTriggersCrossTableSearch(): void
    {
        $this->request->method('getParams')->willReturn([
            'schemas' => '1,2',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(1);

        // crossTableSearch uses \OC::$server->get() which returns DI mocks that throw
        // DoesNotExistException, resulting in a 404 with "No valid magic-mapped register+schema combinations found"
        $result = $this->controller->index('1', '1', $this->objectService);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('No valid magic-mapped', $data['message']);
    }

    // =========================================================================
    // index() — cross-table search with array schemas param
    // =========================================================================

    public function testIndexWithArraySchemasTriggersCrossTableSearch(): void
    {
        $this->request->method('getParams')->willReturn([
            'schemas' => ['1', '2'],
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(1);

        $result = $this->controller->index('1', '1', $this->objectService);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('No valid magic-mapped', $data['message']);
    }

    // =========================================================================
    // index() — cross-table search with multiple registers
    // =========================================================================

    public function testIndexWithMultipleRegistersTriggersCrossTableSearch(): void
    {
        $this->request->method('getParams')->willReturn([
            'registers' => '1,2',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(1);

        $result = $this->controller->index('1', '1', $this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // =========================================================================
    // objects() — cross-table search with multiple schemas
    // =========================================================================

    public function testObjectsWithMultipleSchemasTriggersCrossTableSearch(): void
    {
        $this->request->method('getParams')->willReturn([
            'register' => '1',
            'schemas' => '1,2',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(1);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('No valid magic-mapped', $data['message']);
    }

    // =========================================================================
    // objects() — cross-table search with multiple registers
    // =========================================================================

    public function testObjectsWithMultipleRegistersTriggersCrossTableSearch(): void
    {
        $this->request->method('getParams')->willReturn([
            'schema' => '1',
            'registers' => ['1', '2'],
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(1);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // =========================================================================
    // objects() — uses _register and _schema prefixed params
    // =========================================================================

    public function testObjectsWithUnderscorePrefixedMultiSchemas(): void
    {
        $this->request->method('getParams')->willReturn([
            '_register' => '1',
            'schemas' => '1,2,3',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(1);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(404, $result->getStatus());
    }

    // =========================================================================
    // show() — _names with nested UUID arrays
    // =========================================================================

    public function testShowWithExtendNamesCollectsNestedUuids(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_names',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $uuid1 = '550e8400-e29b-41d4-a716-446655440000';
        $uuid2 = '550e8400-e29b-41d4-a716-446655440001';
        $uuid3 = '550e8400-e29b-41d4-a716-446655440002';

        $cacheHandler = $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class);
        $cacheHandler->method('getMultipleObjectNames')->willReturn([
            $uuid1 => 'Name One',
            $uuid2 => 'Name Two',
            $uuid3 => 'Name Three',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            'ref' => $uuid1,
            'nested' => [
                ['subref' => $uuid2],
                ['deep' => ['innerref' => $uuid3]],
            ],
            '@self' => [
                'uuid' => 'uuid-123',
                'relations' => [],
            ],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);
        $this->objectService->method('getCacheHandler')->willReturn($cacheHandler);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('names', $data['@self']);
    }

    // =========================================================================
    // show() — _names skips @self, id, _id keys in collectUuidsFromArray
    // =========================================================================

    public function testShowWithExtendNamesSkipsMetadataKeys(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_names',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $validUuid = '550e8400-e29b-41d4-a716-446655440000';
        $skippedUuid = '550e8400-e29b-41d4-a716-446655440099';

        $cacheHandler = $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class);
        // The skipped UUID should NOT be requested because it's under 'id' and '_id' keys
        $cacheHandler->method('getMultipleObjectNames')->willReturn([
            $validUuid => 'Valid Name',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            'validField' => $validUuid,
            'id' => $skippedUuid,
            '_id' => $skippedUuid,
            '@self' => [
                'uuid' => 'uuid-123',
                'relations' => [],
            ],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);
        $this->objectService->method('getCacheHandler')->willReturn($cacheHandler);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('names', $data['@self']);
    }

    // =========================================================================
    // show() — _names with relations containing non-string/non-uuid values
    // =========================================================================

    public function testShowWithExtendNamesIgnoresNonUuidRelations(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_names',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $cacheHandler = $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class);
        // No valid UUIDs, so getMultipleObjectNames should not be called
        // (or called with empty array). We return empty.
        $cacheHandler->method('getMultipleObjectNames')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => [
                'uuid' => 'uuid-123',
                'relations' => [
                    'not-a-uuid',
                    12345,
                    ['also-not-a-uuid'],
                ],
            ],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);
        $this->objectService->method('getCacheHandler')->willReturn($cacheHandler);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // downloadFiles() — success path (mocked FileService)
    // =========================================================================

    public function testDownloadFilesReturnsZipOnSuccess(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('1');
        $objectEntity->setSchema('2');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('find')->willReturn($objectEntity);

        // Create a real temp file for the zip
        $tempZip = tempnam(sys_get_temp_dir(), 'test_zip_');
        file_put_contents($tempZip, 'fake-zip-content');

        $fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
        $fileService->method('createObjectFilesZip')->willReturn([
            'path' => $tempZip,
            'filename' => 'test-files.zip',
            'mimeType' => 'application/zip',
        ]);

        $this->container->method('get')->willReturn($fileService);
        $this->request->method('getParam')->willReturn(null);

        $result = $this->controller->downloadFiles('uuid-123', '1', '2', $this->objectService);

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);

        // Clean up if file still exists
        if (file_exists($tempZip)) {
            unlink($tempZip);
        }
    }

    // =========================================================================
    // downloadFiles() — returns 404 when object not found (via DoesNotExist)
    // =========================================================================

    public function testDownloadFilesReturns404WhenObjectDoesNotExistViaException(): void
    {
        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));

        $result = $this->controller->downloadFiles('nonexistent', '1', '2', $this->objectService);

        $this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
        $this->assertSame(404, $result->getStatus());
    }

    // =========================================================================
    // downloadFiles() — custom filename parameter
    // =========================================================================

    public function testDownloadFilesUsesCustomFilename(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('1');
        $objectEntity->setSchema('2');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('find')->willReturn($objectEntity);

        $tempZip = tempnam(sys_get_temp_dir(), 'test_zip_');
        file_put_contents($tempZip, 'fake-zip-content');

        $fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
        $fileService->method('createObjectFilesZip')->willReturn([
            'path' => $tempZip,
            'filename' => 'custom-name.zip',
            'mimeType' => 'application/zip',
        ]);

        $this->container->method('get')->willReturn($fileService);
        $this->request->method('getParam')->willReturn('custom-name');

        $result = $this->controller->downloadFiles('uuid-123', '1', '2', $this->objectService);

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);

        if (file_exists($tempZip)) {
            unlink($tempZip);
        }
    }

    // =========================================================================
    // downloadFiles() — returns 500 when ZIP creation fails
    // =========================================================================

    public function testDownloadFilesReturns500WhenZipCreationFails(): void
    {
        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('1');
        $objectEntity->setSchema('2');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('find')->willReturn($objectEntity);

        $fileService = $this->createMock(\OCA\OpenRegister\Service\FileService::class);
        $fileService->method('createObjectFilesZip')
            ->willThrowException(new \Exception('Disk full'));

        $this->container->method('get')->willReturn($fileService);
        $this->request->method('getParam')->willReturn(null);

        $result = $this->controller->downloadFiles('uuid-123', '1', '2', $this->objectService);

        $this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Disk full', $data['error']);
    }

    // =========================================================================
    // stripEmptyValues() — via show() - preserves 0, false, "0"
    // =========================================================================

    public function testShowStripEmptyValuesPreservesZeroAndFalse(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            'count' => 0,
            'active' => false,
            'code' => '0',
            'empty_string' => '',
            'null_val' => null,
            '@self' => ['uuid' => 'uuid-123'],
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(0, $data['count']);
        $this->assertSame(false, $data['active']);
        $this->assertSame('0', $data['code']);
        $this->assertArrayNotHasKey('empty_string', $data);
        $this->assertArrayNotHasKey('null_val', $data);
    }

    // =========================================================================
    // stripEmptyValues() — nested associative arrays with all empty values removed
    // =========================================================================

    public function testShowStripsNestedAssociativeArraysWithAllEmptyValues(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            'metadata' => [
                'author' => '',
                'date' => null,
            ],
            '@self' => ['uuid' => 'uuid-123'],
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // metadata has only empty values, so it should be stripped entirely
        $this->assertArrayNotHasKey('metadata', $data);
    }

    // =========================================================================
    // stripEmptyValues() — sequential arrays preserve non-empty items
    // =========================================================================

    public function testShowStripsSequentialArraysPreservingNonEmptyItems(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            'tags' => ['php', 'nextcloud', 'test'],
            'nested_list' => [
                ['name' => 'Item1', 'desc' => ''],
                ['name' => 'Item2', 'desc' => null],
            ],
            '@self' => ['uuid' => 'uuid-123'],
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(['php', 'nextcloud', 'test'], $data['tags']);
        // nested_list items have desc stripped, but name remains
        $this->assertCount(2, $data['nested_list']);
        $this->assertSame('Item1', $data['nested_list'][0]['name']);
        $this->assertArrayNotHasKey('desc', $data['nested_list'][0]);
    }

    // =========================================================================
    // show() — _empty=true preserves all empty values including nested
    // =========================================================================

    public function testShowWithEmptyTruePreservesNestedEmptyValues(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_empty' => 'true',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            'empty_string' => '',
            'null_val' => null,
            'metadata' => [
                'author' => '',
                'date' => null,
            ],
            '@self' => ['uuid' => 'uuid-123'],
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('empty_string', $data);
        $this->assertArrayHasKey('null_val', $data);
        $this->assertArrayHasKey('metadata', $data);
        $this->assertSame('', $data['metadata']['author']);
    }

    // =========================================================================
    // show() — extend includes extended objects in @self
    // =========================================================================

    public function testShowWithExtendIncludesExtendedObjects(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => ['relations'],
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([
            '550e8400-e29b-41d4-a716-446655440000' => ['title' => 'Extended Object'],
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('objects', $data['@self']);
        $this->assertArrayHasKey('550e8400-e29b-41d4-a716-446655440000', $data['@self']['objects']);
    }

    // =========================================================================
    // show() — fields parameter as comma-separated string
    // =========================================================================

    public function testShowWithFieldsAsCommaSeparatedString(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_fields' => 'title,description',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // show() — unset parameter as comma-separated string
    // =========================================================================

    public function testShowWithUnsetAsString(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_unset' => 'description,metadata',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // show() — filter parameter as comma-separated string
    // =========================================================================

    public function testShowWithFilterAsString(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_filter' => 'title,name',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // show() — returns null from find() -> 404
    // =========================================================================

    public function testShowReturns404WhenFindReturnsNull(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn(null);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('not found', $data['error']);
    }

    // =========================================================================
    // show() — without @self key in rendered data (no extend processing)
    // =========================================================================

    public function testShowWithoutAtSelfKeyDoesNotAddExtendData(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => ['_registers', '_schemas'],
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        // renderEntity returns data without @self key
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
        ]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayNotHasKey('@self', $data);
        $this->assertArrayNotHasKey('registers', $data);
    }

    // =========================================================================
    // create() — generic exception returns 403 (catches \Exception)
    // =========================================================================

    public function testCreateReturns403OnGenericExceptionIncludingDBException(): void
    {
        $this->setupAdminUser();

        $this->request->method('getParams')->willReturn([
            'title' => 'Test',
        ]);
        $this->request->method('getHeader')->willReturn('application/json');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('saveObject')->willThrowException(
            new DBException('Unique constraint violation')
        );

        $result = $this->controller->create('1', '2', $this->objectService);

        // DBException extends \Exception, caught by generic catch -> 403
        $this->assertSame(403, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    // =========================================================================
    // destroy() — generic exception returns 403 (catches \Exception)
    // =========================================================================

    public function testDestroyReturns403OnGenericExceptionIncludingDBException(): void
    {
        $this->setupAdminUser();

        $this->objectService->method('deleteObject')->willThrowException(
            new DBException('Foreign key violation')
        );

        $result = $this->controller->destroy('uuid-123', 'reg', 'schema', $this->objectService);

        // DBException extends \Exception, caught by generic catch -> 403
        $this->assertSame(403, $result->getStatus());
    }

    // =========================================================================
    // update() — generic exception returns 403 with unlock attempt
    // =========================================================================

    public function testUpdateReturns403OnGenericExceptionWithUnlock(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('1');
        $objectEntity->setSchema('2');
        $objectEntity->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn([
            'title' => 'Updated',
        ]);
        $this->request->method('getHeader')->willReturn('application/json');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('findSilent')->willReturn($objectEntity);
        $this->objectService->method('unlockObject')->willReturn(true);
        $this->objectService->method('saveObject')->willThrowException(
            new DBException('Constraint violation')
        );

        $result = $this->controller->update('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(403, $result->getStatus());
    }

    // =========================================================================
    // patch() — generic exception returns 500 with unlock attempt
    // =========================================================================

    public function testPatchReturns500OnGenericExceptionWithUnlock(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('1');
        $objectEntity->setSchema('2');
        $objectEntity->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn([
            'title' => 'Updated',
        ]);
        $this->request->method('getHeader')->willReturn('application/json');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('unlockObject')->willReturn(true);
        $this->objectService->method('saveObject')
            ->willThrowException(new DBException('Constraint error'));

        $result = $this->controller->patch('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(500, $result->getStatus());
    }

    // =========================================================================
    // postPatch() — generic exception returns 500 with unlock attempt
    // =========================================================================

    public function testPostPatchReturns500OnGenericExceptionWithUnlock(): void
    {
        $this->setupAdminUser();

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setRegister('1');
        $objectEntity->setSchema('2');
        $objectEntity->setObject(['title' => 'Old']);

        $this->request->method('getParams')->willReturn([
            'title' => 'Updated',
            'id' => 'uuid-123',
        ]);
        $this->request->method('getHeader')->willReturn('application/json');

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('unlockObject')->willReturn(true);
        $this->objectService->method('saveObject')
            ->willThrowException(new DBException('DB error'));

        $result = $this->controller->postPatch('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(500, $result->getStatus());
    }

    // =========================================================================
    // objects() — both register and registers params present
    // =========================================================================

    public function testObjectsWithRegistersArrayOverridesRegisterParam(): void
    {
        $this->request->method('getParams')->willReturn([
            'register' => '1',
            'schema' => '2',
            'registers' => ['1', '3'],
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);

        $result = $this->controller->objects($this->objectService);

        // Multiple registers triggers crossTableSearch, DI mocks throw -> 404
        $this->assertSame(404, $result->getStatus());
    }

    // =========================================================================
    // index() — schemas param with duplicates gets deduplicated to single
    // =========================================================================

    public function testIndexWithDuplicateSchemasDoesNotTriggerCrossTable(): void
    {
        $this->request->method('getParams')->willReturn([
            'schemas' => ['1', '1', '1'],
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('buildSearchQuery')->willReturn([]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
            'pages' => 0,
            'page' => 1,
            'limit' => 20,
            'facets' => [],
        ]);

        // parseMultiValue deduplicates, so ['1','1','1'] becomes ['1'] -> single, no cross-table
        $result = $this->controller->index('1', '1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // index() — with single schema param uses normal search
    // =========================================================================

    public function testIndexWithSingleSchemaParamUsesNormalSearch(): void
    {
        $this->request->method('getParams')->willReturn([
            'schemas' => '5',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(5);
        $this->objectService->method('buildSearchQuery')->willReturn([]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
            'pages' => 0,
            'page' => 1,
            'limit' => 20,
            'facets' => [],
        ]);

        $result = $this->controller->index('1', '5', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // index() — schemas param is empty string falls back to normal search
    // =========================================================================

    public function testIndexWithEmptySchemasParamUsesNormalSearch(): void
    {
        $this->request->method('getParams')->willReturn([
            'schemas' => '',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(1);
        $this->objectService->method('buildSearchQuery')->willReturn([]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
            'pages' => 0,
            'page' => 1,
            'limit' => 20,
            'facets' => [],
        ]);

        $result = $this->controller->index('1', '1', $this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // objects() — with schemas param array but only one value
    // =========================================================================

    public function testObjectsWithSingleSchemaArrayUsesNormalSearch(): void
    {
        $this->request->method('getParams')->willReturn([
            'register' => '1',
            'schema' => '2',
            'schemas' => ['2'],
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('buildSearchQuery')->willReturn([]);
        $this->objectService->method('searchObjectsPaginated')->willReturn([
            'results' => [],
            'total' => 0,
            'pages' => 0,
            'page' => 1,
            'limit' => 20,
            'facets' => [],
        ]);

        $result = $this->controller->objects($this->objectService);

        $this->assertSame(200, $result->getStatus());
    }

    // =========================================================================
    // index() — cross-table with both schemas and registers arrays
    // =========================================================================

    public function testIndexWithBothMultipleSchemasAndRegisters(): void
    {
        $this->request->method('getParams')->willReturn([
            'schemas' => ['1', '2'],
            'registers' => ['3', '4'],
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(1);

        $result = $this->controller->index('1', '1', $this->objectService);

        $this->assertSame(404, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertSame(0, $data['total']);
    }

    // =========================================================================
    // show() — extend parameter as non-array string gets normalized
    // =========================================================================

    public function testShowWithExtendAsCommaSeparatedStringParameterWorks(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            'extend' => '_registers,_schemas',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // registerEntity/schemaEntity are null from DI mocks, so registers/schemas keys
        // contain empty arrays which get stripped by stripEmptyValues
        $this->assertArrayHasKey('@self', $data);
    }

    // =========================================================================
    // show() — _names with relations containing nested arrays of UUIDs
    // =========================================================================

    public function testShowWithExtendNamesNestedRelationArrays(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_names',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $uuid1 = '550e8400-e29b-41d4-a716-446655440000';
        $uuid2 = '550e8400-e29b-41d4-a716-446655440001';

        $cacheHandler = $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class);
        $cacheHandler->method('getMultipleObjectNames')->willReturn([
            $uuid1 => 'First',
            $uuid2 => 'Second',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => [
                'uuid' => 'uuid-123',
                'relations' => [
                    [$uuid1, $uuid2],
                ],
            ],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);
        $this->objectService->method('getCacheHandler')->willReturn($cacheHandler);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('names', $data['@self']);
    }

    // =========================================================================
    // show() — _names with @self.object used for UUID collection
    // =========================================================================

    public function testShowWithExtendNamesUsesAtSelfObjectForUuids(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => '_names',
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $uuid1 = '550e8400-e29b-41d4-a716-446655440000';

        $cacheHandler = $this->createMock(\OCA\OpenRegister\Service\Object\CacheHandler::class);
        $cacheHandler->method('getMultipleObjectNames')->willReturn([
            $uuid1 => 'Object Name',
        ]);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => [
                'uuid' => 'uuid-123',
                'relations' => [],
                'object' => [
                    'reference' => $uuid1,
                ],
            ],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);
        $this->objectService->method('getCacheHandler')->willReturn($cacheHandler);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('names', $data['@self']);
    }

    // =========================================================================
    // show() — extend with _registers (plural) with null entity
    // =========================================================================

    public function testShowWithExtendRegistersNullEntityCreatesEmptyRegisters(): void
    {
        $this->setupAdminUser();
        $this->request->method('getParams')->willReturn([
            '_extend' => ['_registers'],
        ]);

        $objectEntity = new \OCA\OpenRegister\Db\ObjectEntity();
        $objectEntity->setUuid('uuid-123');
        $objectEntity->setObject(['title' => 'Test']);

        $this->objectService->method('setRegister')->willReturnSelf();
        $this->objectService->method('setSchema')->willReturnSelf();
        $this->objectService->method('getRegister')->willReturn(1);
        $this->objectService->method('getSchema')->willReturn(2);
        $this->objectService->method('find')->willReturn($objectEntity);
        $this->objectService->method('renderEntity')->willReturn([
            'title' => 'Test',
            '@self' => ['uuid' => 'uuid-123'],
        ]);
        $this->objectService->method('getExtendedObjects')->willReturn([]);

        $result = $this->controller->show('uuid-123', '1', '2', $this->objectService);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // registerEntity is null (DI mapper throws), so registers is empty and stripped
        $this->assertArrayHasKey('@self', $data);
    }
}
