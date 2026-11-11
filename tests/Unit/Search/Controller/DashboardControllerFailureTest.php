<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Controller;

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Search\Controller\DashboardController;
use OCA\OpenRegister\Search\Controller\Support\DashboardControllerServices;
use OCA\OpenRegister\Search\Service\DashboardAuditReads;
use OCA\OpenRegister\Search\Service\DashboardService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The failure answers of the audit analytics: an unreadable date and a failing most-active read.
 */
class DashboardControllerFailureTest extends TestCase {

	/**
	 * A controller for a signed-in administrator.
	 *
	 * @param DashboardAuditReads $reads  The audit reads.
	 * @param LoggerInterface     $logger The logger.
	 *
	 * @return DashboardController
	 */
	private function controller(DashboardAuditReads $reads, LoggerInterface $logger): DashboardController {
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($admin);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$service = $this->createMock(DashboardService::class);
		$service->method('auditReads')->willReturn($reads);

		return new DashboardController(
			$this->createMock(IRequest::class),
			new DashboardControllerServices($service, new ControllerSession($session, $groups, $logger))
		);
	}//end controller()

	public function testAnUnreadableDateIs500WithItsMessageAndNothingIsRead(): void {
		$reads = $this->createMock(DashboardAuditReads::class);
		$reads->expects($this->never())->method('getAuditTrailActionChartData');

		$response = $this->controller($reads, $this->createMock(LoggerInterface::class))->getAuditTrailActionChart('not a date');

		$this->assertSame(500, $response->getStatus());
		$this->assertStringContainsString('not a date', $response->getData()['error']);
	}

	public function testAFailingMostActiveReadIsLoggedWithItsWindowAndAnswered500(): void {
		$reads = $this->createMock(DashboardAuditReads::class);
		$reads->method('getMostActiveObjects')->willThrowException(new \RuntimeException('boom'));
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->willReturnCallback(
			function (string $message, array $context) use (&$logged): void {
				$logged = ['message' => $message] + array_intersect_key($context, array_flip(['register_id', 'schema_id', 'limit', 'hours']));
			}
		);

		$response = $this->controller($reads, $logger)->getMostActiveObjects(3, 4, 5, 6);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve most active objects: boom'], $response->getData());
		$this->assertSame(
			[
				'message' => '[DashboardController] Error retrieving most active objects: boom',
				'register_id' => 3,
				'schema_id' => 4,
				'limit' => 5,
				'hours' => 6,
			],
			$logged
		);
	}
}//end class
