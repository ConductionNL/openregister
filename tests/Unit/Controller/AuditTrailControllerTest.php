<?php

declare(strict_types=1);

/**
 * AuditTrailControllerTest
 * 
 * Unit tests for the AuditTrailController
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

use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Service\LogService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for the AuditTrailController
 *
 * This test class covers all functionality of the AuditTrailController
 * including audit trail retrieval and management.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 */
class AuditTrailControllerTest extends TestCase
{
    /**
     * The AuditTrailController instance being tested
     *
     * @var AuditTrailController
     */
    private AuditTrailController $controller;

    /**
     * Mock request object
     *
     * @var MockObject|IRequest
     */
    private MockObject $request;

    /**
     * Mock log service
     *
     * @var MockObject|LogService
     */
    private MockObject $logService;

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
        $this->logService = $this->createMock(LogService::class);

        // Initialize the controller with mocked dependencies
        $this->controller = new AuditTrailController(
            'openregister',
            $this->request,
            $this->logService
        );
    }

    /**
     * Test index method with successful audit trail listing
     *
     * @return void
     */
    public function testIndexSuccessful(): void
    {
        $logs = [
            ['id' => 1, 'action' => 'create', 'object_id' => 'obj-1'],
            ['id' => 2, 'action' => 'update', 'object_id' => 'obj-2']
        ];
        $total = 2;
        $params = [
            'page' => 1,
            'limit' => 10,
            'offset' => 0,
            'filters' => []
        ];

        // Mock the request parameters
        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn(['page' => 1, 'limit' => 10]);

        $this->logService
            ->expects($this->once())
            ->method('getAllLogs')
            ->willReturn($logs);

        $this->logService
            ->expects($this->once())
            ->method('countAllLogs')
            ->with([])
            ->willReturn($total);

        $response = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('pages', $data);
        $this->assertArrayHasKey('limit', $data);
        $this->assertArrayHasKey('offset', $data);
        
        $this->assertEquals($logs, $data['results']);
        $this->assertEquals($total, $data['total']);
    }

    /**
     * Test show method with successful audit trail retrieval
     *
     * @return void
     */
    public function testShowSuccessful(): void
    {
        $id = 123;
        $log = ['id' => $id, 'action' => 'create', 'object_id' => 'obj-1'];

        $this->logService
            ->expects($this->once())
            ->method('getLog')
            ->with($id)
            ->willReturn($log);

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals($log, $response->getData());
    }

    /**
     * Test show method when audit trail not found
     *
     * @return void
     */
    public function testShowNotFound(): void
    {
        $id = 123;

        $this->logService
            ->expects($this->once())
            ->method('getLog')
            ->with($id)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Audit trail not found'));

        $response = $this->controller->show($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('Audit trail not found', $response->getData()['error']);
    }

    /**
     * Test objects method with successful audit trail for object
     *
     * @return void
     */
    public function testObjectsSuccessful(): void
    {
        $register = 'test-register';
        $schema = 'test-schema';
        $id = 'test-id';
        $logs = [
            ['id' => 1, 'action' => 'create', 'object_id' => $id],
            ['id' => 2, 'action' => 'update', 'object_id' => $id]
        ];
        $total = 2;
        $params = [
            'page' => 1,
            'limit' => 10,
            'offset' => 0,
            'filters' => []
        ];

        // Mock the request parameters
        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn(['page' => 1, 'limit' => 10]);

        $this->logService
            ->expects($this->once())
            ->method('getLogs')
            ->with($register, $schema, $id, $this->isType('array'))
            ->willReturn($logs);

        $this->logService
            ->expects($this->once())
            ->method('count')
            ->with($register, $schema, $id)
            ->willReturn($total);

        $response = $this->controller->objects($register, $schema, $id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertEquals($logs, $data['results']);
        $this->assertEquals($total, $data['total']);
    }

    /**
     * Test export method with successful audit trail export
     *
     * @return void
     */
    public function testExportSuccessful(): void
    {
        $exportResult = [
            'content' => 'csv,data,here',
            'filename' => 'audit_trail.csv',
            'contentType' => 'text/csv',
            'size' => 13
        ];

        $this->request
            ->expects($this->exactly(3))
            ->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'csv'],
                ['includeChanges', true, true],
                ['includeMetadata', false, false]
            ]);

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn(['page' => 1, 'limit' => 10]);

        $this->logService
            ->expects($this->once())
            ->method('exportLogs')
            ->with('csv', $this->isType('array'))
            ->willReturn($exportResult);

        $response = $this->controller->export();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        
        $data = $response->getData();
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertTrue($data['success']);
        $this->assertEquals($exportResult, $data['data']);
    }

    /**
     * Test destroy method with successful audit trail deletion
     *
     * @return void
     */
    public function testDestroySuccessful(): void
    {
        $id = 123;

        $this->logService
            ->expects($this->once())
            ->method('deleteLog')
            ->with($id)
            ->willReturn(true);

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(200, $response->getStatus());
        $this->assertArrayHasKey('message', $response->getData());
        $this->assertEquals('Audit trail deleted successfully', $response->getData()['message']);
    }

    /**
     * Test destroy method when audit trail not found
     *
     * @return void
     */
    public function testDestroyNotFound(): void
    {
        $id = 123;

        $this->logService
            ->expects($this->once())
            ->method('deleteLog')
            ->with($id)
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Audit trail not found'));

        $response = $this->controller->destroy($id);

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertEquals(404, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertEquals('Audit trail not found', $response->getData()['error']);
    }
}