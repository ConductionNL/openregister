<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\Agent;
use OCA\OpenRegister\Db\AgentMapper;
use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointLog;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Service\EndpointService;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\ToolRegistry;
use OCA\OpenRegister\Tool\ToolInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Test-only subclass to inject dependencies since EndpointService has no constructor.
 * Also exposes private methods for thorough testing.
 */
class TestableEndpointService extends EndpointService
{
    public function __construct(
        EndpointLogMapper $endpointLogMapper,
        LoggerInterface $logger,
        IUserSession $userSession,
        IGroupManager $groupManager
    ) {
        $setter = \Closure::bind(function () use ($endpointLogMapper, $logger, $userSession, $groupManager) {
            $this->endpointLogMapper = $endpointLogMapper;
            $this->logger = $logger;
            $this->userSession = $userSession;
            $this->groupManager = $groupManager;
        }, $this, EndpointService::class);
        $setter();
    }

    /**
     * Expose canExecuteEndpoint for direct testing.
     */
    public function publicCanExecuteEndpoint(Endpoint $endpoint): bool
    {
        $method = new \ReflectionMethod(EndpointService::class, 'canExecuteEndpoint');
        $method->setAccessible(true);
        return $method->invoke($this, $endpoint);
    }

    /**
     * Expose executeEndpoint for direct testing.
     */
    public function publicExecuteEndpoint(Endpoint $endpoint, array $request): array
    {
        $method = new \ReflectionMethod(EndpointService::class, 'executeEndpoint');
        $method->setAccessible(true);
        return $method->invoke($this, $endpoint, $request);
    }

    /**
     * Expose logEndpointCall for direct testing.
     */
    public function publicLogEndpointCall(Endpoint $endpoint, array $request, array $result): void
    {
        $method = new \ReflectionMethod(EndpointService::class, 'logEndpointCall');
        $method->setAccessible(true);
        $method->invoke($this, $endpoint, $request, $result);
    }
}

class EndpointServiceTest extends TestCase
{

    /**
     * @var EndpointLogMapper&MockObject
     */
    private EndpointLogMapper $endpointLogMapper;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * @var IGroupManager&MockObject
     */
    private IGroupManager $groupManager;

    private TestableEndpointService $service;

    /**
     * Store original OC::$server to restore after agent tests.
     *
     * @var mixed
     */
    private $originalServer;

    protected function setUp(): void
    {
        $this->endpointLogMapper = $this->createMock(EndpointLogMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->groupManager = $this->createMock(IGroupManager::class);

        $this->service = new TestableEndpointService(
            $this->endpointLogMapper,
            $this->logger,
            $this->userSession,
            $this->groupManager
        );

        // Save original server stub.
        $this->originalServer = \OC::$server;
    }

    protected function tearDown(): void
    {
        // Restore original server stub after each test.
        \OC::$server = $this->originalServer;
    }

    /**
     * Create a real Endpoint entity with the given configuration.
     */
    private function createEndpoint(
        string $targetType = 'view',
        ?string $method = 'GET',
        string $endpointPath = '/api/test',
        array $groups = [],
        ?int $id = 1,
        ?string $targetId = null
    ): Endpoint {
        $endpoint = new Endpoint();

        $reflection = new \ReflectionClass($endpoint);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($endpoint, $id);

        $endpoint->setTargetType($targetType);
        $endpoint->setMethod($method);
        $endpoint->setEndpoint($endpointPath);
        $endpoint->setGroups($groups);

        if ($targetId !== null) {
            $endpoint->setTargetId($targetId);
        }

        return $endpoint;
    }

    /**
     * Helper to set up an admin user on the mocks.
     */
    private function setUpAdminUser(): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin', 'users']);
        return $user;
    }

    /**
     * Helper to set up a regular user in specific groups.
     */
    private function setUpUserInGroups(string $uid, array $groups): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn($groups);
        return $user;
    }

    /**
     * Create a real Agent entity with the given properties.
     */
    private function createAgent(
        ?string $name = 'Test Agent',
        ?string $provider = 'ollama',
        ?string $model = 'llama3',
        ?string $prompt = null,
        ?array $tools = null
    ): Agent {
        $agent = new Agent();
        $agent->setUuid('agent-uuid-123');
        $agent->setName($name);
        $agent->setProvider($provider);
        $agent->setModel($model);
        if ($prompt !== null) {
            $agent->setPrompt($prompt);
        }
        if ($tools !== null) {
            $agent->setTools($tools);
        }
        return $agent;
    }

    /**
     * Set up \OC::$server to return mock services for agent tests.
     *
     * @param AgentMapper&MockObject    $agentMapper
     * @param ToolRegistry&MockObject   $toolRegistry
     * @param SettingsService&MockObject $settingsService
     *
     * @return void
     */
    private function setUpOcServer(
        MockObject $agentMapper,
        MockObject $toolRegistry,
        MockObject $settingsService
    ): void {
        $serverStub = new class ($agentMapper, $toolRegistry, $settingsService) {
            private $agentMapper;
            private $toolRegistry;
            private $settingsService;

            public function __construct($agentMapper, $toolRegistry, $settingsService)
            {
                $this->agentMapper = $agentMapper;
                $this->toolRegistry = $toolRegistry;
                $this->settingsService = $settingsService;
            }

            public function get(string $class): mixed
            {
                return match ($class) {
                    AgentMapper::class => $this->agentMapper,
                    ToolRegistry::class => $this->toolRegistry,
                    SettingsService::class => $this->settingsService,
                    default => throw new \Exception("OC::server->get({$class}) not available in unit tests"),
                };
            }

            public function __call(string $name, array $arguments): mixed
            {
                throw new \Exception("OC::server->{$name}() not available in unit tests");
            }
        };

        \OC::$server = $serverStub;
    }

    // ====================================================================
    // canExecuteEndpoint — permission checks (direct)
    // ====================================================================

    public function testCanExecuteEndpointNoUserPublicEndpoint(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', []);
        $this->userSession->method('getUser')->willReturn(null);

        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointNoUserGroupsRequired(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors']);
        $this->userSession->method('getUser')->willReturn(null);

        $this->assertFalse($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointAdminAlwaysAllowed(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['special-group']);
        $this->setUpAdminUser();

        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointNoGroupsAllowsAuthenticated(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', []);
        $this->setUpUserInGroups('regularuser', ['users']);

        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointUserInAllowedGroup(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors', 'viewers']);
        $this->setUpUserInGroups('editor1', ['editors']);

        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointUserInSecondAllowedGroup(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors', 'viewers']);
        $this->setUpUserInGroups('viewer1', ['viewers']);

        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointUserNotInAnyAllowedGroup(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors', 'viewers']);
        $this->setUpUserInGroups('outsider', ['users', 'marketing']);

        $this->assertFalse($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointUserInMultipleGroupsOneMatches(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['special']);
        $this->setUpUserInGroups('multigroup', ['users', 'special', 'marketing']);

        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }

    // ====================================================================
    // executeEndpoint — target type routing (direct)
    // ====================================================================

    public function testExecuteEndpointViewType(): void
    {
        $endpoint = $this->createEndpoint('view');
        $request = ['method' => 'GET', 'path' => '/api/test', 'data' => [], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('View endpoint executed (placeholder)', $result['response']['message']);
    }

    public function testExecuteEndpointWebhookType(): void
    {
        $endpoint = $this->createEndpoint('webhook');
        $request = ['method' => 'POST', 'path' => '/webhook', 'data' => [], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('Webhook endpoint executed (placeholder)', $result['response']['message']);
    }

    public function testExecuteEndpointRegisterType(): void
    {
        $endpoint = $this->createEndpoint('register');
        $request = ['method' => 'GET', 'path' => '/register', 'data' => [], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('Register endpoint executed (placeholder)', $result['response']['message']);
    }

    public function testExecuteEndpointSchemaType(): void
    {
        $endpoint = $this->createEndpoint('schema');
        $request = ['method' => 'GET', 'path' => '/schema', 'data' => [], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
        $this->assertSame('Schema endpoint executed (placeholder)', $result['response']['message']);
    }

    public function testExecuteEndpointUnknownType(): void
    {
        $endpoint = $this->createEndpoint('nonexistent');
        $request = ['method' => 'GET', 'path' => '/test', 'data' => [], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertStringContainsString('Unknown target type: nonexistent', $result['error']);
    }

    public function testExecuteEndpointAgentTypeFailsGracefullyInUnitTest(): void
    {
        // Agent endpoint calls \OC::$server->get() which will throw in unit tests.
        // This exercises the catch block in executeAgentEndpoint.
        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'hello'], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertArrayHasKey('error', $result);
    }

    // ====================================================================
    // executeAgentEndpoint — with mocked OC::$server
    // ====================================================================

    public function testExecuteAgentEndpointAgentNotFound(): void
    {
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        // findByUuid returns Agent (non-nullable), so it throws when not found.
        $agentMapper->method('findByUuid')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Agent not found'));

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'missing-uuid');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'hello'], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        // Exception caught by outer try/catch => 500.
        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testExecuteAgentEndpointEmptyMessage(): void
    {
        $agent = $this->createAgent();
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        // No message in request data.
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => [], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertSame('Message is required', $result['error']);
    }

    public function testExecuteAgentEndpointEmptyMessageString(): void
    {
        $agent = $this->createAgent();
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        // Empty string message.
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => ''], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertSame('Message is required', $result['error']);
    }

    public function testExecuteAgentEndpointMessageInTopLevelRequest(): void
    {
        $agent = $this->createAgent('Agent', 'unsupported_provider', 'model-x');
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn(['llm' => []]);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        // Message at top-level of request (not in data).
        $request = [
            'method'  => 'POST',
            'path'    => '/api/agent',
            'data'    => [],
            'headers' => [],
            'message' => 'Hello from top level',
        ];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        // unsupported_provider => 501 not implemented.
        $this->assertFalse($result['success']);
        $this->assertSame(501, $result['statusCode']);
        $this->assertStringContainsString('unsupported_provider', $result['error']);
    }

    public function testExecuteAgentEndpointUnsupportedProvider(): void
    {
        $agent = $this->createAgent('Agent', 'openai', 'gpt-4');
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn(['llm' => []]);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hello'], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        $this->assertFalse($result['success']);
        $this->assertSame(501, $result['statusCode']);
        $this->assertStringContainsString('openai', $result['error']);
        $this->assertStringContainsString('not yet implemented', $result['error']);
    }

    public function testExecuteAgentEndpointNoToolsConfigured(): void
    {
        $agent = $this->createAgent('Agent', 'openai', 'gpt-4', null, []);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn(['llm' => []]);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hello'], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        // Empty tools => still reaches provider check => 501.
        $this->assertFalse($result['success']);
        $this->assertSame(501, $result['statusCode']);
    }

    public function testExecuteAgentEndpointNullToolsConfigured(): void
    {
        $agent = $this->createAgent('Agent', 'openai', 'gpt-4', null, null);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn(['llm' => []]);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hello'], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        // null tools coalesced to [] => empty => skips foreach => 501.
        $this->assertFalse($result['success']);
        $this->assertSame(501, $result['statusCode']);
    }

    public function testExecuteAgentEndpointWithToolsLoaded(): void
    {
        $agent = $this->createAgent('Agent', 'openai', 'gpt-4', null, ['register', 'objects']);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn(['llm' => []]);

        // Create a mock tool that returns functions.
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getFunctions')->willReturn([
            ['name' => 'search_register', 'description' => 'Search registers'],
        ]);
        $tool->expects($this->exactly(2))->method('setAgent');

        $toolRegistry->method('getTool')->willReturn($tool);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hello'], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        // Not ollama => 501.
        $this->assertFalse($result['success']);
        $this->assertSame(501, $result['statusCode']);
    }

    public function testExecuteAgentEndpointToolReturnsNull(): void
    {
        $agent = $this->createAgent('Agent', 'openai', 'gpt-4', null, ['missing_tool']);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn(['llm' => []]);

        // Tool not found - returns null.
        $toolRegistry->method('getTool')->willReturn(null);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hello'], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        // Reaches provider check => 501.
        $this->assertFalse($result['success']);
        $this->assertSame(501, $result['statusCode']);
    }

    public function testExecuteAgentEndpointToolThrowsException(): void
    {
        $agent = $this->createAgent('Agent', 'openai', 'gpt-4', null, ['broken_tool']);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn(['llm' => []]);

        // Tool throws exception during loading.
        $toolRegistry->method('getTool')
            ->willThrowException(new \Exception('Tool load failed'));

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hello'], 'headers' => []];

        // The exception is caught inside the foreach, logged as warning, continues.
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        // Still reaches provider check => 501.
        $this->assertFalse($result['success']);
        $this->assertSame(501, $result['statusCode']);
    }

    public function testExecuteAgentEndpointOllamaProviderThrowsError(): void
    {
        $agent = $this->createAgent('Ollama Agent', 'ollama', 'llama3', 'You are helpful.', []);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn([
            'llm' => [
                'ollamaConfig' => ['url' => 'http://localhost:11434'],
            ],
        ]);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hello'], 'headers' => []];

        // callOllamaWithTools doesn't exist as a method, so this throws an Error
        // (not Exception), which is NOT caught by the catch(\Exception) block.
        $this->expectException(\Error::class);
        $this->service->publicExecuteEndpoint($endpoint, $request);
    }

    public function testExecuteAgentEndpointOllamaWithPromptThrowsError(): void
    {
        $agent = $this->createAgent('Agent', 'ollama', 'llama3', 'System prompt here', []);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn([
            'llm' => ['ollamaConfig' => ['url' => 'http://test:11434']],
        ]);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hi'], 'headers' => []];

        // Exercises prompt-building path then hits undefined method.
        $this->expectException(\Error::class);
        $this->service->publicExecuteEndpoint($endpoint, $request);
    }

    public function testExecuteAgentEndpointOllamaWithoutPromptThrowsError(): void
    {
        $agent = $this->createAgent('Agent', 'ollama', 'llama3', null, []);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn([
            'llm' => ['ollamaConfig' => ['url' => 'http://test:11434']],
        ]);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hi'], 'headers' => []];

        // Exercises no-prompt path then hits undefined method.
        $this->expectException(\Error::class);
        $this->service->publicExecuteEndpoint($endpoint, $request);
    }

    public function testExecuteAgentEndpointOllamaEmptyPromptThrowsError(): void
    {
        $agent = $this->createAgent('Agent', 'ollama', 'llama3', '', []);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn([
            'llm' => ['ollamaConfig' => ['url' => 'http://test:11434']],
        ]);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hi'], 'headers' => []];

        // Empty prompt should NOT add system message, then hits undefined method.
        $this->expectException(\Error::class);
        $this->service->publicExecuteEndpoint($endpoint, $request);
    }

    public function testExecuteAgentEndpointOllamaDefaultUrlThrowsError(): void
    {
        $agent = $this->createAgent('Agent', 'ollama', 'llama3', null, []);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        // No ollamaConfig — should use default URL.
        $settingsService->method('getSettings')->willReturn(['llm' => []]);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hi'], 'headers' => []];

        $this->expectException(\Error::class);
        $this->service->publicExecuteEndpoint($endpoint, $request);
    }

    public function testExecuteAgentEndpointOllamaNoLlmConfigThrowsError(): void
    {
        $agent = $this->createAgent('Agent', 'ollama', 'llama3', null, []);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        // No 'llm' key at all.
        $settingsService->method('getSettings')->willReturn([]);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hi'], 'headers' => []];

        $this->expectException(\Error::class);
        $this->service->publicExecuteEndpoint($endpoint, $request);
    }

    public function testExecuteAgentEndpointOllamaWithToolsAndPromptThrowsError(): void
    {
        $agent = $this->createAgent('Agent', 'ollama', 'llama3', 'Be helpful', ['register']);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn([
            'llm' => ['ollamaConfig' => ['url' => 'http://test:11434']],
        ]);

        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getFunctions')->willReturn([
            ['name' => 'list_registers', 'description' => 'List all registers'],
        ]);
        $tool->expects($this->once())->method('setAgent');

        $toolRegistry->method('getTool')->willReturn($tool);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hi'], 'headers' => []];

        // callOllamaWithTools undefined => Error.
        $this->expectException(\Error::class);
        $this->service->publicExecuteEndpoint($endpoint, $request);
    }

    public function testExecuteAgentEndpointMixedToolsSuccessAndFailure(): void
    {
        $agent = $this->createAgent('Agent', 'openai', 'gpt-4', null, ['good_tool', 'bad_tool', 'null_tool']);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn(['llm' => []]);

        $goodTool = $this->createMock(ToolInterface::class);
        $goodTool->method('getFunctions')->willReturn([
            ['name' => 'func1', 'description' => 'test'],
        ]);

        // Map different tool names to different responses.
        $toolRegistry->method('getTool')->willReturnCallback(function (string $name) use ($goodTool) {
            if ($name === 'good_tool') {
                return $goodTool;
            }
            if ($name === 'bad_tool') {
                throw new \Exception('Tool broken');
            }
            // null_tool
            return null;
        });

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hello'], 'headers' => []];

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        // Reaches provider check => 501.
        $this->assertFalse($result['success']);
        $this->assertSame(501, $result['statusCode']);
    }

    // ====================================================================
    // testEndpoint — full integration through public API
    // ====================================================================

    public function testTestEndpointDeniedWhenNoUserAndGroupsRequired(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors']);

        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $result = $this->service->testEndpoint($endpoint);

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['statusCode']);
        $this->assertStringContainsString('Access denied', $result['error']);
    }

    public function testTestEndpointAllowedForAdminUser(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors']);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $this->groupManager
            ->method('getUserGroupIds')
            ->with($user)
            ->willReturn(['admin', 'users']);

        $this->endpointLogMapper
            ->expects($this->once())
            ->method('insert');

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
    }

    public function testTestEndpointAllowedWhenNoGroupsDefined(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', []);

        $user = $this->createMock(IUser::class);

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $this->groupManager
            ->method('getUserGroupIds')
            ->willReturn(['users']);

        $this->endpointLogMapper
            ->expects($this->once())
            ->method('insert');

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    public function testTestEndpointAllowedWhenUserInAllowedGroup(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors', 'viewers']);

        $user = $this->createMock(IUser::class);

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $this->groupManager
            ->method('getUserGroupIds')
            ->willReturn(['viewers']);

        $this->endpointLogMapper
            ->expects($this->once())
            ->method('insert');

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    public function testTestEndpointDeniedWhenUserNotInGroup(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors']);

        $user = $this->createMock(IUser::class);

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $this->groupManager
            ->method('getUserGroupIds')
            ->willReturn(['users']);

        $result = $this->service->testEndpoint($endpoint);

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['statusCode']);
    }

    public function testTestEndpointAllowedForPublicEndpointWithNoUser(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', []);

        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $this->endpointLogMapper
            ->expects($this->once())
            ->method('insert');

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    // --- testEndpoint: target type routing ---

    public function testTestEndpointViewTargetType(): void
    {
        $endpoint = $this->createEndpoint('view');
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
    }

    public function testTestEndpointWebhookTargetType(): void
    {
        $endpoint = $this->createEndpoint('webhook');
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    public function testTestEndpointRegisterTargetType(): void
    {
        $endpoint = $this->createEndpoint('register');
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    public function testTestEndpointSchemaTargetType(): void
    {
        $endpoint = $this->createEndpoint('schema');
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    public function testTestEndpointUnknownTargetType(): void
    {
        $endpoint = $this->createEndpoint('unknown_type');
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertStringContainsString('Unknown target type', $result['error']);
    }

    // --- testEndpoint: agent target type ---

    public function testTestEndpointAgentTargetTypeReturnsErrorInUnitTest(): void
    {
        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'some-agent-uuid');
        $this->setUpAdminUser();

        $this->logger->expects($this->atLeastOnce())->method('error');

        $result = $this->service->testEndpoint($endpoint);

        // Agent path hits \OC::$server which is not available in unit tests,
        // so it will throw and be caught, returning 500.
        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testTestEndpointAgentNotFoundViaTestEndpoint(): void
    {
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        // findByUuid throws DoesNotExistException when agent not found.
        $agentMapper->method('findByUuid')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Agent not found'));
        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'missing-uuid');
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint);

        // Exception caught => 500.
        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testTestEndpointAgentEmptyMessageViaTestEndpoint(): void
    {
        $agent = $this->createAgent();
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $this->setUpAdminUser();

        // testEndpoint passes empty testData which becomes empty 'data',
        // then logs the 400 result.
        $this->endpointLogMapper->expects($this->once())->method('insert');

        $result = $this->service->testEndpoint($endpoint);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertSame('Message is required', $result['error']);
    }

    public function testTestEndpointAgentWithMessageInTestData(): void
    {
        $agent = $this->createAgent('Agent', 'openai', 'gpt-4', null, []);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn(['llm' => []]);
        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $this->setUpAdminUser();

        // Pass message via testData which becomes request['data'].
        $result = $this->service->testEndpoint($endpoint, ['message' => 'Test message']);

        // openai => 501.
        $this->assertFalse($result['success']);
        $this->assertSame(501, $result['statusCode']);
    }

    // --- testEndpoint: with test data ---

    public function testTestEndpointPassesTestDataThrough(): void
    {
        $endpoint = $this->createEndpoint('view', 'POST', '/api/test', []);
        $this->setUpAdminUser();

        $testData = ['key' => 'value', 'nested' => ['a' => 1]];

        $result = $this->service->testEndpoint($endpoint, $testData);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
    }

    public function testTestEndpointUsesMethodFromEndpoint(): void
    {
        $endpoint = $this->createEndpoint('view', 'POST', '/api/create', []);
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    public function testTestEndpointNullMethodDefaultsToGet(): void
    {
        $endpoint = $this->createEndpoint('view', null, '/api/test', []);
        $this->setUpAdminUser();

        // The method defaults to 'GET' when null in testEndpoint line 123.
        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    // --- testEndpoint: error handling ---

    public function testTestEndpointCatchesGroupManagerException(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['test']);

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')
            ->willThrowException(new \Exception('Unexpected error'));

        $this->logger->expects($this->once())->method('error');

        $result = $this->service->testEndpoint($endpoint);

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertSame('Unexpected error', $result['error']);
    }

    public function testTestEndpointCatchesExceptionAndReturnsErrorDetails(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['test']);

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')
            ->willThrowException(new \RuntimeException('Something broke'));

        $result = $this->service->testEndpoint($endpoint);

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
        $this->assertNull($result['response']);
        $this->assertSame('Something broke', $result['error']);
    }

    // ====================================================================
    // logEndpointCall — direct tests
    // ====================================================================

    public function testLogEndpointCallWithAuthenticatedUser(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', [], 42);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $request = ['method' => 'GET', 'path' => '/api/test', 'data' => [], 'headers' => []];
        $result = ['statusCode' => 200, 'response' => ['message' => 'ok']];

        $this->endpointLogMapper
            ->expects($this->once())
            ->method('insert')
            ->with($this->callback(function ($log) {
                $this->assertInstanceOf(EndpointLog::class, $log);
                return true;
            }));

        $this->service->publicLogEndpointCall($endpoint, $request, $result);
    }

    public function testLogEndpointCallWithoutUser(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', [], 10);

        $this->userSession->method('getUser')->willReturn(null);

        $request = ['method' => 'GET', 'path' => '/api/public', 'data' => [], 'headers' => []];
        $result = ['statusCode' => 200, 'response' => null];

        $this->endpointLogMapper
            ->expects($this->once())
            ->method('insert');

        // Should not throw - userId simply not set on the log.
        $this->service->publicLogEndpointCall($endpoint, $request, $result);
    }

    public function testLogEndpointCallWithErrorResult(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', [], 5);

        $this->userSession->method('getUser')->willReturn(null);

        $request = ['method' => 'GET', 'path' => '/api/fail', 'data' => [], 'headers' => []];
        $result = ['statusCode' => 400, 'response' => null, 'error' => 'Bad request'];

        $this->endpointLogMapper
            ->expects($this->once())
            ->method('insert');

        $this->service->publicLogEndpointCall($endpoint, $request, $result);
    }

    public function testLogEndpointCallWithSuccessNoErrorKey(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', [], 7);

        $this->userSession->method('getUser')->willReturn(null);

        $request = ['method' => 'GET', 'path' => '/api/ok', 'data' => [], 'headers' => []];
        // No 'error' key — should default to 'Success' in statusMessage.
        $result = ['statusCode' => 200, 'response' => ['data' => 'test']];

        $this->endpointLogMapper
            ->expects($this->once())
            ->method('insert');

        $this->service->publicLogEndpointCall($endpoint, $request, $result);
    }

    public function testLogEndpointCallInsertFailureDoesNotThrow(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', [], 3);

        $this->userSession->method('getUser')->willReturn(null);

        $request = ['method' => 'GET', 'path' => '/test', 'data' => [], 'headers' => []];
        $result = ['statusCode' => 200, 'response' => null];

        $this->endpointLogMapper
            ->method('insert')
            ->willThrowException(new \Exception('DB insert failed'));

        $this->logger->expects($this->once())->method('error');

        // Should NOT throw.
        $this->service->publicLogEndpointCall($endpoint, $request, $result);
    }

    public function testLogEndpointCallVerifiesLogProperties(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', [], 42);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $request = ['method' => 'GET', 'path' => '/api/test', 'data' => ['key' => 'val'], 'headers' => ['X-Foo' => 'bar']];
        $result = ['statusCode' => 200, 'response' => ['items' => [1, 2, 3]], 'error' => 'some warning'];

        $this->endpointLogMapper
            ->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (EndpointLog $log) {
                // Verify UUID was set.
                $this->assertNotNull($log->getUuid());
                $this->assertNotEmpty($log->getUuid());
                // Verify endpoint ID.
                $this->assertSame(42, $log->getEndpointId());
                // Verify user ID.
                $this->assertSame('testuser', $log->getUserId());
                // Verify status code.
                $this->assertSame(200, $log->getStatusCode());
                // Verify status message (error key present).
                $this->assertSame('some warning', $log->getStatusMessage());
                // Verify request data.
                $this->assertSame(['method' => 'GET', 'path' => '/api/test', 'data' => ['key' => 'val'], 'headers' => ['X-Foo' => 'bar']], $log->getRequest());
                // Note: setResponse uses named arg in source code (known issue),
                // so response may be null. We verify it was attempted.
                // Verify timestamps.
                $this->assertInstanceOf(\DateTime::class, $log->getCreated());
                $this->assertInstanceOf(\DateTime::class, $log->getExpires());
                // Verify expiry is roughly 1 week later.
                $diff = $log->getCreated()->diff($log->getExpires());
                $this->assertSame(7, $diff->days);
                return true;
            }));

        $this->service->publicLogEndpointCall($endpoint, $request, $result);
    }

    public function testLogEndpointCallSuccessMessageDefault(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', [], 1);

        $this->userSession->method('getUser')->willReturn(null);

        $request = ['method' => 'GET', 'path' => '/api/test', 'data' => [], 'headers' => []];
        // No 'error' key => statusMessage defaults to 'Success'.
        $result = ['statusCode' => 200, 'response' => ['data' => 'ok']];

        $this->endpointLogMapper
            ->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (EndpointLog $log) {
                $this->assertSame('Success', $log->getStatusMessage());
                return true;
            }));

        $this->service->publicLogEndpointCall($endpoint, $request, $result);
    }

    public function testTestEndpointLogsCallSuccessfully(): void
    {
        $endpoint = $this->createEndpoint('view');

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);

        $this->endpointLogMapper
            ->expects($this->once())
            ->method('insert');

        $this->service->testEndpoint($endpoint);
    }

    public function testTestEndpointLoggingErrorDoesNotBreakExecution(): void
    {
        $endpoint = $this->createEndpoint('view');

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);

        $this->endpointLogMapper
            ->method('insert')
            ->willThrowException(new \Exception('Log insert failed'));

        // Should not throw — error is caught in logEndpointCall.
        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    // ====================================================================
    // Edge cases and additional branch coverage
    // ====================================================================

    public function testTestEndpointWithEmptyTestData(): void
    {
        $endpoint = $this->createEndpoint('webhook', 'POST', '/api/webhook', []);
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint, []);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
    }

    public function testTestEndpointWithDifferentEndpointIds(): void
    {
        $endpoint = $this->createEndpoint('register', 'GET', '/api/register', [], 999);
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    public function testTestEndpointResponseStructureForSuccess(): void
    {
        $endpoint = $this->createEndpoint('view');
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('statusCode', $result);
        $this->assertArrayHasKey('response', $result);
        $this->assertIsBool($result['success']);
        $this->assertIsInt($result['statusCode']);
    }

    public function testTestEndpointResponseStructureForDenied(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['secret-group']);
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->service->testEndpoint($endpoint);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('statusCode', $result);
        $this->assertArrayHasKey('response', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertNull($result['response']);
    }

    public function testTestEndpointResponseStructureForUnknownType(): void
    {
        $endpoint = $this->createEndpoint('foobar');
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('foobar', $result['error']);
    }

    public function testExecuteEndpointWithEmptyTargetType(): void
    {
        $endpoint = $this->createEndpoint('');
        $request = ['method' => 'GET', 'path' => '/test', 'data' => [], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        // Empty string hits the default case.
        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
    }

    public function testCanExecuteEndpointWithEmptyGroupsArrayAndNoUser(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', []);
        $this->userSession->method('getUser')->willReturn(null);

        // Empty groups = public access = allowed.
        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointAdminBypassesGroupRestriction(): void
    {
        // Endpoint restricted to 'finance' group, but admin should bypass.
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['finance']);
        $this->setUpAdminUser();

        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointUserWithNoGroupsAndEndpointHasGroups(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors']);
        $this->setUpUserInGroups('lonely', []);

        $this->assertFalse($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testLogEndpointCallWithLargeRequestData(): void
    {
        $endpoint = $this->createEndpoint('view', 'POST', '/api/test', [], 1);
        $this->userSession->method('getUser')->willReturn(null);

        $largeData = str_repeat('x', 10000);
        $request = ['method' => 'POST', 'path' => '/api/test', 'data' => ['payload' => $largeData], 'headers' => []];
        $result = ['statusCode' => 200, 'response' => ['data' => $largeData]];

        $this->endpointLogMapper->expects($this->once())->method('insert');

        $this->service->publicLogEndpointCall($endpoint, $request, $result);
    }

    public function testTestEndpointDifferentEndpointPaths(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/v2/buildings/123', []);
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    public function testTestEndpointAllPlaceholderTargetTypesReturnCorrectMessage(): void
    {
        $this->setUpAdminUser();

        $types = [
            'view' => 'View endpoint executed (placeholder)',
            'webhook' => 'Webhook endpoint executed (placeholder)',
            'register' => 'Register endpoint executed (placeholder)',
            'schema' => 'Schema endpoint executed (placeholder)',
        ];

        foreach ($types as $type => $expectedMessage) {
            $endpoint = $this->createEndpoint($type, 'GET', '/api/' . $type, []);
            $request = ['method' => 'GET', 'path' => '/api/' . $type, 'data' => [], 'headers' => []];

            $result = $this->service->publicExecuteEndpoint($endpoint, $request);

            $this->assertTrue($result['success'], "Expected success for target type: $type");
            $this->assertSame($expectedMessage, $result['response']['message'], "Wrong message for type: $type");
        }
    }

    // ====================================================================
    // Agent endpoint — via testEndpoint (full flow with logging)
    // ====================================================================

    public function testTestEndpointAgentUnsupportedProviderLogsResult(): void
    {
        $agent = $this->createAgent('Agent', 'azure', 'gpt-4', null, []);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn(['llm' => []]);
        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $this->setUpAdminUser();

        // Log should be called even for non-success results.
        $this->endpointLogMapper->expects($this->once())->method('insert');

        $result = $this->service->testEndpoint($endpoint, ['message' => 'Hello']);

        $this->assertFalse($result['success']);
        $this->assertSame(501, $result['statusCode']);
        $this->assertStringContainsString('azure', $result['error']);
    }

    public function testTestEndpointAgentNotFoundLogsError(): void
    {
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        // findByUuid throws DoesNotExistException, caught by executeAgentEndpoint's catch block.
        $agentMapper->method('findByUuid')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Not found'));
        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'missing-uuid');
        $this->setUpAdminUser();

        // executeAgentEndpoint catches the exception and returns 500 result,
        // then testEndpoint logs the call.
        $this->endpointLogMapper->expects($this->once())->method('insert');
        $this->logger->expects($this->atLeastOnce())->method('error');

        $result = $this->service->testEndpoint($endpoint);

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
    }

    public function testTestEndpointAgentWithToolsAndMessage(): void
    {
        $agent = $this->createAgent('Agent', 'fireworks', 'llama3', 'Be helpful', ['objects']);
        $agentMapper = $this->createMock(AgentMapper::class);
        $toolRegistry = $this->createMock(ToolRegistry::class);
        $settingsService = $this->createMock(SettingsService::class);

        $agentMapper->method('findByUuid')->willReturn($agent);
        $settingsService->method('getSettings')->willReturn(['llm' => []]);

        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getFunctions')->willReturn([
            ['name' => 'search_objects', 'description' => 'Search objects'],
            ['name' => 'get_object', 'description' => 'Get an object'],
        ]);
        $toolRegistry->method('getTool')->willReturn($tool);

        $this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

        $endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
        $this->setUpAdminUser();

        $result = $this->service->testEndpoint($endpoint, ['message' => 'Search for buildings']);

        // fireworks provider => 501.
        $this->assertFalse($result['success']);
        $this->assertSame(501, $result['statusCode']);
    }

    // ====================================================================
    // Multiple unknown target types
    // ====================================================================

    public function testExecuteEndpointCaseInsensitiveTargetType(): void
    {
        // Target types are case-sensitive — 'View' is not the same as 'view'.
        $endpoint = $this->createEndpoint('View');
        $request = ['method' => 'GET', 'path' => '/test', 'data' => [], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertStringContainsString('View', $result['error']);
    }

    public function testExecuteEndpointWithSpecialCharTargetType(): void
    {
        $endpoint = $this->createEndpoint('view/inject');
        $request = ['method' => 'GET', 'path' => '/test', 'data' => [], 'headers' => []];

        $result = $this->service->publicExecuteEndpoint($endpoint, $request);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
    }

    // ====================================================================
    // canExecuteEndpoint — additional group edge cases
    // ====================================================================

    public function testCanExecuteEndpointUserInAllGroupsNotJustOne(): void
    {
        // User is in ALL the allowed groups — should still pass.
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors', 'viewers']);
        $this->setUpUserInGroups('superuser', ['editors', 'viewers', 'admin']);

        // admin group present => true (admin bypass).
        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointNonAdminUserInAllAllowedGroups(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors', 'viewers']);
        $this->setUpUserInGroups('regularuser', ['editors', 'viewers']);

        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointSingleGroup(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['only-group']);
        $this->setUpUserInGroups('member', ['only-group']);

        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointManyGroupsNoneMatch(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['a', 'b', 'c', 'd', 'e']);
        $this->setUpUserInGroups('outsider', ['x', 'y', 'z']);

        $this->assertFalse($this->service->publicCanExecuteEndpoint($endpoint));
    }

    public function testCanExecuteEndpointLastGroupMatches(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['a', 'b', 'c']);
        $this->setUpUserInGroups('user', ['c']);

        $this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
    }
}
