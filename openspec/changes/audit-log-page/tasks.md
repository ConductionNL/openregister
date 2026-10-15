# Tasks: audit-log-page

## 1. Query

- [x] 1.1 Filtered, cursor-paginated instance-wide query: `lib/Db/AuditTrailPageQuery.php`
      (a class beside the 3,400-line mapper), keyset on the primary key, newest
      first, one row more than the page instead of a count. `AuditTrailPageQueryTest` (6)
      over an evaluating query-builder fake.
- [x] 1.2 RBAC join for non-admins. DELIVERED 2026-09-22 as a separate,
      narrower path rather than a widening of `index()`, exactly as the note
      below asks: `GET /api/audit-trails/readable`, backed by
      `lib/Service/Audit/ReadableAuditTrailLister.php`, archived as
      `openspec/changes/archive/2026-09-22-audit-trail-readable-scope`. The
      existing admin gate on `index()`, `statistics()` and `export()` is
      untouched.
      > 🔴 **READ THIS BEFORE STARTING 1.2: IT WIDENS A SURFACE THAT WAS
      > DELIBERATELY CLOSED.** `AuditTrailController::index()` is admin-only
      > today, at the framework level AND with a body `requireAdmin()` as
      > defence in depth, and its docblock records why: "the cross-tenant
      > audit-trail index leaks per-row diffs of every object change across
      > every register/schema — wave-3 C6". `statistics()` carries the same
      > gate for the same reason, calling per-register volumes "a recon signal
      > across tenants".
      >
      > So this task is not "add a filter to a list". It is re-opening a
      > boundary somebody closed on purpose, and the failure mode is that the
      > join looks right and returns one register too many, which nobody
      > notices because the page renders. Three things follow:
      >
      > 1. **Do not relax the existing gate.** Add a separate, narrower path
      >    for non-admins rather than widening `index()`, so an error in the
      >    new one cannot make the admin one wider than it was.
      > 2. **Resolve readability through the ONE funnel.** `ObjectGrantResolver`
      >    and the schema/register rules already answer "may this caller read
      >    this object", and since openregister#3873 that answer includes
      >    inherited grants. A second reachability rule written for this page
      >    is a second answer to the question the whole RBAC layer exists for.
      > 3. **Probe it with the least privileged principal that should be
      >    refused**, across a tenant boundary, and mutation-check the join:
      >    an RBAC join that is accidentally a no-op returns exactly the rows
      >    an admin sees, which is indistinguishable from a working page until
      >    somebody compares two accounts.
      >
      > Measured 2026-09-18 while finishing `sensitive-field-reveal-audit`:
      > `GET /api/audit-trails`, `/statistics` and `/export` all exist and are
      > all admin-gated, so tasks 2.1 and 2.2 are much further along than the
      > unticked boxes suggest, and 1.2 is the real work.

## 2. API and export

- [x] 2.1 `GET /api/audit-trails?cursor=` with the six filters (`actor`, `from`/`to`,
      `action`, `register`, `schema`, `object`) and `search`; without `cursor` the
      list answers its old shape. A bad date answers 400. `AuditTrailControllerTest`.
- [x] 2.2 CSV and JSON export of the filtered result; background job past
      10,000 rows. `AuditTrailPageService::export()` renders exactly the selected
      rows with `hash` and `previousHash` on every row (`LogService::exportRows()`);
      past 10,000 it queues `AuditTrailExportJob`, which saves the file under
      "Audit exports" in the requester's Files and sends `audit_export_ready`
      (strings in all 37 backend catalogues). `AuditTrailExportJobTest` asserts the
      chain columns in the written CSV through the real LogService.

## 3. Surface

- [ ] 3.1 Audit leaf `index` surface with filter bar and export button. (not run: the audit leaf surface is nextcloud-vue's)

## 4. Tests

- [x] 4.1 Unit tests for filters, RBAC join and export contents (filters and export
      above; the RBAC join is 1.2's `ReadableAuditTrailListerTest`).
- [ ] 4.2 `tests/e2e/ci/audit-log-page.spec.ts`: as admin, filter by actor (not run: e2e needs a browser and 3.1) (live pass, decision 139)
      and period, see the rows, export and check the hash columns.
