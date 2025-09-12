<?php

declare(strict_types=1);

/**
 * SchemasControllerTest
 * 
 * Unit tests for the SchemasController
 *
 * @category   Test
 * @package    OCA\OpenRegister\Tests\Unit\Controller
 * @author     Conduction.nl <info@conduction.nl>
 * @copyright  Conduction.nl 2024
 * @license    EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version    1.0.0
 * @link       https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SchemasController;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\DownloadService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SearchService;
use OCA\OpenRegister\Service\UploadService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DBException;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the SchemasController
 *
 * This test class covers all functionality of the SchemasController
 * including CRUD operations and schema management.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class SchemasControllerTest extends TestCase
{
    /**
     * The SchemasController instance being tested
     *
     * @var SchemasController
     */
    private SchemasController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock app config
     *
     * @var MockObject|IAppConfig
     */
    private MockObject $config;

    /**
     * Mock schema mapper
     *
     * @var MockObject|SchemaMapper
     */
    private MockObject $schemaMapper;

    /**
     * Mock object entity mapper
     *
     * @var MockObject|ObjectEntityMapper
     */
    private MockObject $objectEntityMapper;

    /**
     * Mock download service
     *
     * @var MockObject|DownloadService
     */
    private MockObject $downloadService;

    /**
     * Mock object service
     *
     * @var MockObject|ObjectService
     */
    private MockObject $objectService;

    /**
     * Mock organisation service
     *
     * @var MockObject|OrganisationService
     */
    private MockObject $organisationService;

    /**
     * Mock search service
     *
     * @var MockObject|SearchService
     */
    private MockObject $searchService;

    /**
     * Mock upload service
     *
     * @var MockObject|UploadService
     */
    private MockObject $uploadService;

    /**
     * Mock audit trail mapper
     *
     * @var MockObject|AuditTrailMapper
     */
    private MockObject $auditTrailMapper;

    /**
     * Set up test environment before each test
     *
     * This method initializes all mocks and the controller instance
     * for testing purposes.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create mock objects for all dependencies
        $this->request = $this->createMock(IRequest::class);
        $this->config = $this->createMock(IAppConfig::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectEntityMapper = $this->createMock(ObjectEntityMapper::class);
        $this->downloadService = $this->createMock(DownloadService::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->searchService = $this->createMock(SearchService::class);
        $this->uploadService = $this->createMock(UploadService::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new SchemasController(
            'openregister',
            $this->request,
            $this->config,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->downloadService,
            $this->uploadService,
            $this->auditTrailMapper,
            $this->organisationService
        );
    }

    /**
     * Test page method returns TemplateResponse
     *
     * @return void
     */
    public function testPageReturnsTemplateResponse(): void
    {
        $response = $this->controller->page();

        $this->assertInstanceOf(TemplateResponse::class, $response);
        $this->assertEquals('index', $response->getTemplateName());
        $this->assertEquals([], $response->getParams());
    }

    /**
     * Test index method with successful schema listing
     *
     * @return void
     */
    public function testIndexSuccessful(): void
    {
        $schema1 = $this->createMock(Schema::class);
        $schema2 = $this->createMock(Schema::class);
        $schemas = [$schema1, $schema2];

        $schema1->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Schema 1']);

        $schema2->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 2, 'name' => 'Schema 2']);

        $this->request->expects($this->exactly(3))
            ->method('getParam')
            ->willReturnMap([
                ['filters', [], []],
                ['_search', '', ''],
                ['_extend', [], []]
            ]);

        $this->schemaMapper->expects($this->once())
            ->method('findAll')
            ->with(null, null, [], [], [], [])
            ->willReturn($schemas);

        $response = $this->controller->index($this->objectService, $this->searchService);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(2, $data['results']);
    }

    /**
     * Test show method with successful schema retrieval
     *
     * @return void
     */
    public function testShowSuccessful(): void
    {
        $id = 1;
        $schema = $this->createMock(Schema::class);

        $schema->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Test Schema']);

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('_extend', [])
            ->willReturn([]);

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($id, [])
            ->willReturn($schema);

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['id' => 1, 'name' => 'Test Schema'], $response->getData());
    }

    /**
     * Test show method with schema not found
     *
     * @return void
     */
    public function testShowSchemaNotFound(): void
    {
        $id = 999;

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('_extend', [])
            ->willReturn([]);

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($id, [])
            ->willThrowException(new DoesNotExistException('Schema not found'));

        $this->expectException(DoesNotExistException::class);
        $this->expectExceptionMessage('Schema not found');
        
        $this->controller->show($id);
    }

    /**
     * Test create method with successful schema creation
     *
     * @return void
     */
    public function testCreateSuccessful(): void
    {
        $data = ['name' => 'New Schema', 'description' => 'Test description'];
        $createdSchema = ['id' => 1, 'name' => 'New Schema'];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->with($data)
            ->willReturn($createdSchema);

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($createdSchema, $response->getData());
    }

    /**
     * Test create method with database constraint exception
     *
     * @return void
     */
    public function testCreateWithDatabaseConstraintException(): void
    {
        $data = ['name' => 'Duplicate Schema'];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $dbException = new DBException('Duplicate entry', 1062);
        $constraintException = DatabaseConstraintException::fromDatabaseException($dbException, 'schema');

        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->willThrowException($dbException);

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($constraintException->getHttpStatusCode(), $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    /**
     * Test update method with successful schema update
     *
     * @return void
     */
    public function testUpdateSuccessful(): void
    {
        $id = 1;
        $data = ['name' => 'Updated Schema'];
        $updatedSchema = ['id' => 1, 'name' => 'Updated Schema'];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->schemaMapper->expects($this->once())
            ->method('updateFromArray')
            ->with($id, $data)
            ->willReturn($updatedSchema);

        $response = $this->controller->update($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($updatedSchema, $response->getData());
    }

    /**
     * Test destroy method with successful schema deletion
     *
     * @return void
     */
    public function testDestroySuccessful(): void
    {
        $id = 1;
        $schema = $this->createMock(Schema::class);

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($schema);

        $this->schemaMapper->expects($this->once())
            ->method('delete')
            ->with($schema);

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());
    }

    /**
     * Test destroy method with schema not found
     *
     * @return void
     */
    public function testDestroySchemaNotFound(): void
    {
        $id = 999;

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willThrowException(new DoesNotExistException('Schema not found'));

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Schema not found'], $response->getData());
    }

    /**
     * Test objects method with successful object retrieval
     *
     * @return void
     */
    public function testObjectsSuccessful(): void
    {
        $schema = 1;
        $expectedObjects = [
            'results' => [
                ['id' => 1, 'name' => 'Object 1'],
                ['id' => 2, 'name' => 'Object 2']
            ]
        ];

        $this->objectEntityMapper->expects($this->once())
            ->method('searchObjects')
            ->with([
                '@self' => [
                    'schema' => $schema
                ]
            ])
            ->willReturn($expectedObjects);

        $response = $this->controller->objects($schema);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($expectedObjects, $response->getData());
    }

    /**
     * Test stats method with successful statistics retrieval
     *
     * @return void
     */
    public function testStatsSuccessful(): void
    {
        $id = 1;
        $schema = $this->createMock(Schema::class);
        $stats = [
            'totalObjects' => 50,
            'totalSize' => 512000
        ];

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($schema);

        $this->objectEntityMapper->expects($this->once())
            ->method('getStatistics')
            ->with(null, $id)
            ->willReturn($stats);

        $response = $this->controller->stats($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($stats, $response->getData());
    }

    /**
     * Test stats method with schema not found
     *
     * @return void
     */
    public function testStatsSchemaNotFound(): void
    {
        $id = 999;

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willThrowException(new DoesNotExistException('Schema not found'));

        $response = $this->controller->stats($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Schema not found'], $response->getData());
    }
}
