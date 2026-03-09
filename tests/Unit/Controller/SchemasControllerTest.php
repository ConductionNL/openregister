<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DownloadService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Schemas\FacetCacheHandler;
use OCA\OpenRegister\Service\Schemas\SchemaCacheHandler;
use OCA\OpenRegister\Service\SchemaService;
use OCA\OpenRegister\Service\UploadService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SchemasController
 *
 * @package Unit\Controller
 */
class SchemasControllerTest extends TestCase
{
    private SchemasController $controller;
    private IRequest&MockObject $request;
    private IAppConfig&MockObject $config;
    private SchemaMapper&MockObject $schemaMapper;
    private ObjectEntityMapper&MockObject $objectEntityMapper;
    private DownloadService&MockObject $downloadService;
    private UploadService&MockObject $uploadService;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private OrganisationService&MockObject $organisationService;
    private SchemaCacheHandler&MockObject $schemaCacheService;
    private FacetCacheHandler&MockObject $facetCacheSvc;
    private SchemaService&MockObject $schemaService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->downloadService = $this->createMock(DownloadService::class);
        $this->uploadService = $this->createMock(UploadService::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheHandler::class);
        $this->facetCacheSvc = $this->createMock(FacetCacheHandler::class);
        $this->schemaService = $this->createMock(SchemaService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new SchemasController(
            'openregister',
            $this->request,
            $this->config,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->downloadService,
            $this->uploadService,
            $this->auditTrailMapper,
            $this->organisationService,
            $this->schemaCacheService,
            $this->facetCacheSvc,
            $this->schemaService,
            $this->logger
        );
    }

    public function testIndexReturnsSchemas(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $result = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexWithPagination(): void
    {
        $this->request->method('getParams')->willReturn([
            '_limit' => '5',
            '_offset' => '10',
        ]);
        $this->schemaMapper->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
    }

    public function testShowReturnsSchema(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testCreateReturnsCreatedSchema(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'New Schema']);

        $this->request->method('getParams')->willReturn(['title' => 'New Schema']);
        $this->schemaMapper->method('createFromArray')->willReturn($schema);

        $result = $this->controller->create();

        $this->assertSame(201, $result->getStatus());
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

    public function testCreateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->create();

        $this->assertSame(500, $result->getStatus());
    }

    private function createRealSchema(int $id = 1, string $title = 'Test'): Schema
    {
        $schema = new Schema();
        $ref = new \ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        $schema->setTitle($title);
        return $schema;
    }

    public function testUpdateReturnsUpdatedSchema(): void
    {
        $schema = $this->createRealSchema(1, 'Updated');

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $result = $this->controller->update(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testPatchDelegatesToUpdate(): void
    {
        $schema = $this->createRealSchema(1, 'Patched');

        $this->request->method('getParams')->willReturn(['title' => 'Patched']);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $result = $this->controller->patch(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testDestroyReturnsEmptyOnSuccess(): void
    {
        $schema = $this->createRealSchema(1, 'Test');
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->controller->destroy(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testDestroyReturns500WhenNotFound(): void
    {
        // SchemasController::destroy() catches \Exception (not DoesNotExistException
        // specifically), so DoesNotExistException results in 500 not 404.
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertSame(500, $result->getStatus());
    }
}
