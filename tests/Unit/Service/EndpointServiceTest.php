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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test-only subclass that exposes private methods for thorough testing.
 *
 * It used to exist for a second reason, and its docblock said so:
 *
 *     Test-only subclass to inject dependencies since EndpointService has
 *     no constructor.
 *
 * That was accurate, and it is why this suite stayed green over a class that
 * could not run anywhere else. `EndpointService` genuinely had no constructor,
 * so nothing assigned its four readonly properties in production and every
 * method that read one died with "must not be accessed before initialization".
 * The `Closure::bind` below wrote them from outside the class, which no caller
 * in `lib/` can do — so all 78 tests here exercised a wiring that existed only
 * in this file.
 *
 * The class now has a real constructor, so the subclass calls it. That is the
 * point: these tests now exercise the SAME construction path production uses,
 * and would fail loudly if it went missing again.
 */
class TestableEndpointService extends EndpointService {
	public function __construct(
		EndpointLogMapper $endpointLogMapper,
		LoggerInterface $logger,
		IUserSession $userSession,
		IGroupManager $groupManager,
	) {
		parent::__construct(
			endpointLogMapper: $endpointLogMapper,
			logger: $logger,
			userSession: $userSession,
			groupManager: $groupManager
		);
	}

	/**
	 * Expose canExecuteEndpoint for direct testing.
	 */
	public function publicCanExecuteEndpoint(Endpoint $endpoint): bool {
		$method = new \ReflectionMethod(EndpointService::class, 'canExecuteEndpoint');
		$method->setAccessible(true);
		return $method->invoke($this, $endpoint);
	}

	/**
	 * Expose executeEndpoint for direct testing.
	 */
	public function publicExecuteEndpoint(Endpoint $endpoint, array $request): array {
		$method = new \ReflectionMethod(EndpointService::class, 'executeEndpoint');
		$method->setAccessible(true);
		return $method->invoke($this, $endpoint, $request);
	}

	/**
	 * Expose logEndpointCall for direct testing.
	 */
	public function publicLogEndpointCall(Endpoint $endpoint, array $request, array $result): void {
		$method = new \ReflectionMethod(EndpointService::class, 'logEndpointCall');
		$method->setAccessible(true);
		$method->invoke($this, $endpoint, $request, $result);
	}
}

class EndpointServiceTest extends TestCase {

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

	protected function setUp(): void {
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
		?string $targetId = null,
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
	private function setUpAdminUser(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn(['admin', 'users']);
		return $user;
	}

	/**
	 * Helper to set up a regular user in specific groups.
	 */
	private function setUpUserInGroups(string $uid, array $groups): IUser {
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
		?array $tools = null,
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
	 * Register mock services on \OC::$server for agent tests.
	 *
	 * @param AgentMapper&MockObject $agentMapper
	 * @param ToolRegistry&MockObject $toolRegistry
	 * @param SettingsService&MockObject $settingsService
	 *
	 * @return void
	 */
	private function setUpOcServer(
		MockObject $agentMapper,
		MockObject $toolRegistry,
		MockObject $settingsService,
	): void {
		\OC::$server->registerService(AgentMapper::class, function () use ($agentMapper) {
			return $agentMapper;
		});
		\OC::$server->registerService(ToolRegistry::class, function () use ($toolRegistry) {
			return $toolRegistry;
		});
		\OC::$server->registerService(SettingsService::class, function () use ($settingsService) {
			return $settingsService;
		});
	}

	// ====================================================================
	// canExecuteEndpoint — permission checks (direct)
	// ====================================================================

	public function testCanExecuteEndpointNoUserPublicEndpoint(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', []);
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointNoUserGroupsRequired(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors']);
		$this->userSession->method('getUser')->willReturn(null);

		$this->assertFalse($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointAdminAlwaysAllowed(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['special-group']);
		$this->setUpAdminUser();

		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointNoGroupsAllowsAuthenticated(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', []);
		$this->setUpUserInGroups('regularuser', ['users']);

		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointUserInAllowedGroup(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors', 'viewers']);
		$this->setUpUserInGroups('editor1', ['editors']);

		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointUserInSecondAllowedGroup(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors', 'viewers']);
		$this->setUpUserInGroups('viewer1', ['viewers']);

		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointUserNotInAnyAllowedGroup(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors', 'viewers']);
		$this->setUpUserInGroups('outsider', ['users', 'marketing']);

		$this->assertFalse($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointUserInMultipleGroupsOneMatches(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['special']);
		$this->setUpUserInGroups('multigroup', ['users', 'special', 'marketing']);

		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}

	// ====================================================================
	// executeEndpoint — target type routing (direct)
	// ====================================================================

	public function testExecuteEndpointViewType(): void {
		$endpoint = $this->createEndpoint('view');
		$request = ['method' => 'GET', 'path' => '/api/test', 'data' => [], 'headers' => []];

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertTrue($result['success']);
		$this->assertSame(200, $result['statusCode']);
		$this->assertSame('View endpoint executed (placeholder)', $result['response']['message']);
	}

	public function testExecuteEndpointWebhookType(): void {
		$endpoint = $this->createEndpoint('webhook');
		$request = ['method' => 'POST', 'path' => '/webhook', 'data' => [], 'headers' => []];

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertTrue($result['success']);
		$this->assertSame(200, $result['statusCode']);
		$this->assertSame('Webhook endpoint executed (placeholder)', $result['response']['message']);
	}

	public function testExecuteEndpointRegisterType(): void {
		$endpoint = $this->createEndpoint('register');
		$request = ['method' => 'GET', 'path' => '/register', 'data' => [], 'headers' => []];

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertTrue($result['success']);
		$this->assertSame(200, $result['statusCode']);
		$this->assertSame('Register endpoint executed (placeholder)', $result['response']['message']);
	}

	public function testExecuteEndpointSchemaType(): void {
		$endpoint = $this->createEndpoint('schema');
		$request = ['method' => 'GET', 'path' => '/schema', 'data' => [], 'headers' => []];

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertTrue($result['success']);
		$this->assertSame(200, $result['statusCode']);
		$this->assertSame('Schema endpoint executed (placeholder)', $result['response']['message']);
	}

	public function testExecuteEndpointUnknownType(): void {
		$endpoint = $this->createEndpoint('nonexistent');
		$request = ['method' => 'GET', 'path' => '/test', 'data' => [], 'headers' => []];

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertFalse($result['success']);
		$this->assertSame(400, $result['statusCode']);
		$this->assertStringContainsString('Unknown target type: nonexistent', $result['error']);
	}

	public function testExecuteEndpointAgentTypeFailsGracefullyWhenAgentNotFound(): void {
		// Agent endpoint resolves services via \OC::$server->get() and then
		// tries to find the agent — returns an error when the agent UUID doesn't exist.
		// Seed AgentMapper / ToolRegistry / SettingsService into the
		// service container so the resolution path doesn't NPE on a
		// null AgentMapper (other tests may or may not have set it).
		$agentMapper = $this->createMock(AgentMapper::class);
		$agentMapper->method('findByUuid')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Agent not found'));
		$this->setUpOcServer(
			$agentMapper,
			$this->createMock(ToolRegistry::class),
			$this->createMock(SettingsService::class)
		);

		$endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
		$request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'hello'], 'headers' => []];

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
	}

	// ====================================================================
	// executeAgentEndpoint — with mocked OC::$server
	// ====================================================================

	public function testExecuteAgentEndpointAgentNotFound(): void {
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

	public function testExecuteAgentEndpointEmptyMessage(): void {
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

	public function testExecuteAgentEndpointEmptyMessageString(): void {
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

	public function testExecuteAgentEndpointMessageInTopLevelRequest(): void {
		// Agent endpoint execution is not implemented (deprecated pending
		// hermiq migration); this test verifies message extraction from the
		// top-level request still runs before the 501 is returned (i.e. it
		// does not short-circuit into the 400 "Message is required" path).
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
			'method' => 'POST',
			'path' => '/api/agent',
			'data' => [],
			'headers' => [],
			'message' => 'Hello from top level',
		];

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertFalse($result['success']);
		$this->assertSame(501, $result['statusCode']);
		$this->assertSame('Agent endpoint type is not implemented', $result['error']);
	}

	public function testExecuteAgentEndpointUnsupportedProvider(): void {
		// Agent endpoint execution is not implemented regardless of provider.
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
		$this->assertSame('Agent endpoint type is not implemented', $result['error']);
	}

	public function testExecuteAgentEndpointNoToolsConfigured(): void {
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

		// Agent + message validation pass; execution is not implemented.
		$this->assertFalse($result['success']);
		$this->assertSame(501, $result['statusCode']);
	}

	public function testExecuteAgentEndpointNullToolsConfigured(): void {
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

		// Null tools must not cause a crash; execution is not implemented.
		$this->assertFalse($result['success']);
		$this->assertSame(501, $result['statusCode']);
	}

	public function testExecuteAgentEndpointToolReturnsNull(): void {
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

		// Agent lookup + message validation still run; execution itself is
		// not implemented.
		$this->assertFalse($result['success']);
		$this->assertSame(501, $result['statusCode']);
	}

	public function testExecuteAgentEndpointOllamaProviderReturnsNotImplemented(): void {
		// Regression test: agent execution used to call the undefined
		// callOllamaWithTools() method and fatal with an Error for the
		// 'ollama' provider. It must now return a graceful 501 instead.
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

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertFalse($result['success']);
		$this->assertSame(501, $result['statusCode']);
		$this->assertSame('Agent endpoint type is not implemented', $result['error']);
	}

	public function testExecuteAgentEndpointOllamaWithPromptReturnsNotImplemented(): void {
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

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertFalse($result['success']);
		$this->assertSame(501, $result['statusCode']);
	}

	public function testExecuteAgentEndpointOllamaWithoutPromptReturnsNotImplemented(): void {
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

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertFalse($result['success']);
		$this->assertSame(501, $result['statusCode']);
	}

	public function testExecuteAgentEndpointOllamaDefaultUrlReturnsNotImplemented(): void {
		$agent = $this->createAgent('Agent', 'ollama', 'llama3', null, []);
		$agentMapper = $this->createMock(AgentMapper::class);
		$toolRegistry = $this->createMock(ToolRegistry::class);
		$settingsService = $this->createMock(SettingsService::class);

		$agentMapper->method('findByUuid')->willReturn($agent);
		// No ollamaConfig — should use default URL (irrelevant now, but
		// must not cause a crash while resolving it).
		$settingsService->method('getSettings')->willReturn(['llm' => []]);

		$this->setUpOcServer($agentMapper, $toolRegistry, $settingsService);

		$endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'agent-uuid-123');
		$request = ['method' => 'POST', 'path' => '/api/agent', 'data' => ['message' => 'Hi'], 'headers' => []];

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertFalse($result['success']);
		$this->assertSame(501, $result['statusCode']);
	}

	public function testExecuteAgentEndpointOllamaNoLlmConfigReturnsNotImplemented(): void {
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

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertFalse($result['success']);
		$this->assertSame(501, $result['statusCode']);
	}

	// ====================================================================
	// testEndpoint — full integration through public API
	// ====================================================================

	public function testTestEndpointDeniedWhenNoUserAndGroupsRequired(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors']);

		$this->userSession
			->method('getUser')
			->willReturn(null);

		$result = $this->service->testEndpoint($endpoint);

		$this->assertFalse($result['success']);
		$this->assertSame(403, $result['statusCode']);
		$this->assertStringContainsString('Access denied', $result['error']);
	}

	public function testTestEndpointAllowedForAdminUser(): void {
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

	public function testTestEndpointAllowedWhenNoGroupsDefined(): void {
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

	public function testTestEndpointAllowedWhenUserInAllowedGroup(): void {
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

	public function testTestEndpointDeniedWhenUserNotInGroup(): void {
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

	public function testTestEndpointAllowedForPublicEndpointWithNoUser(): void {
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

	public function testTestEndpointViewTargetType(): void {
		$endpoint = $this->createEndpoint('view');
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint);

		$this->assertTrue($result['success']);
		$this->assertSame(200, $result['statusCode']);
	}

	public function testTestEndpointWebhookTargetType(): void {
		$endpoint = $this->createEndpoint('webhook');
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint);

		$this->assertTrue($result['success']);
	}

	public function testTestEndpointRegisterTargetType(): void {
		$endpoint = $this->createEndpoint('register');
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint);

		$this->assertTrue($result['success']);
	}

	public function testTestEndpointSchemaTargetType(): void {
		$endpoint = $this->createEndpoint('schema');
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint);

		$this->assertTrue($result['success']);
	}

	public function testTestEndpointUnknownTargetType(): void {
		$endpoint = $this->createEndpoint('unknown_type');
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint);

		$this->assertFalse($result['success']);
		$this->assertSame(400, $result['statusCode']);
		$this->assertStringContainsString('Unknown target type', $result['error']);
	}

	// --- testEndpoint: agent target type ---

	public function testTestEndpointAgentTargetTypeReturnsErrorWhenAgentNotFound(): void {
		$endpoint = $this->createEndpoint('agent', 'POST', '/api/agent', [], 1, 'some-agent-uuid');
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint);

		// Agent endpoint resolves services but the agent UUID doesn't exist.
		$this->assertFalse($result['success']);
		$this->assertArrayHasKey('error', $result);
	}

	public function testTestEndpointAgentNotFoundViaTestEndpoint(): void {
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

	public function testTestEndpointAgentEmptyMessageViaTestEndpoint(): void {
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

	public function testTestEndpointAgentWithMessageInTestData(): void {
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

		// Agent execution is not implemented.
		$this->assertFalse($result['success']);
		$this->assertSame(501, $result['statusCode']);
	}

	// --- testEndpoint: with test data ---

	public function testTestEndpointPassesTestDataThrough(): void {
		$endpoint = $this->createEndpoint('view', 'POST', '/api/test', []);
		$this->setUpAdminUser();

		$testData = ['key' => 'value', 'nested' => ['a' => 1]];

		$result = $this->service->testEndpoint($endpoint, $testData);

		$this->assertTrue($result['success']);
		$this->assertSame(200, $result['statusCode']);
	}

	public function testTestEndpointUsesMethodFromEndpoint(): void {
		$endpoint = $this->createEndpoint('view', 'POST', '/api/create', []);
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint);

		$this->assertTrue($result['success']);
	}

	public function testTestEndpointNullMethodDefaultsToGet(): void {
		$endpoint = $this->createEndpoint('view', null, '/api/test', []);
		$this->setUpAdminUser();

		// The method defaults to 'GET' when null in testEndpoint line 123.
		$result = $this->service->testEndpoint($endpoint);

		$this->assertTrue($result['success']);
	}

	// --- testEndpoint: error handling ---

	public function testTestEndpointCatchesGroupManagerException(): void {
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

	public function testTestEndpointCatchesExceptionAndReturnsErrorDetails(): void {
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

	public function testLogEndpointCallWithAuthenticatedUser(): void {
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

	public function testLogEndpointCallWithoutUser(): void {
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

	public function testLogEndpointCallWithErrorResult(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', [], 5);

		$this->userSession->method('getUser')->willReturn(null);

		$request = ['method' => 'GET', 'path' => '/api/fail', 'data' => [], 'headers' => []];
		$result = ['statusCode' => 400, 'response' => null, 'error' => 'Bad request'];

		$this->endpointLogMapper
			->expects($this->once())
			->method('insert');

		$this->service->publicLogEndpointCall($endpoint, $request, $result);
	}

	public function testLogEndpointCallWithSuccessNoErrorKey(): void {
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

	public function testLogEndpointCallInsertFailureDoesNotThrow(): void {
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

	public function testLogEndpointCallVerifiesLogProperties(): void {
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
				// The response, asserted rather than excused. This used to read
				// "setResponse uses named arg in source code (known issue), so
				// response may be null. We verify it was attempted" — and it
				// verified nothing, so every endpoint log stored a null
				// response and the suite stayed green.
				$this->assertSame(
					['statusCode' => 200, 'body' => ['items' => [1, 2, 3]]],
					$log->getResponse(),
					'the response must be stored on the log, not dropped'
				);
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

	public function testLogEndpointCallSuccessMessageDefault(): void {
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

	public function testTestEndpointLogsCallSuccessfully(): void {
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

	public function testTestEndpointLoggingErrorDoesNotBreakExecution(): void {
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

	public function testTestEndpointWithEmptyTestData(): void {
		$endpoint = $this->createEndpoint('webhook', 'POST', '/api/webhook', []);
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint, []);

		$this->assertTrue($result['success']);
		$this->assertSame(200, $result['statusCode']);
	}

	public function testTestEndpointWithDifferentEndpointIds(): void {
		$endpoint = $this->createEndpoint('register', 'GET', '/api/register', [], 999);
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint);

		$this->assertTrue($result['success']);
	}

	public function testTestEndpointResponseStructureForSuccess(): void {
		$endpoint = $this->createEndpoint('view');
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint);

		$this->assertArrayHasKey('success', $result);
		$this->assertArrayHasKey('statusCode', $result);
		$this->assertArrayHasKey('response', $result);
		$this->assertIsBool($result['success']);
		$this->assertIsInt($result['statusCode']);
	}

	public function testTestEndpointResponseStructureForDenied(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['secret-group']);
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->service->testEndpoint($endpoint);

		$this->assertArrayHasKey('success', $result);
		$this->assertArrayHasKey('statusCode', $result);
		$this->assertArrayHasKey('response', $result);
		$this->assertArrayHasKey('error', $result);
		$this->assertNull($result['response']);
	}

	public function testTestEndpointResponseStructureForUnknownType(): void {
		$endpoint = $this->createEndpoint('foobar');
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint);

		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('foobar', $result['error']);
	}

	public function testExecuteEndpointWithEmptyTargetType(): void {
		$endpoint = $this->createEndpoint('');
		$request = ['method' => 'GET', 'path' => '/test', 'data' => [], 'headers' => []];

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		// Empty string hits the default case.
		$this->assertFalse($result['success']);
		$this->assertSame(400, $result['statusCode']);
	}

	public function testCanExecuteEndpointWithEmptyGroupsArrayAndNoUser(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', []);
		$this->userSession->method('getUser')->willReturn(null);

		// Empty groups = public access = allowed.
		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointAdminBypassesGroupRestriction(): void {
		// Endpoint restricted to 'finance' group, but admin should bypass.
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['finance']);
		$this->setUpAdminUser();

		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointUserWithNoGroupsAndEndpointHasGroups(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors']);
		$this->setUpUserInGroups('lonely', []);

		$this->assertFalse($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testLogEndpointCallWithLargeRequestData(): void {
		$endpoint = $this->createEndpoint('view', 'POST', '/api/test', [], 1);
		$this->userSession->method('getUser')->willReturn(null);

		$largeData = str_repeat('x', 10000);
		$request = ['method' => 'POST', 'path' => '/api/test', 'data' => ['payload' => $largeData], 'headers' => []];
		$result = ['statusCode' => 200, 'response' => ['data' => $largeData]];

		$this->endpointLogMapper->expects($this->once())->method('insert');

		$this->service->publicLogEndpointCall($endpoint, $request, $result);
	}

	public function testTestEndpointDifferentEndpointPaths(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/v2/buildings/123', []);
		$this->setUpAdminUser();

		$result = $this->service->testEndpoint($endpoint);

		$this->assertTrue($result['success']);
	}

	public function testTestEndpointAllPlaceholderTargetTypesReturnCorrectMessage(): void {
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

	public function testTestEndpointAgentReturnsNotImplementedAndLogsResult(): void {
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
		$this->assertSame('Agent endpoint type is not implemented', $result['error']);
	}

	public function testTestEndpointAgentNotFoundLogsError(): void {
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

	public function testTestEndpointAgentWithToolsAndMessage(): void {
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

		// A configured agent with tools still gets a not-implemented response.
		$this->assertFalse($result['success']);
		$this->assertSame(501, $result['statusCode']);
	}

	// ====================================================================
	// Multiple unknown target types
	// ====================================================================

	public function testExecuteEndpointCaseInsensitiveTargetType(): void {
		// Target types are case-sensitive — 'View' is not the same as 'view'.
		$endpoint = $this->createEndpoint('View');
		$request = ['method' => 'GET', 'path' => '/test', 'data' => [], 'headers' => []];

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertFalse($result['success']);
		$this->assertSame(400, $result['statusCode']);
		$this->assertStringContainsString('View', $result['error']);
	}

	public function testExecuteEndpointWithSpecialCharTargetType(): void {
		$endpoint = $this->createEndpoint('view/inject');
		$request = ['method' => 'GET', 'path' => '/test', 'data' => [], 'headers' => []];

		$result = $this->service->publicExecuteEndpoint($endpoint, $request);

		$this->assertFalse($result['success']);
		$this->assertSame(400, $result['statusCode']);
	}

	// ====================================================================
	// canExecuteEndpoint — additional group edge cases
	// ====================================================================

	public function testCanExecuteEndpointUserInAllGroupsNotJustOne(): void {
		// User is in ALL the allowed groups — should still pass.
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors', 'viewers']);
		$this->setUpUserInGroups('superuser', ['editors', 'viewers', 'admin']);

		// admin group present => true (admin bypass).
		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointNonAdminUserInAllAllowedGroups(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['editors', 'viewers']);
		$this->setUpUserInGroups('regularuser', ['editors', 'viewers']);

		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointSingleGroup(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['only-group']);
		$this->setUpUserInGroups('member', ['only-group']);

		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointManyGroupsNoneMatch(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['a', 'b', 'c', 'd', 'e']);
		$this->setUpUserInGroups('outsider', ['x', 'y', 'z']);

		$this->assertFalse($this->service->publicCanExecuteEndpoint($endpoint));
	}

	public function testCanExecuteEndpointLastGroupMatches(): void {
		$endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['a', 'b', 'c']);
		$this->setUpUserInGroups('user', ['c']);

		$this->assertTrue($this->service->publicCanExecuteEndpoint($endpoint));
	}
}
