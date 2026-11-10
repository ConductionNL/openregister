<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\OperationsConsistencyController;

/**
 * Builds OperationsConsistencyController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class OperationsConsistencyControllerFactory {
	/**
	 * The constructor arguments of OperationsConsistencyController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param ConsistencyCheckServiceInterface $check Constructor argument.
	 * @param ConsistencyRepairServiceInterface $repair Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Operations\ConsistencyCheckServiceInterface $check,
		\OCA\OpenRegister\Service\Operations\ConsistencyRepairServiceInterface $repair,
		\OCP\IUserSession $userSession,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\OperationsConsistencyControllerServices(
				check: $check,
				repair: $repair,
				userSession: $userSession,
			),
		];
	}

	/**
	 * Build OperationsConsistencyController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return OperationsConsistencyController
	 */
	public static function create(mixed ...$args): OperationsConsistencyController {
		return new OperationsConsistencyController(...self::args(...$args));
	}
}
