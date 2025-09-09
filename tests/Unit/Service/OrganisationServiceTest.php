<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Organisation;
use PHPUnit\Framework\TestCase;
use OCP\IUserSession;
use OCP\IUser;
use OCP\ISession;
use OCP\IGroupManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Test class for OrganisationService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class OrganisationServiceTest extends TestCase
{
    private OrganisationService $organisationService;
    private OrganisationMapper $organisationMapper;
    private IUserSession $userSession;
    private ISession $session;
    private IConfig $config;
    private IGroupManager $groupManager;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock dependencies
        $this->organisationMapper = $this->createMock(OrganisationMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->session = $this->createMock(ISession::class);
        $this->config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Create OrganisationService instance
        $this->organisationService = new OrganisationService(
            $this->organisationMapper,
            $this->userSession,
            $this->session,
            $this->config,
            $this->groupManager,
            $this->logger
        );
    }

    /**
     * Test ensureDefaultOrganisation method
     */
    public function testEnsureDefaultOrganisation(): void
    {
        // Create mock organisation
        $organisation = $this->createMock(Organisation::class);
        $organisation->method('getId')->willReturn('1');
        $organisation->method('getName')->willReturn('Default Organisation');

        // Mock organisation mapper to return existing organisation
        $this->organisationMapper->expects($this->once())
            ->method('findDefault')
            ->willReturn($organisation);

        $result = $this->organisationService->ensureDefaultOrganisation();

        $this->assertEquals($organisation, $result);
    }

    /**
     * Test ensureDefaultOrganisation method when no default exists
     */
    public function testEnsureDefaultOrganisationWhenNoDefaultExists(): void
    {
        // Create mock organisation
        $organisation = $this->createMock(Organisation::class);
        $organisation->method('getId')->willReturn('1');
        $organisation->method('getName')->willReturn('Default Organisation');

        // Mock organisation mapper to throw exception (no default exists)
        $this->organisationMapper->expects($this->once())
            ->method('findDefault')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('No default organisation'));

        // Mock organisation mapper to create new organisation
        $this->organisationMapper->expects($this->once())
            ->method('insert')
            ->willReturn($organisation);

        $result = $this->organisationService->ensureDefaultOrganisation();

        $this->assertEquals($organisation, $result);
    }

    /**
     * Test getUserOrganisations method
     */
    public function testGetUserOrganisations(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Create mock organisations
        $organisation1 = $this->createMock(Organisation::class);
        $organisation1->method('getId')->willReturn('1');
        $organisation1->method('getName')->willReturn('Organisation 1');

        $organisation2 = $this->createMock(Organisation::class);
        $organisation2->method('getId')->willReturn('2');
        $organisation2->method('getName')->willReturn('Organisation 2');

        $organisations = [$organisation1, $organisation2];

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('findByUser')
            ->with('test-user')
            ->willReturn($organisations);

        $result = $this->organisationService->getUserOrganisations();

        $this->assertEquals($organisations, $result);
    }

    /**
     * Test getUserOrganisations method with no user session
     */
    public function testGetUserOrganisationsWithNoUserSession(): void
    {
        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $result = $this->organisationService->getUserOrganisations();

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    /**
     * Test getActiveOrganisation method
     */
    public function testGetActiveOrganisation(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Create mock organisation
        $organisation = $this->createMock(Organisation::class);
        $organisation->method('getId')->willReturn('1');
        $organisation->method('getName')->willReturn('Active Organisation');

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock config to return organisation UUID
        $this->config->expects($this->once())
            ->method('getUserValue')
            ->with('test-user', 'openregister', 'active_organisation_uuid', '')
            ->willReturn('org-uuid-123');

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with('org-uuid-123')
            ->willReturn($organisation);

        $result = $this->organisationService->getActiveOrganisation();

        $this->assertEquals($organisation, $result);
    }

    /**
     * Test getActiveOrganisation method with no active organisation
     */
    public function testGetActiveOrganisationWithNoActiveOrganisation(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock config to return empty string
        $this->config->expects($this->once())
            ->method('getUserValue')
            ->with('test-user', 'openregister', 'active_organisation_uuid', '')
            ->willReturn('');

        $result = $this->organisationService->getActiveOrganisation();

        $this->assertNull($result);
    }

    /**
     * Test setActiveOrganisation method
     */
    public function testSetActiveOrganisation(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock config to set user value
        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('test-user', 'openregister', 'active_organisation_uuid', 'org-uuid-123')
            ->willReturn(true);

        $result = $this->organisationService->setActiveOrganisation('org-uuid-123');

        $this->assertTrue($result);
    }

    /**
     * Test setActiveOrganisation method with no user session
     */
    public function testSetActiveOrganisationWithNoUserSession(): void
    {
        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $result = $this->organisationService->setActiveOrganisation('org-uuid-123');

        $this->assertFalse($result);
    }

    /**
     * Test createOrganisation method
     */
    public function testCreateOrganisation(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Create mock organisation
        $organisation = $this->createMock(Organisation::class);
        $organisation->method('getId')->willReturn('1');
        $organisation->method('getName')->willReturn('New Organisation');

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('insert')
            ->willReturn($organisation);

        $result = $this->organisationService->createOrganisation('New Organisation', 'Description');

        $this->assertEquals($organisation, $result);
    }

    /**
     * Test createOrganisation method with no user session
     */
    public function testCreateOrganisationWithNoUserSession(): void
    {
        // Mock user session to return null
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('User must be logged in to create organisation');

        $this->organisationService->createOrganisation('New Organisation', 'Description');
    }

    /**
     * Test hasAccessToOrganisation method
     */
    public function testHasAccessToOrganisation(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Create mock organisation
        $organisation = $this->createMock(Organisation::class);
        $organisation->method('getId')->willReturn('1');

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with('org-uuid-123')
            ->willReturn($organisation);

        $this->organisationMapper->expects($this->once())
            ->method('hasUserAccess')
            ->with('test-user', 'org-uuid-123')
            ->willReturn(true);

        $result = $this->organisationService->hasAccessToOrganisation('org-uuid-123');

        $this->assertTrue($result);
    }

    /**
     * Test hasAccessToOrganisation method with no access
     */
    public function testHasAccessToOrganisationWithNoAccess(): void
    {
        // Create mock user
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('test-user');

        // Create mock organisation
        $organisation = $this->createMock(Organisation::class);
        $organisation->method('getId')->willReturn('1');

        // Mock user session
        $this->userSession->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        // Mock organisation mapper
        $this->organisationMapper->expects($this->once())
            ->method('findByUuid')
            ->with('org-uuid-123')
            ->willReturn($organisation);

        $this->organisationMapper->expects($this->once())
            ->method('hasUserAccess')
            ->with('test-user', 'org-uuid-123')
            ->willReturn(false);

        $result = $this->organisationService->hasAccessToOrganisation('org-uuid-123');

        $this->assertFalse($result);
    }

    /**
     * Test clearCache method
     */
    public function testClearCache(): void
    {
        $result = $this->organisationService->clearCache();

        $this->assertTrue($result);
    }

    /**
     * Test clearCache method with persistent clear
     */
    public function testClearCacheWithPersistentClear(): void
    {
        $result = $this->organisationService->clearCache(true);

        $this->assertTrue($result);
    }
}
