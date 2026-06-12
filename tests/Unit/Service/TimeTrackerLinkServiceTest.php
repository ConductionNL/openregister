<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\TimeTrackerLinkService}.
 *
 * Exercises the Tier-2 service contract (linkEntry / createAndLinkClient /
 * unlink / getLinkedEntries + available picker) against a mocked
 * TimeTrackerLinkMapper. Tests that touch NC TimeManager's ClientMapper /
 * TaskMapper use the "TimeManager unavailable" path because those classes
 * are resolved from the container and aren't injectable into this unit
 * test scope without the `timemanager` app on the classpath. Real
 * round-trips are gated by `@group requires-app-timemanager`.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\TimeTrackerLink;
use OCA\OpenRegister\Db\TimeTrackerLinkMapper;
use OCA\OpenRegister\Service\TimeTrackerLinkService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * TimeTrackerLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @group requires-app-timemanager
 */
class TimeTrackerLinkServiceTest extends TestCase
{

    private TimeTrackerLinkMapper&MockObject $mapper;

    private IDBConnection&MockObject $db;

    private ContainerInterface&MockObject $container;

    private IAppManager&MockObject $appManager;

    private IUserSession&MockObject $userSession;

    private LoggerInterface&MockObject $logger;

    private TimeTrackerLinkService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->getMockBuilder(TimeTrackerLinkMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(
                    [
                        'findByObjectUuid',
                        'findByObjectAndEntry',
                        'deleteByObjectAndEntryId',
                        'findAll',
                        'insert',
                        'update',
                    ]
                    )
            ->getMock();

        $this->db          = $this->createMock(IDBConnection::class);
        $this->container   = $this->createMock(ContainerInterface::class);
        $this->appManager  = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->service = new TimeTrackerLinkService(
            $this->mapper,
            $this->db,
            $this->container,
            $this->appManager,
            $this->userSession,
            $this->logger
        );
    }//end setUp()

    private function setupUser(string $uid='alice'): IUser&MockObject
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
        return $user;
    }//end setupUser()

    public function testIsTimeManagerAvailableTrue(): void
    {
        $this->appManager->method('isEnabledForUser')->with('timemanager')->willReturn(true);
        $this->assertTrue($this->service->isTimeManagerAvailable());
    }//end testIsTimeManagerAvailableTrue()

    public function testIsTimeManagerAvailableFalse(): void
    {
        $this->appManager->method('isEnabledForUser')->with('timemanager')->willReturn(false);
        $this->assertFalse($this->service->isTimeManagerAvailable());
    }//end testIsTimeManagerAvailableFalse()

    public function testLinkEntryThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);

        $this->service->linkEntry('abc-123', 1, 2, 'client', 'cli-1');
    }//end testLinkEntryThrowsWhenNoUser()

    public function testLinkEntryThrowsOnUnknownType(): void
    {
        $this->setupUser();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        $this->service->linkEntry('abc-123', 1, 2, 'banana', 'cli-1');
    }//end testLinkEntryThrowsOnUnknownType()

    public function testLinkEntryThrowsOnEmptyId(): void
    {
        $this->setupUser();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        $this->service->linkEntry('abc-123', 1, 2, 'client', '   ');
    }//end testLinkEntryThrowsOnEmptyId()

    public function testLinkEntryThrowsWhenTimeManagerUnavailable(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->with('timemanager')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(503);

        $this->service->linkEntry('abc-123', 1, 2, 'client', 'cli-1');
    }//end testLinkEntryThrowsWhenTimeManagerUnavailable()

    public function testLinkEntryThrowsOnDuplicate(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->mapper->method('findByObjectAndEntry')->willReturn(new TimeTrackerLink());

        $this->expectException(Exception::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('Entry already linked to this object');

        $this->service->linkEntry('abc-123', 1, 2, 'client', 'cli-1');
    }//end testLinkEntryThrowsOnDuplicate()

    public function testCreateAndLinkClientThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);

        $this->service->createAndLinkClient('abc-123', 1, 2, 'Acme');
    }//end testCreateAndLinkClientThrowsWhenNoUser()

    public function testCreateAndLinkClientThrowsOnEmptyName(): void
    {
        $this->setupUser();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        $this->service->createAndLinkClient('abc-123', 1, 2, '   ');
    }//end testCreateAndLinkClientThrowsOnEmptyName()

    public function testCreateAndLinkClientThrowsWhenTimeManagerUnavailable(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->with('timemanager')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(503);

        $this->service->createAndLinkClient('abc-123', 1, 2, 'Acme');
    }//end testCreateAndLinkClientThrowsWhenTimeManagerUnavailable()

    public function testUnlinkThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);

        $this->service->unlink('abc-123', 'cli-1');
    }//end testUnlinkThrowsWhenNoUser()

    public function testUnlinkThrowsWhenLinkMissing(): void
    {
        $this->setupUser();
        $this->mapper->method('deleteByObjectAndEntryId')->with('abc-123', 'cli-1')->willReturn(0);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);

        $this->service->unlink('abc-123', 'cli-1');
    }//end testUnlinkThrowsWhenLinkMissing()

    public function testUnlinkSucceeds(): void
    {
        $this->setupUser();
        $this->mapper->expects($this->once())
            ->method('deleteByObjectAndEntryId')
            ->with('abc-123', 'cli-1')
            ->willReturn(1);

        $this->service->unlink('abc-123', 'cli-1');
    }//end testUnlinkSucceeds()

    public function testGetLinkedEntriesReturnsRows(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $link = new TimeTrackerLink();
        $link->setObjectUuid('abc-123');
        $link->setEntryType('client');
        $link->setClientId('cli-1');
        $link->setName('Acme');

        $this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

        $rows = $this->service->getLinkedEntries('abc-123');

        $this->assertCount(1, $rows);
        $this->assertSame('client', $rows[0]['kind']);
        $this->assertSame('cli-1', $rows[0]['id']);
        $this->assertSame('Acme', $rows[0]['name']);
    }//end testGetLinkedEntriesReturnsRows()

    public function testGetLinkedEntriesEmpty(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->willReturn(false);
        $this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([]);

        $this->assertSame([], $this->service->getLinkedEntries('abc-123'));
    }//end testGetLinkedEntriesEmpty()

    public function testGetAvailableClientsEmptyWhenTimeManagerUnavailable(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->with('timemanager')->willReturn(false);

        $this->assertSame([], $this->service->getAvailableClients());
    }//end testGetAvailableClientsEmptyWhenTimeManagerUnavailable()

    public function testGetAvailableClientsEmptyWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->appManager->method('isEnabledForUser')->with('timemanager')->willReturn(true);

        $this->assertSame([], $this->service->getAvailableClients());
    }//end testGetAvailableClientsEmptyWhenNoUser()

    /**
     * `reconcileAllLinks()` returns a zero-stats record when NC TimeManager
     * is not available (the upstream fetch path can't run, so reconciling
     * is a no-op rather than an error).
     *
     * @return void
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md
     */
    public function testReconcileAllLinksNoopWhenTimeManagerUnavailable(): void
    {
        $this->appManager->method('isEnabledForUser')->with('timemanager')->willReturn(false);

        // The mapper must NOT be touched when TimeManager is unavailable —
        // the early-out short-circuits before any DB read.
        $this->mapper->expects($this->never())->method('findAll');

        $stats = $this->service->reconcileAllLinks();

        $this->assertSame(
            ['walked' => 0, 'refreshed' => 0, 'missing' => 0, 'errors' => 0],
            $stats
        );
    }//end testReconcileAllLinksNoopWhenTimeManagerUnavailable()
}//end class
