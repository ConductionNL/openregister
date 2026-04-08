<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ConsumersController;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConsumersControllerTest extends TestCase
{
    private ConsumersController $controller;
    private IRequest&MockObject $request;
    private ConsumerMapper&MockObject $consumerMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->consumerMapper = $this->createMock(ConsumerMapper::class);

        $this->controller = new ConsumersController(
            'openregister',
            $this->request,
            $this->consumerMapper,
            $this->createMock(IL10N::class)
        );
    }

    public function testIndexSuccess(): void
    {
        $consumers = [new Consumer(), new Consumer()];
        $this->consumerMapper->method('findAll')->willReturn($consumers);
        $this->consumerMapper->method('getTotalCallCount')->willReturn(100);

        $result = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(2, $data['results']);
        $this->assertEquals(100, $data['total']);
    }

    public function testShowSuccess(): void
    {
        $consumer = new Consumer();
        $this->consumerMapper->method('find')->willReturn($consumer);

        $result = $this->controller->show(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testShowNotFound(): void
    {
        $this->consumerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(404, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Consumer not found', $data['error']);
    }

    public function testCreateSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Test Consumer',
            '_route' => 'some.route',
        ]);

        $consumer = new Consumer();
        $this->consumerMapper->method('createFromArray')->willReturn($consumer);

        $result = $this->controller->create();

        $this->assertEquals(201, $result->getStatus());
    }

    public function testUpdateSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'name' => 'Updated',
            '_route' => 'some.route',
            'id' => 1,
        ]);

        $consumer = new Consumer();
        $this->consumerMapper->method('updateFromArray')->willReturn($consumer);

        $result = $this->controller->update(1);

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateNotFound(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->consumerMapper->method('updateFromArray')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->update(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $consumer = new Consumer();
        $this->consumerMapper->method('find')->willReturn($consumer);

        $result = $this->controller->destroy(1);

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals([], $result->getData());
    }

    public function testDestroyNotFound(): void
    {
        $this->consumerMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(404, $result->getStatus());
    }

    public function testPatchDelegatesToUpdate(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $consumer = new Consumer();
        $this->consumerMapper->method('updateFromArray')->willReturn($consumer);

        $result = $this->controller->patch(1);

        $this->assertEquals(200, $result->getStatus());
    }
}
