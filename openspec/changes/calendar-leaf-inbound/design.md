# Design: calendar-leaf-inbound

## Context

The leaf's outbound path is solid: `CalendarEventService` hand-assembles tagged VEVENTs into the per-user pinned calendar (`events_calendar_uri`) via `CalDavBackend`, `CalendarLinkService` mirrors linkage into `openregister_calendar_links` with cached display fields, and `getLinkedEvents` unions the link table with the legacy X-OR scan. The inbound path does not exist: no `OCA\DAV\Events` listener anywhere in `lib/`, so Calendar-side edits stale the cache silently; the only cache-repair mechanism (`BackfillCalendarLinksJob`) is a QueuedJob that is registered nowhere and gated off by default; both link write paths insert blindly (duplicates masked only by read-path dedupe); there is no event→objects lookup for any inbound handler to use; and nothing in the calendar path knows what a reminder is. Consumers are starting to need exactly these five (pipelinq `calendar-deepening` is the first).

## Goals / Non-goals

**Goals:** inbound freshness (listen, refresh, delete), reverse lookup, idempotent linking with a unique index, an actually-running backfill, VALARM authoring on create.

**Non-goals:** recurrence, iMIP/attendee round-trip, generic `updateEvent` API, true VEVENT delete via the API, writable virtual calendars, multi-calendar write targets, VTIMEZONE. Each is real, none is needed by current consumers, and each would multiply this change's surface.

## Decisions

### D1 — Listeners over polling

A TimedJob rescan would be simpler but permanently O(all calendars) and up to an interval stale. The DAV events fire in-process on every calendar object write and carry the calendar data, so the listener does string-level `X-OPENREGISTER-OBJECT` prefiltering before any VObject parse — near-zero cost for untagged events, immediate freshness for tagged ones. Listener exceptions are caught and logged: a link-cache refresh must never fail a user's calendar save.

### D2 — Match by tag OR by link row

Tagged events (`taggedWithXor=true`) are matched by their own properties; untagged events that were linked via the link-table path (`linkEvent`) are matched by `findByEventUid`. Both paths are needed — link-table-only links carry no marker inside the VEVENT, which is precisely why the reverse lookup (REQ-CAL-INB-002) is a prerequisite of the listeners, not a nice-to-have.

### D3 — Idempotency at the service, integrity at the database

The service check (`findByObjectAndEvent`-style pre-check returning the existing row) gives friendly semantics; the unique index guarantees them under concurrency (two listeners or a listener racing an API call). The migration dedupes first (keep oldest — its `linkedAt` is the true link time) so index creation cannot fail; the read path already deduplicates, so collapsing rows changes no observable read.

### D4 — Backfill: register and default-on, keep the flag

The job predates the listeners as a one-time Tier-2 migration and is already idempotent (`findByObjectAndEvent` pre-check per event). With listeners handling steady-state, the backfill's remaining role is catching up instances that accumulated tagged events while inbound did not exist — which is every instance, once. Registering it and defaulting the flag to enabled makes upgrade behaviour correct without operator action; the flag flips from "opt-in for a job you must also run by hand" to "kill switch", which is the honest shape for a repair job. QueuedJob stays appropriate: run once, not on an interval.

### D5 — VALARM is authored, never delivered

The leaf writes `ACTION:DISPLAY` + `TRIGGER:-PT{n}M` and stops. The DAV reminders service and every CalDAV client already own alarm delivery; duplicating dispatch in OpenRegister would double-notify. This deliberately splits reminders with consumers: the leaf provides calendar-native alarms; an app-level "nag the assignee in NC notifications" (pipelinq's `FollowUpReminderJob`) is app orchestration on top of leaf reads. One `reminderMinutesBefore` integer, `DISPLAY` only — `EMAIL` alarms and absolute triggers wait for demand.

## Risks / Trade-offs

- **DAV event payload variance across NC versions** — listeners read the calendar data defensively (guarded class references, tolerate absent keys); absence of the event classes degrades to today's behaviour.
- **Backfill cost on large instances at upgrade** — per-user scan of the pinned calendar only (that is all `getEventsForObject('')` reaches); counts are logged; the kill switch remains for operators who want to schedule the window.
- **Unique-index migration on a table with unknown duplicate volume** — dedupe runs in the same migration, keep-oldest is deterministic; read behaviour unchanged by construction.
- **UID reuse across calendars** — the triple includes `calendarUri`; the reverse lookup accepts the optional uri scope for the ambiguous case.

## Migration / Rollout

- Migration (dedupe + unique index) ships first in the change; listeners and idempotent writes assume the index exists.
- Listener registration and job registration are boot-time; disabling the app removes both cleanly.
- Rollback: drop the listeners/registration and set `backfill_calendar_links=no`; the index can stay (it enforces a property that was always intended).
- Consumer sequencing: pipelinq `calendar-deepening` declares `depends_on` on this change for its inbound scenarios; nothing here depends on any consumer.
