<?php

/**
 * Behaviour tests for {@see \OCA\OpenRegister\Service\DeckLinkService}
 * when the Deck app classes resolve: linking a card (board/stack/title,
 * due date, labels, assignees), refreshing linked rows, create-and-link,
 * and the board/stack pickers.
 *
 * The Deck app is replaced by the doubles in Fixtures/DeckAppDoubles.php
 * (the app is not installed in CI).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

require_once __DIR__ . '/Fixtures/DeckAppDoubles.php';

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCA\OpenRegister\Service\CardDraft;
use OCA\OpenRegister\Service\DeckLinkService;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Unit\Service\Fixtures\DeckCardMapperDouble;
use Unit\Service\Fixtures\DeckCardServiceDouble;
use Unit\Service\Fixtures\DeckEntityDouble;
use Unit\Service\Fixtures\DeckListingServiceDouble;

/**
 * DeckLinkServiceAppPresentTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DeckLinkServiceAppPresentTest extends TestCase {
	private DeckLinkMapper&MockObject $linkMapper;
	private IUserSession&MockObject $userSession;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private DeckCardServiceDouble $cardService;
	private DeckCardMapperDouble $cardMapper;
	private DeckListingServiceDouble $boardService;
	private DeckListingServiceDouble $stackService;
	/** @var array<string, string> container ids that throw, with the message */
	private array $unresolvable = [];
	private DeckLinkService $service;

	protected function setUp(): void {
		$this->linkMapper = $this->getMockBuilder(DeckLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findByObjectAndCard', 'deleteByObjectAndCard', 'insert'])
			->getMock();
		$this->linkMapper->method('insert')->willReturnArgument(0);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->cardService = new DeckCardServiceDouble();
		$this->cardMapper = new DeckCardMapperDouble();
		$this->boardService = new DeckListingServiceDouble();
		$this->stackService = new DeckListingServiceDouble();
		$services = [
			'OCA\\Deck\\Service\\CardService' => $this->cardService,
			'OCA\\Deck\\Db\\CardMapper' => $this->cardMapper,
			'OCA\\Deck\\Service\\BoardService' => $this->boardService,
			'OCA\\Deck\\Service\\StackService' => $this->stackService,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($services): object {
			if (isset($this->unresolvable[$id]) === true) {
				throw new RuntimeException($this->unresolvable[$id]);
			}
			return $services[$id];
		});

		$this->service = new DeckLinkService(
			$this->linkMapper,
			new LinkServiceRuntime($this->createMock(IAppManager::class), $this->userSession, $this->logger),
			$this->userManager,
			$container
		);
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function target(): LinkTarget {
		return (new LinkTarget('obj-1', 3))->inSchema(4);
	}

	private function richCard(): DeckEntityDouble {
		return new DeckEntityDouble([
			'stackId' => 12,
			'title' => 'Fix the boiler',
			'duedate' => new DateTimeImmutable('2026-03-04T05:06:07', new DateTimeZone('UTC')),
			'labels' => [new DeckEntityDouble(['id' => '5', 'title' => 'Urgent', 'color' => 'ff0000'])],
			'assignedUsers' => [
				new DeckEntityDouble(['participant' => 'bob', 'typeString' => 'user']),
				new DeckEntityDouble(['participant' => 'team', 'typeString' => 'group']),
			],
			'relatedBoard' => new DeckEntityDouble(['title' => 'Facilities']),
			'relatedStack' => new DeckEntityDouble(['title' => 'Doing']),
		]);
	}

	public function testLinkCardStoresBoardStackTitleDueDateLabelsAndAssignees(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndCard')->willReturn(null);
		$this->cardService->cards[9] = $this->richCard();
		$this->cardMapper->boardId = '77';
		$bob = $this->createMock(IUser::class);
		$bob->method('getDisplayName')->willReturn('Bob B.');
		$this->userManager->method('get')->with('bob')->willReturn($bob);

		$link = $this->service->linkCard($this->target(), 9);

		$this->assertSame(77, $link->getBoardId());
		$this->assertSame(12, $link->getStackId());
		$this->assertSame(9, $link->getCardId());
		$this->assertSame('Fix the boiler', $link->getCardTitle());
		$this->assertSame('2026-03-04T05:06:07+00:00', $link->getDueDate()->format(DATE_ATOM));
		$this->assertSame('[{"id":5,"title":"Urgent","color":"ff0000"}]', $link->getLabels());
		$this->assertSame(
			'[{"uid":"bob","type":"user","displayName":"Bob B."},{"uid":"team","type":"group","displayName":"team"}]',
			$link->getAssignees()
		);
		$this->assertSame('alice', $link->getLinkedBy());
	}

	public function testLinkCardFallsBackToDefaultsWhenCardGettersThrow(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndCard')->willReturn(null);
		$this->cardService->cards[9] = new DeckEntityDouble([]);
		$this->cardMapper->boardId = null;

		$link = $this->service->linkCard($this->target(), 9);

		$this->assertSame(0, $link->getBoardId());
		$this->assertSame(0, $link->getStackId());
		$this->assertSame('', $link->getCardTitle());
		$this->assertNull($link->getDueDate());
		$this->assertNull($link->getLabels());
		$this->assertNull($link->getAssignees());
	}

	public function testLinkCardUsesBoardZeroAndLogsWhenBoardLookupFails(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndCard')->willReturn(null);
		$this->cardService->cards[9] = new DeckEntityDouble(['stackId' => 1, 'title' => 'T']);
		$this->cardMapper->boardId = new RuntimeException('no board');
		$debug = [];
		$this->logger->method('debug')->willReturnCallback(
			function (string $message) use (&$debug): void {
				$debug[] = $message;
			}
		);

		$this->assertSame(0, $this->service->linkCard($this->target(), 9)->getBoardId());
		$this->assertContains('Deck CardMapper::findBoardId(9) failed: no board', $debug);
	}

	public function testLinkCardReportsNotFoundWhenCardServiceReturnsNull(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndCard')->willReturn(null);
		$this->linkMapper->expects($this->never())->method('insert');

		$this->expectExceptionCode(404);
		$this->expectExceptionMessage('Deck card not found');
		$this->service->linkCard($this->target(), 9);
	}

	public function testLinkCardReportsNotFoundWhenCardServiceThrows(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndCard')->willReturn(null);
		$this->cardService->cards[9] = new RuntimeException('permission');

		$this->expectExceptionCode(404);
		$this->service->linkCard($this->target(), 9);
	}

	public function testLinkCardReportsUnavailableWhenCardServiceUnresolvable(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndCard')->willReturn(null);
		$this->unresolvable['OCA\\Deck\\Service\\CardService'] = 'boom';
		$this->logger->expects($this->once())->method('debug')->with('Deck CardService not resolvable: boom');

		$this->expectExceptionCode(503);
		$this->expectExceptionMessage('Deck is not available');
		$this->service->linkCard($this->target(), 9);
	}

	public function testGetLinkedCardsWidensRowsFromLiveCards(): void {
		$link = new DeckLink();
		$link->setCardId(9);
		$link->setCardTitle('Cached');
		$this->linkMapper->method('findByObjectUuid')->with('obj-1')->willReturn([$link]);
		$this->cardService->cards[9] = $this->richCard();
		$this->userManager->method('get')->willReturn(null);

		$rows = $this->service->getLinkedCards('obj-1');

		$this->assertSame('2026-03-04T05:06:07+00:00', $rows[0]['dueDate']);
		$this->assertSame([['id' => 5, 'title' => 'Urgent', 'color' => 'ff0000']], $rows[0]['labels']);
		$this->assertSame('bob', $rows[0]['assignees'][0]['displayName']);
		$this->assertSame('Facilities', $rows[0]['boardTitle']);
		$this->assertSame('Doing', $rows[0]['stackTitle']);
		$this->assertSame('Cached', $rows[0]['cardTitle']);
	}

	public function testGetLinkedCardsKeepsCachedDueDateWhenLiveCardHasNone(): void {
		$link = new DeckLink();
		$link->setCardId(9);
		$link->setDueDate(new \DateTime('2025-01-01T00:00:00+00:00'));
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->cardService->cards[9] = new DeckEntityDouble(['duedate' => null]);

		$rows = $this->service->getLinkedCards('obj-1');

		$this->assertSame($link->jsonSerialize()['dueDate'], $rows[0]['dueDate']);
		$this->assertNotNull($rows[0]['dueDate']);
		$this->assertSame('', $rows[0]['boardTitle']);
	}

	public function testGetLinkedCardsKeepsCachedRowForStaleCard(): void {
		$link = new DeckLink();
		$link->setCardId(9);
		$link->setCardTitle('Cached');
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->cardService->cards[9] = new RuntimeException('deleted');
		$this->logger->expects($this->once())->method('debug')->with('Stale deck link for card 9: deleted');

		$rows = $this->service->getLinkedCards('obj-1');

		$this->assertSame($link->jsonSerialize(), $rows[0]);
		$this->assertArrayNotHasKey('boardTitle', $rows[0]);
	}

	public function testGetLinkedCardsLeavesRowUntouchedWhenCardIsNotAnObject(): void {
		$link = new DeckLink();
		$link->setCardId(9);
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);

		$rows = $this->service->getLinkedCards('obj-1');

		$this->assertSame($link->jsonSerialize(), $rows[0]);
	}

	public function testCreateAndLinkCardCreatesUpdatesAndLinksOnResolvedBoard(): void {
		$this->loginAs('alice');
		$this->cardService->createResult = new DeckEntityDouble(['id' => 31]);
		$this->cardMapper->boardId = 88;
		$draft = (new CardDraft('New card', 12))->onBoard(5)->withDetails('Body', '2026-05-06T07:08:09+00:00');

		$link = $this->service->createAndLinkCard($this->target(), $draft);

		$this->assertSame(['create', 'New card', 12, 'plain', 0, 'alice'], $this->cardService->calls[0]);
		$this->assertSame(
			['update', 31, 'New card', 12, 'plain', 'alice', "Body\n\n[Object: obj-1](/apps/openregister/objects/obj-1)", 0, '2026-05-06T07:08:09+00:00'],
			$this->cardService->calls[1]
		);
		$this->assertSame(88, $link->getBoardId());
		$this->assertSame(31, $link->getCardId());
		$this->assertSame(12, $link->getStackId());
		$this->assertSame('New card', $link->getCardTitle());
		$this->assertNull($link->getLabels());
	}

	public function testCreateAndLinkCardFallsBackToCallerBoardWhenUnresolved(): void {
		$this->loginAs('alice');
		$this->cardService->createResult = new DeckEntityDouble(['id' => 31]);
		$this->cardMapper->boardId = null;

		$link = $this->service->createAndLinkCard($this->target(), (new CardDraft('N', 12))->onBoard(5));

		$this->assertSame(5, $link->getBoardId());
	}

	public function testCreateAndLinkCardWrapsUpdateFailure(): void {
		$this->loginAs('alice');
		$this->cardService->createResult = new DeckEntityDouble(['id' => 31]);
		$this->cardService->updateFailure = new RuntimeException('locked');
		$this->logger->expects($this->once())->method('warning')->with('Failed to create Deck card: locked');
		$this->linkMapper->expects($this->never())->method('insert');

		$this->expectExceptionCode(500);
		$this->expectExceptionMessage('Failed to create Deck card: locked');
		$this->service->createAndLinkCard($this->target(), new CardDraft('N', 12));
	}

	public function testCreateAndLinkCardReportsUnavailableWhenCardServiceUnresolvable(): void {
		$this->loginAs('alice');
		$this->unresolvable['OCA\\Deck\\Service\\CardService'] = 'x';

		$this->expectExceptionCode(503);
		$this->service->createAndLinkCard($this->target(), new CardDraft('N', 12));
	}

	public function testGetAvailableBoardsListsBoardsWithAnIdSkippingBrokenOnes(): void {
		$this->loginAs('alice');
		$this->boardService->result = [
			new DeckEntityDouble(['id' => 1, 'title' => 'One']),
			new DeckEntityDouble(['id' => 2]),
			new DeckEntityDouble(['id' => 0, 'title' => 'Zero']),
			new DeckEntityDouble(['title' => 'No id']),
		];

		$this->assertSame(
			[['id' => 1, 'title' => 'One'], ['id' => 2, 'title' => '']],
			$this->service->getAvailableBoards()
		);
	}

	public function testGetAvailableBoardsReturnsEmptyWithoutUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->boardService->result = [new DeckEntityDouble(['id' => 1, 'title' => 'One'])];

		$this->assertSame([], $this->service->getAvailableBoards());
		$this->assertSame([], $this->boardService->calls);
	}

	public function testGetAvailableBoardsReturnsEmptyAndLogsWhenListingFails(): void {
		$this->loginAs('alice');
		$this->boardService->result = new RuntimeException('db');
		$this->logger->expects($this->once())->method('debug')->with('Deck BoardService->findAll failed: db');

		$this->assertSame([], $this->service->getAvailableBoards());
	}

	public function testGetAvailableBoardsReturnsEmptyForNonArrayResult(): void {
		$this->loginAs('alice');
		$this->boardService->result = 'nope';

		$this->assertSame([], $this->service->getAvailableBoards());
	}

	public function testGetAvailableBoardsReturnsEmptyWhenBoardServiceUnresolvable(): void {
		$this->loginAs('alice');
		$this->unresolvable['OCA\\Deck\\Service\\BoardService'] = 'gone';
		$this->logger->expects($this->once())->method('debug')->with('Deck BoardService not resolvable: gone');

		$this->assertSame([], $this->service->getAvailableBoards());
	}

	public function testGetStacksForBoardTagsEachStackWithItsBoard(): void {
		$this->stackService->result = [new DeckEntityDouble(['id' => 4, 'title' => 'Todo'])];

		$this->assertSame([['id' => 4, 'title' => 'Todo', 'boardId' => 7]], $this->service->getStacksForBoard(7));
		$this->assertSame([[7]], $this->stackService->calls);
	}

	public function testGetStacksForBoardReturnsEmptyAndLogsWhenListingFails(): void {
		$this->stackService->result = new RuntimeException('db');
		$this->logger->expects($this->once())->method('debug')->with('Deck StackService->findAll(7) failed: db');

		$this->assertSame([], $this->service->getStacksForBoard(7));
	}

	public function testGetStacksForBoardReturnsEmptyForNonArrayResult(): void {
		$this->stackService->result = null;

		$this->assertSame([], $this->service->getStacksForBoard(7));
	}

	public function testGetStacksForBoardReturnsEmptyWhenStackServiceUnresolvable(): void {
		$this->unresolvable['OCA\\Deck\\Service\\StackService'] = 'gone';
		$this->logger->expects($this->once())->method('debug')->with('Deck StackService not resolvable: gone');

		$this->assertSame([], $this->service->getStacksForBoard(7));
	}
}
