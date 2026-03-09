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

    public function testGetN8nSettingsSuccess(): void
    {
        $settings = ['url' => 'http://localhost:5678', 'apiKey' => 'secret123'];
        $this->configHandler->method('getN8nSettingsOnly')->willReturn($settings);
        $this->settingsService->method('maskToken')->willReturn('sec***123');

        $result = $this->controller->getN8nSettings();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetN8nSettingsEmptyApiKey(): void
    {
        $settings = ['url' => 'http://localhost:5678', 'apiKey' => ''];
        $this->configHandler->method('getN8nSettingsOnly')->willReturn($settings);

        $result = $this->controller->getN8nSettings();

        $this->assertEquals(200, $result->getStatus());
    }

    public function testGetN8nSettingsException(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->getN8nSettings();

        $this->assertEquals(500, $result->getStatus());
    }

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

        $this->controller->updateN8nSettings();
    }

    public function testUpdateN8nSettingsException(): void
    {
        $this->request->method('getParams')->willReturn(['url' => 'http://localhost']);
        $this->configHandler->method('updateN8nSettingsOnly')
            ->willThrowException(new \Exception('Failed'));

        $result = $this->controller->updateN8nSettings();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testTestN8nConnectionMissingParams(): void
    {
        $this->request->method('getParams')->willReturn([]);

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
    }

    public function testInitializeN8nMissingConfig(): void
    {
        $this->request->method('getParams')->willReturn([]);
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => '', 'apiKey' => '']);

        $result = $this->controller->initializeN8n();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
    }

    public function testGetWorkflowsMissingConfig(): void
    {
        $this->configHandler->method('getN8nSettingsOnly')
            ->willReturn(['url' => '', 'apiKey' => '']);

        $result = $this->controller->getWorkflows();

        $this->assertEquals(400, $result->getStatus());
        $this->assertFalse($result->getData()['success']);
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
    }
}
