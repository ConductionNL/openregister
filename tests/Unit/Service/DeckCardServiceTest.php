<?php

namespace Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCA\OpenRegister\Service\DeckCardService;
use OCP\App\IAppManager;
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
    private DeckCardService $service;

    protected function setUp(): void
    {
        $this->deckLinkMapper = $this->createMock(DeckLinkMapper::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new DeckCardService(
            $this->deckLinkMapper,
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

    public function testGetObjectsForBoardReturnsLinks(): void
    {
        $link = new DeckLink();
        $link->setObjectUuid('abc-123');
        $link->setBoardId(1);

        $this->deckLinkMapper->method('findByBoardId')->with(1)->willReturn([$link]);

        $results = $this->service->getObjectsForBoard(1);

        $this->assertCount(1, $results);
        $this->assertSame('abc-123', $results[0]['objectUuid']);
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
