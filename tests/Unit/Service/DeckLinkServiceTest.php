<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\DeckLinkService}.
 *
 * Exercises the Tier-2 service contract (link/create/list/unlink +
 * board/stack discovery) against a mocked DeckLinkMapper. Tests that
 * touch Deck's internal services (CardService/BoardService/StackService)
 * use the "Deck unavailable" path because those classes are resolved
 * from `\OC::$server` and aren't injectable into this unit test scope.
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
 * @spec openspec/changes/integration-deck/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCA\OpenRegister\Service\DeckLinkService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * DeckLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DeckLinkServiceTest extends TestCase
{
    private DeckLinkMapper&MockObject $mapper;
    private IAppManager&MockObject $appManager;
    private IUserSession&MockObject $userSession;
    private IUserManager&MockObject $userManager;
    private LoggerInterface&MockObject $logger;
    private DeckLinkService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->getMockBuilder(DeckLinkMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'findByObjectUuid',
                'findByObjectAndCard',
                'deleteByObjectAndCard',
                'insert',
            ])
            ->getMock();
        $this->appManager  = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->service = new DeckLinkService(
            $this->mapper,
            $this->appManager,
            $this->userSession,
            $this->userManager,
            $this->logger
        );
    }

    private function setupUser(string $uid = 'admin'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    public function testIsDeckAvailableTrue(): void
    {
        $this->appManager->method('isEnabledForUser')->with('deck')->willReturn(true);
        $this->assertTrue($this->service->isDeckAvailable());
    }

    public function testIsDeckAvailableFalse(): void
    {
        $this->appManager->method('isEnabledForUser')->with('deck')->willReturn(false);
        $this->assertFalse($this->service->isDeckAvailable());
    }

    public function testLinkCardThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No user logged in');

        $this->service->linkCard('abc-123', 1, 2, 5);
    }

    public function testLinkCardThrowsOnDuplicate(): void
    {
        $this->setupUser();
        $existing = new DeckLink();
        $this->mapper->method('findByObjectAndCard')->with('abc-123', 5)->willReturn($existing);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(409);
        $this->expectExceptionMessage('Card already linked to this object');

        $this->service->linkCard('abc-123', 1, 2, 5);
    }

    public function testLinkCardThrowsWhenCardCannotBeResolved(): void
    {
        $this->setupUser();
        $this->mapper->method('findByObjectAndCard')->willReturn(null);

        // Either Deck is not installed (→ 503 from resolveCardService null
        // check) or Deck's CardService throws because card #5 doesn't
        // exist (→ 404). Both are valid "cannot link this card" outcomes,
        // so accept either code.
        $this->expectException(Exception::class);

        try {
            $this->service->linkCard('abc-123', 1, 2, 5);
            $this->fail('Expected exception was not thrown');
        } catch (Exception $exception) {
            $this->assertContains($exception->getCode(), [404, 503]);
            throw $exception;
        }
    }

    public function testUnlinkCardSucceeds(): void
    {
        $this->mapper->expects($this->once())
            ->method('deleteByObjectAndCard')
            ->with('abc-123', 5)
            ->willReturn(1);

        $this->service->unlinkCard('abc-123', 5);
    }

    public function testUnlinkCardNotFound(): void
    {
        $this->mapper->method('deleteByObjectAndCard')->willReturn(0);

        $this->expectException(Exception::class);
        $this->expectExceptionCode(404);
        $this->expectExceptionMessage('Deck link not found');

        $this->service->unlinkCard('abc-123', 5);
    }

    public function testGetLinkedCardsReturnsSerialisedRows(): void
    {
        $link = new DeckLink();
        $link->setObjectUuid('abc-123');
        $link->setCardId(99);
        $link->setCardTitle('Stub Card');

        $this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

        $rows = $this->service->getLinkedCards('abc-123');

        $this->assertCount(1, $rows);
        $this->assertSame('abc-123', $rows[0]['objectUuid']);
        $this->assertSame(99, $rows[0]['cardId']);
        $this->assertSame('Stub Card', $rows[0]['cardTitle']);
        // Tier-2 widened keys always present (empty when Deck unavailable).
        $this->assertArrayHasKey('dueDate', $rows[0]);
        $this->assertArrayHasKey('labels', $rows[0]);
        $this->assertArrayHasKey('assignees', $rows[0]);
        $this->assertSame([], $rows[0]['labels']);
        $this->assertSame([], $rows[0]['assignees']);
    }

    public function testGetLinkedCardsEmpty(): void
    {
        $this->mapper->method('findByObjectUuid')->willReturn([]);

        $this->assertSame([], $this->service->getLinkedCards('nonexistent'));
    }

    public function testCreateAndLinkCardThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No user logged in');

        $this->service->createAndLinkCard('abc-123', 1, 2, 10, 20, 'Test', null, null);
    }

    public function testCreateAndLinkCardThrowsWhenDeckOperationFails(): void
    {
        $this->setupUser();

        // Either Deck isn't installed (→ 503 from the null CardService
        // guard) or CardService throws when creating against a
        // non-existent stack id (→ 500 from the catch-rethrow). Both
        // are "create did not succeed" outcomes; either code is fine.
        $this->expectException(Exception::class);

        try {
            $this->service->createAndLinkCard('abc-123', 1, 2, 10, 20, 'Test', null, null);
            $this->fail('Expected exception was not thrown');
        } catch (Exception $exception) {
            $this->assertContains($exception->getCode(), [500, 503]);
            throw $exception;
        }
    }

    public function testGetAvailableBoardsReturnsEmptyWhenDeckUnavailable(): void
    {
        // BoardService unavailable → empty list, no throw.
        $this->assertSame([], $this->service->getAvailableBoards());
    }

    public function testGetStacksForBoardReturnsEmptyWhenDeckUnavailable(): void
    {
        $this->assertSame([], $this->service->getStacksForBoard(42));
    }
}
