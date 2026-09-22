# Tasks: audit-trail-readable-scope

## 1. The scope decision

- [ ] 1.1 `ReadableAuditTrailLister`: a bounded, cursor paginated scan over
      `AuditTrailMapper::findAll()` that keeps only the entries whose object
      the caller may read.
- [ ] 1.2 Readability through `PermissionHandler::hasPermission()` with action
      `read` and the resolved `ObjectEntity`, which is the funnel that already
      consults `ObjectGrantResolver`. No second reachability rule.
- [ ] 1.3 Fail closed on every unknown: anonymous, no object uuid, object not
      resolved, schema not resolved, and any throwable, all mean absent.

## 2. The surface

- [ ] 2.1 `AuditTrailController::readable()` with `@NoAdminRequired`, and the
      route `GET /api/audit-trails/readable`.
- [ ] 2.2 Withhold `session`, `request` and `ipAddress` from the scoped rows.

## 3. Tests

- [ ] 3.1 Unit tests: the readable entry is kept, the unreadable one is
      dropped, the anonymous caller asks the mapper nothing, a missing object
      and a missing schema are absent, the recon fields are withheld, the scan
      is bounded, and the cursor advances past rows that were filtered out.
- [ ] 3.2 Mutation check the scope assertion: make the lister keep every row
      and quote the assertion that reddens.
- [ ] 3.3 Assert the wiring from the caller: the controller method is routed
      and the class is referenced from `lib/`.
