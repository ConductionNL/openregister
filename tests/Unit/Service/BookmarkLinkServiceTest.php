<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\BookmarkLinkService}.
 *
 * Exercises the Tier-2 service contract (link/create/list/unlink +
 * available-bookmarks picker) against a mocked BookmarkLinkMapper.
 * Tests requiring real NC Bookmarks table data are kept in integration
 * tests (`@group requires-app-bookmarks`); unit scope here is the
 * service's branch coverage when Bookmarks is unavailable.
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
 * @spec openspec/changes/integration-bookmarks/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\BookmarkLink;
use OCA\OpenRegister\Db\BookmarkLinkMapper;
use OCA\OpenRegister\Service\BookmarkLinkService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * BookmarkLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class BookmarkLinkServiceTest extends TestCase {
	private BookmarkLinkMapper&MockObject $mapper;
	private IAppManager&MockObject $appManager;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private BookmarkLinkService $service;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(BookmarkLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods([
				'findByObjectUuid',
				'findByObjectAndBookmark',
				'deleteByObjectAndBookmark',
				'insert',
				'update',
			])
			->getMock();
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new BookmarkLinkService(
			$this->mapper,
			$this->appManager,
			$this->userSession,
			$this->logger
		);
	}

	private function setupUser(string $uid = 'admin'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testIsBookmarksAvailableTrue(): void {
		$this->appManager->method('isEnabledForUser')->with('bookmarks')->willReturn(true);
		$this->assertTrue($this->service->isBookmarksAvailable());
	}

	public function testIsBookmarksAvailableFalse(): void {
		$this->appManager->method('isEnabledForUser')->with('bookmarks')->willReturn(false);
		$this->assertFalse($this->service->isBookmarksAvailable());
	}

	public function testLinkBookmarkThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No user logged in');

		$this->service->linkBookmark('abc-123', 1, 2, 5);
	}

	public function testLinkBookmarkThrowsOnDuplicate(): void {
		$this->setupUser();
		$existing = new BookmarkLink();
		$this->mapper->method('findByObjectAndBookmark')->with('abc-123', 5)->willReturn($existing);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);
		$this->expectExceptionMessage('Bookmark already linked to this object');

		$this->service->linkBookmark('abc-123', 1, 2, 5);
	}

	public function testLinkBookmarkThrowsWhenBookmarksUnavailable(): void {
		$this->setupUser();
		$this->mapper->method('findByObjectAndBookmark')->willReturn(null);
		$this->appManager->method('isEnabledForUser')->with('bookmarks')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);
		$this->expectExceptionMessage('Bookmarks is not available');

		$this->service->linkBookmark('abc-123', 1, 2, 5);
	}

	public function testUnlinkBookmarkSucceeds(): void {
		$this->mapper->expects($this->once())
			->method('deleteByObjectAndBookmark')
			->with('abc-123', 5)
			->willReturn(1);

		$this->service->unlinkBookmark('abc-123', 5);
	}

	public function testUnlinkBookmarkNotFound(): void {
		$this->mapper->method('deleteByObjectAndBookmark')->willReturn(0);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);
		$this->expectExceptionMessage('Bookmark link not found');

		$this->service->unlinkBookmark('abc-123', 5);
	}

	public function testGetLinkedBookmarksEmpty(): void {
		$this->mapper->method('findByObjectUuid')->willReturn([]);

		$this->assertSame([], $this->service->getLinkedBookmarks('nonexistent'));
	}

	public function testGetLinkedBookmarksReturnsCachedRowsWhenBookmarksUnavailable(): void {
		$link = new BookmarkLink();
		$link->setObjectUuid('abc-123');
		$link->setBookmarkId(99);
		$link->setTitle('Conduction');
		$link->setUrl('https://conduction.nl');
		$link->setTags(['vendor']);

		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);
		// Bookmarks uninstalled — cached link row used as-is, no refresh.
		$this->appManager->method('isEnabledForUser')->with('bookmarks')->willReturn(false);

		$rows = $this->service->getLinkedBookmarks('abc-123');

		$this->assertCount(1, $rows);
		$this->assertSame('abc-123', $rows[0]['objectUuid']);
		$this->assertSame(99, $rows[0]['bookmarkId']);
		$this->assertSame('Conduction', $rows[0]['title']);
		$this->assertSame('https://conduction.nl', $rows[0]['url']);
		$this->assertSame(['vendor'], $rows[0]['tags']);
	}

	public function testCreateAndLinkBookmarkThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No user logged in');

		$this->service->createAndLinkBookmark('abc-123', 1, 2, 'T', 'https://x.nl', null, []);
	}

	public function testCreateAndLinkBookmarkThrowsWhenBookmarksUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('bookmarks')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);
		$this->expectExceptionMessage('Bookmarks is not available');

		$this->service->createAndLinkBookmark('abc-123', 1, 2, 'T', 'https://x.nl', null, []);
	}

	public function testCreateAndLinkBookmarkThrowsWhenTitleEmpty(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('bookmarks')->willReturn(true);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);
		$this->expectExceptionMessage('Title is required');

		$this->service->createAndLinkBookmark('abc-123', 1, 2, '   ', 'https://x.nl', null, []);
	}

	public function testCreateAndLinkBookmarkThrowsWhenUrlEmpty(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('bookmarks')->willReturn(true);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);
		$this->expectExceptionMessage('URL is required');

		$this->service->createAndLinkBookmark('abc-123', 1, 2, 'Title', '   ', null, []);
	}

	public function testGetAvailableBookmarksReturnsEmptyWhenBookmarksUnavailable(): void {
		$this->appManager->method('isEnabledForUser')->with('bookmarks')->willReturn(false);
		$this->assertSame([], $this->service->getAvailableBookmarks());
	}

	public function testGetAvailableBookmarksReturnsEmptyWhenNoUser(): void {
		$this->appManager->method('isEnabledForUser')->with('bookmarks')->willReturn(true);
		$this->userSession->method('getUser')->willReturn(null);
		$this->assertSame([], $this->service->getAvailableBookmarks());
	}
}
