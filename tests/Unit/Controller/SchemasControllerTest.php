<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
<<<<<<< HEAD
=======
use OCA\OpenRegister\Service\DownloadService;
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\UploadService;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\DB\Exception as DBException;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

<<<<<<< HEAD
/*
=======
/**
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 * Unit tests for SchemasController
 *
 * @package Unit\Controller
 */
use Psr\Container\ContainerInterface;

class SchemasControllerTest extends TestCase
{
<<<<<<< HEAD

    private SchemasController $controller;

    private IRequest&MockObject $request;

    private IAppConfig&MockObject $config;

    private SchemaMapper&MockObject $schemaMapper;

    private MagicMapper&MockObject $objectMapper;

    private UploadService&MockObject $uploadService;

    private AuditTrailMapper&MockObject $auditTrailMapper;

    private OrganisationService&MockObject $organisationService;

    private SchemaCacheHandler&MockObject $schemaCacheService;

    private FacetCacheHandler&MockObject $facetCacheSvc;

    private SchemaService&MockObject $schemaService;

    private LoggerInterface&MockObject $logger;

    private \Psr\Container\ContainerInterface&MockObject $container;

    /**
     * The user the mocked session resolves to. SchemasController resolves the
     * session + group manager lazily via the container, so the container's
     * get() returns mocks driven by this property. Defaults to an admin so
     * write and authenticated-read tests pass; anonymous-read tests set it null.
     */
    private ?\OCP\IUser $currentUser = null;

=======
    private SchemasController $controller;
    private IRequest&MockObject $request;
    private IAppConfig&MockObject $config;
    private SchemaMapper&MockObject $schemaMapper;
    private MagicMapper&MockObject $objectMapper;
    private DownloadService&MockObject $downloadService;
    private UploadService&MockObject $uploadService;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private OrganisationService&MockObject $organisationService;
    private SchemaCacheHandler&MockObject $schemaCacheService;
    private FacetCacheHandler&MockObject $facetCacheSvc;
    private SchemaService&MockObject $schemaService;
    private LoggerInterface&MockObject $logger;

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    protected function setUp(): void
    {
        parent::setUp();

<<<<<<< HEAD
        $this->request          = $this->createMock(IRequest::class);
        $this->config           = $this->createMock(IAppConfig::class);
        $this->schemaMapper     = $this->createMock(SchemaMapper::class);
        $this->objectMapper     = $this->createMock(MagicMapper::class);
        $this->uploadService    = $this->createMock(UploadService::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->schemaCacheService  = $this->createMock(SchemaCacheHandler::class);
        $this->facetCacheSvc       = $this->createMock(FacetCacheHandler::class);
        $this->schemaService       = $this->createMock(SchemaService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // SchemasController resolves IUserSession + IGroupManager lazily via the
        // container (isCurrentUserAdmin / checkSchemaManagePermission / the
        // read-visibility guard). Default to an authenticated admin; anonymous-read
        // tests set $this->currentUser = null.
        $this->currentUser = $this->createMock(\OCP\IUser::class);
        $this->currentUser->method('getUID')->willReturn('admin');
        $userSession = $this->createMock(\OCP\IUserSession::class);
        $userSession->method('getUser')->willReturnCallback(fn() => $this->currentUser);
        $groupManager = $this->createMock(\OCP\IGroupManager::class);
        $groupManager->method('isAdmin')->willReturnCallback(fn() => $this->currentUser !== null);
        $groupManager->method('getUserGroupIds')->willReturn(['admin']);

        $this->container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $this->container->method('get')->willReturnCallback(
                function ($id) use ($userSession, $groupManager) {
                    if ($id === \OCP\IUserSession::class) {
                        return $userSession;
                    }

                    if ($id === \OCP\IGroupManager::class) {
                        return $groupManager;
                    }

                    return null;
                }
                );

=======
        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->downloadService = $this->createMock(DownloadService::class);
        $this->uploadService = $this->createMock(UploadService::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheHandler::class);
        $this->facetCacheSvc = $this->createMock(FacetCacheHandler::class);
        $this->schemaService = $this->createMock(SchemaService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->controller = new SchemasController(
            'openregister',
            $this->request,
            $this->config,
            $this->schemaMapper,
            $this->objectMapper,
<<<<<<< HEAD
=======
            $this->downloadService,
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
            $this->uploadService,
            $this->auditTrailMapper,
            $this->organisationService,
            $this->schemaCacheService,
            $this->facetCacheSvc,
            $this->schemaService,
            $this->logger,
<<<<<<< HEAD
            $this->container
        );
    }//end setUp()
=======
            $this->createMock(\Psr\Container\ContainerInterface::class)
        );
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testIndexReturnsSchemas(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $result = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testIndexReturnsSchemas()

    public function testIndexWithPagination(): void
    {
        $this->request->method('getParams')->willReturn(
                [
                    '_limit'  => '5',
                    '_offset' => '10',
                ]
                );
=======
    }

    public function testIndexWithPagination(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '5',
            '_offset' => '10',
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->schemaMapper->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testIndexWithPagination()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testShowReturnsSchema(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testShowReturnsSchema()

    public function testShowAnonymousUnpublishedSchemaReturns401(): void
    {
        // Anonymous (no user) + unpublished schema → 401 (read-visibility guard).
        $this->currentUser = null;
        $schema            = $this->createMock(Schema::class);
        $schema->method('getPublished')->willReturn(null);
        $schema->method('getDepublished')->willReturn(null);

        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->controller->show(1);

        $this->assertSame(401, $result->getStatus());
    }//end testShowAnonymousUnpublishedSchemaReturns401()

    public function testShowAnonymousPublishedSchemaReturns200(): void
    {
        // Anonymous + published schema → 200.
        $this->currentUser = null;
        $schema            = $this->createMock(Schema::class);
        $schema->method('getPublished')->willReturn(new \DateTime());
        $schema->method('getDepublished')->willReturn(null);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);
        $this->schemaMapper->method('findExtendedBy')->willReturn([]);

        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
    }//end testShowAnonymousPublishedSchemaReturns200()

    public function testIndexAnonymousFiltersUnpublishedSchemas(): void
    {
        // Anonymous list returns only published schemas.
        $this->currentUser = null;
        $published         = $this->createMock(Schema::class);
        $published->method('getPublished')->willReturn(new \DateTime());
        $published->method('getDepublished')->willReturn(null);
        $published->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Published']);

        $unpublished = $this->createMock(Schema::class);
        $unpublished->method('getPublished')->willReturn(null);
        $unpublished->method('getDepublished')->willReturn(null);
        $unpublished->method('jsonSerialize')->willReturn(['id' => 2, 'title' => 'Unpublished']);

        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('findAll')->willReturn([$published, $unpublished]);
        $this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

        $result = $this->controller->index();
        $data   = $result->getData();

        $this->assertSame(200, $result->getStatus());
        $ids = array_map(fn($s) => $s['id'], $data['results']);
        $this->assertContains(1, $ids);
        $this->assertNotContains(2, $ids);
    }//end testIndexAnonymousFiltersUnpublishedSchemas()

    public function testCreateReturnsCreatedSchema(): void
    {
        $schema = $this->createRealSchema(1, 'New Schema');
=======
    }

    public function testCreateReturnsCreatedSchema(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'New Schema']);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $this->request->method('getParams')->willReturn(['title' => 'New Schema']);
        $this->schemaMapper->method('createFromArray')->willReturn($schema);

        $result = $this->controller->create();

        $this->assertSame(201, $result->getStatus());
<<<<<<< HEAD
    }//end testCreateReturnsCreatedSchema()

    public function testCreateRemovesInternalParams(): void
    {
        $schema = $this->createRealSchema(1, 'Test');

        $this->request->method('getParams')->willReturn(
                [
                    '_route' => 'test',
                    'id'     => 5,
                    'title'  => 'Test',
                ]
                );
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->with(
                    $this->callback(
                    function ($data) {
                        return !isset($data['_route']) && !isset($data['id']) && isset($data['title']);
                    }
                    )
                    )
            ->willReturn($schema);

        $this->controller->create();
    }//end testCreateRemovesInternalParams()
=======
    }

    public function testCreateRemovesInternalParams(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1]);

        $this->request->method('getParams')->willReturn([
            '_route' => 'test',
            'id' => 5,
            'title' => 'Test',
        ]);
        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->with($this->callback(function ($data) {
                return !isset($data['_route']) && !isset($data['id']) && isset($data['title']);
            }))
            ->willReturn($schema);

        $this->controller->create();
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testCreateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->create();

        $this->assertSame(500, $result->getStatus());
<<<<<<< HEAD
    }//end testCreateReturns500OnException()

    private function createRealSchema(int $id=1, string $title='Test'): Schema
    {
        $schema = new Schema();
        $ref    = new \ReflectionClass($schema);
        $prop   = $ref->getProperty('id');
=======
    }

    private function createRealSchema(int $id = 1, string $title = 'Test'): Schema
    {
        $schema = new Schema();
        $ref = new \ReflectionClass($schema);
        $prop = $ref->getProperty('id');
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        $schema->setTitle($title);
        return $schema;
<<<<<<< HEAD
    }//end createRealSchema()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUpdateReturnsUpdatedSchema(): void
    {
        $schema = $this->createRealSchema(1, 'Updated');

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $result = $this->controller->update(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testUpdateReturnsUpdatedSchema()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testPatchDelegatesToUpdate(): void
    {
        $schema = $this->createRealSchema(1, 'Patched');

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $result = $this->controller->patch(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testPatchDelegatesToUpdate()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testDestroyReturnsEmptyOnSuccess(): void
    {
        $schema = $this->createRealSchema(1, 'Test');
        $this->schemaMapper->method('find')->willReturn($schema);
<<<<<<< HEAD
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 0]);
=======
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $result = $this->controller->destroy(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testDestroyReturnsEmptyOnSuccess()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testDestroyReturns500WhenNotFound(): void
    {
        // SchemasController::destroy() catches \Exception (not DoesNotExistException
        // specifically), so DoesNotExistException results in 500 not 404.
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertSame(500, $result->getStatus());
<<<<<<< HEAD
    }//end testDestroyReturns500WhenNotFound()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testDownloadReturnsSchema(): void
    {
        $schema = $this->createRealSchema(1, 'Downloadable');
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->controller->download(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testDownloadReturnsSchema()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testDownloadReturns404WhenNotFound(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->controller->download(999);

        $this->assertSame(404, $result->getStatus());
        $this->assertSame('Schema not found', $result->getData()['error']);
<<<<<<< HEAD
    }//end testDownloadReturns404WhenNotFound()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testRelatedReturnsRelationships(): void
    {
        $schema1 = $this->createRealSchema(1, 'Schema A');
        $schema1->setProperties(['field1' => ['type' => 'string']]);

        $this->schemaMapper->method('getRelated')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema1);
        $this->schemaMapper->method('findAll')->willReturn([$schema1]);
        $this->schemaMapper->method('hasReferenceToSchema')->willReturn(false);

        $result = $this->controller->related(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('incoming', $data);
        $this->assertArrayHasKey('outgoing', $data);
        $this->assertArrayHasKey('total', $data);
<<<<<<< HEAD
    }//end testRelatedReturnsRelationships()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testRelatedReturns404WhenSchemaNotFound(): void
    {
        $this->schemaMapper->method('getRelated')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->related(999);

        $this->assertSame(404, $result->getStatus());
<<<<<<< HEAD
    }//end testRelatedReturns404WhenSchemaNotFound()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testRelatedReturns500OnGenericException(): void
    {
        $this->schemaMapper->method('getRelated')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->related(1);

        $this->assertSame(500, $result->getStatus());
<<<<<<< HEAD
    }//end testRelatedReturns500OnGenericException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testStatsReturnsSchemaStatistics(): void
    {
        $schema = $this->createRealSchema(1, 'Stats Schema');
        $this->schemaMapper->method('find')->willReturn($schema);

<<<<<<< HEAD
        $this->objectMapper->method('getStatistics')->willReturn(
                [
                    'total'     => 50,
                    'invalid'   => 3,
                    'deleted'   => 5,
                    'published' => 42,
                    'locked'    => 1,
                    'size'      => 10000,
                ]
                );

        $this->auditTrailMapper->method('getStatistics')->willReturn(
                [
                    'total' => 100,
                ]
                );

        $this->schemaMapper->method('getRegisterCountPerSchema')->willReturn(
                [
                    1 => 3,
                ]
                );
=======
        $this->objectMapper->method('getStatistics')->willReturn([
            'total' => 50,
            'invalid' => 3,
            'deleted' => 5,
            'published' => 42,
            'locked' => 1,
            'size' => 10000,
        ]);

        $this->auditTrailMapper->method('getStatistics')->willReturn([
            'total' => 100,
        ]);

        $this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([
            1 => 3,
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $result = $this->controller->stats(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(50, $data['objectCount']);
        $this->assertArrayHasKey('objects', $data);
        $this->assertArrayHasKey('logs', $data);
<<<<<<< HEAD
    }//end testStatsReturnsSchemaStatistics()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testStatsReturns404WhenSchemaNotFound(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->stats(999);

        $this->assertSame(404, $result->getStatus());
<<<<<<< HEAD
    }//end testStatsReturns404WhenSchemaNotFound()

    public function testExploreReturnsExplorationResults(): void
    {
        $this->schemaService->method('exploreSchemaProperties')->willReturn(
                [
                    'newProperties'  => ['field1' => ['type' => 'string']],
                    'objectsScanned' => 100,
                ]
                );
=======
    }

    public function testExploreReturnsExplorationResults(): void
    {
        $this->schemaService->method('exploreSchemaProperties')->willReturn([
            'newProperties' => ['field1' => ['type' => 'string']],
            'objectsScanned' => 100,
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $result = $this->controller->explore(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testExploreReturnsExplorationResults()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testExploreReturns500OnException(): void
    {
        $this->schemaService->method('exploreSchemaProperties')
            ->willThrowException(new Exception('Explore failed'));

        $result = $this->controller->explore(1);

        $this->assertSame(500, $result->getStatus());
<<<<<<< HEAD
    }//end testExploreReturns500OnException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUpdateFromExplorationReturns400WhenNoProperties(): void
    {
        $this->request->method('getParam')->willReturn([]);

        $result = $this->controller->updateFromExploration(1);

        $this->assertSame(400, $result->getStatus());
<<<<<<< HEAD
    }//end testUpdateFromExplorationReturns400WhenNoProperties()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUpdateFromExplorationSuccess(): void
    {
        $this->request->method('getParam')
<<<<<<< HEAD
            ->willReturnMap(
                    [
                        ['properties', [], ['field1' => ['type' => 'string']]],
                    ]
                    );
=======
            ->willReturnMap([
                ['properties', [], ['field1' => ['type' => 'string']]],
            ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $updatedSchema = $this->createRealSchema(1, 'Updated');
        $this->schemaService->method('updateSchemaFromExploration')->willReturn($updatedSchema);
        // clearSchemaCache() returns void, no need to mock return value
<<<<<<< HEAD
=======

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $result = $this->controller->updateFromExploration(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
<<<<<<< HEAD
    }//end testUpdateFromExplorationSuccess()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUpdateFromExplorationReturns500OnException(): void
    {
        $this->request->method('getParam')
<<<<<<< HEAD
            ->willReturnMap(
                    [
                        ['properties', [], ['field1' => ['type' => 'string']]],
                    ]
                    );
=======
            ->willReturnMap([
                ['properties', [], ['field1' => ['type' => 'string']]],
            ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $this->schemaService->method('updateSchemaFromExploration')
            ->willThrowException(new Exception('Update error'));

        $result = $this->controller->updateFromExploration(1);

        $this->assertSame(500, $result->getStatus());
<<<<<<< HEAD
    }//end testUpdateFromExplorationReturns500OnException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testPublishSetsPublicationDate(): void
    {
        $schema = $this->createRealSchema(1, 'Publishable');
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->publish(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testPublishSetsPublicationDate()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testPublishReturns404WhenSchemaNotFound(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->publish(999);

        $this->assertSame(404, $result->getStatus());
<<<<<<< HEAD
    }//end testPublishReturns404WhenSchemaNotFound()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testDepublishSetsDepublicationDate(): void
    {
        $schema = $this->createRealSchema(1, 'Depublishable');
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->depublish(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testDepublishSetsDepublicationDate()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testDepublishReturns404WhenSchemaNotFound(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->depublish(999);

        $this->assertSame(404, $result->getStatus());
<<<<<<< HEAD
    }//end testDepublishReturns404WhenSchemaNotFound()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUpdateRemovesImmutableFields(): void
    {
        $schema = $this->createRealSchema(1, 'Updated');

<<<<<<< HEAD
        $this->request->method('getParams')->willReturn(
                [
                    'id'           => 1,
                    'organisation' => 'org1',
                    'owner'        => 'user1',
                    'created'      => '2024-01-01',
                    'title'        => 'Updated',
                ]
                );
=======
        $this->request->method('getParams')->willReturn([
            'id' => 1,
            'organisation' => 'org1',
            'owner' => 'user1',
            'created' => '2024-01-01',
            'title' => 'Updated',
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->schemaMapper->expects($this->once())
            ->method('updateFromArray')
            ->with(
                $this->equalTo(1),
<<<<<<< HEAD
                $this->callback(
                        function ($data) {
                            return !isset($data['id'])
                            && !isset($data['organisation'])
                            && !isset($data['owner'])
                            && !isset($data['created'])
                            && isset($data['title']);
                        }
                        )
=======
                $this->callback(function ($data) {
                    return !isset($data['id'])
                        && !isset($data['organisation'])
                        && !isset($data['owner'])
                        && !isset($data['created'])
                        && isset($data['title']);
                })
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
            )
            ->willReturn($schema);

        $this->controller->update(1);
<<<<<<< HEAD
    }//end testUpdateRemovesImmutableFields()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUpdateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->update(1);

        $this->assertSame(500, $result->getStatus());
<<<<<<< HEAD
    }//end testUpdateReturns500OnException()

    // ── index() branch coverage ──
=======
    }

    // ── index() branch coverage ──

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testIndexWithPageBasedPagination(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

<<<<<<< HEAD
        $this->request->method('getParams')->willReturn(
                [
                    '_limit' => '10',
                    '_page'  => '3',
                ]
                );
=======
        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_page' => '3',
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
<<<<<<< HEAD
    }//end testIndexWithPageBasedPagination()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testIndexWithExtendStats(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

<<<<<<< HEAD
        $this->request->method('getParams')->willReturn(
                [
                    '_extend' => '@self.stats',
                ]
                );
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->schemaMapper->method('findAllExtendedBy')->willReturn([]);
        $this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([1 => 2]);
        $this->objectMapper->method('getStatisticsGroupedBySchema')->willReturn(
                [
                    1 => ['total' => 10, 'size' => 500, 'invalid' => 1, 'deleted' => 0, 'locked' => 0, 'published' => 9],
                ]
                );
        $this->auditTrailMapper->method('getStatisticsGroupedBySchema')->willReturn(
                [
                    1 => ['total' => 20, 'size' => 100],
                ]
                );
=======
        $this->request->method('getParams')->willReturn([
            '_extend' => '@self.stats',
        ]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->schemaMapper->method('findAllExtendedBy')->willReturn([]);
        $this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([1 => 2]);
        $this->objectMapper->method('getStatisticsGroupedBySchema')->willReturn([
            1 => ['total' => 10, 'size' => 500, 'invalid' => 1, 'deleted' => 0, 'locked' => 0, 'published' => 9],
        ]);
        $this->auditTrailMapper->method('getStatisticsGroupedBySchema')->willReturn([
            1 => ['total' => 20, 'size' => 100],
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('stats', $data['results'][0]);
        $this->assertSame(10, $data['results'][0]['stats']['objects']['total']);
        $this->assertSame(2, $data['results'][0]['stats']['registers']);
<<<<<<< HEAD
    }//end testIndexWithExtendStats()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testIndexWithExtendStatsDefaultsForMissingSchema(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 99, 'title' => 'Orphan']);

<<<<<<< HEAD
        $this->request->method('getParams')->willReturn(
                [
                    '_extend' => ['@self.stats'],
                ]
                );
=======
        $this->request->method('getParams')->willReturn([
            '_extend' => ['@self.stats'],
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->schemaMapper->method('findAllExtendedBy')->willReturn([]);
        $this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([]);
        $this->objectMapper->method('getStatisticsGroupedBySchema')->willReturn([]);
        $this->auditTrailMapper->method('getStatisticsGroupedBySchema')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $stats = $result->getData()['results'][0]['stats'];
        $this->assertSame(0, $stats['objects']['total']);
        $this->assertSame(0, $stats['registers']);
<<<<<<< HEAD
    }//end testIndexWithExtendStatsDefaultsForMissingSchema()

    public function testIndexWithFilters(): void
    {
        $this->request->method('getParams')->willReturn(
                [
                    'filters' => ['title' => 'Test'],
                ]
                );
=======
    }

    public function testIndexWithFilters(): void
    {
        $this->request->method('getParams')->willReturn([
            'filters' => ['title' => 'Test'],
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testIndexWithFilters()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testIndexExtendedByPopulated(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Base']);

        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
<<<<<<< HEAD
        $this->schemaMapper->method('findAllExtendedBy')->willReturn(
                [
                    1 => ['uuid-child-1', 'uuid-child-2'],
                ]
                );
=======
        $this->schemaMapper->method('findAllExtendedBy')->willReturn([
            1 => ['uuid-child-1', 'uuid-child-2'],
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertSame(['uuid-child-1', 'uuid-child-2'], $data['results'][0]['@self']['extendedBy']);
<<<<<<< HEAD
    }//end testIndexExtendedByPopulated()

    // ── show() branch coverage ──
=======
    }

    // ── show() branch coverage ──

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testShowReturns404OnDoesNotExistException(): void
    {
        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show(999);

        $this->assertSame(404, $result->getStatus());
        $this->assertSame('Schema not found', $result->getData()['error']);
<<<<<<< HEAD
    }//end testShowReturns404OnDoesNotExistException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testShowReturns404OnValidationException(): void
    {
        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new ValidationException('Schema not found'));

        $result = $this->controller->show(999);

        $this->assertSame(404, $result->getStatus());
<<<<<<< HEAD
    }//end testShowReturns404OnValidationException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testShowReturns500OnGenericException(): void
    {
        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Unexpected error'));

        $result = $this->controller->show(1);

        $this->assertSame(500, $result->getStatus());
        $this->assertSame('Unexpected error', $result->getData()['error']);
<<<<<<< HEAD
    }//end testShowReturns500OnGenericException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testShowWithExtendStats(): void
    {
        $schema = $this->createRealSchema(1, 'Stats Schema');

        $this->request->method('getParam')
<<<<<<< HEAD
            ->willReturnMap(
                    [
                        ['_extend', [], ['@self.stats']],
                    ]
                    );
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('findExtendedBy')->willReturn([]);
        $this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([1 => 5]);
        $this->objectMapper->method('getStatistics')->willReturn(
                [
                    'total'     => 25,
                    'invalid'   => 0,
                    'deleted'   => 0,
                    'published' => 25,
                    'locked'    => 0,
                    'size'      => 5000,
                ]
                );
=======
            ->willReturnMap([
                ['_extend', [], ['@self.stats']],
            ]);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('findExtendedBy')->willReturn([]);
        $this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([1 => 5]);
        $this->objectMapper->method('getStatistics')->willReturn([
            'total' => 25, 'invalid' => 0, 'deleted' => 0,
            'published' => 25, 'locked' => 0, 'size' => 5000,
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->auditTrailMapper->method('getStatistics')->willReturn(['total' => 50, 'size' => 200]);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('stats', $data);
        $this->assertSame(5, $data['stats']['registers']);
<<<<<<< HEAD
    }//end testShowWithExtendStats()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testShowWithAllOfAddsPropertyMetadata(): void
    {
        $schema = $this->createRealSchema(1, 'Composed');
        $schema->setAllOf([['$ref' => '#/schemas/2']]);

        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('findExtendedBy')->willReturn([]);
<<<<<<< HEAD
        $this->schemaMapper->method('getPropertySourceMetadata')->willReturn(
                [
                    'field1' => ['source' => 'native'],
                ]
                );
=======
        $this->schemaMapper->method('getPropertySourceMetadata')->willReturn([
            'field1' => ['source' => 'native'],
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('propertyMetadata', $data['@self']);
<<<<<<< HEAD
    }//end testShowWithAllOfAddsPropertyMetadata()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testShowWithExtendAsString(): void
    {
        $schema = $this->createRealSchema(1, 'Test');

        $this->request->method('getParam')
<<<<<<< HEAD
            ->willReturnMap(
                    [
                        ['_extend', [], '@self.stats'],
                    ]
                    );
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('findExtendedBy')->willReturn([]);
        $this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([]);
        $this->objectMapper->method('getStatistics')->willReturn(
                [
                    'total'     => 0,
                    'invalid'   => 0,
                    'deleted'   => 0,
                    'published' => 0,
                    'locked'    => 0,
                    'size'      => 0,
                ]
                );
=======
            ->willReturnMap([
                ['_extend', [], '@self.stats'],
            ]);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('findExtendedBy')->willReturn([]);
        $this->schemaMapper->method('getRegisterCountPerSchema')->willReturn([]);
        $this->objectMapper->method('getStatistics')->willReturn([
            'total' => 0, 'invalid' => 0, 'deleted' => 0,
            'published' => 0, 'locked' => 0, 'size' => 0,
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->auditTrailMapper->method('getStatistics')->willReturn(['total' => 0, 'size' => 0]);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
        $this->assertArrayHasKey('stats', $result->getData());
<<<<<<< HEAD
    }//end testShowWithExtendAsString()

    // ── create() branch coverage ──
=======
    }

    // ── create() branch coverage ──

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testCreateReturnsErrorOnDBException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new DBException('Duplicate entry x for key schemas_organisation_slug_unique'));

        $result = $this->controller->create();

        $this->assertSame(409, $result->getStatus());
<<<<<<< HEAD
    }//end testCreateReturnsErrorOnDBException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testCreateReturnsErrorOnDatabaseConstraintException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new DatabaseConstraintException('Constraint error', 0, 409));

        $result = $this->controller->create();

        $this->assertSame(409, $result->getStatus());
        $this->assertSame('Constraint error', $result->getData()['error']);
<<<<<<< HEAD
    }//end testCreateReturnsErrorOnDatabaseConstraintException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testCreateReturns400OnValidationError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('Invalid field value'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
        $this->assertStringContainsString('Invalid', $result->getData()['error']);
<<<<<<< HEAD
    }//end testCreateReturns400OnValidationError()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testCreateReturns400OnMustBeError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('Field must be a string'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
<<<<<<< HEAD
    }//end testCreateReturns400OnMustBeError()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testCreateReturns400OnRequiredError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('Title is required'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
<<<<<<< HEAD
    }//end testCreateReturns400OnRequiredError()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testCreateReturns400OnFormatError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('Invalid format for date'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
<<<<<<< HEAD
    }//end testCreateReturns400OnFormatError()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testCreateReturns400OnPropertyAtError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('Property at /name is invalid'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
<<<<<<< HEAD
    }//end testCreateReturns400OnPropertyAtError()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testCreateReturns400OnAuthorizationError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('authorization group invalid'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
<<<<<<< HEAD
    }//end testCreateReturns400OnAuthorizationError()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testCreateReturns409OnConstraintError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('unique constraint violated'));

        $result = $this->controller->create();

        $this->assertSame(409, $result->getStatus());
<<<<<<< HEAD
    }//end testCreateReturns409OnConstraintError()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testCreateReturns409OnDuplicateError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('duplicate key value'));

        $result = $this->controller->create();

        $this->assertSame(409, $result->getStatus());
<<<<<<< HEAD
    }//end testCreateReturns409OnDuplicateError()

    // ── update() branch coverage ──
=======
    }

    // ── update() branch coverage ──

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testUpdateInvalidatesCaches(): void
    {
        $schema = $this->createRealSchema(1, 'Updated');

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

<<<<<<< HEAD
        // runtime-schema-api: update() now routes schema-cache cleanup through the
        // canonical invalidate() entry point (which itself covers the legacy
        // invalidateForSchemaChange cleanup plus the request-scoped mapper cache).
        $this->schemaCacheService->expects($this->once())
            ->method('invalidate')
            ->with(1);
=======
        $this->schemaCacheService->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'update');
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->facetCacheSvc->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'update');

        $result = $this->controller->update(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testUpdateInvalidatesCaches()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUpdateReturnsErrorOnDBException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')
            ->willThrowException(new DBException('Duplicate entry x for key unique'));

        $result = $this->controller->update(1);

        $this->assertSame(409, $result->getStatus());
<<<<<<< HEAD
    }//end testUpdateReturnsErrorOnDBException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUpdateReturnsErrorOnDatabaseConstraintException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')
            ->willThrowException(new DatabaseConstraintException('Constraint', 0, 409));

        $result = $this->controller->update(1);

        $this->assertSame(409, $result->getStatus());
<<<<<<< HEAD
    }//end testUpdateReturnsErrorOnDatabaseConstraintException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUpdateReturns400OnValidationError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')
            ->willThrowException(new Exception('Invalid field'));

        $result = $this->controller->update(1);

        $this->assertSame(400, $result->getStatus());
<<<<<<< HEAD
    }//end testUpdateReturns400OnValidationError()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUpdateReturns409OnConstraintError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')
            ->willThrowException(new Exception('duplicate key'));

        $result = $this->controller->update(1);

        $this->assertSame(409, $result->getStatus());
<<<<<<< HEAD
    }//end testUpdateReturns409OnConstraintError()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUpdateRemovesUnderscoreParams(): void
    {
        $schema = $this->createRealSchema(1, 'Updated');

<<<<<<< HEAD
        $this->request->method('getParams')->willReturn(
                [
                    '_route' => 'test',
                    '_limit' => '10',
                    'title'  => 'Updated',
                ]
                );
=======
        $this->request->method('getParams')->willReturn([
            '_route' => 'test',
            '_limit' => '10',
            'title' => 'Updated',
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->schemaMapper->expects($this->once())
            ->method('updateFromArray')
            ->with(
                $this->equalTo(1),
<<<<<<< HEAD
                $this->callback(
                        function ($data) {
                            return !isset($data['_route'])
                            && !isset($data['_limit'])
                            && isset($data['title']);
                        }
                        )
=======
                $this->callback(function ($data) {
                    return !isset($data['_route'])
                        && !isset($data['_limit'])
                        && isset($data['title']);
                })
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
            )
            ->willReturn($schema);

        $this->controller->update(1);
<<<<<<< HEAD
    }//end testUpdateRemovesUnderscoreParams()

    // ── destroy() branch coverage ──
=======
    }

    // ── destroy() branch coverage ──

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testDestroyInvalidatesCaches(): void
    {
        $schema = $this->createRealSchema(1, 'Deletable');
        $this->schemaMapper->method('find')->willReturn($schema);
<<<<<<< HEAD
        $this->objectMapper->method('getStatistics')->willReturn(['total' => 0]);

        // runtime-schema-api: destroy() now routes schema-cache cleanup through the
        // canonical invalidate() entry point.
        $this->schemaCacheService->expects($this->once())
            ->method('invalidate')
            ->with(1);
=======

        $this->schemaCacheService->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'delete');
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->facetCacheSvc->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'delete');

        $result = $this->controller->destroy(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testDestroyInvalidatesCaches()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testDestroyReturns409OnValidationException(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new ValidationException('Objects still attached'));

        $result = $this->controller->destroy(1);

        $this->assertSame(409, $result->getStatus());
        $this->assertStringContainsString('Objects still attached', $result->getData()['error']);
<<<<<<< HEAD
    }//end testDestroyReturns409OnValidationException()

    // ── stats() branch coverage ──
=======
    }

    // ── stats() branch coverage ──

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testStatsReturns500OnGenericException(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Database connection lost'));

        $result = $this->controller->stats(1);

        $this->assertSame(500, $result->getStatus());
        $this->assertSame('Database connection lost', $result->getData()['error']);
<<<<<<< HEAD
    }//end testStatsReturns500OnGenericException()

    // ── publish() branch coverage ──
=======
    }

    // ── publish() branch coverage ──

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testPublishWithCustomDate(): void
    {
        $schema = $this->createRealSchema(1, 'Publishable');

        $this->request->method('getParam')
<<<<<<< HEAD
            ->willReturnMap(
                    [
                        ['date', null, '2025-06-15'],
                    ]
                    );
=======
            ->willReturnMap([
                ['date', null, '2025-06-15'],
            ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->publish(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testPublishWithCustomDate()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testPublishReturns400OnGenericException(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Unexpected error'));

        $result = $this->controller->publish(1);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame('Unexpected error', $result->getData()['error']);
<<<<<<< HEAD
    }//end testPublishReturns400OnGenericException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testPublishInvalidatesCaches(): void
    {
        $schema = $this->createRealSchema(1, 'Publishable');

        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        $this->schemaCacheService->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'publish');
        $this->facetCacheSvc->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'publish');

        $this->controller->publish(1);
<<<<<<< HEAD
    }//end testPublishInvalidatesCaches()

    // ── depublish() branch coverage ──
=======
    }

    // ── depublish() branch coverage ──

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testDepublishWithCustomDate(): void
    {
        $schema = $this->createRealSchema(1, 'Depublishable');

        $this->request->method('getParam')
<<<<<<< HEAD
            ->willReturnMap(
                    [
                        ['date', null, '2025-12-31'],
                    ]
                    );
=======
            ->willReturnMap([
                ['date', null, '2025-12-31'],
            ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->depublish(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testDepublishWithCustomDate()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testDepublishReturns400OnGenericException(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Update failed'));

        $result = $this->controller->depublish(1);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame('Update failed', $result->getData()['error']);
<<<<<<< HEAD
    }//end testDepublishReturns400OnGenericException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testDepublishInvalidatesCaches(): void
    {
        $schema = $this->createRealSchema(1, 'Depublishable');

        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        $this->schemaCacheService->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'depublish');
        $this->facetCacheSvc->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'depublish');

        $this->controller->depublish(1);
<<<<<<< HEAD
    }//end testDepublishInvalidatesCaches()

    // ── upload() / uploadUpdate() coverage ──
=======
    }

    // ── upload() / uploadUpdate() coverage ──

>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
    public function testUploadUpdateDelegatesToUpload(): void
    {
        $schema = $this->createRealSchema(1, 'Existing');

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Updated']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->uploadUpdate(1);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testUploadUpdateDelegatesToUpload()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUploadNewSchemaWithoutId(): void
    {
        $schema = $this->createRealSchema(1, 'New Schema');

        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'New Schema']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn('org-uuid');

        $result = $this->controller->upload(null);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testUploadNewSchemaWithoutId()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUploadReturnsErrorResponseFromUploadService(): void
    {
        $errorResponse = new JSONResponse(['error' => 'Invalid JSON'], 400);

        $this->uploadService->method('getUploadedJson')->willReturn($errorResponse);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->upload(null);

        $this->assertSame(400, $result->getStatus());
<<<<<<< HEAD
    }//end testUploadReturnsErrorResponseFromUploadService()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUploadNewSchemaWithEmptyTitle(): void
    {
        $schema = $this->createRealSchema(1, 'New Schema');

        $this->uploadService->method('getUploadedJson')->willReturn(['title' => '']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn('org-uuid');

        $result = $this->controller->upload(null);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testUploadNewSchemaWithEmptyTitle()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUploadExistingSchemaById(): void
    {
        $schema = $this->createRealSchema(5, 'Existing');

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Updated via upload']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->upload(5);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testUploadExistingSchemaById()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUploadReturns500OnGenericException(): void
    {
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')
            ->willThrowException(new Exception('Unexpected insert error'));

        $result = $this->controller->upload(null);

        $this->assertSame(500, $result->getStatus());
<<<<<<< HEAD
    }//end testUploadReturns500OnGenericException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUploadReturns400OnValidationException(): void
    {
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')
            ->willThrowException(new Exception('Invalid property value'));

        $result = $this->controller->upload(null);

        $this->assertSame(400, $result->getStatus());
<<<<<<< HEAD
    }//end testUploadReturns400OnValidationException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUploadReturns409OnConstraintException(): void
    {
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')
            ->willThrowException(new Exception('duplicate key'));

        $result = $this->controller->upload(null);

        $this->assertSame(409, $result->getStatus());
<<<<<<< HEAD
    }//end testUploadReturns409OnConstraintException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUploadReturnsErrorOnDBException(): void
    {
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')
            ->willThrowException(new DBException('Duplicate entry x for key unique'));

        $result = $this->controller->upload(null);

        $this->assertSame(409, $result->getStatus());
<<<<<<< HEAD
    }//end testUploadReturnsErrorOnDBException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUploadReturnsErrorOnDatabaseConstraintException(): void
    {
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')
            ->willThrowException(new DatabaseConstraintException('Constraint', 0, 409));

        $result = $this->controller->upload(null);

        $this->assertSame(409, $result->getStatus());
<<<<<<< HEAD
    }//end testUploadReturnsErrorOnDatabaseConstraintException()
=======
    }
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

    public function testUploadNewSchemaWithOrganisationAlreadySet(): void
    {
        $schema = $this->createRealSchema(1, 'New Schema');
        $schema->setOrganisation('existing-org-uuid');

        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'New']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')->willReturn($schema);

        // update should NOT be called for organisation assignment since org is already set
        $this->schemaCacheService->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'create');

        $result = $this->controller->upload(null);

        $this->assertSame(200, $result->getStatus());
<<<<<<< HEAD
    }//end testUploadNewSchemaWithOrganisationAlreadySet()

    // ── related() additional coverage ──
    public function testRelatedWithOutgoingReferences(): void
    {
        $schema1 = $this->createRealSchema(1, 'Source');
        $schema1->setProperties(
                [
                    'ref_field' => ['$ref' => '#/schemas/2'],
                ]
                );
=======
    }

    // ── related() additional coverage ──

    public function testRelatedWithOutgoingReferences(): void
    {
        $schema1 = $this->createRealSchema(1, 'Source');
        $schema1->setProperties([
            'ref_field' => ['$ref' => '#/schemas/2'],
        ]);
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $schema2 = $this->createRealSchema(2, 'Target');
        $schema2->setUuid('target-uuid');
        $schema2->setSlug('target-slug');

        $this->schemaMapper->method('getRelated')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema1);
        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);
        $this->schemaMapper->method('hasReferenceToSchema')
<<<<<<< HEAD
            ->willReturnCallback(
                    function ($properties, $targetSchemaId) {
                        return $targetSchemaId === '2';
                    }
                    );
=======
            ->willReturnCallback(function ($properties, $targetSchemaId) {
                return $targetSchemaId === '2';
            });
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773

        $result = $this->controller->related(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data['outgoing']);
        $this->assertSame(1, $data['total']);
<<<<<<< HEAD
    }//end testRelatedWithOutgoingReferences()

    // ---------------------------------------------------------------------
    // Metadata-read bypass policy — auth-system requirement
    // "Schema and register METADATA-READ lookups MUST bypass multi-tenancy;
    // metadata WRITE lookups MUST enforce it".
    //
    // The five read-path methods below MUST pass `_multitenancy: false` to
    // SchemaMapper::find / findAll. The three mutation-path methods after
    // them MUST keep the safe default (`_multitenancy: true`).
    //
    // openspec/changes/aggregation-runner-multitenancy-policy/specs/auth-system/spec.md
    // ---------------------------------------------------------------------

    /**
     * SchemaMapper::find positional signature is
     * (id, _extend=[], published=null, _rbac=true, _multitenancy=true).
     * These matchers pin the 5th arg.
     */
    private function readBypassWithMatchers(string|int $id): array
    {
        return [
            $this->equalTo($id),
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->isFalse(),
        ];
    }//end readBypassWithMatchers()

    private function mutationDefaultWithMatchers(string|int $id): array
    {
        return [
            $this->equalTo($id),
            $this->anything(),
            $this->anything(),
            $this->anything(),
            $this->isTrue(),
        ];
    }//end mutationDefaultWithMatchers()

    public function testDownloadPassesMultitenancyFalseToFind(): void
    {
        $schema = $this->createRealSchema(1, 'Downloadable');
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(...$this->readBypassWithMatchers(1))
            ->willReturn($schema);

        $this->controller->download(1);
    }//end testDownloadPassesMultitenancyFalseToFind()

    public function testRelatedPassesMultitenancyFalseToFindAndFindAll(): void
    {
        $target = $this->createRealSchema(1, 'Target');
        $target->setProperties([]);
        $this->schemaMapper->method('getRelated')->willReturn([]);
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(...$this->readBypassWithMatchers(1))
            ->willReturn($target);
        // SchemaMapper::findAll signature:
        // (limit, offset, filters, searchConditions, searchParams, _extend,
        //  published, _rbac, _multitenancy).
        // _multitenancy is the 9th positional arg, not the 7th.
        $this->schemaMapper->expects($this->once())
            ->method('findAll')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->isFalse()
            )
            ->willReturn([]);

        $this->controller->related(1);
    }//end testRelatedPassesMultitenancyFalseToFindAndFindAll()

    public function testStatsPassesMultitenancyFalseToFind(): void
    {
        $schema = $this->createRealSchema(1, 'StatsSchema');
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(...$this->readBypassWithMatchers(1))
            ->willReturn($schema);
        // stats() does follow-up work that may throw on missing fixtures;
        // the assertion on the find() mock fires before that, which is all
        // we care about for the policy lock-in.
        try {
            $this->controller->stats(1);
        } catch (\Throwable $ignored) {
            // Intentional — see comment above.
        }
    }//end testStatsPassesMultitenancyFalseToFind()

    public function testPublishPassesMultitenancyFalseToFind(): void
    {
        $schema = $this->createRealSchema(1, 'Publishable');
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(...$this->readBypassWithMatchers(1))
            ->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        try {
            $this->controller->publish(1);
        } catch (\Throwable $ignored) {
            // see testStatsPassesMultitenancyFalseToFind
        }
    }//end testPublishPassesMultitenancyFalseToFind()

    public function testDepublishPassesMultitenancyFalseToFind(): void
    {
        $schema = $this->createRealSchema(1, 'Depublishable');
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(...$this->readBypassWithMatchers(1))
            ->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        try {
            $this->controller->depublish(1);
        } catch (\Throwable $ignored) {
            // see testStatsPassesMultitenancyFalseToFind
        }
    }//end testDepublishPassesMultitenancyFalseToFind()

    public function testUpdatePassesMultitenancyDefaultTrueToFind(): void
    {
        $schema = $this->createRealSchema(1, 'Updatable');
        $this->request->method('getParam')->willReturn(null);
        $this->request->method('getParams')->willReturn(['id' => 1]);
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(...$this->mutationDefaultWithMatchers(1))
            ->willReturn($schema);

        try {
            $this->controller->update(1);
        } catch (\Throwable $ignored) {
            // see testStatsPassesMultitenancyFalseToFind — assertion on find()
            // happens before the mutation logic that needs richer fixtures.
        }
    }//end testUpdatePassesMultitenancyDefaultTrueToFind()

    public function testDestroyPassesMultitenancyDefaultTrueToFind(): void
    {
        $schema = $this->createRealSchema(1, 'Deletable');
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(...$this->mutationDefaultWithMatchers(1))
            ->willReturn($schema);

        try {
            $this->controller->destroy(1);
        } catch (\Throwable $ignored) {
            // see testStatsPassesMultitenancyFalseToFind
        }
    }//end testDestroyPassesMultitenancyDefaultTrueToFind()

    public function testUploadPassesMultitenancyDefaultTrueToFind(): void
    {
        $schema = $this->createRealSchema(1, 'Uploadable');
        $this->request->method('getParam')->willReturn(null);
        $this->request->method('getParams')->willReturn(['id' => 1]);
        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(...$this->mutationDefaultWithMatchers(1))
            ->willReturn($schema);
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'X']);

        try {
            $this->controller->upload(1);
        } catch (\Throwable $ignored) {
            // see testStatsPassesMultitenancyFalseToFind
        }
    }//end testUploadPassesMultitenancyDefaultTrueToFind()
}//end class
=======
    }
}
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
