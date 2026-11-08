<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\RegisterDescriptorController;

/**
 * Builds RegisterDescriptorController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class RegisterDescriptorControllerFactory {
	/**
	 * The constructor arguments of RegisterDescriptorController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param RegisterDescriptorServiceInterface $descriptors Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\RegisterDescriptorServiceInterface $descriptors,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\RegisterDescriptorControllerServices(
				descriptors: $descriptors,
				userSession: $userSession,
				groupManager: $groupManager,
			),
		];
	}

	/**
	 * Build RegisterDescriptorController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return RegisterDescriptorController
	 */
	public static function create(mixed ...$args): RegisterDescriptorController {
		return new RegisterDescriptorController(...self::args(...$args));
	}
}
