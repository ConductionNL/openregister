<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\EndpointsController;
use OCA\OpenRegister\Db\Endpoint;
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

    public function testIndexSuccess(): void
    {
        $endpoints = [new Endpoint(), new Endpoint()];
        $this->endpointMapper->method('findAll')->willReturn($endpoints);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertEquals(2, $data['total']);
    }

    public function testIndexException(): void
    {
        $this->endpointMapper->method('findAll')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->index();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testShowSuccess(): void
    {
        $endpoint = new Endpoint();
        $this->endpointMapper->method('find')->willReturn($endpoint);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testShowNotFound(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testShowException(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->show(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testCreateMissingRequiredFields(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->create();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testCreateSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Test',
            'endpoint' => '/api/test',
        ]);

        $endpoint = new Endpoint();
        $endpoint->setId(1);
        $endpoint->setName('Test');
        $endpoint->setEndpoint('/api/test');
        $this->endpointMapper->method('createFromArray')->willReturn($endpoint);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testCreateException(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Test',
            'endpoint' => '/api/test',
        ]);
        $this->endpointMapper->method('createFromArray')
            ->willThrowException(new \Exception('Create error'));

        $result = $this->controller->create();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testUpdateSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Updated']);
        $endpoint = new Endpoint();
        $endpoint->setId(1);
        $endpoint->setName('Updated');
        $this->endpointMapper->method('updateFromArray')->willReturn($endpoint);

        $result = $this->controller->update(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->endpointMapper->method('updateFromArray')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->update(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $endpoint = new Endpoint();
        $endpoint->setId(1);
        $endpoint->setName('Test');
        $this->endpointMapper->method('find')->willReturn($endpoint);

        $result = $this->controller->destroy(1);

        $this->assertEquals(204, $result->getStatus());
    }

    public function testDestroyNotFound(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testTestEndpointSuccess(): void
    {
        $endpoint = new Endpoint();
        $this->endpointMapper->method('find')->willReturn($endpoint);
        $this->request->method('getParams')->willReturn([]);
        $this->endpointService->method('testEndpoint')->willReturn([
            'success' => true,
            'statusCode' => 200,
            'response' => 'OK',
        ]);

        $result = $this->controller->test(1);

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['success']);
    }

    public function testTestEndpointFailure(): void
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
    }

    public function testTestEndpointNotFound(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->test(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testLogsSuccess(): void
    {
        $this->endpointMapper->method('find')->willReturn(new Endpoint());
        $this->request->method('getParam')
            ->willReturnMap([
                ['limit', null, 50],
                ['offset', null, 0],
            ]);
        $this->endpointLogMapper->method('findByEndpoint')->willReturn([]);

        $result = $this->controller->logs(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testLogsNotFound(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->logs(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testLogStatsSuccess(): void
    {
        $this->endpointMapper->method('find')->willReturn(new Endpoint());
        $this->endpointLogMapper->method('getStatistics')
            ->willReturn(['total' => 10, 'success' => 8, 'failed' => 2]);

        $result = $this->controller->logStats(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testLogStatsNotFound(): void
    {
        $this->endpointMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->logStats(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testAllLogsSuccess(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['endpoint_id', null, null],
                ['limit', null, 50],
                ['offset', null, 0],
            ]);
        $this->endpointLogMapper->method('findAll')->willReturn([]);

        $result = $this->controller->allLogs();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testAllLogsException(): void
    {
        $this->request->method('getParam')
            ->willReturnMap([
                ['endpoint_id', null, null],
                ['limit', null, 50],
                ['offset', null, 0],
            ]);
        $this->endpointLogMapper->method('findAll')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->allLogs();

        $this->assertEquals(500, $result->getStatus());
    }
}
