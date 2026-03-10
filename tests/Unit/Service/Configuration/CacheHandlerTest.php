<?php

declare(strict_types=1);

/**
 * Configuration CacheHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Service\Configuration\CacheHandler;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Db\Organisation;
use OCP\ISession;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for Configuration CacheHandler
 *
 * Tests session-based configuration caching for active organisation.
 */
class CacheHandlerTest extends TestCase
{
    /** @var CacheHandler */
    private CacheHandler $handler;

    /** @var ISession&MockObject */
    private ISession $session;

    /** @var ConfigurationMapper&MockObject */
    private ConfigurationMapper $configurationMapper;

    /** @var OrganisationService&MockObject */
    private OrganisationService $organisationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = $this->createMock(ISession::class);
        $this->configurationMapper = $this->createMock(ConfigurationMapper::class);
        $this->organisationService = $this->createMock(OrganisationService::class);

        $this->handler = new CacheHandler(
            $this->session,
            $this->configurationMapper,
            $this->organisationService
        );
    }

    /**
     * Test returns empty array when no active organisation
     */
    public function testGetConfigurationsReturnsEmptyWhenNoOrganisation(): void
    {
        $this->organisationService->method('getActiveOrganisation')
            ->willReturn(null);

        $result = $this->handler->getConfigurationsForActiveOrganisation();

        $this->assertSame([], $result);
    }

    /**
     * Test returns cached configurations when available in session
     */
    public function testGetConfigurationsReturnsCachedData(): void
    {
        $org = new Organisation();
        $org->setUuid('org-uuid-123');
        $this->organisationService->method('getActiveOrganisation')
            ->willReturn($org);

        $cachedConfigs = [new Configuration()];
        $this->session->method('get')
            ->with('openregister_configurations_org-uuid-123')
            ->willReturn(serialize($cachedConfigs));

        $result = $this->handler->getConfigurationsForActiveOrganisation();

        $this->assertCount(1, $result);
    }

    /**
     * Test fetches from database and caches when not in session
     */
    public function testGetConfigurationsFetchesFromDatabaseOnCacheMiss(): void
    {
        $org = new Organisation();
        $org->setUuid('org-uuid-456');
        $this->organisationService->method('getActiveOrganisation')
            ->willReturn($org);

        // No cached data.
        $this->session->method('get')
            ->willReturn(null);

        // Database returns configurations.
        $configs = [new Configuration(), new Configuration()];
        $this->configurationMapper->method('findAll')
            ->willReturn($configs);

        // Expect session set.
        $this->session->expects($this->once())
            ->method('set')
            ->with(
                'openregister_configurations_org-uuid-456',
                serialize($configs)
            );

        $result = $this->handler->getConfigurationsForActiveOrganisation();

        $this->assertCount(2, $result);
    }
}
