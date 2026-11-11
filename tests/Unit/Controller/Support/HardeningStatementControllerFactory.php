<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\HardeningStatementController;

/**
 * Builds HardeningStatementController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class HardeningStatementControllerFactory {
	/**
	 * The constructor arguments of HardeningStatementController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param StatementService $statements Constructor argument.
	 * @param ElevationService $elevation Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Hardening\StatementService $statements,
		\OCA\OpenRegister\Service\Hardening\ElevationService $elevation,
		\OCP\IUserSession $userSession,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\HardeningStatementControllerServices(
				statements: $statements,
				elevation: $elevation,
				userSession: $userSession,
			),
		];
	}//end args()

	/**
	 * Build HardeningStatementController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return HardeningStatementController
	 */
	public static function create(mixed ...$args): HardeningStatementController {
		return new HardeningStatementController(...self::args(...$args));
	}//end create()
}//end class
