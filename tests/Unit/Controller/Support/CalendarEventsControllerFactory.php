<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\CalendarEventsController;

/**
 * Builds CalendarEventsController from its services as separate arguments.
 */
final class CalendarEventsControllerFactory {
	/**
	 * The constructor arguments of CalendarEventsController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectCalendarEventsInterface $calendarEventService Constructor argument.
	 * @param CalendarLinkServiceInterface $calendarLinkService Constructor argument.
	 * @param ObjectOperationsInterface $objectService Constructor argument.
	 * @param PermissionHandlerInterface $permissions Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface $calendarEventService,
		\OCA\OpenRegister\Service\CalendarLinkServiceInterface $calendarLinkService,
		\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface $objectService,
		\OCA\OpenRegister\Service\Object\PermissionHandlerInterface $permissions,
	): array {
		return [
			$appName,
			$request,
			$calendarEventService,
			$calendarLinkService,
			$objectService,
			$permissions,
		];
	}//end args()

	/**
	 * Build CalendarEventsController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return CalendarEventsController
	 */
	public static function create(mixed ...$args): CalendarEventsController {
		return new CalendarEventsController(...self::args(...$args));
	}//end create()
}//end class
