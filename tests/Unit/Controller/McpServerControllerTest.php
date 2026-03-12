<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\McpServerController;
use OCA\OpenRegister\Service\Mcp\McpProtocolService;
use OCA\OpenRegister\Service\Mcp\McpResourcesService;
use OCA\OpenRegister\Service\Mcp\McpToolsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Stream wrapper to mock php://input for testing.
 */
class MockPhpInputStream
{
    /**
     * @var string|null
     */
    public static ?string $body = null;

    /**
     * @var int
     */
    private int $position = 0;

    /**
     * @var resource|null
     */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        $data = substr(self::$body ?? '', $this->position, $count);
        $this->position += strlen($data);
        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body ?? '');
    }

    public function stream_stat(): array|false
    {
        return [];
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        switch ($whence) {
            case SEEK_SET:
                $this->position = $offset;
                break;
            case SEEK_CUR:
                $this->position += $offset;
                break;
            case SEEK_END:
                $this->position = strlen(self::$body ?? '') + $offset;
                break;
        }
        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }
}

class McpServerControllerTest extends TestCase
{
    private McpServerController $controller;
    private IRequest&MockObject $request;
    private McpProtocolService&MockObject $protocolService;
    private McpToolsService&MockObject $toolsService;
    private McpResourcesService&MockObject $resourcesService;
    private LoggerInterface&MockObject $logger;

    /**
     * Whether the php stream wrapper was overridden.
     */
    private bool $streamOverridden = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->protocolService = $this->createMock(McpProtocolService::class);
        $this->toolsService = $this->createMock(McpToolsService::class);
        $this->resourcesService = $this->createMock(McpResourcesService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new McpServerController(
            'openregister',
            $this->request,
            $this->protocolService,
            $this->toolsService,
            $this->resourcesService,
            $this->logger,
            'admin'
        );
    }

    protected function tearDown(): void
    {
        $this->restorePhpInput();
        parent::tearDown();
    }

    /**
     * Override php://input with custom body content.
     */
    private function mockPhpInput(string $body): void
    {
        MockPhpInputStream::$body = $body;
        if (!$this->streamOverridden) {
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', MockPhpInputStream::class);
            $this->streamOverridden = true;
        }
    }

    /**
     * Restore the default php stream wrapper.
     */
    private function restorePhpInput(): void
    {
        if ($this->streamOverridden) {
            stream_wrapper_restore('php');
            $this->streamOverridden = false;
        }
    }

    // ---------------------------------------------------------------
    // Instantiation
    // ---------------------------------------------------------------

    public function testControllerInstantiation(): void
    {
        $this->assertInstanceOf(McpServerController::class, $this->controller);
    }

    // ---------------------------------------------------------------
    // handle() — Parse errors
    // ---------------------------------------------------------------

    public function testHandleWithEmptyBody(): void
    {
        $this->mockPhpInput('');

        $result = $this->controller->handle();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $data = $result->getData();
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertNull($data['id']);
        $this->assertEquals(-32700, $data['error']['code']);
        $this->assertStringContainsString('Parse error', $data['error']['message']);
    }

    public function testHandleWithInvalidJson(): void
    {
        $this->mockPhpInput('{not valid json}');

        $result = $this->controller->handle();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $data = $result->getData();
        $this->assertEquals(-32700, $data['error']['code']);
        $this->assertStringContainsString('Parse error', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // handle() — Invalid JSON-RPC envelope
    // ---------------------------------------------------------------

    public function testHandleMissingJsonrpcVersion(): void
    {
        $this->mockPhpInput(json_encode([
            'method' => 'ping',
            'id' => 1,
        ]));

        $result = $this->controller->handle();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $data = $result->getData();
        $this->assertEquals(-32600, $data['error']['code']);
        $this->assertStringContainsString('Invalid JSON-RPC', $data['error']['message']);
    }

    public function testHandleWrongJsonrpcVersion(): void
    {
        $this->mockPhpInput(json_encode([
            'jsonrpc' => '1.0',
            'method' => 'ping',
            'id' => 1,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testHandleMissingMethod(): void
    {
        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testHandleInvalidRequestPreservesId(): void
    {
        $this->mockPhpInput(json_encode([
            'jsonrpc' => '1.0',
            'method' => 'ping',
            'id' => 42,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(42, $data['id']);
        $this->assertEquals(-32600, $data['error']['code']);
    }

    public function testHandleInvalidRequestWithoutIdReturnsNullId(): void
    {
        $this->mockPhpInput(json_encode([
            'jsonrpc' => '1.0',
            'method' => 'ping',
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertNull($data['id']);
    }

    // ---------------------------------------------------------------
    // handle() — Notifications (no id)
    // ---------------------------------------------------------------

    public function testHandleNotificationReturns202(): void
    {
        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]));

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                $this->stringContains('Notification received'),
                $this->arrayHasKey('method')
            );

        $result = $this->controller->handle();

        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(Http::STATUS_ACCEPTED, $result->getStatus());
    }

    public function testHandleNotificationWithNullId(): void
    {
        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'id' => null,
        ]));

        $result = $this->controller->handle();

        $this->assertEquals(Http::STATUS_ACCEPTED, $result->getStatus());
    }

    // ---------------------------------------------------------------
    // handle() — Initialize
    // ---------------------------------------------------------------

    public function testHandleInitializeSuccess(): void
    {
        $initResult = [
            'result' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'openregister-mcp', 'version' => '1.0.0'],
            ],
            'sessionId' => 'test-session-123',
        ];

        $this->protocolService->expects($this->once())
            ->method('initialize')
            ->with(['clientInfo' => ['name' => 'test']], 'admin')
            ->willReturn($initResult);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => ['clientInfo' => ['name' => 'test']],
            'id' => 1,
        ]));

        $result = $this->controller->handle();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $data = $result->getData();
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertEquals(1, $data['id']);
        $this->assertArrayHasKey('result', $data);
        $this->assertEquals('2025-03-26', $data['result']['protocolVersion']);

        // Verify session header is set using reflection on parent Response class.
        $ref = new \ReflectionClass(\OCP\AppFramework\Http\Response::class);
        $prop = $ref->getProperty('headers');
        $prop->setAccessible(true);
        $headers = $prop->getValue($result);
        $this->assertArrayHasKey('Mcp-Session-Id', $headers);
        $this->assertEquals('test-session-123', $headers['Mcp-Session-Id']);
    }

    public function testHandleInitializeWithNoParams(): void
    {
        $initResult = [
            'result' => ['protocolVersion' => '2025-03-26'],
            'sessionId' => 'session-456',
        ];

        $this->protocolService->expects($this->once())
            ->method('initialize')
            ->with([], 'admin')
            ->willReturn($initResult);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 2,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertArrayHasKey('result', $data);
    }

    public function testHandleInitializeException(): void
    {
        $this->protocolService->expects($this->once())
            ->method('initialize')
            ->willThrowException(new \Exception('Init failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Initialize failed'),
                $this->arrayHasKey('error')
            );

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [],
            'id' => 3,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32603, $data['error']['code']);
        $this->assertStringContainsString('Initialize failed', $data['error']['message']);
        $this->assertStringContainsString('Init failed', $data['error']['message']);
        $this->assertEquals(3, $data['id']);
    }

    // ---------------------------------------------------------------
    // handle() — Session validation
    // ---------------------------------------------------------------

    public function testHandleRequiresSessionForNonInitializeMethods(): void
    {
        $this->request->expects($this->once())
            ->method('getHeader')
            ->with('Mcp-Session-Id')
            ->willReturn('');

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 4,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32000, $data['error']['code']);
        $this->assertStringContainsString('Mcp-Session-Id header required', $data['error']['message']);
    }

    public function testHandleInvalidSessionReturnsError(): void
    {
        $this->request->expects($this->once())
            ->method('getHeader')
            ->with('Mcp-Session-Id')
            ->willReturn('invalid-session');

        $this->protocolService->expects($this->once())
            ->method('validateSession')
            ->with('invalid-session')
            ->willReturn(null);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 5,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32000, $data['error']['code']);
        $this->assertStringContainsString('Invalid or expired session', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // handle() — Dispatch: ping
    // ---------------------------------------------------------------

    public function testHandlePing(): void
    {
        $this->setupValidSession();

        $this->protocolService->expects($this->once())
            ->method('ping')
            ->willReturn(['status' => 'pong']);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 10,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals('2.0', $data['jsonrpc']);
        $this->assertEquals(10, $data['id']);
        $this->assertEquals(['status' => 'pong'], $data['result']);
    }

    // ---------------------------------------------------------------
    // handle() — Dispatch: tools/list
    // ---------------------------------------------------------------

    public function testHandleToolsList(): void
    {
        $this->setupValidSession();

        $tools = ['tools' => [['name' => 'registers', 'description' => 'Manage registers']]];
        $this->toolsService->expects($this->once())
            ->method('listTools')
            ->willReturn($tools);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 11,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals($tools, $data['result']);
    }

    // ---------------------------------------------------------------
    // handle() — Dispatch: tools/call
    // ---------------------------------------------------------------

    public function testHandleToolCallSuccess(): void
    {
        $this->setupValidSession();

        $toolResult = ['content' => [['type' => 'text', 'text' => 'result']]];
        $this->toolsService->expects($this->once())
            ->method('callTool')
            ->with('registers', ['action' => 'list'])
            ->willReturn($toolResult);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => 'registers',
                'arguments' => ['action' => 'list'],
            ],
            'id' => 12,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals($toolResult, $data['result']);
    }

    public function testHandleToolCallWithoutArguments(): void
    {
        $this->setupValidSession();

        $toolResult = ['content' => [['type' => 'text', 'text' => 'ok']]];
        $this->toolsService->expects($this->once())
            ->method('callTool')
            ->with('registers', [])
            ->willReturn($toolResult);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'registers'],
            'id' => 13,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertArrayHasKey('result', $data);
    }

    public function testHandleToolCallMissingName(): void
    {
        $this->setupValidSession();

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['arguments' => ['action' => 'list']],
            'id' => 14,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertStringContainsString('Missing required parameter: name', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // handle() — Dispatch: resources/list
    // ---------------------------------------------------------------

    public function testHandleResourcesList(): void
    {
        $this->setupValidSession();

        $resources = ['resources' => [['uri' => 'openregister://registers', 'name' => 'Registers']]];
        $this->resourcesService->expects($this->once())
            ->method('listResources')
            ->willReturn($resources);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'resources/list',
            'id' => 15,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals($resources, $data['result']);
    }

    // ---------------------------------------------------------------
    // handle() — Dispatch: resources/read
    // ---------------------------------------------------------------

    public function testHandleResourceReadSuccess(): void
    {
        $this->setupValidSession();

        $readResult = ['contents' => [['uri' => 'openregister://registers', 'text' => '[]']]];
        $this->resourcesService->expects($this->once())
            ->method('readResource')
            ->with('openregister://registers')
            ->willReturn($readResult);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'resources/read',
            'params' => ['uri' => 'openregister://registers'],
            'id' => 16,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals($readResult, $data['result']);
    }

    public function testHandleResourceReadMissingUri(): void
    {
        $this->setupValidSession();

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'resources/read',
            'params' => [],
            'id' => 17,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertStringContainsString('Missing required parameter: uri', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // handle() — Dispatch: resources/templates/list
    // ---------------------------------------------------------------

    public function testHandleResourcesTemplatesList(): void
    {
        $this->setupValidSession();

        $templates = ['resourceTemplates' => [['uriTemplate' => 'openregister://objects/{register}/{schema}']]];
        $this->resourcesService->expects($this->once())
            ->method('listTemplates')
            ->willReturn($templates);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'resources/templates/list',
            'id' => 18,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals($templates, $data['result']);
    }

    // ---------------------------------------------------------------
    // handle() — Dispatch: unknown method
    // ---------------------------------------------------------------

    public function testHandleUnknownMethod(): void
    {
        $this->setupValidSession();

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'unknown/method',
            'id' => 19,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32601, $data['error']['code']);
        $this->assertStringContainsString('Method not found', $data['error']['message']);
        $this->assertStringContainsString('unknown/method', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // handle() — Dispatch: general exception in dispatch
    // ---------------------------------------------------------------

    public function testHandleDispatchInternalError(): void
    {
        $this->setupValidSession();

        $this->protocolService->expects($this->once())
            ->method('ping')
            ->willThrowException(new \RuntimeException('Server crashed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Method dispatch failed'),
                $this->callback(function ($context) {
                    return $context['method'] === 'ping'
                        && $context['error'] === 'Server crashed';
                })
            );

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 20,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32603, $data['error']['code']);
        $this->assertStringContainsString('Server crashed', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // handle() — Dispatch: InvalidArgumentException from tool service
    // ---------------------------------------------------------------

    public function testHandleToolCallInvalidArgumentException(): void
    {
        $this->setupValidSession();

        $this->toolsService->expects($this->once())
            ->method('callTool')
            ->willThrowException(new \InvalidArgumentException('Bad param value'));

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'registers', 'arguments' => ['action' => 'bad']],
            'id' => 21,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertStringContainsString('Bad param value', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // handle() — Dispatch: BadMethodCallException from resource service
    // ---------------------------------------------------------------

    public function testHandleResourceReadBadMethodCallException(): void
    {
        $this->setupValidSession();

        $this->resourcesService->expects($this->once())
            ->method('readResource')
            ->willThrowException(new \BadMethodCallException('No such resource'));

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'resources/read',
            'params' => ['uri' => 'openregister://nonexistent'],
            'id' => 22,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32601, $data['error']['code']);
        $this->assertStringContainsString('No such resource', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // handle() — Exception in resources/list
    // ---------------------------------------------------------------

    public function testHandleResourcesListInternalError(): void
    {
        $this->setupValidSession();

        $this->resourcesService->expects($this->once())
            ->method('listResources')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'resources/list',
            'id' => 23,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32603, $data['error']['code']);
    }

    // ---------------------------------------------------------------
    // handle() — Exception in tools/list
    // ---------------------------------------------------------------

    public function testHandleToolsListInternalError(): void
    {
        $this->setupValidSession();

        $this->toolsService->expects($this->once())
            ->method('listTools')
            ->willThrowException(new \RuntimeException('Tools error'));

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'id' => 24,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32603, $data['error']['code']);
    }

    // ---------------------------------------------------------------
    // handle() — Exception in resources/templates/list
    // ---------------------------------------------------------------

    public function testHandleResourcesTemplatesListInternalError(): void
    {
        $this->setupValidSession();

        $this->resourcesService->expects($this->once())
            ->method('listTemplates')
            ->willThrowException(new \RuntimeException('Templates error'));

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'resources/templates/list',
            'id' => 25,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32603, $data['error']['code']);
    }

    // ---------------------------------------------------------------
    // JSON-RPC response structure validation
    // ---------------------------------------------------------------

    public function testSuccessResponseStructure(): void
    {
        $this->setupValidSession();

        $this->protocolService->expects($this->once())
            ->method('ping')
            ->willReturn(['status' => 'pong']);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 30,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertArrayHasKey('jsonrpc', $data);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('result', $data);
        $this->assertArrayNotHasKey('error', $data);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    public function testErrorResponseStructure(): void
    {
        $this->mockPhpInput('not json');

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertArrayHasKey('jsonrpc', $data);
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayNotHasKey('result', $data);
        $this->assertArrayHasKey('code', $data['error']);
        $this->assertArrayHasKey('message', $data['error']);
        $this->assertEquals(Http::STATUS_OK, $result->getStatus());
    }

    // ---------------------------------------------------------------
    // Various id types
    // ---------------------------------------------------------------

    public function testHandleWithStringId(): void
    {
        $this->setupValidSession();

        $this->protocolService->expects($this->once())
            ->method('ping')
            ->willReturn([]);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 'request-abc',
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals('request-abc', $data['id']);
    }

    public function testHandleWithIntegerId(): void
    {
        $this->setupValidSession();

        $this->protocolService->expects($this->once())
            ->method('ping')
            ->willReturn([]);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'ping',
            'id' => 999,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(999, $data['id']);
    }

    // ---------------------------------------------------------------
    // tools/call with empty params
    // ---------------------------------------------------------------

    public function testHandleToolCallWithEmptyParams(): void
    {
        $this->setupValidSession();

        // When params is empty (no 'name' key), should trigger InvalidArgumentException.
        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 40,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertStringContainsString('Missing required parameter: name', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // resources/read with empty params
    // ---------------------------------------------------------------

    public function testHandleResourceReadWithEmptyParams(): void
    {
        $this->setupValidSession();

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'resources/read',
            'id' => 41,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertStringContainsString('Missing required parameter: uri', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // Initialize does NOT require session
    // ---------------------------------------------------------------

    public function testHandleInitializeDoesNotRequireSession(): void
    {
        // Ensure getHeader is never called for 'initialize'.
        $this->request->expects($this->never())
            ->method('getHeader');

        $initResult = [
            'result' => ['protocolVersion' => '2025-03-26'],
            'sessionId' => 'new-session',
        ];

        $this->protocolService->expects($this->once())
            ->method('initialize')
            ->willReturn($initResult);

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [],
            'id' => 50,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertArrayHasKey('result', $data);
    }

    // ---------------------------------------------------------------
    // Multiple notification methods
    // ---------------------------------------------------------------

    public function testHandleNotificationWithDifferentMethods(): void
    {
        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'cancelled',
        ]));

        $result = $this->controller->handle();

        $this->assertEquals(Http::STATUS_ACCEPTED, $result->getStatus());
    }

    // ---------------------------------------------------------------
    // InvalidArgumentException from resource service readResource
    // ---------------------------------------------------------------

    public function testHandleResourceReadInvalidArgument(): void
    {
        $this->setupValidSession();

        $this->resourcesService->expects($this->once())
            ->method('readResource')
            ->willThrowException(new \InvalidArgumentException('Invalid URI format'));

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'resources/read',
            'params' => ['uri' => 'bad://uri'],
            'id' => 60,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32602, $data['error']['code']);
        $this->assertStringContainsString('Invalid URI format', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // General exception in tools/call service
    // ---------------------------------------------------------------

    public function testHandleToolCallGeneralException(): void
    {
        $this->setupValidSession();

        $this->toolsService->expects($this->once())
            ->method('callTool')
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $this->mockPhpInput(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => ['name' => 'objects', 'arguments' => ['action' => 'list']],
            'id' => 61,
        ]));

        $result = $this->controller->handle();

        $data = $result->getData();
        $this->assertEquals(-32603, $data['error']['code']);
        $this->assertStringContainsString('DB connection lost', $data['error']['message']);
    }

    // ---------------------------------------------------------------
    // Helper: set up a valid session for dispatch tests
    // ---------------------------------------------------------------

    private function setupValidSession(): void
    {
        $this->request->method('getHeader')
            ->with('Mcp-Session-Id')
            ->willReturn('valid-session');

        $this->protocolService->method('validateSession')
            ->with('valid-session')
            ->willReturn('admin');
    }
}
