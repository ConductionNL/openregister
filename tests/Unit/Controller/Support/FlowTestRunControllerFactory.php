<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\FlowTestRunController;

/**
 * Builds FlowTestRunController from its services as separate arguments.
 */
final class FlowTestRunControllerFactory {
	/**
	 * The constructor arguments of FlowTestRunController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param FlowRunServiceInterface $runner Constructor argument.
	 * @param FlowLocatorInterface $resolvers Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param FlowRunnableGuard $guard Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Flow\FlowRunServiceInterface $runner,
		\OCA\OpenRegister\Service\Flow\FlowLocatorInterface $resolvers,
		\OCP\IUserSession $userSession,
		\OCA\OpenRegister\Service\Flow\FlowRunnableGuard $guard,
	): array {
		return [
			$appName,
			$request,
			$runner,
			$resolvers,
			$userSession,
			$guard,
		];
	}//end args()

	/**
	 * Build FlowTestRunController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return FlowTestRunController
	 */
	public static function create(mixed ...$args): FlowTestRunController {
		return new FlowTestRunController(...self::args(...$args));
	}//end create()
}//end class
