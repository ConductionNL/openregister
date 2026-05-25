<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\CospendLinkService}.
 *
 * Exercises the Tier-2 service contract (linkProject / linkBill /
 * createAndLinkProject / unlink / getLinkedEntries + available picker)
 * against a mocked CospendLinkMapper. Tests that touch NC Cospend's
 * `ProjectService` or the `cospend_*` tables use the "Cospend
 * unavailable" path because those are resolved from the container /
 * `IDBConnection` and aren't injectable into this unit-test scope without
 * the `cospend` app on the classpath. Real round-trips are gated by
 * `@group requires-app-cospend`.
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
 * @spec openspec/changes/integration-cospend/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\CospendLink;
use OCA\OpenRegister\Db\CospendLinkMapper;
use OCA\OpenRegister\Service\CospendLinkService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * CospendLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @group requires-app-cospend
 */
class CospendLinkServiceTest extends TestCase
{

    private CospendLinkMapper&MockObject $mapper;

    private ContainerInterface&MockObject $container;

    private IAppManager&MockObject $appManager;

    private IUserSession&MockObject $userSession;

    private IDBConnection&MockObject $db;

    private LoggerInterface&MockObject $logger;

    private CospendLinkService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->getMockBuilder(CospendLinkMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(
                    [
                        'findByObjectUuid',
                        'findByObjectAndId',
                        'findDuplicate',
                        'deleteByObjectAndId',
                        'insert',
                        'update',
                    ]
                    )
            ->getMock();

        $this->container   = $this->createMock(ContainerInterface::class);
        $this->appManager  = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->db          = $this->createMock(IDBConnection::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->service = new CospendLinkService(
            $this->mapper,
            $this->container,
            $this->appManager,
            $this->userSession,
            $this->db,
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

    public function testIsCospendAvailableTrue(): void
    {
        $this->appManager->method('isEnabledForUser')->with('cospend')->willReturn(true);
        $this->assertTrue($this->service->isCospendAvailable());
    }//end testIsCospendAvailableTrue()

    public function testIsCospendAvailableFalse(): void
    {
        $this->appManager->method('isEnabledForUser')->with('cospend')->willReturn(false);
        $this->assertFalse($this->service->isCospendAvailable());
    }//end testIsCospendAvailableFalse()

    public function testLinkProjectThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);

        $this->service->linkProject('abc-123', 1, 2, 'proj-1');
    }//end testLinkProjectThrowsWhenNoUser()

    public function testLinkProjectThrowsWhenCospendUnavailable(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->with('cospend')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(503);

        $this->service->linkProject('abc-123', 1, 2, 'proj-1');
    }//end testLinkProjectThrowsWhenCospendUnavailable()

    public function testLinkProjectThrowsOnEmptyProjectId(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->willReturn(true);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        $this->service->linkProject('abc-123', 1, 2, '   ');
    }//end testLinkProjectThrowsOnEmptyProjectId()

    public function testLinkProjectThrowsOnDuplicate(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->mapper->method('findDuplicate')
            ->with('abc-123', 'project', 'proj-1', null)
            ->willReturn(new CospendLink());

        $this->expectException(Exception::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('Project already linked to this object');

        $this->service->linkProject('abc-123', 1, 2, 'proj-1');
    }//end testLinkProjectThrowsOnDuplicate()

    public function testLinkBillThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);

        $this->service->linkBill('abc-123', 1, 2, 'proj-1', 7);
    }//end testLinkBillThrowsWhenNoUser()

    public function testLinkBillThrowsWhenCospendUnavailable(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->with('cospend')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(503);

        $this->service->linkBill('abc-123', 1, 2, 'proj-1', 7);
    }//end testLinkBillThrowsWhenCospendUnavailable()

    public function testLinkBillThrowsOnDuplicate(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->mapper->method('findDuplicate')
            ->with('abc-123', 'bill', 'proj-1', 7)
            ->willReturn(new CospendLink());

        $this->expectException(Exception::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('Bill already linked to this object');

        $this->service->linkBill('abc-123', 1, 2, 'proj-1', 7);
    }//end testLinkBillThrowsOnDuplicate()

    public function testCreateAndLinkProjectThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);

        $this->service->createAndLinkProject('abc-123', 1, 2, 'Holiday', 'EUR');
    }//end testCreateAndLinkProjectThrowsWhenNoUser()

    public function testCreateAndLinkProjectThrowsOnEmptyName(): void
    {
        $this->setupUser();

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        $this->service->createAndLinkProject('abc-123', 1, 2, '   ', 'EUR');
    }//end testCreateAndLinkProjectThrowsOnEmptyName()

    public function testCreateAndLinkProjectThrowsWhenCospendUnavailable(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->with('cospend')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(503);

        $this->service->createAndLinkProject('abc-123', 1, 2, 'Holiday', 'EUR');
    }//end testCreateAndLinkProjectThrowsWhenCospendUnavailable()

    public function testUnlinkThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(401);

        $this->service->unlink('abc-123', 5);
    }//end testUnlinkThrowsWhenNoUser()

    public function testUnlinkThrowsWhenLinkMissing(): void
    {
        $this->setupUser();
        $this->mapper->method('deleteByObjectAndId')->with('abc-123', 5)->willReturn(0);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);

        $this->service->unlink('abc-123', 5);
    }//end testUnlinkThrowsWhenLinkMissing()

    public function testUnlinkSucceeds(): void
    {
        $this->setupUser();
        $this->mapper->expects($this->once())
            ->method('deleteByObjectAndId')
            ->with('abc-123', 5)
            ->willReturn(1);

        $this->service->unlink('abc-123', 5);
    }//end testUnlinkSucceeds()

    public function testGetLinkedEntriesReturnsSerializedRows(): void
    {
        // Cospend unavailable so no stale-refresh DB access is attempted.
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        $project = new CospendLink();
        $project->setObjectUuid('abc-123');
        $project->setEntryType('project');
        $project->setProjectId('proj-1');
        $project->setName('Holiday');
        $project->setCurrency('EUR');

        $bill = new CospendLink();
        $bill->setObjectUuid('abc-123');
        $bill->setEntryType('bill');
        $bill->setProjectId('proj-1');
        $bill->setBillId(7);
        $bill->setName('Hotel');
        $bill->setAmount(120.5);
        $bill->setCurrency('EUR');

        $this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$project, $bill]);

        $rows = $this->service->getLinkedEntries('abc-123');

        $this->assertCount(2, $rows);
        $this->assertSame('project', $rows[0]['entryType']);
        $this->assertSame('Holiday', $rows[0]['name']);
        $this->assertSame('bill', $rows[1]['entryType']);
        $this->assertSame(7, $rows[1]['billId']);
        $this->assertSame(120.5, $rows[1]['amount']);
    }//end testGetLinkedEntriesReturnsSerializedRows()

    public function testGetLinkedEntriesEmpty(): void
    {
        $this->appManager->method('isEnabledForUser')->willReturn(false);
        $this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([]);

        $this->assertSame([], $this->service->getLinkedEntries('abc-123'));
    }//end testGetLinkedEntriesEmpty()

    public function testGetAvailableProjectsEmptyWhenCospendUnavailable(): void
    {
        $this->appManager->method('isEnabledForUser')->with('cospend')->willReturn(false);

        $this->assertSame([], $this->service->getAvailableProjects());
    }//end testGetAvailableProjectsEmptyWhenCospendUnavailable()

    public function testGetAvailableProjectsEmptyWhenNoUser(): void
    {
        $this->appManager->method('isEnabledForUser')->with('cospend')->willReturn(true);
        $this->userSession->method('getUser')->willReturn(null);

        $this->assertSame([], $this->service->getAvailableProjects());
    }//end testGetAvailableProjectsEmptyWhenNoUser()
}//end class
