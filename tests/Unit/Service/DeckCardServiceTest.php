<?php

namespace Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCA\OpenRegister\Service\DeckCardService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeckCardServiceTest extends TestCase
{
    private DeckLinkMapper&MockObject $deckLinkMapper;
    private IAppManager&MockObject $appManager;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private IURLGenerator&MockObject $urlGenerator;
    private DeckCardService $service;

    protected function setUp(): void
    {
        $this->deckLinkMapper = $this->getMockBuilder(DeckLinkMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByObjectUuid', 'findByBoardId', 'findByObjectAndCard', 'deleteByObjectUuid', 'insert', 'delete'])
            ->addMethods(['find'])
            ->getMock();
        $this->appManager = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);

        // Webroot-aware base for Deck deep links.
        $this->urlGenerator->method('linkToRoute')
            ->with('deck.page.index')
            ->willReturn('/index.php/apps/deck/');

        $this->service = new DeckCardService(
            $this->deckLinkMapper,
            $this->appManager,
            $this->userSession,
            $this->logger,
            $this->urlGenerator
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

    public function testGetCardsForObjectReturnsResults(): void
    {
        $link = new DeckLink();
        $link->setObjectUuid('abc-123');
        $link->setCardTitle('Test Card');

        $this->deckLinkMapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

        $result = $this->service->getCardsForObject('abc-123');

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['results']);
        $this->assertSame('Test Card', $result['results'][0]['cardTitle']);
    }

    public function testGetCardsForObjectEmpty(): void
    {
        $this->deckLinkMapper->method('findByObjectUuid')->willReturn([]);

        $result = $this->service->getCardsForObject('nonexistent');

        $this->assertSame(0, $result['total']);
    }

    /**
     * relation-resourceurl-deeplinks: a card with a board id and card id gets
     * a webroot-aware Deck deep-link `url` so the related-objects widget can
     * navigate straight to the card.
     */
    public function testGetCardsForObjectStampsDeepLinkUrl(): void
    {
        $link = new DeckLink();
        $link->setObjectUuid('abc-123');
        $link->setBoardId(7);
        $link->setCardId(42);

        $this->deckLinkMapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

        $result = $this->service->getCardsForObject('abc-123');

        $this->assertSame(
            '/index.php/apps/deck/board/7/card/42',
            $result['results'][0]['url']
        );
    }

    /**
     * relation-resourceurl-deeplinks: when the card id is missing the record is
     * still returned, just without a (broken) `url`.
     */
    public function testGetCardsForObjectOmitsUrlWhenCardIdMissing(): void
    {
        $link = new DeckLink();
        $link->setObjectUuid('abc-123');
        $link->setBoardId(7);
        // No card id set.

        $this->deckLinkMapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

        $result = $this->service->getCardsForObject('abc-123');

        $this->assertArrayNotHasKey('url', $result['results'][0]);
    }

    /**
     * Phase B-1: rows always carry the widened payload keys (dueDate,
     * labels, assignees) — when Deck isn't resolvable they fall back
     * to sensible defaults (null / empty arrays) without throwing.
     *
     * The DeckCardService resolves OCA\Deck\Service\CardService through
     * \OC::$server (not injectable), so the unit test exercises the
     * "Deck unavailable" path — the live Deck-installed path is
     * exercised in the live verification (see commit message).
     *
     * @group requires-app-internal-api
     */
    public function testGetCardsForObjectShipsWidenedKeysWithDefaultsWhenDeckUnavailable(): void
    {
        $link = new DeckLink();
        $link->setObjectUuid('abc-123');
        $link->setCardTitle('Stub Card');
        $link->setCardId(99);

        $this->deckLinkMapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

        $result = $this->service->getCardsForObject('abc-123');

        $this->assertCount(1, $result['results']);
        $row = $result['results'][0];

        $this->assertArrayHasKey('dueDate', $row);
        $this->assertArrayHasKey('labels', $row);
        $this->assertArrayHasKey('assignees', $row);

        // Defaults when no Deck card service is reachable.
        $this->assertNull($row['dueDate']);
        $this->assertSame([], $row['labels']);
        $this->assertSame([], $row['assignees']);

        // Original payload intact.
        $this->assertSame('Stub Card', $row['cardTitle']);
        $this->assertSame(99, $row['cardId']);
    }

    /**
     * Phase B-1: idempotency — calling getCardsForObject twice produces
     * the same shape (no double-write into the link row, no key duplication).
     */
    public function testGetCardsForObjectIsIdempotent(): void
    {
        $link = new DeckLink();
        $link->setObjectUuid('abc-123');
        $link->setCardId(99);

        $this->deckLinkMapper->method('findByObjectUuid')->willReturn([$link]);

        $r1 = $this->service->getCardsForObject('abc-123');
        $r2 = $this->service->getCardsForObject('abc-123');

        $this->assertSame($r1, $r2);
    }

    public function testLinkOrCreateCardThrowsWhenNoUser(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No user logged in');

        $this->service->linkOrCreateCard('abc-123', 5, ['boardId' => 1, 'stackId' => 2, 'title' => 'Test']);
    }

    public function testLinkOrCreateCardThrowsMissingParams(): void
    {
        $this->setupUser();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Either cardId or boardId+stackId is required');

        $this->service->linkOrCreateCard('abc-123', 5, []);
    }

    public function testUnlinkCardSuccess(): void
    {
        $link = new DeckLink();
        $this->deckLinkMapper->method('find')->with(3)->willReturn($link);
        $this->deckLinkMapper->expects($this->once())->method('delete')->with($link);

        $this->service->unlinkCard(3);
    }

    public function testUnlinkCardNotFound(): void
    {
        $this->deckLinkMapper->method('find')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Deck link not found');

        $this->service->unlinkCard(999);
    }

    /**
     * Build a service whose Deck board-permission check is stubbed, so the
     * board-scoped tests do not depend on a running Deck app.
     *
     * @param bool $canAccess What userCanAccessBoard() should return.
     */
    private function serviceWithBoardAccess(bool $canAccess): DeckCardService
    {
        $service = $this->getMockBuilder(DeckCardService::class)
            ->setConstructorArgs([
                $this->deckLinkMapper,
                $this->appManager,
                $this->userSession,
                $this->logger,
            ])
            ->onlyMethods(['userCanAccessBoard'])
            ->getMock();
        $service->method('userCanAccessBoard')->willReturn($canAccess);

        return $service;
    }

    public function testGetObjectsForBoardReturnsLinks(): void
    {
        $link = new DeckLink();
        $link->setObjectUuid('abc-123');
        $link->setBoardId(1);

        $this->deckLinkMapper->method('findByBoardId')->with(1)->willReturn([$link]);

        $service = $this->serviceWithBoardAccess(true);
        $results = $service->getObjectsForBoard(1);

        $this->assertCount(1, $results);
        $this->assertSame('abc-123', $results[0]['objectUuid']);
    }

    public function testGetObjectsForBoardDeniedReturnsEmpty(): void
    {
        // IDOR: the caller has no Deck access to the board — no links leak and
        // the mapper is never queried.
        $this->deckLinkMapper->expects($this->never())->method('findByBoardId');

        $service = $this->serviceWithBoardAccess(false);

        $this->assertSame([], $service->getObjectsForBoard(1));
    }

    public function testDeleteLinksForObject(): void
    {
        $this->deckLinkMapper->expects($this->once())
            ->method('deleteByObjectUuid')
            ->with('abc-123')
            ->willReturn(2);

        $this->assertSame(2, $this->service->deleteLinksForObject('abc-123'));
    }
}
