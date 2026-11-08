<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\EmailLinksController;

/**
 * Builds EmailLinksController from its services as separate arguments.
 */
final class EmailLinksControllerFactory {
	/**
	 * The constructor arguments of EmailLinksController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param EmailLinkServiceInterface $emailLinkService Constructor argument.
	 * @param ObjectOperationsInterface $objectService Constructor argument.
	 * @param PermissionHandlerInterface $permissions Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\EmailLinkServiceInterface $emailLinkService,
		\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface $objectService,
		\OCA\OpenRegister\Service\Object\PermissionHandlerInterface $permissions,
	): array {
		return [
			$appName,
			$request,
			$emailLinkService,
			$objectService,
			$permissions,
		];
	}//end args()

	/**
	 * Build EmailLinksController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return EmailLinksController
	 */
	public static function create(mixed ...$args): EmailLinksController {
		return new EmailLinksController(...self::args(...$args));
	}//end create()
}//end class
