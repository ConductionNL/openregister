<?php

/**
 * Behaviour tests for {@see \OCA\OpenRegister\Service\BookmarkLinkService}
 * when the Bookmarks app classes resolve: details fetch, cache refresh,
 * picker query building, create-and-link, tag lookup and cleaning.
 *
 * The Bookmarks app is replaced by the doubles in
 * Fixtures/BookmarksAppDoubles.php (the app is not installed in CI).
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

require_once __DIR__ . '/Fixtures/BookmarksAppDoubles.php';

use DateTime;
use Exception;
use OCA\Bookmarks\QueryParameters;
use OCA\OpenRegister\Db\BookmarkLink;
use OCA\OpenRegister\Db\BookmarkLinkMapper;
use OCA\OpenRegister\Service\BookmarkDraft;
use OCA\OpenRegister\Service\BookmarkLinkService;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\LinkTarget;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Unit\Service\Fixtures\BookmarkMapperDouble;
use Unit\Service\Fixtures\BookmarkRowDouble;
use Unit\Service\Fixtures\TagMapperDouble;

/**
 * BookmarkLinkServiceAppPresentTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class BookmarkLinkServiceAppPresentTest extends TestCase {
	private BookmarkLinkMapper&MockObject $linkMapper;
	private IAppManager&MockObject $appManager;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private ContainerInterface&MockObject $container;
	private BookmarkMapperDouble $bookmarkMapper;
	private TagMapperDouble $tagMapper;
	private bool $containerThrows = false;
	private BookmarkLinkService $service;

	protected function setUp(): void {
		$this->linkMapper = $this->getMockBuilder(BookmarkLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findByObjectUuid', 'findByObjectAndBookmark', 'deleteByObjectAndBookmark', 'insert', 'update'])
			->getMock();
		$this->linkMapper->method('insert')->willReturnArgument(0);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->bookmarkMapper = new BookmarkMapperDouble();
		$this->tagMapper = new TagMapperDouble();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(function (string $id): object {
			if ($this->containerThrows === true) {
				throw new RuntimeException('not registered');
			}
			if ($id === '\OCA\Bookmarks\Db\TagMapper') {
				return $this->tagMapper;
			}
			return $this->bookmarkMapper;
		});

		$this->service = new BookmarkLinkService(
			$this->linkMapper,
			new LinkServiceRuntime($this->appManager, $this->userSession, $this->logger),
			$this->container
		);
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function bookmark(int $id, string $owner, string $title = 'Docs', ?int $added = 1700000000): BookmarkRowDouble {
		return new BookmarkRowDouble($id, $owner, 'https://docs.test/' . $id, $title, 'desc ' . $id, $added);
	}

	private function target(): LinkTarget {
		return (new LinkTarget('obj-1', 3))->inSchema(4);
	}

	public function testLinkBookmarkCopiesOwnedBookmarkDetailsAndLooksUpCleanTags(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->linkMapper->method('findByObjectAndBookmark')->willReturn(null);
		$this->bookmarkMapper->findResult = $this->bookmark(7, 'alice');
		$this->tagMapper->findByBookmarkResult = ['or:schema', ' work ', '', 'work', 5, 'home'];

		$link = $this->service->linkBookmark($this->target(), 7);

		$this->assertSame(7, $link->getBookmarkId());
		$this->assertSame('Docs', $link->getTitle());
		$this->assertSame('https://docs.test/7', $link->getUrl());
		$this->assertSame('desc 7', $link->getDescription());
		$this->assertSame(['work', 'home'], $link->getTags());
		$this->assertSame(1700000000, $link->getAddedAt()->getTimestamp());
		$this->assertSame('obj-1', $link->getObjectUuid());
		$this->assertSame('alice', $link->getLinkedBy());
	}

	public function testLinkBookmarkLeavesAddedAtEmptyWhenBookmarkHasNoAddedTime(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->linkMapper->method('findByObjectAndBookmark')->willReturn(null);
		$this->bookmarkMapper->findResult = $this->bookmark(7, 'alice', 'Docs', 0);

		$link = $this->service->linkBookmark($this->target(), 7);

		$this->assertNull($link->getAddedAt());
	}

	public function testLinkBookmarkRefusesBookmarkOwnedBySomeoneElse(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->linkMapper->method('findByObjectAndBookmark')->willReturn(null);
		$this->bookmarkMapper->findResult = $this->bookmark(7, 'mallory');
		$this->linkMapper->expects($this->never())->method('insert');

		$this->expectExceptionCode(404);
		$this->expectExceptionMessage('Bookmark not found');
		$this->service->linkBookmark($this->target(), 7);
	}

	public function testLinkBookmarkReportsNotFoundWhenFindThrows(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->linkMapper->method('findByObjectAndBookmark')->willReturn(null);
		$this->bookmarkMapper->findResult = new RuntimeException('gone');
		$this->logger->expects($this->once())->method('debug')
			->with($this->stringContains('fetchBookmarkDetails find failed: gone'));

		$this->expectExceptionCode(404);
		$this->service->linkBookmark($this->target(), 7);
	}

	public function testLinkBookmarkReportsNotFoundWhenMapperCannotBeResolved(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->linkMapper->method('findByObjectAndBookmark')->willReturn(null);
		$this->containerThrows = true;
		$this->logger->expects($this->once())->method('debug')
			->with($this->stringContains('resolveBookmarkMapper failed: not registered'));

		$this->expectExceptionCode(404);
		$this->service->linkBookmark($this->target(), 7);
	}

	public function testGetLinkedBookmarksRefreshesOnlyStaleLinks(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$stale = new BookmarkLink();
		$stale->setBookmarkId(7);
		$stale->setTitle('Old');
		$stale->setLinkedAt((new DateTime())->setTimestamp(time() - 90000));
		$fresh = new BookmarkLink();
		$fresh->setBookmarkId(8);
		$fresh->setTitle('Fresh');
		$fresh->setLinkedAt(new DateTime());
		$this->linkMapper->method('findByObjectUuid')->with('obj-1')->willReturn([$stale, $fresh]);
		$this->bookmarkMapper->findResult = $this->bookmark(7, 'alice', 'Renamed');
		$this->linkMapper->expects($this->once())->method('update')->with($stale);

		$rows = $this->service->getLinkedBookmarks('obj-1');

		$this->assertSame('Renamed', $rows[0]['title']);
		$this->assertSame('Fresh', $rows[1]['title']);
	}

	public function testGetLinkedBookmarksRefreshesLinkWithoutLinkedAtAndLogsFailedUpdate(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$link = new BookmarkLink();
		$link->setBookmarkId(7);
		$link->setTitle('Old');
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->bookmarkMapper->findResult = $this->bookmark(7, 'alice', 'Renamed');
		$this->linkMapper->method('update')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('debug')
			->with($this->stringContains('cache refresh failed: db down'));

		$rows = $this->service->getLinkedBookmarks('obj-1');

		$this->assertSame('Renamed', $rows[0]['title']);
	}

	public function testGetLinkedBookmarksKeepsCachedRowWhenBookmarkNoLongerVisible(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$link = new BookmarkLink();
		$link->setBookmarkId(7);
		$link->setTitle('Cached');
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->bookmarkMapper->findResult = $this->bookmark(7, 'bob');
		$this->linkMapper->expects($this->never())->method('update');

		$rows = $this->service->getLinkedBookmarks('obj-1');

		$this->assertSame('Cached', $rows[0]['title']);
	}

	public function testGetLinkedBookmarksSkipsRefreshWithoutUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$link = new BookmarkLink();
		$link->setBookmarkId(7);
		$link->setTitle('Cached');
		$this->linkMapper->method('findByObjectUuid')->willReturn([$link]);
		$this->linkMapper->expects($this->never())->method('update');

		$rows = $this->service->getLinkedBookmarks('obj-1');

		$this->assertSame('Cached', $rows[0]['title']);
	}

	public function testGetAvailableBookmarksPassesSearchAndTagsAndNormalisesRows(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$plain = new stdClass();
		$plain->id = 9;
		$plain->url = 'https://plain.test';
		$plain->added = '1700000000';
		$this->bookmarkMapper->findAllResult = [$this->bookmark(7, 'alice'), $plain];
		$this->tagMapper->findByBookmarkResult = ['x'];

		$rows = $this->service->getAvailableBookmarks('docs', ['b' => 'urgent']);

		[$userId, $params] = $this->bookmarkMapper->findAllCalls[0];
		$this->assertSame('alice', $userId);
		$this->assertInstanceOf(QueryParameters::class, $params);
		$this->assertSame(['setSearch' => ['docs'], 'setTags' => ['urgent']], $params->values);
		$this->assertSame(
			['id' => 7, 'title' => 'Docs', 'url' => 'https://docs.test/7', 'description' => 'desc 7', 'tags' => ['x'], 'added' => 1700000000],
			$rows[0]
		);
		$this->assertSame(
			['id' => 9, 'title' => 'https://plain.test', 'url' => 'https://plain.test', 'description' => '', 'tags' => ['x'], 'added' => 1700000000],
			$rows[1]
		);
	}

	public function testGetAvailableBookmarksLeavesFiltersUnsetWhenNoneGiven(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$rows = $this->service->getAvailableBookmarks();

		[, $params] = $this->bookmarkMapper->findAllCalls[0];
		$this->assertSame([], $params->values);
		$this->assertSame([], $rows);
	}

	public function testGetAvailableBookmarksReturnsEmptyAndWarnsWhenQueryFails(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->bookmarkMapper->findAllResult = new RuntimeException('sql');
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('getAvailableBookmarks failed: sql'));

		$this->assertSame([], $this->service->getAvailableBookmarks('q'));
	}

	public function testGetAvailableBookmarksReturnsEmptyWhenMapperUnresolvable(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->containerThrows = true;

		$this->assertSame([], $this->service->getAvailableBookmarks('q'));
	}

	public function testNormaliseFallsBackToNoTagsWhenTagLookupThrows(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->bookmarkMapper->findAllResult = [$this->bookmark(7, 'alice')];
		$this->tagMapper->findByBookmarkResult = new RuntimeException('tags');

		$rows = $this->service->getAvailableBookmarks();

		$this->assertSame([], $rows[0]['tags']);
	}

	public function testNormaliseSkipsTagLookupForBookmarkWithoutId(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->bookmarkMapper->findAllResult = [$this->bookmark(0, 'alice')];
		$this->tagMapper->findByBookmarkResult = ['never'];

		$rows = $this->service->getAvailableBookmarks();

		$this->assertSame([], $rows[0]['tags']);
	}

	public function testCreateAndLinkBookmarkCreatesTaggedBookmarkAndLinksIt(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->bookmarkMapper->insertResult = $this->bookmark(42, 'alice');
		$this->linkMapper->method('findByObjectAndBookmark')->with('obj-1', 42)->willReturn(null);
		$draft = (new BookmarkDraft('  My page  ', ' https://p.test '))->withDetails('About', ['or:x', 'a', ' a ', 'b']);

		$link = $this->service->createAndLinkBookmark($this->target(), $draft);

		$this->assertSame(
			['setUserId' => 'alice', 'setUrl' => 'https://p.test', 'setTitle' => 'My page', 'setDescription' => 'About'],
			$this->bookmarkMapper->inserted[0]->values
		);
		$this->assertSame([[['a', 'b'], 42]], $this->tagMapper->addToCalls);
		$this->assertSame(42, $link->getBookmarkId());
		$this->assertSame('My page', $link->getTitle());
		$this->assertSame(['a', 'b'], $link->getTags());
		$this->assertSame('alice', $link->getLinkedBy());
	}

	public function testCreateAndLinkBookmarkSkipsTaggingWithoutTags(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->bookmarkMapper->insertResult = $this->bookmark(42, 'alice');
		$this->linkMapper->method('findByObjectAndBookmark')->willReturn(null);

		$link = $this->service->createAndLinkBookmark($this->target(), new BookmarkDraft('T', 'https://u'));

		$this->assertSame([], $this->tagMapper->addToCalls);
		$this->assertSame('', $link->getDescription());
	}

	public function testCreateAndLinkBookmarkReturnsExistingLinkInsteadOfDuplicating(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->bookmarkMapper->insertResult = $this->bookmark(42, 'alice');
		$existing = new BookmarkLink();
		$existing->setTitle('Already');
		$this->linkMapper->method('findByObjectAndBookmark')->willReturn($existing);
		$this->linkMapper->expects($this->never())->method('insert');

		$this->assertSame($existing, $this->service->createAndLinkBookmark($this->target(), new BookmarkDraft('T', 'https://u')));
	}

	public function testCreateAndLinkBookmarkFailsWhenCreatedBookmarkHasNoId(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->bookmarkMapper->insertResult = $this->bookmark(0, 'alice');

		$this->expectExceptionCode(500);
		$this->expectExceptionMessage('Failed to retrieve created bookmark id');
		$this->service->createAndLinkBookmark($this->target(), new BookmarkDraft('T', 'https://u'));
	}

	public function testCreateAndLinkBookmarkWrapsNonExceptionFailure(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->bookmarkMapper->insertResult = new \TypeError('bad row');
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('Failed to create bookmark: bad row'));

		$this->expectException(Exception::class);
		$this->expectExceptionCode(500);
		$this->expectExceptionMessage('Failed to create bookmark: bad row');
		$this->service->createAndLinkBookmark($this->target(), new BookmarkDraft('T', 'https://u'));
	}

	public function testCreateAndLinkBookmarkRethrowsExceptionUnchanged(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->bookmarkMapper->insertResult = new RuntimeException('duplicate url', 409);
		$this->logger->expects($this->never())->method('warning');

		$this->expectExceptionCode(409);
		$this->expectExceptionMessage('duplicate url');
		$this->service->createAndLinkBookmark($this->target(), new BookmarkDraft('T', 'https://u'));
	}

	public function testCreateAndLinkBookmarkReportsUnavailableWhenMapperUnresolvable(): void {
		$this->loginAs('alice');
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->containerThrows = true;

		$this->expectExceptionCode(503);
		$this->expectExceptionMessage('Bookmarks is not available');
		$this->service->createAndLinkBookmark($this->target(), new BookmarkDraft('T', 'https://u'));
	}
}
