<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\PollLinkService}.
 *
 * Exercises the Tier-2 service contract (link/create/list/unlink +
 * available-polls picker) against mocked PollLinkMapper + IDBConnection.
 * Tests requiring real Polls table data are kept in integration tests;
 * unit scope here is the service's branch coverage.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/integration-polls/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\PollLink;
use OCA\OpenRegister\Db\PollLinkMapper;
use OCA\OpenRegister\Service\PollLinkService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * PollLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class PollLinkServiceTest extends TestCase
{
    private PollLinkMapper&MockObject $mapper;
    private IDBConnection&MockObject $db;
    private IAppManager&MockObject $appManager;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private PollLinkService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->getMockBuilder(PollLinkMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'findByObjectUuid',
                'findByObjectAndPoll',
                'deleteByObjectAndPoll',
                'insert',
            ])
            ->getMock();
        $this->db          = $this->createMock(IDBConnection::class);
        $this->appManager  = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->service = new PollLinkService(
            $this->mapper,
            $this->db,
            $this->appManager,
            $this->userSession,
            $this->logger
        );
    }

    private function setupUser(string $uid = 'admin'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    public function testIsPollsAvailableTrue(): void
    {
        $this->appManager->method('isEnabledForUser')->with('polls')->willReturn(true);
        $this->assertTrue($this->service->isPollsAvailable());
    }

    public function testIsPollsAvailableFalse(): void
    {
        $this->appManager->method('isEnabledForUser')->with('polls')->willReturn(false);
        $this->assertFalse($this->service->isPollsAvailable());
    }

    public function testLinkPollThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No user logged in');

        $this->service->linkPoll('abc-123', 1, 2, 5);
    }

    public function testLinkPollThrowsOnDuplicate(): void
    {
        $this->setupUser();
        $existing = new PollLink();
        $this->mapper->method('findByObjectAndPoll')->with('abc-123', 5)->willReturn($existing);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('Poll already linked to this object');

        $this->service->linkPoll('abc-123', 1, 2, 5);
    }

    public function testLinkPollThrowsWhenPollsUnavailable(): void
    {
        $this->setupUser();
        $this->mapper->method('findByObjectAndPoll')->willReturn(null);
        $this->appManager->method('isEnabledForUser')->with('polls')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('Polls is not available');

        $this->service->linkPoll('abc-123', 1, 2, 5);
    }

    public function testUnlinkPollSucceeds(): void
    {
        $this->mapper->expects($this->once())
            ->method('deleteByObjectAndPoll')
            ->with('abc-123', 5)
            ->willReturn(1);

        $this->service->unlinkPoll('abc-123', 5);
    }

    public function testUnlinkPollNotFound(): void
    {
        $this->mapper->method('deleteByObjectAndPoll')->willReturn(0);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessage('Poll link not found');

        $this->service->unlinkPoll('abc-123', 5);
    }

    public function testGetLinkedPollsEmpty(): void
    {
        // Polls app not enabled → getLinkedPolls short-circuits to [].
        // isPollsAvailable() returns bool, so the mock must be stubbed
        // (an unconfigured mock returns null and violates the type).
        $this->appManager->method('isEnabledForUser')->with('polls')->willReturn(false);
        $this->mapper->method('findByObjectUuid')->willReturn([]);

        $this->assertSame([], $this->service->getLinkedPolls('nonexistent'));
    }

    public function testGetLinkedPollsReturnsSerialisedRowsWhenPollsUnavailable(): void
    {
        $link = new PollLink();
        $link->setObjectUuid('abc-123');
        $link->setPollId(99);
        $link->setPollTitle('Stub Poll');
        $link->setPollType('textPoll');

        $this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);
        // Polls uninstalled — cached link row used as-is, no DB hop.
        $this->appManager->method('isEnabledForUser')->with('polls')->willReturn(false);

        $rows = $this->service->getLinkedPolls('abc-123');

        $this->assertCount(1, $rows);
        $this->assertSame('abc-123', $rows[0]['objectUuid']);
        $this->assertSame(99, $rows[0]['pollId']);
        $this->assertSame('Stub Poll', $rows[0]['pollTitle']);
        $this->assertSame('textPoll', $rows[0]['pollType']);
        // NC Polls routes votes at /apps/polls/vote/{id} — the legacy
        // /index.php prefix broke under the SPA's <base href> (doubled to
        // /apps/polls/index.php/apps/polls/vote/… on navigation).
        $this->assertSame('/apps/polls/vote/99', $rows[0]['url']);
    }

    public function testCreateAndLinkPollThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No user logged in');

        $this->service->createAndLinkPoll('abc-123', 1, 2, 'Lunch', '', 'textPoll', ['A'], null);
    }

    public function testCreateAndLinkPollThrowsWhenPollsUnavailable(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->with('polls')->willReturn(false);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(503);
        $this->expectExceptionMessage('Polls is not available');

        $this->service->createAndLinkPoll('abc-123', 1, 2, 'Lunch', '', 'textPoll', ['A'], null);
    }

    public function testCreateAndLinkPollThrowsWhenTitleEmpty(): void
    {
        $this->setupUser();
        $this->appManager->method('isEnabledForUser')->with('polls')->willReturn(true);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);
        $this->expectExceptionMessage('Title is required');

        $this->service->createAndLinkPoll('abc-123', 1, 2, '   ', '', 'textPoll', ['A'], null);
    }

    public function testGetAvailablePollsReturnsEmptyWhenPollsUnavailable(): void
    {
        $this->appManager->method('isEnabledForUser')->with('polls')->willReturn(false);
        $this->assertSame([], $this->service->getAvailablePolls());
    }

    public function testGetAvailablePollsReturnsEmptyWhenNoUser(): void
    {
        $this->appManager->method('isEnabledForUser')->with('polls')->willReturn(true);
        $this->userSession->method('getUser')->willReturn(null);
        $this->assertSame([], $this->service->getAvailablePolls());
    }
}
