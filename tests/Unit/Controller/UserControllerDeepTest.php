<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Controller\UserController;
use OCA\OpenRegister\Service\SecurityService;
use OCA\OpenRegister\Service\UserService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class UserControllerDeepTest extends TestCase
{
    private UserController $controller;
    private IRequest|MockObject $request;
    private UserService|MockObject $userService;
    private SecurityService|MockObject $securityService;
    private IUserManager|MockObject $userManager;
    private IUserSession|MockObject $userSession;
    private LoggerInterface|MockObject $logger;

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
            $this->logger,
            $this->createMock(IL10N::class)
        );
    }

    public function testMeNotAuthenticated(): void
    {
        $this->userService->method('getCurrentUser')->willReturn(null);

        $response = $this->controller->me();

        $this->assertEquals(401, $response->getStatus());
    }

    public function testMeException(): void
    {
        $this->userService->method('getCurrentUser')
            ->willThrowException(new Exception('user error'));

        $response = $this->controller->me();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testUpdateMeNotAuthenticated(): void
    {
        $this->userService->method('getCurrentUser')->willReturn(null);

        $response = $this->controller->updateMe();

        $this->assertEquals(401, $response->getStatus());
    }

    public function testUpdateMeException(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userService->method('getCurrentUser')->willReturn($user);
        $this->request->method('getParams')->willReturn(['displayName' => 'Test']);
        $this->securityService->method('sanitizeInput')->willReturnArgument(0);
        $this->userService->method('updateUserProperties')
            ->willThrowException(new Exception('update fail'));

        $response = $this->controller->updateMe();

        $this->assertEquals(500, $response->getStatus());
    }

    public function testUpdateMeStripsInternalAndImmutableParams(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userService->method('getCurrentUser')->willReturn($user);
        $this->request->method('getParams')->willReturn([
            '_route' => 'internal',
            'id' => 1,
            'uid' => 'admin',
            'created' => '2024-01-01',
            'displayName' => 'New Name',
        ]);
        $this->securityService->method('sanitizeInput')->willReturnArgument(0);
        $this->userService->expects($this->once())
            ->method('updateUserProperties')
            ->with($user, $this->callback(function ($data) {
                return isset($data['displayName'])
                    && !isset($data['id'])
                    && !isset($data['uid'])
                    && !isset($data['created'])
                    && !isset($data['_route']);
            }))
            ->willReturn(['displayName' => 'New Name']);

        $response = $this->controller->updateMe();

        $this->assertEquals(200, $response->getStatus());
    }

    public function testConvertToBytesUnlimited(): void
    {
        $ref = new ReflectionClass(UserController::class);
        $method = $ref->getMethod('convertToBytes');
        $method->setAccessible(true);

        $this->assertEquals(0, $method->invoke($this->controller, '-1'));
    }

    public function testConvertToBytesGigabytes(): void
    {
        $ref = new ReflectionClass(UserController::class);
        $method = $ref->getMethod('convertToBytes');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, '1G');
        $this->assertEquals(1073741824, $result);
    }

    public function testConvertToBytesMegabytes(): void
    {
        $ref = new ReflectionClass(UserController::class);
        $method = $ref->getMethod('convertToBytes');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, '128M');
        $this->assertEquals(134217728, $result);
    }

    public function testConvertToBytesKilobytes(): void
    {
        $ref = new ReflectionClass(UserController::class);
        $method = $ref->getMethod('convertToBytes');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, '256K');
        $this->assertEquals(262144, $result);
    }

    public function testLogout(): void
    {
        $this->userSession->expects($this->once())->method('logout');
        $this->securityService->method('addSecurityHeaders')
            ->willReturnArgument(0);

        $response = $this->controller->logout();

        $this->assertEquals(200, $response->getStatus());
        $this->assertTrue($response->getData()['logout']);
    }
}
