<?php

/**
 * UserService Coverage Tests
 *
 * Tests for uncovered branches in UserService: buildQuotaInformation edge cases,
 * getLanguageAndLocale branches, getAdditionalProfileInfo fallbacks,
 * determineChangedFields, getCustomNameFields, setCustomNameFields,
 * getAccountManagerPropertiesSelectively, and getDefaultPropertyScope.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Service\UserService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IAvatarManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\L10N\IFactory;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountProperty;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroup;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class UserServiceCoverageTest extends TestCase
{
    private UserService $userService;
    private IUserManager|MockObject $userManager;
    private IUserSession|MockObject $userSession;
    private IConfig|MockObject $config;
    private IGroupManager|MockObject $groupManager;
    private IAccountManager|MockObject $accountManager;
    private LoggerInterface|MockObject $logger;
    private OrganisationService|MockObject $organisationService;
    private IEventDispatcher|MockObject $eventDispatcher;

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

        $this->userService = new UserService(
            $this->userManager,
            $this->userSession,
            $this->config,
            $this->groupManager,
            $this->accountManager,
            $this->logger,
            $this->organisationService,
            $this->eventDispatcher,
            $this->createMock(IAvatarManager::class),
            $this->createMock(AuditTrailMapper::class),
            $this->createMock(ISecureRandom::class),
            $this->createMock(IDBConnection::class),
            $this->createMock(IFactory::class)
        );
    }

    private function invokeMethod(object $object, string $method, array $args = []): mixed
    {
        $ref = new ReflectionClass($object);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($object, $args);
    }

    /**
     * Create an IUser mock with extra non-interface methods.
     * Methods on IUser: getUID, getDisplayName, setDisplayName, getLastLogin, setPassword,
     * getBackendClassName, canChangeAvatar, canChangePassword, canChangeDisplayName,
     * isEnabled, getEMailAddress, setEMailAddress, getQuota.
     * Methods NOT on IUser (need addMethods): getLanguage, getLocale, getUsedSpace,
     * canChangeMailAddress, getEmailVerified, getAvatarScope, setLanguage, setLocale.
     */
    private function createUserMock(): IUser|MockObject
    {
        return $this->getMockBuilder(IUser::class)
            ->addMethods([
                'getLanguage',
                'getLocale',
                'getUsedSpace',
                'canChangeMailAddress',
                'getEmailVerified',
                'getAvatarScope',
                'setLanguage',
                'setLocale',
            ])
            ->getMockForAbstractClass();
    }

    // =========================================================================
    // getCurrentUser
    // =========================================================================

    public function testGetCurrentUserReturnsUser(): void
    {
        $user = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($user);

        $this->assertSame($user, $this->userService->getCurrentUser());
    }

    public function testGetCurrentUserReturnsNull(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->assertNull($this->userService->getCurrentUser());
    }

    // =========================================================================
    // buildQuotaInformation
    // =========================================================================

    public function testBuildQuotaWithNumericQuota(): void
    {
        $user = $this->createUserMock();
        $user->method('getUID')->willReturn('testuser');
        $user->method('getQuota')->willReturn('1073741824');
        $user->method('getUsedSpace')->willReturn(536870912);

        $result = $this->invokeMethod($this->userService, 'buildQuotaInformation', [$user]);

        $this->assertSame('1073741824', $result['total']);
        $this->assertSame(536870912, $result['used']);
        $this->assertEquals(50.0, $result['relative']);
    }

    public function testBuildQuotaWithNoneQuota(): void
    {
        $user = $this->createUserMock();
        $user->method('getUID')->willReturn('testuser');
        $user->method('getQuota')->willReturn('none');
        $user->method('getUsedSpace')->willReturn(0);

        $result = $this->invokeMethod($this->userService, 'buildQuotaInformation', [$user]);

        $this->assertSame('none', $result['total']);
        $this->assertSame(0, $result['relative']);
    }

    public function testBuildQuotaWithUnlimitedQuota(): void
    {
        $user = $this->createUserMock();
        $user->method('getUID')->willReturn('testuser');
        $user->method('getQuota')->willReturn('unlimited');
        $user->method('getUsedSpace')->willReturn(100);

        $result = $this->invokeMethod($this->userService, 'buildQuotaInformation', [$user]);

        $this->assertSame('unlimited', $result['total']);
        $this->assertSame(0, $result['relative']);
    }

    public function testBuildQuotaWithZeroTotalBytes(): void
    {
        $user = $this->createUserMock();
        $user->method('getUID')->willReturn('testuser');
        $user->method('getQuota')->willReturn('0');
        $user->method('getUsedSpace')->willReturn(0);

        $result = $this->invokeMethod($this->userService, 'buildQuotaInformation', [$user]);

        $this->assertSame(0, $result['relative']);
    }

    // =========================================================================
    // getLanguageAndLocale
    // =========================================================================

    public function testGetLanguageAndLocaleWithEnglish(): void
    {
        $user = $this->createUserMock();
        $user->method('getLanguage')->willReturn('en');
        $user->method('getLocale')->willReturn('');

        $result = $this->invokeMethod($this->userService, 'getLanguageAndLocale', [$user]);

        $this->assertSame('en', $result[0]);
        $this->assertSame('en_US', $result[1]);
    }

    public function testGetLanguageAndLocaleWithNonEnglish(): void
    {
        $user = $this->createUserMock();
        $user->method('getLanguage')->willReturn('nl');
        $user->method('getLocale')->willReturn('');

        $result = $this->invokeMethod($this->userService, 'getLanguageAndLocale', [$user]);

        $this->assertSame('nl', $result[0]);
        $this->assertSame('nl_NL', $result[1]);
    }

    public function testGetLanguageAndLocaleWithExplicitLocale(): void
    {
        $user = $this->createUserMock();
        $user->method('getLanguage')->willReturn('de');
        $user->method('getLocale')->willReturn('de_AT');

        $result = $this->invokeMethod($this->userService, 'getLanguageAndLocale', [$user]);

        $this->assertSame('de', $result[0]);
        $this->assertSame('de_AT', $result[1]);
    }

    public function testGetLanguageAndLocaleNoMethodsAvailable(): void
    {
        // Plain IUser mock without getLanguage/getLocale
        $user = $this->createMock(IUser::class);

        $result = $this->invokeMethod($this->userService, 'getLanguageAndLocale', [$user]);

        $this->assertSame('', $result[0]);
        $this->assertSame('', $result[1]);
    }

    // =========================================================================
    // getAdditionalProfileInfo — AccountManager exception fallback
    // =========================================================================

    public function testGetAdditionalProfileInfoFallbackOnException(): void
    {
        $user = $this->createUserMock();
        $user->method('getUID')->willReturn('testuser');

        $this->accountManager->method('getAccount')
            ->willThrowException(new \Exception('Account not found'));

        $this->config->method('getUserValue')->willReturnMap([
            ['testuser', 'settings', 'phone', '', '+31612345678'],
            ['testuser', 'settings', 'website', '', 'https://example.com'],
            ['testuser', 'settings', 'twitter', '', '@test'],
            ['testuser', 'core', 'firstName', '', 'John'],
            ['testuser', 'core', 'lastName', '', 'Doe'],
            ['testuser', 'core', 'middleName', '', ''],
            ['testuser', 'core', 'organisation', '', ''],
            ['testuser', 'core', 'functie', '', 'Developer'],
        ]);

        $result = $this->invokeMethod($this->userService, 'getAdditionalProfileInfo', [$user]);

        $this->assertSame('+31612345678', $result['phone']);
        $this->assertSame('https://example.com', $result['website']);
        $this->assertSame('@test', $result['twitter']);
        $this->assertSame('Developer', $result['role']);
    }

    public function testGetAdditionalProfileInfoWithOrganisation(): void
    {
        $user = $this->createUserMock();
        $user->method('getUID')->willReturn('testuser');

        $this->accountManager->method('getAccount')
            ->willThrowException(new \Exception('Not found'));

        $this->config->method('getUserValue')->willReturnMap([
            ['testuser', 'settings', 'phone', '', ''],
            ['testuser', 'settings', 'website', '', ''],
            ['testuser', 'settings', 'twitter', '', ''],
            ['testuser', 'core', 'firstName', '', ''],
            ['testuser', 'core', 'lastName', '', ''],
            ['testuser', 'core', 'middleName', '', ''],
            ['testuser', 'core', 'organisation', '', 'org-uuid-123'],
            ['testuser', 'core', 'functie', '', ''],
        ]);

        $result = $this->invokeMethod($this->userService, 'getAdditionalProfileInfo', [$user]);

        $this->assertSame('org-uuid-123', $result['organisation']);
    }

    // =========================================================================
    // getCustomNameFields
    // =========================================================================

    public function testGetCustomNameFieldsEmptyValuesReturnNull(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->config->method('getUserValue')->willReturnMap([
            ['testuser', 'core', 'firstName', '', ''],
            ['testuser', 'core', 'lastName', '', ''],
            ['testuser', 'core', 'middleName', '', ''],
        ]);

        $result = $this->userService->getCustomNameFields($user);

        $this->assertNull($result['firstName']);
        $this->assertNull($result['lastName']);
        $this->assertNull($result['middleName']);
    }

    public function testGetCustomNameFieldsWithValues(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->config->method('getUserValue')->willReturnMap([
            ['testuser', 'core', 'firstName', '', 'John'],
            ['testuser', 'core', 'lastName', '', 'Doe'],
            ['testuser', 'core', 'middleName', '', 'van'],
        ]);

        $result = $this->userService->getCustomNameFields($user);

        $this->assertSame('John', $result['firstName']);
        $this->assertSame('Doe', $result['lastName']);
        $this->assertSame('van', $result['middleName']);
    }

    // =========================================================================
    // setCustomNameFields
    // =========================================================================

    public function testSetCustomNameFieldsOnlyAllowedFields(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->config->expects($this->exactly(2))
            ->method('setUserValue');

        $this->userService->setCustomNameFields($user, [
            'firstName' => 'Jane',
            'lastName' => 'Smith',
            'invalidField' => 'ignored',
        ]);
    }

    // =========================================================================
    // getAccountManagerPropertiesSelectively
    // =========================================================================

    public function testGetAccountManagerPropertiesSelectivelyWithException(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $account = $this->createMock(IAccount::class);
        $this->accountManager->method('getAccount')->willReturn($account);

        $account->method('getProperty')->willThrowException(new \Exception('No such property'));

        $result = $this->invokeMethod($this->userService, 'getAccountManagerPropertiesSelectively', [$user]);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testGetAccountManagerPropertiesSelectivelyWithValues(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $account = $this->createMock(IAccount::class);
        $this->accountManager->method('getAccount')->willReturn($account);

        $phoneProp = $this->createMock(IAccountProperty::class);
        $phoneProp->method('getValue')->willReturn('+31612345678');

        $account->method('getProperty')->willReturnCallback(function ($name) use ($phoneProp) {
            if ($name === IAccountManager::PROPERTY_PHONE) {
                return $phoneProp;
            }
            throw new \Exception('Not found');
        });

        $result = $this->invokeMethod($this->userService, 'getAccountManagerPropertiesSelectively', [$user]);

        $this->assertSame('+31612345678', $result['phone']);
    }

    public function testGetAccountManagerPropertiesSelectivelyEmptyValue(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $account = $this->createMock(IAccount::class);
        $this->accountManager->method('getAccount')->willReturn($account);

        $phoneProp = $this->createMock(IAccountProperty::class);
        $phoneProp->method('getValue')->willReturn('');

        $account->method('getProperty')->willReturnCallback(function ($name) use ($phoneProp) {
            if ($name === IAccountManager::PROPERTY_PHONE) {
                return $phoneProp;
            }
            throw new \Exception('Not found');
        });

        $result = $this->invokeMethod($this->userService, 'getAccountManagerPropertiesSelectively', [$user]);

        $this->assertArrayNotHasKey('phone', $result);
    }

    // =========================================================================
    // getDefaultPropertyScope
    // =========================================================================

    public function testGetDefaultPropertyScopeReturnsPrivateForUnknown(): void
    {
        $result = $this->invokeMethod($this->userService, 'getDefaultPropertyScope', ['unknown_property']);
        $this->assertSame(IAccountManager::SCOPE_PRIVATE, $result);
    }

    public function testGetDefaultPropertyScopeReturnsPublishedForWebsite(): void
    {
        $result = $this->invokeMethod($this->userService, 'getDefaultPropertyScope', [IAccountManager::PROPERTY_WEBSITE]);
        $this->assertSame(IAccountManager::SCOPE_PUBLISHED, $result);
    }

    public function testGetDefaultPropertyScopeReturnsLocalForOrg(): void
    {
        $result = $this->invokeMethod($this->userService, 'getDefaultPropertyScope', [IAccountManager::PROPERTY_ORGANISATION]);
        $this->assertSame(IAccountManager::SCOPE_LOCAL, $result);
    }

    public function testGetDefaultPropertyScopeReturnsPrivateForPhone(): void
    {
        $result = $this->invokeMethod($this->userService, 'getDefaultPropertyScope', [IAccountManager::PROPERTY_PHONE]);
        $this->assertSame(IAccountManager::SCOPE_PRIVATE, $result);
    }

    // =========================================================================
    // determineChangedFields
    // =========================================================================

    public function testDetermineChangedFieldsDetectsChanges(): void
    {
        $old = ['displayName' => 'Old Name', 'email' => 'old@test.nl', 'phone' => '123'];
        $new = ['displayName' => 'New Name', 'email' => 'old@test.nl', 'phone' => '456'];

        $result = $this->invokeMethod($this->userService, 'determineChangedFields', [$old, $new]);

        $this->assertContains('displayName', $result);
        $this->assertContains('phone', $result);
        $this->assertNotContains('email', $result);
    }

    public function testDetermineChangedFieldsHandlesMissingKeys(): void
    {
        $old = [];
        $new = ['displayName' => 'New Name'];

        $result = $this->invokeMethod($this->userService, 'determineChangedFields', [$old, $new]);

        $this->assertContains('displayName', $result);
    }

    public function testDetermineChangedFieldsNoChanges(): void
    {
        $data = ['displayName' => 'Name', 'email' => 'a@b.nl'];

        $result = $this->invokeMethod($this->userService, 'determineChangedFields', [$data, $data]);

        $this->assertEmpty($result);
    }

    public function testDetermineChangedFieldsNullToValue(): void
    {
        $old = ['firstName' => null];
        $new = ['firstName' => 'John'];

        $result = $this->invokeMethod($this->userService, 'determineChangedFields', [$old, $new]);

        $this->assertContains('firstName', $result);
    }
}
