<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\UserSettingsController;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserSettingsControllerTest extends TestCase
{
    private UserSettingsController $controller;
    private IRequest&MockObject $request;
    private GitHubHandler&MockObject $gitHubService;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->request = $this->createMock(IRequest::class);
        $this->gitHubService = $this->createMock(GitHubHandler::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new UserSettingsController(
            'openregister',
            $this->request,
            $this->gitHubService,
            $this->userSession,
            $this->logger
        );
    }

    private function mockAuthenticatedUser(string $uid = 'testuser'): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        return $user;
    }

    public function testGetGitHubTokenStatusNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->controller->getGitHubTokenStatus();

        $this->assertEquals(401, $result->getStatus());
    }

    public function testGetGitHubTokenStatusNoToken(): void
    {
        $this->mockAuthenticatedUser();
        $this->gitHubService->method('getUserToken')->willReturn(null);

        $result = $this->controller->getGitHubTokenStatus();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertFalse($data['hasToken']);
        $this->assertFalse($data['isValid']);
    }

    public function testGetGitHubTokenStatusWithValidToken(): void
    {
        $this->mockAuthenticatedUser();
        $this->gitHubService->method('getUserToken')->willReturn('ghp_test123');
        $this->gitHubService->method('validateToken')->willReturn(true);

        $result = $this->controller->getGitHubTokenStatus();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['hasToken']);
        $this->assertTrue($data['isValid']);
        $this->assertEquals('Token is valid', $data['message']);
    }

    public function testGetGitHubTokenStatusWithInvalidToken(): void
    {
        $this->mockAuthenticatedUser();
        $this->gitHubService->method('getUserToken')->willReturn('ghp_invalid');
        $this->gitHubService->method('validateToken')->willReturn(false);

        $result = $this->controller->getGitHubTokenStatus();

        $this->assertEquals(200, $result->getStatus());
        $data = $result->getData();
        $this->assertTrue($data['hasToken']);
        $this->assertFalse($data['isValid']);
        $this->assertEquals('Token is invalid or expired', $data['message']);
    }

    public function testGetGitHubTokenStatusException(): void
    {
        $this->mockAuthenticatedUser();
        $this->gitHubService->method('getUserToken')
            ->willThrowException(new \Exception('DB error'));

        $result = $this->controller->getGitHubTokenStatus();

        $this->assertEquals(500, $result->getStatus());
    }

    public function testSetGitHubTokenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->controller->setGitHubToken();

        $this->assertEquals(401, $result->getStatus());
    }

    public function testSetGitHubTokenEmpty(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn(['token' => '']);

        $result = $this->controller->setGitHubToken();

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('Token is required', $result->getData()['error']);
    }

    public function testSetGitHubTokenInvalid(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn(['token' => 'ghp_bad']);
        $this->gitHubService->method('validateToken')->willReturn(false);

        $result = $this->controller->setGitHubToken();

        $this->assertEquals(400, $result->getStatus());
        $this->assertEquals('Invalid GitHub token', $result->getData()['error']);
    }

    public function testSetGitHubTokenSuccess(): void
    {
        $this->mockAuthenticatedUser();
        $this->request->method('getParams')->willReturn(['token' => 'ghp_valid123']);
        $this->gitHubService->method('validateToken')->willReturn(true);

        $result = $this->controller->setGitHubToken();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testRemoveGitHubTokenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->controller->removeGitHubToken();

        $this->assertEquals(401, $result->getStatus());
    }

    public function testRemoveGitHubTokenSuccess(): void
    {
        $this->mockAuthenticatedUser();

        $this->gitHubService
            ->expects($this->once())
            ->method('setUserToken')
            ->with(null, 'testuser');

        $result = $this->controller->removeGitHubToken();

        $this->assertEquals(200, $result->getStatus());
        $this->assertTrue($result->getData()['success']);
    }

    public function testRemoveGitHubTokenException(): void
    {
        $this->mockAuthenticatedUser();
        $this->gitHubService->method('setUserToken')
            ->willThrowException(new \Exception('Error'));

        $result = $this->controller->removeGitHubToken();

        $this->assertEquals(500, $result->getStatus());
    }
}
