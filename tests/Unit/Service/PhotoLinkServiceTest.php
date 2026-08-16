<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\PhotoLinkService}.
 *
 * Exercises the Tier-2 service contract (link / createAndLink / unlink /
 * list + available picker) against a mocked PhotoLinkMapper. Tests that
 * touch NC Photos' `AlbumMapper` use the "Photos unavailable" path
 * because that class is resolved from the container and isn't injectable
 * into this unit test scope without the `photos` app on the classpath.
 * Real-AlbumMapper round-trips are gated by `@group requires-app-photos`.
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
 * @spec openspec/changes/integration-photos/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\PhotoLink;
use OCA\OpenRegister\Db\PhotoLinkMapper;
use OCA\OpenRegister\Service\PhotoLinkService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * PhotoLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @group requires-app-photos
 */
class PhotoLinkServiceTest extends TestCase {

	private PhotoLinkMapper&MockObject $mapper;

	private ContainerInterface&MockObject $container;

	private IAppManager&MockObject $appManager;

	private IUserSession&MockObject $userSession;

	private IURLGenerator&MockObject $urlGenerator;

	private LoggerInterface&MockObject $logger;

	private PhotoLinkService $service;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(PhotoLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(
				[
					'findByObjectUuid',
					'findByObjectAndAlbum',
					'deleteByObjectAndAlbum',
					'insert',
					'update',
				]
			)
			->getMock();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->urlGenerator->method('linkToRoute')->willReturn('/index.php/apps/photos/');

		$this->service = new PhotoLinkService(
			$this->mapper,
			$this->container,
			$this->appManager,
			$this->userSession,
			$this->urlGenerator,
			$this->logger
		);
	}//end setUp()

	private function setupUser(string $uid = 'alice'): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}//end setupUser()

	public function testIsPhotosAvailableTrue(): void {
		$this->appManager->method('isEnabledForUser')->with('photos')->willReturn(true);
		$this->assertTrue($this->service->isPhotosAvailable());
	}//end testIsPhotosAvailableTrue()

	public function testIsPhotosAvailableFalse(): void {
		$this->appManager->method('isEnabledForUser')->with('photos')->willReturn(false);
		$this->assertFalse($this->service->isPhotosAvailable());
	}//end testIsPhotosAvailableFalse()

	public function testLinkAlbumThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->linkAlbum('abc-123', 1, 2, 99);
	}//end testLinkAlbumThrowsWhenNoUser()

	public function testLinkAlbumThrowsWhenPhotosUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('photos')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);

		$this->service->linkAlbum('abc-123', 1, 2, 99);
	}//end testLinkAlbumThrowsWhenPhotosUnavailable()

	public function testLinkAlbumThrowsOnDuplicate(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->mapper->method('findByObjectAndAlbum')->with('abc-123', 99)->willReturn(new PhotoLink());

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);
		$this->expectExceptionMessage('Album already linked to this object');

		$this->service->linkAlbum('abc-123', 1, 2, 99);
	}//end testLinkAlbumThrowsOnDuplicate()

	public function testCreateAndLinkAlbumThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->createAndLinkAlbum('abc-123', 1, 2, 'Holiday');
	}//end testCreateAndLinkAlbumThrowsWhenNoUser()

	public function testCreateAndLinkAlbumThrowsOnEmptyName(): void {
		$this->setupUser();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);

		$this->service->createAndLinkAlbum('abc-123', 1, 2, '   ');
	}//end testCreateAndLinkAlbumThrowsOnEmptyName()

	public function testCreateAndLinkAlbumThrowsWhenPhotosUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('photos')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);

		$this->service->createAndLinkAlbum('abc-123', 1, 2, 'Holiday');
	}//end testCreateAndLinkAlbumThrowsWhenPhotosUnavailable()

	public function testUnlinkAlbumThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->unlinkAlbum('abc-123', 99);
	}//end testUnlinkAlbumThrowsWhenNoUser()

	public function testUnlinkAlbumThrowsWhenLinkMissing(): void {
		$this->setupUser();
		$this->mapper->method('deleteByObjectAndAlbum')->with('abc-123', 99)->willReturn(0);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);

		$this->service->unlinkAlbum('abc-123', 99);
	}//end testUnlinkAlbumThrowsWhenLinkMissing()

	public function testUnlinkAlbumSucceeds(): void {
		$this->setupUser();
		$this->mapper->expects($this->once())
			->method('deleteByObjectAndAlbum')
			->with('abc-123', 99)
			->willReturn(1);

		$this->service->unlinkAlbum('abc-123', 99);
	}//end testUnlinkAlbumSucceeds()

	public function testGetLinkedAlbumsReturnsRowsWithDeepLink(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$link = new PhotoLink();
		$link->setObjectUuid('abc-123');
		$link->setAlbumId(42);
		$link->setAlbumName('Holiday');

		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

		$rows = $this->service->getLinkedAlbums('abc-123');

		$this->assertCount(1, $rows);
		$this->assertSame(42, $rows[0]['albumId']);
		$this->assertSame('Holiday', $rows[0]['albumName']);
		// NC Photos routes albums by NAME, not the numeric id — verified live
		// (see PhotoLinkService::albumDeepLink()): `/albums/{id}` opens an
		// empty placeholder album, `/albums/{name}` opens the real one.
		$this->assertStringContainsString('albums/Holiday', $rows[0]['url']);
	}//end testGetLinkedAlbumsReturnsRowsWithDeepLink()

	public function testGetLinkedAlbumsEmpty(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([]);

		$this->assertSame([], $this->service->getLinkedAlbums('abc-123'));
	}//end testGetLinkedAlbumsEmpty()

	public function testGetAvailableAlbumsEmptyWhenPhotosUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('photos')->willReturn(false);

		$this->assertSame([], $this->service->getAvailableAlbums());
	}//end testGetAvailableAlbumsEmptyWhenPhotosUnavailable()

	public function testGetAvailableAlbumsEmptyWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appManager->method('isEnabledForUser')->with('photos')->willReturn(true);

		$this->assertSame([], $this->service->getAvailableAlbums());
	}//end testGetAvailableAlbumsEmptyWhenNoUser()
}//end class
