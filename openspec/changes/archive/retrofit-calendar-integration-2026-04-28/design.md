## Context

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

Calendar integration is an existing CalDAV-exposure layer that surfaces register objects as iCalendar (VEVENT) entries through Nextcloud's `ICalendarProvider`/`ICalendar` contracts. Schemas declare calendar-eligible properties via configuration; the provider walks each register's calendar-enabled schemas, transforms matching objects into VEVENTs, and exposes them under stable CalDAV URIs.

The ghost change `retrofit-calendar-integration-2026-04-28` mints `calendar-integration` as a new capability and retroactively specifies the observed behavior as 2 REQs covering (a) the CalDAV provider/calendar contract and (b) the iCalendar payload transformation.

**Files covered:**
- `lib/Calendar/RegisterCalendarProvider.php` (3 methods) — `ICalendarProvider` implementation: surfaces calendars per user
- `lib/Calendar/RegisterCalendar.php` (9 methods) — `ICalendar` implementation: identity, permissions, search, timerange filters
- `lib/Calendar/CalendarEventTransformer.php` (5 methods) — register-object → VEVENT transformation: all-day vs timed, dtend computation, template interpolation

## Goals / Non-Goals

**Goals:**
- Spec the CalDAV exposure layer so future iCalendar-format changes have a contract to test against
- Spec the VEVENT transformation including the all-day/timed branching and the configurable summary/description templating
- Annotate all 17 methods (across 3 files) with `@spec` tags pointing at the new REQs

**Non-Goals:**
- No code changes — annotations + spec only
- Does not formalize CalDAV write paths (CalDAV in OpenRegister is read-only — register objects are mutated through the standard object API, not through CalDAV PUT/DELETE)
- Does not spec the schema-side calendar-eligibility configuration — that lives under schema-hooks and is referenced indirectly

## Decisions

**Decision: `--cluster calendar-integration` (new capability) rather than `--extend object-interactions`**

Although calendars surface objects, the contract under specification is the CalDAV layer (`ICalendarProvider`, `ICalendar`, VEVENT format), which is orthogonal to object CRUD. Extending `object-interactions` would conflate two distinct subsystems.

**Decision: 2 REQs (provider + transformer) rather than per-method REQs**

The 17 methods cluster cleanly into two observable behaviors: "expose calendars/events to CalDAV clients" and "convert a register object into a valid VEVENT". Splitting further would inflate the spec without adding testable surface.

**Decision: Spec the template-interpolation language as Notes rather than as a REQ scenario**

`interpolateTemplate` accepts a small set of variables (object properties + computed fields). The exact template grammar is implementation-specific and may evolve; the REQ-002 scenarios assert behavior on representative templates rather than enumerating every variable.

## Risks / Trade-offs

- **CalDAV permissions are coarse**: `getPermissions` returns a static permission mask. Per-object ACL is not surfaced through CalDAV. → Acceptable for read-only exposure; flagged in Notes for future tightening if write paths are added.
- **All-day inference is heuristic**: `determineAllDay` checks for time-of-day presence in date strings. Edge cases (e.g., `2026-01-01T00:00:00`) get treated as timed events even if intent was all-day. → Behavior surfaced in REQ-002 scenarios; consumers depend on schema-level type hints.
- **Search/timerange filters are best-effort**: `buildTimerangeFilters` translates CalDAV `time-range` into register query filters with limited semantics (no recurring-event expansion). → Documented in Notes.

## Migration Plan

No migration required — annotations only. The ghost change is archived immediately and `calendar-integration/spec.md` is created at archive time.

`.git-blame-ignore-revs` was updated with the annotation commit SHA.
