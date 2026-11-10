<?php

/**
 * CalendarLinkServiceInterface: the calendar-event link operations of CalendarLinkService that controllers use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\CalendarLink;

/**
 * The methods of CalendarLinkService that callers outside the Service component use.
 */
interface CalendarLinkServiceInterface {

	/**
	 * Link an existing CalDAV event to an OR object.
	 *
	 * Writes ONLY a link-table row (not the X-OR-* properties — we do
	 * not own the VEVENT). Re-fetches event details from CalDAV to
	 * populate cached fields (summary/dtstart/dtend/location).
	 *
	 * @param string $objectUuid Object UUID.
	 * @param int $registerId Register id.
	 * @param int $schemaId Schema id.
	 * @param string $calendarUri Calendar URI (per-user CalDAV path component).
	 * @param string $eventUid VEVENT UID.
	 *
	 * @return CalendarLink The persisted link row.
	 *
	 * @throws Exception When no user is logged in or the event cannot be located.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function linkEvent(
		string $objectUuid,
		int $registerId,
		int $schemaId,
		string $calendarUri,
		string $eventUid,
	): CalendarLink;

	/**
	 * Create a new VEVENT through CalendarEventService and record a link-table row.
	 *
	 * Delegates the VEVENT write (which adds X-OPENREGISTER-* properties)
	 * to {@see CalendarEventService::createEvent()} then mirrors the
	 * linkage in the link table with `taggedWithXor=true`.
	 *
	 * @param LinkTarget          $target    The object, register and schema to link to.
	 * @param array<string,mixed> $eventData Event data (summary, dtstart, dtend, location, ...).
	 *
	 * @return CalendarLink The persisted link row.
	 *
	 * @throws Exception On creation failure.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function createLinkedEvent(LinkTarget $target, array $eventData): CalendarLink;

	/**
	 * Unlink an event from an object.
	 *
	 * Always removes the link-table row. If the row carried
	 * `taggedWithXor=true`, additionally strips the X-OPENREGISTER-*
	 * properties from the underlying VEVENT (best-effort; failures
	 * are logged but do not abort the unlink).
	 *
	 * @param string $objectUuid Object UUID.
	 * @param string $eventUid VEVENT UID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function unlinkEvent(string $objectUuid, string $eventUid): void;

	/**
	 * Get all events linked to an object as the UNION of link-table rows
	 * and the legacy X-OPENREGISTER-* CalDAV scan.
	 *
	 * Each row carries a `source` field:
	 *   - `link-table`  — only in the link table
	 *   - `xor-only`    — only on the VEVENT as X-OR-* properties
	 *   - `both`        — present in both sources
	 *
	 * Dedupe key: `(calendarUri, eventUid)` where calendarUri can be
	 * empty when the CalDAV scan does not surface it; in that case
	 * dedupe falls back to eventUid alone.
	 *
	 * @param string $objectUuid Object UUID.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getLinkedEvents(string $objectUuid): array;

	/**
	 * List the current user's VEVENT-supporting calendars.
	 *
	 * @return array<int,array<string,mixed>> Each entry has id, uri, displayName, color.
	 *
	 * @throws Exception When no user is logged in.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getAvailableCalendars(): array;

	/**
	 * List recent events on a named calendar — picker step 2.
	 *
	 * @param string               $calendarUri Calendar URI.
	 * @param CalendarPickerWindow $window      The earliest start and the row cap.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws Exception When no user, no calendar, or calendar URI is unknown.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function getEventsForCalendar(string $calendarUri, CalendarPickerWindow $window): array;
}//end interface
