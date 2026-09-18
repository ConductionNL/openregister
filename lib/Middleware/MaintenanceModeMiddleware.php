<?php

/**
 * MaintenanceModeMiddleware: refuses reads and writes while the mode holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCA\OpenRegister\Controller\OperationsConsoleController;
use OCA\OpenRegister\Service\Operations\MaintenanceModeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use Throwable;

/**
 * While maintenance mode holds, every controller but the console is refused.
 *
 * D-6 has two halves and the second is the one that gets dropped: the mode
 * must leave the administration surface reachable, because an administrator
 * who cannot reach the console cannot leave the mode, and then the only way
 * out is a database edit. So the allowance is not "administrators may read" —
 * an administrator browsing objects during maintenance is exactly what the
 * mode is for — it is "the console is always reachable", named by class.
 *
 * The refusal carries the administered message, so a user who runs into a
 * closed instance is told why rather than shown a bare 503.
 *
 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
 */
class MaintenanceModeMiddleware extends Middleware {

	/**
	 * The controllers that stay reachable while the mode holds.
	 *
	 * @var array<int, string>
	 */
	private const ALWAYS_REACHABLE = [OperationsConsoleController::class];

	/**
	 * Constructor.
	 *
	 * @param MaintenanceModeService $maintenance The mode.
	 */
	public function __construct(private readonly MaintenanceModeService $maintenance) {
	}//end __construct()

	/**
	 * Refuse the request when the instance is closed.
	 *
	 * @param Controller|string $controller The controller about to run.
	 * @param string            $methodName The method about to run.
	 *
	 * @return void
	 *
	 * @throws MaintenanceModeHeldException When the instance is closed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The method is part of the
	 * Middleware contract; the mode closes a controller, not a verb.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 */
	public function beforeController(Controller|string $controller, string $methodName): void {
		if ($this->reachableAnyway(controller: $controller) === true) {
			return;
		}

		try {
			$holds = $this->maintenance->holds();
		} catch (Throwable $exception) {
			// The mode is held in app configuration. If that cannot be read,
			// the instance is not closed: failing shut here would close every
			// register on a configuration hiccup.
			return;
		}

		if ($holds === false) {
			return;
		}

		throw new MaintenanceModeHeldException(message: $this->maintenance->message());

	}//end beforeController()

	/**
	 * Turn the refusal into the response the reader gets.
	 *
	 * @param Controller|string $controller The controller that was refused.
	 * @param string            $methodName The method that was refused.
	 * @param \Exception        $exception  The refusal.
	 *
	 * @return JSONResponse The 503 carrying the message.
	 *
	 * @throws \Exception Anything that is not this middleware's refusal.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Part of the contract.
	 *
	 * @spec openspec/changes/admin-operations-console/specs/operations-console/spec.md#requirement-maintenance-mode-closes-the-instance-without-locking-administration-out-req-aoc-006
	 */
	public function afterException(Controller|string $controller, string $methodName, \Exception $exception): JSONResponse {
		if (($exception instanceof MaintenanceModeHeldException) === false) {
			throw $exception;
		}

		return new JSONResponse(
			[
				'error' => 'maintenance-mode',
				'message' => $exception->getMessage(),
			],
			Http::STATUS_SERVICE_UNAVAILABLE
		);

	}//end afterException()

	/**
	 * Is this controller one the mode never closes.
	 *
	 * @param Controller|string $controller The controller.
	 *
	 * @return bool True when it stays reachable.
	 */
	private function reachableAnyway(Controller|string $controller): bool {
		$class = is_string($controller) ? $controller : $controller::class;

		return in_array($class, self::ALWAYS_REACHABLE, true);

	}//end reachableAnyway()
}//end class
