<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\AuditTrailController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\LogService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditTrailControllerTest extends TestCase
{
    private AuditTrailController $controller;
    private IRequest&MockObject $request;
    private LogService&MockObject $logService;
    private AuditTrailMapper&MockObject $auditTrailMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->logService = $this->createMock(LogService::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);

        $this->controller = new AuditTrailController(
            'openregister',
            $this->request,
            $this->logService,
            $this->auditTrailMapper
        );
    }

    public function testIndexSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->logService->method('getAllLogs')->willReturn([]);
        $this->logService->method('countAllLogs')->willReturn(0);

        $result = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    public function testShowSuccess(): void
    {
        $log = ['id' => 1, 'action' => 'create'];
        $this->logService->method('getLog')->willReturn($log);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testShowNotFound(): void
    {
        $this->logService->method('getLog')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Audit trail not found', $data['error']);
    }

    public function testObjectsSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->logService->method('getLogs')->willReturn([]);
        $this->logService->method('count')->willReturn(0);

        $result = $this->controller->objects('reg1', 'schema1', 'obj1');

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
    }

    public function testObjectsInvalidArgument(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->logService->method('getLogs')
            ->willThrowException(new \InvalidArgumentException('Bad input'));

        $result = $this->controller->objects('reg1', 'schema1', 'obj1');

        $this->assertEquals(400, $result->getStatus());
    }

    public function testObjectsNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->logService->method('getLogs')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->objects('reg1', 'schema1', 'obj1');

        $this->assertEquals(404, $result->getStatus());
    }

    public function testExportSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'csv'],
                ['includeChanges', true, true],
                ['includeMetadata', false, false],
            ]);

        $this->logService->method('exportLogs')->willReturn([
            'content' => 'csv-content',
            'filename' => 'export.csv',
            'contentType' => 'text/csv',
        ]);

        $result = $this->controller->export();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testExportInvalidFormat(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'invalid'],
                ['includeChanges', true, true],
                ['includeMetadata', false, false],
            ]);

        $this->logService->method('exportLogs')
            ->willThrowException(new \InvalidArgumentException('Bad format'));

        $result = $this->controller->export();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testExportGeneralException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['format', 'csv', 'csv'],
                ['includeChanges', true, true],
                ['includeMetadata', false, false],
            ]);

        $this->logService->method('exportLogs')
            ->willThrowException(new \Exception('Export error'));

        $result = $this->controller->export();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $this->logService->method('deleteLog')->willReturn(true);

        $result = $this->controller->destroy(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testDestroyReturnsFalse(): void
    {
        $this->logService->method('deleteLog')->willReturn(false);

        $result = $this->controller->destroy(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testDestroyNotFound(): void
    {
        $this->logService->method('deleteLog')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDestroyMultipleSuccess(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['ids', null, '1,2,3'],
            ]);

        $this->logService->method('deleteLogs')->willReturn([
            'deleted' => 3,
            'failed' => 0,
        ]);

        $result = $this->controller->destroyMultiple();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testDestroyMultipleException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->request->method('getParam')
            ->willReturnMap([
                ['ids', null, null],
            ]);

        $this->logService->method('deleteLogs')
            ->willThrowException(new \Exception('Deletion failed'));

        $result = $this->controller->destroyMultiple();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testClearAllSuccess(): void
    {
        $this->auditTrailMapper->method('clearAllLogs')->willReturn(true);

        $result = $this->controller->clearAll();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testClearAllNoExpired(): void
    {
        $this->auditTrailMapper->method('clearAllLogs')->willReturn(false);

        $result = $this->controller->clearAll();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testClearAllException(): void
    {
        $this->auditTrailMapper->method('clearAllLogs')
            ->willThrowException(new \Exception('Clear failed'));

        $result = $this->controller->clearAll();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
    }
}
