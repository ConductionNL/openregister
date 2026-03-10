<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\WorkflowEngineController;
use OCA\OpenRegister\Service\WorkflowEngineRegistry;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WorkflowEngineControllerTest extends TestCase
{
    private WorkflowEngineController $controller;
    private IRequest&MockObject $request;
    private WorkflowEngineRegistry&MockObject $registry;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->registry = $this->createMock(WorkflowEngineRegistry::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new WorkflowEngineController(
            'openregister',
            $this->request,
            $this->registry,
            $this->logger
        );
    }

    private function createEngineEntity(): \OCA\OpenRegister\Db\WorkflowEngine
    {
        $engine = new \OCA\OpenRegister\Db\WorkflowEngine();
        $ref = new \ReflectionClass($engine);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($engine, 1);
        $engine->setName('Test Engine');
        $engine->setEngineType('n8n');
        $engine->setBaseUrl('http://localhost:5678');
        return $engine;
    }

    public function testIndexSuccess(): void
    {
        $engine = $this->createEngineEntity();
        $this->registry->method('getEngines')->willReturn([$engine]);

        $result = $this->controller->index();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data);
        $this->assertEquals('Test Engine', $data[0]['name']);
    }

    public function testShowSuccess(): void
    {
        $engine = $this->createEngineEntity();
        $this->registry->method('getEngine')->with(1)->willReturn($engine);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals('Test Engine', $result->getData()['name']);
    }

    public function testShowNotFound(): void
    {
        $this->registry->method('getEngine')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testCreateInvalidType(): void
    {
        $result = $this->controller->create(
            'Test',
            'invalid_type',
            'http://localhost:5678'
        );

        $this->assertEquals(400, $result->getStatus());
        $this->assertStringContainsString('Invalid engine type', $result->getData()['error']);
    }

    public function testCreateSuccess(): void
    {
        $engine = $this->createEngineEntity();
        $this->registry->method('createEngine')->willReturn($engine);
        $this->registry->method('getEngine')->willReturn($engine);

        $result = $this->controller->create(
            'Test Engine',
            'n8n',
            'http://localhost:5678'
        );

        $this->assertEquals(201, $result->getStatus());
    }

    public function testUpdateSuccess(): void
    {
        $engine = $this->createEngineEntity();
        $this->request->method('getParams')->willReturn(['name' => 'Updated']);
        $this->registry->method('updateEngine')->willReturn($engine);

        $result = $this->controller->update(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateNotFound(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Updated']);
        $this->registry->method('updateEngine')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->update(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $engine = $this->createEngineEntity();
        $this->registry->method('deleteEngine')->willReturn($engine);

        $result = $this->controller->destroy(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testDestroyNotFound(): void
    {
        $this->registry->method('deleteEngine')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testHealthSuccess(): void
    {
        $healthResult = ['status' => 'healthy'];
        $this->registry->method('healthCheck')->willReturn($healthResult);

        $result = $this->controller->health(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($healthResult, $result->getData());
    }

    public function testHealthNotFound(): void
    {
        $this->registry->method('healthCheck')
            ->willThrowException(new DoesNotExistException('not found'));

        $result = $this->controller->health(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testHealthException(): void
    {
        $this->registry->method('healthCheck')
            ->willThrowException(new \Exception('Connection failed'));

        $result = $this->controller->health(1);

        $this->assertEquals(500, $result->getStatus());
    }

    public function testAvailable(): void
    {
        $engines = [['type' => 'n8n', 'name' => 'n8n']];
        $this->registry->method('discoverEngines')->willReturn($engines);

        $result = $this->controller->available();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($engines, $result->getData());
    }
}
