<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\MapLinkService}.
 *
 * Exercises the Tier-2 service contract (link / createAndLink / unlink /
 * list + available picker) against a mocked MapLinkMapper. Tests that
 * touch NC Maps' `FavoritesService` use the "Maps unavailable" path
 * because that class is resolved from the container and isn't injectable
 * into this unit test scope without the `maps` app on the classpath.
 * Real-FavoritesService round-trips are gated by `@group requires-app-maps`.
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
 * @spec openspec/changes/integration-maps/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\MapLink;
use OCA\OpenRegister\Db\MapLinkMapper;
use OCA\OpenRegister\Service\MapLinkService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * MapLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @group requires-app-maps
 */
class MapLinkServiceTest extends TestCase {

	private MapLinkMapper&MockObject $mapper;

	private ContainerInterface&MockObject $container;

	private IAppManager&MockObject $appManager;

	private IUserSession&MockObject $userSession;

	private IURLGenerator&MockObject $urlGenerator;

	private LoggerInterface&MockObject $logger;

	private MapLinkService $service;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(MapLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(
				[
					'findByObjectUuid',
					'findByObjectAndFavorite',
					'deleteByObjectAndFavorite',
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

		$this->urlGenerator->method('linkToRoute')->willReturn('/index.php/apps/maps/');

		$this->service = new MapLinkService(
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

	public function testIsMapsAvailableTrue(): void {
		$this->appManager->method('isEnabledForUser')->with('maps')->willReturn(true);
		$this->assertTrue($this->service->isMapsAvailable());
	}//end testIsMapsAvailableTrue()

	public function testIsMapsAvailableFalse(): void {
		$this->appManager->method('isEnabledForUser')->with('maps')->willReturn(false);
		$this->assertFalse($this->service->isMapsAvailable());
	}//end testIsMapsAvailableFalse()

	public function testLinkPoiThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->linkPoi('abc-123', 1, 2, 99);
	}//end testLinkPoiThrowsWhenNoUser()

	public function testLinkPoiThrowsWhenMapsUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('maps')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);

		$this->service->linkPoi('abc-123', 1, 2, 99);
	}//end testLinkPoiThrowsWhenMapsUnavailable()

	public function testLinkPoiThrowsOnDuplicate(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->mapper->method('findByObjectAndFavorite')->with('abc-123', 99)->willReturn(new MapLink());

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);
		$this->expectExceptionMessage('POI already linked to this object');

		$this->service->linkPoi('abc-123', 1, 2, 99);
	}//end testLinkPoiThrowsOnDuplicate()

	public function testCreateAndLinkPoiThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->createAndLinkPoi('abc-123', 1, 2, 'Office', 52.37, 4.89);
	}//end testCreateAndLinkPoiThrowsWhenNoUser()

	public function testCreateAndLinkPoiThrowsOnEmptyName(): void {
		$this->setupUser();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);

		$this->service->createAndLinkPoi('abc-123', 1, 2, '   ', 52.37, 4.89);
	}//end testCreateAndLinkPoiThrowsOnEmptyName()

	public function testCreateAndLinkPoiThrowsWhenMapsUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('maps')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);

		$this->service->createAndLinkPoi('abc-123', 1, 2, 'Office', 52.37, 4.89);
	}//end testCreateAndLinkPoiThrowsWhenMapsUnavailable()

	public function testUnlinkPoiThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->unlinkPoi('abc-123', 99);
	}//end testUnlinkPoiThrowsWhenNoUser()

	public function testUnlinkPoiThrowsWhenLinkMissing(): void {
		$this->setupUser();
		$this->mapper->method('deleteByObjectAndFavorite')->with('abc-123', 99)->willReturn(0);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);

		$this->service->unlinkPoi('abc-123', 99);
	}//end testUnlinkPoiThrowsWhenLinkMissing()

	public function testUnlinkPoiSucceeds(): void {
		$this->setupUser();
		$this->mapper->expects($this->once())
			->method('deleteByObjectAndFavorite')
			->with('abc-123', 99)
			->willReturn(1);

		$this->service->unlinkPoi('abc-123', 99);
	}//end testUnlinkPoiSucceeds()

	public function testGetLinkedPoisReturnsRowsWithDeepLink(): void {
		$link = new MapLink();
		$link->setObjectUuid('abc-123');
		$link->setFavoriteId(42);
		$link->setName('Office');
		$link->setCategory('Work');
		$link->setLat(52.37);
		$link->setLng(4.89);

		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

		$rows = $this->service->getLinkedPois('abc-123');

		$this->assertCount(1, $rows);
		$this->assertSame(42, $rows[0]['favoriteId']);
		$this->assertSame('Office', $rows[0]['name']);
		$this->assertSame('Work', $rows[0]['category']);
		// poiDeepLink() deep-links to the map coordinates (#map=16/lat/lng),
		// not a marker-specific fragment — see MapLinkService::poiDeepLink().
		$this->assertStringContainsString('#map=16/52.37/4.89', $rows[0]['url']);
	}//end testGetLinkedPoisReturnsRowsWithDeepLink()

	public function testGetLinkedPoisEmpty(): void {
		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([]);

		$this->assertSame([], $this->service->getLinkedPois('abc-123'));
	}//end testGetLinkedPoisEmpty()

	public function testGetAvailablePoisEmptyWhenMapsUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('maps')->willReturn(false);

		$this->assertSame([], $this->service->getAvailablePois());
	}//end testGetAvailablePoisEmptyWhenMapsUnavailable()

	public function testGetAvailablePoisEmptyWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appManager->method('isEnabledForUser')->with('maps')->willReturn(true);

		$this->assertSame([], $this->service->getAvailablePois());
	}//end testGetAvailablePoisEmptyWhenNoUser()
}//end class
