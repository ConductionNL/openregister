<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\AgentsController;
use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\ToolRegistry;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AgentsControllerTest extends TestCase
{
    private AgentsController $controller;
    private IRequest&MockObject $request;
    private AgentMapper&MockObject $agentMapper;
    private OrganisationService&MockObject $organisationService;
    private ToolRegistry&MockObject $toolRegistry;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->agentMapper = $this->createMock(AgentMapper::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->toolRegistry = $this->createMock(ToolRegistry::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new AgentsController(
            'openregister',
            $this->request,
            $this->agentMapper,
            $this->organisationService,
            $this->toolRegistry,
            $this->logger,
            'testuser'
        );
    }

    public function testPage(): void
    {
        $result = $this->controller->page();

        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testIndexSuccess(): void
    {
        $org = new Organisation();
        $org->setUuid('org-uuid');

        $this->organisationService->method('getActiveOrganisation')->willReturn($org);
        $this->request->method('getParams')->willReturn([]);

        $agents = [new Agent(), new Agent()];
        $this->agentMapper->method('findByOrganisation')->willReturn($agents);

        $result = $this->controller->index();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(2, $data['results']);
    }

    public function testIndexSuccessNoOrganisation(): void
    {
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->request->method('getParams')->willReturn([]);

        $agents = [new Agent()];
        $this->agentMapper->method('findAll')->willReturn($agents);

        $result = $this->controller->index();

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertCount(1, $data['results']);
    }

    public function testIndexException(): void
    {
        $this->organisationService->method('getActiveOrganisation')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->index();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Failed to retrieve agents', $data['error']);
    }

    public function testShowSuccess(): void
    {
        $agent = new Agent();
        $this->agentMapper->method('find')->willReturn($agent);
        $this->agentMapper->method('canUserAccessAgent')->willReturn(true);

        $result = $this->controller->show(1);

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testShowAccessDenied(): void
    {
        $agent = new Agent();
        $this->agentMapper->method('find')->willReturn($agent);
        $this->agentMapper->method('canUserAccessAgent')->willReturn(false);

        $result = $this->controller->show(1);

        $this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Access denied to this agent', $data['error']);
    }

    public function testShowNotFound(): void
    {
        $this->agentMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->controller->show(999);

        $this->assertEquals(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Agent not found', $data['error']);
    }

    public function testCreateSuccess(): void
    {
        $this->request->method('getParams')->willReturn(['name' => 'Test Agent']);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);

        $agent = new Agent();
        $agent->setId(1);
        $agent->setOrganisation(null);
        $agent->setIsPrivate(true);
        $this->agentMapper->method('createFromArray')->willReturn($agent);

        $result = $this->controller->create();

        $this->assertEquals(Http::STATUS_CREATED, $result->getStatus());
    }

    public function testCreateException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->organisationService->method('getActiveOrganisation')->willReturn(null);
        $this->agentMapper->method('createFromArray')
            ->willThrowException(new \Exception('Validation failed'));

        $result = $this->controller->create();

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
        $data = $result->getData();
        $this->assertStringContainsString('Failed to create agent', $data['error']);
    }

    public function testUpdateSuccess(): void
    {
        $agent = new Agent();
        $agent->setOrganisation('org-1');
        $agent->setOwner('testuser');
        $this->agentMapper->method('find')->willReturn($agent);
        $this->agentMapper->method('canUserModifyAgent')->willReturn(true);
        $this->agentMapper->method('update')->willReturn($agent);
        $this->request->method('getParams')->willReturn(['name' => 'Updated']);

        $result = $this->controller->update(1);

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testUpdateForbidden(): void
    {
        $agent = new Agent();
        $this->agentMapper->method('find')->willReturn($agent);
        $this->agentMapper->method('canUserModifyAgent')->willReturn(false);

        $result = $this->controller->update(1);

        $this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
    }

    public function testUpdateException(): void
    {
        $this->agentMapper->method('find')
            ->willThrowException(new \Exception('Not found'));

        $result = $this->controller->update(999);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testPatchDelegatesToUpdate(): void
    {
        $agent = new Agent();
        $agent->setOrganisation('org-1');
        $agent->setOwner('testuser');
        $this->agentMapper->method('find')->willReturn($agent);
        $this->agentMapper->method('canUserModifyAgent')->willReturn(true);
        $this->agentMapper->method('update')->willReturn($agent);
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->patch(1);

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testDestroySuccess(): void
    {
        $agent = new Agent();
        $this->agentMapper->method('find')->willReturn($agent);
        $this->agentMapper->method('canUserModifyAgent')->willReturn(true);

        $result = $this->controller->destroy(1);

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('Agent deleted successfully', $data['message']);
    }

    public function testDestroyNotAuthenticated(): void
    {
        $controller = new AgentsController(
            'openregister',
            $this->request,
            $this->agentMapper,
            $this->organisationService,
            $this->toolRegistry,
            $this->logger,
            null
        );

        $agent = new Agent();
        $this->agentMapper->method('find')->willReturn($agent);

        $result = $controller->destroy(1);

        $this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('User not authenticated', $data['error']);
    }

    public function testDestroyForbidden(): void
    {
        $agent = new Agent();
        $this->agentMapper->method('find')->willReturn($agent);
        $this->agentMapper->method('canUserModifyAgent')->willReturn(false);

        $result = $this->controller->destroy(1);

        $this->assertEquals(Http::STATUS_FORBIDDEN, $result->getStatus());
    }

    public function testDestroyException(): void
    {
        $this->agentMapper->method('find')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->destroy(999);

        $this->assertEquals(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testStatsSuccess(): void
    {
        $this->agentMapper->method('count')
            ->willReturnOnConsecutiveCalls(10, 7, 3);

        $result = $this->controller->stats();

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals(10, $data['total']);
        $this->assertEquals(7, $data['active']);
        $this->assertEquals(3, $data['inactive']);
    }

    public function testStatsException(): void
    {
        $this->agentMapper->method('count')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->stats();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }

    public function testToolsSuccess(): void
    {
        $tools = [['name' => 'tool1'], ['name' => 'tool2']];
        $this->toolRegistry->method('getAllTools')->willReturn($tools);

        $result = $this->controller->tools();

        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
        $data = $result->getData();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(2, $data['results']);
    }

    public function testToolsException(): void
    {
        $this->toolRegistry->method('getAllTools')
            ->willThrowException(new \Exception('Registry error'));

        $result = $this->controller->tools();

        $this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
    }
}
