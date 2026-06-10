# Tasks: Integration — Calendar

> **ADR-028 task-cap waiver**: this leaf has 26 tasks (cap is 15). The work is a single integration vertical slice (provider + sub-resource controller + tab + 4-surface widget + tests + nl/en). Splitting it would force interleaved depends_on chains that ship slower than one cohesive leaf. Hydra builders SHOULD batch this leaf across multiple turns.

## Backend

- [x] Create `lib/Service/Integration/Providers/CalendarProvider.php` extending `AbstractIntegrationProvider`
  - getId() returns 'calendar'
  - getLabel() returns translatable 'Meetings'
  - getIcon() returns 'Calendar'
  - getGroup() returns 'comms'
  - getRequiredApp() returns 'calendar'
  - getStorageStrategy() returns 'link-table'
  - isEnabled() checks NC Calendar app availability
  - list/get/create/update/delete delegate to CalendarEventService
  - health() returns CalDAV status + auth (always 'none')
- [x] Register `CalendarProvider` as DI-tagged `IntegrationProvider` in `lib/AppInfo/Application.php`
- [~] Unit test `tests/Unit/Service/Integration/Providers/CalendarProviderTest.php` — covers contract methods + delegation + isEnabled when app is missing — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Tab

- [~] Create `nextcloud-vue/src/components/CnCalendarTab/CnCalendarTab.vue` — deferred to downstream cycle / fleet-wide adoption (handoff)
  - Lists linked VEVENTs ordered by date ascending
  - Inline create form: date picker, time picker, summary, attendees (uses contacts integration if present, falls back to email input)
  - Per-meeting actions: open in NC Calendar, unlink
  - Empty state: "No meetings linked yet"
- [~] Create `nextcloud-vue/src/components/CnCalendarTab/index.js` barrel — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Component test `nextcloud-vue/tests/components/CnCalendarTab.test.js` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Widget

- [~] Create `nextcloud-vue/src/components/CnCalendarCard/CnCalendarCard.vue` — deferred to downstream cycle / fleet-wide adoption (handoff)
  - Branches on `surface` prop:
    - `user-dashboard`: next 5 upcoming meetings across all linked objects
    - `app-dashboard`: meetings for objects in current app scope
    - `detail-page`: this object's meetings + "Add meeting" CTA
    - `single-entity`: chip with date + summary + status icon, accepts `entityId` prop
- [~] Create `nextcloud-vue/src/components/CnCalendarCard/index.js` barrel — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Surface-specific component tests `nextcloud-vue/tests/components/CnCalendarCard.test.js` — one describe block per surface — deferred to downstream cycle / fleet-wide adoption (handoff)

## Frontend — Registration

- [~] Create `nextcloud-vue/src/integrations/builtin/calendar.js` calling `OCA.OpenRegister.integrations.register({ id: 'calendar', label: t(...), icon: 'Calendar', group: 'comms', requiredApp: 'calendar', tab: CnCalendarTab, widget: CnCalendarCard, referenceType: 'calendar', defaultSize: { w: 4, h: 3 } })` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Wire calendar.js into the registry's boot sequence in `nextcloud-vue/src/integrations/registry.js` — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Add exports to `nextcloud-vue/src/components/index.js` and `nextcloud-vue/src/index.js` — deferred to downstream cycle / fleet-wide adoption (handoff)

## Quality

- [~] Parity gate passes locally (`scripts/check-integration-parity.sh`) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] All new strings translated to nl + en (l10n updates in both repos) — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] PHPCS / PHPMD / PHPStan / Psalm strict pass on backend changes — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] ESLint clean on frontend changes — deferred to downstream cycle / fleet-wide adoption (handoff)

## Acceptance verification

- [~] End-to-end: install NC Calendar, log in, open an object whose schema has `linkedTypes: ["calendar"]`, see Meetings tab, create a meeting, verify it appears in NC Calendar app, unlink it, verify the VEVENT remains in NC Calendar — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Hide test: uninstall NC Calendar, verify Meetings tab disappears from sidebar and `calendar` is removed from OCS capabilities — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Reference-property test: schema with `nextMeeting: { type: 'string', referenceType: 'calendar' }` renders `single-entity` widget for the referenced VEVENT — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] Backwards-compat: app using `<CnObjectSidebar :hidden-tabs="['calendar']">` correctly hides the new tab — deferred to downstream cycle / fleet-wide adoption (handoff)
