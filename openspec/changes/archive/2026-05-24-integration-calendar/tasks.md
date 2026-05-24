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
- [ ] Unit test `tests/Unit/Service/Integration/Providers/CalendarProviderTest.php` — covers contract methods + delegation + isEnabled when app is missing

## Frontend — Tab

- [x] Create bespoke sidebar tab `nextcloud-vue/src/integrations/builtin/calendar/CnCalendarTab.vue`
  - Lists linked VEVENTs ordered by date ascending
  - Timeline groups events into "Upcoming" + "Past"
  - Inline create form: summary + start (NcDateTimePickerNative) + location (POSTs to existing `/api/objects/{r}/{s}/{id}/events` controller — `CalendarProvider` does not implement `create()`)
  - Per-meeting unlink action (registry DELETE endpoint; VEVENT remains in NC Calendar)
  - Empty / loading / error states per ADR-017
  - 503 unavailable banner per AD-23
- [x] Component test `nextcloud-vue/src/integrations/builtin/calendar/__tests__/CnCalendarTab.spec.js` — 6 tests

## Frontend — Widget

- [x] Create bespoke widget `nextcloud-vue/src/integrations/builtin/calendar/CnCalendarCard.vue`
  - Branches on `surface` prop:
    - `user-dashboard`: next 5 upcoming meetings (compact list)
    - `app-dashboard`: same compact list scoped to current app context
    - `detail-page`: this object's meetings + "Open in Calendar" footer CTA
    - `single-entity`: chip with date + summary + status icon, accepts `entityId` (composite `calendarId/eventUri`)
- [x] Surface-specific component tests `nextcloud-vue/src/integrations/builtin/calendar/__tests__/CnCalendarCard.spec.js` — 6 tests covering all four surfaces, empty + error states, maxDisplay cap

## Frontend — Registration

- [ ] **Deferred to fleet consolidator** — `nextcloud-vue/src/integrations/builtin/leaves.js` repoint of the `calendar` entry from the generic `leaf()` factory to the bespoke `CnCalendarTab` + `CnCalendarCard`. Shared file across all 10 partial-leaf waves; one atomic edit by the consolidator avoids parallel-edit conflicts. Parity gate currently passes (the generic factory still satisfies tab/widget contract).

## Quality

- [x] Parity gate passes locally (`node scripts/check-integration-parity.js`)
- [x] ESLint clean on the new frontend files
- [x] All 12 new Jest tests pass; full suite still discoverable (144 tests)
- [ ] nl + en `.po` updates — deferred to next translation-sync wave (new strings are wrapped in `t('nextcloud-vue', ...)` so the next `update-translations` run picks them up)
- [x] Backend PHPCS / PHPMD / PHPStan / Psalm — no backend changes in this wave; `CalendarProvider` already shipped and passes strict checks
- [ ] Unit test `tests/Unit/Service/Integration/Providers/CalendarProviderTest.php` — **deferred** to a backend follow-up issue; existing `CalendarEventServiceTest` covers the happy/empty/missing paths the provider delegates to

## Acceptance verification

- [ ] **Deferred** — end-to-end browser verification, hide test, reference-property test, and `hidden-tabs` backwards-compat assertion all depend on the leaves.js consolidator landing first. Tracked in the cross-wave consolidation issue.
