# Integration: Calendar (Meetings)

## Problem

OpenRegister can already link CalDAV VEVENT entries to objects via `CalendarEventService` and `CalendarEventsController` (253 LOC, shipped in `nextcloud-entity-relations`). The backend stores RFC 9253 LINK properties on the VEVENT and offers full CRUD over `/api/objects/{register}/{schema}/{id}/calendar`.

**The UI is missing entirely.** Users cannot see, add, or manage meetings linked to objects. Consuming apps (Procest, Pipelinq, Decidesk, ZaakAfhandelApp) have no path to surface meetings on case detail pages or dashboards. Today this leaf is **partial** per the 2026-05-24 registry audit — the backend `CalendarEventService` delegate works and returns real linked items, but the frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` uses the generic `leaf()` factory with no bespoke `CnCalendarTab` / `CnCalendarCard` (timeline view). This blocks ADR-022 enforcement: while this leaf is incomplete, consuming apps like Procest and Decidesk have no working integration UI path and reinvent meeting surfaces locally.

## Context

- **Audit bucket**: partial (2026-05-24)
- **Current backend**: Uses OR's `CalendarEventService` delegate — returns real linked VEVENTs with date/time/attendees
- **Current frontend**: generic `leaf()` shell in `nextcloud-vue/src/integrations/builtin/leaves.js` — no bespoke tab/widget (timeline view)
- **Target NC class(es)**: existing `OCA\OpenRegister\Service\CalendarEventService` (CalDAV-backed) — no new NC class import required
- **Storage strategy**: `link-table` augmented by CalDAV custom properties (X-OPENREGISTER-*) for reverse discovery
- **Depends on**: `pluggable-integration-registry` (umbrella mechanism — registry code is done; umbrella issue #1307 stays open until OCS capability + useRegistry default flip land; this leaf does not need to wait for those)
- **Related ADRs**: ADR-019 (mechanism), ADR-022 (consumption principle)
- **Cross-app demand:** Procest (case meetings), Pipelinq (kanban deadlines), Decidesk (council meetings), ZaakAfhandelApp (zaak afspraken), MyDash (personal agenda overview)

## Proposed Solution

Ship the calendar integration's full vertical slice through the umbrella's contract:

1. **`CalendarProvider`** (PHP) — `IntegrationProvider` implementation wrapping the existing `CalendarEventService`. id=`calendar`, group=`comms`, requiredApp=`calendar`, storage=`link-table`, no extra permission, no auth requirements.
2. **`CnCalendarTab`** (Vue) — sidebar tab listing linked VEVENTs with date/time, attendees, location. Inline create form (date picker, summary, attendees from contacts integration if present).
3. **`CnCalendarCard`** (Vue) — widget rendering across all four surfaces:
   - `user-dashboard`: upcoming meetings across all the user's objects
   - `app-dashboard`: meetings for objects in this app's scope
   - `detail-page`: this object's meetings as a card with "Add meeting" CTA
   - `single-entity`: a single VEVENT rendered as a chip (date + summary + status)
4. **Frontend registration** — `src/integrations/builtin/calendar.js` (or app-side if not built-in) calls `OCA.OpenRegister.integrations.register({ id: 'calendar', ... })`.
5. **Backend registration** — `CalendarProvider` is DI-tagged in `Application::register()`.
6. **Spec delta** — new requirement under `generic-integrations` capability or new `integration-calendar` capability spec.
7. **Tests** — provider unit test, component tests across surfaces, integration test asserting end-to-end flow (register → schema accepts `calendar` in linkedTypes → UI renders).

Provider wraps the existing `CalendarEventService` (which already imports CalDAV plumbing) and falls back to `IntegrationHealth::missingApp('calendar')` when NC Calendar is not installed.

## Scope

### In scope

- `lib/Service/Integration/Providers/CalendarProvider.php` (wraps existing `CalendarEventService`)
- `nextcloud-vue/src/components/CnCalendarTab/CnCalendarTab.vue`
- `nextcloud-vue/src/components/CnCalendarCard/CnCalendarCard.vue` (handles all 4 surfaces internally)
- `nextcloud-vue/src/integrations/builtin/calendar.js` registration
- DI tag registration in `lib/AppInfo/Application.php`
- Unit test for `CalendarProvider`
- Component tests for `CnCalendarTab` + `CnCalendarCard` (one per surface)
- nl + en translations for all new strings
- Spec delta: new `integration-calendar` capability spec
- Update developer guide to reference calendar as a worked link-table example

### Out of scope

- Modifications to the existing `CalendarEventService` or `CalendarEventsController` — they are wrapped, not changed
- Additional VEVENT features beyond what the existing controller exposes (e.g., recurring events UI, free/busy view) — separate future changes if needed
- Calendar selection UI for choosing which calendar a new VEVENT lands in — defaults to the user's first writable calendar (existing service behaviour)
- Cross-meeting analytics / KPIs on dashboards — apps may build these on top, but not in this leaf

## Acceptance criteria

- [ ] A user with the Calendar app installed sees a "Meetings" tab in `CnObjectSidebar` for any object whose schema has `calendar` in `linkedTypes`
- [ ] The tab lists all linked VEVENTs with date, summary, attendees
- [ ] User can create a new meeting from the tab (creates the VEVENT in their calendar + the link)
- [ ] User can unlink a meeting (removes the link, leaves the VEVENT in the calendar)
- [ ] `CnCalendarCard` renders correctly on all four surfaces — verified by component tests
- [ ] OCS capabilities response includes `calendar` in the `integrations` block when the NC Calendar app is installed
- [ ] When the NC Calendar app is uninstalled, the integration is hidden everywhere (sidebar, dashboards, capabilities)
- [ ] A schema property with `referenceType: 'calendar'` renders the `single-entity` widget surface for the referenced VEVENT id
- [ ] Provider has zero references to MarkerLookupTrait UNLESS storage strategy is `query-time` AND the marker column is verified to exist in the target NC app
- [ ] Real `CalendarEventService` delegation for the backing NC app (skip for `query-time` providers that genuinely should DB-query only)
- [ ] `health()` returns `IntegrationHealth::missingApp('calendar')` when NC Calendar absent; never throws
- [ ] PHPUnit tests cover: happy-path (app installed + linked), absent-app (graceful empty), empty-result (app installed, no links)
- [ ] Frontend leaf in `nextcloud-vue/src/integrations/builtin/leaves.js` wires the new bespoke `CnCalendarTab` + `CnCalendarCard` components
- [ ] `nextcloud-vue/scripts/check-integration-parity.js` exit 0
- [ ] SPDX-License-Identifier + SPDX-FileCopyrightText inside the file docblock (ADR-014)
- [ ] nl + en translations complete (ADR-007)
