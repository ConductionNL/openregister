# Tasks: live-audit-round-one

## 1. Notices only on a real change (E1)

- [x] 1.1 `lib/Service/Notification/SystemEntityChange.php`.
- [x] 1.2 `SystemEntityNotificationListener` skips an update that is not real.
- [x] 1.3 Tests: version-only configuration, reorder-only schema, a real property change. Two fail on the old code.

## 2. Setup wizard stays closed

- [x] 2.1 `SetupController::runAction('dismiss-setup')`.
- [x] 2.2 `src/services/wizardDismissal.js`, wired in `App.vue`.
- [x] 2.3 Tests: `SetupControllerTest` (2), `wizardDismissal.spec.js` (4).

## 3. Header sort (G2)

- [x] 3.1 `SchemasIndex` handles `@sort` through `sortSchemas()`.
- [x] 3.2 `AuditTrailIndex` sortable headers, server sort kept in the store.
- [x] 3.3 Tests: `listSort.spec.js` (7), `auditTrail.spec.js` (1).

## 4. Activity links (E1)

- [x] 4.1 `ActivityService::buildObjectLink()` uses the deep link registry.
- [x] 4.2 Tests: owned object and unclaimed object. One fails on the old code.
