<?php

declare(strict_types=1);

/**
 * RegistersControllerTest
 * 
 * Unit tests for the RegistersController
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

use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Service\RegisterService;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\UploadService;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DBException;
use OCA\OpenRegister\Exception\DatabaseConstraintException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the RegistersController
 *
 * This test class covers all functionality of the RegistersController
 * including CRUD operations, import/export, and statistics.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class RegistersControllerTest extends TestCase
{
    /**
     * The RegistersController instance being tested
     *
     * @var RegistersController
     */
    private RegistersController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock register service
     *
     * @var MockObject|RegisterService
     */
    private MockObject $registerService;

    /**
     * Mock object entity mapper
     *
     * @var MockObject|ObjectEntityMapper
     */
    private MockObject $objectEntityMapper;

    /**
     * Mock upload service
     *
     * @var MockObject|UploadService
     */
    private MockObject $uploadService;

    /**
     * Mock logger
     *
     * @var MockObject|LoggerInterface
     */
    private MockObject $logger;

    /**
     * Mock user session
     *
     * @var MockObject|IUserSession
     */
    private MockObject $userSession;

    /**
     * Mock configuration service
     *
     * @var MockObject|ConfigurationService
     */
    private MockObject $configurationService;

    /**
     * Mock audit trail mapper
     *
     * @var MockObject|AuditTrailMapper
     */
    private MockObject $auditTrailMapper;

    /**
     * Mock export service
     *
     * @var MockObject|ExportService
     */
    private MockObject $exportService;

    /**
     * Mock import service
     *
     * @var MockObject|ImportService
     */
    private MockObject $importService;

    /**
     * Mock schema mapper
     *
     * @var MockObject|SchemaMapper
     */
    private MockObject $schemaMapper;

    /**
     * Mock register mapper
     *
     * @var MockObject|RegisterMapper
     */
    private MockObject $registerMapper;

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

        // Initialize the controller with mocked dependencies
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
            $this->registerMapper
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
     * Test index method with successful register listing
     *
     * @return void
     */
    public function testIndexSuccessful(): void
    {
        $register1 = $this->createMock(Register::class);
        $register2 = $this->createMock(Register::class);
        $registers = [$register1, $register2];

        $register1->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Register 1']);

        $register2->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 2, 'name' => 'Register 2']);

        $this->request->expects($this->exactly(3))
            ->method('getParam')
            ->willReturnMap([
                ['filters', [], []],
                ['_search', '', ''],
                ['_extend', [], []]
            ]);

        $this->registerService->expects($this->once())
            ->method('findAll')
            ->with(null, null, [], [], [], [])
            ->willReturn($registers);

        $response = $this->controller->index(
            $this->createMock(ObjectService::class),
        );

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(2, $data['results']);
    }

    /**
     * Test index method with stats extension
     *
     * @return void
     */
    public function testIndexWithStatsExtension(): void
    {
        $register = $this->createMock(Register::class);
        $registers = [$register];

        $register->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Register 1']);

        $this->request->expects($this->exactly(3))
            ->method('getParam')
            ->willReturnMap([
                ['filters', [], []],
                ['_search', '', ''],
                ['_extend', [], ['@self.stats']]
            ]);

        $this->registerService->expects($this->once())
            ->method('findAll')
            ->willReturn($registers);

        $this->objectEntityMapper->expects($this->once())
            ->method('getStatistics')
            ->with(1, null)
            ->willReturn(['total' => 10, 'size' => 1024]);

        $this->auditTrailMapper->expects($this->once())
            ->method('getStatistics')
            ->with(1, null)
            ->willReturn(['total' => 5]);

        $response = $this->controller->index(
            $this->createMock(ObjectService::class),
        );

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('stats', $data['results'][0]);
    }

    /**
     * Test show method with successful register retrieval
     *
     * @return void
     */
    public function testShowSuccessful(): void
    {
        $id = 1;
        $register = $this->createMock(Register::class);

        $register->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Test Register']);

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('_extend', [])
            ->willReturn([]);

        $this->registerService->expects($this->once())
            ->method('find')
            ->with($id, [])
            ->willReturn($register);

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(['id' => 1, 'name' => 'Test Register'], $response->getData());
    }

    /**
     * Test show method with stats extension
     *
     * @return void
     */
    public function testShowWithStatsExtension(): void
    {
        $id = 1;
        $register = $this->createMock(Register::class);

        $register->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Test Register']);

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('_extend', [])
            ->willReturn(['@self.stats']);

        $this->registerService->expects($this->once())
            ->method('find')
            ->with($id, [])
            ->willReturn($register);

        $this->objectEntityMapper->expects($this->once())
            ->method('getStatistics')
            ->with(1, null)
            ->willReturn(['total' => 10]);

        $this->auditTrailMapper->expects($this->once())
            ->method('getStatistics')
            ->with(1, null)
            ->willReturn(['total' => 5]);

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('stats', $data);
    }

    /**
     * Test create method with successful register creation
     *
     * @return void
     */
    public function testCreateSuccessful(): void
    {
        $data = ['name' => 'New Register', 'description' => 'Test description'];
        $createdRegister = $this->createMock(\OCA\OpenRegister\Db\Register::class);

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->registerService->expects($this->once())
            ->method('createFromArray')
            ->with($data)
            ->willReturn($createdRegister);

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($createdRegister, $response->getData());
    }

    /**
     * Test create method with database constraint exception
     *
     * @return void
     */
    public function testCreateWithDatabaseConstraintException(): void
    {
        $data = ['name' => 'Duplicate Register'];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $dbException = new DBException('Duplicate entry', 1062);
        $constraintException = DatabaseConstraintException::fromDatabaseException($dbException, 'register');

        $this->registerService->expects($this->once())
            ->method('createFromArray')
            ->willThrowException($dbException);

        $response = $this->controller->create();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($constraintException->getHttpStatusCode(), $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
    }

    /**
     * Test update method with successful register update
     *
     * @return void
     */
    public function testUpdateSuccessful(): void
    {
        $id = 1;
        $data = ['name' => 'Updated Register'];
        $updatedRegister = $this->createMock(\OCA\OpenRegister\Db\Register::class);

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($data);

        $this->registerService->expects($this->once())
            ->method('updateFromArray')
            ->with($id, $data)
            ->willReturn($updatedRegister);

        $response = $this->controller->update($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($updatedRegister, $response->getData());
    }

    /**
     * Test destroy method with successful register deletion
     *
     * @return void
     */
    public function testDestroySuccessful(): void
    {
        $id = 1;
        $register = $this->createMock(Register::class);

        $this->registerService->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $this->registerService->expects($this->once())
            ->method('delete')
            ->with($register);

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals([], $response->getData());
    }

    /**
     * Test schemas method with successful schema retrieval
     *
     * @return void
     */
    public function testSchemasSuccessful(): void
    {
        $id = 1;
        $register = $this->createMock(Register::class);
        $schema1 = $this->createMock(Schema::class);
        $schema2 = $this->createMock(Schema::class);
        $schemas = [$schema1, $schema2];

        $register->expects($this->once())
            ->method('getId')
            ->willReturn('1');

        $schema1->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 1, 'name' => 'Schema 1']);

        $schema2->expects($this->once())
            ->method('jsonSerialize')
            ->willReturn(['id' => 2, 'name' => 'Schema 2']);

        $this->registerService->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $this->registerMapper->expects($this->once())
            ->method('getSchemasByRegisterId')
            ->with(1)
            ->willReturn($schemas);

        $response = $this->controller->schemas($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertCount(2, $data['results']);
        $this->assertEquals(2, $data['total']);
    }

    /**
     * Test schemas method with register not found
     *
     * @return void
     */
    public function testSchemasRegisterNotFound(): void
    {
        $id = 999;

        $this->registerService->expects($this->once())
            ->method('find')
            ->with($id)
            ->willThrowException(new DoesNotExistException('Register not found'));

        $response = $this->controller->schemas($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Register not found'], $response->getData());
    }

    /**
     * Test objects method with successful object retrieval
     *
     * @return void
     */
    public function testObjectsSuccessful(): void
    {
        $register = 1;
        $schema = 2;
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
                    'register' => $register,
                    'schema' => $schema
                ]
            ])
            ->willReturn($expectedObjects);

        $response = $this->controller->objects($register, $schema);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals($expectedObjects, $response->getData());
    }

    /**
     * Test export method with configuration format
     *
     * @return void
     */
    public function testExportConfigurationFormat(): void
    {
        $id = 1;
        $register = $this->createMock(Register::class);
        $exportData = ['registers' => [], 'schemas' => []];

        $this->request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['format', 'configuration', 'configuration'],
                ['includeObjects', false, false]
            ]);

        $this->registerService->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $this->configurationService->expects($this->once())
            ->method('exportConfig')
            ->with($register, false)
            ->willReturn($exportData);

        $response = $this->controller->export($id);

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
    }

    /**
     * Test export method with Excel format
     *
     * @return void
     */
    public function testExportExcelFormat(): void
    {
        $id = 1;
        $register = $this->createMock(Register::class);
        $spreadsheet = $this->createMock(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);

        $this->request->expects($this->exactly(2))
            ->method('getParam')
            ->willReturnMap([
                ['format', 'configuration', 'excel'],
                ['includeObjects', false, false]
            ]);

        $this->registerService->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $this->exportService->expects($this->once())
            ->method('exportToExcel')
            ->with($register)
            ->willReturn($spreadsheet);

        $response = $this->controller->export($id);

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
    }

    /**
     * Test export method with CSV format
     *
     * @return void
     */
    public function testExportCsvFormat(): void
    {
        $id = 1;
        $register = $this->createMock(Register::class);
        $schema = $this->createMock(Schema::class);
        $csvContent = 'id,name,description';

        $this->request->expects($this->exactly(3))
            ->method('getParam')
            ->willReturnMap([
                ['format', 'configuration', 'csv'],
                ['includeObjects', false, false],
                ['schema', null, 1]
            ]);

        $this->registerService->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $this->schemaMapper->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($schema);

        $this->exportService->expects($this->once())
            ->method('exportToCsv')
            ->with($register, $schema)
            ->willReturn($csvContent);

        $response = $this->controller->export($id);

        $this->assertInstanceOf(DataDownloadResponse::class, $response);
    }

    /**
     * Test import method with Excel file
     *
     * @return void
     */
    public function testImportExcelFile(): void
    {
        $id = 1;
        $register = $this->createMock(Register::class);
        $uploadedFile = [
            'name' => 'test.xlsx',
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'tmp_name' => '/tmp/test.xlsx'
        ];
        $summary = [
            'excel' => [
                'created' => [['id' => 1, 'name' => 'Object 1']],
                'updated' => [],
                'errors' => []
            ]
        ];

        $this->request->expects($this->exactly(8))
            ->method('getParam')
            ->willReturnMap([
                ['type', null, 'excel'],
                ['includeObjects', false, false],
                ['validation', false, false],
                ['events', false, false],
                ['publish', false, false],
                ['rbac', true, true],
                ['multi', true, true],
                ['chunkSize', 5, 5]
            ]);

        $this->request->expects($this->once())
            ->method('getUploadedFile')
            ->with('file')
            ->willReturn($uploadedFile);

        $this->registerService->expects($this->once())
            ->method('find')
            ->with($id)
            ->willReturn($register);

        $this->importService->expects($this->once())
            ->method('importFromExcel')
            ->with(
                $this->isType('string'),
                $register,
                $this->isNull(),
                $this->isType('int'),
                $this->isType('bool'),
                $this->isType('bool'),
                $this->isType('bool'),
                $this->isType('bool'),
                $this->isType('bool')
            )
            ->willReturn($summary);

        $response = $this->controller->import($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('summary', $data);
        $this->assertEquals('Import successful', $data['message']);
    }

    /**
     * Test import method with no file uploaded
     *
     * @return void
     */
    public function testImportNoFileUploaded(): void
    {
        $id = 1;

        $this->request->expects($this->once())
            ->method('getUploadedFile')
            ->with('file')
            ->willReturn(null);

        $response = $this->controller->import($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(400, $response->getStatus());
        $this->assertEquals(['error' => 'No file uploaded'], $response->getData());
    }

    /**
     * Test stats method with successful statistics retrieval
     *
     * @return void
     */
    public function testStatsSuccessful(): void
    {
        $register = $this->createMock(\OCA\OpenRegister\Db\Register::class);
        $register->expects($this->any())
            ->method('getId')
            ->willReturn('1');

        $this->registerService->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($register);

        $this->registerService->expects($this->once())
            ->method('calculateStats')
            ->with($register)
            ->willReturn([
                'register_id' => '1',
                'register_name' => 'Test Register',
                'total_objects' => 0,
                'total_schemas' => 0
            ]);

        $response = $this->controller->stats(1);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $data = $response->getData();
        $this->assertArrayHasKey('register_id', $data);
        $this->assertEquals('1', $data['register_id']);
    }

    /**
     * Test stats method with register not found
     *
     * @return void
     */
    public function testStatsRegisterNotFound(): void
    {
        $id = 999;

        $this->registerService->expects($this->once())
            ->method('find')
            ->with($id)
            ->willThrowException(new DoesNotExistException('Register not found'));

        $response = $this->controller->stats($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertEquals(['error' => 'Register not found'], $response->getData());
    }
}
