<?php

namespace Unit\WorkflowEngine;

use OCA\OpenRegister\WorkflowEngine\N8nAdapter;
use OCA\OpenRegister\WorkflowEngine\WorkflowResult;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class N8nAdapterTest extends TestCase
{
    private IClientService&MockObject $clientService;
    private LoggerInterface&MockObject $logger;
    private IClient&MockObject $client;
    private N8nAdapter $adapter;

    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = $this->createMock(IClient::class);

        $this->clientService->method('newClient')->willReturn($this->client);

        $this->adapter = new N8nAdapter($this->clientService, $this->logger);
        $this->adapter->configure('https://n8n.example.com', [
            'authType' => 'bearer',
            'token' => 'test-token',
        ]);
    }

    // --- configure() ---

    public function testConfigureTrimsTrailingSlash(): void
    {
        $this->adapter->configure('https://n8n.example.com/', []);
        // Verify by checking webhook URL (uses baseUrl)
        $this->assertSame('https://n8n.example.com/webhook/wf-1', $this->adapter->getWebhookUrl('wf-1'));
    }

    // --- deployWorkflow() ---

    public function testDeployWorkflowSuccess(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['id' => '42']));

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'https://n8n.example.com/rest/workflows',
                $this->callback(function ($opts) {
                    return $opts['json'] === ['name' => 'test'] && $opts['headers']['Authorization'] === 'Bearer test-token';
                })
            )
            ->willReturn($response);

        $id = $this->adapter->deployWorkflow(['name' => 'test']);
        $this->assertSame('42', $id);
    }

    public function testDeployWorkflowMissingId(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([]));

        $this->client->method('post')->willReturn($response);

        $id = $this->adapter->deployWorkflow([]);
        $this->assertSame('', $id);
    }

    // --- updateWorkflow() ---

    public function testUpdateWorkflowReturnsIdFromResponse(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['id' => '99']));

        $this->client->expects($this->once())
            ->method('patch')
            ->with(
                'https://n8n.example.com/rest/workflows/42',
                $this->anything()
            )
            ->willReturn($response);

        $this->assertSame('99', $this->adapter->updateWorkflow('42', ['name' => 'updated']));
    }

    public function testUpdateWorkflowFallsBackToInputId(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([]));

        $this->client->method('patch')->willReturn($response);

        $this->assertSame('42', $this->adapter->updateWorkflow('42', []));
    }

    // --- getWorkflow() ---

    public function testGetWorkflowReturnsData(): void
    {
        $expected = ['id' => '1', 'name' => 'My Flow'];
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode($expected));

        $this->client->expects($this->once())
            ->method('get')
            ->with('https://n8n.example.com/rest/workflows/1', $this->anything())
            ->willReturn($response);

        $this->assertSame($expected, $this->adapter->getWorkflow('1'));
    }

    public function testGetWorkflowReturnsEmptyArrayOnNull(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('null');

        $this->client->method('get')->willReturn($response);

        $this->assertSame([], $this->adapter->getWorkflow('1'));
    }

    // --- deleteWorkflow() ---

    public function testDeleteWorkflowCallsDelete(): void
    {
        $this->client->expects($this->once())
            ->method('delete')
            ->with('https://n8n.example.com/rest/workflows/5', $this->anything());

        $this->adapter->deleteWorkflow('5');
    }

    // --- activateWorkflow() ---

    public function testActivateWorkflowSendsActiveTrue(): void
    {
        $this->client->expects($this->once())
            ->method('patch')
            ->with(
                'https://n8n.example.com/rest/workflows/5',
                $this->callback(function ($opts) {
                    return $opts['json'] === ['active' => true];
                })
            );

        $this->adapter->activateWorkflow('5');
    }

    // --- deactivateWorkflow() ---

    public function testDeactivateWorkflowSendsActiveFalse(): void
    {
        $this->client->expects($this->once())
            ->method('patch')
            ->with(
                'https://n8n.example.com/rest/workflows/5',
                $this->callback(function ($opts) {
                    return $opts['json'] === ['active' => false];
                })
            );

        $this->adapter->deactivateWorkflow('5');
    }

    // --- executeWorkflow() ---

    public function testExecuteWorkflowApproved(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['status' => 'approved']));

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('wf-1', ['data' => true]);
        $this->assertTrue($result->isApproved());
        $this->assertSame('n8n', $result->getMetadata()['engine']);
    }

    public function testExecuteWorkflowRejected(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'status' => 'rejected',
            'errors' => [['message' => 'bad']],
            'metadata' => ['extra' => 'info'],
        ]));

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('wf-1', []);
        $this->assertTrue($result->isRejected());
        $this->assertSame([['message' => 'bad']], $result->getErrors());
        $this->assertSame('n8n', $result->getMetadata()['engine']);
        $this->assertSame('info', $result->getMetadata()['extra']);
    }

    public function testExecuteWorkflowModified(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'status' => 'modified',
            'data' => ['name' => 'new'],
        ]));

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('wf-1', []);
        $this->assertTrue($result->isModified());
        $this->assertSame(['name' => 'new'], $result->getData());
    }

    public function testExecuteWorkflowError(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'status' => 'error',
            'errors' => [['message' => 'Something failed']],
        ]));

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('wf-1', []);
        $this->assertTrue($result->isError());
        $this->assertSame('Something failed', $result->getErrors()[0]['message']);
    }

    public function testExecuteWorkflowNullResponseReturnsApproved(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('null');

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('wf-1', []);
        $this->assertTrue($result->isApproved());
    }

    public function testExecuteWorkflowDefaultStatusIsApproved(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['status' => 'unknown_status']));

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('wf-1', []);
        $this->assertTrue($result->isApproved());
    }

    public function testExecuteWorkflowExceptionReturnsError(): void
    {
        $this->client->method('post')->willThrowException(new \RuntimeException('Connection refused'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->adapter->executeWorkflow('wf-1', []);
        $this->assertTrue($result->isError());
        $this->assertSame('Connection refused', $result->getErrors()[0]['message']);
        $this->assertSame('n8n', $result->getMetadata()['engine']);
        $this->assertSame('wf-1', $result->getMetadata()['workflowId']);
    }

    public function testExecuteWorkflowTimeoutReturnsTimeoutError(): void
    {
        $this->client->method('post')->willThrowException(new \RuntimeException('Request timed out'));

        $result = $this->adapter->executeWorkflow('wf-1', [], 10);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('timed out after 10 seconds', $result->getErrors()[0]['message']);
    }

    public function testExecuteWorkflowTimeoutKeywordAlsoMatches(): void
    {
        $this->client->method('post')->willThrowException(new \RuntimeException('Connection timeout'));

        $result = $this->adapter->executeWorkflow('wf-1', [], 30);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('timed out after 30 seconds', $result->getErrors()[0]['message']);
    }

    public function testExecuteWorkflowUsesWebhookUrl(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['status' => 'approved']));

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'https://n8n.example.com/webhook/wf-1',
                $this->callback(function ($opts) {
                    return $opts['json'] === ['input' => 'data'] && $opts['timeout'] === 15;
                })
            )
            ->willReturn($response);

        $this->adapter->executeWorkflow('wf-1', ['input' => 'data'], 15);
    }

    // --- getWebhookUrl() ---

    public function testGetWebhookUrl(): void
    {
        $this->assertSame('https://n8n.example.com/webhook/wf-42', $this->adapter->getWebhookUrl('wf-42'));
    }

    // --- listWorkflows() ---

    public function testListWorkflowsSuccess(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'data' => [
                ['id' => 1, 'name' => 'Flow A', 'active' => true],
                ['id' => 2, 'name' => 'Flow B', 'active' => false],
            ],
        ]));

        $this->client->method('get')->willReturn($response);

        $workflows = $this->adapter->listWorkflows();
        $this->assertCount(2, $workflows);
        $this->assertSame('1', $workflows[0]['id']);
        $this->assertSame('Flow A', $workflows[0]['name']);
        $this->assertTrue($workflows[0]['active']);
        $this->assertFalse($workflows[1]['active']);
    }

    public function testListWorkflowsEmptyData(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([]));

        $this->client->method('get')->willReturn($response);

        $this->assertSame([], $this->adapter->listWorkflows());
    }

    public function testListWorkflowsMissingNameDefaults(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'data' => [['id' => 1]],
        ]));

        $this->client->method('get')->willReturn($response);

        $workflows = $this->adapter->listWorkflows();
        $this->assertSame('', $workflows[0]['name']);
        $this->assertFalse($workflows[0]['active']);
    }

    public function testListWorkflowsExceptionReturnsEmpty(): void
    {
        $this->client->method('get')->willThrowException(new \RuntimeException('fail'));

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame([], $this->adapter->listWorkflows());
    }

    // --- healthCheck() ---

    public function testHealthCheckReturnsTrue(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(200);

        $this->client->expects($this->once())
            ->method('get')
            ->with(
                'https://n8n.example.com/rest/settings',
                $this->callback(function ($opts) {
                    return $opts['timeout'] === 5;
                })
            )
            ->willReturn($response);

        $this->assertTrue($this->adapter->healthCheck());
    }

    public function testHealthCheckReturnsFalseOnNon200(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn(500);

        $this->client->method('get')->willReturn($response);

        $this->assertFalse($this->adapter->healthCheck());
    }

    public function testHealthCheckReturnsFalseOnException(): void
    {
        $this->client->method('get')->willThrowException(new \RuntimeException('down'));

        $this->logger->expects($this->once())->method('debug');

        $this->assertFalse($this->adapter->healthCheck());
    }

    // --- Auth headers ---

    public function testBearerAuthHeader(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['id' => '1']));

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function ($opts) {
                    return $opts['headers']['Authorization'] === 'Bearer test-token'
                        && $opts['headers']['Accept'] === 'application/json';
                })
            )
            ->willReturn($response);

        $this->adapter->deployWorkflow([]);
    }

    public function testBasicAuthHeader(): void
    {
        $this->adapter->configure('https://n8n.example.com', [
            'authType' => 'basic',
            'username' => 'user',
            'password' => 'pass',
        ]);

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['id' => '1']));

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function ($opts) {
                    return $opts['headers']['Authorization'] === 'Basic ' . base64_encode('user:pass');
                })
            )
            ->willReturn($response);

        $this->adapter->deployWorkflow([]);
    }

    public function testNoAuthHeader(): void
    {
        $this->adapter->configure('https://n8n.example.com', ['authType' => 'none']);

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['id' => '1']));

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function ($opts) {
                    return !isset($opts['headers']['Authorization'])
                        && $opts['headers']['Accept'] === 'application/json';
                })
            )
            ->willReturn($response);

        $this->adapter->deployWorkflow([]);
    }

    public function testNoAuthConfigAtAll(): void
    {
        $this->adapter->configure('https://n8n.example.com');

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['id' => '1']));

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function ($opts) {
                    return !isset($opts['headers']['Authorization']);
                })
            )
            ->willReturn($response);

        $this->adapter->deployWorkflow([]);
    }

    // --- Error response with missing message ---

    public function testExecuteWorkflowErrorStatusMissingMessageFallback(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'status' => 'error',
            'errors' => [],
        ]));

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('wf-1', []);
        $this->assertTrue($result->isError());
        $this->assertSame('Unknown error', $result->getErrors()[0]['message']);
    }
}
