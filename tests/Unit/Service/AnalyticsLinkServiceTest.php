<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\AnalyticsLinkService}.
 *
 * Exercises the Tier-2 service contract (link / createAndLink / unlink /
 * list + available picker) against a mocked AnalyticsLinkMapper. Tests
 * that touch NC Analytics' `ReportService` use the "Analytics
 * unavailable" path because that class is resolved via `\OCP\Server::get()`
 * and isn't injectable into this unit-test scope without the `analytics`
 * app on the classpath. Real-ReportService round-trips are gated by
 * `@group requires-app-analytics`.
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
 * @spec openspec/changes/integration-analytics/tasks.md
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Db\AnalyticsLink;
use OCA\OpenRegister\Db\AnalyticsLinkMapper;
use OCA\OpenRegister\Service\AnalyticsLinkService;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * AnalyticsLinkServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @group requires-app-analytics
 */
class AnalyticsLinkServiceTest extends TestCase {

	private AnalyticsLinkMapper&MockObject $mapper;

	private IAppManager&MockObject $appManager;

	private IUserSession&MockObject $userSession;

	private LoggerInterface&MockObject $logger;

	private AnalyticsLinkService $service;

	protected function setUp(): void {
		$this->mapper = $this->getMockBuilder(AnalyticsLinkMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(
				[
					'findByObjectUuid',
					'findByObjectAndReport',
					'deleteByObjectAndReport',
					'insert',
					'update',
				]
			)
			->getMock();

		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new AnalyticsLinkService(
			$this->mapper,
			$this->appManager,
			$this->userSession,
			$this->logger
		);
	}//end setUp()

	private function setupUser(string $uid = 'alice'): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}//end setupUser()

	public function testIsAnalyticsAvailableTrue(): void {
		$this->appManager->method('isEnabledForUser')->with('analytics')->willReturn(true);
		$this->assertTrue($this->service->isAnalyticsAvailable());
	}//end testIsAnalyticsAvailableTrue()

	public function testIsAnalyticsAvailableFalse(): void {
		$this->appManager->method('isEnabledForUser')->with('analytics')->willReturn(false);
		$this->assertFalse($this->service->isAnalyticsAvailable());
	}//end testIsAnalyticsAvailableFalse()

	public function testLinkReportThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->linkReport('abc-123', 1, 2, 99);
	}//end testLinkReportThrowsWhenNoUser()

	public function testLinkReportThrowsWhenAnalyticsUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('analytics')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);

		$this->service->linkReport('abc-123', 1, 2, 99);
	}//end testLinkReportThrowsWhenAnalyticsUnavailable()

	public function testLinkReportThrowsOnDuplicate(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->mapper->method('findByObjectAndReport')->with('abc-123', 99)->willReturn(new AnalyticsLink());

		$this->expectException(Exception::class);
		$this->expectExceptionCode(409);
		$this->expectExceptionMessage('Report already linked to this object');

		$this->service->linkReport('abc-123', 1, 2, 99);
	}//end testLinkReportThrowsOnDuplicate()

	public function testCreateAndLinkReportThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->createAndLinkReport('abc-123', 1, 2, 'Sales');
	}//end testCreateAndLinkReportThrowsWhenNoUser()

	public function testCreateAndLinkReportThrowsOnEmptyName(): void {
		$this->setupUser();

		$this->expectException(Exception::class);
		$this->expectExceptionCode(400);

		$this->service->createAndLinkReport('abc-123', 1, 2, '   ');
	}//end testCreateAndLinkReportThrowsOnEmptyName()

	public function testCreateAndLinkReportThrowsWhenAnalyticsUnavailable(): void {
		$this->setupUser();
		$this->appManager->method('isEnabledForUser')->with('analytics')->willReturn(false);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(503);

		$this->service->createAndLinkReport('abc-123', 1, 2, 'Sales');
	}//end testCreateAndLinkReportThrowsWhenAnalyticsUnavailable()

	public function testUnlinkReportThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(401);

		$this->service->unlinkReport('abc-123', 99);
	}//end testUnlinkReportThrowsWhenNoUser()

	public function testUnlinkReportThrowsWhenLinkMissing(): void {
		$this->setupUser();
		$this->mapper->method('deleteByObjectAndReport')->with('abc-123', 99)->willReturn(0);

		$this->expectException(Exception::class);
		$this->expectExceptionCode(404);

		$this->service->unlinkReport('abc-123', 99);
	}//end testUnlinkReportThrowsWhenLinkMissing()

	public function testUnlinkReportSucceeds(): void {
		$this->setupUser();
		$this->mapper->expects($this->once())
			->method('deleteByObjectAndReport')
			->with('abc-123', 99)
			->willReturn(1);

		$this->service->unlinkReport('abc-123', 99);
	}//end testUnlinkReportSucceeds()

	public function testGetLinkedReportsReturnsRowsWithDeepLink(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$link = new AnalyticsLink();
		$link->setObjectUuid('abc-123');
		$link->setReportId(42);
		$link->setReportTitle('Sales');

		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([$link]);

		$rows = $this->service->getLinkedReports('abc-123');

		$this->assertCount(1, $rows);
		$this->assertSame(42, $rows[0]['reportId']);
		$this->assertSame('Sales', $rows[0]['reportTitle']);
		$this->assertStringContainsString('/r/42', $rows[0]['url']);
	}//end testGetLinkedReportsReturnsRowsWithDeepLink()

	public function testGetLinkedReportsEmpty(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->mapper->method('findByObjectUuid')->with('abc-123')->willReturn([]);

		$this->assertSame([], $this->service->getLinkedReports('abc-123'));
	}//end testGetLinkedReportsEmpty()

	public function testGetAvailableReportsEmptyWhenAnalyticsUnavailable(): void {
		$this->appManager->method('isEnabledForUser')->with('analytics')->willReturn(false);

		$this->assertSame([], $this->service->getAvailableReports());
	}//end testGetAvailableReportsEmptyWhenAnalyticsUnavailable()
}//end class
