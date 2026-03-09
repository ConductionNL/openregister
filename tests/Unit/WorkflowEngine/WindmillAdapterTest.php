<?php

namespace Unit\WorkflowEngine;

use OCA\OpenRegister\WorkflowEngine\WindmillAdapter;
use OCA\OpenRegister\WorkflowEngine\WorkflowResult;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WindmillAdapterTest extends TestCase
{
    private IClientService&MockObject $clientService;
    private LoggerInterface&MockObject $logger;
    private IClient&MockObject $client;
    private WindmillAdapter $adapter;

    protected function setUp(): void
    {
        $this->clientService = $this->createMock(IClientService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->client = $this->createMock(IClient::class);

        $this->clientService->method('newClient')->willReturn($this->client);

        $this->adapter = new WindmillAdapter($this->clientService, $this->logger);
        $this->adapter->configure('https://windmill.example.com', [
            'token' => 'wm-token',
            'workspace' => 'myws',
        ]);
    }

    // --- configure() ---

    public function testConfigureTrimsTrailingSlash(): void
    {
        $this->adapter->configure('https://windmill.example.com/', ['workspace' => 'ws']);
        $this->assertSame(
            'https://windmill.example.com/api/w/ws/jobs/run/f/flow-1',
            $this->adapter->getWebhookUrl('flow-1')
        );
    }

    public function testConfigureDefaultWorkspace(): void
    {
        $this->adapter->configure('https://windmill.example.com', []);
        $this->assertStringContainsString('/api/w/main/', $this->adapter->getWebhookUrl('flow-1'));
    }

    // --- deployWorkflow() ---

    public function testDeployWorkflowSuccess(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['path' => 'f/my-flow']));

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'https://windmill.example.com/api/w/myws/flows/create',
                $this->callback(function ($opts) {
                    return $opts['headers']['Authorization'] === 'Bearer wm-token';
                })
            )
            ->willReturn($response);

        $this->assertSame('f/my-flow', $this->adapter->deployWorkflow(['name' => 'test']));
    }

    public function testDeployWorkflowFallsBackToId(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['id' => '42']));

        $this->client->method('post')->willReturn($response);

        $this->assertSame('42', $this->adapter->deployWorkflow([]));
    }

    public function testDeployWorkflowEmptyResponse(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([]));

        $this->client->method('post')->willReturn($response);

        $this->assertSame('', $this->adapter->deployWorkflow([]));
    }

    // --- updateWorkflow() ---

    public function testUpdateWorkflowReturnsPath(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['path' => 'f/updated']));

        $this->client->expects($this->once())
            ->method('post')
            ->with('https://windmill.example.com/api/w/myws/flows/update/f/old', $this->anything())
            ->willReturn($response);

        $this->assertSame('f/updated', $this->adapter->updateWorkflow('f/old', []));
    }

    public function testUpdateWorkflowFallsBackToInputId(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([]));

        $this->client->method('post')->willReturn($response);

        $this->assertSame('f/old', $this->adapter->updateWorkflow('f/old', []));
    }

    // --- getWorkflow() ---

    public function testGetWorkflowSuccess(): void
    {
        $expected = ['path' => 'f/flow', 'summary' => 'Test'];
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode($expected));

        $this->client->expects($this->once())
            ->method('get')
            ->with('https://windmill.example.com/api/w/myws/flows/get/f/flow', $this->anything())
            ->willReturn($response);

        $this->assertSame($expected, $this->adapter->getWorkflow('f/flow'));
    }

    public function testGetWorkflowNullResponse(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('null');

        $this->client->method('get')->willReturn($response);

        $this->assertSame([], $this->adapter->getWorkflow('f/x'));
    }

    // --- deleteWorkflow() ---

    public function testDeleteWorkflowCallsDelete(): void
    {
        $this->client->expects($this->once())
            ->method('delete')
            ->with('https://windmill.example.com/api/w/myws/flows/delete/f/flow', $this->anything());

        $this->adapter->deleteWorkflow('f/flow');
    }

    // --- activateWorkflow() (no-op) ---

    public function testActivateWorkflowIsNoOp(): void
    {
        // Should not make any HTTP calls
        $this->client->expects($this->never())->method('post');
        $this->client->expects($this->never())->method('patch');
        $this->client->expects($this->never())->method('get');
        $this->client->expects($this->never())->method('delete');

        $this->adapter->activateWorkflow('f/flow');
    }

    // --- deactivateWorkflow() (no-op) ---

    public function testDeactivateWorkflowIsNoOp(): void
    {
        $this->client->expects($this->never())->method('post');
        $this->client->expects($this->never())->method('patch');

        $this->adapter->deactivateWorkflow('f/flow');
    }

    // --- executeWorkflow() ---

    public function testExecuteWorkflowApproved(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['status' => 'approved']));

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                'https://windmill.example.com/api/w/myws/jobs/run_wait_result/f/f/flow',
                $this->callback(function ($opts) {
                    return $opts['json'] === ['key' => 'val'] && $opts['timeout'] === 20;
                })
            )
            ->willReturn($response);

        $result = $this->adapter->executeWorkflow('f/flow', ['key' => 'val'], 20);
        $this->assertTrue($result->isApproved());
        $this->assertSame('windmill', $result->getMetadata()['engine']);
    }

    public function testExecuteWorkflowRejected(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'status' => 'rejected',
            'errors' => [['message' => 'invalid']],
        ]));

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('f/flow', []);
        $this->assertTrue($result->isRejected());
    }

    public function testExecuteWorkflowModified(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'status' => 'modified',
            'data' => ['x' => 1],
        ]));

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('f/flow', []);
        $this->assertTrue($result->isModified());
        $this->assertSame(['x' => 1], $result->getData());
    }

    public function testExecuteWorkflowErrorStatus(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'status' => 'error',
            'errors' => [['message' => 'boom']],
        ]));

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('f/flow', []);
        $this->assertTrue($result->isError());
        $this->assertSame('boom', $result->getErrors()[0]['message']);
    }

    public function testExecuteWorkflowNullResponseReturnsApproved(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('null');

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('f/flow', []);
        $this->assertTrue($result->isApproved());
        $this->assertSame('windmill', $result->getMetadata()['engine']);
    }

    public function testExecuteWorkflowDefaultStatusIsApproved(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode(['status' => 'something_else']));

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('f/flow', []);
        $this->assertTrue($result->isApproved());
    }

    public function testExecuteWorkflowExceptionReturnsError(): void
    {
        $this->client->method('post')->willThrowException(new \RuntimeException('network fail'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->adapter->executeWorkflow('f/flow', []);
        $this->assertTrue($result->isError());
        $this->assertSame('network fail', $result->getErrors()[0]['message']);
        $this->assertSame('windmill', $result->getMetadata()['engine']);
        $this->assertSame('f/flow', $result->getMetadata()['workflowId']);
    }

    public function testExecuteWorkflowTimeoutError(): void
    {
        $this->client->method('post')->willThrowException(new \RuntimeException('Request timed out'));

        $result = $this->adapter->executeWorkflow('f/flow', [], 15);
        $this->assertTrue($result->isError());
        $this->assertStringContainsString('timed out after 15 seconds', $result->getErrors()[0]['message']);
    }

    public function testExecuteWorkflowTimeoutKeyword(): void
    {
        $this->client->method('post')->willThrowException(new \RuntimeException('Connection timeout error'));

        $result = $this->adapter->executeWorkflow('f/flow', [], 5);
        $this->assertStringContainsString('timed out after 5 seconds', $result->getErrors()[0]['message']);
    }

    public function testExecuteWorkflowErrorMissingMessageFallback(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            'status' => 'error',
            'errors' => [],
        ]));

        $this->client->method('post')->willReturn($response);

        $result = $this->adapter->executeWorkflow('f/flow', []);
        $this->assertSame('Unknown error', $result->getErrors()[0]['message']);
    }

    // --- getWebhookUrl() ---

    public function testGetWebhookUrl(): void
    {
        $this->assertSame(
            'https://windmill.example.com/api/w/myws/jobs/run/f/f/flow',
            $this->adapter->getWebhookUrl('f/flow')
        );
    }

    // --- listWorkflows() ---

    public function testListWorkflowsSuccess(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([
            ['path' => 'f/flow-a', 'summary' => 'Flow A'],
            ['path' => 'f/flow-b'],
        ]));

        $this->client->expects($this->once())
            ->method('get')
            ->with('https://windmill.example.com/api/w/myws/flows/list', $this->anything())
            ->willReturn($response);

        $workflows = $this->adapter->listWorkflows();
        $this->assertCount(2, $workflows);
        $this->assertSame('f/flow-a', $workflows[0]['id']);
        $this->assertSame('Flow A', $workflows[0]['name']);
        $this->assertSame('f/flow-b', $workflows[1]['id']);
        $this->assertSame('f/flow-b', $workflows[1]['name']); // falls back to path
    }

    public function testListWorkflowsNullResponse(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('null');

        $this->client->method('get')->willReturn($response);

        $this->assertSame([], $this->adapter->listWorkflows());
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
                'https://windmill.example.com/api/version',
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
        $response->method('getStatusCode')->willReturn(503);

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

    public function testTokenAuthHeader(): void
    {
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([]));

        $this->client->expects($this->once())
            ->method('post')
            ->with(
                $this->anything(),
                $this->callback(function ($opts) {
                    return $opts['headers']['Authorization'] === 'Bearer wm-token'
                        && $opts['headers']['Accept'] === 'application/json';
                })
            )
            ->willReturn($response);

        $this->adapter->deployWorkflow([]);
    }

    public function testNoTokenNoAuthHeader(): void
    {
        $this->adapter->configure('https://windmill.example.com', ['workspace' => 'ws']);

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn(json_encode([]));

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
}
