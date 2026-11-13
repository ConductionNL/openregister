<?php

/**
 * ObjectDateCalendar — a read-only platform calendar of the dates objects declare.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

use OCP\Calendar\Exceptions\CalendarException;
use OCP\Calendar\ICalendarIsWritable;
use OCP\Calendar\ICreateFromString;
use OCP\Constants;

/**
 * One calendar-enabled schema that declares date kinds, or one saved calendar
 * view, as a platform calendar for one principal.
 *
 * Its events are the feed's events: they come from the same generator, run
 * as the principal through the object access path. It is read-only. The
 * events are generated from the objects, so an event a client moved would be
 * overwritten at the next read while the caseworker believed the edit held;
 * a write is therefore refused outright, never accepted.
 *
 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-the-platform-calendar-is-read-only-and-resolved-per-principal-req-pcb-002
 */
class ObjectDateCalendar implements ICreateFromString, ICalendarIsWritable {

	/**
	 * The refusal a client write receives.
	 *
	 * @var string
	 */
	public const READ_ONLY_MESSAGE = 'This calendar is generated from register objects and is read-only.';

	/**
	 * Constructor.
	 *
	 * @param ObjectDateCalendarScope $scope  What the calendar holds and for whom.
	 * @param DeclaredDateCalendars   $source Answers the calendar's events.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-the-platform-calendar-is-read-only-and-resolved-per-principal-req-pcb-002
	 */
	public function __construct(
		private readonly ObjectDateCalendarScope $scope,
		private readonly DeclaredDateCalendars $source,
	) {
	}//end __construct()

	/**
	 * The calendar key.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function getKey(): string {
		return $this->scope->key;
	}//end getKey()

	/**
	 * The calendar URI.
	 *
	 * @return string The URI.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function getUri(): string {
		return $this->scope->key;
	}//end getUri()

	/**
	 * The display name.
	 *
	 * @return string|null The name.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function getDisplayName(): ?string {
		return $this->scope->displayName;
	}//end getDisplayName()

	/**
	 * The display colour.
	 *
	 * @return string|null The CSS hex colour.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function getDisplayColor(): ?string {
		return $this->scope->color;
	}//end getDisplayColor()

	/**
	 * The events of this calendar, as the principal may list them.
	 *
	 * @param string   $pattern          The summary text pattern.
	 * @param array    $searchProperties The properties to search in (the summary is searched).
	 * @param array    $options          The options; `timerange` is read.
	 * @param int|null $limit            The page size.
	 * @param int|null $offset           The page offset.
	 *
	 * @return array The VEVENT search results.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-the-platform-calendar-is-read-only-and-resolved-per-principal-req-pcb-002
	 */
	public function search(
		string $pattern = '',
		array $searchProperties = [],
		array $options = [],
		?int $limit = null,
		?int $offset = null,
	): array {
		return $this->source->searchResults(
			scope: $this->scope,
			query: new CalendarSearchQuery(pattern: $pattern, options: $options, limit: $limit, offset: $offset)
		);
	}//end search()

	/**
	 * Read access only.
	 *
	 * @return int The permission bitmask.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-the-platform-calendar-is-read-only-and-resolved-per-principal-req-pcb-002
	 */
	public function getPermissions(): int {
		return Constants::PERMISSION_READ;
	}//end getPermissions()

	/**
	 * A generated calendar is never deleted.
	 *
	 * @return bool False.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function isDeleted(): bool {
		return false;
	}//end isDeleted()

	/**
	 * A generated calendar is not writable.
	 *
	 * @return bool False.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-the-platform-calendar-is-read-only-and-resolved-per-principal-req-pcb-002
	 */
	public function isWritable(): bool {
		return false;
	}//end isWritable()

	/**
	 * Refuse a client write.
	 *
	 * @param string $name         The object name the client chose.
	 * @param string $calendarData The iCalendar body it sent.
	 *
	 * @return void
	 *
	 * @throws CalendarException Always: the calendar is read-only.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-the-platform-calendar-is-read-only-and-resolved-per-principal-req-pcb-002
	 */
	public function createFromString(string $name, string $calendarData): void {
		throw new CalendarException(self::READ_ONLY_MESSAGE);
	}//end createFromString()

	/**
	 * Refuse a minimal client write.
	 *
	 * @param string $name         The object name the client chose.
	 * @param string $calendarData The iCalendar body it sent.
	 *
	 * @return void
	 *
	 * @throws CalendarException Always: the calendar is read-only.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-the-platform-calendar-is-read-only-and-resolved-per-principal-req-pcb-002
	 */
	public function createFromStringMinimal(string $name, string $calendarData): void {
		throw new CalendarException(self::READ_ONLY_MESSAGE);
	}//end createFromStringMinimal()
}//end class
