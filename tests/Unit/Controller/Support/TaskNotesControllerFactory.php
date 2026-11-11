<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\TaskNotesController;

/**
 * Builds TaskNotesController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class TaskNotesControllerFactory {
	/**
	 * The constructor arguments of TaskNotesController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param TaskManagerInterface $tasks Constructor argument.
	 * @param TaskAuthorizationService $authorization Constructor argument.
	 * @param NoteServiceInterface $notes Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface $tasks,
		\OCA\OpenRegister\Service\Task\TaskAuthorizationService $authorization,
		\OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface $notes,
		\OCP\IUserSession $userSession,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\TaskNotesControllerServices(
				tasks: $tasks,
				authorization: $authorization,
				notes: $notes,
				userSession: $userSession,
			),
		];
	}

	/**
	 * Build TaskNotesController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return TaskNotesController
	 */
	public static function create(mixed ...$args): TaskNotesController {
		return new TaskNotesController(...self::args(...$args));
	}
}
