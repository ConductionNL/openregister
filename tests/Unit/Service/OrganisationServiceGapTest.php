<?php

/**
 * OrganisationService Gap Coverage Tests
 *
 * Tests for uncovered methods in OrganisationService including
 * organisation settings, slug generation, cache management, and access control.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Gap coverage tests for OrganisationService
 */
class OrganisationServiceGapTest extends TestCase
{
    /** @var OrganisationService */
    private OrganisationService $service;

    /** @var MockObject|OrganisationMapper */
    private $organisationMapper;

    /** @var MockObject|IUserSession */
    private $userSession;

    /** @var MockObject|ISession */
    private $session;

    /** @var MockObject|IConfig */
    private $config;

    /** @var MockObject|IAppConfig */
    private $appConfig;

    /** @var MockObject|IGroupManager */
    private $groupManager;

    /** @var MockObject|IUserManager */
    private $userManager;

    /** @var MockObject|LoggerInterface */
    private $logger;

    /** @var MockObject|SettingsService */
    private $settingsService;

    /**
     * Set up test dependencies
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession        = $this->createMock(IUserSession::class);
        $this->session            = $this->createMock(ISession::class);
        $this->config             = $this->createMock(IConfig::class);
        $this->appConfig          = $this->createMock(IAppConfig::class);
        $this->groupManager       = $this->createMock(IGroupManager::class);
        $this->userManager        = $this->createMock(IUserManager::class);
        $this->logger             = $this->createMock(LoggerInterface::class);
        $this->settingsService    = $this->createMock(SettingsService::class);

        // Clear static caches between tests.
        $reflection = new \ReflectionClass(OrganisationService::class);
        $defaultOrgCache = $reflection->getProperty('defaultOrgCache');
        $defaultOrgCache->setValue(null, null);
        $defaultOrgCacheTs = $reflection->getProperty('defaultOrgCacheTs');
        $defaultOrgCacheTs->setValue(null, null);
        $userOrgsCache = $reflection->getProperty('userOrgsCache');
        $userOrgsCache->setValue(null, []);

        $this->service = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->config,
            $this->appConfig,
            $this->groupManager,
            $this->userManager,
            $this->logger,
            $this->settingsService
        );
    }

    // =============================================
    // getOrganisationSettingsOnly tests
    // =============================================

    /**
     * Test getOrganisationSettingsOnly returns defaults when empty
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnlyReturnsDefaults(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn('');

        $result = $this->service->getOrganisationSettingsOnly();

        $this->assertArrayHasKey('organisation', $result);
        $this->assertNull($result['organisation']['default_organisation']);
        $this->assertTrue($result['organisation']['auto_create_default_organisation']);
    }

    /**
     * Test getOrganisationSettingsOnly returns stored values
     *
     * @return void
     */
    public function testGetOrganisationSettingsOnlyReturnsStoredValues(): void
    {
        $stored = json_encode([
            'default_organisation'             => 'uuid-123',
            'auto_create_default_organisation' => false,
        ]);

        $this->appConfig
            ->method('getValueString')
            ->willReturn($stored);

        $result = $this->service->getOrganisationSettingsOnly();

        $this->assertEquals('uuid-123', $result['organisation']['default_organisation']);
        $this->assertFalse($result['organisation']['auto_create_default_organisation']);
    }

    // =============================================
    // getDefaultOrganisationUuid tests
    // =============================================

    /**
     * Test getDefaultOrganisationUuid returns direct config value
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuidReturnsDirectConfig(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                if ($key === 'defaultOrganisation') {
                    return 'direct-uuid-456';
                }
                return $default;
            });

        $result = $this->service->getDefaultOrganisationUuid();
        $this->assertEquals('direct-uuid-456', $result);
    }

    /**
     * Test getDefaultOrganisationUuid returns null when not set
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuidReturnsNullWhenNotSet(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willReturn('');

        $result = $this->service->getDefaultOrganisationUuid();
        $this->assertNull($result);
    }

    /**
     * Test getDefaultOrganisationUuid handles exception
     *
     * @return void
     */
    public function testGetDefaultOrganisationUuidHandlesException(): void
    {
        $this->appConfig
            ->method('getValueString')
            ->willThrowException(new \Exception('Config error'));

        $result = $this->service->getDefaultOrganisationUuid();
        $this->assertNull($result);
    }

    // =============================================
    // getUserOrganisations tests
    // =============================================

    /**
     * Test getUserOrganisations returns empty when no user
     *
     * @return void
     */
    public function testGetUserOrganisationsReturnsEmptyWhenNoUser(): void
    {
        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $result = $this->service->getUserOrganisations();
        $this->assertEmpty($result);
    }

    // =============================================
    // getActiveOrganisation tests
    // =============================================

    /**
     * Test getActiveOrganisation returns null when no user
     *
     * @return void
     */
    public function testGetActiveOrganisationReturnsNullWhenNoUser(): void
    {
        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $result = $this->service->getActiveOrganisation();
        $this->assertNull($result);
    }

    // =============================================
    // setActiveOrganisation tests
    // =============================================

    /**
     * Test setActiveOrganisation throws when no user
     *
     * @return void
     */
    public function testSetActiveOrganisationThrowsWhenNoUser(): void
    {
        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No user logged in');
        $this->service->setActiveOrganisation('some-uuid');
    }

    /**
     * Test setActiveOrganisation throws when organisation not found
     *
     * @return void
     */
    public function testSetActiveOrganisationThrowsWhenOrgNotFound(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $this->organisationMapper
            ->method('findByUuid')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Organisation not found');
        $this->service->setActiveOrganisation('nonexistent-uuid');
    }

    /**
     * Test setActiveOrganisation throws when user not member
     *
     * @return void
     */
    public function testSetActiveOrganisationThrowsWhenUserNotMember(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $organisation = $this->createMock(Organisation::class);
        $organisation->method('hasUser')->willReturn(false);

        $this->organisationMapper
            ->method('findByUuid')
            ->willReturn($organisation);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('User does not belong to this organisation');
        $this->service->setActiveOrganisation('some-uuid');
    }

    // =============================================
    // joinOrganisation tests
    // =============================================

    /**
     * Test joinOrganisation throws when no user
     *
     * @return void
     */
    public function testJoinOrganisationThrowsWhenNoUser(): void
    {
        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No user logged in');
        $this->service->joinOrganisation('some-uuid');
    }

    /**
     * Test joinOrganisation throws when target user not found
     *
     * @return void
     */
    public function testJoinOrganisationThrowsWhenTargetUserNotFound(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $this->userManager
            ->method('get')
            ->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Target user not found');
        $this->service->joinOrganisation('some-uuid', 'nonexistent-user');
    }

    // =============================================
    // leaveOrganisation tests
    // =============================================

    /**
     * Test leaveOrganisation throws when no user
     *
     * @return void
     */
    public function testLeaveOrganisationThrowsWhenNoUser(): void
    {
        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No user logged in');
        $this->service->leaveOrganisation('some-uuid');
    }

    // =============================================
    // hasAccessToOrganisation tests
    // =============================================

    /**
     * Test hasAccessToOrganisation returns false when org not found
     *
     * @return void
     */
    public function testHasAccessToOrganisationReturnsFalseWhenNotFound(): void
    {
        $this->organisationMapper
            ->method('findByUuid')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->service->hasAccessToOrganisation('bad-uuid');
        $this->assertFalse($result);
    }

    /**
     * Test hasAccessToOrganisation returns false when no user
     *
     * @return void
     */
    public function testHasAccessToOrganisationReturnsFalseWhenNoUser(): void
    {
        $org = $this->createMock(Organisation::class);

        $this->organisationMapper
            ->method('findByUuid')
            ->willReturn($org);

        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $result = $this->service->hasAccessToOrganisation('some-uuid');
        $this->assertFalse($result);
    }

    /**
     * Test hasAccessToOrganisation returns true for admin
     *
     * @return void
     */
    public function testHasAccessToOrganisationReturnsTrueForAdmin(): void
    {
        $org = $this->createMock(Organisation::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $this->organisationMapper
            ->method('findByUuid')
            ->willReturn($org);

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $this->groupManager
            ->method('isAdmin')
            ->willReturn(true);

        $result = $this->service->hasAccessToOrganisation('some-uuid');
        $this->assertTrue($result);
    }

    /**
     * Test hasAccessToOrganisation checks user membership
     *
     * @return void
     */
    public function testHasAccessToOrganisationChecksUserMembership(): void
    {
        $org = $this->createMock(Organisation::class);
        $org->method('hasUser')->willReturn(true);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->organisationMapper
            ->method('findByUuid')
            ->willReturn($org);

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $this->groupManager
            ->method('isAdmin')
            ->willReturn(false);

        $result = $this->service->hasAccessToOrganisation('some-uuid');
        $this->assertTrue($result);
    }

    // =============================================
    // getUserOrganisationStats tests
    // =============================================

    /**
     * Test getUserOrganisationStats returns empty when no user
     *
     * @return void
     */
    public function testGetUserOrganisationStatsReturnsEmptyWhenNoUser(): void
    {
        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $result = $this->service->getUserOrganisationStats();
        $this->assertEquals(0, $result['total']);
        $this->assertNull($result['active']);
        $this->assertEmpty($result['results']);
    }

    // =============================================
    // clearDefaultOrganisationCache tests
    // =============================================

    /**
     * Test clearDefaultOrganisationCache clears static cache
     *
     * @return void
     */
    public function testClearDefaultOrganisationCacheClearsStaticCache(): void
    {
        // Just verify it does not throw.
        $this->service->clearDefaultOrganisationCache();

        $reflection = new \ReflectionClass(OrganisationService::class);
        $cache = $reflection->getProperty('defaultOrgCache');
        $this->assertNull($cache->getValue(null));

        $cacheTs = $reflection->getProperty('defaultOrgCacheTs');
        $this->assertNull($cacheTs->getValue(null));
    }

    // =============================================
    // clearCache tests
    // =============================================

    /**
     * Test clearCache returns false when no user
     *
     * @return void
     */
    public function testClearCacheReturnsFalseWhenNoUser(): void
    {
        $this->userSession
            ->method('getUser')
            ->willReturn(null);

        $result = $this->service->clearCache();
        $this->assertFalse($result);
    }

    /**
     * Test clearCache returns true when user exists
     *
     * @return void
     */
    public function testClearCacheReturnsTrueWhenUserExists(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $result = $this->service->clearCache();
        $this->assertTrue($result);
    }

    /**
     * Test clearCache with clearPersistent deletes user config
     *
     * @return void
     */
    public function testClearCacheWithClearPersistentDeletesUserConfig(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');

        $this->userSession
            ->method('getUser')
            ->willReturn($user);

        $this->config
            ->expects($this->once())
            ->method('deleteUserValue')
            ->with('testuser', 'openregister', 'active_organisation');

        $result = $this->service->clearCache(true);
        $this->assertTrue($result);
    }

    // =============================================
    // setDefaultOrganisationId tests
    // =============================================

    /**
     * Test setDefaultOrganisationId stores value in appConfig
     *
     * @return void
     */
    public function testSetDefaultOrganisationIdStoresValue(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('setValueString')
            ->with('openregister', 'defaultOrganisation', 'new-uuid-789');

        $this->service->setDefaultOrganisationId('new-uuid-789');
    }
}
