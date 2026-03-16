<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\N8nSettingsController;
use OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class N8nSettingsControllerTest extends TestCase
{
    private N8nSettingsController $controller;
    private IRequest&MockObject $request;
    private ConfigurationSettingsHandler&MockObject $configHandler;
    private SettingsService&MockObject $settingsService;
    private LoggerInterface&MockObject $logger;
    private IClientService&MockObject $clientService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->configHandler = $this->createMock(ConfigurationSettingsHandler::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->clientService = $this->createMock(IClientService::class);

        $this->controller = new N8nSettingsController(
            'openregister',
            $this->request,
            $this->configHandler,
            $this->settingsService,
            $this->logger,
            $this->clientService
        );
    }

    // -------------------------------------------------------------------------
    // getN8nSettings
    // -------------------------------------------------------------------------

    public function testGetN8nSettingsSuccess(): void
    {
        $settings = ['url' => 'http://localhost:5678', 'apiKey' => 'secret123'];
        $this->configHandler->method('getN8nSettingsOnly')->willReturn($settings);
        $this->settingsService->method('maskToken')->willReturn('sec***123');

        $result = $this->controller->getN8nSettings();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertEquals('http://localhost:5678', $data['url']);
        $this->assertEquals('sec***123', $data['apiKey']);
    }

    public function testGetN8nSettingsEmptyApiKey(): void
    {
        $settings = ['url' => 'http://localhost:5678', 'apiKey' => ''];
        $this->configHandler->method('getN8nSettingsOnly')->willReturn($settings);
        $this->settingsService->expects($this->never())->method('maskToken');

        $result = $this->controller->getN8nSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals('', $result->getData()['apiKey']);
    }

    public function testGetN8nSettingsNullApiKey(): void
    {
        $settings = ['url' => 'http://localhost:5678'];
        $this->configHandler->method('getN8nSettingsOnly')->willReturn($settings);
        $this->settingsService->expects($this->never())->method('maskToken');

        $result = $this->controller->getN8nSettings();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetN8nSettingsException(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getN8nSettings();

        $this->assertEquals(500, $result->getStatus());
        $this->assertStringContainsString('DB error', $result->getData()['error']);
    }

    // -------------------------------------------------------------------------
    // updateN8nSettings
    // -------------------------------------------------------------------------

    public function testUpdateN8nSettingsSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'url' => 'http://localhost:5678',
            'apiKey' => 'newkey',
        ]);
        $this->configHandler->method('updateN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'newkey']);
        $this->settingsService->method('maskToken')->willReturn('new***key');

        $result = $this->controller->updateN8nSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('n8n settings saved successfully', $result->getData()['message']);
        $this->assertEquals('new***key', $result->getData()['data']['apiKey']);
    }

    public function testUpdateN8nSettingsPreservesMaskedApiKey(): void
    {
        $this->request->method('getParams')->willReturn([
            'apiKey' => 'sec***123',
        ]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['apiKey' => 'original_secret_key']);
        $this->configHandler->expects($this->once())
            ->method('updateN8nSettingsOnly')
            ->with($this->callback(function ($data) {
                return $data['apiKey'] === 'original_secret_key';
            }))
            ->willReturn(['apiKey' => 'original_secret_key']);
        $this->settingsService->method('maskToken')->willReturn('ori***key');

        $result = $this->controller->updateN8nSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testUpdateN8nSettingsEmptyApiKeyInResult(): void
    {
        $this->request->method('getParams')->willReturn([
            'url' => 'http://localhost:5678',
        ]);
        $this->configHandler->method('updateN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => '']);
        $this->settingsService->expects($this->never())->method('maskToken');

        $result = $this->controller->updateN8nSettings();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('', $result->getData()['data']['apiKey']);
    }

    public function testUpdateN8nSettingsNoApiKeyInParams(): void
    {
        $this->request->method('getParams')->willReturn([
            'url' => 'http://localhost:5678',
        ]);
        $this->configHandler->expects($this->never())->method('getN8nSettingsOnly');
        $this->configHandler->method('updateN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'existing']);
        $this->settingsService->method('maskToken')->willReturn('exi***ing');

        $result = $this->controller->updateN8nSettings();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testUpdateN8nSettingsException(): void
    {
        $this->request->method('getParams')->willReturn(['url' => 'http://localhost']);
        $this->configHandler->method('updateN8nSettingsOnly')
            ->willThrowException(new \Exception('Save failed'));

        $result = $this->controller->updateN8nSettings();

        $this->assertEquals(500, $result->getStatus());
        $this->assertStringContainsString('Save failed', $result->getData()['error']);
    }

    // -------------------------------------------------------------------------
    // testN8nConnection
    // -------------------------------------------------------------------------

    public function testTestN8nConnectionMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $result = $this->controller->testN8nConnection();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertEquals('n8n URL and API key are required', $result->getData()['message']);
    }

    public function testTestN8nConnectionMissingUrl(): void
    {
        $this->request->method('getParams')->willReturn(['apiKey' => 'key']);

        $result = $this->controller->testN8nConnection();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testTestN8nConnectionMissingApiKey(): void
    {
        $this->request->method('getParams')->willReturn(['url' => 'http://localhost:5678']);

        $result = $this->controller->testN8nConnection();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testTestN8nConnectionSuccess(): void
    {
        $this->request->method('getParams')->willReturn([
            'url' => 'http://localhost:5678',
            'apiKey' => 'testkey',
        ]);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode(['data' => [['id' => 1]]]));

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->testN8nConnection();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('n8n connection successful', $result->getData()['message']);
        $this->assertEquals('Connected', $result->getData()['details']['version']);
        $this->assertEquals(1, $result->getData()['details']['users']);
    }

    public function testTestN8nConnectionSuccessWithTrailingSlash(): void
    {
        $this->request->method('getParams')->willReturn([
            'url' => 'http://localhost:5678/',
            'apiKey' => 'testkey',
        ]);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode(['data' => []]));

        $client = $this->createMock(IClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with(
                'http://localhost:5678/api/v1/users',
                $this->anything()
            )
            ->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->testN8nConnection();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals(0, $result->getData()['details']['users']);
    }

    public function testTestN8nConnectionSuccessEmptyDataField(): void
    {
        $this->request->method('getParams')->willReturn([
            'url' => 'http://localhost:5678',
            'apiKey' => 'testkey',
        ]);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn(json_encode([]));

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->testN8nConnection();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals(0, $result->getData()['details']['users']);
    }

    public function testTestN8nConnectionNon2xxStatus(): void
    {
        $this->request->method('getParams')->willReturn([
            'url' => 'http://localhost:5678',
            'apiKey' => 'testkey',
        ]);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(401);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->testN8nConnection();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertStringContainsString('401', $result->getData()['message']);
    }

    public function testTestN8nConnectionStatus300(): void
    {
        $this->request->method('getParams')->willReturn([
            'url' => 'http://localhost:5678',
            'apiKey' => 'testkey',
        ]);

        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(300);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($response);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->testN8nConnection();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testTestN8nConnectionException(): void
    {
        $this->request->method('getParams')->willReturn([
            'url' => 'http://localhost:5678',
            'apiKey' => 'testkey',
        ]);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willThrowException(new \Exception('Connection refused'));
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->testN8nConnection();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertStringContainsString('Connection refused', $result->getData()['message']);
    }

    // -------------------------------------------------------------------------
    // initializeN8n
    // -------------------------------------------------------------------------

    public function testInitializeN8nMissingConfig(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => '', 'apiKey' => '']);

        $result = $this->controller->initializeN8n();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertEquals('n8n connection not configured', $result->getData()['message']);
    }

    public function testInitializeN8nMissingUrl(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => '', 'apiKey' => 'key']);

        $result = $this->controller->initializeN8n();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testInitializeN8nMissingApiKey(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => '']);

        $result = $this->controller->initializeN8n();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testInitializeN8nExistingProject(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key']);

        $projectsResponse = $this->createMock(IResponse::class);
        $projectsResponse->method('getBody')->willReturn(json_encode([
            'data' => [
                ['id' => 'proj-123', 'name' => 'openregister'],
            ],
        ]));

        $workflowsResponse = $this->createMock(IResponse::class);
        $workflowsResponse->method('getBody')->willReturn(json_encode([
            'data' => [
                ['id' => 'wf-1', 'name' => 'Workflow 1'],
                ['id' => 'wf-2', 'name' => 'Workflow 2'],
            ],
        ]));

        $client = $this->createMock(IClient::class);
        $client->method('get')
            ->willReturnOnConsecutiveCalls($projectsResponse, $workflowsResponse);
        $client->expects($this->never())->method('post');
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->initializeN8n();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('n8n project initialized successfully', $result->getData()['message']);
        $this->assertEquals('openregister', $result->getData()['details']['project']);
        $this->assertEquals('proj-123', $result->getData()['details']['projectId']);
        $this->assertEquals(2, $result->getData()['details']['workflows']);
    }

    public function testInitializeN8nCreatesNewProject(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678/', 'apiKey' => 'key']);

        $projectsResponse = $this->createMock(IResponse::class);
        $projectsResponse->method('getBody')->willReturn(json_encode([
            'data' => [
                ['id' => 'other-proj', 'name' => 'some-other-project'],
            ],
        ]));

        $createResponse = $this->createMock(IResponse::class);
        $createResponse->method('getBody')->willReturn(json_encode([
            'id' => 'new-proj-456',
            'name' => 'openregister',
        ]));

        $workflowsResponse = $this->createMock(IResponse::class);
        $workflowsResponse->method('getBody')->willReturn(json_encode([
            'data' => [],
        ]));

        $client = $this->createMock(IClient::class);
        $client->method('get')
            ->willReturnOnConsecutiveCalls($projectsResponse, $workflowsResponse);
        $client->expects($this->once())->method('post')->willReturn($createResponse);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->initializeN8n();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('new-proj-456', $result->getData()['details']['projectId']);
        $this->assertEquals(0, $result->getData()['details']['workflows']);
    }

    public function testInitializeN8nCreatesNewProjectWithCustomName(): void
    {
        $this->request->method('getParams')->willReturn(['project' => 'my-custom-project']);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key']);

        $projectsResponse = $this->createMock(IResponse::class);
        $projectsResponse->method('getBody')->willReturn(json_encode(['data' => []]));

        $createResponse = $this->createMock(IResponse::class);
        $createResponse->method('getBody')->willReturn(json_encode([
            'id' => 'custom-proj-789',
            'name' => 'my-custom-project',
        ]));

        $workflowsResponse = $this->createMock(IResponse::class);
        $workflowsResponse->method('getBody')->willReturn(json_encode(['data' => []]));

        $client = $this->createMock(IClient::class);
        $client->method('get')
            ->willReturnOnConsecutiveCalls($projectsResponse, $workflowsResponse);
        $client->method('post')->willReturn($createResponse);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->initializeN8n();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEquals('my-custom-project', $result->getData()['details']['project']);
        $this->assertEquals('custom-proj-789', $result->getData()['details']['projectId']);
    }

    public function testInitializeN8nProjectCreationReturnsNullId(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key']);

        $projectsResponse = $this->createMock(IResponse::class);
        $projectsResponse->method('getBody')->willReturn(json_encode(['data' => []]));

        $createResponse = $this->createMock(IResponse::class);
        $createResponse->method('getBody')->willReturn(json_encode(['name' => 'openregister']));

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($projectsResponse);
        $client->method('post')->willReturn($createResponse);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->initializeN8n();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertEquals('Failed to create or find project', $result->getData()['message']);
    }

    public function testInitializeN8nEmptyProjectsList(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key']);

        $projectsResponse = $this->createMock(IResponse::class);
        // Response without 'data' key at all.
        $projectsResponse->method('getBody')->willReturn(json_encode([]));

        $createResponse = $this->createMock(IResponse::class);
        $createResponse->method('getBody')->willReturn(json_encode([
            'id' => 'new-id',
            'name' => 'openregister',
        ]));

        $workflowsResponse = $this->createMock(IResponse::class);
        $workflowsResponse->method('getBody')->willReturn(json_encode(['data' => []]));

        $client = $this->createMock(IClient::class);
        $client->method('get')
            ->willReturnOnConsecutiveCalls($projectsResponse, $workflowsResponse);
        $client->method('post')->willReturn($createResponse);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->initializeN8n();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testInitializeN8nInnerException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key']);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willThrowException(new \Exception('API timeout'));
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->initializeN8n();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertStringContainsString('API timeout', $result->getData()['message']);
    }

    public function testInitializeN8nOuterException(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willThrowException(new \Exception('Config error'));

        $result = $this->controller->initializeN8n();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertStringContainsString('Config error', $result->getData()['message']);
    }

    public function testInitializeN8nWorkflowsWithNoDataKey(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key']);

        $projectsResponse = $this->createMock(IResponse::class);
        $projectsResponse->method('getBody')->willReturn(json_encode([
            'data' => [['id' => 'proj-1', 'name' => 'openregister']],
        ]));

        $workflowsResponse = $this->createMock(IResponse::class);
        $workflowsResponse->method('getBody')->willReturn(json_encode([]));

        $client = $this->createMock(IClient::class);
        $client->method('get')
            ->willReturnOnConsecutiveCalls($projectsResponse, $workflowsResponse);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->initializeN8n();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals(0, $result->getData()['details']['workflows']);
    }

    // -------------------------------------------------------------------------
    // getWorkflows
    // -------------------------------------------------------------------------

    public function testGetWorkflowsMissingConfig(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => '', 'apiKey' => '']);

        $result = $this->controller->getWorkflows();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertEquals('n8n connection not configured', $result->getData()['message']);
    }

    public function testGetWorkflowsMissingUrl(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => '', 'apiKey' => 'key']);

        $result = $this->controller->getWorkflows();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testGetWorkflowsMissingApiKey(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => '']);

        $result = $this->controller->getWorkflows();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testGetWorkflowsSuccess(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key', 'project' => 'openregister']);

        $projectsResponse = $this->createMock(IResponse::class);
        $projectsResponse->method('getBody')->willReturn(json_encode([
            'data' => [['id' => 'proj-1', 'name' => 'openregister']],
        ]));

        $workflowsResponse = $this->createMock(IResponse::class);
        $workflowsResponse->method('getBody')->willReturn(json_encode([
            'data' => [
                ['id' => 'wf-1', 'name' => 'Workflow A'],
                ['id' => 'wf-2', 'name' => 'Workflow B'],
            ],
        ]));

        $client = $this->createMock(IClient::class);
        $client->method('get')
            ->willReturnOnConsecutiveCalls($projectsResponse, $workflowsResponse);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->getWorkflows();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertCount(2, $result->getData()['workflows']);
    }

    public function testGetWorkflowsWithTrailingSlashUrl(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678/', 'apiKey' => 'key', 'project' => 'openregister']);

        $projectsResponse = $this->createMock(IResponse::class);
        $projectsResponse->method('getBody')->willReturn(json_encode([
            'data' => [['id' => 'proj-1', 'name' => 'openregister']],
        ]));

        $workflowsResponse = $this->createMock(IResponse::class);
        $workflowsResponse->method('getBody')->willReturn(json_encode(['data' => []]));

        $client = $this->createMock(IClient::class);
        $client->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls($projectsResponse, $workflowsResponse);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->getWorkflows();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testGetWorkflowsProjectNotFound(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key', 'project' => 'openregister']);

        $projectsResponse = $this->createMock(IResponse::class);
        $projectsResponse->method('getBody')->willReturn(json_encode([
            'data' => [['id' => 'other-proj', 'name' => 'some-other-project']],
        ]));

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($projectsResponse);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->getWorkflows();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEmpty($result->getData()['workflows']);
        $this->assertEquals('Project not found. Please initialize first.', $result->getData()['message']);
    }

    public function testGetWorkflowsProjectNotFoundEmptyList(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key', 'project' => 'openregister']);

        $projectsResponse = $this->createMock(IResponse::class);
        $projectsResponse->method('getBody')->willReturn(json_encode(['data' => []]));

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($projectsResponse);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->getWorkflows();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEmpty($result->getData()['workflows']);
    }

    public function testGetWorkflowsDefaultProjectName(): void
    {
        // No 'project' key in settings - should default to 'openregister'.
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key']);

        $projectsResponse = $this->createMock(IResponse::class);
        $projectsResponse->method('getBody')->willReturn(json_encode([
            'data' => [['id' => 'proj-1', 'name' => 'openregister']],
        ]));

        $workflowsResponse = $this->createMock(IResponse::class);
        $workflowsResponse->method('getBody')->willReturn(json_encode(['data' => []]));

        $client = $this->createMock(IClient::class);
        $client->method('get')
            ->willReturnOnConsecutiveCalls($projectsResponse, $workflowsResponse);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->getWorkflows();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testGetWorkflowsException(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key']);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willThrowException(new \Exception('Connection refused'));
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->getWorkflows();

        $this->assertEquals(500, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
        $this->assertStringContainsString('Connection refused', $result->getData()['message']);
    }

    public function testGetWorkflowsNoDataKeyInResponse(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => 'http://localhost:5678', 'apiKey' => 'key', 'project' => 'openregister']);

        $projectsResponse = $this->createMock(IResponse::class);
        $projectsResponse->method('getBody')->willReturn(json_encode([
            'data' => [['id' => 'proj-1', 'name' => 'openregister']],
        ]));

        $workflowsResponse = $this->createMock(IResponse::class);
        $workflowsResponse->method('getBody')->willReturn(json_encode([]));

        $client = $this->createMock(IClient::class);
        $client->method('get')
            ->willReturnOnConsecutiveCalls($projectsResponse, $workflowsResponse);
        $this->clientService->method('newClient')->willReturn($client);

        $result = $this->controller->getWorkflows();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
        $this->assertEmpty($result->getData()['workflows']);
    }
}
