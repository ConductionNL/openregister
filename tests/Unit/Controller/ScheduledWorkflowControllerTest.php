<?php

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ScheduledWorkflowController;
use OCA\OpenRegister\Db\ScheduledWorkflow;
use OCA\OpenRegister\Db\ScheduledWorkflowMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScheduledWorkflowControllerTest extends TestCase
{
    private ScheduledWorkflowController $controller;
    private ScheduledWorkflowMapper $mapper;
    private IRequest $request;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(ScheduledWorkflowMapper::class);
        $this->request = $this->createMock(IRequest::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->controller = new ScheduledWorkflowController(
            'openregister',
            $this->request,
            $this->mapper,
            $logger
        );
    }

    public function testIndexReturnsAllWorkflows(): void
    {
        $wf = new ScheduledWorkflow();
        $wf->hydrate(['uuid' => 's-1', 'name' => 'Test', 'engine' => 'n8n', 'workflowId' => 'wf-1']);

        $this->mapper->expects($this->once())
            ->method('findAll')
            ->willReturn([$wf]);

        $response = $this->controller->index();
        $data = $response->getData();

        $this->assertCount(1, $data);
        $this->assertSame('s-1', $data[0]['uuid']);
    }

    public function testShowReturns404ForMissing(): void
    {
        $this->mapper->expects($this->once())
            ->method('find')
            ->willThrowException(new DoesNotExistException('not found'));

        $response = $this->controller->show(999);

        $this->assertSame(404, $response->getStatus());
    }

    public function testCreateReturns201(): void
    {
        $wf = new ScheduledWorkflow();
        $wf->hydrate(['uuid' => 's-2', 'name' => 'New', 'engine' => 'n8n', 'workflowId' => 'wf-2']);

        $this->request->method('getParams')
            ->willReturn(['name' => 'New', 'engine' => 'n8n', 'workflowId' => 'wf-2']);

        $this->mapper->expects($this->once())
            ->method('createFromArray')
            ->willReturn($wf);

        $response = $this->controller->create();

        $this->assertSame(201, $response->getStatus());
    }
}
