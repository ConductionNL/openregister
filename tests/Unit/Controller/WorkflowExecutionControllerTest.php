<?php

namespace Unit\Controller;

use OCA\OpenRegister\Controller\WorkflowExecutionController;
use OCA\OpenRegister\Db\WorkflowExecution;
use OCA\OpenRegister\Db\WorkflowExecutionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WorkflowExecutionControllerTest extends TestCase
{
    private WorkflowExecutionController $controller;
    private WorkflowExecutionMapper $mapper;
    private IRequest $request;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(WorkflowExecutionMapper::class);
        $this->request = $this->createMock(IRequest::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->controller = new WorkflowExecutionController(
            'openregister',
            $this->request,
            $this->mapper,
            $logger
        );
    }

    public function testIndexReturnsResultsWithPagination(): void
    {
        $exec = new WorkflowExecution();
        $exec->hydrate([
            'uuid' => 'e-1', 'hookId' => 'h1', 'eventType' => 'creating',
            'objectUuid' => 'obj-1', 'engine' => 'n8n', 'workflowId' => 'wf-1',
            'status' => 'approved',
        ]);

        $this->request->method('getParam')
            ->willReturnMap([
                ['objectUuid', null, null],
                ['schemaId', null, null],
                ['hookId', null, null],
                ['status', null, null],
                ['engine', null, null],
                ['since', null, null],
                ['limit', '50', '50'],
                ['offset', '0', '0'],
            ]);

        $this->mapper->expects($this->once())
            ->method('findAll')
            ->willReturn([$exec]);

        $this->mapper->expects($this->once())
            ->method('countAll')
            ->willReturn(1);

        $response = $this->controller->index();
        $data = $response->getData();

        $this->assertSame(1, $data['total']);
        $this->assertSame(50, $data['limit']);
        $this->assertCount(1, $data['results']);
    }

    public function testShowReturnsExecution(): void
    {
        $exec = new WorkflowExecution();
        $exec->hydrate([
            'uuid' => 'e-1', 'hookId' => 'h1', 'eventType' => 'creating',
            'objectUuid' => 'obj-1', 'engine' => 'n8n', 'workflowId' => 'wf-1',
            'status' => 'approved',
        ]);

        $this->mapper->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($exec);

        $response = $this->controller->show(42);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('e-1', $response->getData()['uuid']);
    }

    public function testShowReturns404ForMissing(): void
    {
        $this->mapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $response = $this->controller->show(999);

        $this->assertSame(404, $response->getStatus());
    }

    public function testDestroyDeletesRecord(): void
    {
        $exec = new WorkflowExecution();

        $this->mapper->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($exec);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($exec);

        $response = $this->controller->destroy(42);

        $this->assertSame(200, $response->getStatus());
    }
}
