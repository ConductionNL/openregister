<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\AuditQueryController;

/**
 * Builds AuditQueryController from its services as separate arguments, wrapped into its constructor bundle.
 */
final class AuditQueryControllerFactory {
	/**
	 * The constructor arguments of AuditQueryController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param AuditQueryService $auditQueryService Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\AuditQueryService $auditQueryService,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\AuditQueryControllerServices(
				auditQueryService: $auditQueryService,
				principal: new \OCA\OpenRegister\Controller\Support\SessionPrincipal(userSession: $userSession, groupManager: $groupManager),
			),
		];
	}//end args()

	/**
	 * Build AuditQueryController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return AuditQueryController
	 */
	public static function create(mixed ...$args): AuditQueryController {
		return new AuditQueryController(...self::args(...$args));
	}//end create()
}//end class
