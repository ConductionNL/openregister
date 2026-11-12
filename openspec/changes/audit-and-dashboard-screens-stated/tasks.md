# Tasks: audit-and-dashboard-screens-stated

The screens are built; these tasks tie them to the requirements and add the checks that keep them true.

## 1. Tags

- [ ] 1.1 Add `@spec openspec/changes/audit-and-dashboard-screens-stated/specs/enhanced-audit-trail/spec.md#REQ-ADS-001` to `exportAuditTrails` and the list loader in `src/views/logs/AuditTrailIndex.vue` and to the filter methods in `src/sidebars/logs/AuditTrailSideBar.vue`.
- [ ] 1.2 Add `#REQ-ADS-002` to the data loaders and the date range handler in `src/views/dashboard/DashboardIndex.vue`.

## 2. Checks

- [ ] 2.1 `tests/e2e/ci/audit-trail-page.spec.ts`: filter on schema, action and date range; export CSV and compare the row count with the list total; open an update entry and read old and new values (REQ-ADS-001).
- [ ] 2.2 `tests/e2e/ci/dashboard.spec.ts`: seed two registers, open the app, read the counts, change the date range (REQ-ADS-002).
- [ ] 2.3 Check that the dashboard counts are scoped to what the user may read: a user who may read one register sees only that register's objects counted. Fix the count source in `lib/Service/DashboardService.php` if it counts instance-wide (REQ-ADS-002).

## 3. Matrix

- [ ] 3.1 When the tasks are done, archive this change into `enhanced-audit-trail` and `built-in-dashboards` and repoint `hist-viewer` and `op-dashboard` in `openspec/parity/capabilities.json` to the main spec requirement.
