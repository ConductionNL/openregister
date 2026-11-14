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
 * @spec openspec/specs/operations-console/spec.md
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
	 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
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
	 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 *
	 * @return void
	 */
	public function testTheOperationsConsoleIsNotClosedByTheModeItControls(): void {
		$refused = $this->refusalOf($this->middleware(true), OperationsConsoleController::class, 'index');

		// Pair: testAReadIsRefusedWithTheAdministeredMessage, same mode, another controller.
		$this->assertNull($refused, 'The operations console was closed by the mode it controls.');
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
	 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
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
			if ($this->refusalOf($this->middleware(true), $class, $method) !== null) {
				$refused[] = $key;
			}
		}

		$this->assertSame([], $refused, 'These operations endpoints are closed by the mode they administer.');
	}

	/**
	 * With the mode off, nothing is refused.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 *
	 * @return void
	 */
	public function testAnOpenInstanceRefusesNothing(): void {
		$refused = $this->refusalOf($this->middleware(false), ObjectsController::class, 'index');

		// Pair: testAReadIsRefusedWithTheAdministeredMessage, same read, mode held.
		$this->assertNull($refused, 'An open instance refused an ordinary read.');
	}

	/**
	 * A mode that cannot be read does not close the instance: the read goes
	 * through and the message is never asked for.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 *
	 * @return void
	 */
	public function testAnUnreadableModeDoesNotCloseTheInstance(): void {
		$maintenance = $this->createMock(MaintenanceModeService::class);
		$maintenance->expects($this->once())->method('holds')->willThrowException(new RuntimeException('config unavailable'));
		$maintenance->expects($this->never())->method('message');

		$refused = $this->refusalOf(new MaintenanceModeMiddleware($maintenance), ObjectsController::class, 'index');

		$this->assertNull($refused, 'An unreadable mode closed the instance.');
	}

	/**
	 * A controller passed as an instance is matched by its class: the console
	 * instance stays reachable, the mode is not even consulted.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 *
	 * @return void
	 */
	public function testAControllerInstanceIsMatchedByItsClass(): void {
		$maintenance = $this->createMock(MaintenanceModeService::class);
		$maintenance->expects($this->never())->method('holds');

		$console = (new \ReflectionClass(OperationsConsoleController::class))->newInstanceWithoutConstructor();
		(new MaintenanceModeMiddleware($maintenance))->beforeController($console, 'index');
	}

	/**
	 * Run beforeController and return the refusal it raised, or null.
	 *
	 * @param MaintenanceModeMiddleware $middleware The middleware under test.
	 * @param string                    $controller The controller class.
	 * @param string                    $method     The controller method.
	 *
	 * @return MaintenanceModeHeldException|null The refusal, if any.
	 */
	private function refusalOf(MaintenanceModeMiddleware $middleware, string $controller, string $method): ?MaintenanceModeHeldException {
		try {
			$middleware->beforeController($controller, $method);
		} catch (MaintenanceModeHeldException $refusal) {
			return $refusal;
		}

		return null;
	}

	/**
	 * The refusal reaches the reader as a 503 naming the message.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
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
	 * @spec openspec/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
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
