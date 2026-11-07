<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\HardeningStatementController;

/**
 * Builds HardeningStatementController from its services as separate arguments.
 */
final class HardeningStatementControllerFactory {
	/**
	 * The constructor arguments of HardeningStatementController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param StatementService $statements Constructor argument.
	 * @param ElevationService $elevation Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Hardening\StatementService $statements,
		\OCA\OpenRegister\Service\Hardening\ElevationService $elevation,
		\OCP\IUserSession $userSession,
	): array {
		return [
			$appName,
			$request,
			$statements,
			$elevation,
			$userSession,
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
