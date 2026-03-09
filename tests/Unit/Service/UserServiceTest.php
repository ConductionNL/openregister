<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\UserService;
use OCP\Accounts\IAccountManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for UserService.
 *
 * Note: buildUserDataArray and updateUserProperties call private methods that
 * use OC::$server which is not available in pure unit tests. Those methods are
 * tested only when the OC class is available (integration-level), but we test
 * the simpler public methods here.
 */
class UserServiceTest extends TestCase
{
    private UserService $service;
    private IUserManager&MockObject $userManager;
    private IUserSession&MockObject $userSession;
    private IConfig&MockObject $config;
    private IGroupManager&MockObject $groupManager;
    private IAccountManager&MockObject $accountManager;
    private LoggerInterface&MockObject $logger;
    private OrganisationService&MockObject $organisationService;
    private IEventDispatcher&MockObject $eventDispatcher;

    protected function setUp(): void
    {
        $this->userManager = $this->createMock(IUserManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->accountManager = $this->createMock(IAccountManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);

        $this->service = new UserService(
            $this->userManager,
            $this->userSession,
            $this->config,
            $this->groupManager,
            $this->accountManager,
            $this->logger,
            $this->organisationService,
            $this->eventDispatcher
        );
    }

    private function createUserMock(string $uid = 'testuser'): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn('Test User');
        $user->method('getEMailAddress')->willReturn('test@example.com');
        $user->method('isEnabled')->willReturn(true);
        return $user;
    }

    // ── getCurrentUser ──

    public function testGetCurrentUserReturnsUserFromSession(): void
    {
        $user = $this->createUserMock();
        $this->userSession->method('getUser')->willReturn($user);

        $result = $this->service->getCurrentUser();
        $this->assertSame($user, $result);
    }

    public function testGetCurrentUserReturnsNullWhenNotAuthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->assertNull($this->service->getCurrentUser());
    }

    // ── getCustomNameFields ──

    public function testGetCustomNameFieldsReturnsFieldsFromConfig(): void
    {
        $user = $this->createUserMock();
        $this->config->method('getUserValue')->willReturnCallback(
            function (string $userId, string $app, string $key, string $default) {
                $values = [
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'middleName' => 'M',
                ];
                return $values[$key] ?? $default;
            }
        );

        $result = $this->service->getCustomNameFields($user);

        $this->assertSame('John', $result['firstName']);
        $this->assertSame('Doe', $result['lastName']);
        $this->assertSame('M', $result['middleName']);
    }

    public function testGetCustomNameFieldsReturnsNullForEmptyValues(): void
    {
        $user = $this->createUserMock();
        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->getCustomNameFields($user);

        $this->assertNull($result['firstName']);
        $this->assertNull($result['lastName']);
        $this->assertNull($result['middleName']);
    }

    // ── setCustomNameFields ──

    public function testSetCustomNameFieldsSetsAllowedFields(): void
    {
        $user = $this->createUserMock();

        $this->config->expects($this->exactly(2))
            ->method('setUserValue');

        $this->service->setCustomNameFields($user, [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'invalidField' => 'ignored',
        ]);
    }

    public function testSetCustomNameFieldsIgnoresDisallowedFields(): void
    {
        $user = $this->createUserMock();

        $this->config->expects($this->never())->method('setUserValue');

        $this->service->setCustomNameFields($user, ['invalidField' => 'ignored']);
    }

    public function testSetCustomNameFieldsSetsAllThreeFields(): void
    {
        $user = $this->createUserMock();

        $this->config->expects($this->exactly(3))
            ->method('setUserValue');

        $this->service->setCustomNameFields($user, [
            'firstName' => 'John',
            'lastName' => 'Doe',
            'middleName' => 'M',
        ]);
    }

    // ── buildUserDataArray (requires OC::$server, skip in pure unit tests) ──

    public function testBuildUserDataArrayRequiresOcClass(): void
    {
        $this->markTestSkipped('buildUserDataArray calls OC::$server — covered by integration tests');
    }

    public function testUpdateUserPropertiesRequiresOcClass(): void
    {
        $this->markTestSkipped('updateUserProperties calls OC::$server — covered by integration tests');
    }
}
