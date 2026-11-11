<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\AuditSinkController;

/**
 * Builds AuditSinkController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class AuditSinkControllerFactory {
	/**
	 * The constructor arguments of AuditSinkController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param AuditSink $sink Constructor argument.
	 * @param AuditSinkStatus $status Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Audit\AuditSink $sink,
		\OCA\OpenRegister\Service\Audit\AuditSinkStatus $status,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\AuditSinkControllerServices(
				sink: $sink,
				status: $status,
				userSession: $userSession,
				groupManager: $groupManager,
			),
		];
	}//end args()

	/**
	 * Build AuditSinkController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return AuditSinkController
	 */
	public static function create(mixed ...$args): AuditSinkController {
		return new AuditSinkController(...self::args(...$args));
	}//end create()
}//end class
