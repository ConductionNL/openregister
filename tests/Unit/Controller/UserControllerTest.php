<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\UserController;
use OCA\OpenRegister\Service\SecurityService;
use OCA\OpenRegister\Service\UserService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserControllerTest extends TestCase
{
    private UserController $controller;
    private IRequest&MockObject $request;
    private UserService&MockObject $userService;
    private SecurityService&MockObject $securityService;
    private IUserManager&MockObject $userManager;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->userService = $this->createMock(UserService::class);
        $this->securityService = $this->createMock(SecurityService::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new UserController(
            'openregister',
            $this->request,
            $this->userService,
            $this->securityService,
            $this->userManager,
            $this->userSession,
            $this->logger
        );
    }

    public function testMeSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $userData = ['uid' => 'testuser', 'displayName' => 'Test User'];

        $this->userService->method('getCurrentUser')->willReturn($user);
        $this->userService->method('buildUserDataArray')->willReturn($userData);

        $result = $this->controller->me();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($userData, $result->getData());
    }

    public function testMeNotAuthenticated(): void
    {
        $this->userService->method('getCurrentUser')->willReturn(null);

        $result = $this->controller->me();

        $this->assertEquals(401, $result->getStatus());
        $this->assertEquals('Not authenticated', $result->getData()['error']);
    }

    public function testMeException(): void
    {
        $this->userService->method('getCurrentUser')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->me();

        $this->assertEquals(500, $result->getStatus());
        $this->assertEquals('Failed to retrieve user profile', $result->getData()['error']);
    }

    public function testUpdateMeSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $updatedProfile = ['uid' => 'testuser', 'displayName' => 'Updated'];

        $this->userService->method('getCurrentUser')->willReturn($user);
        $this->request->method('getParams')->willReturn(['displayName' => 'Updated']);
        $this->securityService->method('sanitizeInput')->willReturnArgument(0);
        $this->userService->method('updateUserProperties')->willReturn($updatedProfile);

        $result = $this->controller->updateMe();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals($updatedProfile, $result->getData());
    }

    public function testUpdateMeNotAuthenticated(): void
    {
        $this->userService->method('getCurrentUser')->willReturn(null);

        $result = $this->controller->updateMe();

        $this->assertEquals(401, $result->getStatus());
    }

    public function testUpdateMeStripsInternalAndImmutableFields(): void
    {
        $user = $this->createMock(IUser::class);

        $this->userService->method('getCurrentUser')->willReturn($user);
        $this->request->method('getParams')->willReturn([
            '_route' => 'test',
            'id' => '123',
            'uid' => 'admin',
            'created' => '2024-01-01',
            'displayName' => 'Valid',
        ]);
        $this->securityService->method('sanitizeInput')->willReturnArgument(0);

        $this->userService
            ->expects($this->once())
            ->method('updateUserProperties')
            ->with($this->anything(), $this->callback(function ($data) {
                return isset($data['displayName'])
                    && !isset($data['_route'])
                    && !isset($data['id'])
                    && !isset($data['uid'])
                    && !isset($data['created']);
            }))
            ->willReturn(['displayName' => 'Valid']);

        $this->controller->updateMe();
    }

    public function testLogoutSuccess(): void
    {
        $this->userSession->expects($this->once())->method('logout');

        $response = $this->createMock(JSONResponse::class);
        $this->securityService->method('addSecurityHeaders')->willReturnArgument(0);

        $result = $this->controller->logout();

        $this->assertInstanceOf(JSONResponse::class, $result);
        $this->assertTrue($result->getData()['logout']);
    }

    public function testLoginInvalidCredentials(): void
    {
        $this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
        $this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
        $this->request->method('getParams')->willReturn([
            'username' => 'testuser',
            'password' => 'wrongpass',
        ]);
        $this->securityService->method('validateLoginCredentials')->willReturn([
            'valid' => true,
            'credentials' => ['username' => 'testuser', 'password' => 'wrongpass'],
        ]);
        $this->securityService->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
        $this->userManager->method('checkPassword')->willReturn(false);

        $result = $this->controller->login();

        $this->assertEquals(401, $result->getStatus());
        $this->assertEquals('Invalid username or password', $result->getData()['error']);
    }

    public function testLoginValidationFails(): void
    {
        $this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
        $this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
        $this->request->method('getParams')->willReturn([]);
        $this->securityService->method('validateLoginCredentials')->willReturn([
            'valid' => false,
            'error' => 'Username is required',
        ]);

        $result = $this->controller->login();

        $this->assertEquals(400, $result->getStatus());
    }

    public function testLoginRateLimited(): void
    {
        $this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
        $this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
        $this->request->method('getParams')->willReturn([
            'username' => 'testuser',
            'password' => 'pass',
        ]);
        $this->securityService->method('validateLoginCredentials')->willReturn([
            'valid' => true,
            'credentials' => ['username' => 'testuser', 'password' => 'pass'],
        ]);
        $this->securityService->method('checkLoginRateLimit')->willReturn([
            'allowed' => false,
            'reason' => 'Too many attempts',
        ]);

        $result = $this->controller->login();

        $this->assertEquals(429, $result->getStatus());
    }

    public function testLoginDisabledAccount(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(false);

        $this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
        $this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
        $this->request->method('getParams')->willReturn([
            'username' => 'testuser',
            'password' => 'pass',
        ]);
        $this->securityService->method('validateLoginCredentials')->willReturn([
            'valid' => true,
            'credentials' => ['username' => 'testuser', 'password' => 'pass'],
        ]);
        $this->securityService->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
        $this->userManager->method('checkPassword')->willReturn($user);

        $result = $this->controller->login();

        $this->assertEquals(401, $result->getStatus());
        $this->assertEquals('Account is disabled', $result->getData()['error']);
    }

    public function testLoginSuccess(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('isEnabled')->willReturn(true);
        $user->method('getUID')->willReturn('testuser');

        $this->securityService->method('getClientIpAddress')->willReturn('127.0.0.1');
        $this->securityService->method('addSecurityHeaders')->willReturnArgument(0);
        $this->request->method('getParams')->willReturn([
            'username' => 'testuser',
            'password' => 'correctpass',
        ]);
        $this->securityService->method('validateLoginCredentials')->willReturn([
            'valid' => true,
            'credentials' => ['username' => 'testuser', 'password' => 'correctpass'],
        ]);
        $this->securityService->method('checkLoginRateLimit')->willReturn(['allowed' => true]);
        $this->userManager->method('checkPassword')->willReturn($user);
        $this->userService->method('buildUserDataArray')->willReturn(['uid' => 'testuser']);

        $result = $this->controller->login();

        $this->assertEquals(200, $result->getStatus());
        $this->assertEquals('Login successful', $result->getData()['message']);
        $this->assertTrue($result->getData()['session_created']);
    }
}
