<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\FlowTimerDiagnosticController;

/**
 * Builds FlowTimerDiagnosticController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class FlowTimerDiagnosticControllerFactory {
	/**
	 * The constructor arguments of FlowTimerDiagnosticController.
	 *
	 * @param string $appName Unused; kept so existing calls still fit.
	 * @param IRequest $request Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param TermDiagnosticInterface $diagnostic Constructor argument.
	 * @param WorkingCalendarDefinitionsInterface $calendars Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\OCA\OpenRegister\Service\Flow\Timer\TermDiagnosticInterface $diagnostic,
		\OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinitionsInterface $calendars = new \OCA\OpenRegister\Service\Flow\Timer\WorkingCalendars(),
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\FlowTimerDiagnosticControllerServices(
				userSession: $userSession,
				groupManager: $groupManager,
				diagnostic: $diagnostic,
				calendars: $calendars,
			),
		];
	}//end args()

	/**
	 * Build FlowTimerDiagnosticController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FlowTimerDiagnosticController
	 */
	public static function create(mixed ...$args): FlowTimerDiagnosticController {
		return new FlowTimerDiagnosticController(...self::args(...$args));
	}//end create()
}//end class
