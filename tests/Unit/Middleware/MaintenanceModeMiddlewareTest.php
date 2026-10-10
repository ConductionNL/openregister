<?php

/**
 * Unit tests for MaintenanceModeMiddleware — closed, but not locked.
 *
 * The lock-out is the failure that costs an afternoon: if the console is
 * refused along with everything else, the only way back is a database edit. So
 * the console-stays-reachable case is asserted first, and the refusal is
 * asserted to carry the administered message rather than a bare 503.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Middleware
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace Unit\Middleware;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\OperationsConsoleController;
use OCA\OpenRegister\Middleware\MaintenanceModeHeldException;
use OCA\OpenRegister\Middleware\MaintenanceModeMiddleware;
use OCA\OpenRegister\Service\Operations\MaintenanceModeService;
use OCP\AppFramework\Http;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MaintenanceModeMiddlewareTest extends TestCase {

	/**
	 * The middleware, over a mode that holds or does not.
	 *
	 * @param bool   $holds   Whether the instance is closed.
	 * @param string $message What readers are told.
	 *
	 * @return MaintenanceModeMiddleware The middleware under test.
	 */
	private function middleware(bool $holds, string $message = 'onderhoud tot 14:00'): MaintenanceModeMiddleware {
		$maintenance = $this->createMock(MaintenanceModeService::class);
		$maintenance->method('holds')->willReturn($holds);
		$maintenance->method('message')->willReturn($message);

		return new MaintenanceModeMiddleware($maintenance);
	}

	/**
	 * While the mode holds, an ordinary read is refused with the message.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 *
	 * @return void
	 */
	public function testAReadIsRefusedWithTheAdministeredMessage(): void {
		$refused = null;

		try {
			$this->middleware(true)->beforeController(ObjectsController::class, 'index');
		} catch (MaintenanceModeHeldException $refusal) {
			$refused = $refusal;
		}

		$this->assertNotNull($refused, 'An ordinary read went through a closed instance.');
		$this->assertSame('onderhoud tot 14:00', $refused->getMessage());
	}

	/**
	 * The console stays reachable, which is what makes leaving the mode
	 * possible at all.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 *
	 * @return void
	 */
	public function testTheOperationsConsoleIsNotClosedByTheModeItControls(): void {
		$this->middleware(true)->beforeController(OperationsConsoleController::class, 'index');

		$this->addToAssertionCount(1);
	}

	/**
	 * Every controller that serves a route under /api/operations stays
	 * reachable while the mode holds, read from appinfo/routes.php.
	 *
	 * The allowance used to name one class. When the maintenance and
	 * consistency endpoints moved to their own controllers (bd7fe3470d) the
	 * allowance did not follow, so entering the mode worked and leaving it
	 * answered 503: the lock-out this middleware exists to prevent. Reading the
	 * routes rather than a list written here is what keeps the next split from
	 * doing the same.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 *
	 * @return void
	 */
	public function testEveryOperationsRouteStaysReachable(): void {
		$routes = require __DIR__.'/../../../appinfo/routes.php';

		$controllers = [];
		foreach ($routes['routes'] as $route) {
			if (str_starts_with($route['url'], '/api/operations/') === false) {
				continue;
			}

			[$short, $method] = explode('#', $route['name']);
			$class            = 'OCA\\OpenRegister\\Controller\\'.ucfirst($short).'Controller';
			$controllers[$class.'::'.$method] = [$class, $method];
		}

		$this->assertArrayHasKey(
			'OCA\\OpenRegister\\Controller\\OperationsMaintenanceController::maintenance',
			$controllers,
			'The routes no longer carry the maintenance endpoint this test is about.'
		);

		$refused = [];
		foreach ($controllers as $key => [$class, $method]) {
			try {
				$this->middleware(true)->beforeController($class, $method);
			} catch (MaintenanceModeHeldException) {
				$refused[] = $key;
			}
		}

		$this->assertSame([], $refused, 'These operations endpoints are closed by the mode they administer.');
	}

	/**
	 * With the mode off, nothing is refused.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 *
	 * @return void
	 */
	public function testAnOpenInstanceRefusesNothing(): void {
		$this->middleware(false)->beforeController(ObjectsController::class, 'index');

		$this->addToAssertionCount(1);
	}

	/**
	 * The refusal reaches the reader as a 503 naming the message.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 *
	 * @return void
	 */
	public function testTheRefusalIsA503CarryingTheMessage(): void {
		$response = $this->middleware(true)->afterException(
			ObjectsController::class,
			'index',
			new MaintenanceModeHeldException('onderhoud tot 14:00')
		);

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('maintenance-mode', $response->getData()['error']);
		$this->assertSame('onderhoud tot 14:00', $response->getData()['message']);
	}

	/**
	 * Any other exception passes through untouched: a middleware that
	 * swallowed them would turn every failure into a maintenance notice.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 *
	 * @return void
	 */
	public function testSomebodyElsesExceptionIsNotClaimed(): void {
		$this->expectException(RuntimeException::class);

		$this->middleware(true)->afterException(
			ObjectsController::class,
			'index',
			new RuntimeException('something else entirely')
		);
	}
}
