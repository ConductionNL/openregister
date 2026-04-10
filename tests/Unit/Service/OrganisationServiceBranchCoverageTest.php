<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Organisation;
use OCP\IUserSession;
use OCP\ISession;
use OCP\IConfig;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUser;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Branch coverage tests for OrganisationService — targets uncovered branches in
 * getOrganisationSettingsOnly, getDefaultOrganisationUuid, hasAccessToOrganisation,
 * getUserOrganisationStats, clearCache, leaveOrganisation, createOrganisation.
 */
class OrganisationServiceBranchCoverageTest extends TestCase
{
    private OrganisationService $service;
    private OrganisationMapper&MockObject $organisationMapper;
    private IUserSession&MockObject $userSession;
    private ISession&MockObject $session;
    private IConfig&MockObject $config;
    private IAppConfig&MockObject $appConfig;
    private IGroupManager&MockObject $groupManager;
    private IUserManager&MockObject $userManager;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->config = $this->createMock(IConfig::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Prevent ensureDefaultOrganisation from creating orgs
        $this->config->method('getUserValue')->willReturn('');
        $this->appConfig->method('getValueString')->willReturn('default-org-uuid');

        $defaultOrg = new Organisation();
        $defaultOrg->setUuid('default-org-uuid');
        $defaultOrg->setName('Default Organisation');
        $defaultOrg->setUsers([]);

        $this->organisationMapper->method('findByUuid')
            ->willReturn($defaultOrg);

        // Clear static caches via reflection
        $ref = new \ReflectionClass(OrganisationService::class);
        $prop = $ref->getProperty('defaultOrgCache');
        $prop->setValue(null, null);
        $propTs = $ref->getProperty('defaultOrgCacheTs');
        $propTs->setValue(null, null);
        $propUserOrgs = $ref->getProperty('userOrgsCache');
        $propUserOrgs->setValue(null, []);

        $this->service = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->config,
            $this->appConfig,
            $this->groupManager,
            $this->userManager,
            $this->logger
        );
    }

    // =========================================================================
    // getOrganisationSettingsOnly
    // =========================================================================

    public function testGetOrganisationSettingsOnlyReturnsDefaults(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) {
                if ($key === 'organisation') {
                    return '';
                }
                return $default;
            });

        $service = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->config,
            $appConfig,
            $this->groupManager,
            $this->userManager,
            $this->logger
        );

        $result = $service->getOrganisationSettingsOnly();

        $this->assertNull($result['organisation']['default_organisation']);
        $this->assertTrue($result['organisation']['auto_create_default_organisation']);
    }

    public function testGetOrganisationSettingsOnlyWithStoredConfig(): void
    {
        $storedConfig = json_encode([
            'default_organisation' => 'org-uuid-123',
            'auto_create_default_organisation' => false,
        ]);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')
            ->willReturnCallback(function ($app, $key, $default) use ($storedConfig) {
                if ($key === 'organisation') {
                    return $storedConfig;
                }
                return $default;
            });

        $service = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->config,
            $appConfig,
            $this->groupManager,
            $this->userManager,
            $this->logger
        );

        $result = $service->getOrganisationSettingsOnly();

        $this->assertSame('org-uuid-123', $result['organisation']['default_organisation']);
        $this->assertFalse($result['organisation']['auto_create_default_organisation']);
    }

    // =========================================================================
    // hasAccessToOrganisation
    // =========================================================================

    public function testHasAccessToOrganisationNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->service->hasAccessToOrganisation('org-uuid');
        $this->assertFalse($result);
    }

    public function testHasAccessToOrganisationAdminUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);

        $org = new Organisation();
        $org->setUuid('org-uuid');
        $org->setUsers([]);

        $this->organisationMapper->method('findByUuid')
            ->with('org-uuid')
            ->willReturn($org);

        $this->groupManager->method('isAdmin')
            ->with('admin')
            ->willReturn(true);

        $result = $this->service->hasAccessToOrganisation('org-uuid');
        $this->assertTrue($result);
    }

    // =========================================================================
    // getUserOrganisationStats
    // =========================================================================

    public function testGetUserOrganisationStatsNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->service->getUserOrganisationStats();

        $this->assertSame(0, $result['total']);
        $this->assertNull($result['active']);
        $this->assertSame([], $result['results']);
    }

    // =========================================================================
    // clearCache
    // =========================================================================

    public function testClearCacheNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->service->clearCache();
        $this->assertFalse($result);
    }

    public function testClearCacheWithPersistent(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('user1', 'openregister', 'active_organisation');

        $result = $this->service->clearCache(true);
        $this->assertTrue($result);
    }

    public function testClearCacheWithoutPersistent(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);

        $result = $this->service->clearCache(false);
        $this->assertTrue($result);
    }

    // =========================================================================
    // clearDefaultOrganisationCache
    // =========================================================================

    public function testClearDefaultOrganisationCache(): void
    {
        $this->service->clearDefaultOrganisationCache();
        $this->assertTrue(true);
    }

    // =========================================================================
    // getUserOrganisations — no user
    // =========================================================================

    public function testGetUserOrganisationsNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->service->getUserOrganisations();
        $this->assertSame([], $result);
    }

    // =========================================================================
    // getActiveOrganisation — no user
    // =========================================================================

    public function testGetActiveOrganisationNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $result = $this->service->getActiveOrganisation();
        $this->assertNull($result);
    }

    // =========================================================================
    // setActiveOrganisation — no user
    // =========================================================================

    public function testSetActiveOrganisationNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No user logged in');
        $this->service->setActiveOrganisation('org-uuid');
    }

    // =========================================================================
    // joinOrganisation — no user
    // =========================================================================

    public function testJoinOrganisationNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No user logged in');
        $this->service->joinOrganisation('org-uuid');
    }

    // =========================================================================
    // leaveOrganisation — no user
    // =========================================================================

    public function testLeaveOrganisationNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No user logged in');
        $this->service->leaveOrganisation('org-uuid');
    }

    // =========================================================================
    // createOrganisation — invalid UUID
    // =========================================================================

    public function testCreateOrganisationWithInvalidUuid(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('user1');
        $this->userSession->method('getUser')->willReturn($user);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid UUID format');
        $this->service->createOrganisation('Test Org', '', true, 'not-a-valid-uuid');
    }
}
