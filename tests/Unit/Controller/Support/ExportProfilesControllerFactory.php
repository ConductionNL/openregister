<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ExportProfilesController;

/**
 * Builds ExportProfilesController from its services as separate arguments.
 */
final class ExportProfilesControllerFactory {
	/**
	 * The constructor arguments of ExportProfilesController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ExportProfileService $service Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param ExportRunRecorder $exportRuns Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Export\ExportProfileService $service,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\OCA\OpenRegister\Service\Export\ExportRunRecorder $exportRuns,
	): array {
		return [
			$appName,
			$request,
			$service,
			$userSession,
			$groupManager,
			$exportRuns,
		];
	}//end args()

	/**
	 * Build ExportProfilesController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ExportProfilesController
	 */
	public static function create(mixed ...$args): ExportProfilesController {
		return new ExportProfilesController(...self::args(...$args));
	}//end create()
}//end class
