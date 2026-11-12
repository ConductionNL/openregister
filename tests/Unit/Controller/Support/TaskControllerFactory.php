<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\TaskController;

/**
 * Builds TaskController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class TaskControllerFactory {
	/**
	 * The constructor arguments of TaskController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param TaskManagerInterface $tasks Constructor argument.
	 * @param TaskInboxService $inbox Constructor argument.
	 * @param TaskAuthorizationService $authorization Constructor argument.
	 * @param TaskTemporalProjection $temporal Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param TaskFormResolver $forms Constructor argument.
	 * @param TaskFormCompletion $completion Constructor argument.
	 * @param ?LoggerInterface $logger Constructor argument.
	 * @param ?IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface $tasks,
		\OCA\OpenRegister\Service\Task\TaskInboxService $inbox,
		\OCA\OpenRegister\Service\Task\TaskAuthorizationService $authorization,
		\OCA\OpenRegister\Service\Task\TaskTemporalProjection $temporal,
		\OCP\IUserSession $userSession,
		\OCA\OpenRegister\Service\Task\TaskFormResolver $forms,
		\OCA\OpenRegister\Service\Task\TaskFormCompletion $completion,
		?\Psr\Log\LoggerInterface $logger = null,
		?\OCP\IGroupManager $groupManager = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\TaskControllerServices(
				work: new \OCA\OpenRegister\Controller\Support\TaskWork(
					tasks: $tasks,
					inbox: $inbox,
					authorization: $authorization,
					temporal: $temporal,
				),
				completion: new \OCA\OpenRegister\Controller\Support\TaskCompletion(
					forms: $forms,
					completion: $completion,
				),
				platform: new \OCA\OpenRegister\Controller\Support\TaskPlatform(
					userSession: $userSession,
					logger: $logger,
					groupManager: $groupManager,
				),
			),
		];
	}

	/**
	 * Build TaskController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return TaskController
	 */
	public static function create(mixed ...$args): TaskController {
		return new TaskController(...self::args(...$args));
	}
}
