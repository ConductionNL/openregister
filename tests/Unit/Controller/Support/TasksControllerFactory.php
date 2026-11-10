<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\TasksController;

/**
 * Builds TasksController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class TasksControllerFactory {
	/**
	 * The constructor arguments of TasksController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectCalendarTasksInterface $taskService Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param ?TaskInboxService $inbox Constructor argument.
	 * @param ?IUserSession $userSession Constructor argument.
	 * @param ?TaskTemporalProjection $temporal Constructor argument.
	 * @param ?IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\ObjectCalendarTasksInterface $taskService,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		?\OCA\OpenRegister\Service\Task\TaskInboxService $inbox = null,
		?\OCP\IUserSession $userSession = null,
		?\OCA\OpenRegister\Service\Task\TaskTemporalProjection $temporal = null,
		?\OCP\IGroupManager $groupManager = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\TasksControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\TasksDomain(
					taskService: $taskService,
					objectService: $objectService,
					inbox: $inbox,
					temporal: $temporal,
				),
				platform: new \OCA\OpenRegister\Controller\Support\TasksPlatform(
					userSession: $userSession,
					groupManager: $groupManager,
				),
			),
		];
	}

	/**
	 * Build TasksController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return TasksController
	 */
	public static function create(mixed ...$args): TasksController {
		return new TasksController(...self::args(...$args));
	}
}
