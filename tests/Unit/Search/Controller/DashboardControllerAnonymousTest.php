<?php

/**
 * DashboardController refuses analytics to a caller with no session.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Controller;

use OCA\OpenRegister\Controller\Support\ControllerSession;
use OCA\OpenRegister\Search\Controller\DashboardController;
use OCA\OpenRegister\Search\Controller\Support\DashboardControllerServices;
use OCA\OpenRegister\Search\Service\DashboardService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The no-session branch of the admin gate: the existing test covers a signed-in
 * non-admin; this one covers no user at all, where the group check must not run.
 */
class DashboardControllerAnonymousTest extends TestCase {

	public function testNoSessionIsRefusedWithoutAskingTheGroupManagerOrTheService(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method('isAdmin');
		$service = $this->createMock(DashboardService::class);
		$service->expects($this->never())->method($this->anything());

		$controller = new DashboardController(
			$this->createMock(IRequest::class),
			new DashboardControllerServices($service, new ControllerSession($session, $groups, $this->createMock(LoggerInterface::class)))
		);

		$index = $controller->index();
		$calculate = $controller->calculate(1, 2);

		$this->assertSame(403, $index->getStatus());
		$this->assertSame(['error' => 'Admin privileges required'], $index->getData());
		$this->assertSame(403, $calculate->getStatus());
	}
}
