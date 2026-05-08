<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\UserService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountProperty;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Branch coverage tests for UserService — targets uncovered branches in
 * buildUserDataArray, updateUserProperties, getCustomNameFields,
 * setCustomNameFields, determineChangedFields, buildQuotaInformation,
 * getLanguageAndLocale, getAdditionalProfileInfo, updateStandardUserProperties,
 * updateProfileProperties, getDefaultPropertyScope.
 */
class UserServiceBranchCoverageTest extends TestCase
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
        parent::setUp();

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

    // =========================================================================
    // getCurrentUser
    // =========================================================================

    public function testGetCurrentUser(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $result = $this->service->getCurrentUser();
        $this->assertSame($user, $result);
    }

    public function testGetCurrentUserNull(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->service->getCurrentUser();
        $this->assertNull($result);
    }

    // =========================================================================
    // getCustomNameFields
    // =========================================================================

    public function testGetCustomNameFieldsReturnsNames(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->config->method('getUserValue')
            ->willReturnCallback(function ($userId, $app, $key, $default) {
                $values = [
                    'firstName' => 'John',
                    'lastName' => 'Doe',
                    'middleName' => '',
                ];
                return $values[$key] ?? $default;
            });

        $result = $this->service->getCustomNameFields($user);

        $this->assertSame('John', $result['firstName']);
        $this->assertSame('Doe', $result['lastName']);
        $this->assertNull($result['middleName']);
    }

    public function testGetCustomNameFieldsAllEmpty(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->getCustomNameFields($user);

        $this->assertNull($result['firstName']);
        $this->assertNull($result['lastName']);
        $this->assertNull($result['middleName']);
    }

    // =========================================================================
    // setCustomNameFields
    // =========================================================================

    public function testSetCustomNameFields(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->config->expects($this->exactly(2))
            ->method('setUserValue');

        $this->service->setCustomNameFields($user, [
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'invalidField' => 'should be ignored',
        ]);
    }

    // =========================================================================
    // buildUserDataArray — organisation exception path
    // =========================================================================

    public function testBuildUserDataArrayHandlesOrganisationException(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $user->method('getDisplayName')->willReturn('Test User');
        $user->method('getEMailAddress')->willReturn('test@example.com');
        $user->method('isEnabled')->willReturn(true);

        $this->groupManager->method('getUserGroups')->willReturn([]);

        $this->config->method('getUserValue')->willReturn('');

        // Mock AccountManager to return empty account
        $account = $this->createMock(IAccount::class);
        $account->method('getProperty')->willThrowException(
            new \OCP\Accounts\PropertyDoesNotExistException('Not found')
        );
        $this->accountManager->method('getAccount')->willReturn($account);

        // Organisation service throws exception
        $this->organisationService->method('getUserOrganisationStats')
            ->willThrowException(new \Exception('Org service error'));

        $result = $this->service->buildUserDataArray($user);

        $this->assertSame('testuser', $result['uid']);
        $this->assertArrayHasKey('organisations', $result);
        $this->assertSame(0, $result['organisations']['total']);
    }

    // =========================================================================
    // updateUserProperties — with activeOrganisation
    // =========================================================================

    public function testUpdateUserPropertiesWithActiveOrganisation(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $user->method('getDisplayName')->willReturn('Test');
        $user->method('getEMailAddress')->willReturn('test@example.com');
        $user->method('isEnabled')->willReturn(true);

        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->config->method('getUserValue')->willReturn('');

        $account = $this->createMock(IAccount::class);
        $account->method('getProperty')->willThrowException(
            new \OCP\Accounts\PropertyDoesNotExistException('Not found')
        );
        $this->accountManager->method('getAccount')->willReturn($account);

        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);

        $this->organisationService->expects($this->once())
            ->method('setActiveOrganisation')
            ->with('new-org-uuid')
            ->willReturn(true);

        $result = $this->service->updateUserProperties($user, [
            'activeOrganisation' => 'new-org-uuid',
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['organisation_updated']);
    }

    public function testUpdateUserPropertiesWithFailedOrganisationSwitch(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $user->method('getDisplayName')->willReturn('Test');
        $user->method('getEMailAddress')->willReturn('test@example.com');
        $user->method('isEnabled')->willReturn(true);

        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->config->method('getUserValue')->willReturn('');

        $account = $this->createMock(IAccount::class);
        $account->method('getProperty')->willThrowException(
            new \OCP\Accounts\PropertyDoesNotExistException('Not found')
        );
        $this->accountManager->method('getAccount')->willReturn($account);

        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);

        $this->organisationService->method('setActiveOrganisation')
            ->willReturn(false);

        $result = $this->service->updateUserProperties($user, [
            'activeOrganisation' => 'bad-org',
        ]);

        $this->assertFalse($result['organisation_updated']);
        $this->assertSame('Failed to update active organization', $result['organisation_message']);
    }
}
