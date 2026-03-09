<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\UploadService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception as DBException;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RegistersController
 *
 * @package Unit\Controller
 */
class RegistersControllerTest extends TestCase
{
    private RegistersController $controller;
    private IRequest&MockObject $request;
    private RegisterService&MockObject $registerService;
    private ObjectEntityMapper&MockObject $objectEntityMapper;
    private UploadService&MockObject $uploadService;
    private LoggerInterface&MockObject $logger;
    private IUserSession&MockObject $userSession;
    private ConfigurationService&MockObject $configurationService;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private ExportService&MockObject $exportService;
    private ImportService&MockObject $importService;
    private SchemaMapper&MockObject $schemaMapper;
    private RegisterMapper&MockObject $registerMapper;
    private GitHubHandler&MockObject $githubService;
    private IAppManager&MockObject $appManager;
    private OasService&MockObject $oasService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->registerService = $this->createMock(RegisterService::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->uploadService = $this->createMock(UploadService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->exportService = $this->createMock(ExportService::class);
        $this->importService = $this->createMock(ImportService::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->githubService = $this->createMock(GitHubHandler::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->oasService = $this->createMock(OasService::class);

        $this->controller = new RegistersController(
            'openregister',
            $this->request,
            $this->registerService,
            $this->objectEntityMapper,
            $this->uploadService,
            $this->logger,
            $this->userSession,
            $this->configurationService,
            $this->auditTrailMapper,
            $this->exportService,
            $this->importService,
            $this->schemaMapper,
            $this->registerMapper,
            $this->githubService,
            $this->appManager,
            $this->oasService
        );
    }

    public function testIndexReturnsRegisters(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

        $this->request->method('getParams')->willReturn([]);
        $this->registerService->method('findAll')->willReturn([$register]);

        $result = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexWithPagination(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_page' => '2',
        ]);
        $this->registerService->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
    }

    public function testShowReturnsRegister(): void
    {
        $register = $this->createMock(Register::class);
        $register->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

        $this->request->method('getParam')->willReturn([]);
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
    }

    private function createRealRegister(int $id = 1, string $title = 'Test'): Register
    {
        $register = new Register();
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        $register->setTitle($title);
        return $register;
    }

    public function testCreateReturnsCreatedRegister(): void
    {
        $register = $this->createRealRegister(1, 'New Register');

        $this->request->method('getParams')->willReturn(['title' => 'New Register']);
        $this->registerService->method('createFromArray')->willReturn($register);

        $result = $this->controller->create();

        $this->assertSame(201, $result->getStatus());
    }

    public function testCreateRemovesInternalParamsAndId(): void
    {
        $register = $this->createRealRegister(1, 'Test');

        $this->request->method('getParams')->willReturn([
            '_route' => 'test',
            'id' => 5,
            'title' => 'Test',
        ]);
        $this->registerService->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return !isset($data['_route']) && !isset($data['id']) && isset($data['title']);
            }))
            ->willReturn($register);

        $this->controller->create();
    }

    public function testCreateReturns500OnGenericException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->registerService->method('createFromArray')
            ->willThrowException(new Exception('Create failed'));

        $result = $this->controller->create();

        $this->assertSame(500, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateReturnsUpdatedRegister(): void
    {
        $register = $this->createRealRegister(1, 'Updated');

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->registerService->method('updateFromArray')->willReturn($register);

        $result = $this->controller->update(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdateRemovesImmutableFields(): void
    {
        $register = $this->createRealRegister(1, 'Updated');

        $this->request->method('getParams')->willReturn([
            'id' => 1,
            'organisation' => 'org1',
            'owner' => 'user1',
            'created' => '2024-01-01',
            'title' => 'Updated',
        ]);
        $this->registerService->expects($this->once())
            ->method('updateFromArray')
            ->with(
                $this->equalTo(1),
                $this->callback(function ($data) {
                    return !isset($data['id'])
                        && !isset($data['organisation'])
                        && !isset($data['owner'])
                        && !isset($data['created'])
                        && isset($data['title']);
                })
            )
            ->willReturn($register);

        $this->controller->update(1);
    }

    public function testPatchDelegatesToUpdate(): void
    {
        $register = $this->createRealRegister(1, 'Patched');

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->registerService->method('updateFromArray')->willReturn($register);

        $result = $this->controller->patch(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testDestroyReturnsEmptyOnSuccess(): void
    {
        $register = $this->createMock(Register::class);
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->destroy(1);

        $this->assertSame(200, $result->getStatus());
        $this->assertSame([], $result->getData());
    }

    public function testDestroyReturns404WhenNotFound(): void
    {
        $this->registerService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertSame(404, $result->getStatus());
    }

    public function testDestroyReturns409OnValidationException(): void
    {
        $register = $this->createMock(Register::class);
        $this->registerService->method('find')->willReturn($register);
        $this->registerService->method('delete')
            ->willThrowException(new \OCA\OpenRegister\Exception\ValidationException('Objects attached'));

        $result = $this->controller->destroy(1);

        $this->assertSame(409, $result->getStatus());
    }

    public function testDestroyReturns500OnGenericException(): void
    {
        $register = $this->createMock(Register::class);
        $this->registerService->method('find')->willReturn($register);
        $this->registerService->method('delete')
            ->willThrowException(new Exception('Delete error'));

        $result = $this->controller->destroy(1);

        $this->assertSame(500, $result->getStatus());
    }

    public function testSchemasReturnsSchemasList(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')->willReturn($register);

        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Schema']);

        $this->registerMapper->method('getSchemasByRegisterId')->willReturn([$schema]);

        $result = $this->controller->schemas(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data['results']);
        $this->assertSame(1, $data['total']);
    }

    public function testSchemasReturns404WhenRegisterNotFound(): void
    {
        $this->registerService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->schemas(999);

        $this->assertSame(404, $result->getStatus());
    }

    public function testSchemasReturns500OnGenericException(): void
    {
        $this->registerService->method('find')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->schemas(1);

        $this->assertSame(500, $result->getStatus());
    }

    public function testObjectsReturnsSearchResults(): void
    {
        $this->objectEntityMapper->method('searchObjects')->willReturn([
            'results' => [],
            'total' => 0,
        ]);

        $result = $this->controller->objects(1, 1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdateReturnsErrorOnDBException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->registerService->method('updateFromArray')
            ->willThrowException(new DBException('Constraint violation'));

        $result = $this->controller->update(1);

        // DBException is caught and wrapped with DatabaseConstraintException
        $this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $result);
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testShowThrowsWhenNotFound(): void
    {
        // show() has no try/catch, so DoesNotExistException propagates
        $this->request->method('getParam')->willReturn([]);
        $this->registerService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(DoesNotExistException::class);
        $this->controller->show(999);
    }

    public function testExportReturns400OnException(): void
    {
        $this->request->method('getParam')->willReturn('configuration');
        $this->registerService->method('find')
            ->willThrowException(new Exception('Export error'));

        $result = $this->controller->export(1);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());
    }

    public function testImportReturns400WhenNoFile(): void
    {
        $this->request->method('getUploadedFile')->willReturn(null);

        $result = $this->controller->import(1);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame('No file uploaded', $result->getData()['error']);
    }

    public function testPublishToGitHubReturns400WhenMissingParams(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->registerMapper->method('find')->willReturn($register);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->publishToGitHub(1);

        $this->assertSame(400, $result->getStatus());
        $this->assertStringContainsString('Owner and repo', $result->getData()['error']);
    }

    public function testPublishToGitHubReturns404WhenRegisterNotFound(): void
    {
        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->publishToGitHub(999);

        $this->assertSame(404, $result->getStatus());
    }

    public function testStatsReturnsRegisterStatistics(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->stats(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('register', $data);
    }

    public function testStatsReturns404WhenNotFound(): void
    {
        $this->registerService->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->stats(999);

        $this->assertSame(404, $result->getStatus());
    }

    public function testStatsReturns500OnGenericException(): void
    {
        $this->registerService->method('find')
            ->willThrowException(new Exception('Error'));

        $result = $this->controller->stats(1);

        $this->assertSame(500, $result->getStatus());
    }
}
