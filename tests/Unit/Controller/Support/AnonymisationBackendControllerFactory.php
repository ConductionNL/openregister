<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\AnonymisationBackendController;

/**
 * Builds AnonymisationBackendController from its services as separate arguments.
 */
final class AnonymisationBackendControllerFactory {
	/**
	 * The constructor arguments of AnonymisationBackendController.
	 *
	 * @param mixed $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param AnonymisationBackendService $service Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		mixed $appName,
		\OCP\IRequest $request,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\OCA\OpenRegister\Service\Anonymisation\AnonymisationBackendService $service,
	): array {
		return [
			$appName,
			$request,
			$userSession,
			$groupManager,
			$service,
		];
	}//end args()

	/**
	 * Build AnonymisationBackendController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return AnonymisationBackendController
	 */
	public static function create(mixed ...$args): AnonymisationBackendController {
		return new AnonymisationBackendController(...self::args(...$args));
	}//end create()
}//end class
