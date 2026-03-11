<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointLog;
use OCA\OpenRegister\Db\EndpointLogMapper;
use OCA\OpenRegister\Service\EndpointService;
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
            // Need fresh service for each since userSession->getUser can only be stubbed once.
            // But since we already set it up, just create endpoints.
            $endpoint = $this->createEndpoint($type, 'GET', '/api/' . $type, []);
            $request = ['method' => 'GET', 'path' => '/api/' . $type, 'data' => [], 'headers' => []];

            $result = $this->service->publicExecuteEndpoint($endpoint, $request);

            $this->assertTrue($result['success'], "Expected success for target type: $type");
            $this->assertSame($expectedMessage, $result['response']['message'], "Wrong message for type: $type");
        }
    }
}
