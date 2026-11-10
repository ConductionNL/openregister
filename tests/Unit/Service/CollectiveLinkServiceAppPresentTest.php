<?php

/**
 * Behaviour tests for {@see \OCA\OpenRegister\Service\CollectiveLinkService}
 * when the Collectives services resolve: the collectives and pages pickers,
 * page lookup across collectives, and the stale-link refresh.
 *
 * The Collectives app is replaced by the doubles in
 * Fixtures/CollectivesAppDoubles.php (the app is not installed in CI).
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

require_once __DIR__ . '/Fixtures/CollectivesAppDoubles.php';

use DateTime;
use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\CollectiveLinkMapper;
use OCA\OpenRegister\Service\CollectiveLinkService;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Unit\Service\Fixtures\CollectiveServiceDouble;
use Unit\Service\Fixtures\CollectivesEntityDouble;
use Unit\Service\Fixtures\PageServiceDouble;

/**
 * CollectiveLinkServiceAppPresentTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class CollectiveLinkServiceAppPresentTest extends TestCase {
	private CollectiveLinkMapper&MockObject $linkMapper;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private CollectiveServiceDouble $collectives;
	private PageServiceDouble $pages;
	/** @var array<string, string> container ids that throw, with the message */
	private array $unresolvable = [];
	private CollectiveLinkService $service;

	protected function setUp(): void {
		$this->linkMapper = $this->getMockBuilder(CollectiveLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findByObjectAndPage', 'deleteByObjectAndPage', 'insert', 'update'])
			->getMock();
		$this->linkMapper->method('insert')->willReturnArgument(0);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->with('collectives')->willReturn(true);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->with('collectives.start.index')->willReturn('/apps/collectives/');
		$this->collectives = new CollectiveServiceDouble();
		$this->pages = new PageServiceDouble();
		$services = [
			'OCA\\Collectives\\Service\\CollectiveService' => $this->collectives,
			'OCA\\Collectives\\Service\\PageService' => $this->pages,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id) use ($services): object {
			if (isset($this->unresolvable[$id]) === true) {
				throw new RuntimeException($this->unresolvable[$id]);
			}
			return $services[$id];
		});

		$this->service = new CollectiveLinkService(
			$this->linkMapper,
			$container,
			new LinkServiceRuntime($appManager, $this->userSession, $this->logger),
			$urlGenerator
		);
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function collective(int $id, string $name): CollectivesEntityDouble {
		return new CollectivesEntityDouble(['id' => $id, 'name' => $name, 'emoji' => '📚']);
	}

	private function page(int $id, string $title, array $extra = []): CollectivesEntityDouble {
		return new CollectivesEntityDouble(['id' => $id, 'title' => $title] + $extra);
	}

	public function testGetAvailableCollectivesListsCollectivesSkippingBrokenOnes(): void {
		$this->loginAs('alice');
		$this->collectives->collectives = [$this->collective(1, 'Handbook'), new CollectivesEntityDouble(['id' => 2])];

		$this->assertSame([['id' => 1, 'name' => 'Handbook', 'emoji' => '📚']], $this->service->getAvailableCollectives());
		$this->assertSame(['alice'], $this->collectives->calls);
	}

	public function testGetAvailableCollectivesReturnsEmptyAndWarnsWhenListingFails(): void {
		$this->loginAs('alice');
		$this->collectives->collectives = new RuntimeException('db');
		$this->logger->expects($this->once())->method('warning')
			->with('CollectiveLinkService::getAvailableCollectives failed: db');

		$this->assertSame([], $this->service->getAvailableCollectives());
	}

	public function testGetAvailableCollectivesReturnsEmptyWhenServiceUnresolvable(): void {
		$this->loginAs('alice');
		$this->unresolvable['OCA\\Collectives\\Service\\CollectiveService'] = 'gone';
		$this->logger->expects($this->once())->method('debug')
			->with('CollectiveLinkService: OCA\\Collectives\\Service\\CollectiveService unavailable: gone');

		$this->assertSame([], $this->service->getAvailableCollectives());
	}

	public function testGetAvailablePagesFiltersByTitleAcrossCollectives(): void {
		$this->loginAs('alice');
		$this->collectives->collectives = [$this->collective(1, 'Handbook'), $this->collective(2, 'Broken')];
		$this->pages->pagesByCollective[1] = [
			$this->page(10, 'Onboarding Guide', ['timestamp' => 1700000000, 'emoji' => '👋']),
			$this->page(11, 'Holidays'),
			new CollectivesEntityDouble(['title' => 'No id']),
		];
		$this->pages->pagesByCollective[2] = new RuntimeException('no access');

		$rows = $this->service->getAvailablePages('guide');

		$this->assertSame(
			[[
				'pageId' => 10,
				'collectiveId' => 1,
				'collectiveName' => 'Handbook',
				'title' => 'Onboarding Guide',
				'emoji' => '👋',
				'lastModified' => (new DateTime())->setTimestamp(1700000000)->format(DateTime::ATOM),
				'url' => '/apps/collectives/?fileId=10',
			]],
			$rows
		);
	}

	public function testGetAvailablePagesWithoutSearchListsEveryPageAndNullsMissingMetadata(): void {
		$this->loginAs('alice');
		$this->collectives->collectives = [$this->collective(1, 'Handbook')];
		$this->pages->pagesByCollective[1] = [$this->page(11, 'Holidays', ['timestamp' => 0])];

		$rows = $this->service->getAvailablePages(null);

		$this->assertCount(1, $rows);
		$this->assertNull($rows[0]['emoji']);
		$this->assertNull($rows[0]['lastModified']);
	}

	public function testGetAvailablePagesNullsLastModifiedWhenTimestampUnreadable(): void {
		$this->loginAs('alice');
		$this->collectives->collectives = [$this->collective(1, 'Handbook')];
		$this->pages->pagesByCollective[1] = [$this->page(11, 'Holidays')];

		$this->assertNull($this->service->getAvailablePages('')[0]['lastModified']);
	}

	public function testGetAvailablePagesReturnsEmptyAndWarnsWhenListingFails(): void {
		$this->loginAs('alice');
		$this->collectives->collectives = new RuntimeException('db');
		$this->logger->expects($this->once())->method('warning')
			->with('CollectiveLinkService::getAvailablePages failed: db');

		$this->assertSame([], $this->service->getAvailablePages('x'));
	}

	public function testGetAvailablePagesReturnsEmptyWhenPageServiceUnresolvable(): void {
		$this->loginAs('alice');
		$this->unresolvable['OCA\\Collectives\\Service\\PageService'] = 'gone';

		$this->assertSame([], $this->service->getAvailablePages('x'));
		$this->assertSame([], $this->collectives->calls);
	}

	public function testLinkPageFindsThePageInTheCollectiveThatHoldsIt(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndPage')->willReturn(null);
		$this->collectives->collectives = [$this->collective(1, 'Other'), $this->collective(2, 'Handbook')];
		$this->pages->pageByKey['2/10'] = $this->page(10, 'Guide', ['timestamp' => 1700000000, 'emoji' => '👋', 'slug' => 'guide']);

		$link = $this->service->linkPage((new LinkTarget('obj-1', 3))->inSchema(4), 10);

		$this->assertSame(2, $link->getCollectiveId());
		$this->assertSame('Handbook', $link->getCollectiveName());
		$this->assertSame('Guide', $link->getPageTitle());
		$this->assertSame('guide', $link->getSlug());
		$this->assertSame('👋', $link->getEmoji());
		$this->assertSame(1700000000, $link->getLastModified()->getTimestamp());
		$this->assertSame('/apps/collectives/?fileId=10', $link->getUrl());
	}

	public function testLinkPageNullsMetadataThePageCannotProvide(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndPage')->willReturn(null);
		$this->collectives->collectives = [$this->collective(2, 'Handbook')];
		$this->pages->pageByKey['2/10'] = $this->page(10, 'Guide', ['timestamp' => -5]);

		$link = $this->service->linkPage((new LinkTarget('obj-1', 3))->inSchema(4), 10);

		$this->assertNull($link->getSlug());
		$this->assertNull($link->getEmoji());
		$this->assertNull($link->getLastModified());
	}

	public function testLinkPageReportsNotFoundWhenNoCollectiveHoldsThePage(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndPage')->willReturn(null);
		$this->collectives->collectives = [$this->collective(1, 'Other')];
		$this->linkMapper->expects($this->never())->method('insert');

		$this->expectExceptionCode(404);
		$this->service->linkPage((new LinkTarget('obj-1', 3))->inSchema(4), 10);
	}

	public function testLinkPageReportsNotFoundWhenCollectivesCannotBeListed(): void {
		$this->loginAs('alice');
		$this->linkMapper->method('findByObjectAndPage')->willReturn(null);
		$this->collectives->collectives = new RuntimeException('db');
		$this->logger->expects($this->once())->method('debug')
			->with('CollectiveLinkService::fetchPageInfo getCollectives failed: db');

		$this->expectExceptionCode(404);
		$this->service->linkPage((new LinkTarget('obj-1', 3))->inSchema(4), 10);
	}

	public function testGetLinkedPagesRefreshesStaleLinkFromThePage(): void {
		$this->loginAs('alice');
		$link = new CollectiveLink();
		$link->setPageId(10);
		$link->setCollectiveName('Old name');
		$link->setPageTitle('Old title');
		$link->setLinkedAt((new DateTime())->setTimestamp(time() - 90000));
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->collectives->collectives = [$this->collective(2, 'Handbook')];
		$this->pages->pageByKey['2/10'] = $this->page(10, 'New title', ['slug' => 'new']);
		$this->linkMapper->expects($this->once())->method('update')->with($link)->willReturnArgument(0);

		$rows = $this->service->getLinkedPages('obj-1');

		$this->assertSame('New title', $rows[0]['pageTitle']);
		$this->assertSame('Handbook', $rows[0]['collectiveName']);
		$this->assertSame('new', $rows[0]['slug']);
	}

	public function testGetLinkedPagesLeavesFreshLinkUntouched(): void {
		$this->loginAs('alice');
		$link = new CollectiveLink();
		$link->setPageId(10);
		$link->setPageTitle('Cached');
		$link->setLinkedAt(new DateTime());
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->linkMapper->expects($this->never())->method('update');

		$this->assertSame('Cached', $this->service->getLinkedPages('obj-1')[0]['pageTitle']);
		$this->assertSame([], $this->collectives->calls);
	}

	public function testGetLinkedPagesKeepsCachedLinkWhenPageIsGone(): void {
		$this->loginAs('alice');
		$link = new CollectiveLink();
		$link->setPageId(10);
		$link->setPageTitle('Cached');
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->collectives->collectives = [];
		$this->linkMapper->expects($this->never())->method('update');

		$this->assertSame('Cached', $this->service->getLinkedPages('obj-1')[0]['pageTitle']);
	}

	public function testGetLinkedPagesReturnsRefreshedLinkWhenUpdateFails(): void {
		$this->loginAs('alice');
		$link = new CollectiveLink();
		$link->setPageId(10);
		$link->setCollectiveName('Kept');
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->collectives->collectives = [new CollectivesEntityDouble(['id' => 2, 'name' => ''])];
		$this->pages->pageByKey['2/10'] = $this->page(10, 'New title');
		$this->linkMapper->method('update')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('debug')
			->with('CollectiveLinkService::refreshLink update failed: db down');

		$row = $this->service->getLinkedPages('obj-1')[0];

		$this->assertSame('New title', $row['pageTitle']);
		// An empty collective name from the live read does not wipe the cached one.
		$this->assertSame('Kept', $row['collectiveName']);
	}
}
