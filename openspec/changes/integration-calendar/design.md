# Design: Integration — Calendar

> Most architectural decisions live in the umbrella's [design.md](../pluggable-integration-registry/design.md). This file captures only calendar-leaf-specific choices.

## Approach

Wrap the existing `CalendarEventService` (which already handles CalDAV VEVENT CRUD + RFC 9253 LINK + X-OPENREGISTER-* properties) in a thin `CalendarProvider` implementing `IntegrationProvider`. Build a sidebar tab and a four-surface widget that consume the provider via the standard sub-resource endpoints.

```
 CnObjectSidebar ──► CnCalendarTab ──► /api/objects/.../calendar (GET/POST)
                            │
                            └──► CalendarEventsController ──► CalendarEventService ──► CalDAV
                                              │
 CnDashboardPage ──► CnCalendarCard ──┐       │
 CnDetailPage    ──► CnCalendarCard ──┤       │
 CnFormDialog    ──► CnCalendarCard ──┘       │
 (referenceType)         (single-entity)      │
                            │                 │
                            └──► same endpoints, different render
```

## Architecture Decisions

### AD-1: Provider wraps existing service, does not duplicate

**Decision**: `CalendarProvider` injects `CalendarEventService` and delegates every contract method to it. Zero business logic in the provider itself.

**Why**: Backend is already shipped, tested, and integrated. Re-implementing would duplicate logic and create drift risk. The provider exists purely to satisfy the registry contract.

**Trade-off**: One extra layer of indirection. Acceptable — it's a thin wrapper, not a rewrite.

### AD-2: One widget component handles all four surfaces

**Decision**: `CnCalendarCard.vue` accepts `surface` as a prop and branches internally:
- `user-dashboard` / `app-dashboard`: compact "next 5 upcoming meetings" list
- `detail-page`: full meeting list with create CTA
- `single-entity`: chip with date + summary + status icon

No `widgetCompact` / `widgetExpanded` / `widgetEntity` overrides for this leaf. Branching internally is simpler than maintaining four files for what is fundamentally the same data.

**Why**: The data model is uniform (VEVENT). Only the visual density differs across surfaces. A single component with internal branching keeps it DRY without harming readability.

**Trade-off**: One file is slightly larger. Acceptable; the rendering differences are small.

### AD-3: Inline create form, no full-screen meeting editor

**Decision**: The "Add meeting" affordance in `CnCalendarTab` opens an inline form (date, time, summary, attendees) that creates the VEVENT directly. No modal, no full editor. For richer editing the user clicks through to NC Calendar.

**Why**: Sidebar real estate is constrained. A modal would obscure the object the user is working with. The 90% case is "schedule a quick follow-up" — the inline form covers it. The 10% case (editing recurrence, finding free time) goes to NC Calendar where those tools belong.

**Trade-off**: Power users may want richer in-tab editing. They have NC Calendar one click away.

### AD-4: Attendee picker reuses contacts integration if present

**Decision**: The attendee field in the inline create form reuses the `contacts` integration's `single-entity` widget for attendee selection (when the contacts integration is registered). When contacts is not registered, falls back to a plain email input.

**Why**: Already-built mechanisms (the contacts integration + reference-property rendering from the umbrella) make this free. Better UX, no duplicate "user picker" code.

**Trade-off**: Soft dependency on the contacts leaf landing first to get the rich experience. The fallback ensures the calendar leaf is independently shippable.

## Files Affected

### New files — Backend

| File | Purpose |
|---|---|
| `lib/Service/Integration/Providers/CalendarProvider.php` | `IntegrationProvider` implementation wrapping `CalendarEventService` |
| `tests/Unit/Service/Integration/Providers/CalendarProviderTest.php` | Provider unit tests |

### Modified files — Backend

| File | Change |
|---|---|
| `lib/AppInfo/Application.php` | Register `CalendarProvider` as DI-tagged service |

### New files — Frontend

| File | Purpose |
|---|---|
| `nextcloud-vue/src/components/CnCalendarTab/CnCalendarTab.vue` | Sidebar tab |
| `nextcloud-vue/src/components/CnCalendarTab/index.js` | Barrel |
| `nextcloud-vue/src/components/CnCalendarCard/CnCalendarCard.vue` | Widget for all 4 surfaces |
| `nextcloud-vue/src/components/CnCalendarCard/index.js` | Barrel |
| `nextcloud-vue/src/integrations/builtin/calendar.js` | Registration call |
| `nextcloud-vue/tests/components/CnCalendarTab.test.js` | Component tests |
| `nextcloud-vue/tests/components/CnCalendarCard.test.js` | Surface-specific component tests |

### Modified files — Frontend

| File | Change |
|---|---|
| `nextcloud-vue/src/components/index.js` | Add `CnCalendarTab`, `CnCalendarCard` exports |
| `nextcloud-vue/src/index.js` | Add to barrel |
| `nextcloud-vue/src/integrations/registry.js` | Boot calendar registration alongside other built-ins |

## Risks

| Risk | Mitigation |
|---|---|
| User has no writable calendar | Existing `CalendarEventService` handles this — returns 422 with a clear message; tab surfaces it as "Set up a calendar in Nextcloud Calendar to add meetings" |
| Attendees with no NC account (external email) | RFC 5545 supports this; vCard not required. Inline form accepts free-text email |
| Recurring meetings rendered incorrectly | Initial leaf displays the master VEVENT only; recurrence expansion is a future enhancement |
| Permissions on shared calendars | Existing service respects CalDAV ACLs — we trust the underlying mechanism |

## Open questions

None for the leaf — all design decisions in the umbrella apply unchanged. If new questions emerge during implementation, they go in this file or the umbrella's open questions.
