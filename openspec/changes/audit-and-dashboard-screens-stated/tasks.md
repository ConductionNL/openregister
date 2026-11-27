# Tasks: audit-and-dashboard-screens-stated

The screens are built; these tasks tie them to the requirements and add the checks that keep them true.

## 1. Tags

- [x] 1.1 Add `@spec openspec/changes/audit-and-dashboard-screens-stated/specs/enhanced-audit-trail/spec.md#REQ-ADS-001` to `exportAuditTrails` and the list loader in `src/views/logs/AuditTrailIndex.vue` and to the filter methods in `src/sidebars/logs/AuditTrailSideBar.vue`.
- [x] 1.2 Add `#REQ-ADS-002` to the data loaders and the date range handler in `src/views/dashboard/DashboardIndex.vue`.

## 2. Checks

- [x] 2.1 `tests/e2e/ci/audit-trail-page.spec.ts`: filter on schema, action and date range; export CSV and compare the row count with the list total; open an update entry and read old and new values (REQ-ADS-001).
  - Done (26 Nov): 3 passed against a sandbox Nextcloud 34.0.6 + OpenRegister main on MariaDB. Red on main: `/api/audit-trails` dropped `register` and `schema` and never read a date range, so the filtered page listed 5 rows instead of 1. Fixed in `AuditTrailController::listScope()` with a `created` range in `AuditTrailMapper`. The sidebar now sends ISO dates instead of `Date.toString()`, and the export reads `dateFrom`/`dateTo`.
- [x] 2.2 `tests/e2e/ci/dashboard.spec.ts`: seed two registers, open the app, read the counts, change the date range (REQ-ADS-002).
  - Done (26 Nov): 2 passed on the same sandbox. Red on main: "Last 30 days" showed 0 events, because the chart bound `created <= <today>` meant today's midnight and dropped today. Fixed in `AuditActionStatistics::onOrBefore()`, which now bounds by the start of the next day.
- [x] 2.3 Check that the dashboard counts are scoped to what the user may read: a user who may read one register sees only that register's objects counted. Fix the count source in `lib/Service/DashboardService.php` if it counts instance-wide (REQ-ADS-002).
  Checked: every count and chart endpoint behind the dashboard (`lib/Search/Controller/DashboardController.php`, service `lib/Search/Service/DashboardService.php`) is admin-only; a non-admin gets 403 and nothing is aggregated, so no count is instance-wide for a user who may not read it. Pinned by `DashboardControllerTest::testAnalyticsEndpointsRejectNonAdmin`, now tagged REQ-ADS-002. No fix needed.

## 3. Matrix

- [ ] 3.1 When the tasks are done, archive this change into `enhanced-audit-trail` and `built-in-dashboards` and repoint `hist-viewer` and `op-dashboard` in `openspec/parity/capabilities.json` to the main spec requirement.
