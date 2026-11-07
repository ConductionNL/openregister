<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\LinkedEntityController;

/**
 * Builds LinkedEntityController from its services as separate arguments.
 */
final class LinkedEntityControllerFactory {
	/**
	 * The constructor arguments of LinkedEntityController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param LinkedEntityServiceInterface $linkedEntityService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\LinkedEntityServiceInterface $linkedEntityService,
		\Psr\Log\LoggerInterface $logger,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$appName,
			$request,
			$linkedEntityService,
			$logger,
			$userSession,
			$groupManager,
		];
	}//end args()

	/**
	 * Build LinkedEntityController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return LinkedEntityController
	 */
	public static function create(mixed ...$args): LinkedEntityController {
		return new LinkedEntityController(...self::args(...$args));
	}//end create()
}//end class
