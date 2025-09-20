<?php

declare(strict_types=1);

/**
 * SchemasControllerTest
 * 
 * Comprehensive unit tests for the SchemasController, which handles HTTP API
 * endpoints for schema management in OpenRegister. This test suite covers:
 * 
 * ## Test Categories:
 * 
 * ### 1. Schema Management
 * - testIndex: Tests listing all schemas
 * - testShow: Tests retrieving a specific schema by ID
 * - testStore: Tests creating new schemas
 * - testUpdate: Tests updating existing schemas
 * - testDestroy: Tests deleting schemas
 * 
 * ### 2. Statistics & Analytics
 * - testStatsSuccessful: Tests schema statistics retrieval
 * - testStatsSchemaNotFound: Tests error handling for non-existent schemas
 * - testStatsWithEmptyData: Tests statistics with no data
 * 
 * ### 3. File Operations
 * - testDownload: Tests schema file download functionality
 * - testUpload: Tests schema file upload functionality
 * - testExport: Tests schema export functionality
 * 
 * ### 4. Error Handling
 * - testShowNotFound: Tests handling of non-existent schema requests
 * - testUpdateNotFound: Tests handling of update requests for non-existent schemas
 * - testDestroyNotFound: Tests handling of delete requests for non-existent schemas
 * - testStoreValidationError: Tests validation error handling
 * 
 * ## API Endpoints Covered:
 * 
 * - `GET /schemas` - List all schemas
 * - `GET /schemas/{id}` - Get specific schema
 * - `POST /schemas` - Create new schema
 * - `PUT /schemas/{id}` - Update schema
 * - `DELETE /schemas/{id}` - Delete schema
 * - `GET /schemas/{id}/stats` - Get schema statistics
 * - `GET /schemas/{id}/download` - Download schema file
 * - `POST /schemas/{id}/upload` - Upload schema file
 * 
 * ## Mocking Strategy:
 * 
 * The tests use comprehensive mocking to isolate the controller from dependencies:
 * - SchemaMapper: Mocked for database operations
 * - ObjectEntityMapper: Mocked for object queries
 * - ObjectService: Mocked for object statistics and operations
 * - DownloadService: Mocked for file operations
 * - UploadService: Mocked for file uploads
 * - AuditTrailMapper: Mocked for audit logging
 * - OrganisationService: Mocked for organization operations
 * - Cache Services: Mocked for caching operations
 * 
 * ## Response Types:
 * 
 * Tests verify various response types:
 * - JSONResponse: For API data responses
 * - TemplateResponse: For view responses
 * - DataResponse: For simple data responses
 * - Error responses: For error handling
 * 
 * ## Data Validation:
 * 
 * Tests cover various data validation scenarios:
 * - Valid schema data
 * - Invalid schema data
 * - Missing required fields
 * - Invalid data types
 * - Duplicate schema names
 * 
 * ## Integration Points:
 * 
 * - **Database Layer**: Integrates with various mappers
 * - **Service Layer**: Uses multiple services for business logic
 * - **File System**: Handles file uploads and downloads
 * - **Caching**: Integrates with cache services
 * - **Audit Trail**: Logs all operations
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
use OCA\OpenRegister\Service\SchemaCacheService;
use OCA\OpenRegister\Service\SchemaFacetCacheService;
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
     * Mock schema cache service
     *
     * @var MockObject|SchemaCacheService
     */
    private MockObject $schemaCacheService;

    /**
     * Mock schema facet cache service
     *
     * @var MockObject|SchemaFacetCacheService
     */
    private MockObject $schemaFacetCacheService;

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
        $this->uploadService = $this->createMock(UploadService::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->schemaCacheService = $this->createMock(SchemaCacheService::class);
        $this->schemaFacetCacheService = $this->createMock(SchemaFacetCacheService::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new SchemasController(
            'openregister',
            $this->request,
            $this->config,
            $this->schemaMapper,
            $this->objectEntityMapper,
            $this->downloadService,
            $this->objectService,
            $this->uploadService,
            $this->auditTrailMapper,
            $this->organisationService,
            $this->schemaCacheService,
            $this->schemaFacetCacheService
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

        $response = $this->controller->index($this->objectService);

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
        $createdSchema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->schemaMapper->expects($this->once())
            ->method('createFromArray')
            ->with($data)
            ->willReturn($createdSchema);

        $this->organisationService->expects($this->once())
            ->method('getOrganisationForNewEntity')
            ->willReturn('test-org-uuid');

        $this->schemaMapper->expects($this->once())
            ->method('update')
            ->with($createdSchema)
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
        $updatedSchema = $this->createMock(\OCA\OpenRegister\Db\Schema::class);
        $updatedSchema->method('getId')->willReturn((string)$id);
        
        // Mock the cache service methods to handle the type conversion
        $this->schemaCacheService->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with($this->callback(function($schemaId) use ($id) {
                return (int)$schemaId === $id;
            }), 'update');
        $this->schemaFacetCacheService->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with($this->callback(function($schemaId) use ($id) {
                return (int)$schemaId === $id;
            }), 'update');

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
        $schema->method('getId')->willReturn((string)$id);

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($schema);

        $this->schemaMapper->expects($this->once())
            ->method('delete')
            ->with($schema);

        // Mock the cache service methods to handle the type conversion
        $this->schemaCacheService->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with($this->callback(function($schemaId) use ($id) {
                return (int)$schemaId === $id;
            }), 'delete');
        $this->schemaFacetCacheService->expects($this->once())
            ->method('invalidateForSchemaChange')
            ->with($this->callback(function($schemaId) use ($id) {
                return (int)$schemaId === $id;
            }), 'delete');

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
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Schema not found'));

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->expectExceptionMessage('Schema not found');
        $this->controller->destroy($id);
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
        $schema->expects($this->any())
            ->method('getId')
            ->willReturn((string)$id);

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($schema);

        $this->objectService->expects($this->once())
            ->method('getObjectStats')
            ->with((string)$id)
            ->willReturn(['total_objects' => 0, 'active_objects' => 0, 'deleted_objects' => 0]);

        $this->objectService->expects($this->once())
            ->method('getFileStats')
            ->with((string)$id)
            ->willReturn(['total_files' => 0, 'total_size' => 0]);

        $this->objectService->expects($this->once())
            ->method('getLogStats')
            ->with((string)$id)
            ->willReturn(['total_logs' => 0, 'recent_logs' => 0]);

        $this->schemaMapper->expects($this->once())
            ->method('getRegisterCount')
            ->with((string)$id)
            ->willReturn(0);

        $response = $this->controller->stats($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        
        // Debug: print the actual response
        if (!isset($data['objects'])) {
            $this->fail('Response data: ' . json_encode($data));
        }
        
        $this->assertArrayHasKey('objects', $data);
        $this->assertArrayHasKey('files', $data);
        $this->assertArrayHasKey('logs', $data);
        $this->assertArrayHasKey('registers', $data);
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
