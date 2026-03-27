<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\EndpointsController;
use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointLog;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Db\EndpointMapper;
use OCA\OpenRegister\Service\EndpointService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EndpointsControllerTest extends TestCase
{
    private EndpointsController $controller;
    private IRequest&MockObject $request;
    private EndpointMapper&MockObject $endpointMapper;
    private EndpointLogMapper&MockObject $endpointLogMapper;
    private EndpointService&MockObject $endpointService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->endpointMapper = $this->createMock(EndpointMapper::class);
        $this->endpointLogMapper = $this->createMock(EndpointLogMapper::class);
        $this->endpointService = $this->createMock(EndpointService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new EndpointsController(
            'openregister',
            $this->request,
            $this->endpointMapper,
            $this->endpointLogMapper,
            $this->endpointService,
            $this->logger
        );
    }

    // ──────────────────────────────────────────────
    // index()
    // ──────────────────────────────────────────────

    public function testIndexReturnsEndpointsAndTotal(): void
    {
        $endpoints = [new Endpoint(), new Endpoint()];
        $this->endpointMapper->method('findAll')->willReturn($endpoints);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertCount(2, $data['results']);
        $this->assertEquals(2, $data['total']);
    }

    public function testIndexReturnsEmptyListWhenNoEndpoints(): void
    {
        $this->endpointMapper->method('findAll')->willReturn([]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(0, $data['results']);
        $this->assertEquals(0, $data['total']);
    }

    public function testIndexReturns500OnException(): void
    {
        $this->endpointMapper->method('findAll')
            ->willThrowException(new \Exception('DB connection failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error listing endpoints'));

        $result = $this->controller->index();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to list endpoints', $data['error']);
    }

    // ──────────────────────────────────────────────
    // show()
    // ──────────────────────────────────────────────

    public function testShowReturnsEndpoint(): void
    {
        $endpoint = new Endpoint();
        $endpoint->setName('Test Endpoint');
        $this->endpointMapper->method('find')->with(1)->willReturn($endpoint);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testShowReturns404WhenNotFound(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Endpoint not found', $data['error']);
    }

    public function testShowReturns500OnGenericException(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error retrieving endpoint'));

        $result = $this->controller->show(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to retrieve endpoint', $data['error']);
    }

    // ──────────────────────────────────────────────
    // create()
    // ──────────────────────────────────────────────

    public function testCreateReturns400WhenNameMissing(): void
    {
        $this->request->method('getParams')->willReturn([
            'endpoint' => '/api/test',
        ]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Name and endpoint path are required', $data['error']);
    }

    public function testCreateReturns400WhenEndpointMissing(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Test',
        ]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Name and endpoint path are required', $data['error']);
    }

    public function testCreateReturns400WhenBothFieldsMissing(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testCreateReturns400WhenFieldsAreEmpty(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => '',
            'endpoint' => '',
        ]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testCreateReturns201OnSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Test Endpoint',
            'endpoint' => '/api/test',
        ]);

        $endpoint = new Endpoint();
        $endpoint->setId(1);
        $endpoint->setName('Test Endpoint');
        $endpoint->setEndpoint('/api/test');
        $this->endpointMapper->method('createFromArray')->willReturn($endpoint);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Endpoint created'));

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateReturns500OnException(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Test',
            'endpoint' => '/api/test',
        ]);
        $this->endpointMapper->method('createFromArray')
            ->willThrowException(new \Exception('Insert failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error creating endpoint'));

        $result = $this->controller->create();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContains('Failed to create endpoint', $data['error']);
    }

    // ──────────────────────────────────────────────
    // update()
    // ──────────────────────────────────────────────

    public function testUpdateReturnsUpdatedEndpoint(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Updated Name',
        ]);
        $endpoint = new Endpoint();
        $endpoint->setId(1);
        $endpoint->setName('Updated Name');
        $this->endpointMapper->method('updateFromArray')->willReturn($endpoint);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Endpoint updated'));

        $result = $this->controller->update(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateStripsIdFromRequestData(): void
    {
        $this->request->method('getParams')->willReturn([
            'id' => 999,
            'name' => 'Updated',
        ]);
        $endpoint = new Endpoint();
        $endpoint->setId(1);
        $endpoint->setName('Updated');

        $this->endpointMapper->expects($this->once())
            ->method('updateFromArray')
            ->with(
                $this->equalTo(1),
                $this->logicalAnd(
                    $this->arrayHasKey('name'),
                    $this->logicalNot($this->arrayHasKey('id'))
                )
            )
            ->willReturn($endpoint);

        $result = $this->controller->update(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateReturns404WhenNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->endpointMapper->method('updateFromArray')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->update(999);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Endpoint not found', $data['error']);
    }

    public function testUpdateReturns500OnGenericException(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Updated',
        ]);
        $this->endpointMapper->method('updateFromArray')
            ->willThrowException(new \Exception('Update failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error updating endpoint'));

        $result = $this->controller->update(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContains('Failed to update endpoint', $data['error']);
    }

    // ──────────────────────────────────────────────
    // destroy()
    // ──────────────────────────────────────────────

    public function testDestroyReturns204OnSuccess(): void
    {
        $endpoint = new Endpoint();
        $endpoint->setId(1);
        $endpoint->setName('Test');
        $this->endpointMapper->method('find')->willReturn($endpoint);
        $this->endpointMapper->expects($this->once())
            ->method('delete')
            ->with($endpoint);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Endpoint deleted'));

        $result = $this->controller->destroy(1);

        $this->assertEquals(204, $result->getStatus());
        $this->assertNull($result->getData());
    }

    public function testDestroyReturns404WhenNotFound(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Endpoint not found', $data['error']);
    }

    public function testDestroyReturns500OnGenericException(): void
    {
        $endpoint = new Endpoint();
        $endpoint->setId(1);
        $endpoint->setName('Test');
        $this->endpointMapper->method('find')->willReturn($endpoint);
        $this->endpointMapper->method('delete')
            ->willThrowException(new \Exception('Delete failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error deleting endpoint'));

        $result = $this->controller->destroy(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to delete endpoint', $data['error']);
    }

    // ──────────────────────────────────────────────
    // test()
    // ──────────────────────────────────────────────

    public function testTestEndpointReturnsSuccessResult(): void
    {
        $endpoint = new Endpoint();
        $this->endpointMapper->method('find')->willReturn($endpoint);
        $this->request->method('getParams')->willReturn([]);
        $this->endpointService->method('testEndpoint')->willReturn([
            'success' => true,
            'statusCode' => 200,
            'response' => ['data' => 'ok'],
        ]);

        $result = $this->controller->test(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
        $this->assertEquals('Test endpoint executed successfully', $data['message']);
        $this->assertEquals(200, $data['statusCode']);
        $this->assertEquals(['data' => 'ok'], $data['response']);
    }

    public function testTestEndpointWithTestData(): void
    {
        $endpoint = new Endpoint();
        $this->endpointMapper->method('find')->willReturn($endpoint);
        $this->request->method('getParams')->willReturn([
            'data' => ['key' => 'value'],
        ]);
        $this->endpointService->expects($this->once())
            ->method('testEndpoint')
            ->with($endpoint, ['key' => 'value'])
            ->willReturn([
                'success' => true,
                'statusCode' => 200,
                'response' => 'OK',
            ]);

        $result = $this->controller->test(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testTestEndpointReturnsFailureWithErrorMessage(): void
    {
        $endpoint = new Endpoint();
        $this->endpointMapper->method('find')->willReturn($endpoint);
        $this->request->method('getParams')->willReturn([]);
        $this->endpointService->method('testEndpoint')->willReturn([
            'success' => false,
            'statusCode' => 503,
            'error' => 'Service unavailable',
        ]);

        $result = $this->controller->test(1);

        $this->assertEquals(503, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Service unavailable', $data['message']);
        $this->assertEquals(503, $data['statusCode']);
    }

    public function testTestEndpointReturnsFailureWithoutErrorKey(): void
    {
        $endpoint = new Endpoint();
        $this->endpointMapper->method('find')->willReturn($endpoint);
        $this->request->method('getParams')->willReturn([]);
        $this->endpointService->method('testEndpoint')->willReturn([
            'success' => false,
            'statusCode' => 500,
        ]);

        $result = $this->controller->test(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['success']);
        $this->assertEquals('Test endpoint execution failed', $data['message']);
    }

    public function testTestEndpointReturns404WhenNotFound(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->test(999);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Endpoint not found', $data['error']);
    }

    public function testTestEndpointReturns500OnGenericException(): void
    {
        $endpoint = new Endpoint();
        $this->endpointMapper->method('find')->willReturn($endpoint);
        $this->request->method('getParams')->willReturn([]);
        $this->endpointService->method('testEndpoint')
            ->willThrowException(new \Exception('Connection timeout'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error testing endpoint'));

        $result = $this->controller->test(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContains('Failed to test endpoint', $data['error']);
    }

    // ──────────────────────────────────────────────
    // logs()
    // ──────────────────────────────────────────────

    public function testLogsReturnsLogsForEndpoint(): void
    {
        $this->endpointMapper->method('find')->willReturn(new Endpoint());
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', null, 50],
                ['offset', null, 0],
            ]);
        $logs = [new EndpointLog(), new EndpointLog()];
        $this->endpointLogMapper->method('findByEndpoint')->willReturn($logs);

        $result = $this->controller->logs(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertCount(2, $data['results']);
        $this->assertEquals(2, $data['total']);
    }

    public function testLogsWithCustomPagination(): void
    {
        $this->endpointMapper->method('find')->willReturn(new Endpoint());
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', null, '10'],
                ['offset', null, '20'],
            ]);
        $this->endpointLogMapper->expects($this->once())
            ->method('findByEndpoint')
            ->with(1, 10, 20)
            ->willReturn([]);

        $result = $this->controller->logs(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testLogsReturns404WhenEndpointNotFound(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->logs(999);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Endpoint not found', $data['error']);
    }

    public function testLogsReturns500OnGenericException(): void
    {
        $this->endpointMapper->method('find')->willReturn(new Endpoint());
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', null, 50],
                ['offset', null, 0],
            ]);
        $this->endpointLogMapper->method('findByEndpoint')
            ->willThrowException(new \Exception('Query failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error retrieving endpoint logs'));

        $result = $this->controller->logs(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to retrieve endpoint logs', $data['error']);
    }

    // ──────────────────────────────────────────────
    // logStats()
    // ──────────────────────────────────────────────

    public function testLogStatsReturnsStatistics(): void
    {
        $this->endpointMapper->method('find')->willReturn(new Endpoint());
        $stats = ['total' => 100, 'success' => 90, 'failed' => 10];
        $this->endpointLogMapper->method('getStatistics')
            ->with(1)
            ->willReturn($stats);

        $result = $this->controller->logStats(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(100, $data['total']);
        $this->assertEquals(90, $data['success']);
        $this->assertEquals(10, $data['failed']);
    }

    public function testLogStatsReturns404WhenEndpointNotFound(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->logStats(999);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Endpoint not found', $data['error']);
    }

    public function testLogStatsReturns500OnGenericException(): void
    {
        $this->endpointMapper->method('find')->willReturn(new Endpoint());
        $this->endpointLogMapper->method('getStatistics')
            ->willThrowException(new \Exception('Stats calculation failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error retrieving endpoint log statistics'));

        $result = $this->controller->logStats(1);

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to retrieve endpoint log statistics', $data['error']);
    }

    // ──────────────────────────────────────────────
    // allLogs()
    // ──────────────────────────────────────────────

    public function testAllLogsReturnsAllLogsWithoutFilter(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['endpoint_id', null, null],
                ['limit', null, 50],
                ['offset', null, 0],
            ]);
        $logs = [new EndpointLog()];
        $this->endpointLogMapper->method('findAll')->willReturn($logs);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertCount(1, $data['results']);
        $this->assertEquals(1, $data['total']);
    }

    public function testAllLogsWithEndpointIdFilter(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['endpoint_id', null, '5'],
                ['limit', null, 10],
                ['offset', null, 0],
            ]);
        $paginatedLogs = [new EndpointLog(), new EndpointLog()];
        $allEndpointLogs = [new EndpointLog(), new EndpointLog(), new EndpointLog()];

        $this->endpointLogMapper->expects($this->exactly(2))
            ->method('findByEndpoint')
            ->willReturnCallback(function (int $endpointId, ?int $limit, ?int $offset) use ($paginatedLogs, $allEndpointLogs) {
                $this->assertEquals(5, $endpointId);
                if ($limit === null && $offset === null) {
                    return $allEndpointLogs;
                }
                return $paginatedLogs;
            });

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(2, $data['results']);
        $this->assertEquals(3, $data['total']);
    }

    public function testAllLogsWithEmptyEndpointIdFallsBackToAll(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['endpoint_id', null, ''],
                ['limit', null, 50],
                ['offset', null, 0],
            ]);
        $this->endpointLogMapper->method('findAll')->willReturn([]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(0, $data['total']);
    }

    public function testAllLogsWithZeroEndpointIdFallsBackToAll(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['endpoint_id', null, '0'],
                ['limit', null, 50],
                ['offset', null, 0],
            ]);
        $this->endpointLogMapper->method('findAll')->willReturn([]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testAllLogsReturns500OnException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['endpoint_id', null, null],
                ['limit', null, 50],
                ['offset', null, 0],
            ]);
        $this->endpointLogMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Error retrieving endpoint logs'));

        $result = $this->controller->allLogs();

        $this->assertEquals(500, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContains('Failed to retrieve endpoint logs', $data['error']);
    }

    public function testAllLogsWithEndpointIdFilterException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['endpoint_id', null, '5'],
                ['limit', null, 50],
                ['offset', null, 0],
            ]);
        $this->endpointLogMapper->method('findByEndpoint')
            ->willThrowException(new \Exception('Query error'));

        $this->logger->expects($this->once())
            ->method('error');

        $result = $this->controller->allLogs();

        $this->assertEquals(500, $result->getStatus());
    }

    // ──────────────────────────────────────────────
    // Helper assertion
    // ──────────────────────────────────────────────

    private static function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        static::assertStringContainsString($needle, $haystack, $message);
    }
}
