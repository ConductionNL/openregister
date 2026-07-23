<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\MigrationPackService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Registers\RegisterCacheHandler;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Service\UploadService;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception as DBException;
use OCP\IRequest;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RegistersController
 *
 * @package Unit\Controller
 */
use Psr\Container\ContainerInterface;

class RegistersControllerTest extends TestCase
{
    private RegistersController $controller;
    private IRequest&MockObject $request;
    private RegisterService&MockObject $registerService;
    private MagicMapper&MockObject $objectMapper;
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
    private IGroupManager&MockObject $groupManager;
    private \Psr\Container\ContainerInterface&MockObject $container;

    /**
     * The user the mocked session resolves to. Defaults to an admin so write
     * and authenticated-read tests pass; tests exercising the @PublicPage
     * read-visibility guard set this to null to simulate an anonymous caller.
     */
    private ?\OCP\IUser $currentUser = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->registerService = $this->createMock(RegisterService::class);
        $this->objectMapper = $this->createMock(MagicMapper::class);
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
        $this->groupManager = $this->createMock(IGroupManager::class);

        // Default to an authenticated admin so write tests and authenticated-read
        // tests behave as before. Anonymous-read tests set $this->currentUser = null.
        $this->currentUser = $this->createMock(\OCP\IUser::class);
        $this->currentUser->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturnCallback(fn() => $this->currentUser);
        $this->groupManager->method('isAdmin')->willReturnCallback(fn() => $this->currentUser !== null);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);

        // checkRegisterManagePermission() resolves IGroupManager via the container
        // on its no-authorization (admin-only) branch — return the stubbed one.
        $this->container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $this->container->method('get')->willReturnCallback(function ($id) {
            if ($id === \OCP\IGroupManager::class) {
                return $this->groupManager;
            }
            return null;
        });

        $this->controller = new RegistersController(
            'openregister',
            $this->request,
            $this->registerService,
            $this->objectMapper,
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
            $this->oasService,
            $this->container,
            $this->groupManager,
            $this->createMock(RegisterCacheHandler::class),
            new \OCA\OpenRegister\Service\Serializer\RegisterSerializer($this->schemaMapper, $this->logger),
            $this->createMock(MigrationPackService::class)
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

        // Authenticated caller (setUp default) — the read-visibility guard does not apply.
        $this->request->method('getParam')->willReturn([]);
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testShowAnonymousUnpublishedRegisterReturns401(): void
    {
        // Anonymous (no user) + register without public read → 401 (RBAC visibility guard).
        // Use a real Register entity so getAuthorization() (a __call magic) works without mocking.
        $this->currentUser = null;
        $register = $this->createRealRegister(1, 'Private');
        // No authorization set → getAuthorization() returns null → isPublicReadable = false.

        $this->request->method('getParam')->willReturn([]);
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->show(1);

        $this->assertSame(401, $result->getStatus());
    }

    public function testShowAnonymousPublishedRegisterReturns200(): void
    {
        // Anonymous + register with public read authorization → 200.
        // Use a real Register entity so getAuthorization() (a __call magic) works without mocking.
        $this->currentUser = null;
        $register = $this->createRealRegister(1, 'Public');
        $register->setAuthorization(['read' => ['public']]);

        $this->request->method('getParam')->willReturn([]);
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexAnonymousFiltersUnpublishedRegisters(): void
    {
        // Anonymous list returns only registers whose authorization grants public read.
        // Use real Register entities so getAuthorization() (a __call magic) works without mocking.
        $this->currentUser = null;
        $published = $this->createRealRegister(1, 'Published');
        $published->setAuthorization(['read' => ['public']]);

        $unpublished = $this->createRealRegister(2, 'Unpublished');
        // No authorization set → getAuthorization() returns null → isPublicReadable = false.

        $this->request->method('getParams')->willReturn([]);
        $this->registerService->method('findAll')->willReturn([$published, $unpublished]);

        $result = $this->controller->index();
        $data   = $result->getData();

        $this->assertSame(200, $result->getStatus());
        $ids = array_map(fn($r) => $r['id'], $data['results']);
        $this->assertContains(1, $ids);
        $this->assertNotContains(2, $ids);
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

    /**
     * Set schemas on a Register via reflection (setSchemas uses named args which break Entity::setter)
     */
    private function setRegisterSchemas(Register $register, array $schemaIds): void
    {
        $ref = new \ReflectionClass($register);
        $prop = $ref->getProperty('schemas');
        $prop->setAccessible(true);
        $prop->setValue($register, $schemaIds);
    }

    /**
     * Create a real Schema entity (Entity uses __call magic, so mocking getId/getSlug fails)
     */
    private function createRealSchema(int $id, string $title = 'Schema'): \OCA\OpenRegister\Db\Schema
    {
        $schema = new \OCA\OpenRegister\Db\Schema();
        $ref = new \ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        $schema->setTitle($title);
        return $schema;
    }

    /**
     * Create a real ObjectEntity (Entity uses __call magic, so mocking getId/getUuid fails)
     */
    private function createRealObjectEntity(int $id, string $uuid = 'uuid-123'): \OCA\OpenRegister\Db\ObjectEntity
    {
        $obj = new \OCA\OpenRegister\Db\ObjectEntity();
        $ref = new \ReflectionClass($obj);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($obj, $id);
        $obj->setUuid($uuid);
        return $obj;
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
        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')->willReturn($register);
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 0]);

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
        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')->willReturn($register);
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 0]);
        $this->registerService->method('delete')
            ->willThrowException(new \OCA\OpenRegister\Exception\ValidationException('Objects attached'));

        $result = $this->controller->destroy(1);

        $this->assertSame(409, $result->getStatus());
    }

    public function testDestroyReturns500OnGenericException(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')->willReturn($register);
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 0]);
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
        $this->objectMapper->method('searchObjects')->willReturn([
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

    public function testIndexThrowsOnException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->registerService->method('findAll')
            ->willThrowException(new Exception('DB error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('DB error');
        $this->controller->index();
    }

    public function testUpdateThrowsOnGenericException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->registerService->method('updateFromArray')
            ->willThrowException(new Exception('Update error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Update error');
        $this->controller->update(1);
    }

    public function testCreateReturns409OnDBException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Dup']);
        $this->registerService->method('createFromArray')
            ->willThrowException(new DBException('Duplicate entry'));

        $result = $this->controller->create();

        // DBException is caught and returns error response
        $this->assertInstanceOf(JSONResponse::class, $result);
        $data = $result->getData();
        $this->assertArrayHasKey('error', $data);
    }

    public function testObjectsThrowsOnException(): void
    {
        $this->objectMapper->method('searchObjects')
            ->willThrowException(new Exception('Search error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Search error');
        $this->controller->objects(1, 1);
    }

    public function testPublishToGitHubReturns500OnException(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->registerMapper->method('find')->willReturn($register);
        $this->request->method('getParams')->willReturn([
            'owner' => 'org',
            'repo' => 'repo',
        ]);
        $this->oasService->method('createOas')
            ->willThrowException(new Exception('Push failed'));

        $result = $this->controller->publishToGitHub(1);

        $this->assertSame(500, $result->getStatus());
    }

    public function testImportReturns400OnException(): void
    {
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'import.csv',
            'tmp_name' => '/tmp/import.csv',
            'size' => 1024,
        ]);
        $this->registerService->method('find')
            ->willThrowException(new Exception('Import error'));

        $result = $this->controller->import(1);

        $this->assertSame(400, $result->getStatus());
    }

    // NOTE: publish()/depublish() endpoint tests removed — the
    // RegistersController publish/depublish endpoints were retired for
    // security (commit 29b1de3af, H1). publishToGitHub() (tested below)
    // is a separate, still-supported method.

    public function testPatchThrowsWhenNotFound(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->registerService->method('updateFromArray')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(DoesNotExistException::class);
        $this->controller->patch(999);
    }

    // ── Export format tests ────────────────────────────────────────────

    public function testExportConfigurationFormatSuccess(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('test-register');
        $this->request->method('getParam')->willReturnMap([
            ['format', 'configuration', 'configuration'],
            ['includeObjects', false, false],
            ['schema', null, null],
        ]);
        $this->registerService->method('find')->willReturn($register);
        $this->configurationService->method('exportConfig')->willReturn([
            'info' => ['title' => 'Test'],
        ]);

        $result = $this->controller->export(1);

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
    }

    public function testExportCsvMissingSchemaReturns400(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->request->method('getParam')->willReturnMap([
            ['format', 'configuration', 'csv'],
            ['includeObjects', false, false],
            ['schema', null, null],
        ]);
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->export(1);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());
        $this->assertStringContainsString('schema', $result->getData()['error']);
    }

    // NOTE: publish()/depublish() success tests removed — endpoints retired
    // for security (commit 29b1de3af, H1).

    // ── PublishToGitHub success test ───────────────────────────────────

    public function testPublishToGitHubSuccess(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('test-register');
        $this->registerMapper->method('find')->willReturn($register);
        $this->request->method('getParams')->willReturn([
            'owner' => 'testorg',
            'repo' => 'testrepo',
            'path' => 'api/test.yaml',
            'branch' => 'main',
        ]);
        $this->oasService->method('createOas')->willReturn([
            'openapi' => '3.0.0',
            'info' => ['title' => 'Test'],
        ]);
        $this->githubService->method('getFileSha')->willReturn('abc123');
        $this->githubService->method('publishConfiguration')->willReturn([
            'commit_sha' => 'sha456',
            'commit_url' => 'https://github.com/testorg/testrepo/commit/sha456',
            'file_url' => 'https://github.com/testorg/testrepo/blob/main/api/test.yaml',
        ]);
        $this->githubService->method('getRepositoryInfo')->willReturn([
            'default_branch' => 'main',
        ]);

        $result = $this->controller->publishToGitHub(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    // ── Stats edge cases ──────────────────────────────────────────────

    public function testStatsContainsExpectedKeys(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->stats(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('register', $data);
        $this->assertArrayHasKey('message', $data);
    }

    // ── Create with all fields ────────────────────────────────────────

    public function testCreateWithFullParams(): void
    {
        $register = $this->createRealRegister(1, 'Full Register');

        $this->request->method('getParams')->willReturn([
            'title' => 'Full Register',
            'description' => 'A register with all fields',
            'version' => '2.0.0',
        ]);
        $this->registerService->method('createFromArray')->willReturn($register);

        $result = $this->controller->create();

        $this->assertSame(201, $result->getStatus());
    }

    // ── Destroy with DatabaseConstraintException ──────────────────────

    public function testDestroyReturns500OnDatabaseConstraintException(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')->willReturn($register);
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 0]);
        $this->registerService->method('delete')
            ->willThrowException(new DatabaseConstraintException('Foreign key constraint'));

        $result = $this->controller->destroy(1);

        // DatabaseConstraintException extends Exception, so caught by generic catch -> 500
        $this->assertSame(500, $result->getStatus());
    }

    // ── Objects with pagination params ────────────────────────────────

    public function testObjectsWithPaginationParams(): void
    {
        $this->objectMapper->method('searchObjects')->willReturn([
            'results' => [['id' => 1], ['id' => 2]],
            'total' => 2,
        ]);

        $result = $this->controller->objects(1, 1);

        $this->assertSame(200, $result->getStatus());
    }

    // ── Update with DoesNotExistException ─────────────────────────────

    public function testUpdateReturnsErrorOnDoesNotExist(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->registerService->method('updateFromArray')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(DoesNotExistException::class);
        $this->controller->update(999);
    }

    // ── Index extended tests ─────────────────────────────────────────

    public function testIndexWithOffsetParam(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '5',
            '_offset' => '10',
        ]);
        $this->registerService->expects($this->once())
            ->method('findAll')
            ->with(
                $this->equalTo(5),
                $this->equalTo(10),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $this->assertSame([], $result->getData()['results']);
    }

    public function testIndexWithFiltersParam(): void
    {
        $this->request->method('getParams')->willReturn([
            'filters' => ['title' => 'Test'],
        ]);
        $this->registerService->expects($this->once())
            ->method('findAll')
            ->with(
                $this->isNull(),
                $this->isNull(),
                $this->equalTo(['title' => 'Test']),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexWithExtendAsString(): void
    {
        $this->request->method('getParams')->willReturn([
            '_extend' => 'schemas',
        ]);
        $this->registerService->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexWithExtendSchemasExpandsSchemaIds(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->setRegisterSchemas($register, [10, 20]);

        $this->request->method('getParams')->willReturn([
            '_extend' => ['schemas'],
        ]);
        $this->registerService->method('findAll')->willReturn([$register]);

        $schema1 = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema1->method('jsonSerialize')->willReturn(['id' => 10, 'title' => 'Schema A']);
        $schema2 = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema2->method('jsonSerialize')->willReturn(['id' => 20, 'title' => 'Schema B']);

        $this->schemaMapper->method('find')
            ->willReturnCallback(function ($id) use ($schema1, $schema2) {
                if ($id === 10) {
                    return $schema1;
                }
                return $schema2;
            });

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(2, $data['results'][0]['schemas']);
        $this->assertSame('Schema A', $data['results'][0]['schemas'][0]['title']);
    }

    public function testIndexWithExtendSchemasSkipsMissingSchema(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->setRegisterSchemas($register, [10, 999]);

        $this->request->method('getParams')->willReturn([
            '_extend' => ['schemas'],
        ]);
        $this->registerService->method('findAll')->willReturn([$register]);

        $schema1 = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema1->method('jsonSerialize')->willReturn(['id' => 10, 'title' => 'Schema A']);

        $this->schemaMapper->method('find')
            ->willReturnCallback(function ($id) use ($schema1) {
                if ($id === 10) {
                    return $schema1;
                }
                throw new DoesNotExistException('Not found');
            });

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // RegisterSerializer::expandSchemas() now RETAINS an orphan schema ID
        // at its original position when the schema cannot be resolved (rather
        // than dropping it), so typed JSON clients still see the reference.
        // Expect both entries: the expanded object (10) and the orphan ID (999).
        $this->assertCount(2, $data['results'][0]['schemas']);
        $this->assertSame(['id' => 10, 'title' => 'Schema A'], $data['results'][0]['schemas'][0]);
        $this->assertSame(999, $data['results'][0]['schemas'][1]);
    }

    public function testIndexWithSelfStatsExtend(): void
    {
        $register = $this->createRealRegister(1, 'Test');

        $this->request->method('getParams')->willReturn([
            '_extend' => ['@self.stats'],
        ]);
        $this->registerService->method('findAll')->willReturn([$register]);
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 5]);
        $this->auditTrailMapper->method('getStatistics')->willReturn(['total' => 3]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('stats', $data['results'][0]);
        $this->assertArrayHasKey('objects', $data['results'][0]['stats']);
        $this->assertArrayHasKey('logs', $data['results'][0]['stats']);
        $this->assertArrayHasKey('files', $data['results'][0]['stats']);
    }

    public function testIndexWithSchemasAndSelfStatsExtend(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->setRegisterSchemas($register, [10]);

        $this->request->method('getParams')->willReturn([
            '_extend' => ['schemas', '@self.stats'],
        ]);
        $this->registerService->method('findAll')->willReturn([$register]);

        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 10, 'title' => 'Schema A']);

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->registerService->method('getSchemaObjectCounts')->willReturn([
            10 => ['total' => 42],
        ]);
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 5]);
        $this->auditTrailMapper->method('getStatistics')->willReturn(['total' => 3]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // Schema should have stats attached
        $this->assertArrayHasKey('stats', $data['results'][0]['schemas'][0]);
        $this->assertSame(['total' => 42], $data['results'][0]['schemas'][0]['stats']['objects']);
    }

    public function testIndexWithSchemasAndStatsNoCountForSchema(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->setRegisterSchemas($register, [10]);

        $this->request->method('getParams')->willReturn([
            '_extend' => ['schemas', '@self.stats'],
        ]);
        $this->registerService->method('findAll')->willReturn([$register]);

        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 10, 'title' => 'Schema A']);

        $this->schemaMapper->method('find')->willReturn($schema);
        // Return empty counts — schema 10 is not in the map
        $this->registerService->method('getSchemaObjectCounts')->willReturn([]);
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 0]);
        $this->auditTrailMapper->method('getStatistics')->willReturn(['total' => 0]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        // Should have zero stats
        $this->assertSame(['total' => 0], $data['results'][0]['schemas'][0]['stats']['objects']);
    }

    public function testIndexPageConvertsToOffset(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_page' => '3',
        ]);
        $this->registerService->expects($this->once())
            ->method('findAll')
            ->with(
                $this->equalTo(10),
                $this->equalTo(20),  // (3-1) * 10 = 20
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
    }

    // ── Show extended tests ──────────────────────────────────────────

    public function testShowWithSelfStatsExtend(): void
    {
        $register = $this->createRealRegister(1, 'Test');

        $this->request->method('getParam')->willReturn('@self.stats');
        $this->registerService->method('find')->willReturn($register);
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 10]);
        $this->auditTrailMapper->method('getStatistics')->willReturn(['total' => 5]);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('stats', $data);
        $this->assertSame(['total' => 10], $data['stats']['objects']);
        $this->assertSame(['total' => 5], $data['stats']['logs']);
        $this->assertSame(['total' => 0, 'size' => 0], $data['stats']['files']);
    }

    public function testShowWithExtendAsArray(): void
    {
        $register = $this->createRealRegister(1, 'Test');

        $this->request->method('getParam')->willReturn(['@self.stats']);
        $this->registerService->method('find')->willReturn($register);
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 10]);
        $this->auditTrailMapper->method('getStatistics')->willReturn(['total' => 5]);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('stats', $data);
    }

    public function testShowWithoutStatsExtend(): void
    {
        $register = $this->createRealRegister(1, 'Test');

        $this->request->method('getParam')->willReturn([]);
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayNotHasKey('stats', $data);
    }

    // ── Create DatabaseConstraintException catch branch ──────────────

    public function testCreateReturns409OnDatabaseConstraintException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->registerService->method('createFromArray')
            ->willThrowException(new DatabaseConstraintException('Slug already exists', 409));

        $result = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(409, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Slug already exists', $data['error']);
    }

    // ── Update DatabaseConstraintException catch branch ──────────────

    public function testUpdateReturns409OnDatabaseConstraintException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->registerService->method('updateFromArray')
            ->willThrowException(new DatabaseConstraintException('Duplicate slug', 409));

        $result = $this->controller->update(1);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(409, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Duplicate slug', $data['error']);
    }

    // ── Export extended tests ────────────────────────────────────────

    public function testExportConfigurationWithIncludeObjects(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('test-register');
        $this->request->method('getParam')->willReturnMap([
            ['format', 'configuration', 'configuration'],
            ['includeObjects', false, 'true'],
            ['schema', null, null],
        ]);
        $this->registerService->method('find')->willReturn($register);
        $this->configurationService->expects($this->once())
            ->method('exportConfig')
            ->with(
                $this->equalTo($register),
                $this->isTrue()
            )
            ->willReturn(['info' => ['title' => 'Test']]);

        $result = $this->controller->export(1);

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
    }

    public function testExportCsvWithSchemaSuccess(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('test-register');

        $schema = $this->createRealSchema(5, 'Test Schema');
        $schema->setSlug('test-schema');

        $this->request->method('getParam')->willReturnMap([
            ['format', 'configuration', 'csv'],
            ['includeObjects', false, false],
            ['schema', null, '5'],
        ]);
        $this->registerService->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->exportService->method('exportToCsv')->willReturn('col1,col2\nval1,val2');
        $this->currentUser = null;

        $result = $this->controller->export(1);

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
    }

    public function testExportExcelSuccess(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('test-register');

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        $this->request->method('getParam')->willReturnMap([
            ['format', 'configuration', 'excel'],
            ['includeObjects', false, false],
            ['schema', null, null],
        ]);
        $this->registerService->method('find')->willReturn($register);
        $this->exportService->method('exportToExcel')->willReturn($spreadsheet);
        $this->currentUser = null;

        $result = $this->controller->export(1);

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
    }

    public function testExportPdfWithSchemaSuccess(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('test-register');

        $schema = $this->createRealSchema(5, 'Test Schema');
        $schema->setSlug('test-schema');

        $this->request->method('getParam')->willReturnMap([
            ['format', 'configuration', 'pdf'],
            ['includeObjects', false, false],
            ['schema', null, '5'],
        ]);
        $this->registerService->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->exportService->method('exportToPdf')->willReturn("%PDF-1.7\n...fake pdf bytes...");
        $this->currentUser = null;

        $result = $this->controller->export(1);

        $this->assertInstanceOf(\OCP\AppFramework\Http\DataDownloadResponse::class, $result);
        $this->assertSame('application/pdf', $result->getHeaders()['Content-Type'] ?? null);
        $this->assertStringContainsString('.pdf', $result->getHeaders()['Content-Disposition'] ?? '');
    }

    public function testExportPdfMissingSchemaReturns400(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->request->method('getParam')->willReturnMap([
            ['format', 'configuration', 'pdf'],
            ['includeObjects', false, false],
            ['schema', null, null],
        ]);
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->export(1);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());
        $this->assertStringContainsString('schema', $result->getData()['error']);
    }

    public function testExportPdfTooLargeReturns400(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('test-register');

        $schema = $this->createRealSchema(5, 'Test Schema');
        $schema->setSlug('test-schema');

        $this->request->method('getParam')->willReturnMap([
            ['format', 'configuration', 'pdf'],
            ['includeObjects', false, false],
            ['schema', null, '5'],
        ]);
        $this->registerService->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->exportService->method('exportToPdf')->willThrowException(
            new \OCA\OpenRegister\Exception\ExportTooLargeException(6000, 5000)
        );
        $this->currentUser = null;

        $result = $this->controller->export(1);

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());
        $this->assertSame('export_too_large', $result->getData()['error']);
        $this->assertSame(6000, $result->getData()['rowCount']);
        $this->assertSame(5000, $result->getData()['maxRows']);
    }

    // ── Import extended tests ────────────────────────────────────────

    public function testImportExcelSuccess(): void
    {
        $register = $this->createRealRegister(1, 'Test');

        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'data.xlsx',
            'tmp_name' => '/tmp/data.xlsx',
            'size' => 2048,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['type', null, 'excel'],
            ['includeObjects', false, false],
            ['validation', false, false],
            ['events', false, false],
            ['publish', false, false],
            ['enrich', true, true],
            ['rbac', true, true],
            ['multi', true, true],
            ['schema', null, null],
        ]);
        $this->registerService->method('find')->willReturn($register);
        $this->importService->method('importFromExcel')->willReturn([
            'created' => [],
            'updated' => [],
            'errors' => [],
        ]);
        // import() is admin-gated (checkRegisterManagePermission); keep the
        // default authenticated admin from setUp() rather than nulling it.

        $result = $this->controller->import(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Import successful', $data['message']);
    }

    public function testImportCsvMissingSchemaReturns400(): void
    {
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'data.csv',
            'tmp_name' => '/tmp/data.csv',
            'size' => 1024,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['type', null, 'csv'],
            ['includeObjects', false, false],
            ['validation', false, false],
            ['events', false, false],
            ['publish', false, false],
            ['enrich', true, true],
            ['schema', null, null],
        ]);
        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->import(1);

        $this->assertSame(400, $result->getStatus());
        $this->assertStringContainsString('Schema parameter is required', $result->getData()['error']);
    }

    public function testImportCsvWithSchemaSuccess(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $schema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);

        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'data.csv',
            'tmp_name' => '/tmp/data.csv',
            'size' => 1024,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['type', null, 'csv'],
            ['includeObjects', false, false],
            ['validation', false, false],
            ['events', false, false],
            ['publish', false, false],
            ['enrich', true, true],
            ['rbac', true, true],
            ['multi', true, true],
            ['schema', null, '5'],
        ]);
        $this->registerService->method('find')->willReturn($register);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->importService->method('importFromCsv')->willReturn([
            'created' => [],
            'updated' => [],
            'errors' => [],
        ]);
        // import() is admin-gated (checkRegisterManagePermission); keep the
        // default authenticated admin from setUp() rather than nulling it.

        $result = $this->controller->import(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Import successful', $data['message']);
    }

    public function testImportAutoDetectsExcelFromExtension(): void
    {
        $register = $this->createRealRegister(1, 'Test');

        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'data.xlsx',
            'tmp_name' => '/tmp/data.xlsx',
            'size' => 2048,
        ]);
        // type param is null — auto-detection should kick in
        $this->request->method('getParam')->willReturnMap([
            ['type', null, null],
            ['includeObjects', false, false],
            ['validation', false, false],
            ['events', false, false],
            ['publish', false, false],
            ['enrich', true, true],
            ['rbac', true, true],
            ['multi', true, true],
        ]);
        $this->registerService->method('find')->willReturn($register);
        $this->importService->expects($this->once())
            ->method('importFromExcel')
            ->willReturn(['created' => [], 'updated' => [], 'errors' => []]);
        // import() is admin-gated (checkRegisterManagePermission); keep the
        // default authenticated admin from setUp() rather than nulling it.

        $result = $this->controller->import(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testImportAutoDetectsCsvFromExtension(): void
    {
        $register = $this->createRealRegister(1, 'Test');

        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'data.csv',
            'tmp_name' => '/tmp/data.csv',
            'size' => 1024,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['type', null, null],
            ['includeObjects', false, false],
            ['validation', false, false],
            ['events', false, false],
            ['publish', false, false],
            ['enrich', true, true],
            ['rbac', true, true],
            ['multi', true, true],
            ['schema', null, null],
        ]);
        $this->registerService->method('find')->willReturn($register);

        $result = $this->controller->import(1);

        // CSV without schema returns 400
        $this->assertSame(400, $result->getStatus());
    }

    public function testImportAutoDetectsConfigurationFromJsonExtension(): void
    {
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'config.json',
            'tmp_name' => '/tmp/config.json',
            'size' => 512,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['type', null, null],
            ['includeObjects', false, false],
            ['validation', false, false],
            ['events', false, false],
            ['publish', false, false],
            ['enrich', true, true],
            ['owner', null, null],
            ['appId', null, null],
            ['version', null, null],
        ]);
        $this->request->method('getParams')->willReturn([]);

        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')->willReturn($register);
        $this->configurationService->method('getUploadedJson')->willReturn(['info' => []]);
        $this->configurationService->method('importFromJson')->willReturn([
            'registers' => [$register],
            'schemas' => [],
            'objects' => [],
        ]);

        $result = $this->controller->import(1);

        $this->assertSame(200, $result->getStatus());
    }

    // ── Import: register-bundle auto-create (register-import-auto-create, #1487) ──

    /**
     * REQ-IMP-AC-01: an admin uploading a register-bundle JSON for a
     * register that does not exist is let through (register: null) to
     * ImportService::importFromJson(), which resolves/creates it from the
     * bundle — the pre-existing immediate 404 no longer fires for this case.
     */
    public function testImportMissingRegisterJsonBundleAdminPassesThrough(): void
    {
        $bundlePath = tempnam(sys_get_temp_dir(), 'bundle_test_').'.json';
        file_put_contents($bundlePath, json_encode([
            'components' => [
                'registers' => ['products' => ['slug' => 'products', 'title' => 'Products']],
                'schemas' => ['product' => ['slug' => 'product', 'title' => 'Product']],
            ],
        ]));

        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'bundle.json',
            'tmp_name' => $bundlePath,
            'size' => 512,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['type', null, null],
            ['includeObjects', false, false],
            ['validation', false, false],
            ['events', false, false],
            ['publish', false, false],
            ['enrich', true, true],
            ['rbac', true, true],
            ['multi', true, true],
            ['schema', null, null],
        ]);

        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));
        // Default currentUser (set up in setUp()) is an admin.
        $this->importService->expects($this->once())
            ->method('importFromJson')
            ->with(
                $this->anything(),
                $this->callback(fn($register) => $register === null)
            )
            ->willReturn(['created' => [], 'updated' => [], 'errors' => []]);

        $result = $this->controller->import('products');

        $this->assertSame(200, $result->getStatus());
        unlink($bundlePath);
    }

    /**
     * A non-admin uploading the same register-bundle JSON for a missing
     * register still gets the existing 404 — auto-create requires admin
     * (mirrors the existing admin-only register-creation gate).
     */
    public function testImportMissingRegisterJsonBundleNonAdminReturns404(): void
    {
        $bundlePath = tempnam(sys_get_temp_dir(), 'bundle_test_').'.json';
        file_put_contents($bundlePath, json_encode([
            'components' => [
                'registers' => ['products' => ['slug' => 'products', 'title' => 'Products']],
                'schemas' => ['product' => ['slug' => 'product', 'title' => 'Product']],
            ],
        ]));

        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'bundle.json',
            'tmp_name' => $bundlePath,
            'size' => 512,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['type', null, null],
        ]);

        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));
        // Simulate an anonymous/non-admin caller.
        $this->currentUser = null;

        $this->importService->expects($this->never())->method('importFromJson');

        $result = $this->controller->import('products');

        $this->assertSame(404, $result->getStatus());
        unlink($bundlePath);
    }

    /**
     * A missing register with a non-JSON upload (e.g. Excel) still 404s
     * immediately, unchanged — auto-create only applies to JSON bundles.
     */
    public function testImportMissingRegisterExcelReturns404(): void
    {
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'data.xlsx',
            'tmp_name' => '/tmp/nonexistent-data.xlsx',
            'size' => 2048,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['type', null, 'excel'],
        ]);

        $this->registerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->importService->expects($this->never())->method('importFromExcel');

        $result = $this->controller->import(999);

        $this->assertSame(404, $result->getStatus());
    }

    // ── PublishToGitHub edge cases ───────────────────────────────────

    public function testPublishToGitHubUsesDefaultPathWhenEmpty(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('my-register');
        $this->registerMapper->method('find')->willReturn($register);
        $this->request->method('getParams')->willReturn([
            'owner' => 'org',
            'repo' => 'repo',
            'path' => '',
            'branch' => 'main',
        ]);
        $this->oasService->method('createOas')->willReturn(['openapi' => '3.0.0']);

        $this->githubService->expects($this->once())
            ->method('publishConfiguration')
            ->with(
                $this->equalTo('org'),
                $this->equalTo('repo'),
                $this->equalTo('my-register_openregister.json'),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'commit_sha' => 'abc',
                'commit_url' => 'https://example.com',
                'file_url' => 'https://example.com/file',
            ]);
        $this->githubService->method('getRepositoryInfo')->willReturn(['default_branch' => 'main']);

        $result = $this->controller->publishToGitHub(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testPublishToGitHubNonDefaultBranchMessage(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('my-register');
        $this->registerMapper->method('find')->willReturn($register);
        $this->request->method('getParams')->willReturn([
            'owner' => 'org',
            'repo' => 'repo',
            'path' => 'api/spec.json',
            'branch' => 'develop',
        ]);
        $this->oasService->method('createOas')->willReturn(['openapi' => '3.0.0']);
        $this->githubService->method('publishConfiguration')->willReturn([
            'commit_sha' => 'abc',
            'commit_url' => 'https://example.com',
            'file_url' => 'https://example.com/file',
        ]);
        $this->githubService->method('getRepositoryInfo')->willReturn(['default_branch' => 'main']);

        $result = $this->controller->publishToGitHub(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('develop', $data['message']);
        $this->assertStringContainsString('main', $data['message']);
        $this->assertSame('develop', $data['branch']);
        $this->assertSame('main', $data['default_branch']);
    }

    public function testPublishToGitHubGetRepositoryInfoFails(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('my-register');
        $this->registerMapper->method('find')->willReturn($register);
        $this->request->method('getParams')->willReturn([
            'owner' => 'org',
            'repo' => 'repo',
            'path' => 'api/spec.json',
            'branch' => 'main',
        ]);
        $this->oasService->method('createOas')->willReturn(['openapi' => '3.0.0']);
        $this->githubService->method('publishConfiguration')->willReturn([
            'commit_sha' => 'abc',
            'commit_url' => 'https://example.com',
            'file_url' => 'https://example.com/file',
        ]);
        $this->githubService->method('getRepositoryInfo')
            ->willThrowException(new Exception('API rate limit'));

        $result = $this->controller->publishToGitHub(1);

        // Should still succeed — getRepositoryInfo failure is non-fatal
        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertNull($data['default_branch']);
    }

    public function testPublishToGitHubGetFileShaFails(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('my-register');
        $this->registerMapper->method('find')->willReturn($register);
        $this->request->method('getParams')->willReturn([
            'owner' => 'org',
            'repo' => 'repo',
            'path' => 'new-file.json',
            'branch' => 'main',
        ]);
        $this->oasService->method('createOas')->willReturn(['openapi' => '3.0.0']);
        // getFileSha throws — file doesn't exist, should pass null for fileSha
        $this->githubService->method('getFileSha')
            ->willThrowException(new Exception('Not Found'));
        $this->githubService->expects($this->once())
            ->method('publishConfiguration')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->isNull()  // fileSha should be null
            )
            ->willReturn([
                'commit_sha' => 'abc',
                'commit_url' => 'https://example.com',
                'file_url' => 'https://example.com/file',
            ]);
        $this->githubService->method('getRepositoryInfo')->willReturn(['default_branch' => 'main']);

        $result = $this->controller->publishToGitHub(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testPublishToGitHubStripsLeadingSlashFromPath(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('my-register');
        $this->registerMapper->method('find')->willReturn($register);
        $this->request->method('getParams')->willReturn([
            'owner' => 'org',
            'repo' => 'repo',
            'path' => '/api/spec.json',
            'branch' => 'main',
        ]);
        $this->oasService->method('createOas')->willReturn(['openapi' => '3.0.0']);
        $this->githubService->expects($this->once())
            ->method('publishConfiguration')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->equalTo('api/spec.json'),  // Leading slash stripped
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything()
            )
            ->willReturn([
                'commit_sha' => 'abc',
                'commit_url' => 'https://example.com',
                'file_url' => 'https://example.com/file',
            ]);
        $this->githubService->method('getRepositoryInfo')->willReturn(['default_branch' => 'main']);

        $result = $this->controller->publishToGitHub(1);

        $this->assertSame(200, $result->getStatus());
    }

    // ── Create removes underscore-prefixed params ────────────────────

    public function testCreateRemovesUnderscorePrefixedParams(): void
    {
        $register = $this->createRealRegister(1, 'Test');

        $this->request->method('getParams')->willReturn([
            '_route' => 'some.route',
            '_method' => 'POST',
            'title' => 'Test',
        ]);
        $this->registerService->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return !isset($data['_route'])
                    && !isset($data['_method'])
                    && isset($data['title']);
            }))
            ->willReturn($register);

        $result = $this->controller->create();

        $this->assertSame(201, $result->getStatus());
    }

    // ── Update removes underscore-prefixed params ────────────────────

    public function testUpdateRemovesUnderscorePrefixedParams(): void
    {
        $register = $this->createRealRegister(1, 'Updated');

        $this->request->method('getParams')->willReturn([
            '_route' => 'some.route',
            '_method' => 'PUT',
            'title' => 'Updated',
        ]);
        $this->registerService->expects($this->once())
            ->method('updateFromArray')
            ->with(
                $this->equalTo(1),
                $this->callback(function ($data) {
                    return !isset($data['_route'])
                        && !isset($data['_method'])
                        && isset($data['title']);
                })
            )
            ->willReturn($register);

        $result = $this->controller->update(1);

        $this->assertSame(200, $result->getStatus());
    }

    // ── Import configuration type with objects in result ─────────────

    public function testImportConfigurationWithObjectsInResult(): void
    {
        $register = $this->createRealRegister(1, 'Test');

        $object = $this->createRealObjectEntity(42, 'uuid-123');

        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'config.json',
            'tmp_name' => '/tmp/config.json',
            'size' => 512,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['type', null, 'configuration'],
            ['includeObjects', false, false],
            ['validation', false, false],
            ['events', false, false],
            ['publish', false, false],
            ['enrich', true, true],
            ['owner', null, null],
            ['appId', null, null],
            ['version', null, null],
        ]);
        $this->request->method('getParams')->willReturn([]);

        $this->registerService->method('find')->willReturn($register);
        $this->configurationService->method('getUploadedJson')->willReturn(['info' => []]);
        $this->configurationService->method('importFromJson')->willReturn([
            'registers' => [$register],
            'schemas' => [],
            'objects' => [$object],
        ]);

        $result = $this->controller->import(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame('Import successful', $data['message']);
        $this->assertCount(1, $data['summary']['configuration']['created']);
        $this->assertSame(42, $data['summary']['configuration']['created'][0]['id']);
        $this->assertSame('uuid-123', $data['summary']['configuration']['created'][0]['uuid']);
    }

    public function testImportConfigurationNoRegistersInResultMergesSchemas(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->setRegisterSchemas($register, [10]);

        $newSchema = $this->createRealSchema(20, 'New Schema');

        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'config.json',
            'tmp_name' => '/tmp/config.json',
            'size' => 512,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['type', null, 'configuration'],
            ['includeObjects', false, false],
            ['validation', false, false],
            ['events', false, false],
            ['publish', false, false],
            ['enrich', true, true],
            ['owner', null, null],
            ['appId', null, null],
            ['version', null, null],
        ]);
        $this->request->method('getParams')->willReturn([]);

        $this->registerService->method('find')->willReturn($register);
        $this->configurationService->method('getUploadedJson')->willReturn(['info' => []]);
        $this->configurationService->method('importFromJson')->willReturn([
            'registers' => [],  // No registers -> merge schemas branch
            'schemas' => [$newSchema],
            'objects' => [],
        ]);
        // Track what updateFromArray receives (don't use callback constraint
        // since PHPUnit throws ExpectationFailedException which the controller catches)
        $capturedData = null;
        $this->registerService->expects($this->atLeastOnce())
            ->method('updateFromArray')
            ->willReturnCallback(function ($id, $data) use ($register, &$capturedData) {
                $capturedData = $data;
                return $register;
            });

        $result = $this->controller->import(1);

        $this->assertSame(200, $result->getStatus());
        // Verify updateFromArray was called (the no-registers branch was exercised)
        $this->assertNotNull($capturedData);
        $this->assertArrayHasKey('schemas', $capturedData);
    }

    public function testImportConfigurationGetUploadedJsonReturnsJsonResponse(): void
    {
        $this->request->method('getUploadedFile')->willReturn([
            'name' => 'config.json',
            'tmp_name' => '/tmp/config.json',
            'size' => 512,
        ]);
        $this->request->method('getParam')->willReturnMap([
            ['type', null, 'configuration'],
            ['includeObjects', false, false],
            ['validation', false, false],
            ['events', false, false],
            ['publish', false, false],
            ['enrich', true, true],
        ]);
        $this->request->method('getParams')->willReturn([]);

        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')->willReturn($register);

        // getUploadedJson returns a JSONResponse (error case)
        $errorResponse = new JSONResponse(['error' => 'Invalid JSON'], 400);
        $this->configurationService->method('getUploadedJson')->willReturn($errorResponse);

        $result = $this->controller->import(1);

        // Should return the error JSONResponse directly
        $this->assertSame(400, $result->getStatus());
        $this->assertSame('Invalid JSON', $result->getData()['error']);
    }

    // ── PublishToGitHub custom commit message ────────────────────────

    public function testPublishToGitHubCustomCommitMessage(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('my-register');
        $this->registerMapper->method('find')->willReturn($register);
        $this->request->method('getParams')->willReturn([
            'owner' => 'org',
            'repo' => 'repo',
            'path' => 'spec.json',
            'branch' => 'main',
            'commitMessage' => 'Custom commit message',
        ]);
        $this->oasService->method('createOas')->willReturn(['openapi' => '3.0.0']);
        $this->githubService->expects($this->once())
            ->method('publishConfiguration')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->equalTo('Custom commit message'),
                $this->anything()
            )
            ->willReturn([
                'commit_sha' => 'abc',
                'commit_url' => 'https://example.com',
                'file_url' => 'https://example.com/file',
            ]);
        $this->githubService->method('getRepositoryInfo')->willReturn(['default_branch' => 'main']);

        $result = $this->controller->publishToGitHub(1);

        $this->assertSame(200, $result->getStatus());
    }

    // ── Schemas with string ID (UUID/slug) ───────────────────────────

    public function testSchemasWithStringId(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')
            ->with($this->equalTo('test-slug'))
            ->willReturn($register);

        $this->registerMapper->method('getSchemasByRegisterId')->willReturn([]);

        $result = $this->controller->schemas('test-slug');

        $this->assertSame(200, $result->getStatus());
        $this->assertSame(0, $result->getData()['total']);
    }

    // ── Destroy calls delete on service ──────────────────────────────

    public function testDestroyCallsDeleteOnService(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $this->registerService->method('find')->willReturn($register);
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 0]);
        $this->registerService->expects($this->once())
            ->method('delete')
            ->with($this->equalTo($register));

        $result = $this->controller->destroy(1);

        $this->assertSame(200, $result->getStatus());
    }

    // ── Index with null schemas in register ──────────────────────────

    public function testIndexExtendSchemasWithNullSchemasField(): void
    {
        // Register with no schemas set (null/empty)
        $register = $this->createRealRegister(1, 'Test');
        // schemas defaults to [] in Register entity

        $this->request->method('getParams')->willReturn([
            '_extend' => ['schemas'],
        ]);
        $this->registerService->method('findAll')->willReturn([$register]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        // schemas should remain as empty array (no expansion needed)
        $this->assertSame([], $result->getData()['results'][0]['schemas']);
    }

    // ── Export configuration JSON encode failure ─────────────────────

    public function testExportConfigurationJsonEncodeFails(): void
    {
        $register = $this->createRealRegister(1, 'Test');
        $register->setSlug('test-register');
        $this->request->method('getParam')->willReturnMap([
            ['format', 'configuration', 'configuration'],
            ['includeObjects', false, false],
            ['schema', null, null],
        ]);
        $this->registerService->method('find')->willReturn($register);
        // json_encode fails on resources or certain values — use INF to trigger failure
        $this->configurationService->method('exportConfig')->willReturn(['value' => INF]);

        $result = $this->controller->export(1);

        // json_encode returns false for INF, triggering the exception catch
        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(400, $result->getStatus());
    }

    /**
     * registerObjectStats() sums the per-schema counts, reporting a LIVE total.
     *
     * `total` must exclude soft-deleted rows (the per-schema UNION's `total` includes
     * them, `deleted` is the subset), so a register with two schemas — one with 5 objects
     * / 1 deleted, one with 3 / 0 deleted — reports total 7 (=(5-1)+(3-0)) and deleted 1.
     * ObjectEntityMapper::getStatistics() must NOT be called when per-schema counts exist.
     *
     * @return void
     */
    public function testRegisterObjectStatsSumsLiveCountsFromSchemaStats(): void
    {
        $this->objectMapper->expects($this->never())->method('getStatistics');

        $schemaStats = [
            10 => ['total' => 5, 'deleted' => 1, 'invalid' => 0, 'locked' => 0, 'size' => 100],
            20 => ['total' => 3, 'deleted' => 0, 'invalid' => 0, 'locked' => 0, 'size' => 50],
        ];

        $method = new \ReflectionMethod(RegistersController::class, 'registerObjectStats');
        $method->setAccessible(true);
        $result = $method->invoke($this->controller, 7, $schemaStats);

        $this->assertSame(7, $result['total']);
        $this->assertSame(1, $result['deleted']);
        $this->assertSame(150, $result['size']);
    }

    /**
     * With no per-schema counts (the '@self.stats'-without-'schemas' caller),
     * registerObjectStats() falls back to the mapper rather than reporting zero.
     *
     * @return void
     */
    public function testRegisterObjectStatsFallsBackToMapperWithoutSchemaStats(): void
    {
        $this->objectMapper->expects($this->once())
            ->method('getStatistics')
            ->with(registerId: 7, schemaId: null)
            ->willReturn(['total' => 42, 'size' => 0, 'invalid' => 0, 'deleted' => 0, 'locked' => 0]);

        $method = new \ReflectionMethod(RegistersController::class, 'registerObjectStats');
        $method->setAccessible(true);
        $result = $method->invoke($this->controller, 7, null);

        $this->assertSame(42, $result['total']);
    }

}
