# Tasks: Integration — Calendar

## Backend

- [ ] Create `lib/Service/Integration/Providers/CalendarProvider.php` extending `AbstractIntegrationProvider`
  - getId() returns 'calendar'
  - getLabel() returns translatable 'Meetings'
  - getIcon() returns 'Calendar'
  - getGroup() returns 'comms'
  - getRequiredApp() returns 'calendar'
  - getStorageStrategy() returns 'link-table'
  - isEnabled() checks NC Calendar app availability
  - list/get/create/update/delete delegate to CalendarEventService
  - health() returns CalDAV status + auth (always 'none')
- [ ] Register `CalendarProvider` as DI-tagged `IntegrationProvider` in `lib/AppInfo/Application.php`
- [ ] Unit test `tests/Unit/Service/Integration/Providers/CalendarProviderTest.php` — covers contract methods + delegation + isEnabled when app is missing

## Frontend — Tab

- [ ] Create `nextcloud-vue/src/components/CnCalendarTab/CnCalendarTab.vue`
  - Lists linked VEVENTs ordered by date ascending
  - Inline create form: date picker, time picker, summary, attendees (uses contacts integration if present, falls back to email input)
  - Per-meeting actions: open in NC Calendar, unlink
  - Empty state: "No meetings linked yet"
- [ ] Create `nextcloud-vue/src/components/CnCalendarTab/index.js` barrel
- [ ] Component test `nextcloud-vue/tests/components/CnCalendarTab.test.js`

## Frontend — Widget

- [ ] Create `nextcloud-vue/src/components/CnCalendarCard/CnCalendarCard.vue`
  - Branches on `surface` prop:
    - `user-dashboard`: next 5 upcoming meetings across all linked objects
    - `app-dashboard`: meetings for objects in current app scope
    - `detail-page`: this object's meetings + "Add meeting" CTA
    - `single-entity`: chip with date + summary + status icon, accepts `entityId` prop
- [ ] Create `nextcloud-vue/src/components/CnCalendarCard/index.js` barrel
- [ ] Surface-specific component tests `nextcloud-vue/tests/components/CnCalendarCard.test.js` — one describe block per surface

## Frontend — Registration

- [ ] Create `nextcloud-vue/src/integrations/builtin/calendar.js` calling `OCA.OpenRegister.integrations.register({ id: 'calendar', label: t(...), icon: 'Calendar', group: 'comms', requiredApp: 'calendar', tab: CnCalendarTab, widget: CnCalendarCard, referenceType: 'calendar', defaultSize: { w: 4, h: 3 } })`
- [ ] Wire calendar.js into the registry's boot sequence in `nextcloud-vue/src/integrations/registry.js`
- [ ] Add exports to `nextcloud-vue/src/components/index.js` and `nextcloud-vue/src/index.js`

## Quality

- [ ] Parity gate passes locally (`scripts/check-integration-parity.sh`)
- [ ] All new strings translated to nl + en (l10n updates in both repos)
- [ ] PHPCS / PHPMD / PHPStan / Psalm strict pass on backend changes
- [ ] ESLint clean on frontend changes

## Acceptance verification

- [ ] End-to-end: install NC Calendar, log in, open an object whose schema has `linkedTypes: ["calendar"]`, see Meetings tab, create a meeting, verify it appears in NC Calendar app, unlink it, verify the VEVENT remains in NC Calendar
- [ ] Hide test: uninstall NC Calendar, verify Meetings tab disappears from sidebar and `calendar` is removed from OCS capabilities
- [ ] Reference-property test: schema with `nextMeeting: { type: 'string', referenceType: 'calendar' }` renders `single-entity` widget for the referenced VEVENT
- [ ] Backwards-compat: app using `<CnObjectSidebar :hidden-tabs="['calendar']">` correctly hides the new tab
