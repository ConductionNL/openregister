<?php

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Event\UserProfileUpdatedEvent;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\UserService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
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
 * Covers getCurrentUser, getCustomNameFields, setCustomNameFields,
 * buildUserDataArray (including org stats, account properties, quota,
 * language/locale, name fields, caching, exception handling),
 * updateUserProperties (org switch success/failure, event dispatch,
 * standard property updates, profile property updates, functie fallback),
 * and determineChangedFields (via updateUserProperties).
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

    /**
     * Create a mock IUser with basic methods configured.
     */
    private function createUserMock(string $uid = 'testuser'): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn('Test User');
        $user->method('getEMailAddress')->willReturn('test@example.com');
        $user->method('isEnabled')->willReturn(true);
        return $user;
    }

    /**
     * Create a mock IAccountProperty with a given value.
     */
    private function createPropertyMock(string $value): IAccountProperty&MockObject
    {
        $property = $this->createMock(IAccountProperty::class);
        $property->method('getValue')->willReturn($value);
        return $property;
    }

    /**
     * Create an IAccount mock that returns properties from a map.
     *
     * @param array<string,string> $propertyMap Keys are IAccountManager::PROPERTY_* constants, values are property values.
     *                                          Keys not in the map will throw an exception (simulating missing property).
     */
    private function createAccountMock(array $propertyMap = []): IAccount&MockObject
    {
        $account = $this->createMock(IAccount::class);
        $account->method('getProperty')->willReturnCallback(
            function (string $propertyName) use ($propertyMap) {
                if (isset($propertyMap[$propertyName])) {
                    return $this->createPropertyMock($propertyMap[$propertyName]);
                }
                throw new \OCP\Accounts\PropertyDoesNotExistException($propertyName);
            }
        );
        return $account;
    }

    /**
     * Set up common mocks for buildUserDataArray calls.
     *
     * @param array $orgStats  Organisation stats to return
     * @param array $propMap   Account property map
     * @param array $configMap Config getUserValue map (key => value)
     */
    private function setupBuildMocks(
        array $orgStats = ['total' => 0, 'active' => null, 'results' => []],
        array $propMap = [],
        array $configMap = []
    ): void {
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn($orgStats);

        $account = $this->createAccountMock($propMap);
        $this->accountManager->method('getAccount')->willReturn($account);

        if ($configMap !== []) {
            $this->config->method('getUserValue')->willReturnCallback(
                function (string $userId, string $app, string $key, string $default) use ($configMap) {
                    return $configMap[$key] ?? $default;
                }
            );
        } else {
            $this->config->method('getUserValue')->willReturn('');
        }
    }

    // =====================================================================
    // getCurrentUser
    // =====================================================================

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

    // =====================================================================
    // getCustomNameFields
    // =====================================================================

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

    public function testGetCustomNameFieldsPartialValues(): void
    {
        $user = $this->createUserMock();
        $this->config->method('getUserValue')->willReturnCallback(
            function (string $userId, string $app, string $key, string $default) {
                if ($key === 'firstName') {
                    return 'Alice';
                }
                return '';
            }
        );

        $result = $this->service->getCustomNameFields($user);

        $this->assertSame('Alice', $result['firstName']);
        $this->assertNull($result['lastName']);
        $this->assertNull($result['middleName']);
    }

    // =====================================================================
    // setCustomNameFields
    // =====================================================================

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

    public function testSetCustomNameFieldsCastsToString(): void
    {
        $user = $this->createUserMock();

        $captured = [];
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->willReturnCallback(function ($uid, $app, $key, $value) use (&$captured) {
                $captured = ['key' => $key, 'value' => $value];
            });

        $this->service->setCustomNameFields($user, ['firstName' => 123]);

        $this->assertSame('firstName', $captured['key']);
        $this->assertSame('123', $captured['value']);
    }

    public function testSetCustomNameFieldsEmptyArrayDoesNothing(): void
    {
        $user = $this->createUserMock();
        $this->config->expects($this->never())->method('setUserValue');

        $this->service->setCustomNameFields($user, []);
    }

    // =====================================================================
    // buildUserDataArray
    // =====================================================================

    public function testBuildUserDataArrayReturnsBasicUserInfo(): void
    {
        $user = $this->createUserMock();
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getGID')->willReturn('admin');

        $this->groupManager->method('getUserGroups')->willReturn([$group]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn([
                'total' => 1,
                'active' => ['name' => 'Test Org', 'uuid' => 'org-123'],
                'results' => [['name' => 'Test Org', 'uuid' => 'org-123']],
            ]);

        $account = $this->createAccountMock();
        $this->accountManager->method('getAccount')->willReturn($account);
        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->buildUserDataArray($user);

        $this->assertSame('testuser', $result['uid']);
        $this->assertSame('Test User', $result['displayName']);
        $this->assertSame('test@example.com', $result['email']);
        $this->assertTrue($result['enabled']);
        $this->assertContains('admin', $result['groups']);
        $this->assertIsArray($result['quota']);
        $this->assertIsArray($result['organisations']);
        $this->assertSame(1, $result['organisations']['total']);
    }

    public function testBuildUserDataArrayHandlesOrganisationException(): void
    {
        $user = $this->createUserMock();
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willThrowException(new \Exception('DB error'));

        $account = $this->createAccountMock();
        $this->accountManager->method('getAccount')->willReturn($account);
        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->buildUserDataArray($user);

        $this->assertSame('testuser', $result['uid']);
        $this->assertSame(0, $result['organisations']['total']);
        $this->assertNull($result['organisations']['active']);
        $this->assertEmpty($result['organisations']['all']);
        $this->assertTrue($result['organisations']['available']);
    }

    public function testBuildUserDataArrayCachesOrgStats(): void
    {
        $user = $this->createUserMock();
        $this->groupManager->method('getUserGroups')->willReturn([]);

        // Should only be called once even when buildUserDataArray is called twice
        $this->organisationService->expects($this->once())
            ->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);

        $account = $this->createAccountMock();
        $this->accountManager->method('getAccount')->willReturn($account);
        $this->config->method('getUserValue')->willReturn('');

        $this->service->buildUserDataArray($user);
        $this->service->buildUserDataArray($user);
    }

    public function testBuildUserDataArrayBackendCapabilities(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks();

        $result = $this->service->buildUserDataArray($user);

        $this->assertArrayHasKey('backendCapabilities', $result);
        $this->assertArrayHasKey('displayName', $result['backendCapabilities']);
        $this->assertArrayHasKey('email', $result['backendCapabilities']);
        $this->assertArrayHasKey('password', $result['backendCapabilities']);
        $this->assertArrayHasKey('avatar', $result['backendCapabilities']);
    }

    public function testBuildUserDataArrayNameFields(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks();

        $result = $this->service->buildUserDataArray($user);

        $this->assertArrayHasKey('firstName', $result);
        $this->assertArrayHasKey('lastName', $result);
        $this->assertArrayHasKey('middleName', $result);
    }

    public function testBuildUserDataArrayWithAccountProperties(): void
    {
        $user = $this->createUserMock();

        $propMap = [
            IAccountManager::PROPERTY_PHONE => '+31612345678',
            IAccountManager::PROPERTY_ADDRESS => '123 Main St',
            IAccountManager::PROPERTY_WEBSITE => 'https://example.com',
            IAccountManager::PROPERTY_TWITTER => '@testuser',
            IAccountManager::PROPERTY_FEDIVERSE => '@test@mastodon.social',
            IAccountManager::PROPERTY_ORGANISATION => 'Test Corp',
            IAccountManager::PROPERTY_ROLE => 'Developer',
            IAccountManager::PROPERTY_HEADLINE => 'A headline',
            IAccountManager::PROPERTY_BIOGRAPHY => 'A bio',
        ];

        $this->setupBuildMocks(
            orgStats: ['total' => 0, 'active' => null, 'results' => []],
            propMap: $propMap
        );

        $result = $this->service->buildUserDataArray($user);

        $this->assertSame('+31612345678', $result['phone']);
        $this->assertSame('123 Main St', $result['address']);
        $this->assertSame('https://example.com', $result['website']);
        $this->assertSame('@testuser', $result['twitter']);
        $this->assertSame('@test@mastodon.social', $result['fediverse']);
        $this->assertSame('Test Corp', $result['organisation']);
        $this->assertSame('Developer', $result['role']);
        $this->assertSame('A headline', $result['headline']);
        $this->assertSame('A bio', $result['biography']);
    }

    public function testBuildUserDataArraySkipsEmptyAccountProperties(): void
    {
        $user = $this->createUserMock();

        // Property with empty value should not be included
        $emptyProperty = $this->createMock(IAccountProperty::class);
        $emptyProperty->method('getValue')->willReturn('');

        $account = $this->createMock(IAccount::class);
        $account->method('getProperty')->willReturnCallback(
            function (string $propertyName) use ($emptyProperty) {
                if ($propertyName === IAccountManager::PROPERTY_PHONE) {
                    return $emptyProperty;
                }
                throw new \OCP\Accounts\PropertyDoesNotExistException($propertyName);
            }
        );
        $this->accountManager->method('getAccount')->willReturn($account);

        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);
        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->buildUserDataArray($user);

        $this->assertArrayNotHasKey('phone', $result);
    }

    public function testBuildUserDataArrayOrganisationTransformAddsNaam(): void
    {
        $user = $this->createUserMock();

        $orgStats = [
            'total' => 2,
            'active' => ['name' => 'Gemeente Amsterdam', 'uuid' => 'org-1', 'id' => 1, 'slug' => 'amsterdam'],
            'results' => [
                ['name' => 'Gemeente Amsterdam', 'uuid' => 'org-1'],
                ['name' => 'Gemeente Utrecht', 'uuid' => 'org-2'],
            ],
        ];

        $this->setupBuildMocks(orgStats: $orgStats);

        $result = $this->service->buildUserDataArray($user);

        // Active org should have 'naam' mirroring 'name'
        $this->assertSame('Gemeente Amsterdam', $result['organisations']['active']['naam']);
        $this->assertSame('Gemeente Amsterdam', $result['organisations']['active']['name']);

        // All orgs should also have 'naam'
        $this->assertSame('Gemeente Amsterdam', $result['organisations']['all'][0]['naam']);
        $this->assertSame('Gemeente Utrecht', $result['organisations']['all'][1]['naam']);
        $this->assertSame(2, $result['organisations']['total']);
        $this->assertTrue($result['organisations']['available']);
    }

    public function testBuildUserDataArrayOrganisationNullActive(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks(orgStats: ['total' => 0, 'active' => null, 'results' => []]);

        $result = $this->service->buildUserDataArray($user);

        $this->assertNull($result['organisations']['active']);
        $this->assertEmpty($result['organisations']['all']);
    }

    public function testBuildUserDataArrayFunctieFromRole(): void
    {
        $user = $this->createUserMock();

        $propMap = [
            IAccountManager::PROPERTY_ROLE => 'Ontwikkelaar',
        ];

        $this->setupBuildMocks(propMap: $propMap);

        $result = $this->service->buildUserDataArray($user);

        // 'functie' should be set from 'role' when not separately provided
        $this->assertSame('Ontwikkelaar', $result['functie']);
    }

    public function testBuildUserDataArrayFunctieFallbackFromConfig(): void
    {
        $user = $this->createUserMock();

        // No role in AccountManager, but functie in user config
        $this->setupBuildMocks(configMap: ['functie' => 'Manager']);

        $result = $this->service->buildUserDataArray($user);

        // 'role' comes from config fallback, and 'functie' mirrors it
        $this->assertSame('Manager', $result['role']);
        $this->assertSame('Manager', $result['functie']);
    }

    public function testBuildUserDataArrayCustomNameFieldsFromConfig(): void
    {
        $user = $this->createUserMock();

        $this->setupBuildMocks(configMap: [
            'firstName' => 'Jan',
            'lastName' => 'de Vries',
            'middleName' => 'van',
        ]);

        $result = $this->service->buildUserDataArray($user);

        $this->assertSame('Jan', $result['firstName']);
        $this->assertSame('de Vries', $result['lastName']);
        $this->assertSame('van', $result['middleName']);
    }

    public function testBuildUserDataArrayOrganisationFromConfig(): void
    {
        $user = $this->createUserMock();

        $this->setupBuildMocks(configMap: ['organisation' => 'org-uuid-123']);

        $result = $this->service->buildUserDataArray($user);

        $this->assertSame('org-uuid-123', $result['organisation']);
    }

    public function testBuildUserDataArrayAccountManagerExceptionFallsBackToConfig(): void
    {
        $user = $this->createUserMock();

        // AccountManager throws exception
        $this->accountManager->method('getAccount')
            ->willThrowException(new \Exception('AccountManager unavailable'));

        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);

        // Fallback: phone, website, twitter from config
        $this->config->method('getUserValue')->willReturnCallback(
            function (string $userId, string $app, string $key, string $default) {
                $values = [
                    'phone' => '+31600000000',
                    'website' => 'https://fallback.nl',
                    'twitter' => '@fallback',
                ];
                if ($app === 'settings' && isset($values[$key])) {
                    return $values[$key];
                }
                return $default;
            }
        );

        $result = $this->service->buildUserDataArray($user);

        $this->assertSame('+31600000000', $result['phone']);
        $this->assertSame('https://fallback.nl', $result['website']);
        $this->assertSame('@fallback', $result['twitter']);
    }

    public function testBuildUserDataArrayMultipleGroups(): void
    {
        $user = $this->createUserMock();

        $group1 = $this->createMock(\OCP\IGroup::class);
        $group1->method('getGID')->willReturn('admin');
        $group2 = $this->createMock(\OCP\IGroup::class);
        $group2->method('getGID')->willReturn('users');

        $this->groupManager->method('getUserGroups')->willReturn([$group1, $group2]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);
        $account = $this->createAccountMock();
        $this->accountManager->method('getAccount')->willReturn($account);
        $this->config->method('getUserValue')->willReturn('');

        $result = $this->service->buildUserDataArray($user);

        $this->assertCount(2, $result['groups']);
        $this->assertContains('admin', $result['groups']);
        $this->assertContains('users', $result['groups']);
    }

    public function testBuildUserDataArrayQuotaDefaults(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks();

        $result = $this->service->buildUserDataArray($user);

        $this->assertIsArray($result['quota']);
        $this->assertArrayHasKey('free', $result['quota']);
        $this->assertArrayHasKey('used', $result['quota']);
        $this->assertArrayHasKey('total', $result['quota']);
        $this->assertArrayHasKey('relative', $result['quota']);
    }

    public function testBuildUserDataArrayDefaultSubadminEmpty(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks();

        $result = $this->service->buildUserDataArray($user);

        $this->assertSame([], $result['subadmin']);
    }

    // =====================================================================
    // updateUserProperties
    // =====================================================================

    public function testUpdateUserPropertiesBasicUpdate(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks();

        $result = $this->service->updateUserProperties($user, [
            'displayName' => 'New Name',
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['organisation_updated']);
    }

    public function testUpdateUserPropertiesWithOrganisationSwitch(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks();
        $this->organisationService->method('setActiveOrganisation')
            ->willReturn(true);

        $result = $this->service->updateUserProperties($user, [
            'activeOrganisation' => 'org-456',
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['organisation_updated']);
        $this->assertSame('Active organization updated successfully', $result['organisation_message']);
    }

    public function testUpdateUserPropertiesWithFailedOrganisationSwitch(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks();
        $this->organisationService->method('setActiveOrganisation')
            ->willReturn(false);

        $result = $this->service->updateUserProperties($user, [
            'activeOrganisation' => 'org-invalid',
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['organisation_updated']);
        $this->assertSame('Failed to update active organization', $result['organisation_message']);
    }

    public function testUpdateUserPropertiesWithNameFields(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks();

        $this->config->expects($this->atLeastOnce())
            ->method('setUserValue');

        $result = $this->service->updateUserProperties($user, [
            'firstName' => 'John',
            'lastName' => 'Doe',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testUpdateUserPropertiesNoChangesNoEvent(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks();

        // When no fields actually change, no event should be dispatched
        $this->eventDispatcher->expects($this->never())
            ->method('dispatchTyped');

        $result = $this->service->updateUserProperties($user, []);

        $this->assertTrue($result['success']);
    }

    public function testUpdateUserPropertiesDispatchesEventOnChange(): void
    {
        $user = $this->createUserMock();
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);

        // First call returns empty firstName, second call returns updated firstName
        $callCount = 0;
        $account = $this->createMock(IAccount::class);
        $account->method('getProperty')->willReturnCallback(
            function (string $propertyName) {
                throw new \OCP\Accounts\PropertyDoesNotExistException($propertyName);
            }
        );
        $this->accountManager->method('getAccount')->willReturn($account);

        // Config getUserValue will return different values on successive calls
        // to simulate the "before" and "after" states
        $getUserValueCallCount = 0;
        $this->config->method('getUserValue')->willReturnCallback(
            function (string $userId, string $app, string $key, string $default) use (&$getUserValueCallCount) {
                $getUserValueCallCount++;
                // After setUserValue is called, firstName will be 'NewFirst'
                // We simulate this by returning 'NewFirst' on later calls
                if ($key === 'firstName' && $getUserValueCallCount > 3) {
                    return 'NewFirst';
                }
                return $default;
            }
        );

        $this->config->method('setUserValue')->willReturn(null);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(UserProfileUpdatedEvent::class));

        $this->service->updateUserProperties($user, [
            'firstName' => 'NewFirst',
        ]);
    }

    public function testUpdateUserPropertiesStoresFunctieInConfig(): void
    {
        $user = $this->createUserMock();
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);

        $account = $this->createAccountMock();
        $this->accountManager->method('getAccount')->willReturn($account);

        $functieCaptured = false;
        $this->config->method('getUserValue')->willReturn('');
        $this->config->method('setUserValue')->willReturnCallback(
            function ($uid, $app, $key, $value) use (&$functieCaptured) {
                if ($key === 'functie' && $value === 'Beheerder') {
                    $functieCaptured = true;
                }
            }
        );

        $this->service->updateUserProperties($user, ['functie' => 'Beheerder']);

        $this->assertTrue($functieCaptured, 'functie should be stored in user config');
    }

    public function testUpdateUserPropertiesOrgSwitchInvalidatesCacheAndRefetches(): void
    {
        $user = $this->createUserMock();
        $this->groupManager->method('getUserGroups')->willReturn([]);

        // When org is switched, cachedOrgStats should be invalidated.
        // getUserOrganisationStats should be called at least twice:
        // once for oldData, and once or more for newData after cache invalidation
        $this->organisationService->expects($this->atLeast(2))
            ->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);

        $this->organisationService->method('setActiveOrganisation')->willReturn(true);

        $account = $this->createAccountMock();
        $this->accountManager->method('getAccount')->willReturn($account);
        $this->config->method('getUserValue')->willReturn('');

        $this->service->updateUserProperties($user, [
            'activeOrganisation' => 'org-new',
        ]);
    }

    public function testUpdateUserPropertiesHandlesProfileUpdateException(): void
    {
        $user = $this->createUserMock();
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);

        // First call to getAccount works (for buildUserDataArray), then throws on update
        $account = $this->createAccountMock();
        $callCount = 0;
        $this->accountManager->method('getAccount')->willReturnCallback(
            function () use ($account, &$callCount) {
                $callCount++;
                // Let the first few calls work (buildUserDataArray reads),
                // then throw for updateProfileProperties
                if ($callCount > 2) {
                    throw new \Exception('Account update failed');
                }
                return $account;
            }
        );
        $this->config->method('getUserValue')->willReturn('');

        // Should not throw, just log a warning
        $result = $this->service->updateUserProperties($user, [
            'phone' => '+31699999999',
        ]);

        $this->assertTrue($result['success']);
    }

    public function testUpdateUserPropertiesUpdatesExistingAccountProperty(): void
    {
        $user = $this->createUserMock();
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);

        // Create a property that has a current value and can be updated
        $phoneProperty = $this->createMock(IAccountProperty::class);
        $phoneProperty->method('getValue')->willReturn('+31600000000');
        $phoneProperty->expects($this->atLeastOnce())->method('setValue')->with('+31699999999');

        $account = $this->createMock(IAccount::class);
        $account->method('getProperty')->willReturnCallback(
            function (string $propertyName) use ($phoneProperty) {
                if ($propertyName === IAccountManager::PROPERTY_PHONE) {
                    return $phoneProperty;
                }
                throw new \OCP\Accounts\PropertyDoesNotExistException($propertyName);
            }
        );
        $this->accountManager->method('getAccount')->willReturn($account);
        $this->accountManager->expects($this->atLeastOnce())->method('updateAccount');

        $this->config->method('getUserValue')->willReturn('');

        $this->service->updateUserProperties($user, [
            'phone' => '+31699999999',
        ]);
    }

    public function testUpdateUserPropertiesDoesNotUpdateUnchangedProperty(): void
    {
        $user = $this->createUserMock();
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);

        // Property already has the same value
        $phoneProperty = $this->createMock(IAccountProperty::class);
        $phoneProperty->method('getValue')->willReturn('+31600000000');
        $phoneProperty->expects($this->never())->method('setValue');

        $account = $this->createMock(IAccount::class);
        $account->method('getProperty')->willReturnCallback(
            function (string $propertyName) use ($phoneProperty) {
                if ($propertyName === IAccountManager::PROPERTY_PHONE) {
                    return $phoneProperty;
                }
                throw new \OCP\Accounts\PropertyDoesNotExistException($propertyName);
            }
        );
        $this->accountManager->method('getAccount')->willReturn($account);
        // updateAccount should NOT be called since nothing changed
        $this->accountManager->expects($this->never())->method('updateAccount');

        $this->config->method('getUserValue')->willReturn('');

        $this->service->updateUserProperties($user, [
            'phone' => '+31600000000',
        ]);
    }

    public function testUpdateUserPropertiesResultStructure(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks();

        $result = $this->service->updateUserProperties($user, []);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('organisation_updated', $result);
        $this->assertSame('User properties updated successfully', $result['message']);
    }

    public function testUpdateUserPropertiesWithNonStringActiveOrganisation(): void
    {
        $user = $this->createUserMock();
        $this->setupBuildMocks();

        // activeOrganisation is not a string, should be ignored
        $this->organisationService->expects($this->never())
            ->method('setActiveOrganisation');

        $result = $this->service->updateUserProperties($user, [
            'activeOrganisation' => 123,
        ]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['organisation_updated']);
    }

    public function testUpdateUserPropertiesWithMultipleProfileFields(): void
    {
        $user = $this->createUserMock();
        $this->groupManager->method('getUserGroups')->willReturn([]);
        $this->organisationService->method('getUserOrganisationStats')
            ->willReturn(['total' => 0, 'active' => null, 'results' => []]);

        // Track which properties had setValue called
        $updatedProperties = [];
        $account = $this->createMock(IAccount::class);
        $account->method('getProperty')->willReturnCallback(
            function (string $propertyName) use (&$updatedProperties) {
                $property = $this->createMock(IAccountProperty::class);
                $property->method('getValue')->willReturn('old-value');
                $property->method('setValue')->willReturnCallback(
                    function (string $value) use ($propertyName, &$updatedProperties) {
                        $updatedProperties[$propertyName] = $value;
                        return $this->createMock(IAccountProperty::class);
                    }
                );
                return $property;
            }
        );
        $this->accountManager->method('getAccount')->willReturn($account);
        $this->config->method('getUserValue')->willReturn('');

        $this->service->updateUserProperties($user, [
            'phone' => '+31600000001',
            'website' => 'https://new.example.com',
            'biography' => 'Updated bio',
            'firstName' => 'Updated',
        ]);

        $this->assertArrayHasKey(IAccountManager::PROPERTY_PHONE, $updatedProperties);
        $this->assertArrayHasKey(IAccountManager::PROPERTY_WEBSITE, $updatedProperties);
        $this->assertArrayHasKey(IAccountManager::PROPERTY_BIOGRAPHY, $updatedProperties);
    }
}
