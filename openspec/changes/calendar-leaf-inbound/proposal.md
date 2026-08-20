# Proposal: calendar-leaf-inbound

## Summary

Close the inbound half of the calendar leaf. Today the leaf is write-and-forget: `CalendarEventService` creates X-OPENREGISTER-tagged VEVENTs and `CalendarLinkService` records rows in `openregister_calendar_links`, but nothing ever flows back. There are zero listeners on `OCA\DAV` calendar-object events, so an event moved, edited, or deleted in Nextcloud Calendar silently stales the cached `summary`/`dtstart`/`dtend`/`location` on its link row; `BackfillCalendarLinksJob` — the only mechanism that turns Calendar-side tagged events into link rows — is unregistered and disabled by default (`backfill_calendar_links` = `no`); repeated link calls duplicate rows because neither link path checks for an existing row; there is no reverse event→objects lookup for a listener to use; and the leaf has no reminder capability (no VALARM anywhere in the calendar path). Consumer apps (pipelinq `calendar-deepening`, and any leaf app rendering the calendar card) need all five.

## Why

Leaf-first (ADR-022) only holds if the leaf is complete enough that apps never route around it. Pipelinq's calendar-deepening change wants follow-up reminders and inbound event visibility; without this change it would either overclaim ("two-way sync") or be tempted to scan CalDAV itself — exactly what the leaf exists to prevent. The stale-cache defect is also a correctness bug in its own right: the link table presents times that the calendar no longer holds.

## What Changes

1. **Inbound listeners** — register `IEventListener`s for `OCA\DAV\Events\CalendarObjectCreatedEvent`, `CalendarObjectUpdatedEvent`, `CalendarObjectMovedEvent`, and `CalendarObjectDeletedEvent`. For VEVENTs carrying `X-OPENREGISTER-*` properties (or matching an existing link row by event UID): refresh the cached link fields on update/move, create the missing link row on create (idempotently), and delete the link row on deletion of the VEVENT.
2. **Reverse lookup** — `CalendarLinkMapper::findByEventUid(string $eventUid, ?string $calendarUri = null)` plus a service surface `CalendarLinkService::getObjectsForEvent()`, required by the listeners and useful to consumers.
3. **Idempotent linking** — `linkEvent` / `createAndLinkEvent` return the existing row instead of inserting a duplicate for the same `(objectUuid, eventUid, calendarUri)`; a migration adds the unique index after deduplicating existing rows (keep oldest, the read path already deduplicates so no visible change).
4. **Backfill activation** — register `BackfillCalendarLinksJob` so it is schedulable, and flip the `backfill_calendar_links` default to enabled for the registered one-shot run on upgrade (the job is already idempotent via `findByObjectAndEvent`); the flag remains the kill switch.
5. **Reminder support (VALARM)** — `CalendarEventService::createEvent` accepts an optional `reminderMinutesBefore` in `$data` and writes a `VALARM` (ACTION:DISPLAY, `TRIGGER:-PT{n}M`) into the VEVENT; the event array shape exposes it. Alarm delivery stays native to the calendar stack (Calendar app / DAV reminders service) — the leaf authors the alarm, it does not deliver it.

## Out of scope

- Recurrence (RRULE/EXDATE/RECURRENCE-ID) — untouched; recurring events are handled as today (single-instance semantics).
- Attendee round-trip / scheduling (ORGANIZER, PARTSTAT read-back, iMIP) — the attendee surface stays write-once bare emails.
- A general `updateEvent()`/PUT route and true VEVENT deletion via the API — `destroy` keeps unlink semantics.
- Writable virtual schema calendars (`RegisterCalendar` stays `PERMISSION_READ`).
- Multi-calendar write targets — writes keep landing on the pinned `events_calendar_uri`.
- VTIMEZONE authoring — UTC stays forced; a timezone pass is its own change.
