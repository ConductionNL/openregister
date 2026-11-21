<?php

/**
 * Behaviour tests for {@see \OCA\OpenRegister\Service\DeckCardService}
 * when the Deck app classes resolve: widening linked rows from live cards,
 * linking an existing card, creating a new one, and the board access guard.
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

use Exception;
use OCA\OpenRegister\Db\DeckLink;
use OCA\OpenRegister\Db\DeckLinkMapper;
use OCA\OpenRegister\Service\DeckCardService;
use OCA\OpenRegister\Service\DeckPlatform;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
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
 * DeckCardServiceAppPresentTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class DeckCardServiceAppPresentTest extends TestCase {
	private DeckLinkMapper&MockObject $linkMapper;
	private IUserSession&MockObject $userSession;
	private IURLGenerator&MockObject $urlGenerator;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private DeckCardServiceDouble $cardService;
	private DeckCardMapperDouble $cardMapper;
	private DeckListingServiceDouble $boardService;
	/** @var array<string, \Throwable> container ids that throw */
	private array $unresolvable = [];
	private DeckCardService $service;

	protected function setUp(): void {
		$this->linkMapper = $this->getMockBuilder(DeckLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find', 'findByObjectUuid', 'findByBoardId', 'findByObjectAndCard', 'deleteByObjectUuid', 'insert', 'delete'])
			->getMock();
		$this->linkMapper->method('insert')->willReturnArgument(0);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->cardService = new DeckCardServiceDouble();
		$this->cardMapper = new DeckCardMapperDouble();
		$this->boardService = new DeckListingServiceDouble();
		$services = [
			'OCA\\Deck\\Service\\CardService' => $this->cardService,
			'OCA\\Deck\\Db\\CardMapper' => $this->cardMapper,
			'OCA\\Deck\\Service\\BoardService' => $this->boardService,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($services): object {
			if (isset($this->unresolvable[$id]) === true) {
				throw $this->unresolvable[$id];
			}
			return $services[$id];
		});

		$this->service = new DeckCardService(
			$this->linkMapper,
			new LinkServiceRuntime($this->createMock(IAppManager::class), $this->userSession, $this->logger),
			$this->urlGenerator,
			new DeckPlatform($this->userManager, $container)
		);
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function link(int $cardId, int $boardId = 0): DeckLink {
		$link = new DeckLink();
		$link->setCardId($cardId);
		$link->setBoardId($boardId);
		return $link;
	}

	public function testGetCardsForObjectReadsLiveCardAndResolvesUserAssigneeNames(): void {
		$this->linkMapper->method('findByObjectUuid')->willReturn([$this->link(9)]);
		$this->cardService->cards[9] = new DeckEntityDouble([
			'duedate' => new \DateTimeImmutable('2026-03-04T05:06:07+00:00'),
			'labels' => [new DeckEntityDouble(['id' => 2, 'title' => 'Bug', 'color' => '00ff00'])],
			'assignedUsers' => [
				new DeckEntityDouble(['participant' => 'bob', 'typeString' => 'user']),
				new DeckEntityDouble(['participant' => 'team', 'typeString' => 'group']),
			],
		]);
		$this->userManager->expects($this->once())->method('get')->with('bob')->willReturn(null);

		$this->service->getCardsForObject('obj-1');

		$this->assertSame([['find', 9]], $this->cardService->calls);
	}

	public function testGetCardsForObjectResolvesNoNamesWhenCardGettersFailOrReturnOddShapes(): void {
		$this->linkMapper->method('findByObjectUuid')->willReturn([$this->link(9), $this->link(10)]);
		$this->cardService->cards[9] = new DeckEntityDouble([]);
		$this->cardService->cards[10] = new DeckEntityDouble(['duedate' => '2026-01-01', 'labels' => 'x', 'assignedUsers' => null]);
		$this->userManager->expects($this->never())->method('get');

		$result = $this->service->getCardsForObject('obj-1');

		$this->assertSame(2, $result['total']);
		$this->assertSame([['find', 9], ['find', 10]], $this->cardService->calls);
	}

	public function testGetCardsForObjectUsesDefaultsForStaleOrNonObjectCards(): void {
		$this->linkMapper->method('findByObjectUuid')->willReturn([$this->link(9), $this->link(10)]);
		$this->cardService->cards[9] = new RuntimeException('deleted');
		$this->cardService->cards[10] = 'not an object';

		$rows = $this->service->getCardsForObject('obj-1')['results'];

		$this->assertSame([null, null], array_column($rows, 'dueDate'));
		$this->assertSame([['find', 9], ['find', 10]], $this->cardService->calls);
	}

	public function testGetCardsForObjectSkipsLookupForCardIdZero(): void {
		$this->linkMapper->method('findByObjectUuid')->willReturn([$this->link(0)]);

		$this->service->getCardsForObject('obj-1');

		$this->assertSame([], $this->cardService->calls);
	}

	public function testGetCardsForObjectLogsAndUsesDefaultsWhenCardServiceUnresolvable(): void {
		$this->linkMapper->method('findByObjectUuid')->willReturn([$this->link(9)]);
		$this->unresolvable['OCA\\Deck\\Service\\CardService'] = new RuntimeException('nope');
		$this->logger->expects($this->once())->method('debug')->with('Deck CardService not resolvable: nope');

		$row = $this->service->getCardsForObject('obj-1')['results'][0];

		$this->assertSame([], $row['labels']);
	}

	public function testGetCardsForObjectOmitsUrlForLinkWithoutBoard(): void {
		$this->linkMapper->method('findByObjectUuid')->willReturn([$this->link(9, 0)]);
		$this->urlGenerator->expects($this->never())->method('linkToRoute');

		$row = $this->service->getCardsForObject('obj-1')['results'][0];

		$this->assertArrayNotHasKey('url', $row);
	}

	public function testGetCardsForObjectBuildsDeepLinkWithoutDoubleSlash(): void {
		$this->linkMapper->method('findByObjectUuid')->willReturn([$this->link(9, 4)]);
		$this->urlGenerator->method('linkToRoute')->with('deck.page.index')->willReturn('/index.php/apps/deck/');

		$row = $this->service->getCardsForObject('obj-1')['results'][0];

		$this->assertSame('/index.php/apps/deck/board/4/card/9', $row['url']);
	}

	public function testDeepLinkFailureLeavesUrlOut(): void {
		$this->linkMapper->method('findByObjectUuid')->willReturn([$this->link(9, 4)]);
		$this->urlGenerator->method('linkToRoute')->willThrowException(new RuntimeException('no route'));

		$row = $this->service->getCardsForObject('obj-1')['results'][0];

		$this->assertArrayNotHasKey('url', $row);
	}

	public function testLinkOrCreateCardLinksExistingCardWithItsBoardAndStack(): void {
		$this->loginAs('alice');
		$this->cardService->cards[9] = new DeckEntityDouble(['title' => 'Existing', 'stackId' => 12]);
		$this->cardMapper->boardId = 77;
		$this->linkMapper->method('findByObjectAndCard')->with('obj-1', 9)->willReturn(null);

		$link = $this->service->linkOrCreateCard('obj-1', 3, ['cardId' => '9']);

		$this->assertSame('obj-1', $link->getObjectUuid());
		$this->assertSame(3, $link->getRegisterId());
		$this->assertSame(77, $link->getBoardId());
		$this->assertSame(12, $link->getStackId());
		$this->assertSame(9, $link->getCardId());
		$this->assertSame('Existing', $link->getCardTitle());
		$this->assertSame('alice', $link->getLinkedBy());
		$this->assertNotNull($link->getLinkedAt());
	}

	public function testLinkOrCreateCardUsesBoardZeroWhenMapperFindsNoBoard(): void {
		$this->loginAs('alice');
		$this->cardService->cards[9] = new DeckEntityDouble(['title' => 'Existing', 'stackId' => 12]);
		$this->cardMapper->boardId = null;
		$this->linkMapper->method('findByObjectAndCard')->willReturn(null);

		$this->assertSame(0, $this->service->linkOrCreateCard('obj-1', 3, ['cardId' => 9])->getBoardId());
	}

	public function testLinkOrCreateCardRefusesDuplicateLink(): void {
		$this->loginAs('alice');
		$this->cardService->cards[9] = new DeckEntityDouble(['title' => 'Existing', 'stackId' => 12]);
		$this->cardMapper->boardId = 77;
		$this->linkMapper->method('findByObjectAndCard')->willReturn(new DeckLink());
		$this->linkMapper->expects($this->never())->method('insert');

		$this->expectExceptionCode(409);
		$this->expectExceptionMessage('Card already linked to this object');
		$this->service->linkOrCreateCard('obj-1', 3, ['cardId' => 9]);
	}

	public function testLinkOrCreateCardReportsNotFoundWhenCardLookupThrows(): void {
		$this->loginAs('alice');
		$this->cardService->cards[9] = new RuntimeException('no such card');
		$this->logger->expects($this->once())->method('debug')
			->with('Deck CardService not available, card lookup skipped: no such card');

		$this->expectExceptionCode(404);
		$this->expectExceptionMessage('Deck card not found');
		$this->service->linkOrCreateCard('obj-1', 3, ['cardId' => 9]);
	}

	public function testLinkOrCreateCardCreatesCardWithObjectLinkInDescription(): void {
		$this->loginAs('alice');
		$this->cardService->createResult = new DeckEntityDouble(['id' => 31]);

		$link = $this->service->linkOrCreateCard('obj-1', 3, ['boardId' => '5', 'stackId' => '12', 'title' => 'Call back', 'description' => 'Ring them']);

		$this->assertSame(['create', 'Call back', 12, 'plain', 0, 'alice'], $this->cardService->calls[0]);
		$this->assertSame(
			['update', 31, 'Call back', 12, 'plain', 'alice', "Ring them\n\n[Object: obj-1](/apps/openregister/objects/obj-1)", 0, null],
			$this->cardService->calls[1]
		);
		$this->assertSame(31, $link->getCardId());
		$this->assertSame(5, $link->getBoardId());
		$this->assertSame(12, $link->getStackId());
		$this->assertSame('Call back', $link->getCardTitle());
	}

	public function testLinkOrCreateCardCreatesUntitledCardWithBareObjectLink(): void {
		$this->loginAs('alice');
		$this->cardService->createResult = new DeckEntityDouble(['id' => 31]);

		$link = $this->service->linkOrCreateCard('obj-1', 3, ['boardId' => 5, 'stackId' => 12]);

		$this->assertSame('Untitled', $link->getCardTitle());
		$this->assertSame('[Object: obj-1](/apps/openregister/objects/obj-1)', $this->cardService->calls[1][6]);
	}

	public function testLinkOrCreateCardFailsAndLogsWhenCreateThrows(): void {
		$this->loginAs('alice');
		$this->cardService->createResult = new RuntimeException('stack archived');
		$this->logger->expects($this->once())->method('warning')->with('Failed to create Deck card: stack archived');
		$this->linkMapper->expects($this->never())->method('insert');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Failed to create Deck card');
		$this->service->linkOrCreateCard('obj-1', 3, ['boardId' => 5, 'stackId' => 12]);
	}

	public function testLinkOrCreateCardRequiresStackAlongsideBoard(): void {
		$this->loginAs('alice');

		$this->expectExceptionMessage('Either cardId or boardId+stackId is required');
		$this->service->linkOrCreateCard('obj-1', 3, ['boardId' => 5]);
	}

	public function testGetObjectsForBoardReturnsLinksWhenDeckGrantsBoardAccess(): void {
		$link = $this->link(9, 4);
		$this->linkMapper->method('findByBoardId')->with(4)->willReturn([$link]);

		$this->assertSame([$link->jsonSerialize()], $this->service->getObjectsForBoard(4));
		$this->assertSame([4], $this->boardService->found);
	}

	public function testGetObjectsForBoardReturnsNothingWhenDeckDeniesBoardAccess(): void {
		$this->boardService->findFailure = new RuntimeException('NoPermission');
		$this->linkMapper->expects($this->never())->method('findByBoardId');

		$this->assertSame([], $this->service->getObjectsForBoard(4));
	}

	public function testGetObjectsForBoardReturnsNothingWhenBoardServiceUnresolvable(): void {
		$this->unresolvable['OCA\\Deck\\Service\\BoardService'] = new RuntimeException('gone');
		$this->linkMapper->expects($this->never())->method('findByBoardId');

		$this->assertSame([], $this->service->getObjectsForBoard(4));
	}
}
