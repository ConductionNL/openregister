<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\TaskEventsController;

/**
 * Builds TaskEventsController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class TaskEventsControllerFactory {
	/**
	 * The constructor arguments of TaskEventsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param TaskManagerInterface $tasks Constructor argument.
	 * @param TaskAuthorizationService $authorization Constructor argument.
	 * @param CalendarLinkService $links Constructor argument.
	 * @param ObjectCalendarEventsInterface $events Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\TaskManagerInterface $tasks,
		\OCA\OpenRegister\Service\Task\TaskAuthorizationService $authorization,
		\OCA\OpenRegister\Service\CalendarLinkService $links,
		\OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface $events,
		\OCP\IUserSession $userSession,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\TaskEventsControllerServices(
				tasks: $tasks,
				authorization: $authorization,
				links: $links,
				events: $events,
			),
			$userSession,
		];
	}

	/**
	 * Build TaskEventsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return TaskEventsController
	 */
	public static function create(mixed ...$args): TaskEventsController {
		return new TaskEventsController(...self::args(...$args));
	}
}
