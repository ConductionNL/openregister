<?php

/**
 * OpenRegister - OrganisationService system-identity helpers test
 *
 * Covers the helpers that surface the system-user identifier and reader
 * groups for openregister#1617.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests {@see OrganisationService::getSystemUserId()} and
 * {@see OrganisationService::getSystemReaderGroups()} — the two helpers
 * introduced for the openregister#1617 fix.
 *
 * The system identifier and reader-group lookup are the single source of
 * truth for both SaveObject (write-side attribution) and MagicRbacHandler
 * (read-side visibility carve-out). Tests live here so a change to either
 * helper trips a focused failure rather than a diffuse downstream surprise.
 */
class OrganisationServiceSystemIdentityTest extends TestCase
{

    /**
     * IAppConfig mock — primary collaborator for both helpers.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * System under test.
     *
     * @var OrganisationService
     */
    private OrganisationService $service;

    /**
     * Construct an OrganisationService with mocked collaborators.
     *
     * Builds the instance via reflection so this test does not need to track
     * the (long) production constructor signature — only IAppConfig is
     * exercised here.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appConfig = $this->createMock(IAppConfig::class);

        $reflection = new ReflectionClass(OrganisationService::class);
        /*
         * @var OrganisationService $service
         */
        $service = $reflection->newInstanceWithoutConstructor();

        $appConfigProp = $reflection->getProperty('appConfig');
        $appConfigProp->setAccessible(true);
        $appConfigProp->setValue($service, $this->appConfig);

        // Wire the logger - getSystemUserId/Groups don't touch it but other
        // potentially-loaded code paths might via PHP property initialisation.
        $loggerProp = $reflection->getProperty('logger');
        $loggerProp->setAccessible(true);
        $loggerProp->setValue($service, $this->createMock(LoggerInterface::class));

        $this->service = $service;
    }//end setUp()

    /**
     * When the `systemUserId` config key is unset, the helper falls back to
     * the constant default `__system__`.
     *
     * @return void
     */
    public function testGetSystemUserIdReturnsDefaultWhenConfigEmpty(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('getValueString')
            ->with('openregister', OrganisationService::CONFIG_SYSTEM_USER_ID, '')
            ->willReturn('');

        $this->assertSame(
            OrganisationService::SYSTEM_USER_ID_DEFAULT,
            $this->service->getSystemUserId()
        );
    }//end testGetSystemUserIdReturnsDefaultWhenConfigEmpty()

    /**
     * A configured override is honoured verbatim.
     *
     * @return void
     */
    public function testGetSystemUserIdHonoursConfiguredOverride(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('getValueString')
            ->with('openregister', OrganisationService::CONFIG_SYSTEM_USER_ID, '')
            ->willReturn('cron-bot');

        $this->assertSame('cron-bot', $this->service->getSystemUserId());
    }//end testGetSystemUserIdHonoursConfiguredOverride()

    /**
     * Empty reader-groups config returns an empty array.
     *
     * @return void
     */
    public function testGetSystemReaderGroupsReturnsEmptyWhenConfigEmpty(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('getValueString')
            ->with('openregister', OrganisationService::CONFIG_SYSTEM_READER_GROUPS, '')
            ->willReturn('');

        $this->assertSame([], $this->service->getSystemReaderGroups());
    }//end testGetSystemReaderGroupsReturnsEmptyWhenConfigEmpty()

    /**
     * Reader-groups parse: comma-separated, trimmed, empties dropped.
     *
     * @return void
     */
    public function testGetSystemReaderGroupsTrimsAndDropsEmpties(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('getValueString')
            ->with('openregister', OrganisationService::CONFIG_SYSTEM_READER_GROUPS, '')
            ->willReturn(' log-readers , audit-readers ,, ');

        $this->assertSame(
            ['log-readers', 'audit-readers'],
            $this->service->getSystemReaderGroups()
        );
    }//end testGetSystemReaderGroupsTrimsAndDropsEmpties()
}//end class
