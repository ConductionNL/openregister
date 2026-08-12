<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\CollectiveLinkService}.
 *
 * Exercises the Tier-2 service contract (link / createAndLink / unlink /
 * list + available picker + collectives cascade) against a mocked
 * CollectiveLinkMapper. Tests that touch NC Collectives'
 * `CollectiveService` / `PageService` use the "Collectives unavailable"
 * path because those classes are resolved from the container and aren't
 * injectable into this unit test scope without the `collectives` app on
 * the classpath. Real round-trips are gated by
 * `@group requires-app-collectives`.
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
 * @spec openspec/changes/integration-collectives/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\CollectiveLink;
use OCA\OpenRegister\Db\CollectiveLinkMapper;
use OCA\OpenRegister\Service\CollectiveLinkService;
use OCP\App\IAppManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * CollectiveLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @group requires-app-collectives
 */
class CollectiveLinkServiceTest extends TestCase {

	private CollectiveLinkMapper&MockObject $mapper;

	private ContainerInterface&MockObject $container;

	private IAppManager&MockObject $appManager;

	private IUserSession&MockObject $userSession;

	private IURLGenerator&MockObject $urlGenerator;

	private LoggerInterface&MockObject $logger;

	private CollectiveLinkService $service;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(CollectiveLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(
				[
					'findByObjectUuid',
					'findByObjectAndPage',
					'deleteByObjectAndPage',
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

		$this->urlGenerator->method('linkToRoute')->willReturn('/index.php/apps/collectives/');

		$this->service = new CollectiveLinkService(
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

	public function testIsCollectivesAvailableTrue(): void {
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(true);
		$this->assertTrue($this->service->isCollectivesAvailable());
	}//end testIsCollectivesAvailableTrue()

	public function testIsCollectivesAvailableFalse(): void {
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(false);
		$this->assertFalse($this->service->isCollectivesAvailable());
	}//end testIsCollectivesAvailableFalse()

	public function testLinkPageThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->linkPage('abc-123', 1, 2, 99);
	}//end testLinkPageThrowsWhenNoUser()

	public function testLinkPageThrowsWhenCollectivesUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);

		$this->service->linkPage('abc-123', 1, 2, 99);
	}//end testLinkPageThrowsWhenCollectivesUnavailable()

	public function testLinkPageThrowsOnDuplicate(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->mapper->method('findByObjectAndPage')->with('abc-123', 99)->willReturn(new CollectiveLink());

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);
		$this->expectExceptionMessage('Page already linked to this object');

		$this->service->linkPage('abc-123', 1, 2, 99);
	}//end testLinkPageThrowsOnDuplicate()

	public function testCreateAndLinkPageThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->createAndLinkPage('abc-123', 1, 2, 5, 'Runbook');
	}//end testCreateAndLinkPageThrowsWhenNoUser()

	public function testCreateAndLinkPageThrowsOnEmptyTitle(): void {
		$this->setupUser();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);

		$this->service->createAndLinkPage('abc-123', 1, 2, 5, '   ');
	}//end testCreateAndLinkPageThrowsOnEmptyTitle()

	public function testCreateAndLinkPageThrowsOnMissingCollective(): void {
		$this->setupUser();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);

		$this->service->createAndLinkPage('abc-123', 1, 2, 0, 'Runbook');
	}//end testCreateAndLinkPageThrowsOnMissingCollective()

	public function testCreateAndLinkPageThrowsWhenCollectivesUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);

		$this->service->createAndLinkPage('abc-123', 1, 2, 5, 'Runbook');
	}//end testCreateAndLinkPageThrowsWhenCollectivesUnavailable()

	public function testUnlinkPageThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->unlinkPage('abc-123', 99);
	}//end testUnlinkPageThrowsWhenNoUser()

	public function testUnlinkPageThrowsWhenLinkMissing(): void {
		$this->setupUser();
		$this->mapper->method('deleteByObjectAndPage')->with('abc-123', 99)->willReturn(0);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);

		$this->service->unlinkPage('abc-123', 99);
	}//end testUnlinkPageThrowsWhenLinkMissing()

	public function testUnlinkPageSucceeds(): void {
		$this->setupUser();
		$this->mapper->expects($this->once())
			->method('deleteByObjectAndPage')
			->with('abc-123', 99)
			->willReturn(1);

		$this->service->unlinkPage('abc-123', 99);
	}//end testUnlinkPageSucceeds()

	public function testGetLinkedPagesReturnsRows(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$link = new CollectiveLink();
		$link->setObjectUuid('abc-123');
		$link->setPageId(42);
		$link->setPageTitle('Runbook');
		$link->setCollectiveName('Ops');
		$link->setUrl('/index.php/apps/collectives/?fileId=42');

		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

		$rows = $this->service->getLinkedPages('abc-123');

		$this->assertCount(1, $rows);
		$this->assertSame(42, $rows[0]['pageId']);
		$this->assertSame('Runbook', $rows[0]['pageTitle']);
		$this->assertSame('Ops', $rows[0]['collectiveName']);
	}//end testGetLinkedPagesReturnsRows()

	public function testGetLinkedPagesEmpty(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([]);

		$this->assertSame([], $this->service->getLinkedPages('abc-123'));
	}//end testGetLinkedPagesEmpty()

	public function testGetAvailablePagesEmptyWhenCollectivesUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(false);

		$this->assertSame([], $this->service->getAvailablePages());
	}//end testGetAvailablePagesEmptyWhenCollectivesUnavailable()

	public function testGetAvailablePagesEmptyWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(true);

		$this->assertSame([], $this->service->getAvailablePages());
	}//end testGetAvailablePagesEmptyWhenNoUser()

	public function testGetAvailableCollectivesEmptyWhenCollectivesUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(false);

		$this->assertSame([], $this->service->getAvailableCollectives());
	}//end testGetAvailableCollectivesEmptyWhenCollectivesUnavailable()

	public function testGetAvailableCollectivesEmptyWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->appManager->method('isEnabledForUser')->with('collectives')->willReturn(true);

		$this->assertSame([], $this->service->getAvailableCollectives());
	}//end testGetAvailableCollectivesEmptyWhenNoUser()
}//end class
