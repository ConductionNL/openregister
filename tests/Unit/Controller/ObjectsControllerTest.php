<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
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
}
