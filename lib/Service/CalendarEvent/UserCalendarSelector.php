<?php

/**
 * UserCalendarSelector
 *
 * Chooses, and pins, the calendar OpenRegister writes a user's events to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\CalendarEvent
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\CalendarEvent;

use Exception;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Selects the user's VEVENT calendar, honouring and maintaining the pinned URI.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\CalendarEvent
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
final class UserCalendarSelector {

	/**
	 * NC app id used for IConfig user-value persistence.
	 *
	 * @var string
	 */
	private const APP_NAME = 'openregister';

	/**
	 * IConfig user-value key that pins the calendar URI chosen on the first write.
	 *
	 * @var string
	 */
	private const CONFIG_CALENDAR_URI = 'events_calendar_uri';

	/**
	 * Config for user-scoped key/value persistence.
	 *
	 * @var IConfig
	 */
	private readonly IConfig $config;

	/**
	 * Logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IConfig         $config NC config (user-value store)
	 * @param LoggerInterface $logger Logger
	 *
	 * @return void
	 */
	public function __construct(IConfig $config, LoggerInterface $logger) {
		$this->config = $config;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * The calendar to target among the user's calendars: the pinned one, else the default, which is then pinned.
	 *
	 * @param string $userId    The user id.
	 * @param array  $calendars The user's calendar rows.
	 *
	 * @return array Calendar data with 'id' and 'uri' keys.
	 *
	 * @throws Exception If no calendar supports VEVENT.
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function select(string $userId, array $calendars): array {
		return $this->pinnedCalendar(userId: $userId, calendars: $calendars)
			?? $this->repinnedCalendar(userId: $userId, calendars: $calendars);
	}//end select()

	/**
	 * Choose the default VEVENT calendar and pin it for the user.
	 *
	 * @param string $userId    The user id.
	 * @param array  $calendars The user's calendar rows.
	 *
	 * @return array Calendar data with 'id' and 'uri' keys.
	 *
	 * @throws Exception If no calendar supports VEVENT.
	 */
	private function repinnedCalendar(string $userId, array $calendars): array {
		$chosen = $this->defaultVeventCalendar(calendars: $calendars);
		if ($chosen === null) {
			throw new Exception('No VEVENT-supporting calendar found for user ' . $userId);
		}

		$this->pinCalendar(userId: $userId, calendar: $chosen);

		return $this->calendarRef(calendar: $chosen);
	}//end repinnedCalendar()

	/**
	 * The calendar the user's pin names, when it still exists and supports VEVENT.
	 *
	 * A stale pin (calendar gone / no longer VEVENT) is logged so admins can
	 * see the migration, and the caller redetects and repins.
	 *
	 * @param string $userId    The user id.
	 * @param array  $calendars The user's calendar rows.
	 *
	 * @return array|null Calendar data with 'id' and 'uri' keys, or null without a usable pin.
	 */
	private function pinnedCalendar(string $userId, array $calendars): ?array {
		$pinnedUri = $this->config->getUserValue($userId, self::APP_NAME, self::CONFIG_CALENDAR_URI, '');
		if ($pinnedUri === '') {
			return null;
		}

		foreach ($calendars as $calendar) {
			if ($this->isVeventCalendarAt(calendar: $calendar, uri: $pinnedUri) === true) {
				return $this->calendarRef(calendar: $calendar);
			}
		}

		$this->logger->info('Pinned calendar URI ' . $pinnedUri . ' no longer resolves for user ' . $userId . '; reselecting');

		return null;
	}//end pinnedCalendar()

	/**
	 * Whether a calendar row has the given URI and supports VEVENT.
	 *
	 * @param array  $calendar The calendar row.
	 * @param string $uri      The URI it must have.
	 *
	 * @return bool
	 */
	private function isVeventCalendarAt(array $calendar, string $uri): bool {
		return ($calendar['uri'] ?? null) === $uri && $this->calendarSupportsVevent(calendar: $calendar) === true;
	}//end isVeventCalendarAt()

	/**
	 * The calendar to pin when there is no usable pin.
	 *
	 * Prefers `personal` when present so the pin lands on the user's default
	 * calendar rather than on whichever calendar happens to be first in the row
	 * order (`contact_birthdays`, …).
	 *
	 * @param array $calendars The user's calendar rows.
	 *
	 * @return array|null The calendar row, or null when none supports VEVENT.
	 */
	private function defaultVeventCalendar(array $calendars): ?array {
		$veventCalendars = array_values(
			array_filter($calendars, fn (array $calendar): bool => $this->calendarSupportsVevent(calendar: $calendar))
		);
		foreach ($veventCalendars as $calendar) {
			if (($calendar['uri'] ?? null) === 'personal') {
				return $calendar;
			}
		}

		return ($veventCalendars[0] ?? null);
	}//end defaultVeventCalendar()

	/**
	 * Persist the chosen calendar as the user's pin, best-effort.
	 *
	 * A missing pin only means the next call repeats this selection, which is
	 * not a fatal error for the current operation.
	 *
	 * @param string $userId   The user id.
	 * @param array  $calendar The chosen calendar row.
	 *
	 * @return void
	 */
	private function pinCalendar(string $userId, array $calendar): void {
		try {
			$this->config->setUserValue($userId, self::APP_NAME, self::CONFIG_CALENDAR_URI, (string)$calendar['uri']);
		} catch (Exception $e) {
			$this->logger->warning(
				'Failed to persist calendar URI pin: ' . $e->getMessage(),
				['userId' => $userId, 'uri' => $calendar['uri'] ?? null]
			);
		}
	}//end pinCalendar()

	/**
	 * The id and uri of a calendar row.
	 *
	 * @param array $calendar The calendar row.
	 *
	 * @return array{id: mixed, uri: mixed}
	 */
	private function calendarRef(array $calendar): array {
		return [
			'id' => $calendar['id'],
			'uri' => $calendar['uri'],
		];
	}//end calendarRef()

	/**
	 * Inspect a calendar row from CalDavBackend and decide whether it
	 * supports VEVENT.
	 *
	 * @param array $calendar Row returned by CalDavBackend::getCalendarsForUser().
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function calendarSupportsVevent(array $calendar): bool {
		$components = $calendar['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] ?? null;
		if (is_string($components) === true) {
			return stripos($components, 'VEVENT') !== false;
		}

		return $this->listsVevent(components: $this->componentNames(components: $components));
	}//end calendarSupportsVevent()

	/**
	 * The component names of a non-string supported-calendar-component-set value.
	 *
	 * @param mixed $components Sabre's property (exposing getValue()), a list, or anything else.
	 *
	 * @return iterable The names; empty for a value that holds none.
	 */
	private function componentNames(mixed $components): iterable {
		if (is_object($components) === true && method_exists($components, 'getValue') === true) {
			return $components->getValue();
		}

		if (is_iterable($components) === true) {
			return $components;
		}

		return [];
	}//end componentNames()

	/**
	 * Whether a list of component names contains VEVENT, in any case.
	 *
	 * @param iterable $components The component names.
	 *
	 * @return bool
	 */
	private function listsVevent(iterable $components): bool {
		foreach ($components as $comp) {
			if (strtoupper((string)$comp) === 'VEVENT') {
				return true;
			}
		}

		return false;
	}//end listsVevent()
}//end class
