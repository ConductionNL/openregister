<?php

declare(strict_types=1);

namespace Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DownloadService;
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
    private UnifiedObjectMapper&MockObject $objectMapper;
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
        $this->objectMapper = $this->createMock(UnifiedObjectMapper::class);
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
            $this->objectMapper,
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

    // ── index() branch coverage ──

    public function testIndexWithPageBasedPagination(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

        $this->request->method('getParams')->willReturn([
            '_limit' => '10',
            '_page' => '3',
        ]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
    }

    public function testIndexWithExtendStats(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Test']);

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

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('stats', $data['results'][0]);
        $this->assertSame(10, $data['results'][0]['stats']['objects']['total']);
        $this->assertSame(2, $data['results'][0]['stats']['registers']);
    }

    public function testIndexWithExtendStatsDefaultsForMissingSchema(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 99, 'title' => 'Orphan']);

        $this->request->method('getParams')->willReturn([
            '_extend' => ['@self.stats'],
        ]);
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
    }

    public function testIndexWithFilters(): void
    {
        $this->request->method('getParams')->willReturn([
            'filters' => ['title' => 'Test'],
        ]);
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->schemaMapper->method('findAllExtendedBy')->willReturn([]);

        $result = $this->controller->index();

        $this->assertSame(200, $result->getStatus());
    }

    public function testIndexExtendedByPopulated(): void
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('jsonSerialize')->willReturn(['id' => 1, 'title' => 'Base']);

        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->schemaMapper->method('findAllExtendedBy')->willReturn([
            1 => ['uuid-child-1', 'uuid-child-2'],
        ]);

        $result = $this->controller->index();

        $data = $result->getData();
        $this->assertSame(['uuid-child-1', 'uuid-child-2'], $data['results'][0]['@self']['extendedBy']);
    }

    // ── show() branch coverage ──

    public function testShowReturns404OnDoesNotExistException(): void
    {
        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show(999);

        $this->assertSame(404, $result->getStatus());
        $this->assertSame('Schema not found', $result->getData()['error']);
    }

    public function testShowReturns404OnValidationException(): void
    {
        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new ValidationException('Schema not found'));

        $result = $this->controller->show(999);

        $this->assertSame(404, $result->getStatus());
    }

    public function testShowReturns500OnGenericException(): void
    {
        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Unexpected error'));

        $result = $this->controller->show(1);

        $this->assertSame(500, $result->getStatus());
        $this->assertSame('Unexpected error', $result->getData()['error']);
    }

    public function testShowWithExtendStats(): void
    {
        $schema = $this->createRealSchema(1, 'Stats Schema');

        $this->request->method('getParam')
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
        $this->auditTrailMapper->method('getStatistics')->willReturn(['total' => 50, 'size' => 200]);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('stats', $data);
        $this->assertSame(5, $data['stats']['registers']);
    }

    public function testShowWithAllOfAddsPropertyMetadata(): void
    {
        $schema = $this->createRealSchema(1, 'Composed');
        $schema->setAllOf([['$ref' => '#/schemas/2']]);

        $this->request->method('getParam')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('findExtendedBy')->willReturn([]);
        $this->schemaMapper->method('getPropertySourceMetadata')->willReturn([
            'field1' => ['source' => 'native'],
        ]);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('propertyMetadata', $data['@self']);
    }

    public function testShowWithExtendAsString(): void
    {
        $schema = $this->createRealSchema(1, 'Test');

        $this->request->method('getParam')
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
        $this->auditTrailMapper->method('getStatistics')->willReturn(['total' => 0, 'size' => 0]);

        $result = $this->controller->show(1);

        $this->assertSame(200, $result->getStatus());
        $this->assertArrayHasKey('stats', $result->getData());
    }

    // ── create() branch coverage ──

    public function testCreateReturnsErrorOnDBException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new DBException('Duplicate entry x for key schemas_organisation_slug_unique'));

        $result = $this->controller->create();

        $this->assertSame(409, $result->getStatus());
    }

    public function testCreateReturnsErrorOnDatabaseConstraintException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new DatabaseConstraintException('Constraint error', 0, 409));

        $result = $this->controller->create();

        $this->assertSame(409, $result->getStatus());
        $this->assertSame('Constraint error', $result->getData()['error']);
    }

    public function testCreateReturns400OnValidationError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('Invalid field value'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
        $this->assertStringContainsString('Invalid', $result->getData()['error']);
    }

    public function testCreateReturns400OnMustBeError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('Field must be a string'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
    }

    public function testCreateReturns400OnRequiredError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('Title is required'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
    }

    public function testCreateReturns400OnFormatError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('Invalid format for date'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
    }

    public function testCreateReturns400OnPropertyAtError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('Property at /name is invalid'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
    }

    public function testCreateReturns400OnAuthorizationError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('authorization group invalid'));

        $result = $this->controller->create();

        $this->assertSame(400, $result->getStatus());
    }

    public function testCreateReturns409OnConstraintError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('unique constraint violated'));

        $result = $this->controller->create();

        $this->assertSame(409, $result->getStatus());
    }

    public function testCreateReturns409OnDuplicateError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Test']);
        $this->schemaMapper->method('createFromArray')
            ->willThrowException(new Exception('duplicate key value'));

        $result = $this->controller->create();

        $this->assertSame(409, $result->getStatus());
    }

    // ── update() branch coverage ──

    public function testUpdateInvalidatesCaches(): void
    {
        $schema = $this->createRealSchema(1, 'Updated');

        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')->willReturn($schema);

        $this->schemaCacheService->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'update');
        $this->facetCacheSvc->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'update');

        $result = $this->controller->update(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdateReturnsErrorOnDBException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')
            ->willThrowException(new DBException('Duplicate entry x for key unique'));

        $result = $this->controller->update(1);

        $this->assertSame(409, $result->getStatus());
    }

    public function testUpdateReturnsErrorOnDatabaseConstraintException(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')
            ->willThrowException(new DatabaseConstraintException('Constraint', 0, 409));

        $result = $this->controller->update(1);

        $this->assertSame(409, $result->getStatus());
    }

    public function testUpdateReturns400OnValidationError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')
            ->willThrowException(new Exception('Invalid field'));

        $result = $this->controller->update(1);

        $this->assertSame(400, $result->getStatus());
    }

    public function testUpdateReturns409OnConstraintError(): void
    {
        $this->request->method('getParams')->willReturn(['title' => 'Updated']);
        $this->schemaMapper->method('updateFromArray')
            ->willThrowException(new Exception('duplicate key'));

        $result = $this->controller->update(1);

        $this->assertSame(409, $result->getStatus());
    }

    public function testUpdateRemovesUnderscoreParams(): void
    {
        $schema = $this->createRealSchema(1, 'Updated');

        $this->request->method('getParams')->willReturn([
            '_route' => 'test',
            '_limit' => '10',
            'title' => 'Updated',
        ]);
        $this->schemaMapper->expects($this->once())
            ->method('updateFromArray')
            ->with(
                $this->equalTo(1),
                $this->callback(function ($data) {
                    return !isset($data['_route'])
                        && !isset($data['_limit'])
                        && isset($data['title']);
                })
            )
            ->willReturn($schema);

        $this->controller->update(1);
    }

    // ── destroy() branch coverage ──

    public function testDestroyInvalidatesCaches(): void
    {
        $schema = $this->createRealSchema(1, 'Deletable');
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->schemaCacheService->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'delete');
        $this->facetCacheSvc->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with(1, 'delete');

        $result = $this->controller->destroy(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testDestroyReturns409OnValidationException(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new ValidationException('Objects still attached'));

        $result = $this->controller->destroy(1);

        $this->assertSame(409, $result->getStatus());
        $this->assertStringContainsString('Objects still attached', $result->getData()['error']);
    }

    // ── stats() branch coverage ──

    public function testStatsReturns500OnGenericException(): void
    {
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Database connection lost'));

        $result = $this->controller->stats(1);

        $this->assertSame(500, $result->getStatus());
        $this->assertSame('Database connection lost', $result->getData()['error']);
    }

    // ── publish() branch coverage ──

    public function testPublishWithCustomDate(): void
    {
        $schema = $this->createRealSchema(1, 'Publishable');

        $this->request->method('getParam')
            ->willReturnMap([
                ['date', null, '2025-06-15'],
            ]);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->publish(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testPublishReturns400OnGenericException(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Unexpected error'));

        $result = $this->controller->publish(1);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame('Unexpected error', $result->getData()['error']);
    }

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
    }

    // ── depublish() branch coverage ──

    public function testDepublishWithCustomDate(): void
    {
        $schema = $this->createRealSchema(1, 'Depublishable');

        $this->request->method('getParam')
            ->willReturnMap([
                ['date', null, '2025-12-31'],
            ]);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->depublish(1);

        $this->assertSame(200, $result->getStatus());
    }

    public function testDepublishReturns400OnGenericException(): void
    {
        $this->request->method('getParam')->willReturn(null);
        $this->schemaMapper->method('find')
            ->willThrowException(new Exception('Update failed'));

        $result = $this->controller->depublish(1);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame('Update failed', $result->getData()['error']);
    }

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
    }

    // ── upload() / uploadUpdate() coverage ──

    public function testUploadUpdateDelegatesToUpload(): void
    {
        $schema = $this->createRealSchema(1, 'Existing');

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Updated']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->uploadUpdate(1);

        $this->assertSame(200, $result->getStatus());
    }

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
    }

    public function testUploadReturnsErrorResponseFromUploadService(): void
    {
        $errorResponse = new JSONResponse(['error' => 'Invalid JSON'], 400);

        $this->uploadService->method('getUploadedJson')->willReturn($errorResponse);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->upload(null);

        $this->assertSame(400, $result->getStatus());
    }

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
    }

    public function testUploadExistingSchemaById(): void
    {
        $schema = $this->createRealSchema(5, 'Existing');

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Updated via upload']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('update')->willReturn($schema);

        $result = $this->controller->upload(5);

        $this->assertSame(200, $result->getStatus());
    }

    public function testUploadReturns500OnGenericException(): void
    {
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')
            ->willThrowException(new Exception('Unexpected insert error'));

        $result = $this->controller->upload(null);

        $this->assertSame(500, $result->getStatus());
    }

    public function testUploadReturns400OnValidationException(): void
    {
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')
            ->willThrowException(new Exception('Invalid property value'));

        $result = $this->controller->upload(null);

        $this->assertSame(400, $result->getStatus());
    }

    public function testUploadReturns409OnConstraintException(): void
    {
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')
            ->willThrowException(new Exception('duplicate key'));

        $result = $this->controller->upload(null);

        $this->assertSame(409, $result->getStatus());
    }

    public function testUploadReturnsErrorOnDBException(): void
    {
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')
            ->willThrowException(new DBException('Duplicate entry x for key unique'));

        $result = $this->controller->upload(null);

        $this->assertSame(409, $result->getStatus());
    }

    public function testUploadReturnsErrorOnDatabaseConstraintException(): void
    {
        $this->uploadService->method('getUploadedJson')->willReturn(['title' => 'Test']);
        $this->request->method('getParams')->willReturn([]);
        $this->schemaMapper->method('insert')
            ->willThrowException(new DatabaseConstraintException('Constraint', 0, 409));

        $result = $this->controller->upload(null);

        $this->assertSame(409, $result->getStatus());
    }

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
    }

    // ── related() additional coverage ──

    public function testRelatedWithOutgoingReferences(): void
    {
        $schema1 = $this->createRealSchema(1, 'Source');
        $schema1->setProperties([
            'ref_field' => ['$ref' => '#/schemas/2'],
        ]);

        $schema2 = $this->createRealSchema(2, 'Target');
        $schema2->setUuid('target-uuid');
        $schema2->setSlug('target-slug');

        $this->schemaMapper->method('getRelated')->willReturn([]);
        $this->schemaMapper->method('find')->willReturn($schema1);
        $this->schemaMapper->method('findAll')->willReturn([$schema1, $schema2]);
        $this->schemaMapper->method('hasReferenceToSchema')
            ->willReturnCallback(function ($properties, $targetSchemaId) {
                return $targetSchemaId === '2';
            });

        $result = $this->controller->related(1);

        $this->assertSame(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data['outgoing']);
        $this->assertSame(1, $data['total']);
    }
}
