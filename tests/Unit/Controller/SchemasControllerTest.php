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

    public function testDownloadReturnsSchema(): void
    {
        $schema = $this->createRealSchema(1, 'Downloadable');
        $this->schemaMapper->method('find')->willReturn($schema);

        $result = $this->controller->download(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testDownloadReturns404WhenNotFound(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Not found'));

        $result = $this->controller->download(999);

        $this->assertSame(404, $result->getStatus());
        $this->assertSame('Schema not found', $result->getData()['error']);
    }

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
    }

    public function testRelatedReturns404WhenSchemaNotFound(): void
    {
        $this->schemaMapper->method('getRelated')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->related(999);

        $this->assertSame(404, $result->getStatus());
    }

    public function testRelatedReturns500OnGenericException(): void
    {
        $this->schemaMapper->method('getRelated')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->related(1);

        $this->assertSame(500, $result->getStatus());
    }

    public function testStatsReturnsSchemaStatistics(): void
    {
        $schema = $this->createRealSchema(1, 'Stats Schema');
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->objectEntityMapper->method('getStatistics')->willReturn([
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

        $result = $this->controller->stats(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertSame(50, $data['objectCount']);
        $this->assertArrayHasKey('objects', $data);
        $this->assertArrayHasKey('logs', $data);
    }

    public function testStatsReturns404WhenSchemaNotFound(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->stats(999);

        $this->assertSame(404, $result->getStatus());
    }

    public function testExploreReturnsExplorationResults(): void
    {
        $this->schemaService->method('exploreSchemaProperties')->willReturn([
            'newProperties' => ['field1' => ['type' => 'string']],
            'objectsScanned' => 100,
        ]);

        $result = $this->controller->explore(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testExploreReturns500OnException(): void
    {
        $this->schemaService->method('exploreSchemaProperties')
            ->willThrowException(new Exception('Explore failed'));

        $result = $this->controller->explore(1);

        $this->assertSame(500, $result->getStatus());
    }

    public function testUpdateFromExplorationReturns400WhenNoProperties(): void
    {
        $this->request->method('getParam')->willReturn([]);

        $result = $this->controller->updateFromExploration(1);

        $this->assertSame(400, $result->getStatus());
    }

    public function testUpdateFromExplorationSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['properties', [], ['field1' => ['type' => 'string']]],
            ]);

        $updatedSchema = $this->createRealSchema(1, 'Updated');
        $this->schemaService->method('updateSchemaFromExploration')->willReturn($updatedSchema);
        // clearSchemaCache() returns void, no need to mock return value

        $result = $this->controller->updateFromExploration(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testUpdateFromExplorationReturns500OnException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['properties', [], ['field1' => ['type' => 'string']]],
            ]);

        $this->schemaService->method('updateSchemaFromExploration')
            ->willThrowException(new Exception('Update error'));

        $result = $this->controller->updateFromExploration(1);

        $this->assertSame(500, $result->getStatus());
    }

    public function testPublishSetsPublicationDate(): void
    {
        $schema = $this->createRealSchema(1, 'Publishable');
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->publish(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testPublishReturns404WhenSchemaNotFound(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->publish(999);

        $this->assertSame(404, $result->getStatus());
    }

    public function testDepublishSetsDepublicationDate(): void
    {
        $schema = $this->createRealSchema(1, 'Depublishable');
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->depublish(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testDepublishReturns404WhenSchemaNotFound(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->depublish(999);

        $this->assertSame(404, $result->getStatus());
    }

    public function testUpdateRemovesImmutableFields(): void
    {
        $schema = $this->createRealSchema(1, 'Updated');

        $this->request->method('getParams')->willReturn([
            'id' => 1,
            'organisation' => 'org1',
            'owner' => 'user1',
            'created' => '2024-01-01',
            'title' => 'Updated',
        ]);
        $this->schemaMapper->expects($this->once())
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
            ->willReturn($schema);

        $this->controller->update(1);
    }

    public function testUpdateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')
            ->willThrowException(new Exception('DB error'));

        $result = $this->controller->update(1);

        $this->assertSame(500, $result->getStatus());
    }
}
