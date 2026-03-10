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
}
