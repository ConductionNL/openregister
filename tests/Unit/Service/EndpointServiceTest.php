<?php

namespace Unit\Service;

use OCA\OpenRegister\Db\Endpoint;
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

    private EndpointService $service;

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
        ?int $id = 1
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

        return $endpoint;
    }

    // --- testEndpoint: permission checks ---

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

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['statusCode']);
    }

    public function testTestEndpointWebhookTargetType(): void
    {
        $endpoint = $this->createEndpoint('webhook');

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    public function testTestEndpointRegisterTargetType(): void
    {
        $endpoint = $this->createEndpoint('register');

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    public function testTestEndpointSchemaTargetType(): void
    {
        $endpoint = $this->createEndpoint('schema');

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);

        $result = $this->service->testEndpoint($endpoint);

        $this->assertTrue($result['success']);
    }

    public function testTestEndpointUnknownTargetType(): void
    {
        $endpoint = $this->createEndpoint('unknown_type');

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')->willReturn(['admin']);

        $result = $this->service->testEndpoint($endpoint);

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['statusCode']);
        $this->assertStringContainsString('Unknown target type', $result['error']);
    }

    // --- testEndpoint: error handling ---

    public function testTestEndpointCatchesGroupManagerException(): void
    {
        $endpoint = $this->createEndpoint('view', 'GET', '/api/test', ['test']);

        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);
        $this->groupManager->method('getUserGroupIds')
            ->willThrowException(new \Exception('Unexpected error'));

        $result = $this->service->testEndpoint($endpoint);

        $this->assertFalse($result['success']);
        $this->assertSame(500, $result['statusCode']);
    }

    // --- logEndpointCall ---

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
}
