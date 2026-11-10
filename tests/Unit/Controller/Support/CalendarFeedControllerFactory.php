<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\CalendarFeedController;

/**
 * Builds CalendarFeedController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class CalendarFeedControllerFactory {
	/**
	 * The constructor arguments of CalendarFeedController.
	 *
	 * @param string $appName Ignored: the controller uses Application::APP_ID.
	 * @param IRequest $request Constructor argument.
	 * @param CalendarFeedTokenService $tokens Constructor argument.
	 * @param ObjectCalendarFeedService $feed Constructor argument.
	 * @param AppointmentAttendeeService $attendees Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IThrottler $throttler Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Calendar\CalendarFeedTokenService $tokens,
		\OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService $feed,
		\OCA\OpenRegister\Service\Calendar\AppointmentAttendeeService $attendees,
		\OCP\IUserSession $userSession,
		\OCP\Security\Bruteforce\IThrottler $throttler,
		\Psr\Log\LoggerInterface $logger,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\CalendarFeedControllerServices(
				domain: new \OCA\OpenRegister\Controller\Support\CalendarFeedDomain(
					tokens: $tokens,
					feed: $feed,
					attendees: $attendees,
					userSession: $userSession,
				),
				platform: new \OCA\OpenRegister\Controller\Support\CalendarFeedPlatform(
					throttler: $throttler,
					logger: $logger,
				),
			),
		];
	}

	/**
	 * Build CalendarFeedController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return CalendarFeedController
	 */
	public static function create(mixed ...$args): CalendarFeedController {
		return new CalendarFeedController(...self::args(...$args));
	}
}
